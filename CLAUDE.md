# Hinweise für KI-Assistenten - Nicht prüfbedürftig

Fundus: ein Firmenwiki zum Selbstbetreiben. Ein Theme für **BookStack** plus fertiger
Docker-Stapel (Wiki, MariaDB, draw.io, Texterkennung, Ollama, nächtliche Sicherung). Eigenes sind
der KI-Chat „Frag Fundus“ (antwortet nur aus Artikeln, die die fragende Person lesen darf),
Rückmeldungen, Prüffristen, OCR für Anhänge und die Anmeldung über OIDC mit mitgeliefertem
Keycloak-Realm. Nutzer sind kleine und mittlere deutsche Firmen; Inhalte verlassen das eigene Netz
nicht. Oberfläche, Code, Bezeichner, Doku und Commit-Nachrichten sind Deutsch.

Erst lesen: [docs/ENTWICKLUNG.md](docs/ENTWICKLUNG.md) — die zentrale Doku (Architektur, KI,
Rechte, Tests, Betrieb, Update, Anhang „Fallstricke“ und Anhang B für die Reparaturbefehle).
Dazu [README.md](README.md) (was und für wen) und [docs/START.md](docs/START.md)
(der Weg für Einsteiger unter Windows).

## Befehle

```bash
python skripte/env-anlegen.py --lokal
docker compose -p fundus -f docker-compose.yml -f docker-compose.lokal.yml up -d   # localhost:6875

# Rollen, Bereiche, Vorlagen über die BookStack-API (Token aus den Einstellungen)
BOOKSTACK_URL=http://localhost:6875 BOOKSTACK_TOKEN_ID=… BOOKSTACK_TOKEN_SECRET=… \
  python skripte/einrichten.py --beispiele

docker exec -u abc -w /app/www fundus-wiki-1 php artisan fundus:ki-index   # --neu nach Modellwechsel
docker exec -u abc -w /app/www fundus-wiki-1 php artisan fundus:datei-text # OCR für Bestands-Anhänge

node skripte/rauchtest.mjs          # Oberfläche auf JavaScript-Fehler (braucht Konten aus --beispiele)
bash skripte/testen.sh              # Teststapel: Theme-Tests, Sicherung, Wiederherstellung
bash skripte/anmeldung-testen.sh    # eigener Stapel mit echtem Keycloak
node --check theme/fundus/public/wiki.js

node skripte/pdf/bauen.mjs          # ENTWICKLUNG.md -> .pdf (nicht eingecheckt)
bash skripte/handbuch-einspielen.sh --trockenlauf
```

## Was man sonst erst durch Stolpern lernt

- **Immer `docker exec -u abc …`.** Als root angelegte Cache-Dateien kann das Wiki nicht mehr
  beschreiben, einzelne Artikel liefern dann Fehler 500. Reparatur steht in Anhang B der
  Entwicklerdoku. Der Projektname steht als `name: fundus` in `docker-compose.yml`, der Container heißt `fundus-wiki-1`.
- **Theme-Änderungen sind sofort live.** `./theme/fundus` ist in den Container gemountet, absichtlich
  ohne `:ro`. Ein halb gespeichertes `functions.php` wirft sofort Fehler im laufenden Wiki — erst
  fertig schreiben, dann speichern.
- **Jede eigene Abfrage auf Inhalte läuft über `scopes('visible')`.** Das ist nicht verhandelbar,
  es ist das Sicherheitsmodell: Die KI darf nur zeigen, was die fragende Person ohnehin lesen darf.
  Jede neue Abfrage wird zusätzlich mit einem Konto ohne Bearbeitungsrecht geprüft.
- **BookStack wird nicht verändert**, nur ergänzt: Bausteine per `renderBefore`/`renderAfter`
  anhängen, Views nie überschreiben. Ausnahmen: `theme/fundus/users/profile.blade.php` ersetzt eine
  View vollständig, `theme/fundus/auth/parts/login-message.blade.php` füllt einen leeren Platzhalter —
  bei jedem BookStack-Update gegen das Original abgleichen.
- **Präfix `fundus` für alles Eigene:** CSS-Klassen `fundus-…`, `data-fundus-…`, Routen `/fundus/…`,
  Tabellen `fundus_…`, Variablen `FUNDUS_…`. Bezeichner ohne Umlaute (`pruefen`, `rueckmeldung`),
  Texte mit. Oberflächentexte in Du-Form, kurz und sachlich.
- **Eigenes Vokabular:** Regal → **Bereich**, Buch → **Thema**, Kapitel → **Abschnitt**,
  Seite → **Artikel** (umgesetzt in `lang/de_informal/`). Gilt für Oberfläche und Doku.
- **Kein Build, keine Paketmanager.** Es gibt weder `package.json` noch `composer.json`. Python nur
  Standardbibliothek, JavaScript ohne Bundler, PHP aus dem BookStack-Image. Keine Anfragen nach
  außen: keine Schriften von Google, kein Gravatar, eigenes draw.io.
- **Generiert, nicht von Hand ändern:** `docs/ENTWICKLUNG.pdf`, `theme/fundus/public/icon*.png`,
  `handbuch/bilder/*.png` (Ausnahme: die `editor-*.png` werden von Hand aufgenommen, weil der Editor
  nachlädt), `docs/vorstellung/*` (`node skripte/vorstellung/bauen.mjs`, nicht eingecheckt, hängt am Release). `theme/fundus/ki/vendor/**` sind fremde Bibliotheken — nicht anfassen.
- **`wiki.js` steht inline in `kopf.blade.php`** (`file_get_contents`), weil BookStack den MIME-Typ
  von Theme-Dateien aus dem Inhalt rät und JavaScript für HTML hielt. Gleicher Grund für die Route
  `/fundus/ki/bibliotheken.js`. In `wiki.css` die Abschnitte **nicht umsortieren**, spätere
  überschreiben frühere absichtlich; Farben nur über die `:root`-Variablen, nur heller Modus.
- **Eigene Tabellen legt der Code selbst an** (`CREATE TABLE IF NOT EXISTS`), es gibt keine
  Laravel-Migrationen. Seit BookStack 26 liegen Seiteninhalte in `entities`/`entity_page_data` und
  nicht mehr in `pages` — in Skripten die Modelle nutzen, nicht die Tabelle.
- **Compose:** `docker-compose.yml` ist die Basis, die anderen vier sind Overlays und werden immer
  zusätzlich mit `-f` angehängt, nie allein gestartet. Die Teststapel (`test.yml`,
  `anmeldung-test.yml`) startet **nur das jeweilige Skript**, das vorher eine Sicherung einspielt.
  `ports: !reset []` löscht die geerbte Portliste (Compose führt Listen sonst zusammen), damit der
  Teststapel neben dem lokalen Wiki laufen kann. `profiles: ["nie"]` schaltet einen geerbten Dienst
  ab, weil das Profil nie aktiviert wird — so bleibt Ollama in den Tests aus.
  `FUNDUS_TESTSTAPEL=1` ist die Sicherung dagegen, dass die schreibenden Tests je im echten Wiki
  laufen: ohne die Variable bricht `theme-tests.php` ab.
- **Leere Variablen kommen im Wiki nicht an.** PHP-FPM reicht `FOO=` gar nicht erst durch, im Wiki
  liefert `env('FOO', 'vorgabe')` dann die Vorgabe; die Theme-Tests (CLI) sehen dagegen `''`. Eine
  Vorgabe im Code muss deshalb dasselbe bedeuten wie „leer“ (so bei `FUNDUS_TITEL_CLAIM`).
- **Dateien für Dienste als Ordner mounten.** Git ersetzt Dateien beim `pull`, ein einzeln gemountetes
  File zeigt im laufenden Container weiter die alte Fassung. Deshalb hängt `skripte/` als Ganzes im
  Dienst `sicherung`.
- **Unter Windows und in Git Bash** braucht es `MSYS_NO_PATHCONV=1` vor Befehlen mit Containerpfaden;
  die Skripte setzen das selbst.
- **`einrichten.py` ist idempotent**, überschreibt aber Rollen und Bereichsrechte bei jedem Lauf.
  Struktur, Rollen und Rechte stehen ausschließlich in den Daten oben in der Datei
  (`ROLLEN`, `REGALE`, `ZUGANG`); `handbuch-einspielen.sh` liest dieselbe Quelle.
- **Keine echten Firmendaten.** `theme/fundus/titel.php` (Berufstitel je E-Mail) bleibt leer,
  Beispiele und Testdaten sind erfunden (`firma.intern`, „Mia Mitarbeiterin“, „Alex Azubi“).

## Was zusammen geändert werden muss

- Neue Umgebungsvariable → `.env.example` **und** `docker-compose.yml`, jeweils mit Kommentar,
  dazu die Tabelle „Konfiguration“ in der Entwicklerdoku.
- Neuer Baustein → Blade in `theme/fundus/fundus/`, Einhängen in `functions.php`, Stil in `wiki.css`.
- Neue Route, Übersicht oder Abfrage → Test in `skripte/tests/theme-tests.php`.
- MariaDB anheben → gleichzeitig in `docker-compose.yml` und `sicherung/Dockerfile`, damit
  `mariadb-dump` zur Serverversion passt.
- Neues Handbuch-Bild → Eintrag in `skripte/handbuch-bilder/bilder.mjs`.

## Fertig heißt

`node skripte/rauchtest.mjs` ohne JavaScript-Fehler und `bash skripte/testen.sh` grün. Wer Anmeldung,
Rollen oder den Realm ändert, lässt zusätzlich `bash skripte/anmeldung-testen.sh` laufen.
Dazu ein Eintrag in `CHANGELOG.md` unter „Unveröffentlicht“ und die Entwicklerdoku nachgezogen,
einschließlich `stand:` im YAML-Kopf; bei sichtbaren Änderungen auch das README.
Ein BookStack-Update nur nach dem Ablauf im Kapitel „Update“.
