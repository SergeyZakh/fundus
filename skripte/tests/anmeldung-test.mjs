// Anmeldung über OIDC wie im Browser: Anmeldeseite → Keycloak-Formular → Rückweg ins Wiki, mit Cookies und
// allen Weiterleitungen. Danach Rollen, Titel, Gruppenentzug und Abmelden prüfen.
// Nur über skripte/anmeldung-testen.sh starten; das legt Stapel, Rollen und Testkonten an.
import { execFileSync } from 'node:child_process';
import http from 'node:http';
import https from 'node:https';

const WIKI = 'http://localhost:6877';
const PROJEKT = ['compose', '-p', 'fundus-anmeldung', '-f', 'docker-compose.yml', '-f', 'docker-compose.keycloak.yml', '-f', 'docker-compose.anmeldung-test.yml'];
const PASSWORT = 'Test-Passwort-1';

let bestanden = 0;
const fehlgeschlagen = [];
function pruefe(name, ok, hinweis = '') {
  if (ok) { bestanden++; console.log(`  ✓ ${name}`); } else { fehlgeschlagen.push(name); console.log(`  ✗ ${name}\n      ${hinweis}`); }
}

/* ---------- Ein kleiner Browser: Cookies je Host, Weiterleitungen einzeln ---------- */
function browser() {
  const dosen = new Map();
  // keycloak.test hat keinen DNS-Eintrag; der Test schickt ihn an 127.0.0.1, wo der Port liegt.
  const lookup = (host, opt, cb) => (host.endsWith('.test') || host.endsWith('.localhost') || host === 'localhost'
    ? (opt.all ? cb(null, [{ address: '127.0.0.1', family: 4 }]) : cb(null, '127.0.0.1', 4))
    : cb(new Error('nur localhost und .test')));

  function anfrage(methode, adresse, formular) {
    const url = new URL(adresse);
    const dose = dosen.get(url.host) || new Map();
    const koerper = formular ? new URLSearchParams(formular).toString() : null;
    const modul = url.protocol === 'https:' ? https : http;
    return new Promise((fertig, fehler) => {
      const req = modul.request(url, {
        method: methode, lookup, ca: testCa(), rejectUnauthorized: true,
        headers: {
          Cookie: [...dose].map(([k, v]) => `${k}=${v}`).join('; '),
          ...(koerper ? { 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(koerper) } : {}),
          Accept: 'text/html',
        },
      }, (res) => {
        for (const c of [].concat(res.headers['set-cookie'] || [])) {
          const [paar] = c.split(';');
          const i = paar.indexOf('=');
          dose.set(paar.slice(0, i).trim(), paar.slice(i + 1));
        }
        dosen.set(url.host, dose);
        let text = '';
        res.setEncoding('utf8');
        res.on('data', (d) => { text += d; });
        res.on('end', () => fertig({ status: res.statusCode, ort: res.headers.location ? new URL(res.headers.location, url).href : null, text }));
      });
      req.on('error', fehler);
      if (koerper) req.write(koerper);
      req.end();
    });
  }

  /** Weiterleitungen folgen; gibt die letzte Antwort und alle besuchten Adressen zurück. */
  async function folgen(antwort, weg = []) {
    while (antwort.ort && [301, 302, 303, 307].includes(antwort.status) && weg.length < 15) {
      weg.push(antwort.ort);
      antwort = await anfrage('GET', antwort.ort);
    }
    return { antwort, weg };
  }

  return { anfrage, folgen };
}

const htmlDekodieren = (t) => t.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
const csrf = (html) => (html.match(/name="_token"\s+value="([^"]+)"/) || html.match(/<meta name="token" content="([^"]+)"/) || [])[1];

/** Kompletter Anmeldeweg. Ergebnis: { b (Browser), start (HTML nach dem Login), weg } */
async function anmelden(benutzer) {
  const b = browser();
  const login = await b.anfrage('GET', `${WIKI}/login`);
  const token = csrf(login.text);
  if (!token) throw new Error('Kein CSRF-Token auf /login');
  let r = await b.anfrage('POST', `${WIKI}/oidc/login`, { _token: token });
  if (!r.ort?.includes('/realms/fundus/protocol/openid-connect/auth')) throw new Error(`Keine Weiterleitung zu Keycloak: ${r.status} ${r.ort}`);
  const zuKeycloak = await b.folgen(r);
  const formular = zuKeycloak.antwort.text.match(/<form[^>]*id="kc-form-login"[^>]*action="([^"]+)"/);
  if (!formular) throw new Error('Kein Keycloak-Anmeldeformular');
  r = await b.anfrage('POST', htmlDekodieren(formular[1]), { username: benutzer, password: PASSWORT, credentialId: '' });
  if (!r.ort?.startsWith(`${WIKI}/oidc/callback`)) throw new Error(`Keycloak leitet nicht zum Wiki zurück: ${r.status} ${r.ort} ${r.text.slice(0, 200)}`);
  const zurueck = await b.folgen(r, [r.ort]);
  return { b, start: zurueck.antwort, weg: zurueck.weg };
}

/* ---------- Helfer für Container ---------- */
const docker = (...args) => execFileSync('docker', [...PROJEKT, ...args], { encoding: 'utf8', env: { ...process.env, MSYS_NO_PATHCONV: '1' } });

/**
 * Die Test-CA aus dem Keycloak-Container, einmal geholt und gemerkt.
 *
 * Damit prüft der Test die TLS-Kette wirklich. Mit rejectUnauthorized: false wäre er
 * auch dann grün, wenn Keycloak ein falsches oder abgelaufenes Zertifikat ausliefert –
 * und genau das soll er ja mit abdecken.
 */
let caZwischen = null;
const testCa = () => (caZwischen ??= docker('exec', '-T', 'keycloak', 'cat', '/zertifikate/ca.pem'));
const wikiPhp = (code) => docker('exec', '-T', '-u', 'abc', '-w', '/app/www', 'wiki', 'php', '-r',
  `require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`).trim();
const kcadm = (...args) => docker('exec', '-T', 'keycloak', '/opt/keycloak/bin/kcadm.sh', ...args).trim();
const konto = (benutzer) => JSON.parse(wikiPhp(`
  $u = BookStack\\Users\\Models\\User::query()->where("email", "${benutzer}@anmeldung.test")->first();
  echo json_encode($u ? ["name" => $u->name, "rollen" => $u->roles()->pluck("display_name")->sort()->values(), "extern" => $u->external_auth_id,
    "titel" => DB::table("fundus_titel")->where("user_id", $u->id)->first()] : null);`));

/* ---------- Tests ---------- */
console.log('\nAnmeldung über Keycloak');

try {
  const mia = await anmelden('mia');
  pruefe('Mia landet angemeldet im Wiki', mia.start.status === 200 && /Hallo Mia|Mia Muster/.test(mia.start.text) && !/\/login"/.test(mia.weg.at(-1) || ''),
    `Status ${mia.start.status}, Weg: ${mia.weg.join(' → ')}`);
  const kMia = konto('mia');
  pruefe('Konto wurde beim ersten Login angelegt, Name aus Keycloak', kMia?.name === 'Mia Muster', JSON.stringify(kMia));
  pruefe('Mia bekommt über ihre Gruppe genau die Rolle Mitarbeiter', JSON.stringify(kMia?.rollen) === '["Mitarbeiter"]', JSON.stringify(kMia?.rollen));
  pruefe('Konto-ID aus dem Claim „sub“ gespeichert', /^[0-9a-f-]{36}$/.test(kMia?.extern || ''), kMia?.extern);
  pruefe('Titel aus Keycloak, Stufe aus dem Titel abgeleitet', kMia?.titel?.titel === 'Junior Consultant' && kMia?.titel?.stufe === 'junior', JSON.stringify(kMia?.titel));
  pruefe('Titel steht im Wiki neben dem Namen', mia.start.text.includes('"titel":"Junior Consultant"'));

  const alex = await anmelden('alex');
  const kAlex = konto('alex');
  pruefe('Alex bekommt die Rolle Azubi und die Stufe aus Keycloak', JSON.stringify(kAlex?.rollen) === '["Azubi"]' && kAlex?.titel?.stufe === 'azubi', JSON.stringify(kAlex));

  // Gruppe in Keycloak entziehen: Beim nächsten Login ist die Rolle weg.
  const id = kcadm('get', 'users', '-r', 'fundus', '-q', 'username=mia', '--fields', 'id', '--format', 'csv', '--noquotes').replace(/\r/g, '');
  const gruppe = kcadm('get', `users/${id}/groups`, '-r', 'fundus', '--fields', 'id', '--format', 'csv', '--noquotes').replace(/\r/g, '').split('\n')[0];
  kcadm('delete', `users/${id}/groups/${gruppe}`, '-r', 'fundus');
  kcadm('update', `users/${id}`, '-r', 'fundus', '-s', 'attributes.titel=["Senior Consultant"]');
  await anmelden('mia');
  const kMia2 = konto('mia');
  pruefe('Gruppe in Keycloak entfernt → Rolle beim nächsten Login entzogen', JSON.stringify(kMia2?.rollen) === '[]', JSON.stringify(kMia2?.rollen));
  pruefe('Geänderter Titel wird beim nächsten Login übernommen', kMia2?.titel?.titel === 'Senior Consultant' && kMia2?.titel?.stufe === 'senior', JSON.stringify(kMia2?.titel));

  // Abmelden im Wiki führt zur Abmeldung bei Keycloak. Der Knopf im Wiki schickt bei
  // AUTH_METHOD=oidc an /oidc/logout (header-user-menu.blade.php); /logout wäre der
  // gewöhnliche Weg und würde den Anbieter gar nicht erst fragen.
  const seite = await alex.b.anfrage('GET', `${WIKI}/`);
  const abmelden = await alex.b.anfrage('POST', `${WIKI}/oidc/logout`, { _token: csrf(seite.text) });
  pruefe('Abmelden leitet zur Abmeldung bei Keycloak', (abmelden.ort || '').includes('/protocol/openid-connect/logout'), `${abmelden.status} ${abmelden.ort}`);

  const falsch = browser();
  const login = await falsch.anfrage('GET', `${WIKI}/login`);
  const kc = await falsch.folgen(await falsch.anfrage('POST', `${WIKI}/oidc/login`, { _token: csrf(login.text) }));
  const aktion = kc.antwort.text.match(/<form[^>]*id="kc-form-login"[^>]*action="([^"]+)"/)[1];
  const r = await falsch.anfrage('POST', htmlDekodieren(aktion), { username: 'mia', password: 'falsch', credentialId: '' });
  pruefe('Falsches Passwort bleibt bei Keycloak hängen', r.status === 200 && !r.ort, `${r.status} ${r.ort}`);
} catch (e) {
  pruefe('Anmeldeweg ohne Ausnahme', false, e.stack || String(e));
}

console.log(`\n${bestanden} bestanden, ${fehlgeschlagen.length} fehlgeschlagen`);
process.exit(fehlgeschlagen.length ? 1 : 0);
