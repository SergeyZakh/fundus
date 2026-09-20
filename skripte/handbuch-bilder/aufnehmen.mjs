// Erzeugt die Bilder des Handbuchs (handbuch/bilder) neu: Ausschnitte echter Wiki-Seiten mit
// nummerierten Markierungen, die zu den nummerierten Erklärungen im Artikel passen.
//
// Ablauf je Bild: Seite im Container als bestimmte Person rendern (render.php), lokal in headless
// Chrome öffnen (CSS, Schrift und Skripte kommen vom laufenden Wiki), Vorbereitung ausführen
// (z. B. Menü öffnen), Markierungen setzen, Ausschnitt als PNG speichern.
//
// Aufruf (Wiki lokal auf http://localhost:6875, Stapel „fundus“ läuft):
//   node skripte/handbuch-bilder/aufnehmen.mjs            alle Bilder
//   node skripte/handbuch-bilder/aufnehmen.mjs artikel    nur Bilder, deren Name „artikel“ enthält
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BILDER, CHAT_SKRIPTE } from './bilder.mjs';
import { chromePfad } from '../chrome.mjs';

const HIER = path.dirname(fileURLToPath(import.meta.url));
const ZIEL = path.resolve(HIER, '../../handbuch/bilder');
const TEMP = fs.mkdtempSync(path.join(os.tmpdir(), 'fundus-handbuch-'));
const CONTAINER = process.env.WIKI_CONTAINER || 'fundus-wiki-1';
const CHROME = chromePfad();
const filter = process.argv[2] || '';

// Seite im Container rendern: eigenes Mini-Skript, das eine Anfrage als Person X durch BookStack schickt.
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
execFileSync('docker', ['cp', path.join(TEMP, 'render.php'), `${CONTAINER}:/tmp/fundus-render.php`]);
// Immer als abc (Benutzer des Webservers): Als root angelegte Cache-Ordner kann das Wiki später nicht beschreiben.
const rendern = (adresse, person) => execFileSync('docker', ['exec', '-u', 'abc', '-w', '/app/www', CONTAINER, 'php', '/tmp/fundus-render.php', adresse, String(person)], {
  maxBuffer: 64 * 1024 * 1024, env: { ...process.env, MSYS_NO_PATHCONV: '1' },
}).toString();

// ---------- Chrome über das DevTools-Protokoll ----------
const chrome = spawn(CHROME, ['--headless=new', '--disable-gpu', '--hide-scrollbars', '--remote-debugging-port=0',
  '--disable-web-security', '--allow-file-access-from-files', `--user-data-dir=${path.join(TEMP, 'profil')}`, 'about:blank']);
const wsUrl = await new Promise((ok) => chrome.stderr.on('data', (d) => { const m = String(d).match(/ws:\/\/\S+/); if (m) ok(m[0]); }));
const ws = new WebSocket(wsUrl);
await new Promise((ok) => ws.addEventListener('open', ok));
let nr = 0;
const warten = new Map();
const ereignisse = [];
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data);
  if (m.id && warten.has(m.id)) { warten.get(m.id)(m); warten.delete(m.id); } else ereignisse.push(m);
});
const cdp = (method, params = {}, sessionId) => new Promise((ok, fehler) => {
  const id = ++nr;
  warten.set(id, (m) => (m.error ? fehler(new Error(`${method}: ${m.error.message}`)) : ok(m.result)));
  ws.send(JSON.stringify({ id, method, params, sessionId }));
});
const { targetInfos } = await cdp('Target.getTargets');
const { sessionId } = await cdp('Target.attachToTarget', { targetId: targetInfos.find((t) => t.type === 'page').targetId, flatten: true });
await cdp('Page.enable', {}, sessionId);
const auswerten = async (code) => {
  const r = await cdp('Runtime.evaluate', { expression: code, awaitPromise: true, returnByValue: true }, sessionId);
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
  return r.result.value;
};
const pause = (ms) => new Promise((ok) => setTimeout(ok, ms));

// Markierungen und Ausschnitt im Browser berechnen und zeichnen.
const MARKIEREN = `(async (auftrag) => {
  const alle = (sel) => [...document.querySelectorAll(sel)].filter((el) => el.getClientRects().length);
  const rahmen = (els) => {
    const r = els.map((el) => el.getBoundingClientRect());
    return { x: Math.min(...r.map((a) => a.left)), y: Math.min(...r.map((a) => a.top)),
             r: Math.max(...r.map((a) => a.right)), b: Math.max(...r.map((a) => a.bottom)) };
  };
  // Schwebende Knöpfe (Fundus, nach oben) verdecken sonst Inhalte; im Chatbild bleibt das Fenster sichtbar.
  const stil = document.createElement('style');
  stil.textContent = '.fundus-ki-knopf, .back-to-top { display: none !important; }';
  document.head.append(stil);
  const ebene = document.createElement('div');
  ebene.style.cssText = 'position:absolute;left:0;top:0;width:0;height:0;z-index:99999;pointer-events:none';
  document.body.append(ebene);
  const gesetzt = [];
  for (const m of auftrag.marken || []) {
    const els = alle(m.ziel);
    if (!els.length) throw new Error('Markierung nicht gefunden: ' + m.ziel);
    const k = rahmen(m.alle ? els : [els[0]]);
    const pad = m.abstand ?? 4;
    if (m.rahmen !== false) {
      const box = document.createElement('div');
      box.style.cssText = 'position:absolute;border:2.5px solid #E8590C;border-radius:10px;box-shadow:0 0 0 3px rgba(232,89,12,.15)';
      Object.assign(box.style, { left: k.x - pad + scrollX + 'px', top: k.y - pad + scrollY + 'px', width: k.r - k.x + pad * 2 + 'px', height: k.b - k.y + pad * 2 + 'px' });
      ebene.append(box);
    }
    const n = document.createElement('div');
    n.textContent = m.nr;
    n.style.cssText = 'position:absolute;width:26px;height:26px;border-radius:50%;background:#E8590C;color:#fff;font:700 14px/26px Instrument Sans,system-ui,sans-serif;text-align:center;box-shadow:0 0 0 3px #fff,0 2px 6px rgba(0,0,0,.25)';
    const seite = m.seite || 'links-oben';
    // Die Marke sitzt mittig auf der Ecke des Rahmens. Ist das Ziel kleiner als die Marke selbst
    // (26 px) – eine Quellennummer im Text ist 16 px breit –, deckt sie genau das zu, was sie
    // zeigen soll; dann rückt sie ganz daneben. Nennt „seite“ keine Richtung, wird auf dieser
    // Achse mittig ausgerichtet.
    const breit = k.r - k.x + pad * 2;
    const hoch = k.b - k.y + pad * 2;
    const versatzX = breit < 30 ? 26 : 13;
    const versatzY = hoch < 30 ? 26 : 13;
    const weg = m.versatz ?? 0;
    const x = seite.includes('rechts') ? k.r + pad - 26 + versatzX + weg
      : seite.includes('links') ? k.x - pad - versatzX - weg : (k.x + k.r) / 2 - 13;
    const y = seite.includes('unten') ? k.b + pad - 26 + versatzY + weg
      : seite.includes('oben') ? k.y - pad - versatzY - weg : (k.y + k.b) / 2 - 13;
    Object.assign(n.style, { left: x + scrollX + 'px', top: y + scrollY + 'px' });
    ebene.append(n);
    gesetzt.push({ x: x + scrollX, y: y + scrollY });
  }
  let clip;
  if (auftrag.ausschnitt === 'fenster') {
    clip = { x: 0, y: 0, width: innerWidth, height: innerHeight };
  } else {
    const treffer = alle(auftrag.ausschnitt);
    if (!treffer.length) throw new Error('Ausschnitt nicht gefunden: ' + auftrag.ausschnitt);
    const k = rahmen(treffer);
    const rand = auftrag.rand ?? 24;
    clip = { x: Math.max(0, k.x - rand + scrollX), y: Math.max(0, k.y - rand + scrollY), width: k.r - k.x + rand * 2, height: k.b - k.y + rand * 2 };
  }
  // Eine Marke am äußeren Rand des Ausschnitts fiele sonst aus dem Bild: Ausschnitt so weit
  // ziehen, dass jede gesetzte Marke vollständig darin liegt – mit demselben Rand wie das Bild
  // selbst, sonst klebt sie an der Kante.
  const luft = auftrag.rand ?? 24;
  for (const p of gesetzt) {
    const rechts = Math.max(clip.x + clip.width, p.x + 26 + luft);
    const unten = Math.max(clip.y + clip.height, p.y + 26 + luft);
    clip.x = Math.max(0, Math.min(clip.x, p.x - luft));
    clip.y = Math.max(0, Math.min(clip.y, p.y - luft));
    clip.width = rechts - clip.x;
    clip.height = unten - clip.y;
  }
  return clip;
})`;

// Beispiel-Rückmeldungen für das Bild der Auswertung: vor dem Rendern anlegen, danach wieder löschen.
const BEISPIEL_RUECKMELDUNG = `<?php
require '/app/www/vendor/autoload.php';
$app = require '/app/www/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
\\FundusRueckmeldung\\Rueckmeldung::tabelleAnlegen();
$seite = BookStack\\Entities\\Models\\Page::query()->where('slug', $argv[2])->firstOrFail();
$t = Illuminate\\Support\\Facades\\DB::table('fundus_rueckmeldungen');
if ($argv[1] === 'anlegen') {
    $jetzt = now();
    foreach ([[3, 'ja'], [4, 'ja'], [1, 'ja'], [4, 'nein']] as $i => [$person, $art]) {
        $t->insert(['page_id' => $seite->id, 'user_id' => $person, 'art' => $art, 'text' => 'fundus-handbuch-beispiel', 'created_at' => $jetzt, 'updated_at' => $jetzt]);
    }
    $t->insert(['page_id' => $seite->id, 'user_id' => 4, 'art' => 'veraltet', 'grund' => 'bild', 'abschnitt' => 'Schritte',
        'text' => 'Der Screenshot zu Schritt 1 zeigt noch das alte Admin Center.', 'created_at' => $jetzt->copy()->subHours(3), 'updated_at' => $jetzt]);
} else {
    $t->where('page_id', $seite->id)->where(fn ($q) => $q->where('text', 'fundus-handbuch-beispiel')->orWhere('text', 'like', 'Der Screenshot zu Schritt 1 zeigt noch das alte Admin Center.'))->delete();
}
`;
fs.writeFileSync(path.join(TEMP, 'beispiel.php'), BEISPIEL_RUECKMELDUNG);
execFileSync('docker', ['cp', path.join(TEMP, 'beispiel.php'), `${CONTAINER}:/tmp/fundus-beispiel.php`]);
const beispiel = (aktion, adresse) => execFileSync('docker', ['exec', '-u', 'abc', '-w', '/app/www', CONTAINER, 'php', '/tmp/fundus-beispiel.php', aktion, adresse.split('/').pop()], {
  env: { ...process.env, MSYS_NO_PATHCONV: '1' },
});

let fertig = 0;
// Ein kaputtes Bild (Markierung fehlt nach einem Umbau, Seite fehlt) bricht nicht den ganzen Lauf ab:
// Es wird gemeldet, die übrigen Bilder entstehen trotzdem. Am Ende steht die Liste aller Probleme.
const fehler = [];
for (const bild of BILDER.filter((b) => b.name.includes(filter))) {
  try {
    await aufnehmen(bild);
    fertig++;
  } catch (e) {
    const grund = String(e.message || e).replace(/^Error: /, '').split('\n')[0];
    fehler.push([bild.name, grund]);
    console.log(`✗ ${bild.name}.png  ${grund}`);
  }
}

async function aufnehmen(bild) {
  if (bild.beispielRueckmeldung) beispiel('anlegen', bild.adresse);
  let html;
  try {
    html = rendern(bild.adresse, bild.person ?? 3);
  } finally {
    if (bild.beispielRueckmeldung) beispiel('loeschen', bild.adresse);
  }
  if (bild.suche) {
    const treffer = rendern(bild.suche, bild.person ?? 3);
    html = html.replace('<head>', `<head><script>window.__suche = ${JSON.stringify(treffer).replace(/</g, '\\u003c')};</script>`);
  }
  const datei = path.join(TEMP, `${bild.name}.html`);
  // Vor allen Skripten: gespeicherte Zustände (Chat, Seitenleisten) setzen, falls das Bild sie braucht.
  const vorher = `<script>try{localStorage.clear();sessionStorage.clear();${bild.speicher || ''}}catch(e){}</script>`;
  const chatSkripte = bild.chat ? CHAT_SKRIPTE.map((src) => `<script src="${src}"></script>`).join('') : '';
  fs.writeFileSync(datei, html.replace('<head>', `<head>${vorher}${chatSkripte}`));
  await cdp('Emulation.setDeviceMetricsOverride', { width: bild.breite || 1440, height: bild.hoehe || 900, deviceScaleFactor: 1, mobile: false }, sessionId);
  await cdp('Page.navigate', { url: `file:///${datei.replace(/\\/g, '/')}` }, sessionId);
  await pause(bild.warten || 1800);
  await auswerten(`document.fonts.ready.then(() => true)`);
  if (bild.vorbereiten) await auswerten(`(async () => { ${bild.vorbereiten} })()`);
  await pause(400);
  const clip = await auswerten(`${MARKIEREN}(${JSON.stringify({ marken: bild.marken, ausschnitt: bild.ausschnitt || 'fenster', rand: bild.rand })})`);
  await pause(150);
  const { data } = await cdp('Page.captureScreenshot', { format: 'png', clip: { ...clip, scale: 1 }, captureBeyondViewport: true }, sessionId);
  fs.writeFileSync(path.join(ZIEL, `${bild.name}.png`), Buffer.from(data, 'base64'));
  console.log(`✓ ${bild.name}.png  ${Math.round(clip.width)}×${Math.round(clip.height)}`);
}
console.log(`\n${fertig} Bilder in ${ZIEL}`);
if (fehler.length) {
  console.log(`${fehler.length} fehlgeschlagen:`);
  for (const [name, grund] of fehler) console.log(`  ${name}: ${grund}`);
}
ws.close();
chrome.kill();
// Chrome gibt das Profil erst kurz nach dem Beenden frei.
await pause(1500);
try { fs.rmSync(TEMP, { recursive: true, force: true }); } catch (e) { /* Reste im Temp-Ordner sind harmlos */ }
process.exit(fehler.length ? 1 : 0);
