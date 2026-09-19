#!/bin/bash
# Testet die Anmeldung über OIDC von Anfang bis Ende: echter Keycloak, echtes Wiki, echte Weiterleitungen.
#
#   1. Stapel „fundus-anmeldung“ starten: Wiki mit leerer Datenbank, Keycloak mit Test-Zertifikat.
#   2. Dem Wiki die Test-CA beibringen, Rollen mit Gruppen anlegen, in Keycloak zwei Testkonten anlegen.
#   3. skripte/tests/anmeldung-test.mjs meldet sich wie ein Browser an und prüft Rollen, Titel, Gruppenentzug, Abmelden.
#   4. Stapel samt Volumes entfernen.
#
# Das lokale Wiki bleibt unberührt. Aufruf aus dem Ordner fundus:
#   bash skripte/anmeldung-testen.sh             # alles
#   bash skripte/anmeldung-testen.sh --behalten  # Stapel danach stehen lassen (Wiki: http://localhost:6877)

set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1

# Werte, die docker-compose.keycloak.yml verlangt; nur für diesen Test, nie für den Betrieb.
export KEYCLOAK_URL=https://keycloak.test:8443
export KEYCLOAK_ADMIN=admin
export KEYCLOAK_ADMIN_PASSWORD=anmeldung-test-admin
export KEYCLOAK_DB_PASSWORD=anmeldung-test-db
export OIDC_CLIENT_SECRET=anmeldung-test-geheimnis

STAPEL=(docker compose -p fundus-anmeldung -f docker-compose.yml -f docker-compose.keycloak.yml -f docker-compose.anmeldung-test.yml)

behalten=0
[ "${1:-}" = "--behalten" ] && behalten=1

schritt() { echo; echo "== $*"; }
aufraeumen() {
  if [ "$behalten" -eq 0 ]; then
    schritt "Stapel entfernen"
    "${STAPEL[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
  else
    echo "Stapel bleibt stehen. Wiki: http://localhost:6877  Keycloak: https://keycloak.test:8443/admin"
    echo "Entfernen: ${STAPEL[*]} down -v"
  fi
}
trap aufraeumen EXIT

schritt "Stapel frisch starten"
"${STAPEL[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
"${STAPEL[@]}" up -d keycloak

schritt "Warten, bis Keycloak den Realm ausliefert"
for i in $(seq 1 90); do
  if curl -sk --max-time 3 https://127.0.0.1:8443/realms/fundus/.well-known/openid-configuration | grep -q '"issuer"'; then
    echo "bereit nach $((i * 2)) s"; break
  fi
  [ "$i" -eq 90 ] && { echo "FEHLER: Keycloak kam nicht hoch"; "${STAPEL[@]}" logs --tail 40 keycloak; exit 1; }
  sleep 2
done

schritt "Wiki starten und der Test-CA vertrauen"
"${STAPEL[@]}" up -d --wait wiki
# PHP (curl) liest die CA-Liste des Systems bei jeder Verbindung; ein Neustart ist nicht nötig.
#
# ACHTUNG, nur für diesen Wegwerf-Container: An die gebündelte CA-Liste anzuhängen ist schnell, aber
# jedes update-ca-certificates überschreibt sie wieder, und als root in einen laufenden Container zu
# schreiben gehört nicht in den Betrieb. Dort gehört eine eigene CA als Datei nach
# /usr/local/share/ca-certificates/ und danach einmal update-ca-certificates – oder man nimmt gleich
# ein Zertifikat, dem das System ohnehin traut.
"${STAPEL[@]}" exec -T -u root wiki sh -c 'cat /zertifikate/ca.pem >> /etc/ssl/certs/ca-certificates.crt'

schritt "Rollen im Wiki mit Gruppen verknüpfen"
"${STAPEL[@]}" exec -T -u abc -w /app/www wiki php -r '
require "vendor/autoload.php"; $app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use BookStack\Users\Models\Role;
Role::query()->where("system_name", "admin")->update(["external_auth_id" => "wiki-admin"]);
foreach (["Mitarbeiter" => "wiki-mitarbeiter", "Azubi" => "wiki-azubi"] as $name => $gruppe) {
    $rolle = Role::query()->firstOrNew(["display_name" => $name]);
    $rolle->forceFill(["description" => "Anmeldetest", "external_auth_id" => $gruppe, "mfa_enforced" => false])->save();
}
echo "Rollen: ", Role::query()->whereNotNull("external_auth_id")->where("external_auth_id", "!=", "")->pluck("external_auth_id", "display_name")->toJson(), "\n";
'

schritt "Testkonten in Keycloak anlegen"
kc() { "${STAPEL[@]}" exec -T keycloak /opt/keycloak/bin/kcadm.sh "$@"; }
kc config credentials --server http://localhost:8080 --realm master --user "$KEYCLOAK_ADMIN" --password "$KEYCLOAK_ADMIN_PASSWORD" >/dev/null
konto() { # benutzer vorname nachname gruppe titel stufe
  kc create users -r fundus -s username="$1" -s enabled=true -s emailVerified=true -s email="$1@anmeldung.test" \
    -s firstName="$2" -s lastName="$3" -s "attributes.titel=[\"$5\"]" ${6:+-s "attributes.titel_stufe=[\"$6\"]"} >/dev/null
  kc set-password -r fundus --username "$1" --new-password "Test-Passwort-1" >/dev/null
  local id gruppe
  id="$(kc get users -r fundus -q username="$1" --fields id --format csv --noquotes | tr -d '\r')"
  gruppe="$(kc get groups -r fundus -q search="$4" --fields id,name --format csv --noquotes | tr -d '\r' | grep ",$4\$" | cut -d, -f1)"
  kc update "users/$id/groups/$gruppe" -r fundus -s realm=fundus -s userId="$id" -s groupId="$gruppe" -n >/dev/null
  echo "✓ $1 in $4, Titel „$5“"
}
konto mia Mia Muster wiki-mitarbeiter "Junior Consultant" ""
konto alex Alex Azubi wiki-azubi "Auszubildender Fachinformatiker" azubi

schritt "Anmeldung testen"
node skripte/tests/anmeldung-test.mjs
