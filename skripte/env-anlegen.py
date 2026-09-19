"""
Legt die .env aus .env.example an und ersetzt alle Platzhalter durch Zufallswerte.

    python skripte/env-anlegen.py            Betrieb: Adressen danach von Hand anpassen
    python skripte/env-anlegen.py --lokal    Test auf dem eigenen Rechner (localhost:6875, draw.io lokal)

Eine vorhandene .env wird nie überschrieben. Nur Python-Standardbibliothek, läuft unter Windows, macOS und Linux.
"""

import base64
import re
import secrets
import string
import sys
from pathlib import Path

WURZEL = Path(__file__).resolve().parent.parent
VORLAGE = WURZEL / ".env.example"
ZIEL = WURZEL / ".env"


def passwort(laenge=32):
    # Nur Buchstaben und Ziffern: keine Probleme mit Anführungszeichen oder $ in der .env.
    zeichen = string.ascii_letters + string.digits
    return "".join(secrets.choice(zeichen) for _ in range(laenge))


# Variable → Wert. Nur aktive Zeilen (ohne # davor) werden ersetzt.
ZUFALL = {
    # Laravel erwartet 32 zufällige Bytes, Base64-kodiert, mit Präfix.
    "APP_KEY": "base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
    "DB_PASSWORD": passwort(),
    "KEYCLOAK_ADMIN_PASSWORD": passwort(),
    "KEYCLOAK_DB_PASSWORD": passwort(),
    # Entspricht openssl rand -hex 32.
    "SICHERUNG_KOPIE_SCHLUESSEL": secrets.token_hex(32),
}

LOKAL = {
    "APP_URL": "http://localhost:6875",
    "DRAWIO_SERVER_URL": "http://localhost:6876/",
    "DRAWIO_URL": "http://localhost:6876/?embed=1&proto=json&spin=1&configure=1&offline=1&lang=de",
}


def setzen(text, name, wert):
    muster = re.compile(rf"^{re.escape(name)}=.*$", re.MULTILINE)
    if not muster.search(text):
        sys.exit(f"{name} fehlt in .env.example")
    return muster.sub(lambda _: f"{name}={wert}", text, count=1)


def main():
    # Windows-Konsolen nehmen sonst die ANSI-Codepage und zeigen Umlaute falsch.
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")
    lokal = "--lokal" in sys.argv[1:]
    if ZIEL.exists():
        sys.exit(".env gibt es schon und bleibt unverändert. Zum Neuanlegen erst löschen oder umbenennen.")
    text = VORLAGE.read_text(encoding="utf-8")
    for name, wert in {**ZUFALL, **(LOKAL if lokal else {})}.items():
        text = setzen(text, name, wert)
    ZIEL.write_text(text, encoding="utf-8", newline="\n")

    print(f".env angelegt ({'lokal' if lokal else 'Betrieb'}).")
    print("In den Passwort-Manager übernehmen: APP_KEY und SICHERUNG_KOPIE_SCHLUESSEL.")
    print("Ohne sie lässt sich eine Sicherung nicht wiederherstellen bzw. die Kopie außer Haus nicht lesen.")
    if lokal:
        print("Start: docker compose -p fundus -f docker-compose.yml -f docker-compose.lokal.yml up -d")
    else:
        print("Noch anpassen: APP_URL, DRAWIO_URL, DRAWIO_SERVER_URL, bei Bedarf Anmeldung, SICHERUNG_KOPIE und OLLAMA_URL.")


if __name__ == "__main__":
    main()
