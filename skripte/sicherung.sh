#!/bin/bash
# Nächtliche Sicherung des Wikis: Datenbank-Dump und hochgeladene Dateien.
#
# Läuft als eigener Dienst im Compose-Stapel und wartet jeweils bis SICHERUNG_UHRZEIT.
# Sofort sichern (z. B. vor einem Update):
#   docker compose exec sicherung bash /skripte/sicherung.sh jetzt
#
# Ergebnis je Lauf: /sicherungen/<JJJJ-MM-TT_HHMM>/{datenbank.sql.gz,dateien.tar.gz,pruefsummen.sha256}
# Mit SICHERUNG_KOPIE geht jede Sicherung zusätzlich per rclone außer Haus und wird dort geprüft,
# mit SICHERUNG_KOPIE_SCHLUESSEL vorher verschlüsselt (Inhalt und Dateinamen).
# Nach jedem Lauf steht in /sicherungen/status.json, wie es ausging; Fundus meldet Fehler den Admins.
# Eine Kopie zurückholen (und entschlüsseln), danach wie gewohnt wiederherstellen.sh:
#   docker compose exec sicherung bash /skripte/sicherung.sh holen <JJJJ-MM-TT_HHMM>
# Wiederherstellen: skripte/wiederherstellen.sh, Ablauf in docs/ENTWICKLUNG.md (Kapitel „Sicherung“).

set -euo pipefail

ZIEL=/sicherungen
UHRZEIT="${SICHERUNG_UHRZEIT:-02:30}"
TAGE="${SICHERUNG_TAGE:-14}"
# rclone-Ziel als Verbindungszeichenkette, etwa „:sftp,host=backup.firma.intern,user=wiki,pass=…:fundus“.
# Werte mit „:“ oder „,“ (URLs) in einfache Anführungszeichen: „:webdav,url='https://…',user=…:pfad“.
# Leer: keine Kopie außer Haus (Fundus erinnert die Admins daran).
KOPIE="${SICHERUNG_KOPIE:-}"
KOPIE_TAGE="${SICHERUNG_KOPIE_TAGE:-90}"
STATUS="$ZIEL/status.json"

# Mit Schlüssel legt rclone ein „crypt“ über das Ziel: Das Ziel sieht nur verschlüsselte Dateien mit
# verschlüsselten Namen. Über Umgebungsvariablen statt einer verschachtelten Verbindungszeichenkette,
# weil das Ziel selbst schon Anführungszeichen, Doppelpunkte und Kommas enthält.
# Ohne den Schlüssel ist die Kopie wertlos: im Passwort-Manager ablegen, zusätzlich auf Papier.
VERSCHLUESSELT=nein
KOPIE_ZIEL="$KOPIE"
if [ -n "$KOPIE" ] && [ -n "${SICHERUNG_KOPIE_SCHLUESSEL:-}" ]; then
  export RCLONE_CONFIG_KOPIE_TYPE=crypt
  export RCLONE_CONFIG_KOPIE_REMOTE="$KOPIE"
  RCLONE_CONFIG_KOPIE_PASSWORD="$(rclone obscure "$SICHERUNG_KOPIE_SCHLUESSEL")"
  export RCLONE_CONFIG_KOPIE_PASSWORD
  KOPIE_ZIEL="kopie:"
  VERSCHLUESSELT=ja
fi

# Die Ordner unter /config/www, in denen BookStack Inhalte ablegt (linuxserver-Image):
#   uploads = Bilder in Seiten, files = Anhänge, images = geschützte Bilder.
# Das Theme kommt aus Git, die .env erzeugt der Container aus den Variablen.
DATEIORDNER=(uploads files images)

protokoll() { echo "$(date '+%F %T') $*"; }

# Bricht eine Sicherung ab: Grund ins Protokoll, halb geschriebene Dateien weg.
verwerfen() {
  protokoll "FEHLER: $2, Sicherung verworfen"
  rm -rf "$1"
}

# Jeder Schritt prüft seinen Erfolg selbst: sichern läuft in „if ! sichern“, und dort setzt Bash „set -e“ außer
# Kraft. Ein Fehler mittendrin (Verbindung weg, Platte voll) liefe sonst still weiter bis „ok“.
sichern() {
  local stempel ordner tmp tar_ende=0
  stempel="$(date +%F_%H%M)"
  # Zweiter Lauf in derselben Minute (etwa von Hand): mit Sekunden, sonst landete er im Ordner des ersten.
  if [ -e "$ZIEL/$stempel" ]; then
    stempel="$(date +%F_%H%M%S)"
  fi
  ordner="$ZIEL/$stempel"
  # Erst in einen versteckten Ordner schreiben und am Ende umbenennen, damit ein
  # abgebrochener Lauf nie wie eine vollständige Sicherung aussieht.
  tmp="$ZIEL/.laufend-$stempel"
  rm -rf "$tmp"
  mkdir -p "$tmp"

  protokoll "Sicherung $stempel beginnt"

  # --single-transaction liefert einen konsistenten Stand, ohne das Wiki zu sperren.
  MYSQL_PWD="$DB_PASSWORD" mariadb-dump \
    --host="$DB_HOST" --user="$DB_USERNAME" \
    --single-transaction --quick --default-character-set=utf8mb4 \
    "$DB_DATABASE" | gzip -6 > "$tmp/datenbank.sql.gz" \
    || { verwerfen "$tmp" "mariadb-dump ist gescheitert"; return 1; }

  local vorhanden=()
  for d in "${DATEIORDNER[@]}"; do
    [ -d "/wiki/www/$d" ] && vorhanden+=("$d")
  done
  # Ganz frisches Wiki ohne Uploads: tar verweigert ein leeres Archiv, deshalb dann ein leeres Verzeichnis sichern.
  if [ ${#vorhanden[@]} -eq 0 ]; then
    tar -czf "$tmp/dateien.tar.gz" -C /wiki/www --files-from /dev/null || tar_ende=$?
  else
    tar -czf "$tmp/dateien.tar.gz" -C /wiki/www "${vorhanden[@]}" || tar_ende=$?
  fi
  # GNU tar meldet 1, wenn sich eine Datei beim Lesen geändert hat (jemand lädt gerade hoch); das Archiv ist
  # trotzdem brauchbar. Ab 2 ist es unvollständig.
  if [ "$tar_ende" -gt 1 ]; then
    verwerfen "$tmp" "tar ist gescheitert (Rückgabe $tar_ende)"
    return 1
  elif [ "$tar_ende" -eq 1 ]; then
    protokoll "Hinweis: Dateien haben sich während der Sicherung geändert; die nächste Sicherung enthält sie vollständig"
  fi

  (cd "$tmp" && sha256sum datenbank.sql.gz dateien.tar.gz > pruefsummen.sha256) \
    || { verwerfen "$tmp" "Prüfsummen ließen sich nicht schreiben"; return 1; }

  # Ein Dump ohne Tabellen wäre wertlos, ein abgebrochener unvollständig; lieber laut scheitern. Seit BookStack 26
  # liegen Bücher, Kapitel und Seiten gemeinsam in „entities“ (eine Tabelle „pages“ gibt es nicht mehr).
  # grep -c statt -q: -q beendet früh, gzip bekäme SIGPIPE und pipefail meldete einen Fehler.
  if [ "$(gzip -dc "$tmp/datenbank.sql.gz" | grep -c "CREATE TABLE \`entities\`")" -eq 0 ]; then
    verwerfen "$tmp" "Dump enthält keine Tabelle entities"
    return 1
  fi
  # Die letzte Zeile schreibt mariadb-dump erst, wenn alles drin ist.
  if ! gzip -dc "$tmp/datenbank.sql.gz" | tail -n 1 | grep -q '^-- Dump completed'; then
    verwerfen "$tmp" "Dump ist unvollständig"
    return 1
  fi

  mv "$tmp" "$ordner" || { verwerfen "$tmp" "$ordner ließ sich nicht anlegen"; return 1; }
  LETZTE="$stempel"
  protokoll "Sicherung fertig: $ordner ($(du -sh "$ordner" | cut -f1))"

  find "$ZIEL" -mindepth 1 -maxdepth 1 -type d -name '20*' -mtime +"$TAGE" -print -exec rm -rf {} + \
    | while read -r alt; do protokoll "Alte Sicherung entfernt: $alt"; done
  # Reste abgebrochener Läufe (Container mitten in der Sicherung beendet) nach einem Tag wegräumen.
  find "$ZIEL" -mindepth 1 -maxdepth 1 -type d \( -name '.laufend-*' -o -name '.holen-*' \) -mtime +0 -exec rm -rf {} + || true
}

# rclone schreibt in Fehlermeldungen die ganze Verbindungszeichenkette, samt Passwort. Nichts davon darf
# ins Protokoll oder über status.json ins Wiki: das Ziel wird zu „<Ziel>“, Geheimnisse zu „***“.
ohne_geheimnisse() {
  local zeile
  while IFS= read -r zeile; do
    [ -n "$KOPIE" ] && zeile="${zeile//"$KOPIE"/<Ziel>}"
    # Rechnernamen bleiben stehen, sie helfen bei der Fehlersuche; Zugangsdaten nie, auch nicht als
    # „https://nutzer:passwort@rechner“ in einer URL.
    printf '%s\n' "$zeile" | sed -E \
      -e "s/(pass|password|password2|secret_access_key|access_key_id|key|token)=[^,:' ]*/\1=***/g" \
      -e "s#(://)[^/@ ]+@#\1***@#g"
  done
}

# Kopie außer Haus: hochladen, gegen die Sicherung vergleichen, alte Kopien entfernen.
# rclone-Meldungen landen (bereinigt) im Protokoll; bei Fehlern steht die letzte Zeile im Status.
kopieren() {
  local stempel="$1" alt grenze
  protokoll "Kopie nach außen beginnt (verschlüsselt: $VERSCHLUESSELT)"
  rclone copy "$ZIEL/$stempel" "$KOPIE_ZIEL/$stempel" --retries 3 --low-level-retries 5 --contimeout 30s --timeout 10m 2>&1 \
    | ohne_geheimnisse | tee /tmp/rclone.log || true
  # Nicht auf den Rückgabewert von copy verlassen: check vergleicht, was wirklich drüben liegt
  # (bei Verschlüsselung die entschlüsselten Größen).
  if ! rclone check "$ZIEL/$stempel" "$KOPIE_ZIEL/$stempel" --one-way --size-only 2>&1 | ohne_geheimnisse >>/tmp/rclone.log; then
    KOPIE_FEHLER="$(grep -v '^\s*$' /tmp/rclone.log | tail -n 1 | cut -c1-200)"
    protokoll "FEHLER: Kopie unvollständig: $KOPIE_FEHLER"
    return 1
  fi
  protokoll "Kopie geprüft: $stempel"
  grenze=$(date -d "-$KOPIE_TAGE days" +%s)
  rclone lsf --dirs-only "$KOPIE_ZIEL" 2>/dev/null | grep '^20' | while read -r alt; do
    alt="${alt%/}"
    if [ "$(date -d "${alt%%_*}" +%s 2>/dev/null || echo "$grenze")" -lt "$grenze" ]; then
      rclone purge "$KOPIE_ZIEL/$alt" 2>&1 | ohne_geheimnisse && protokoll "Alte Kopie entfernt: $alt"
    fi
  done
}

# Eine Kopie zurückholen (bei Verschlüsselung entschlüsselt) und gegen ihre Prüfsummen prüfen.
holen() {
  local stempel="$1"
  [ -n "$KOPIE" ] || { protokoll "FEHLER: SICHERUNG_KOPIE ist nicht gesetzt"; return 1; }
  if [ -z "$stempel" ]; then
    protokoll "Kopien außer Haus:"
    rclone lsf --dirs-only "$KOPIE_ZIEL" 2>&1 | ohne_geheimnisse | grep '^20' | sed 's#/$##'
    return 0
  fi
  if [ -e "$ZIEL/$stempel" ]; then
    protokoll "FEHLER: $ZIEL/$stempel gibt es schon; erst umbenennen oder löschen"
    return 1
  fi
  protokoll "Hole $stempel"
  rclone copy "$KOPIE_ZIEL/$stempel" "$ZIEL/.holen-$stempel" 2>&1 | ohne_geheimnisse
  (cd "$ZIEL/.holen-$stempel" && sha256sum -c pruefsummen.sha256) || { protokoll "FEHLER: Prüfsummen stimmen nicht"; return 1; }
  mv "$ZIEL/.holen-$stempel" "$ZIEL/$stempel"
  protokoll "Geholt nach $ZIEL/$stempel, jetzt: bash /skripte/wiederherstellen.sh $stempel"
}

# Ergebnis für Fundus: ok, sicherung-fehler oder kopie-fehler, dazu Zeit und Meldung.
status() {
  local ergebnis="$1" meldung="${2:-}" kopie="aus"
  [ -n "$KOPIE" ] && kopie="an"
  # JSON von Hand: Steuerzeichen weg, Backslash und Anführungszeichen maskieren.
  meldung="$(printf '%s' "$meldung" | tr -d '\000-\037' | sed 's/\\/\\\\/g; s/"/\\"/g')"
  printf '{"zeit":"%s","ergebnis":"%s","kopie":"%s","verschluesselt":"%s","sicherung":"%s","meldung":"%s"}\n' \
    "$(date -Iseconds)" "$ergebnis" "$kopie" "$VERSCHLUESSELT" "${LETZTE:-}" "$meldung" > "$STATUS.neu"
  mv "$STATUS.neu" "$STATUS"
}

# Ein ganzer Lauf: sichern, kopieren, Status schreiben.
lauf() {
  LETZTE="" KOPIE_FEHLER=""
  if ! sichern; then
    status sicherung-fehler "Die Sicherung ist gescheitert, Einzelheiten im Protokoll des Dienstes sicherung."
    return 1
  fi
  if [ -n "$KOPIE" ] && ! kopieren "$LETZTE"; then
    status kopie-fehler "Die Kopie außer Haus ist gescheitert: ${KOPIE_FEHLER:-unbekannter Fehler}"
    return 1
  fi
  status ok
}

sekunden_bis() {
  local jetzt ziel
  jetzt=$(date +%s)
  ziel=$(date -d "today $UHRZEIT" +%s)
  [ "$ziel" -le "$jetzt" ] && ziel=$(date -d "tomorrow $UHRZEIT" +%s)
  echo $((ziel - jetzt))
}

if [ "${1:-}" = "jetzt" ]; then
  lauf
  exit $?
fi
if [ "${1:-}" = "holen" ]; then
  holen "${2:-}"
  exit $?
fi

# Eine ungültige Uhrzeit legte den Dienst still, und ohne ersten Lauf gäbe es auch kein status.json, über das Fundus
# die Admins warnt. Deshalb gleich melden, und zwar dort, wo die Admins nachsehen.
if ! date -d "today $UHRZEIT" >/dev/null 2>&1; then
  protokoll "FEHLER: SICHERUNG_UHRZEIT „$UHRZEIT“ ist keine Uhrzeit (HH:MM)"
  status sicherung-fehler "SICHERUNG_UHRZEIT „$UHRZEIT“ ist keine Uhrzeit (HH:MM); der Dienst sicherung läuft nicht."
  exit 1
fi

# Das Ziel selbst nie ausgeben: Es enthält die Zugangsdaten.
if [ -n "$KOPIE" ]; then
  protokoll "Sicherungsdienst bereit, täglich um $UHRZEIT, Aufbewahrung $TAGE Tage, Kopie außer Haus $KOPIE_TAGE Tage (verschlüsselt: $VERSCHLUESSELT)"
else
  protokoll "Sicherungsdienst bereit, täglich um $UHRZEIT, Aufbewahrung $TAGE Tage, ohne Kopie außer Haus"
fi
while true; do
  sleep "$(sekunden_bis)"
  # Jede Nacht als neuer Prozess: So gilt nach einem Update gleich die neue Fassung dieses Skripts.
  # Ein Fehlschlag soll den Dienst nicht beenden; die nächste Nacht versucht es erneut.
  bash "$0" jetzt || protokoll "FEHLER: Sicherung fehlgeschlagen"
  # Verhindert einen Doppellauf in derselben Minute.
  sleep 61
done
