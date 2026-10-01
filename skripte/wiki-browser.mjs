// Gemeinsamer Unterbau für die Skripte, die Wiki-Seiten als Person rendern und in headless Chrome öffnen:
// Rauchtest (skripte/rauchtest.mjs), Handbuch-Bilder (skripte/handbuch-bilder/aufnehmen.mjs) und
// Vorstellungsbilder (skripte/vorstellung/bauen.mjs).
//
//   wikiImContainer(name)   PHP-Skripte in den Wiki-Container legen und als abc ausführen, Seiten als Person rendern
//   chromeStarten(optionen) headless Chrome über das DevTools-Protokoll steuern
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromePfad } from './chrome.mjs';

export const CONTAINER = process.env.WIKI_CONTAINER || 'fundus-wiki-1';
export const pause = (ms) => new Promise((ok) => setTimeout(ok, ms));

// Seite im Container rendern: eine Anfrage als Person (Kennung oder E-Mail) durch BookStack schicken. Fehlt das
// Konto oder antwortet die Seite nicht mit 200, bricht es ab; sonst prüfte ein Test still die Anmeldeseite statt
// der Seite. Das dritte Argument ist der Accept-Kopf (die Hinweise antworten mit JSON).
const RENDER = `<?php
require '/app/www/vendor/autoload.php';
$app = require '/app/www/bootstrap/app.php';
$kernel = $app->make(Illuminate\\Contracts\\Http\\Kernel::class);
$req = Illuminate\\Http\\Request::create($argv[1], 'GET');
$req->headers->set('Accept', $argv[3] ?? 'text/html');
$app->instance('request', $req);
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$konten = BookStack\\Users\\Models\\User::query();
$konto = ctype_digit($argv[2]) ? $konten->find((int) $argv[2]) : $konten->where('email', $argv[2])->first();
if (!$konto) {
    fwrite(STDERR, "Konto {$argv[2]} fehlt; einmal python skripte/einrichten.py --beispiele laufen lassen.\\n");
    exit(3);
}
auth()->login($konto);
$antwort = $kernel->handle($req);
if ($antwort->getStatusCode() !== 200) {
    fwrite(STDERR, "{$argv[1]} antwortet {$antwort->getStatusCode()} für {$argv[2]}.\\n");
    exit(4);
}
echo $antwort->getContent();
`;

/**
 * PHP im Wiki-Container. name trennt die Dateien mehrerer Skripte (/tmp/fundus-<name>-….php).
 * Immer als abc (Benutzer des Webservers): Als root angelegte Cache-Ordner kann das Wiki später nicht beschreiben.
 */
export function wikiImContainer(name) {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), `fundus-${name}-`));
  const abgelegt = [];
  const ablegen = (kurz, inhalt) => {
    const lokal = path.join(temp, `${kurz}.php`);
    const ziel = `/tmp/fundus-${name}-${kurz}.php`;
    fs.writeFileSync(lokal, inhalt);
    execFileSync('docker', ['cp', lokal, `${CONTAINER}:${ziel}`]);
    abgelegt.push(ziel);
    return ziel;
  };
  const php = (...args) => execFileSync('docker', ['exec', '-u', 'abc', '-w', '/app/www', CONTAINER, 'php', ...args], {
    maxBuffer: 64 * 1024 * 1024, env: { ...process.env, MSYS_NO_PATHCONV: '1' },
  }).toString();
  const render = ablegen('render', RENDER);
  return {
    temp,
    ablegen,
    php,
    rendern: (adresse, person, accept = 'text/html') => php(render, adresse, String(person), accept),
    aufraeumen: () => {
      execFileSync('docker', ['exec', CONTAINER, 'rm', '-f', ...abgelegt]);
      // Chrome gibt sein Profil (im selben Ordner) erst kurz nach dem Beenden frei; Reste im Temp-Ordner sind harmlos.
      try { fs.rmSync(temp, { recursive: true, force: true }); } catch (e) { /* siehe oben */ }
    },
  };
}

/**
 * headless Chrome mit einer Seite. Optionen: profil (Ordner), scrollleisten (Vorgabe aus), jsFehler (Liste, in die
 * Ausnahmen der Seite geschrieben werden). Liefert cdp(methode, parameter), auswerten(code), oeffnen(datei, …) und beenden().
 */
export async function chromeStarten({ profil, scrollleisten = false, jsFehler = null } = {}) {
  const chrome = spawn(chromePfad(), ['--headless=new', '--disable-gpu', ...(scrollleisten ? [] : ['--hide-scrollbars']),
    '--remote-debugging-port=0', '--disable-web-security', '--allow-file-access-from-files', `--user-data-dir=${profil}`, 'about:blank']);
  const wsUrl = await new Promise((ok) => chrome.stderr.on('data', (d) => { const m = String(d).match(/ws:\/\/\S+/); if (m) ok(m[0]); }));
  const ws = new WebSocket(wsUrl);
  await new Promise((ok) => ws.addEventListener('open', ok));
  let nr = 0;
  const warten = new Map();
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && warten.has(m.id)) { warten.get(m.id)(m); warten.delete(m.id); }
    if (jsFehler && m.method === 'Runtime.exceptionThrown') {
      jsFehler.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text);
    }
  });
  const senden = (method, params = {}, sessionId) => new Promise((ok, fehler) => {
    const id = ++nr;
    warten.set(id, (m) => (m.error ? fehler(new Error(`${method}: ${m.error.message}`)) : ok(m.result)));
    ws.send(JSON.stringify({ id, method, params, sessionId }));
  });
  const { targetInfos } = await senden('Target.getTargets');
  const { sessionId } = await senden('Target.attachToTarget', { targetId: targetInfos.find((t) => t.type === 'page').targetId, flatten: true });
  const cdp = (method, params = {}) => senden(method, params, sessionId);
  await cdp('Page.enable');
  if (jsFehler) await cdp('Runtime.enable');

  return {
    cdp,
    async auswerten(code) {
      const r = await cdp('Runtime.evaluate', { expression: code, awaitPromise: true, returnByValue: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    },
    async fenster(breite, hoehe, dichte = 1, mobil = false) {
      await cdp('Emulation.setDeviceMetricsOverride', { width: breite, height: hoehe, deviceScaleFactor: dichte, mobile: mobil });
    },
    async oeffnen(datei) {
      await cdp('Page.navigate', { url: pathToFileURL(datei).href });
    },
    async beenden() {
      ws.close();
      chrome.kill();
      await pause(1500); // Chrome gibt das Profil erst kurz nach dem Beenden frei
    },
  };
}
