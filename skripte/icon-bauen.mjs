// Erzeugt das App-Icon (Favicon, PWA, OpenSearch): weißes „F“ in Instrument Sans auf fast schwarzem,
// abgerundetem Quadrat. Ergebnis: theme/fundus/public/icon*.png, eingebunden in functions.php.
//
// Aufruf: node skripte/icon-bauen.mjs
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { chromePfad } from './chrome.mjs';

const HIER = path.dirname(fileURLToPath(import.meta.url));
const PUBLIC = path.resolve(HIER, '../theme/fundus/public');
const CHROME = chromePfad();
const TEMP = fs.mkdtempSync(path.join(os.tmpdir(), 'fundus-icon-'));

// Dateiname → Kantenlänge; die Namen entsprechen BookStacks Einstellungen app-icon, app-icon-180 …
const GROESSEN = { 'icon.png': 256, 'icon-180.png': 180, 'icon-128.png': 128, 'icon-64.png': 64, 'icon-32.png': 32 };
const schrift = pathToFileURL(path.join(PUBLIC, 'fonts/instrument-sans-latin.woff2')).href;

for (const [datei, s] of Object.entries(GROESSEN)) {
  const html = `<!doctype html><style>
    @font-face { font-family: 'Instrument Sans'; font-weight: 400 600; src: url(${schrift}) format('woff2'); }
    html, body { margin: 0; background: transparent; }
    div { width: ${s}px; height: ${s}px; border-radius: ${s * 0.22}px; background: #16161a; color: #fff;
          display: flex; align-items: center; justify-content: center;
          font: 600 ${s * 0.66}px/1 'Instrument Sans', sans-serif; }
    /* Nur die Versalhöhe zählt, sonst sitzt der Buchstabe wegen der Unterlänge zu hoch. */
    span { text-box: trim-both cap alphabetic; }
  </style><div><span>F</span></div>`;
  const quelle = path.join(TEMP, `${s}.html`);
  fs.writeFileSync(quelle, html);
  execFileSync(CHROME, ['--headless=new', '--disable-gpu', '--hide-scrollbars', '--allow-file-access-from-files',
    `--user-data-dir=${path.join(TEMP, 'profil')}`, '--default-background-color=00000000', '--virtual-time-budget=2000',
    `--window-size=${s},${s}`, `--screenshot=${path.join(PUBLIC, datei)}`, pathToFileURL(quelle).href], { stdio: 'ignore' });
  console.log(`✓ ${datei} (${s} px)`);
}
fs.rmSync(TEMP, { recursive: true, force: true });
