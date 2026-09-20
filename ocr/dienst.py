"""
Texterkennung für Fundus: PDFs und Bilder rein, Text raus.

    POST /erkennen   Rohdaten der Datei im Body → JSON {text, konfidenz, seiten, seiten_gelesen, seiten_ocr, seiten_verworfen}
    GET  /gesund     200, sobald der Dienst läuft

Das Wiki (theme/fundus/dateitext) schickt jeden hochgeladenen Anhang hierher und legt den Text im Artikel ab.
Der Dienst hält nichts: Jede Datei liegt nur für die Dauer der Anfrage in /tmp.

PDF-Seiten mit eigenem Text (am Rechner erstellt) werden nur ausgelesen, nur Seiten ohne Text (Scans) gehen durch
Tesseract. Dabei zählt die Konfidenz: Wörter unter OCR_WORT_MIN fallen weg, Seiten, deren Mittel unter
OCR_SEITE_MIN liegt, ganz. Sonst verwässert Buchstabensalat aus schlechten Scans die Suche.

Antworten: 200 Ergebnis (Text kann leer sein) · 400 leerer Body · 413 zu groß · 415 kein PDF/Bild ·
422 Datei nicht lesbar (verschlüsselt, kaputt, Zeitlimit): nicht wiederholen · 5xx: später erneut versuchen.
Nur Python-Standardbibliothek, damit das Image ohne pip auskommt.
"""

import json
import os
import re
import subprocess
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SPRACHEN = os.environ.get("OCR_SPRACHEN", "deu+eng")
MAX_BYTES = int(os.environ.get("OCR_MAX_MB", "60")) * 1024 * 1024
MAX_SEITEN = int(os.environ.get("OCR_MAX_SEITEN", "50"))
WORT_MIN = float(os.environ.get("OCR_WORT_MIN", "50"))
SEITE_MIN = float(os.environ.get("OCR_SEITE_MIN", "60"))
# Ab so vielen Zeichen (ohne Leerraum) gilt eine PDF-Seite als digital und wird nicht erkannt.
TEXT_MIN = int(os.environ.get("OCR_TEXT_MIN", "50"))
# Längere Seitenkante beim Rendern in Pixeln: A4 mit 300 dpi. Fest statt dpi, damit riesige Seitenformate
# nicht den Speicher sprengen.
PIXEL = int(os.environ.get("OCR_PIXEL", "3508"))
ZEITLIMIT_SCHRITT = int(os.environ.get("OCR_ZEITLIMIT_SEITE", "180"))
GLEICHZEITIG = threading.Semaphore(int(os.environ.get("OCR_PARALLEL", "1")))


class Unlesbar(Exception):
    """Datei kaputt, verschlüsselt oder zu langsam: erneut versuchen hilft nicht."""


def art(daten: bytes):
    if b"%PDF-" in daten[:1024]:
        return "pdf"
    bild = (
        daten.startswith(b"\x89PNG\r\n\x1a\n")
        or daten.startswith(b"\xff\xd8\xff")
        or daten[:4] in (b"II*\x00", b"MM\x00*")
        or daten[:6] in (b"GIF87a", b"GIF89a")
        or (daten[:4] == b"RIFF" and daten[8:12] == b"WEBP")
        or daten[:2] == b"BM"
    )
    return "bild" if bild else None


def ausfuehren(befehl):
    try:
        return subprocess.run(befehl, check=True, capture_output=True, timeout=ZEITLIMIT_SCHRITT).stdout
    except subprocess.TimeoutExpired as e:
        raise Unlesbar(f"Zeitlimit bei {befehl[0]}") from e
    except subprocess.CalledProcessError as e:
        meldung = e.stderr.decode("utf-8", "replace").strip().splitlines()
        raise Unlesbar(f"{befehl[0]}: {meldung[-1] if meldung else 'Fehler'}") from e


def tesseract(bild):
    """Text und Konfidenz (0–100, nach Wortlänge gewichtet) eines Bildes; leerer Text, wenn die Seite zu schlecht ist."""
    tsv = ausfuehren(["tesseract", bild, "stdout", "-l", SPRACHEN, "--psm", "3", "tsv"]).decode("utf-8", "replace")
    absaetze = {}
    gewichtet = 0.0
    zeichen = 0
    for zeile in tsv.splitlines()[1:]:
        spalten = zeile.split("\t")
        if len(spalten) < 12 or spalten[0] != "5":
            continue
        wort = spalten[11].strip()
        konfidenz = float(spalten[10])
        if not wort or konfidenz < 0:
            continue
        gewichtet += konfidenz * len(wort)
        zeichen += len(wort)
        if konfidenz < WORT_MIN or not re.search(r"\w", wort):
            continue
        # Seite, Block, Absatz → Zeile → Wörter
        absatz = absaetze.setdefault((spalten[1], spalten[2], spalten[3]), {})
        absatz.setdefault(spalten[4], []).append(wort)

    if zeichen == 0:
        return "", None
    mittel = gewichtet / zeichen
    if mittel < SEITE_MIN:
        return "", mittel
    text = "\n\n".join("\n".join(" ".join(w) for w in zeilen.values()) for zeilen in absaetze.values())
    return text, mittel


def erkennen(daten: bytes, typ: str):
    with tempfile.TemporaryDirectory() as ordner:
        datei = os.path.join(ordner, "eingang")
        with open(datei, "wb") as f:
            f.write(daten)

        if typ == "bild":
            text, konfidenz = tesseract(datei)
            return ergebnis([text], [konfidenz] if konfidenz is not None else [], 1, 1, 1, 0 if text or konfidenz is None else 1)

        info = ausfuehren(["pdfinfo", datei]).decode("utf-8", "replace")
        treffer = re.search(r"^Pages:\s+(\d+)", info, re.M)
        seiten = int(treffer.group(1)) if treffer else 0
        gelesen = min(seiten, MAX_SEITEN)
        texte, konfidenzen, ocr, verworfen = [], [], 0, 0
        for nummer in range(1, gelesen + 1):
            seite = ["-f", str(nummer), "-l", str(nummer)]
            digital = ausfuehren(["pdftotext", *seite, "-enc", "UTF-8", datei, "-"]).decode("utf-8", "replace")
            if len(re.sub(r"\s", "", digital)) >= TEXT_MIN:
                texte.append(digital)
                continue
            bild = os.path.join(ordner, "seite")
            ausfuehren(["pdftoppm", *seite, "-scale-to", str(PIXEL), "-gray", "-png", "-singlefile", datei, bild])
            text, konfidenz = tesseract(bild + ".png")
            os.remove(bild + ".png")
            ocr += 1
            if konfidenz is not None:
                konfidenzen.append(konfidenz)
            if text:
                texte.append(text)
            elif konfidenz is not None:
                verworfen += 1
        return ergebnis(texte, konfidenzen, seiten, gelesen, ocr, verworfen)


def ergebnis(texte, konfidenzen, seiten, gelesen, ocr, verworfen):
    return {
        "text": "\n\n".join(t.strip() for t in texte if t.strip()),
        "konfidenz": round(sum(konfidenzen) / len(konfidenzen), 1) if konfidenzen else None,
        "seiten": seiten,
        "seiten_gelesen": gelesen,
        "seiten_ocr": ocr,
        "seiten_verworfen": verworfen,
    }


class Anfrage(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == "/gesund":
            return self.antworten(200, {"ok": True})
        self.antworten(404, {"fehler": "Nicht gefunden"})

    def do_POST(self):
        if self.path != "/erkennen":
            return self.antworten(404, {"fehler": "Nicht gefunden"})
        laenge = int(self.headers.get("Content-Length") or 0)
        if laenge <= 0:
            return self.antworten(400, {"fehler": "Leere Anfrage"})
        if laenge > MAX_BYTES:
            return self.antworten(413, {"fehler": "Datei zu groß"})
        daten = self.rfile.read(laenge)
        typ = art(daten)
        if typ is None:
            return self.antworten(415, {"fehler": "Kein PDF und kein Bild"})
        try:
            with GLEICHZEITIG:
                self.antworten(200, erkennen(daten, typ))
        except Unlesbar as e:
            self.antworten(422, {"fehler": str(e)})

    def antworten(self, status, daten):
        inhalt = json.dumps(daten, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(inhalt)))
        self.end_headers()
        self.wfile.write(inhalt)

    def log_message(self, format, *args):
        if self.path != "/gesund":
            print(f"{self.address_string()} {format % args}", flush=True)


if __name__ == "__main__":
    print(f"Texterkennung auf :8080 (Sprachen {SPRACHEN}, Wort ≥ {WORT_MIN}, Seite ≥ {SEITE_MIN})", flush=True)
    ThreadingHTTPServer(("0.0.0.0", 8080), Anfrage).serve_forever()
