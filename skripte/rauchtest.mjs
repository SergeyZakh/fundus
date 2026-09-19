// Rauchtest für das Theme: rendert typische Seiten im Wiki-Container als bestimmte Person, lädt sie in
// headless Chrome und prüft, ob wiki.js ohne Fehler durchläuft und die Umbauten stattgefunden haben.
// Gedacht für vor und nach einem BookStack-Update oder einem größeren Umbau am Theme.
//
// Aufruf (lokaler Stapel „fundus“ läuft, Handbuch und Beispielinhalte eingerichtet):
//   node skripte/rauchtest.mjs                    Ergebnis anzeigen
//   node skripte/rauchtest.mjs vorher.json        zusätzlich speichern, später mit nachher.json vergleichen
// Rückgabewert 1, wenn eine Seite einen JavaScript-Fehler wirft.
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { chromePfad } from './chrome.mjs';

const CONTAINER = process.env.WIKI_CONTAINER || 'fundus-wiki-1';
const CHROME = chromePfad();
const TEMP = fs.mkdtempSync(path.join(os.tmpdir(), 'fundus-rauch-'));

// Adresse und Person (1 = Admin, 3 = Mitarbeiterin). Ein Artikel mit Code, Menüpfad und Bildern ist Pflicht.
const SEITEN = [
  ['/', 3], ['/books', 3], ['/shelves', 3], ['/books/microsoft-365', 3],
  ['/books/microsoft-365/page/freigegebenes-postfach-einrichten', 3],
  ['/books/microsoft-365/page/freigegebenes-postfach-einrichten', 1],
  ['/books/so-funktioniert-das-wiki', 1],
  ['/books/so-funktioniert-das-wiki/page/uberblick', 3],
];

// Was nach dem Laden gezählt wird; die Zahlen vorher und nachher sollten gleich sein.
const PRUEFUNG = `JSON.stringify({
  laedtNoch: document.documentElement.classList.contains('fundus-laedt'),
  aktionsleiste: document.querySelectorAll('.fundus-aktionsleiste').length,
  aktionen: document.querySelectorAll('.fundus-aktion').length,
  mehrMenue: document.querySelectorAll('.fundus-mehr-liste > *').length,
  inhaltRechts: !!document.querySelector('.tri-layout-right-contents > #page-navigation'),
  artikelkopf: !!document.querySelector('#bkmrk-page-title + [data-fundus-artikelkopf]'),
  kennzahlen: !!document.querySelector('main.content-wrap [data-fundus-uebersicht], [data-fundus-liste-kopf]'),
  personen: document.querySelectorAll('[data-fundus-person]').length,
  bilder: document.querySelectorAll('.fundus-bild').length,
  menuepfade: document.querySelectorAll('.fundus-pfad').length,
  codebloecke: document.querySelectorAll('.fundus-code').length,
  kachelSymboleOffen: document.querySelectorAll('template[data-fundus-symbol]').length,
  kachelMeta: document.querySelectorAll('.grid-card .fundus-karte-meta').length,
  kiChat: !!document.querySelector('[data-fundus-ki]'),
  suche: !!document.querySelector('[data-fundus-suche]'),
  rueckmeldung: !!document.querySelector('[data-fundus-rueckmeldung]'),
})`;

// Seite im Container rendern. Als abc (Benutzer des Webservers): Als root angelegte Cache-Ordner
// kann das Wiki danach nicht mehr beschreiben.
const RENDER = `<?php
require '/app/www/vendor/autoload.php';
$app = require '/app/www/bootstrap/app.php';
$kernel = $app->make(Illuminate\\Contracts\\Http\\Kernel::class);
$req = Illuminate\\Http\\Request::create($argv[1], 'GET');
$app->instance('request', $req);
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
auth()->loginUsingId((int) $argv[2]);
echo $kernel->handle($req)->getContent();
`;
fs.writeFileSync(path.join(TEMP, 'render.php'), RENDER);
execFileSync('docker', ['cp', path.join(TEMP, 'render.php'), `${CONTAINER}:/tmp/fundus-rauch.php`]);
const rendern = (adresse, person) => execFileSync('docker', ['exec', '-u', 'abc', '-w', '/app/www', CONTAINER, 'php', '/tmp/fundus-rauch.php', adresse, String(person)], {
  maxBuffer: 64 * 1024 * 1024, env: { ...process.env, MSYS_NO_PATHCONV: '1' },
}).toString();

// ---------- Chrome über das DevTools-Protokoll ----------
const chrome = spawn(CHROME, ['--headless=new', '--disable-gpu', '--remote-debugging-port=0',
  '--disable-web-security', '--allow-file-access-from-files', `--user-data-dir=${path.join(TEMP, 'profil')}`, 'about:blank']);
const wsUrl = await new Promise((ok) => chrome.stderr.on('data', (d) => { const m = String(d).match(/ws:\/\/\S+/); if (m) ok(m[0]); }));
const ws = new WebSocket(wsUrl);
await new Promise((ok) => ws.addEventListener('open', ok));
let nr = 0;
const warten = new Map();
const fehler = [];
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data);
  if (m.id && warten.has(m.id)) { warten.get(m.id)(m); warten.delete(m.id); }
  if (m.method === 'Runtime.exceptionThrown') fehler.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text);
});
const cdp = (method, params = {}, sessionId) => new Promise((ok, nein) => {
  const id = ++nr;
  warten.set(id, (m) => (m.error ? nein(new Error(m.error.message)) : ok(m.result)));
  ws.send(JSON.stringify({ id, method, params, sessionId }));
});
const { targetInfos } = await cdp('Target.getTargets');
const { sessionId } = await cdp('Target.attachToTarget', { targetId: targetInfos.find((t) => t.type === 'page').targetId, flatten: true });
await cdp('Page.enable', {}, sessionId);
await cdp('Runtime.enable', {}, sessionId);
await cdp('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false }, sessionId);

const ergebnis = {};
for (const [adresse, person] of SEITEN) {
  fehler.length = 0;
  const datei = path.join(TEMP, 'seite.html');
  const html = rendern(adresse, person);
  fs.writeFileSync(datei, html.replace('<head>', '<head><script>try{localStorage.clear();sessionStorage.clear();}catch(e){}</script>'));
  await cdp('Page.navigate', { url: `file:///${datei.replace(/\\/g, '/')}` }, sessionId);
  await new Promise((ok) => setTimeout(ok, 2000));
  const r = await cdp('Runtime.evaluate', { expression: PRUEFUNG, returnByValue: true }, sessionId);
  ergebnis[`${adresse} (Person ${person})`] = { ...JSON.parse(r.result.value), jsFehler: [...new Set(fehler)].map((f) => f.split('\n')[0]) };
}

console.log(JSON.stringify(ergebnis, null, 2));
if (process.argv[2]) fs.writeFileSync(process.argv[2], JSON.stringify(ergebnis, null, 2));
const mitFehler = Object.entries(ergebnis).filter(([, e]) => e.jsFehler.length);
console.log(mitFehler.length ? `\n✗ JavaScript-Fehler auf ${mitFehler.length} Seite(n)` : '\n✓ Keine JavaScript-Fehler');

ws.close();
chrome.kill();
execFileSync('docker', ['exec', CONTAINER, 'rm', '-f', '/tmp/fundus-rauch.php']);
await new Promise((ok) => setTimeout(ok, 1200)); // Chrome gibt das Profil erst kurz nach dem Beenden frei
try { fs.rmSync(TEMP, { recursive: true, force: true }); } catch (e) { /* Reste im Temp-Ordner sind harmlos */ }
process.exit(mitFehler.length ? 1 : 0);
