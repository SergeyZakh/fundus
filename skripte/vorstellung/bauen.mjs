// Erzeugt die Vorstellungsbilder (docs/vorstellung): sieben Folien im Format 1600 × 2000 px für ein
// Karussell, dazu fundus-vorstellung.pdf mit allen Folien (LinkedIn zeigt ein PDF zum Durchblättern).
//
// Ablauf je Folie: Wiki-Seiten im Container als bestimmte Person rendern (wie aufnehmen.mjs), in headless
// Chrome mit doppelter Pixeldichte aufnehmen, mit Überschrift und Seitenzahl als HTML-Vorlage
// zusammensetzen und diese als PNG speichern. Keine Markierungen, nur Beispielinhalte, heller Modus.
//
// Aufruf (Wiki lokal auf http://localhost:6875, Stapel „fundus“ läuft, einrichten.py --beispiele gelaufen):
//   node skripte/vorstellung/bauen.mjs        alle Folien und das PDF
//   node skripte/vorstellung/bauen.mjs 3      nur Folie 3 (kein PDF)
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { CHAT_SKRIPTE } from '../handbuch-bilder/bilder.mjs';
import { wikiImContainer, chromeStarten, pause } from '../wiki-browser.mjs';

const HIER = path.dirname(fileURLToPath(import.meta.url));
const ZIEL = path.resolve(HIER, '../../docs/vorstellung');
const SCHRIFT = pathToFileURL(path.resolve(HIER, '../../theme/fundus/public/fonts/instrument-sans-latin.woff2')).href;
const nurFolie = Number(process.argv[2]) || 0;
fs.mkdirSync(ZIEL, { recursive: true });

const ADMIN = 1;
const MIA = 'mitarbeiter@firma.intern';
const ALEX = 'azubi@firma.intern';
const ARTIKEL = '/books/microsoft-365/page/freigegebenes-postfach-einrichten';
const REPO = 'github.com/SergeyZakh/fundus';

// ---------- Seite im Container als Person rendern (skripte/wiki-browser.mjs) ----------
// Beispieldaten, die nur während einer Aufnahme im Wiki stehen:
//   rueckmeldung / rueckmeldung-weg  eine offene Rückmeldung von Alex, damit der Admin einen Hinweis bekommt
//   gelesen / gelesen-weg            gelesene Artikel für Mia an Werktagen der letzten Monate, damit ihre Aktivität
//                                    auf der Startseite nicht leer ist; gelöscht wird nur, was hier angelegt wurde
const BEISPIEL = `<?php
require '/app/www/vendor/autoload.php';
$app = require '/app/www/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use Illuminate\\Support\\Facades\\DB;
$konto = fn (string $email) => BookStack\\Users\\Models\\User::query()->where('email', $email)->value('id') ?? 1;
$text = 'Der Screenshot zu Schritt 1 zeigt noch das alte Admin Center.';
$merkdatei = '/tmp/fundus-vorstellung-gelesen.json';
switch ($argv[1]) {
    case 'rueckmeldung':
        \\FundusRueckmeldung\\Rueckmeldung::tabelleAnlegen();
        $seite = BookStack\\Entities\\Models\\Page::query()->where('slug', $argv[2])->firstOrFail();
        DB::table('fundus_rueckmeldungen')->insert(['page_id' => $seite->id, 'user_id' => $konto('azubi@firma.intern'), 'art' => 'veraltet',
            'grund' => 'bild', 'abschnitt' => 'Schritte', 'text' => $text, 'created_at' => now()->subHours(2), 'updated_at' => now()]);
        break;
    case 'rueckmeldung-weg':
        DB::table('fundus_rueckmeldungen')->where('text', $text)->where('user_id', $konto('azubi@firma.intern'))->delete();
        break;
    case 'gelesen':
        \\FundusAktivitaet\\Aktivitaet::tabelleAnlegen();
        $mia = $konto('mitarbeiter@firma.intern');
        $artikel = BookStack\\Entities\\Models\\Page::query()->where('draft', false)->where('template', false)->pluck('id')->all();
        mt_srand(7);
        $neu = [];
        for ($tag = now()->subMonths(7)->startOfDay(); $tag->lt(now()->startOfDay()); $tag = $tag->addDay()) {
            if ($tag->isWeekend() || mt_rand(1, 100) > 55) continue;
            foreach ((array) array_rand(array_flip($artikel), mt_rand(1, 3)) as $id) {
                $zeile = ['user_id' => $mia, 'page_id' => (int) $id, 'datum' => $tag->toDateString()];
                if (DB::table('fundus_gelesen')->where($zeile)->exists()) continue;
                DB::table('fundus_gelesen')->insert($zeile);
                $neu[] = $zeile;
            }
        }
        file_put_contents($merkdatei, json_encode($neu));
        break;
    case 'gelesen-weg':
        foreach (json_decode(@file_get_contents($merkdatei) ?: '[]', true) as $zeile) {
            DB::table('fundus_gelesen')->where($zeile)->delete();
        }
        @unlink($merkdatei);
        break;
}
`;
const wiki = wikiImContainer('vorstellung');
const beispielSkript = wiki.ablegen('beispiel', BEISPIEL);
const rendern = (adresse, person, accept) => wiki.rendern(adresse, person, accept || 'text/html');
const beispiel = (aktion) => wiki.php(beispielSkript, aktion, ARTIKEL.split('/').pop());
// Beispieldaten anlegen, aufnehmen, in jedem Fall wieder entfernen.
const mitBeispiel = async (art, aufnahme) => {
  beispiel(art);
  try { return await aufnahme(); } finally { beispiel(`${art}-weg`); }
};

// ---------- Chrome über das DevTools-Protokoll ----------
const chrome = await chromeStarten({ profil: path.join(wiki.temp, 'profil') });
const { cdp, auswerten } = chrome;
const oeffnen = async (datei, breite, hoehe, dichte, mobil = false) => {
  await chrome.fenster(breite, hoehe, dichte, mobil);
  await chrome.oeffnen(datei);
};

// ---------- Aufnahme einer Wiki-Seite ----------
// a: adresse, person, breite/hoehe (CSS-Pixel), mobil, speicher (Skript vor allen anderen), chat (Bibliotheken
// des Chats laden), abrufe (Pfade, die fetch aus vorab gerenderten Antworten bekommt), vorbereiten, ausschnitt
// ('fenster' oder Selektor) und rand. Ergebnis: PNG mit doppelter Pixeldichte als Buffer.
async function aufnehmen(a) {
  const html = rendern(a.adresse, a.person);
  const abrufe = {};
  for (const pfad of a.abrufe || []) {
    abrufe[pfad] = rendern(pfad, a.person, pfad.startsWith('/fundus/') ? 'application/json' : 'text/html');
  }
  // fetch geht in der Datei-Ansicht nicht ans Wiki; was die Seite nachlädt, kommt aus „abrufe“.
  const stummel = `window.__abrufe = ${JSON.stringify(abrufe).replace(/</g, '\\u003c')};
    const __fetch = window.fetch;
    window.fetch = (u, o) => {
      const p = new URL(u, location.href);
      const t = window.__abrufe[p.pathname + p.search];
      if (t === undefined) return __fetch(u, o);
      return Promise.resolve(new Response(t, { status: 200, headers: { 'Content-Type': t.trim().startsWith('{') ? 'application/json' : 'text/html' } }));
    };`;
  const vorher = `<script>try{localStorage.clear();sessionStorage.clear();${stummel}${a.speicher || ''}}catch(e){}</script>`
    // Nach oben und der Sprunglink für Tastatur sind keine Inhalte; der Sprunglink blitzte beim Scrollen auf.
    + '<style>.back-to-top, .skip-to-content-link { display: none !important; }</style>';
  const chatSkripte = a.chat ? CHAT_SKRIPTE.map((src) => `<script src="${src}"></script>`).join('') : '';
  const datei = path.join(wiki.temp, 'seite.html');
  fs.writeFileSync(datei, html.replace('<head>', `<head>${vorher}${chatSkripte}`));
  await oeffnen(datei, a.breite || 1440, a.hoehe || 900, 2, !!a.mobil);
  await pause(a.chat ? 2400 : 1800);
  await auswerten('document.fonts.ready.then(() => true)');
  if (a.vorbereiten) await auswerten(`(async () => { ${a.vorbereiten} })()`);
  await pause(500);
  if (!a.ausschnitt || a.ausschnitt === 'fenster') {
    const { data } = await cdp('Page.captureScreenshot', { format: 'png' });
    return Buffer.from(data, 'base64');
  }
  const clip = await auswerten(`(() => {
    const els = [...document.querySelectorAll(${JSON.stringify(a.ausschnitt)})].filter((el) => el.getClientRects().length);
    if (!els.length) throw new Error('Ausschnitt nicht gefunden: ' + ${JSON.stringify(a.ausschnitt)});
    const r = els.map((el) => el.getBoundingClientRect());
    const rand = ${a.rand ?? 0};
    const x = Math.max(0, Math.min(...r.map((k) => k.left)) - rand), y = Math.min(...r.map((k) => k.top)) - rand;
    return { x: x + scrollX, y: Math.max(0, y + scrollY), width: Math.max(...r.map((k) => k.right)) + rand - x, height: Math.max(...r.map((k) => k.bottom)) + rand - y, scale: 1 };
  })()`);
  const { data } = await cdp('Page.captureScreenshot', { format: 'png', clip, captureBeyondViewport: true });
  return Buffer.from(data, 'base64');
}
const bild = (puffer, klasse = '') => `<img class="${klasse}" src="data:image/png;base64,${puffer.toString('base64')}" alt="">`;

// ---------- Beispielgespräche (nur im Browser-Speicher der Aufnahme, nicht im Wiki) ----------
const quellePostfach = { titel: 'Freigegebenes Postfach einrichten', abschnitt: 'Schritte', thema: 'Microsoft 365', url: `http://localhost:6875${ARTIKEL}`, auszug: 'Im Exchange Admin Center → Empfänger → Postfächer auf „Freigegebenes Postfach hinzufügen“ klicken. Anzeigenamen und Adresse eintragen, speichern.', stelle: 'Im Exchange Admin Center' };
const quelleWeiterleitung = { titel: 'Weiterleitung für ein Postfach einrichten', abschnitt: 'Schritte', thema: 'Microsoft 365', url: 'http://localhost:6875/books/microsoft-365/page/weiterleitung-fur-ein-postfach-einrichten', auszug: 'Im Exchange Admin Center das Postfach öffnen, E-Mail-Fluss → Weiterleitung verwalten.', stelle: '' };
const frage = 'Wie richte ich ein freigegebenes Postfach ein?';
const postfach = [
  { rolle: 'nutzer', text: frage },
  { rolle: 'ki', frage, dauer: 6, quellen: [quellePostfach, quelleWeiterleitung],
    text: 'So legst du ein freigegebenes Postfach an:\n\n1. Im **Exchange Admin Center** unter *Empfänger → Postfächer* auf **Freigegebenes Postfach hinzufügen** klicken [1].\n2. Anzeigenamen und Adresse eintragen und speichern [1].\n3. Das neue Postfach öffnen und unter **Delegierung** die Personen bei **Lesen und verwalten** und **Senden als** eintragen [1].\n\nSoll die Post zusätzlich an eine andere Adresse gehen, richtest du danach eine Weiterleitung ein [2].' },
];
const kundenFrage = 'Wer darf bei der Musterkunde GmbH Änderungen freigeben?';
const kundeMitRecht = [
  { rolle: 'nutzer', text: kundenFrage },
  { rolle: 'ki', frage: kundenFrage, dauer: 5,
    quellen: [{ titel: 'Kundenüberblick', abschnitt: 'Ansprechpartner', thema: 'Musterkunde GmbH', url: 'http://localhost:6875/books/musterkunde-gmbh/page/kundenuberblick', auszug: 'IT-Ansprechpartner vor Ort · Ticket oder Zentrale · Darf Änderungen freigeben', stelle: '' }],
    text: 'Änderungen freigeben darf der **IT-Ansprechpartner vor Ort** [1]. Du erreichst ihn über ein Ticket oder die Zentrale [1].\n\nÜber Anschaffungen entscheidet die Geschäftsführung [1].' },
];
// Wortgleich mit der Antwort in theme/fundus/ki/Ki.php, wenn nichts Lesbares gefunden wird.
const kundeOhneRecht = [
  { rolle: 'nutzer', text: kundenFrage },
  { rolle: 'ki', frage: kundenFrage, dauer: 1, quellen: [], text: 'Dazu habe ich im Wiki nichts gefunden, das du lesen darfst.' },
];
// Das Archiv liegt je Konto unter „fundus-ki-archiv-<Kennung>“; die Kennung steht erst im Baustein des Chats.
const chatSpeicher = (gross, nachrichten, archiv = []) => `sessionStorage.setItem('fundus-ki', ${JSON.stringify(JSON.stringify({ offen: true, gross, chatId: 'beispiel', nachrichten }))});`
  + `document.addEventListener('DOMContentLoaded', () => { const ki = document.querySelector('[data-fundus-ki]'); if (ki) localStorage.setItem('fundus-ki-archiv-' + ki.dataset.nutzer, ${JSON.stringify(JSON.stringify([
    { id: 'beispiel', titel: nachrichten[0].text, zeit: Date.now() - 2 * 60e3, nachrichten }, ...archiv]))}); });`;
const archiv = [
  { id: 'b2', titel: 'Was tun, wenn beim Kunden das Internet ausfällt?', zeit: Date.now() - 26 * 3600e3, nachrichten: [] },
  { id: 'b3', titel: 'MFA für ein Konto zurücksetzen', zeit: Date.now() - 4 * 86400e3, nachrichten: [] },
];
const chatOeffnen = `document.querySelector('[data-fundus-ki-knopf]').click(); document.querySelector('[data-fundus-ki-knopf]').click();`;

// ---------- Folien ----------
// titel: zwei Zeilen, die zweite grau. buehne: async () => HTML für die Fläche unter der Überschrift.
const karte = (puffer) => `<div class="karte">${bild(puffer)}</div>`;
const FOLIEN = [
  {
    titel: ['Das Firmenwiki,', 'das antwortet.'],
    buehne: async () => karte(await mitBeispiel('gelesen', () => aufnehmen({ adresse: '/', person: MIA, breite: 1440, hoehe: 900 }))),
  },
  {
    titel: ['Frag Fundus.', 'Antwort mit Quelle.'],
    buehne: async () => karte(await aufnehmen({
      adresse: ARTIKEL, person: MIA, breite: 1440, hoehe: 860, chat: true,
      speicher: chatSpeicher(true, postfach, archiv),
      vorbereiten: `${chatOeffnen} await new Promise((ok) => setTimeout(ok, 300)); document.querySelector('.fundus-ki-mitte .fundus-ki-quelle > button')?.click();`,
    })),
  },
  {
    titel: ['Ein Klick', 'zur Fundstelle.'],
    buehne: async () => karte(await aufnehmen({
      adresse: ARTIKEL, person: MIA, breite: 1440, hoehe: 860,
      speicher: `sessionStorage.setItem('fundus-ki-stelle', JSON.stringify({ pfad: location.pathname, stelle: 'Im Exchange Admin Center', abschnitt: 'Schritte' }));`,
      // Der Abschnitt leuchtet nur kurz auf und ist zur Aufnahme schon verblasst. Gezeigt wird der helle Zustand
      // aus den ersten 60 % der Animation fundus-ki-aufleuchten (wiki.css), mit denselben Farben.
      vorbereiten: `await new Promise((ok) => setTimeout(ok, 900));
        Object.assign(document.querySelector('.fundus-ki-fundstelle').style, {
          animation: 'none', backgroundColor: 'var(--ki-hell)', boxShadow: '0 0 0 1px var(--ki-kante)',
        });`,
    })),
  },
  {
    titel: ['Strg + K,', 'von überall.'],
    buehne: async () => karte(await aufnehmen({
      adresse: ARTIKEL, person: MIA, breite: 1280, hoehe: 860, abrufe: ['/search?term=postfach'],
      vorbereiten: `document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true }));
        await new Promise((ok) => setTimeout(ok, 200));
        const feld = document.querySelector('[data-fundus-suche] input[name="term"]');
        feld.value = 'postfach';
        feld.dispatchEvent(new Event('input', { bubbles: true }));
        await new Promise((ok) => setTimeout(ok, 1200));`,
    })),
  },
  {
    titel: ['Die KI sieht nur,', 'was du sehen darfst.'],
    buehne: async () => {
      const panel = (person, nachrichten) => aufnehmen({
        adresse: ARTIKEL, person, breite: 1200, hoehe: 700, chat: true,
        speicher: chatSpeicher(false, nachrichten), vorbereiten: chatOeffnen, ausschnitt: '.fundus-ki',
      });
      return `<div class="paar">
        <figure><figcaption>Als Technik</figcaption>${karte(await panel(ADMIN, kundeMitRecht))}</figure>
        <figure><figcaption>Als Azubi</figcaption>${karte(await panel(ALEX, kundeOhneRecht))}</figure>
      </div>`;
    },
  },
  {
    titel: ['Veraltet?', 'Direkt melden.'],
    buehne: async () => {
      const formular = await aufnehmen({
        adresse: ARTIKEL, person: MIA, breite: 1440, hoehe: 1000,
        vorbereiten: `document.querySelector('[data-melden]').click();
          await new Promise((ok) => setTimeout(ok, 300));
          const form = document.querySelector('[data-formular]');
          form.querySelector('input[name="grund"][value="bild"]')?.click();
          form.querySelector('[data-abschnitt]').value = 'Schritte';
          const text = form.querySelector('textarea');
          text.value = 'Der Screenshot zu Schritt 1 zeigt noch das alte Admin Center.';
          text.dispatchEvent(new Event('input', { bubbles: true }));
          document.querySelector('[data-fundus-rueckmeldung]').scrollIntoView({ block: 'center' });`,
        ausschnitt: '.fundus-rueckmeldung', rand: 0,
      });
      const hinweis = await mitBeispiel('rueckmeldung', () => aufnehmen({
        adresse: '/', person: ADMIN, breite: 1440, hoehe: 900, abrufe: ['/fundus/hinweise'],
        vorbereiten: `await new Promise((ok) => setTimeout(ok, 800)); document.querySelector('.fundus-hinweise .oeffnen').click();`,
        ausschnitt: '.fundus-hinweise-karte', rand: 0,
      }));
      return `<div class="gestapelt">${karte(formular)}<figure class="unten"><figcaption>Beim Admin</figcaption>${karte(hinweis)}</figure></div>`;
    },
  },
  {
    titel: ['Open Source, MIT.', 'Mach mit.'],
    // Ohne Logo und Karte: nur die Adresse und ein Satz.
    buehne: async () => `<div class="repo">
        <p class="adresse">${REPO}</p>
        <p class="unterzeile">Der Code steht unter der MIT-Lizenz. Issues, Ideen und Pull Requests sind willkommen.</p>
      </div>`,
  },
];

// ---------- Vorlage einer Folie ----------
const STIL = `
@font-face { font-family: 'Instrument Sans'; font-weight: 400 600; src: url(${SCHRIFT}) format('woff2'); }
:root { --grund: #F2F1ED; --text: #17171A; --grau: #A3A098; --marke: #8D8A82; }
* { box-sizing: border-box; margin: 0; }
html, body { width: 1600px; height: 2000px; }
body { position: relative; background: var(--grund); color: var(--text); font-family: 'Instrument Sans', system-ui, sans-serif; overflow: hidden; }
.kopf { position: absolute; left: 130px; top: 150px; right: 130px; }
.marke { font-size: 46px; font-weight: 600; color: var(--marke); letter-spacing: -.01em; }
h1 { margin-top: 34px; font-size: 124px; line-height: 1.0; font-weight: 600; letter-spacing: -.045em; }
h1 span { display: block; }
h1 span + span { color: var(--grau); }
.buehne { position: absolute; left: 130px; right: 130px; top: 560px; bottom: 210px; display: flex; align-items: center; justify-content: center; }
.seite { position: absolute; right: 130px; bottom: 96px; font-size: 46px; color: var(--grau); letter-spacing: -.01em; }
.karte { display: inline-block; overflow: hidden; border-radius: 28px; background: #fff;
  box-shadow: 0 2px 6px rgba(40, 35, 25, .05), 0 30px 80px rgba(40, 35, 25, .13); }
.karte img { display: block; max-width: 1340px; max-height: 1230px; width: auto; height: auto; }
figure { display: flex; flex-direction: column; gap: 22px; }
figcaption { font-size: 38px; font-weight: 500; color: var(--marke); }
.paar { display: flex; gap: 60px; align-items: flex-start; }
.paar .karte img { max-width: 610px; max-height: 1120px; }
.gestapelt { display: flex; flex-direction: column; gap: 48px; width: 1340px; }
.gestapelt > .karte { align-self: flex-start; }
.gestapelt > .karte img { max-width: 1240px; }
.gestapelt > .unten { align-self: flex-end; }
.gestapelt > .unten .karte img { max-width: 680px; }
.repo { display: flex; flex-direction: column; gap: 36px; width: 100%; }
.adresse { font-size: 80px; font-weight: 600; letter-spacing: -.035em; }
.unterzeile { max-width: 1180px; font-size: 48px; color: var(--marke); line-height: 1.35; }
`;
const folieHtml = (titel, buehne, n, gesamt) => `<!doctype html><html lang="de"><head><meta charset="utf-8"><style>${STIL}</style></head><body>
  <div class="kopf"><p class="marke">Fundus</p><h1>${titel.map((z) => `<span>${z}</span>`).join('')}</h1></div>
  <div class="buehne">${buehne}</div>
  <p class="seite">${n}/${gesamt}</p>
</body></html>`;

const fertig = [];
const fehler = [];
for (const [i, folie] of FOLIEN.entries()) {
  const n = i + 1;
  if (nurFolie && n !== nurFolie) continue;
  try {
    const buehne = await folie.buehne();
    const datei = path.join(wiki.temp, `folie-${n}.html`);
    fs.writeFileSync(datei, folieHtml(folie.titel, buehne, n, FOLIEN.length));
    await oeffnen(datei, 1600, 2000, 1);
    await pause(600);
    await auswerten('document.fonts.ready.then(() => true)');
    // Ohne die Schrift fiele die Folie still auf eine Systemschrift zurück.
    if (!await auswerten(`document.fonts.load("600 124px 'Instrument Sans'").then((f) => f.length > 0)`)) throw new Error('Instrument Sans nicht geladen');
    const { data } = await cdp('Page.captureScreenshot', { format: 'png', clip: { x: 0, y: 0, width: 1600, height: 2000, scale: 1 } });
    const ziel = path.join(ZIEL, `folie-${n}.png`);
    fs.writeFileSync(ziel, Buffer.from(data, 'base64'));
    fertig.push(ziel);
    console.log(`✓ folie-${n}.png  ${Math.round(fs.statSync(ziel).size / 1024)} KB  ${folie.titel.join(' ')}`);
  } catch (e) {
    fehler.push(n);
    console.log(`✗ folie-${n}.png  ${String(e.message || e).split('\n')[0]}`);
  }
}

// Alle Folien als PDF, je Folie eine Seite im selben Format. Nur bei einem vollständigen Lauf.
if (!nurFolie && !fehler.length) {
  const seiten = fertig.map((f) => `<img src="${pathToFileURL(f).href}">`).join('');
  const datei = path.join(wiki.temp, 'pdf.html');
  fs.writeFileSync(datei, `<!doctype html><html><head><meta charset="utf-8"><style>
    @page { size: 1600px 2000px; margin: 0; } * { margin: 0; } img { display: block; width: 1600px; height: 2000px; break-after: page; }
  </style></head><body>${seiten}</body></html>`);
  await oeffnen(datei, 1600, 2000, 1);
  await pause(800);
  const { data } = await cdp('Page.printToPDF', { printBackground: true, preferCSSPageSize: true, marginTop: 0, marginBottom: 0, marginLeft: 0, marginRight: 0 });
  const pdf = path.join(ZIEL, 'fundus-vorstellung.pdf');
  fs.writeFileSync(pdf, Buffer.from(data, 'base64'));
  console.log(`✓ fundus-vorstellung.pdf  ${Math.round(fs.statSync(pdf).size / 1024)} KB`);
}

console.log(`\n${fertig.length} Folien in ${ZIEL}`);
await chrome.beenden();
wiki.aufraeumen();
process.exit(fehler.length ? 1 : 0);
