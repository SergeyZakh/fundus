// Baut docs/ENTWICKLUNG.pdf aus docs/ENTWICKLUNG.md (Aussehen: stil.css daneben).
//
//   node skripte/pdf/bauen.mjs            (Chrome-Pfad über die Variable CHROME änderbar)
//
// Ablauf: Markdown mit marked (liegt schon im Theme) in HTML umwandeln, Deckblatt, Inhaltsverzeichnis,
// Kapitelnummern und Hinweiskästen ergänzen, in headless Chrome öffnen und als PDF drucken.
// Schreibweisen für Kästen, Bilder und Seitenumbrüche stehen oben in ENTWICKLUNG.md.
import { spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { chromePfad } from '../chrome.mjs';

const HIER = path.dirname(fileURLToPath(import.meta.url));
const DOCS = path.join(HIER, '..', '..', 'docs');
const QUELLE = path.join(DOCS, 'ENTWICKLUNG.md');
const ZIEL = path.join(DOCS, 'ENTWICKLUNG.pdf');
const STIL = pathToFileURL(path.join(HIER, 'stil.css')).href;
// Zwischendatei neben der Quelle, damit die relativen Pfade der Bilder stimmen; wird am Ende gelöscht.
const ZWISCHEN = path.join(DOCS, '.ENTWICKLUNG-druck.html');
const CHROME = chromePfad();
const { marked } = createRequire(import.meta.url)('../../theme/fundus/ki/vendor/marked.umd.js');

const maskieren = (t) => String(t).replace(/[&<>"]/g, (z) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[z]));
const klartext = (html) => html.replace(/<[^>]+>/g, '').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'");

// ---------- Kopfdaten (zwischen den --- am Dateianfang) ----------
let md = fs.readFileSync(QUELLE, 'utf8').replace(/\r\n/g, '\n');
const kopf = { fakten: [] };
const kopfTreffer = md.match(/^---\n([\s\S]*?)\n---\n/);
if (kopfTreffer) {
  let liste = null;
  for (const zeile of kopfTreffer[1].split('\n')) {
    const eintrag = zeile.match(/^\s+-\s+(.*)$/);
    const feld = zeile.match(/^(\w+):\s*(.*)$/);
    if (eintrag && liste) kopf[liste].push(eintrag[1]);
    else if (feld) { liste = feld[2] ? null : feld[1]; kopf[feld[1]] = feld[2] || []; }
  }
  md = md.slice(kopfTreffer[0].length);
}

// ---------- Markdown → HTML ----------
md = md.replace(/^<!--\s*neue Seite\s*-->$/gim, '<div class="neue-seite"></div>');
let html = marked.parse(md, { gfm: true });

// Hinweiskästen in GitHubs Schreibweise: > [!CAUTION] allein in der Zeile, darunter > **Titel**.
// Nur diese fünf Marken zeigt GitHub selbst als Kasten; hier werden sie auf die vier Stilarten
// der PDF-Fassung abgebildet.
const KASTEN = { note: 'info', tip: 'tipp', important: 'info', warning: 'achtung', caution: 'gefahr' };
html = html.replace(/<blockquote>\s*<p>\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*(?:<strong>([^<]*)<\/strong>)?([\s\S]*?)<\/blockquote>/gi, (_, marke, titel, rest) => {
  rest = rest.trim().replace(/^<\/p>\s*/, '');
  const kopf = titel ? `<span class="titel">${titel}</span>` : '';
  return `<div class="kasten ${KASTEN[marke.toLowerCase()]}">${kopf}${rest.startsWith('<') ? rest : `<p>${rest}`}</div>`;
});

// Bilder allein im Absatz: Alternativtext wird zur Bildunterschrift, Abbildungen werden durchgezählt.
let abbildung = 0;
html = html.replace(/<p>\s*<img src="([^"]+)" alt="([^"]*)"\s*\/?>\s*<\/p>/g, (_, src, alt) => {
  const art = src.endsWith('.svg') ? 'diagramm' : 'bildschirm';
  return `<figure class="${art}"><div class="rahmen"><img src="${src}" alt=""></div>`
    + `<figcaption><b>Abbildung ${++abbildung}</b>${alt ? ` ${alt}` : ''}</figcaption></figure>`;
});

// Kapitel nummerieren, Anhänge mit Buchstaben, Unterkapitel als 3.1, 3.2 …; alles fürs Inhaltsverzeichnis sammeln.
const kapitel = [];
let nummer = 0;
let anhang = 0;
html = html.replace(/<h([12])[^>]*>([\s\S]*?)<\/h\1>/g, (_, ebene, inhalt) => {
  const aktuell = kapitel.at(-1);
  if (ebene === '2') {
    if (!aktuell) return `<h2>${inhalt}</h2>`;
    const nr = `${aktuell.nr}.${aktuell.unter.length + 1}`;
    const id = `k${kapitel.length}-${aktuell.unter.length + 1}`;
    aktuell.unter.push({ id, nr, titel: klartext(inhalt) });
    return `<h2 id="${id}"><span class="nr">${nr}</span>${inhalt}</h2>`;
  }
  const istAnhang = /^Anhang:\s*/.test(inhalt);
  const nr = istAnhang ? String.fromCharCode(65 + anhang++) : String(++nummer);
  const titel = inhalt.replace(/^Anhang:\s*/, '');
  kapitel.push({ id: `k${kapitel.length + 1}`, nr, titel: klartext(titel), unter: [] });
  const etikett = istAnhang ? `Anhang ${nr}` : `Kapitel ${nr.padStart(2, '0')}`;
  return `<h1 class="kapitel" id="k${kapitel.length}"><span class="etikett">${etikett}</span><span class="titel">${titel}</span></h1>`;
});
html = html.replace(/<div class="neue-seite"><\/div>\s*<h1 class="kapitel"/g, '<h1 class="kapitel neu"');
// Das erste Kapitel beginnt ohnehin auf einer neuen Seite (nach dem Inhaltsverzeichnis).
html = html.replace('<h1 class="kapitel"', '<h1 class="kapitel neu"').replace(/<div class="neue-seite"><\/div>/g, '');

// ---------- Deckblatt und Inhaltsverzeichnis ----------
const deckblatt = `<section class="deckblatt">
  <div class="oben"><span>${maskieren(kopf.marke || '')}</span><span>Intern</span></div>
  <div class="mitte">
    <div class="art">Entwicklerdokumentation</div>
    <h1>${maskieren(kopf.titel || 'Dokumentation')}</h1>
    <p class="unter">${maskieren(kopf.untertitel || '')}</p>
  </div>
  <div class="fakten">${kopf.fakten.map((f) => { const [fett, text = ''] = f.split('|').map((s) => s.trim()); return `<div><b>${maskieren(fett)}</b><span>${maskieren(text)}</span></div>`; }).join('')}</div>
  <div class="fuss"><span>Stand ${maskieren(kopf.stand || '')}</span><span>Fundus</span></div>
</section>`;

const inhalt = `<section class="inhalt">
  <div class="inhalt-kopf"><span class="etikett">Übersicht</span><h1>Inhalt</h1></div>
  <ol>${kapitel.map((k) => `
  <li><a href="#${k.id}"><span class="nr">${k.nr}</span><span class="titel">${maskieren(k.titel)}</span></a>${k.unter.length ? `<ol>${k.unter.map((u) => `<li><a href="#${u.id}"><span class="nr">${u.nr}</span>${maskieren(u.titel)}</a></li>`).join('')}</ol>` : ''}</li>`).join('')}
  </ol>
</section>`;

fs.writeFileSync(ZWISCHEN, `<!doctype html><html lang="de"><head><meta charset="utf-8">
<title>${maskieren(kopf.titel || '')} – Entwicklerdokumentation</title><link rel="stylesheet" href="${STIL}"></head>
<body>${deckblatt}${inhalt}${html}</body></html>`);

// ---------- Drucken mit headless Chrome ----------
const profil = fs.mkdtempSync(path.join(os.tmpdir(), 'fundus-pdf-'));
const chrome = spawn(CHROME, ['--headless=new', '--disable-gpu', '--remote-debugging-port=0', '--allow-file-access-from-files', `--user-data-dir=${profil}`, 'about:blank']);
const wsUrl = await new Promise((ok) => chrome.stderr.on('data', (d) => { const m = String(d).match(/ws:\/\/\S+/); if (m) ok(m[0]); }));
const ws = new WebSocket(wsUrl);
await new Promise((ok) => ws.addEventListener('open', ok));
let nr = 0;
const warten = new Map();
ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && warten.has(m.id)) { warten.get(m.id)(m); warten.delete(m.id); } });
const cdp = (method, params = {}, sessionId) => new Promise((ok, nein) => {
  const id = ++nr;
  warten.set(id, (m) => (m.error ? nein(new Error(`${method}: ${m.error.message}`)) : ok(m.result)));
  ws.send(JSON.stringify({ id, method, params, sessionId }));
});

try {
  const { targetInfos } = await cdp('Target.getTargets');
  const { sessionId } = await cdp('Target.attachToTarget', { targetId: targetInfos.find((t) => t.type === 'page').targetId, flatten: true });
  await cdp('Page.enable', {}, sessionId);
  await cdp('Page.navigate', { url: pathToFileURL(ZWISCHEN).href }, sessionId);
  await new Promise((ok) => setTimeout(ok, 1500));
  await cdp('Runtime.evaluate', { expression: 'document.fonts.ready.then(() => true)', awaitPromise: true }, sessionId);

  const fuss = `<div style="width:100%; font: 7pt 'Segoe UI', sans-serif; color:#8C8C8C; padding:0 20mm; display:flex; justify-content:space-between;">
    <span>${maskieren(kopf.titel || '')} · Entwicklerdokumentation</span><span class="pageNumber"></span></div>`;
  const { data } = await cdp('Page.printToPDF', {
    printBackground: true, preferCSSPageSize: true, displayHeaderFooter: true,
    headerTemplate: '<span></span>', footerTemplate: fuss, generateDocumentOutline: true, generateTaggedPDF: true,
  }, sessionId);
  fs.writeFileSync(ZIEL, Buffer.from(data, 'base64'));
  console.log(`✓ ${path.relative(process.cwd(), ZIEL)} (${Math.round(fs.statSync(ZIEL).size / 1024)} KB, ${kapitel.length} Kapitel)`);
} finally {
  ws.close();
  chrome.kill();
  fs.rmSync(ZWISCHEN, { force: true });
  await new Promise((ok) => setTimeout(ok, 1000));
  try { fs.rmSync(profil, { recursive: true, force: true }); } catch (e) { /* harmlos */ }
}
process.exit(0);
