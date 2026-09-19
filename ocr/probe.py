"""
Probe für den Texterkennungsdienst, läuft im Container gegen den laufenden Dienst:

    docker compose exec ocr python3 /app/probe.py
    docker compose exec -T ocr python3 /app/probe.py --beispiel scan.pdf > scan.pdf   (auch text.pdf, scan.png)

Baut die Testdateien selbst (PDF mit Text, gescanntes PDF, Bild, Rauschen), damit keine Beispieldateien im Repo liegen.
"""

import json
import os
import random
import struct
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
import zlib

ADRESSE = os.environ.get("OCR_PROBE_URL", "http://localhost:8080")
ZEILEN = ["Wartungsvertrag Heizungsanlage", "Rechnungsnummer 4711", "Pr\\374fung der Pumpe am Montag"]

fehler = []
bestanden = 0


def pruefen(name, bedingung, info=""):
    global bestanden
    print(("  ✓ " if bedingung else "  ✗ ") + name + ("" if bedingung else f"\n      {info}"))
    if bedingung:
        bestanden += 1
    else:
        fehler.append(name)


def pdf(objekte):
    """Minimales PDF aus Objekt-Inhalten (bytes), Objekt 1 ist der Katalog."""
    aus = bytearray(b"%PDF-1.4\n")
    stellen = []
    for nummer, inhalt in enumerate(objekte, start=1):
        stellen.append(len(aus))
        aus += f"{nummer} 0 obj\n".encode() + inhalt + b"\nendobj\n"
    xref = len(aus)
    aus += f"xref\n0 {len(objekte) + 1}\n0000000000 65535 f \n".encode()
    aus += b"".join(f"{s:010d} 00000 n \n".encode() for s in stellen)
    aus += f"trailer\n<< /Size {len(objekte) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()
    return bytes(aus)


def strom(daten, extra=b""):
    return b"<< /Length " + str(len(daten)).encode() + b" " + extra + b">>\nstream\n" + daten + b"\nendstream"


def text_pdf():
    befehle = "BT /F1 26 Tf 60 760 Td " + " 0 -48 Td ".join(f"({z}) Tj" for z in ZEILEN) + " ET"
    return pdf([
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
        strom(befehle.encode("latin-1")),
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
    ])


def jpeg_masse(daten):
    i = 2
    while i < len(daten):
        marker, laenge = daten[i + 1], struct.unpack(">H", daten[i + 2:i + 4])[0]
        if marker in (0xC0, 0xC1, 0xC2):
            hoehe, breite = struct.unpack(">HH", daten[i + 5:i + 9])
            return breite, hoehe, daten[i + 9]
        i += 2 + laenge
    raise ValueError("kein SOF im JPEG")


def bild_pdf(jpeg):
    breite, hoehe, kanaele = jpeg_masse(jpeg)
    farbe = b"/DeviceGray" if kanaele == 1 else b"/DeviceRGB"
    return pdf([
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /XObject << /Im0 5 0 R >> >> >>",
        strom(b"q 595 0 0 842 0 0 cm /Im0 Do Q"),
        strom(jpeg, b"/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode "
              % (breite, hoehe, farbe)),
    ])


def rauschen_png(breite=900, hoehe=600):
    zufall = random.Random(4711)
    roh = b"".join(b"\x00" + bytes(zufall.choice((0, 255)) for _ in range(breite)) for _ in range(hoehe))
    block = lambda typ, daten: struct.pack(">I", len(daten)) + typ + daten + struct.pack(">I", zlib.crc32(typ + daten))
    return (b"\x89PNG\r\n\x1a\n" + block(b"IHDR", struct.pack(">IIBBBBB", breite, hoehe, 8, 0, 0, 0, 0))
            + block(b"IDAT", zlib.compress(roh)) + block(b"IEND", b""))


def senden(daten):
    anfrage = urllib.request.Request(ADRESSE + "/erkennen", data=daten, method="POST",
                                     headers={"Content-Type": "application/octet-stream"})
    try:
        with urllib.request.urlopen(anfrage, timeout=600) as antwort:
            return antwort.status, json.loads(antwort.read())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read())


def gerendert(format):
    """Das Text-PDF als Bild, wie ein Scan (ohne Textebene)."""
    with tempfile.TemporaryDirectory() as ordner:
        pdf_datei = os.path.join(ordner, "text.pdf")
        with open(pdf_datei, "wb") as f:
            f.write(text_pdf())
        subprocess.run(["pdftoppm", "-scale-to-x", "1240", "-scale-to-y", "1754", "-gray", f"-{format}", "-singlefile",
                        pdf_datei, os.path.join(ordner, "scan")], check=True)
        with open(os.path.join(ordner, "scan." + ("jpg" if format == "jpeg" else format)), "rb") as f:
            return f.read()


BEISPIELE = {
    "text.pdf": text_pdf,
    "scan.pdf": lambda: bild_pdf(gerendert("jpeg")),
    "scan.png": lambda: gerendert("png"),
}

# python3 probe.py --beispiel scan.pdf > scan.pdf  gibt eine Beispieldatei aus, z. B. zum Hochladen im Wiki.
if len(sys.argv) == 3 and sys.argv[1] == "--beispiel" and sys.argv[2] in BEISPIELE:
    sys.stdout.buffer.write(BEISPIELE[sys.argv[2]]())
    sys.exit(0)

png = gerendert("png")
jpeg = gerendert("jpeg")

print("Texterkennung")

with urllib.request.urlopen(ADRESSE + "/gesund", timeout=10) as antwort:
    pruefen("Dienst antwortet auf /gesund", antwort.status == 200)

status, daten = senden(text_pdf())
pruefen("PDF mit Text wird ausgelesen, nicht erkannt",
        status == 200 and daten["seiten_ocr"] == 0 and "Rechnungsnummer 4711" in daten["text"] and "Prüfung" in daten["text"], daten)

status, daten = senden(png)
pruefen("Bild wird erkannt, mit Umlaut und Zahl, Konfidenz ≥ 80",
        status == 200 and "Rechnungsnummer" in daten["text"] and "4711" in daten["text"] and "Prüfung" in daten["text"]
        and (daten["konfidenz"] or 0) >= 80, daten)

status, daten = senden(bild_pdf(jpeg))
pruefen("Gescanntes PDF ohne Text geht durch die Erkennung",
        status == 200 and daten["seiten_ocr"] == 1 and "Heizungsanlage" in daten["text"], daten)

status, daten = senden(rauschen_png())
pruefen("Rauschen ergibt keinen Text", status == 200 and daten["text"] == "", daten)

status, daten = senden(b"PK\x03\x04 kein PDF")
pruefen("Anderer Dateityp wird mit 415 abgelehnt", status == 415, (status, daten))

status, daten = senden(b"%PDF-1.4\nkaputt")
pruefen("Kaputtes PDF ergibt 422 (nicht wiederholen)", status == 422, (status, daten))

print(f"\n{bestanden} bestanden, {len(fehler)} fehlgeschlagen")
sys.exit(1 if fehler else 0)
