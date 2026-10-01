#!/bin/bash
# Testet das Theme in einem getrennten Stapel mit echten Daten, ohne das lokale Wiki zu verändern.
#
#   1. Im lokalen Wiki (Projekt „fundus“) eine Sicherung anlegen.
#   2. Teststapel „fundus-test“ mit leeren Volumes starten und die Sicherung mit
#      wiederherstellen.sh zurückspielen. Das prüft nebenbei Sicherung und Wiederherstellung.
#   3. Theme-Tests (skripte/tests/theme-tests.php) im Test-Wiki ausführen.
#   4. Kopie außer Haus: sichern auf ein WebDAV-Ziel im Teststapel, dann mit ausgefallenem Ziel.
#   5. Teststapel samt Volumes wieder entfernen.
#
# Aufruf aus dem Ordner fundus:
#   bash skripte/testen.sh             # alles
#   bash skripte/testen.sh --behalten  # Teststapel danach nicht entfernen (zum Nachsehen)
#   bash skripte/testen.sh --nur-tests # Sicherung überspringen, Tests im laufenden Teststapel

set -euo pipefail
cd "$(dirname "$0")/.."
# Git Bash unter Windows würde Containerpfade wie /sicherungen sonst in Windows-Pfade umschreiben.
export MSYS_NO_PATHCONV=1

LOKAL=(docker compose -p fundus -f docker-compose.yml -f docker-compose.lokal.yml)
TEST=(docker compose -p fundus-test -f docker-compose.yml -f docker-compose.test.yml)

behalten=0
nur_tests=0
for arg in "$@"; do
  case "$arg" in
    --behalten) behalten=1 ;;
    --nur-tests) nur_tests=1; behalten=1 ;;
    *) echo "Unbekannte Option: $arg"; exit 2 ;;
  esac
done

schritt() { echo; echo "== $*"; }

aufraeumen() {
  if [ "$behalten" -eq 0 ]; then
    schritt "Teststapel entfernen"
    "${TEST[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
  else
    echo "Teststapel bleibt stehen. Entfernen: ${TEST[*]} down -v"
  fi
}

if [ "$nur_tests" -eq 0 ]; then
  trap aufraeumen EXIT

  schritt "Sicherung im lokalen Wiki anlegen"
  "${LOKAL[@]}" exec -T sicherung bash /skripte/sicherung.sh jetzt
  name="$("${LOKAL[@]}" exec -T sicherung sh -c 'ls -1 /sicherungen | grep -E "^20" | sort | tail -n 1')"
  echo "Neueste Sicherung: $name"

  schritt "Teststapel frisch starten"
  "${TEST[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
  "${TEST[@]}" up -d --wait datenbank sicherung

  schritt "Sicherung in den Teststapel kopieren"
  # Direkt von Container zu Container, ohne Umweg über Pfade auf dem Rechner.
  "${LOKAL[@]}" exec -T sicherung tar -cf - -C /sicherungen "$name" \
    | "${TEST[@]}" exec -T sicherung tar -xf - -C /sicherungen

  schritt "Sicherung zurückspielen"
  "${TEST[@]}" exec -T sicherung bash /skripte/wiederherstellen.sh "$name"

  schritt "Test-Wiki starten (Migrationen, bis /status antwortet)"
  "${TEST[@]}" up -d --wait wiki

  # Kurzer Abgleich: Stimmen die Artikelzahlen überein, ist die Wiederherstellung vollständig.
  zaehlen='MYSQL_PWD="$DB_PASSWORD" mariadb -N --host="$DB_HOST" --user="$DB_USERNAME" "$DB_DATABASE" -e "SELECT COUNT(*) FROM entities; SELECT COUNT(*) FROM images;" | tr "\n" " "'
  vorher="$("${LOKAL[@]}" exec -T sicherung sh -c "$zaehlen")"
  nachher="$("${TEST[@]}" exec -T sicherung sh -c "$zaehlen")"
  echo "Seiten/Bilder lokal: $vorher, Teststapel: $nachher"
  if [ "$vorher" != "$nachher" ]; then
    echo "FEHLER: Wiederherstellung unvollständig"
    exit 1
  fi

  schritt "Wiederherstellung wie im Betrieb: Wiki anhalten, zurückspielen, starten"
  # Cache und Sitzungen des laufenden Wikis gehören zum alten Stand und müssen danach leer sein.
  "${TEST[@]}" exec -T sicherung touch /wiki/www/framework/cache/fundus-test-alt /wiki/www/framework/sessions/fundus-test-alt
  "${TEST[@]}" stop wiki >/dev/null 2>&1
  "${TEST[@]}" exec -T sicherung bash /skripte/wiederherstellen.sh "$name" >/dev/null
  if "${TEST[@]}" exec -T sicherung sh -c 'test -e /wiki/www/framework/cache/fundus-test-alt || test -e /wiki/www/framework/sessions/fundus-test-alt'; then
    echo "FEHLER: Cache oder Sitzungen nach der Wiederherstellung noch da"
    exit 1
  fi
  "${TEST[@]}" up -d --wait wiki
  echo "✓ Cache und Sitzungen geleert, Wiki läuft wieder"
fi

schritt "Theme-Tests"
# Als abc (Benutzer des Wikis), sonst legt PHP Cache-Ordner als root an.
"${TEST[@]}" exec -T -u abc wiki php /tests/theme-tests.php

if [ "$nur_tests" -eq 0 ]; then
  schritt "Kopie außer Haus (verschlüsselt, WebDAV-Ziel im Teststapel)"
  status() { "${TEST[@]}" exec -T sicherung cat /sicherungen/status.json; }
  "${TEST[@]}" exec -T sicherung bash /skripte/sicherung.sh jetzt | tail -n 3
  stempel="$("${TEST[@]}" exec -T sicherung sh -c 'ls -1 /sicherungen | grep -E "^20" | sort | tail -n 1')"
  echo "Status: $(status)"
  if ! status | grep -q '"ergebnis":"ok","kopie":"an","verschluesselt":"ja"'; then
    echo "FEHLER: Lauf mit Kopie nicht ok"
    exit 1
  fi

  # Am Ziel vorbei, ohne Schlüssel: Was sieht jemand, der dort Zugriff hat?
  roh="$("${TEST[@]}" exec -T sicherung rclone lsf -R ":webdav,url='http://kopie-ziel:8080':fundus" | tr '\n' ' ')"
  echo "Auf dem Ziel liegt: ${roh:0:160} …"
  if [ -z "$roh" ] || echo "$roh" | grep -qE "datenbank|dateien|pruefsummen|$stempel"; then
    echo "FEHLER: Auf dem Ziel sind Namen lesbar"
    exit 1
  fi
  echo "✓ Auf dem Ziel nur verschlüsselte Namen"

  # Zurückholen: lokale Sicherung beiseite, aus der Kopie holen, Prüfsummen müssen stimmen.
  "${TEST[@]}" exec -T sicherung mv "/sicherungen/$stempel" "/sicherungen/beiseite-$stempel"
  if ! "${TEST[@]}" exec -T sicherung bash /skripte/sicherung.sh holen "$stempel"; then
    echo "FEHLER: Kopie ließ sich nicht zurückholen"
    exit 1
  fi
  echo "✓ Kopie zurückgeholt und entschlüsselt, Prüfsummen stimmen"

  # Ziel weg: Der Lauf muss scheitern und es im Status sagen, ohne Zugangsdaten. Der Rechnername darf
  # drinstehen (rclone nennt ihn im Fehler, er hilft bei der Suche), Passwort und Schlüssel nie.
  "${TEST[@]}" stop kopie-ziel >/dev/null 2>&1
  "${TEST[@]}" exec -T sicherung bash /skripte/sicherung.sh jetzt >/dev/null 2>&1 || true
  echo "Status ohne Ziel: $(status)"
  if ! status | grep -q '"ergebnis":"kopie-fehler"'; then
    echo "FEHLER: Ausgefallenes Ziel wird nicht gemeldet"
    exit 1
  fi
  if status | grep -qE "qQe0BETl4dOC|nur-fuer-den-teststapel|user=test"; then
    echo "FEHLER: Zugangsdaten oder Schlüssel im Status"
    exit 1
  fi
  echo "✓ Ausgefallenes Ziel steht im Status, ohne Zugangsdaten und Schlüssel"

  # Geschwärzt wird auch, was rclone in anderer Form ausgibt: Passwort-Felder und Zugangsdaten in URLs.
  geschwaerzt="$("${TEST[@]}" exec -T sicherung bash -c 'source <(sed -n "/^ohne_geheimnisse() {/,/^}/p" /skripte/sicherung.sh); KOPIE=":sftp,host=h,pass=GEHEIM1:p"; printf "%s\n" "Fehler in :sftp,host=h,pass=GEHEIM1:p" "url https://nutzer:GEHEIM2@backup/x" "secret_access_key=GEHEIM3,endpoint" | ohne_geheimnisse')"
  echo "$geschwaerzt"
  if echo "$geschwaerzt" | grep -q "GEHEIM"; then
    echo "FEHLER: Geheimnis nicht geschwärzt"
    exit 1
  fi
  echo "✓ Passwörter, Schlüssel und Zugangsdaten in URLs werden geschwärzt"

  schritt "Sicherung: Fehler mittendrin und falsche Einstellung"
  # Ein mariadb-dump, dem mitten im Dump die Verbindung wegbricht: „entities“ steht schon drin, der Rest fehlt.
  "${TEST[@]}" exec -T sicherung sh -c 'mkdir -p /tmp/attrappe && cat > /tmp/attrappe/mariadb-dump && chmod +x /tmp/attrappe/mariadb-dump' <<'ATTRAPPE'
#!/bin/sh
echo 'CREATE TABLE `entities` (id int);'
echo 'mariadb-dump: Error 2013: Lost connection to server during query' >&2
exit 2
ATTRAPPE
  "${TEST[@]}" exec -T sicherung env PATH="/tmp/attrappe:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" \
    bash /skripte/sicherung.sh jetzt >/dev/null 2>&1 || true
  echo "Status: $(status)"
  if ! status | grep -q '"ergebnis":"sicherung-fehler"'; then
    echo "FEHLER: Abgebrochener Dump gilt als Sicherung"
    exit 1
  fi
  if "${TEST[@]}" exec -T sicherung sh -c 'ls -1a /sicherungen | grep -q "^\.laufend-"'; then
    echo "FEHLER: Reste des abgebrochenen Laufs liegen noch in /sicherungen"
    exit 1
  fi
  echo "✓ Abgebrochener Dump wird verworfen und den Admins gemeldet"

  # Falsche Uhrzeit: Der Dienst meldet es im Status, statt still zu wiederholen.
  "${TEST[@]}" exec -T sicherung env SICHERUNG_UHRZEIT=25:99 timeout 20 bash /skripte/sicherung.sh >/dev/null 2>&1 || true
  echo "Status: $(status)"
  if ! status | grep -q '"ergebnis":"sicherung-fehler".*SICHERUNG_UHRZEIT'; then
    echo "FEHLER: Ungültige SICHERUNG_UHRZEIT wird nicht gemeldet"
    exit 1
  fi
  echo "✓ Ungültige Uhrzeit steht im Status"
fi
