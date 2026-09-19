# Fundus

Firmenwiki mit KI-Suche zum Selbstbetreiben. Fundus ist ein Theme für [BookStack](https://www.bookstackapp.com/) mit einem fertigen Docker-Compose-Stapel: Wiki, Datenbank, draw.io, Texterkennung für Anhänge, Sprachmodell und nächtliche Sicherung. Die KI läuft über [Ollama](https://ollama.com/) im selben Stapel oder auf einem eigenen Server; Inhalte verlassen das eigene Netz nicht.

**Ohne technische Vorkenntnisse:** [Erste Schritte](docs/erste-schritte.md) erklärt, was du
brauchst, wo du es bekommst und wie Fundus Schritt für Schritt auf einem Windows-Rechner läuft.

![Startseite von Fundus](handbuch/bilder/startseite.png)

## Funktionen

- **KI-Chat „Frag Fundus“:** beantwortet Fragen aus den Artikeln, die die fragende Person lesen darf, und nennt die Quellen mit Sprung zur Fundstelle.
- **Suche mit Strg + K** über alle Artikel, Themen und Bereiche.
- **Rückmeldungen:** „War das hilfreich?“ und „Veraltet melden“ unter jedem Artikel, Auswertung für alle mit Bearbeitungsrecht.
- **Prüffristen:** Artikel werden nach einem einstellbaren Intervall zur Prüfung fällig; Verantwortliche sehen das auf der Startseite.
- **Texterkennung:** Text aus hochgeladenen PDFs und Bildern wird durchsuchbar und steht der KI zur Verfügung.
- **Sicherung:** nächtlicher Dump von Datenbank und Uploads, optional verschlüsselte Kopie außer Haus per rclone (SFTP, S3, WebDAV, SMB).
- **Anmeldung über OIDC:** Keycloak-Realm mitgeliefert, Authentik und Entra ID vorbereitet; Rollen folgen den Gruppen des Anmeldedienstes.
- **Einrichtung als Code:** Rollen, Bereiche, Vorlagen und ein Handbuch für Mitarbeitende per Skript.

BookStack selbst bleibt unverändert. Das Theme hängt eigene Bausteine an vorhandene Views an und nutzt überall BookStacks Rechteprüfung.

## Voraussetzungen

- Docker mit Docker Compose v2
- Python 3.10 oder neuer (Einrichtungsskripte, nur Standardbibliothek)
- Für den KI-Chat rund 8 GB Arbeitsspeicher und 5 GB Platz für die Modelle; eine NVIDIA-Grafikkarte beschleunigt die Antworten, ist aber nicht nötig
- Optional für Entwicklung: Node.js 22 und Chrome oder Chromium (Rauchtest, Handbuch-Bilder, PDF der Doku)

Die Oberfläche ist deutsch (Du-Form).

## Schnellstart lokal

```bash
python skripte/env-anlegen.py --lokal
docker compose -p fundus -f docker-compose.yml -f docker-compose.lokal.yml up -d
```

Nach etwa zwei Minuten läuft das Wiki unter <http://localhost:6875>. Erste Anmeldung mit `admin@admin.com` / `password`, danach sofort ändern.

Rollen, Bereiche und Vorlagen einrichten (API-Token unter *Einstellungen → Benutzer → Admin → API-Token* anlegen):

```bash
BOOKSTACK_URL=http://localhost:6875 BOOKSTACK_TOKEN_ID=… BOOKSTACK_TOKEN_SECRET=… \
  python skripte/einrichten.py --beispiele
```

`--beispiele` legt zusätzlich drei Beispielartikel an.

Den KI-Chat bedient der Dienst `ollama` im Stapel. Beim ersten Start lädt `ollama-modelle` die Modelle `qwen3.5:4b` und `bge-m3` (rund 4 GB) und beendet sich danach. Ist das geschehen (`docker compose -p fundus logs ollama-modelle`), einmal den Index über die vorhandenen Artikel bauen:

```bash
docker exec -u abc -w /app/www fundus-wiki-1 php artisan fundus:ki-index
```

Ein vorhandenes Ollama statt des Containers: `OLLAMA_URL` in `.env` auf dessen Adresse setzen, etwa `http://host.docker.internal:11434`. Ohne KI-Chat: `OLLAMA_URL=` (leer).

## Betrieb

Der Stapel läuft mit Docker Compose hinter einem Reverse Proxy mit TLS (Caddy, nginx, Traefik …). Nach außen offen sind nur Wiki und draw.io, als Vorgabe nur für den Server selbst (`127.0.0.1:6875` und `:6876`); der Proxy leitet die Adressen dorthin weiter. `python skripte/env-anlegen.py` erzeugt eine `.env` mit Zufallswerten für alle Schlüssel und Passwörter; Adressen, Anmeldung und Sicherungsziel werden danach angepasst. Alle Variablen sind in [.env.example](.env.example) erklärt.

Ersteinrichtung, Anmeldung, Update, Sicherung und Wiederherstellung beschreibt die [Entwicklerdoku](docs/entwicklerdoku.md), Kapitel „Betrieb“.

## Aufbau

| Ordner | Inhalt |
| --- | --- |
| `theme/fundus/` | Theme: `functions.php` als Einstieg, Bausteine, `wiki.js`, `wiki.css`, KI-Chat, Rückmeldungen, Prüfung, Begriffe |
| `skripte/` | Einrichtung, `.env` anlegen, Sicherung und Wiederherstellung, Handbuch einspielen, Rauchtest, Tests |
| `ocr/`, `sicherung/` | eigene Images für Texterkennung und Sicherung |
| `keycloak/` | Realm-Vorlage für die mitgelieferte Anmeldung |
| `handbuch/` | Handbuch „So funktioniert das Wiki“ für Mitarbeitende, wird ins Wiki eingespielt |
| `vorlagen/` | Artikelvorlagen (Anleitung, Prozess, Checkliste, Kundenüberblick) |
| `docs/` | Entwicklerdoku; PDF-Fassung mit `node docs/pdf-bauen.mjs` |

## Anpassen und erweitern

- **Konfiguration** über Umgebungsvariablen: KI-Modelle, Treffergrenze, Prüfintervall, Werkzeug-Links auf der Startseite (`FUNDUS_WERKZEUGE`), Texterkennung, Sicherung.
- **Struktur und Rechte** stehen als Daten oben in `skripte/einrichten.py` (Rollen, Bereiche, Themen, Freigaben).
- **Vorlagen und Handbuch** sind HTML-Dateien in `vorlagen/` und `handbuch/`.
- **Aussehen:** Farben als CSS-Variablen in `:root` von `theme/fundus/public/wiki.css`.
- **Eigene Bausteine und Routen:** Kapitel „Theme erweitern“ der Entwicklerdoku.

## Mitmachen

Fehler und Vorschläge als [Issue](../../issues). Ablauf für Änderungen, Tests und Schreibweise: [CONTRIBUTING.md](CONTRIBUTING.md). Sicherheitslücken bitte nicht öffentlich melden, sondern wie in [SECURITY.md](SECURITY.md) beschrieben.

## Lizenz

MIT, siehe [LICENSE](LICENSE). Enthaltene Werke Dritter (Schrift, Symbole, Chat-Bibliotheken) behalten ihre eigenen Lizenzen; die Liste steht ebenfalls in LICENSE. BookStack ist nicht enthalten und wird als Docker-Image bezogen.

---

**English summary:** Fundus is a self-hosted company wiki built as a theme for BookStack, shipped as a Docker Compose stack (wiki, MariaDB, draw.io, OCR, Ollama, nightly backups). It adds an AI chat that answers from articles the user is permitted to read, using Ollama in the same stack or on an existing server, plus feedback, review reminders and OIDC sign-in. The user interface and documentation are in German. Quick start: `python skripte/env-anlegen.py --lokal`, then `docker compose -p fundus -f docker-compose.yml -f docker-compose.lokal.yml up -d` and open <http://localhost:6875>.
