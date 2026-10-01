# Mitmachen

Danke, dass du helfen willst! Drei Wege, von klein nach groß:

## 1. Eine Idee vorschlagen

Dir fehlt etwas im Wiki, im Chat oder beim Betrieb? Eröffne ein Issue mit der Vorlage
**„Vorschlag“**. Wichtig ist vor allem der Anlass: welches Problem es löst und wen es betrifft
(Lesende, Redaktion, Admins, Betrieb).

## 2. Einen Fehler melden

Eröffne ein Issue mit der Vorlage **„Fehler melden“**. Beschreibe, was du gemacht hast, was
passiert ist und was du erwartet hättest, dazu die Version von Fundus und den BookStack-Image-Tag.
Screenshots und Protokolle helfen, aber bitte nur mit ausgedachten Inhalten: keine Zugangsdaten,
keine Firmeninhalte, keine echten Namen.

## 3. Code beitragen

Für größere Änderungen vorher ein Issue anlegen, damit Ansatz und Umfang geklärt sind.

### Entwicklungsumgebung

```bash
python skripte/env-anlegen.py --lokal
docker compose -p fundus -f docker-compose.yml -f docker-compose.lokal.yml up -d
```

Das Theme ist in den Container gemountet: Datei speichern, Seite neu laden. Aufbau, Bausteine und Routen beschreibt die [Entwicklerdoku](docs/ENTWICKLUNG.md), Kapitel „Aufbau des Codes“.

Zwei Regeln, die sonst Zeit kosten:

- **Theme-Änderungen sind sofort live.** Ein halb geschriebenes `functions.php` wirft sofort Fehler im laufenden Wiki. Erst fertig schreiben, dann speichern.
- **Befehle im Container immer als `abc`** (`docker exec -u abc …`). Als root angelegte Cache-Dateien kann das Wiki nicht mehr beschreiben; einzelne Seiten liefern dann Fehler 500. Reparatur: Entwicklerdoku, Anhang „Befehle“.

Unter Windows mit Git Bash vor Befehlen mit Containerpfaden `MSYS_NO_PATHCONV=1` setzen, sonst werden Pfade wie `/app/www` umgeschrieben.

### Prüfen

| Prüfung | Befehl | Wann |
| --- | --- | --- |
| Syntax, Compose-Dateien, Images | läuft automatisch in GitHub Actions (`.github/workflows/pruefen.yml`) | jeder Push |
| Rauchtest: Seiten laden ohne JavaScript-Fehler (als Admin und als Beispielkonto Mia aus `einrichten.py --beispiele`) | `node skripte/rauchtest.mjs` | nach Änderungen an `wiki.js`, `wiki.css` oder Bausteinen |
| Theme-Tests: Rechte, Rückmeldungen, KI-Suche, Sicherung und Wiederherstellung | `bash skripte/testen.sh` | vor jedem Pull Request mit Änderungen am Theme oder an der Sicherung |
| Anmeldung über Keycloak | `bash skripte/anmeldung-testen.sh` | nach Änderungen an Anmeldung, Rollen oder Realm |

`testen.sh` nimmt die Daten des lokalen Wikis (Projekt `fundus`) als Ausgangspunkt und prüft in einem getrennten Stapel; das lokale Wiki bleibt unverändert. Ollama wird dafür nicht gebraucht.

Neue Routen, Übersichten oder Abfragen bekommen einen Test in `skripte/tests/theme-tests.php`. Jede eigene Abfrage auf Inhalte läuft über `scopes('visible')`, damit niemand mehr sieht, als er öffnen darf.

### Schreibweise

- **Deutsch** in Code, Kommentaren, Oberfläche und Doku. Bezeichner ohne Umlaute (`pruefen`, `rueckmeldung`), Texte mit.
- **Präfix `fundus`** für alles Eigene: CSS-Klassen `fundus-…`, Routen `/fundus/…`, Tabellen `fundus_…`, Variablen `FUNDUS_…`.
- **Kommentare erklären das Warum**, nicht das Was. Fallstricke, die Zeit gekostet haben, gehören in den Kommentar an der Stelle und in den Anhang „Fallstricke“ der Entwicklerdoku.
- **Oberflächentexte** in Du-Form, kurz und sachlich. Begriffe im Wiki: Bereich, Thema, Abschnitt, Artikel (statt Regal, Buch, Kapitel, Seite).
- **Keine Abhängigkeiten ohne Grund.** Python nur mit Standardbibliothek, JavaScript ohne Build-Schritt. Fremdbibliotheken liegen mit Lizenztext in `theme/fundus/ki/vendor/`.
- **BookStack nicht verändern.** Bausteine an vorhandene Views anhängen statt Views zu überschreiben; so bleibt ein Update ein neuer Image-Tag.
- Formatierung nach `.editorconfig`: UTF-8, LF, zwei Leerzeichen, in PHP und Python vier.

## Pull Requests

- Eine Änderung pro Pull Request, mit kurzer Beschreibung von Anlass und Lösung.
- Sichtbare Änderungen an der Oberfläche mit Screenshot.
- Neue Variablen in `.env.example` und `docker-compose.yml` mit Kommentar eintragen, bei Bedarf in der Entwicklerdoku.
- Einträge in `CHANGELOG.md` unter „Unveröffentlicht“.

## BookStack-Update

Dependabot schlägt neue Image-Versionen vor. Ein BookStack-Update nur nach dem Ablauf im Kapitel „Update“ der Entwicklerdoku übernehmen: Rauchtest vorher und nachher vergleichen, Ziel-Views der Bausteine prüfen, `testen.sh` ausführen. `mariadb` in `docker-compose.yml` und `sicherung/Dockerfile` immer gemeinsam anheben, damit `mariadb-dump` zur Serverversion passt.

## Sicherheitslücken

Nicht als Issue, sondern über [SECURITY.md](SECURITY.md).
