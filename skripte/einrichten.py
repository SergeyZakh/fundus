#!/usr/bin/env python3
"""Richtet Rollen, Regale, Bücher, Vorlagen und Rechte in Fundus (BookStack) ein.

Das Skript darf beliebig oft laufen: Es legt nur an, was fehlt, und
überschreibt keine Inhalte. Rollen werden bei jedem Lauf auf den hier
beschriebenen Stand gebracht; Buchrechte nur bei Büchern ohne eigene Rechte,
damit spätere Freigaben (z. B. Azubi für ein Kundenbuch) erhalten bleiben.

Benötigt nur Python 3.10+ ohne Zusatzpakete. Aufruf:

    BOOKSTACK_URL=https://wiki.firma.intern \
    BOOKSTACK_TOKEN_ID=... BOOKSTACK_TOKEN_SECRET=... \
    python skripte/einrichten.py
    python skripte/einrichten.py --beispiele    # dazu drei Beispielartikel und zwei Beispielkonten
    python skripte/einrichten.py --texte        # Beschreibungen auf den Stand dieser Datei bringen

Beschreibungen von Bereichen, Themen und Abschnitten setzt das Skript sonst nur beim Anlegen, damit
Änderungen der Redaktion im Wiki bleiben. Mit --texte überschreibt es sie mit den Texten unten, etwa
nach einem Update von Fundus mit neuen Formulierungen. Artikel und Vorlagen fasst es nie an.

Das Token gehört einem Konto mit der Rolle Admin (Anleitung: docs/ENTWICKLUNG.md,
Kapitel „Ersteinrichtung“).

Aufbau der Datei: oben die Daten (ROLLEN, REGALE, ZUGANG), darunter ein kleiner API-Client
und die Schritte rollen_einrichten → vorlagen_einrichten → struktur_einrichten.
Wer Struktur oder Rechte ändern will, ändert nur die Daten oben.
"""

from __future__ import annotations

import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

VORLAGEN_ORDNER = Path(__file__).resolve().parent.parent / "vorlagen"
HANDBUCH_ORDNER = Path(__file__).resolve().parent.parent / "handbuch"
SYMBOL_ORDNER = Path(__file__).resolve().parent.parent / "theme" / "fundus" / "symbole"

# ---------------------------------------------------------------------------
# Rollen
# ---------------------------------------------------------------------------
# Der Wert in „gruppe“ ist der Name der Entra-Gruppe. BookStack vergleicht ihn
# in Kleinbuchstaben, Leerzeichen werden zu Bindestrichen.
#
# Grundsatz: Lesen und Bearbeiten von Inhalten gibt es auf Rollenebene nur für
# Redaktion, Technik und KI-Leser. Mitarbeiter und Azubis bekommen Inhalte
# ausschließlich über Rechte an Regalen und Büchern. Ein neu angelegtes Buch
# ist für sie also erst sichtbar, wenn jemand es freigibt; ein vergessenes
# Kundenbuch bleibt dadurch geschlossen statt offen.

INHALT_LESEN = [
    "bookshelf-view-all", "book-view-all", "chapter-view-all", "page-view-all",
]
INHALT_BEARBEITEN = INHALT_LESEN + [
    "chapter-create-all", "chapter-update-all", "chapter-delete-all",
    "page-create-all", "page-update-all", "page-delete-all",
    "book-update-all",
    "image-create-all", "image-update-all", "image-delete-all",
    "attachment-create-all", "attachment-update-all", "attachment-delete-all",
]
MITARBEITEN = [
    # Bilder und Anhänge beim Schreiben; ob man eine Seite bearbeiten darf,
    # entscheiden die Rechte am Buch.
    "image-create-all", "image-update-own", "image-delete-own",
    "attachment-create-all", "attachment-update-own", "attachment-delete-own",
    "comment-create-all", "comment-update-own", "comment-delete-own",
    # Seit BookStack v26.05 ein eigenes Recht: ohne es keine Versionsliste.
    "revision-view-all",
    "content-export", "receive-notifications",
]

ROLLEN = [
    {
        "name": "Redaktion", "gruppe": "WIKI-Redaktion",
        "beschreibung": "Legt Bereiche und Themen an, pflegt Vorlagen und Rechte.",
        "rechte": sorted(set(INHALT_BEARBEITEN + MITARBEITEN + [
            "bookshelf-create-all", "bookshelf-update-all", "bookshelf-delete-all",
            "book-create-all", "book-delete-all",
            "image-update-all", "image-delete-all",
            "attachment-update-all", "attachment-delete-all",
            "comment-update-all", "comment-delete-all",
            "restrictions-manage-all", "templates-manage", "editor-change",
        ])),
    },
    {
        "name": "Technik", "gruppe": "WIKI-Technik",
        "beschreibung": "Liest und bearbeitet alle Bereiche, auch Kunden.",
        "rechte": sorted(set(INHALT_BEARBEITEN + MITARBEITEN)),
    },
    {
        "name": "Mitarbeiter", "gruppe": "WIKI-Mitarbeiter",
        "beschreibung": "Liest alles außer Kunden, bearbeitet Anleitungen und Prozesse.",
        "rechte": sorted(MITARBEITEN),
    },
    {
        "name": "Azubi", "gruppe": "WIKI-Azubi",
        "beschreibung": "Wie Mitarbeiter; Kundenthemen nur nach Freigabe.",
        "rechte": sorted(MITARBEITEN),
    },
    {
        # Für Werkzeuge, die über die API lesen. Der eingebaute KI-Chat Fundus braucht die
        # Rolle nicht (er liest direkt im Theme mit den Rechten der fragenden Person).
        # Keine Entra-Gruppe: Die Rolle bekommt nur ein technisches Konto mit API-Token.
        "name": "KI-Leser", "gruppe": "",
        "beschreibung": "Nur lesen über die API, für externe Werkzeuge.",
        "rechte": sorted(INHALT_LESEN + ["access-api"]),
    },
]
ADMIN_GRUPPE = "WIKI-Admin"
# Beispielrollen einer frischen Installation. Sie würden über den Namensabgleich
# jede Entra-Gruppe „Editor“ oder „Viewer“ aufnehmen und verwirren nur.
MITGELIEFERTE_ROLLEN = ["Editor", "Viewer"]
# Die beiden Systemrollen bringen englische Beschreibungen mit; in der Rollenliste stünden sie
# sonst als einzige nicht auf Deutsch.
SYSTEMROLLEN = {
    "admin": "Verwaltet das ganze Wiki: Einstellungen, Rollen und alle Inhalte.",
    "public": "Für Besucher ohne Anmeldung, falls der öffentliche Zugang eingeschaltet ist.",
}

# ---------------------------------------------------------------------------
# Struktur
# ---------------------------------------------------------------------------
# „zugang“ beschreibt, was Mitarbeiter und Azubis in einem Regal dürfen:
#   kunden     – nichts; Azubis sehen das Regal, aber nur freigegebene Bücher
#   bearbeiten – lesen, Seiten und Kapitel anlegen und bearbeiten
#   lesen      – nur lesen

VORLAGEN_BUCH = {
    "name": "Vorlagen", "symbol": "vorlage",
    "beschreibung": "Artikelvorlagen für das ganze Wiki. Änderungen hier wirken auf alle neuen Artikel.",
}
VORLAGEN = {
    "anleitung": "Vorlage: Anleitung",
    "kundenueberblick": "Vorlage: Kundenüberblick",
    "prozess": "Vorlage: Prozess oder Richtlinie",
    "checkliste": "Vorlage: Checkliste",
}

REGALE = [
    {
        "name": "Kunden", "symbol": "kunden",
        "beschreibung": "Ein Thema je Kunde: Überblick, Umgebung, Abläufe, Änderungen. Zugangsdaten stehen im Passwort-Manager, nie hier.",
        "zugang": "kunden",
        "buecher": [
            {
                "name": "Musterkunde GmbH", "symbol": "kunde",
                "beschreibung": "Beispiel für den Aufbau eines Kundenthemas. Zum Anlegen eines neuen Kunden dieses Thema kopieren.",
                "vorlage": "anleitung",
                "kapitel": [
                    {"name": "Überblick", "vorlage": "kundenueberblick",
                     "seiten": [{"name": "Kundenüberblick", "aus": "kundenueberblick"}]},
                    {"name": "Umgebung", "beschreibung": "Netz, Server, Clients, Microsoft 365"},
                    {"name": "Abläufe und Besonderheiten"},
                    {"name": "Änderungsprotokoll"},
                ],
            },
        ],
    },
    {
        "name": "Anleitungen & Technik", "symbol": "anleitung",
        "beschreibung": "Schritt-für-Schritt-Anleitungen und technisches Wissen, nach Themen sortiert.",
        "zugang": "bearbeiten",
        "buecher": [
            {"name": "Microsoft 365", "symbol": "cloud", "beschreibung": "Exchange Online, Teams, SharePoint, Entra ID, Intune.", "vorlage": "anleitung"},
            {"name": "Netzwerk", "symbol": "netzwerk", "beschreibung": "Firewalls, Switche, WLAN, VPN.", "vorlage": "anleitung"},
            {"name": "Server & Virtualisierung", "symbol": "server", "beschreibung": "Windows Server, Hypervisoren, Datensicherung.", "vorlage": "anleitung"},
            {"name": "Security", "symbol": "schild", "beschreibung": "Virenschutz, Härtung, Vorfälle.", "vorlage": "anleitung"},
            {"name": "Clients & Drucker", "symbol": "bildschirm", "beschreibung": "Arbeitsplätze, Notebooks, Drucker und Scanner.", "vorlage": "anleitung"},
            {"name": "Telefonie", "symbol": "telefon", "beschreibung": "Anbieter, Endgeräte, Rufnummern.", "vorlage": "anleitung"},
            {"name": "Tools", "symbol": "werkzeug", "beschreibung": "Fernwartung, Virenschutz, Zeiterfassung und weitere Werkzeuge.", "vorlage": "anleitung"},
        ],
    },
    {
        "name": "Prozesse & Richtlinien", "symbol": "ablauf",
        "beschreibung": "Wie wir arbeiten: Abläufe, Regeln und Checklisten.",
        "zugang": "bearbeiten",
        "buecher": [
            {"name": "Onboarding & Offboarding", "symbol": "person-neu", "beschreibung": "Neue und ausscheidende Kollegen, intern und beim Kunden.", "vorlage": "prozess"},
            {"name": "Ticketablauf", "symbol": "ticket", "beschreibung": "Vom Anruf bis zum geschlossenen Ticket.", "vorlage": "prozess"},
            {"name": "Notfall", "symbol": "notfall", "beschreibung": "Was bei Ausfällen und Sicherheitsvorfällen zu tun ist.", "vorlage": "prozess"},
            {"name": "Datenschutz & Informationssicherheit", "symbol": "privat", "beschreibung": "Richtlinien und Pflichten.", "vorlage": "prozess"},
            {"name": "Checklisten", "symbol": "checkliste", "beschreibung": "Zum Abhaken bei wiederkehrenden Aufgaben.", "vorlage": "checkliste"},
        ],
    },
    {
        "name": "Ausbildung & interne Tools", "symbol": "ausbildung",
        "beschreibung": "Wissen für Azubis und Anleitungen zu unseren eigenen Werkzeugen.",
        "zugang": "lesen",
        "buecher": [
            {"name": "Wissen für Azubis", "symbol": "idee", "beschreibung": "Grundlagen, Prüfungsvorbereitung, erste Schritte im Betrieb.", "vorlage": "anleitung"},
            {"name": "Interne Werkzeuge", "symbol": "code", "beschreibung": "Selbst gebaute Werkzeuge und Skripte.", "vorlage": "anleitung"},
            {"name": "So funktioniert das Wiki", "symbol": "hilfe",
             "beschreibung": "Handbuch: lesen, suchen, schreiben und für die Redaktion.",
             "kapitel": [
                 {"name": "Erste Schritte", "seiten": [
                     {"name": "Schnellstart in 5 Minuten", "datei": "00-schnellstart"},
                 ]},
                 {"name": "Lesen und finden", "seiten": [
                     {"name": "Überblick", "datei": "01-ueberblick"},
                     {"name": "Suchen und finden", "datei": "02-suchen"},
                     {"name": "Artikel lesen", "datei": "03-lesen"},
                     {"name": "Fundus fragen", "datei": "13-fundus"},
                     {"name": "Ich sehe etwas nicht", "datei": "14-ich-sehe-nichts"},
                 ]},
                 {"name": "Schreiben", "seiten": [
                     {"name": "Neuen Artikel anlegen", "datei": "04-artikel-anlegen"},
                     {"name": "Text gestalten", "datei": "05-text-gestalten"},
                     {"name": "Bilder, Anhänge und Zeichnungen", "datei": "06-bilder-anhaenge-zeichnungen"},
                     {"name": "Speichern und Versionen", "datei": "07-speichern-versionen"},
                     {"name": "Was nie ins Wiki gehört", "datei": "08-was-nie-ins-wiki"},
                 ]},
                 {"name": "Für die Redaktion", "seiten": [
                     {"name": "Bereiche und Themen anlegen", "datei": "09-bereiche-themen"},
                     {"name": "Rechte und Freigaben", "datei": "10-rechte-freigaben"},
                     {"name": "Personen und Konten", "datei": "11-personen-konten"},
                     {"name": "Vorlagen pflegen", "datei": "12-vorlagen"},
                     {"name": "Rückmeldungen, Titel und Profilbilder", "datei": "15-rueckmeldungen-titel"},
                 ]},
             ]},
        ],
    },
]

LESEN = {"view": True, "create": False, "update": False, "delete": False}
BEARBEITEN = {"view": True, "create": True, "update": True, "delete": False}

# Rechte je Zugangsart: (Regal, Buch) → {Rolle: Recht}. Nicht genannte Rollen
# erben ihre Rollenrechte (Redaktion und Technik sehen dadurch alles).
ZUGANG = {
    "kunden": ({"Azubi": LESEN}, {}),
    "bearbeiten": ({"Mitarbeiter": LESEN, "Azubi": LESEN},
                   {"Mitarbeiter": BEARBEITEN, "Azubi": BEARBEITEN}),
    "lesen": ({"Mitarbeiter": LESEN, "Azubi": LESEN},
              {"Mitarbeiter": LESEN, "Azubi": LESEN}),
}

# ---------------------------------------------------------------------------
# Beispielartikel und -konten (nur mit --beispiele)
# ---------------------------------------------------------------------------
# Damit ein frisches Wiki nicht leer ist und die Handbuch-Bilder (skripte/handbuch-bilder) etwas zeigen.
# Jeder Artikel nutzt, was ein guter Artikel hat: Ziel, Voraussetzungen, Schritte, Hinweiskasten, Code.
# "zweite_fassung" erzeugt eine zweite Version (für das Bild „Versionen“).
#
# Die Konten zeigen das Wiki aus Sicht einer Rolle: Rauchtest und Handbuch-Bilder finden sie über die E-Mail,
# die Theme-Tests nutzen dieselben Adressen. Ohne Passwort: Anmelden kann sich damit niemand, bis ein Admin eins setzt.
BEISPIEL_KONTEN = [
    {"name": "Mia Mitarbeiterin", "email": "mitarbeiter@firma.intern", "rolle": "Mitarbeiter"},
    {"name": "Alex Azubi", "email": "azubi@firma.intern", "rolle": "Azubi"},
]

BEISPIELE = {
    "buch": "Microsoft 365",
    "kapitel": "Exchange Online",
    "artikel": [
        {
            "name": "Freigegebenes Postfach einrichten",
            "html": (
                '<p class="callout info">Ein freigegebenes Postfach braucht keine eigene Lizenz, solange es unter 50 GB bleibt.</p>'
                "<h2>Ziel</h2><p>Ein gemeinsames Postfach, etwa info@ oder support@, das mehrere Personen lesen "
                "und aus dem sie antworten können.</p>"
                "<h2>Voraussetzungen</h2><ul><li>Rolle „Exchange-Administrator“ oder „Globaler Administrator“</li>"
                "<li>Die Adresse ist noch frei (kein Konto und keine Gruppe mit derselben Adresse)</li></ul>"
                "<h2>Schritte</h2><ol>"
                "<li>Im Exchange Admin Center unter <strong>Empfänger → Postfächer</strong> auf "
                "<strong>Freigegebenes Postfach hinzufügen</strong> klicken.</li>"
                "<li>Anzeigenamen und Adresse eintragen, speichern.</li>"
                "<li>Das neue Postfach öffnen, unter <strong>Delegierung</strong> die Personen bei "
                "<strong>Lesen und verwalten</strong> und <strong>Senden als</strong> eintragen.</li>"
                "</ol>"
                '<p class="callout warning">Änderungen an den Berechtigungen brauchen bis zu einer Stunde, bis Outlook '
                "das Postfach anzeigt.</p>"
                "<h2>Prüfen</h2><p>In PowerShell sehen, wer Zugriff hat:</p>"
                '<pre><code class="language-powershell">Connect-ExchangeOnline\n'
                'Get-MailboxPermission -Identity info@firma.example | Where-Object { $_.User -notlike "NT AUTHORITY*" }'
                "</code></pre>"
            ),
            "zweite_fassung": (
                "Die Adresse ist noch frei (kein Konto und keine Gruppe mit derselben Adresse)",
                "Die Adresse ist noch frei (kein Konto, keine Gruppe und kein Alias mit derselben Adresse)",
            ),
        },
        {
            "name": "Weiterleitung für ein Postfach einrichten",
            "html": (
                "<h2>Ziel</h2><p>Post an ein Postfach zusätzlich an eine andere Adresse schicken, etwa während "
                "einer Abwesenheit.</p>"
                "<h2>Schritte</h2><ol>"
                "<li>Im Exchange Admin Center das Postfach öffnen.</li>"
                "<li><strong>E-Mail-Fluss → Weiterleitung verwalten</strong> wählen.</li>"
                "<li>Zieladresse eintragen und festlegen, ob eine Kopie im Postfach bleibt.</li>"
                "</ol>"
                '<p class="callout danger">Weiterleitungen nach außen blockiert die Standardrichtlinie. Nur mit Freigabe '
                "der Technik ändern.</p>"
            ),
        },
        {
            "name": "Postfachberechtigungen prüfen",
            "html": (
                "<h2>Ziel</h2><p>Herausfinden, wer auf ein Postfach zugreifen oder daraus senden darf.</p>"
                "<h2>Schritte</h2><ol><li>PowerShell öffnen und anmelden.</li>"
                "<li>Berechtigungen abfragen:</li></ol>"
                '<pre><code class="language-powershell">Connect-ExchangeOnline\n'
                "Get-MailboxPermission -Identity support@firma.example\n"
                "Get-RecipientPermission -Identity support@firma.example</code></pre>"
                '<p class="callout info">Get-MailboxPermission zeigt Lesen und verwalten, Get-RecipientPermission zeigt Senden als.</p>'
            ),
        },
    ],
}


# ---------------------------------------------------------------------------
# API
# ---------------------------------------------------------------------------

class Api:
    def __init__(self, url: str, token_id: str, token_secret: str):
        self.basis = url.rstrip("/") + "/api/"
        self.kopf = {
            "Authorization": f"Token {token_id}:{token_secret}",
            "Accept": "application/json",
            "Content-Type": "application/json",
        }

    def anfrage(self, methode: str, pfad: str, daten: dict | None = None) -> dict:
        koerper = json.dumps(daten).encode() if daten is not None else None
        for versuch in range(5):
            req = urllib.request.Request(self.basis + pfad, data=koerper, method=methode, headers=self.kopf)
            try:
                with urllib.request.urlopen(req, timeout=60) as antwort:
                    inhalt = antwort.read()
                    return json.loads(inhalt) if inhalt else {}
            except urllib.error.HTTPError as fehler:
                # BookStack begrenzt API-Anfragen pro Minute (Standard 180).
                if fehler.code == 429 and versuch < 4:
                    time.sleep(int(fehler.headers.get("Retry-After", "10")))
                    continue
                text = fehler.read().decode(errors="replace")
                raise SystemExit(f"API-Fehler {fehler.code} bei {methode} {pfad}:\n{text}") from None
        raise SystemExit(f"API antwortet dauerhaft mit 429 bei {methode} {pfad}")

    def bild_hochladen(self, seiten_id: int, datei: Path) -> dict:
        # Die Bilder-API erwartet multipart/form-data; ohne Zusatzpakete von Hand gebaut.
        grenze = "----fundus" + os.urandom(8).hex()
        teile = []
        nl = chr(13) + chr(10)
        for feld, wert in (("type", "gallery"), ("uploaded_to", str(seiten_id)), ("name", datei.stem)):
            teile.append(f'--{grenze}{nl}Content-Disposition: form-data; name="{feld}"{nl}{nl}{wert}{nl}'.encode())
        teile.append(f'--{grenze}{nl}Content-Disposition: form-data; name="image"; filename="{datei.name}"{nl}'
                     f'Content-Type: image/png{nl}{nl}'.encode() + datei.read_bytes() + nl.encode())
        teile.append(f"--{grenze}--{nl}".encode())
        kopf = {**self.kopf, "Content-Type": f"multipart/form-data; boundary={grenze}"}
        req = urllib.request.Request(self.basis + "image-gallery", data=b"".join(teile), method="POST", headers=kopf)
        try:
            with urllib.request.urlopen(req, timeout=120) as antwort:
                return json.loads(antwort.read())
        except urllib.error.HTTPError as fehler:
            text = fehler.read().decode(errors="replace")
            raise SystemExit(f"Bild-Upload fehlgeschlagen ({fehler.code}) für {datei.name}: {text}") from None

    def alle(self, pfad: str, **filter_: str) -> list[dict]:
        ergebnis, offset = [], 0
        while True:
            parameter = {"count": "500", "offset": str(offset)}
            parameter.update({f"filter[{k}]": v for k, v in filter_.items()})
            seite = self.anfrage("GET", f"{pfad}?{urllib.parse.urlencode(parameter)}")
            ergebnis += seite["data"]
            offset += len(seite["data"])
            if not seite["data"] or offset >= seite["total"]:
                return ergebnis

    def finde(self, pfad: str, name: str, **filter_: str) -> dict | None:
        # Der Namensfilter vergleicht ohne Rücksicht auf Groß-/Kleinschreibung,
        # deshalb hier noch einmal exakt prüfen.
        treffer = [e for e in self.alle(pfad, name=name, **filter_) if e["name"] == name]
        return treffer[0] if treffer else None


TEXTE = "--texte" in sys.argv[1:]


def beschreibung_angleichen(api: Api, pfad: str, eintrag: dict, soll: str | None) -> None:
    """Mit --texte: Beschreibung im Wiki auf den Text aus dieser Datei setzen, falls sie abweicht."""
    if not TEXTE or soll is None:
        return
    if (eintrag.get("description") or "").strip() != soll.strip():
        api.anfrage("PUT", f"{pfad}/{eintrag['id']}", {"description": soll})
        meldung("✓", f"Beschreibung: {eintrag['name']}")


def meldung(zeichen: str, text: str) -> None:
    print(f"  {zeichen} {text}")


# ---------------------------------------------------------------------------
# Schritte
# ---------------------------------------------------------------------------

def rollen_einrichten(api: Api) -> dict[str, int]:
    print("Rollen")
    vorhanden = {r["display_name"]: r for r in api.alle("roles")}

    admin = next(r for r in vorhanden.values() if r["system_name"] == "admin")
    if admin["external_auth_id"] != ADMIN_GRUPPE.lower():
        api.anfrage("PUT", f"roles/{admin['id']}", {"external_auth_id": ADMIN_GRUPPE.lower()})
    meldung("✓", f"{admin['display_name']} ← Gruppe {ADMIN_GRUPPE}")
    for rolle in vorhanden.values():
        beschreibung = SYSTEMROLLEN.get(rolle["system_name"])
        if beschreibung and rolle.get("description") != beschreibung:
            api.anfrage("PUT", f"roles/{rolle['id']}", {"description": beschreibung})
            meldung("✓", f"{rolle['display_name']}: Beschreibung auf Deutsch")

    ids = {"Admin": admin["id"]}
    for rolle in ROLLEN:
        daten = {
            "display_name": rolle["name"],
            "description": rolle["beschreibung"],
            "external_auth_id": rolle["gruppe"].lower(),
            "permissions": rolle["rechte"],
        }
        if rolle["name"] in vorhanden:
            rid = vorhanden[rolle["name"]]["id"]
            api.anfrage("PUT", f"roles/{rid}", daten)
            meldung("✓", f"{rolle['name']} aktualisiert")
        else:
            rid = api.anfrage("POST", "roles", daten)["id"]
            meldung("+", f"{rolle['name']} angelegt")
        ids[rolle["name"]] = rid

    for name in MITGELIEFERTE_ROLLEN:
        rolle = vorhanden.get(name)
        if not rolle:
            continue
        if rolle["users_count"] == 0:
            api.anfrage("DELETE", f"roles/{rolle['id']}")
            meldung("−", f"Beispielrolle {name} entfernt")
        else:
            meldung("!", f"Beispielrolle {name} hat noch Nutzer und bleibt; bitte von Hand prüfen")
    return ids


def symbol_setzen(api: Api, pfad: str, eid: int, symbol: str | None) -> None:
    """Setzt das Schlagwort „Symbol“, das das Theme als Kachelsymbol zeigt.
    Ein von Hand gewähltes gültiges Symbol bleibt; andere Schlagwörter bleiben erhalten."""
    if not symbol:
        return
    tags = api.anfrage("GET", f"{pfad}/{eid}").get("tags", [])
    vorhanden = [t for t in tags if t["name"].lower() == "symbol"]
    # Ein gültiges Symbol (Datei im Theme vorhanden) bleibt; ein fehlendes oder unbekanntes wird ersetzt.
    if vorhanden and (SYMBOL_ORDNER / f"{vorhanden[0]['value'].strip().lower()}.svg").is_file():
        return
    tags = [{"name": t["name"], "value": t["value"]} for t in tags if t not in vorhanden] + [{"name": "Symbol", "value": symbol}]
    api.anfrage("PUT", f"{pfad}/{eid}", {"tags": tags})


def handbuch_seite(api: Api, kapitel_id: int, name: str, datei: Path) -> None:
    """Legt eine Handbuchseite an und lädt ihre Bilder hoch.

    Bilder brauchen eine Seite, zu der sie gehören; deshalb erst die Seite ohne Bilder
    anlegen, dann jedes Bild hochladen und die Seite mit den echten Adressen aktualisieren.
    """
    html = datei.read_text(encoding="utf-8")
    bilder = sorted(set(re.findall(r'src="(bilder/[^"]+)"', html)))
    seite = api.anfrage("POST", "pages", {"chapter_id": kapitel_id, "name": name,
                                          "html": re.sub(r'<p><img [^>]*></p>', '', html)})
    if not bilder:
        return
    for pfad in bilder:
        if not (datei.parent / pfad).exists():
            # Die Bilder entstehen erst aus einem Wiki mit Inhalt (skripte/handbuch-bilder). Fehlt eins,
            # bleibt die Seite ohne; skripte/handbuch-einspielen.sh bringt es später nach.
            html = re.sub(r'<p><img [^>]*src="' + re.escape(pfad) + r'"[^>]*></p>', '', html)
            meldung("!", f"Bild {pfad} fehlt, {name} ohne Bild angelegt")
            continue
        bild = api.bild_hochladen(seite["id"], datei.parent / pfad)
        html = html.replace(f'src="{pfad}"', f'src="{bild["url"]}"')
    api.anfrage("PUT", f"pages/{seite['id']}", {"html": html})


def rechte_liste(rollen_ids: dict[str, int], zuordnung: dict[str, dict]) -> list[dict]:
    return [{"role_id": rollen_ids[name], **recht} for name, recht in zuordnung.items()]


def hat_eigene_rechte(api: Api, typ: str, eid: int) -> bool:
    daten = api.anfrage("GET", f"content-permissions/{typ}/{eid}")
    return bool(daten["role_permissions"]) or not daten["fallback_permissions"]["inheriting"]


def rechte_setzen(api: Api, typ: str, eid: int, rollen_ids: dict[str, int], zuordnung: dict[str, dict]) -> None:
    api.anfrage("PUT", f"content-permissions/{typ}/{eid}", {
        "role_permissions": rechte_liste(rollen_ids, zuordnung),
        "fallback_permissions": {"inheriting": True},
    })


def vorlagen_einrichten(api: Api, rollen_ids: dict[str, int]) -> tuple[dict[str, int], list[str]]:
    print("Vorlagen")
    buch = api.finde("books", VORLAGEN_BUCH["name"])
    if not buch:
        buch = api.anfrage("POST", "books", {"name": VORLAGEN_BUCH["name"], "description": VORLAGEN_BUCH["beschreibung"]})
        meldung("+", f"Buch {buch['name']} angelegt")
    symbol_setzen(api, "books", buch["id"], VORLAGEN_BUCH["symbol"])
    if TEXTE:
        beschreibung_angleichen(api, "books", api.anfrage("GET", f"books/{buch['id']}"), VORLAGEN_BUCH["beschreibung"])
    if not hat_eigene_rechte(api, "book", buch["id"]):
        # Alle müssen Vorlagen lesen können, sonst greift die Standardvorlage beim Anlegen nicht.
        rechte_setzen(api, "book", buch["id"], rollen_ids, {"Mitarbeiter": LESEN, "Azubi": LESEN})
        meldung("✓", "Rechte: alle lesen, Redaktion bearbeitet")

    ids, nicht_markiert = {}, []
    for schluessel, name in VORLAGEN.items():
        seite = api.finde("pages", name, book_id=str(buch["id"]))
        if not seite:
            html = (VORLAGEN_ORDNER / f"{schluessel}.html").read_text(encoding="utf-8")
            seite = api.anfrage("POST", "pages", {"book_id": buch["id"], "name": name, "html": html})
            meldung("+", f"{name} angelegt")
        else:
            seite = api.anfrage("GET", f"pages/{seite['id']}")
        ids[schluessel] = seite["id"]
        if not seite.get("template"):
            nicht_markiert.append(name)
    return ids, nicht_markiert


def standardvorlage(api: Api, typ: str, eintrag: dict, vorlage: str | None, vorlagen_ids: dict[str, int], markiert: bool) -> None:
    if not vorlage or not markiert:
        return
    ziel = vorlagen_ids[vorlage]
    if eintrag.get("default_template_id") != ziel:
        pfad = "books" if typ == "book" else "chapters"
        api.anfrage("PUT", f"{pfad}/{eintrag['id']}", {"default_template_id": ziel})
        meldung("✓", f"Standardvorlage für {eintrag['name']}: {VORLAGEN[vorlage]}")


def struktur_einrichten(api: Api, rollen_ids: dict[str, int], vorlagen_ids: dict[str, int], markiert: bool) -> None:
    for regal in REGALE:
        print(f"Regal {regal['name']}")
        regal_rechte, buch_rechte = ZUGANG[regal["zugang"]]
        buch_ids = []

        for b in regal["buecher"]:
            buch = api.finde("books", b["name"])
            if not buch:
                buch = api.anfrage("POST", "books", {"name": b["name"], "description": b["beschreibung"]})
                meldung("+", f"Buch {b['name']}")
            symbol_setzen(api, "books", buch["id"], b.get("symbol"))
            buch = api.anfrage("GET", f"books/{buch['id']}")
            beschreibung_angleichen(api, "books", buch, b.get("beschreibung"))
            buch_ids.append(buch["id"])
            standardvorlage(api, "book", buch, b.get("vorlage"), vorlagen_ids, markiert)

            kapitel_vorhanden = {k["name"]: k for k in buch["contents"] if k["type"] == "chapter"}
            for k in b.get("kapitel", []):
                kapitel = kapitel_vorhanden.get(k["name"])
                if not kapitel:
                    kapitel = api.anfrage("POST", "chapters", {
                        "book_id": buch["id"], "name": k["name"], "description": k.get("beschreibung", ""),
                    })
                    meldung("+", f"Kapitel {b['name']} / {k['name']}")
                kapitel = api.anfrage("GET", f"chapters/{kapitel['id']}")
                beschreibung_angleichen(api, "chapters", kapitel, k.get("beschreibung"))
                standardvorlage(api, "chapter", kapitel, k.get("vorlage"), vorlagen_ids, markiert)

                seiten_vorhanden = {s["name"] for s in kapitel.get("pages", [])}
                for s in k.get("seiten", []):
                    if s["name"] not in seiten_vorhanden:
                        if "datei" in s:
                            handbuch_seite(api, kapitel["id"], s["name"], HANDBUCH_ORDNER / f"{s['datei']}.html")
                        else:
                            html = (VORLAGEN_ORDNER / f"{s['aus']}.html").read_text(encoding="utf-8")
                            api.anfrage("POST", "pages", {"chapter_id": kapitel["id"], "name": s["name"], "html": html})
                        meldung("+", f"Seite {b['name']} / {k['name']} / {s['name']}")

        vorhanden = api.finde("shelves", regal["name"])
        if not vorhanden:
            vorhanden = api.anfrage("POST", "shelves", {
                "name": regal["name"], "description": regal["beschreibung"], "books": buch_ids,
            })
            meldung("+", f"Regal {regal['name']}")
        else:
            # Bücher, die die Redaktion später ins Regal gestellt hat, bleiben drin.
            bisher = [bk["id"] for bk in api.anfrage("GET", f"shelves/{vorhanden['id']}")["books"]]
            fehlend = [i for i in buch_ids if i not in bisher]
            if fehlend:
                api.anfrage("PUT", f"shelves/{vorhanden['id']}", {"books": bisher + fehlend})
                meldung("✓", f"{len(fehlend)} Buch/Bücher ins Regal gestellt")

        if TEXTE:
            beschreibung_angleichen(api, "shelves", api.anfrage("GET", f"shelves/{vorhanden['id']}"), regal["beschreibung"])
        symbol_setzen(api, "shelves", vorhanden["id"], regal.get("symbol"))
        rechte_setzen(api, "bookshelf", vorhanden["id"], rollen_ids, regal_rechte)

        # Alle Bücher im Regal, auch später von Hand angelegte: Wer noch keine
        # eigenen Rechte hat, bekommt den Standard des Regals.
        for bk in api.anfrage("GET", f"shelves/{vorhanden['id']}")["books"]:
            if buch_rechte and not hat_eigene_rechte(api, "book", bk["id"]):
                rechte_setzen(api, "book", bk["id"], rollen_ids, buch_rechte)
                meldung("✓", f"Rechte gesetzt: {bk['name']}")


def beispiele_einrichten(api: Api, rollen_ids: dict[str, int]) -> None:
    print("Beispielkonten")
    for konto in BEISPIEL_KONTEN:
        if any(u["email"] == konto["email"] for u in api.alle("users", email=konto["email"])):
            meldung("=", konto["name"])
            continue
        api.anfrage("POST", "users", {"name": konto["name"], "email": konto["email"], "roles": [rollen_ids[konto["rolle"]]]})
        meldung("+", f"{konto['name']} ({konto['rolle']}, ohne Passwort)")

    print("Beispielartikel")
    buch = api.finde("books", BEISPIELE["buch"])
    if not buch:
        meldung("!", f"Thema „{BEISPIELE['buch']}“ fehlt, Beispiele übersprungen")
        return
    kapitel = api.finde("chapters", BEISPIELE["kapitel"], book_id=str(buch["id"]))
    if not kapitel:
        kapitel = api.anfrage("POST", "chapters", {"book_id": buch["id"], "name": BEISPIELE["kapitel"]})
        meldung("+", f"Abschnitt {BEISPIELE['kapitel']}")
    for artikel in BEISPIELE["artikel"]:
        if api.finde("pages", artikel["name"], book_id=str(buch["id"])):
            meldung("=", artikel["name"])
            continue
        seite = api.anfrage("POST", "pages", {"chapter_id": kapitel["id"], "name": artikel["name"], "html": artikel["html"]})
        if "zweite_fassung" in artikel:
            alt, neu = artikel["zweite_fassung"]
            api.anfrage("PUT", f"pages/{seite['id']}", {"html": artikel["html"].replace(alt, neu)})
        meldung("+", artikel["name"])


def main() -> None:
    fehlend = [v for v in ("BOOKSTACK_URL", "BOOKSTACK_TOKEN_ID", "BOOKSTACK_TOKEN_SECRET") if not os.environ.get(v)]
    if fehlend:
        raise SystemExit("Fehlende Umgebungsvariablen: " + ", ".join(fehlend))

    api = Api(os.environ["BOOKSTACK_URL"], os.environ["BOOKSTACK_TOKEN_ID"], os.environ["BOOKSTACK_TOKEN_SECRET"])
    system = api.anfrage("GET", "system")
    print(f"BookStack {system['version']} unter {system['base_url']}\n")

    rollen_ids = rollen_einrichten(api)
    vorlagen_ids, nicht_markiert = vorlagen_einrichten(api, rollen_ids)
    struktur_einrichten(api, rollen_ids, vorlagen_ids, markiert=not nicht_markiert)
    if "--beispiele" in sys.argv[1:]:
        print()
        beispiele_einrichten(api, rollen_ids)

    print()
    if nicht_markiert:
        # Die API kann das Vorlagen-Kennzeichen nicht setzen; ohne Kennzeichen
        # lehnt BookStack eine Seite als Standardvorlage ab.
        print("Noch ein Handgriff: Diese Artikel im Thema „Vorlagen“ als Vorlage kennzeichnen")
        for name in nicht_markiert:
            print(f"  • {name}")
        print("  Artikel öffnen → Bearbeiten → rechts „Vorlagen“ → „Artikel ist eine Vorlage“ → Artikel speichern.")
        print("  Danach dieses Skript noch einmal starten; es setzt dann die Standardvorlagen.")
    else:
        print("Fertig.")


if __name__ == "__main__":
    # Zeilenweise ausgeben, auch wenn die Ausgabe umgeleitet wird: Die Einrichtung dauert Minuten, und ohne
    # Fortschritt hält man sie für hängengeblieben.
    sys.stdout.reconfigure(encoding="utf-8", line_buffering=True)
    main()
