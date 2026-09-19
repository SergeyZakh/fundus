#!/bin/bash
# Spielt eine Sicherung aus /sicherungen zurück: Datenbank und hochgeladene Dateien.
#
# Vorher den Dienst „wiki“ stoppen, damit niemand während des Rückspielens schreibt:
#   docker compose stop wiki
#   docker compose exec sicherung bash /skripte/wiederherstellen.sh            # listet Sicherungen
#   docker compose exec sicherung bash /skripte/wiederherstellen.sh 2026-09-13_0230
#   docker compose start wiki
#
# Die Datenbank wird dabei vollständig ersetzt. Ablauf und Prüfung: docs/entwicklerdoku.md (Kapitel „Sicherung“).

set -euo pipefail

ZIEL=/sicherungen
DATEIORDNER=(uploads files images)

if [ $# -eq 0 ]; then
  echo "Vorhandene Sicherungen:"
  # Namen sind Zeitstempel (20…), also ohne Leer- oder Sonderzeichen.
  # shellcheck disable=SC2010
  ls -1 "$ZIEL" | grep -E '^20' || echo "  (keine)"
  echo
  echo "Aufruf: wiederherstellen.sh <Name der Sicherung>"
  exit 1
fi

quelle="$ZIEL/$1"
[ -d "$quelle" ] || { echo "Sicherung $quelle nicht gefunden"; exit 1; }

echo "Prüfe Prüfsummen …"
(cd "$quelle" && sha256sum -c pruefsummen.sha256)

# Läuft das Wiki noch, antwortet es im Container-Netz; dann lieber abbrechen.
if (exec 3<>/dev/tcp/wiki/80) 2>/dev/null; then
  echo "Der Dienst „wiki“ läuft noch. Erst stoppen: docker compose stop wiki"
  exit 1
fi

echo "Datenbank $DB_DATABASE wird ersetzt …"
{
  # Der Dump enthält DROP TABLE je Tabelle; Fremdschlüssel während des Imports aus.
  echo "SET FOREIGN_KEY_CHECKS=0;"
  gzip -dc "$quelle/datenbank.sql.gz"
  echo "SET FOREIGN_KEY_CHECKS=1;"
} | MYSQL_PWD="$DB_PASSWORD" mariadb --host="$DB_HOST" --user="$DB_USERNAME" "$DB_DATABASE"

echo "Dateien werden ersetzt …"
# Auf einem neuen Server war das Wiki noch nie gestartet, dann fehlt der Ordner.
mkdir -p /wiki/www
for d in "${DATEIORDNER[@]}"; do
  rm -rf "/wiki/www/$d"
done
# Als root entpackt, behält tar die ursprünglichen Besitzer (Benutzer 1000 im Wiki-Container).
tar -xzf "$quelle/dateien.tar.gz" -C /wiki/www

echo "Fertig. Jetzt: docker compose start wiki"
