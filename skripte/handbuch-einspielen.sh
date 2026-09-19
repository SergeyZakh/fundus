#!/bin/bash
# Spielt das Handbuch ins laufende Wiki ein (Texte als neue Version, Bilder hochgeladen).
#
#   bash skripte/handbuch-einspielen.sh               einspielen
#   bash skripte/handbuch-einspielen.sh --trockenlauf nur anzeigen, was passieren würde
#
# Voraussetzungen: Stapel läuft (Container WIKI_CONTAINER, Standard fundus-wiki-1), Python 3.
# Bilder vorher bei Bedarf neu erzeugen: node skripte/handbuch-bilder/aufnehmen.mjs

set -euo pipefail
export MSYS_NO_PATHCONV=1

# Unter Git Bash (Windows) braucht docker cp den Windows-Pfad; pwd -W gibt es nur dort.
HIER="$(cd "$(dirname "$0")" && (pwd -W 2>/dev/null || pwd))"
CONTAINER="${WIKI_CONTAINER:-fundus-wiki-1}"
ADMIN_ID="${WIKI_ADMIN_ID:-1}"
ZIEL=/tmp/fundus-handbuch

# Struktur des Handbuchs aus einrichten.py lesen, damit es nur eine Quelle gibt.
struktur="$(cd "$HIER" && python -c '
import json, einrichten
buch = next(b for r in einrichten.REGALE for b in r["buecher"] if b["name"] == "So funktioniert das Wiki")
print(json.dumps({"buch": buch["name"], "kapitel": buch["kapitel"]}))  # ASCII-Escapes: Windows-Konsolen kodieren sonst falsch
')"

docker exec "$CONTAINER" rm -rf "$ZIEL"
docker cp "$HIER/../handbuch" "$CONTAINER:$ZIEL"
printf '%s' "$struktur" | docker exec -i "$CONTAINER" sh -c "cat > $ZIEL/struktur.json"
docker cp "$HIER/handbuch-einspielen.php" "$CONTAINER:$ZIEL/einspielen.php"
# Als abc (Benutzer des Webservers), nicht als root: Sonst gehören neue Cache-Ordner und Bilder root,
# und das Wiki kann dort später nichts mehr schreiben (Fehler 500 beim Öffnen einzelner Artikel).
docker exec -u abc -w /app/www "$CONTAINER" php "$ZIEL/einspielen.php" "$ZIEL" "$ADMIN_ID" "$@"
docker exec "$CONTAINER" rm -rf "$ZIEL"
