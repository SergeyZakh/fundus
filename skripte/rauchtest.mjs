// Rauchtest für das Theme: rendert typische Seiten im Wiki-Container als bestimmte Person, lädt sie in
// headless Chrome und prüft, ob wiki.js ohne Fehler durchläuft und die Umbauten stattgefunden haben.
// Gedacht für vor und nach einem BookStack-Update oder einem größeren Umbau am Theme.
//
// Aufruf (lokaler Stapel „fundus“ läuft, Handbuch und Beispielinhalte eingerichtet):
//   node skripte/rauchtest.mjs                    Ergebnis anzeigen
//   node skripte/rauchtest.mjs vorher.json        zusätzlich speichern, später mit nachher.json vergleichen
// Rückgabewert 1, wenn eine Seite einen JavaScript-Fehler wirft oder sich nicht laden lässt.
import fs from 'node:fs';
import path from 'node:path';
import { wikiImContainer, chromeStarten, pause } from './wiki-browser.mjs';

// Adresse und Person: 1 ist das erste Konto (Admin), Mia die Mitarbeiterin aus einrichten.py --beispiele,
// gefunden über ihre E-Mail-Adresse. Ein Artikel mit Code, Menüpfad und Bildern ist Pflicht.
const MIA = 'mitarbeiter@firma.intern';
const SEITEN = [
  ['/', MIA], ['/books', MIA], ['/shelves', MIA], ['/books/microsoft-365', MIA],
  ['/books/microsoft-365/page/freigegebenes-postfach-einrichten', MIA],
  ['/books/microsoft-365/page/freigegebenes-postfach-einrichten', 1],
  ['/books/so-funktioniert-das-wiki', 1],
  ['/books/so-funktioniert-das-wiki/page/uberblick', MIA],
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

// Seite im Container als Person rendern (skripte/wiki-browser.mjs), in headless Chrome öffnen und zählen.
const wiki = wikiImContainer('rauch');
const fehler = [];
const chrome = await chromeStarten({ profil: path.join(wiki.temp, 'profil'), scrollleisten: true, jsFehler: fehler });
await chrome.fenster(1440, 900);

const ergebnis = {};
let nichtGeladen = 0;
for (const [adresse, person] of SEITEN) {
  fehler.length = 0;
  const datei = path.join(wiki.temp, 'seite.html');
  let html;
  try {
    html = wiki.rendern(adresse, person);
  } catch (e) {
    ergebnis[`${adresse} (Person ${person})`] = { nichtGeladen: String(e.stderr || e.message).trim().split('\n').pop() };
    nichtGeladen++;
    continue;
  }
  fs.writeFileSync(datei, html.replace('<head>', '<head><script>try{localStorage.clear();sessionStorage.clear();}catch(e){}</script>'));
  await chrome.oeffnen(datei);
  await pause(2000);
  ergebnis[`${adresse} (Person ${person})`] = { ...JSON.parse(await chrome.auswerten(PRUEFUNG)), jsFehler: [...new Set(fehler)].map((f) => f.split('\n')[0]) };
}

console.log(JSON.stringify(ergebnis, null, 2));
if (process.argv[2]) fs.writeFileSync(process.argv[2], JSON.stringify(ergebnis, null, 2));
const mitFehler = Object.entries(ergebnis).filter(([, e]) => e.jsFehler?.length);
console.log(mitFehler.length ? `\n✗ JavaScript-Fehler auf ${mitFehler.length} Seite(n)` : '\n✓ Keine JavaScript-Fehler');
if (nichtGeladen) console.log(`✗ ${nichtGeladen} Seite(n) nicht geladen, Grund steht oben unter „nichtGeladen“`);

await chrome.beenden();
wiki.aufraeumen();
process.exit(mitFehler.length || nichtGeladen ? 1 : 0);
