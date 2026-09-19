# Änderungen

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionen nach [Semantic Versioning](https://semver.org/lang/de/).

## Unveröffentlicht

Erste öffentliche Fassung.

### Enthalten

- Theme `fundus` für BookStack 26.05.4: Startseite mit Bereichen, Aktivität und Werkzeug-Links, Suche mit Strg + K, Zen-Modus, Artikelkopf mit Stand und Lesezeit
- KI-Chat über Ollama mit Rechteprüfung, Quellenangaben und Sprung zur Fundstelle; Ollama läuft als Dienst im Stapel und lädt seine Modelle beim ersten Start selbst
- Rückmeldungen zu Artikeln, Prüffristen, Benachrichtigungen für Verantwortliche
- Texterkennung für PDFs und Bilder (Dienst `ocr`)
- Nächtliche Sicherung mit verschlüsselter Kopie außer Haus, Wiederherstellung per Skript
- Anmeldung über OIDC mit Keycloak-Realm, Rollen aus Gruppen, Berufstitel
- Einrichtungsskript für Rollen, Bereiche und Vorlagen, Handbuch für Mitarbeitende
- `skripte/env-anlegen.py` erzeugt die `.env` mit Zufallswerten
- Betrieb hinter einem beliebigen Reverse Proxy: `WIKI_PORT`, `DRAWIO_PORT` und `KEYCLOAK_PORT`, als Vorgabe nur auf `127.0.0.1`; Beispielkonfiguration für Caddy in der Entwicklerdoku
- Anleitung ohne technische Vorkenntnisse (`docs/erste-schritte.md`)
- Prüfungen in GitHub Actions, Dependabot für Images und Actions
