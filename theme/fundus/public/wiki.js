// ============================================================================
// Fundus – Verhalten im Browser (Theme „fundus“ für BookStack)
// ============================================================================
//
// Die Datei wird nicht als eigene Datei geladen, sondern von fundus/kopf.blade.php
// direkt in jede Seite geschrieben (BookStack rät den MIME-Typ von Theme-Dateien falsch).
// Sie läuft also synchron im <head>, bevor der Seiteninhalt da ist.
//
// Grundsatz: BookStacks Elemente werden verschoben und umbeschriftet, nicht nachgebaut.
// Ereignis-Handler von BookStack bleiben dadurch erhalten. Ohne JavaScript bleibt jedes
// Element an seinem Originalplatz, das Wiki funktioniert weiter.
//
// Inhalt
//   1. Start .................. was beim Laden in welcher Reihenfolge passiert
//   2. Frühe Eingriffe ........ Editor, draw.io, Codeblöcke, Ladeskelett (vor DOMContentLoaded)
//   3. Seitenaufbau ........... Aktionsleiste, Übersichten, Kacheln, Symbole
//   4. Kopfleiste & Navigation  Seitenleisten-Knopf, Strg+K-Suche, Nach oben, Inhaltsverzeichnis
//   5. Artikel ................ Lesehilfen, Bild-Zoom, Codeblöcke, Rückmeldungen
//   6. Personen ............... Profilbilder/Initialen und Berufstitel neben Namen
//   7. KI-Chat Fundus ......... Chatfenster, Archiv, Antwort-Darstellung, Fundstelle
//   8. Hilfsfunktionen
//
// Namenskonvention: CSS-Klassen, data-Attribute und Speicher-Schlüssel beginnen mit
// „fundus-“ (Projektname), damit sie nie mit BookStack kollidieren.

// ============================================================================
// 1. Start
//    Alle Umbauten laufen nach DOMContentLoaded. Die Reihenfolge ist wichtig:
//    erst Elemente verschieben, dann Personen und Symbole schmücken, zuletzt die Dialoge.
// ============================================================================

document.addEventListener('DOMContentLoaded', () => {
  // „In diesem Artikel“ von links nach rechts, über die Details.
  const inhalt = document.getElementById('page-navigation');
  const rechts = document.querySelector('.tri-layout-right-contents');
  if (inhalt && rechts) {
    rechts.prepend(inhalt);
  }

  // Stand und Lesezeit direkt unter den Seitentitel.
  const kopf = document.querySelector('[data-fundus-artikelkopf]');
  const titel = document.getElementById('bkmrk-page-title');
  if (kopf && titel) {
    titel.after(kopf);
  }

  uebersichtEinsetzen();
  aktionsleiste();
  detailsNachRechts();
  listenSymbole();
  personenSchmuecken();
  rueckmeldungen();
  pruefungKnopf();
  gelesenMelden();
  lesehilfen();
  const kommentare = document.querySelector('.comments-container');
  if (kommentare) new MutationObserver(() => personenSchmuecken(kommentare)).observe(kommentare, { childList: true, subtree: true });
  nachObenKnopf();
  symboleEinsetzen();
  leistenKnopf();
  inhaltMitlaufen(inhalt);
  sucheStrgK();
  titelVorschau();
  suchseite();
  kiChat();
  kiFundstelleMarkieren();
  hinweise();
  const artikel = document.querySelector('.page-content');
  if (artikel) {
    codeBloeckeBeschriften();
    new MutationObserver(() => codeBloeckeBeschriften()).observe(artikel, { childList: true, subtree: true });
  }
  kiGespraecheAufraeumen();
});

// Umbau fertig: Platzhalter weg, echte Inhalte zeigen (Klasse setzt fundus/kopf). Auch nach einem Fehler
// oben, deshalb als eigener Listener, der nach dem ersten läuft.
document.addEventListener('DOMContentLoaded', () => {
  requestAnimationFrame(() => document.documentElement.classList.remove('fundus-laedt'));
});

// ============================================================================
// 2. Frühe Eingriffe
//    Diese Listener müssen stehen, bevor BookStack Editor, draw.io oder CodeMirror
//    startet. Deshalb direkt beim Ausführen der Datei, nicht erst bei DOMContentLoaded.
// ============================================================================

// Editor: dasselbe Stylesheet wie die Ansicht, damit Hinweiskästen und Zeichnungen beim
// Schreiben so aussehen wie später auf der Seite. Muss vor dem Start des Editors
// registriert sein, deshalb nicht erst bei DOMContentLoaded. (Dokumentiertes BookStack-Ereignis.)
window.addEventListener('editor-tinymce::pre-init', (event) => {
  const config = event.detail.config;
  const css = document.querySelector('link[href*="/theme/fundus/wiki.css"]')?.href;
  if (css) config.content_css = [...[].concat(config.content_css || []), css];
});

// draw.io: BookStack exportiert Zeichnungen ohne Rand, Linien am Bildrand werden dann
// halb abgeschnitten. Dieser Empfänger ist vor BookStacks eigenem registriert: Beim
// „Speichern“ fordert er den Export selbst an, mit Rand, und lässt BookStack den
// Speicher-Befehl nicht mehr sehen. Die Antwort („export“) verarbeitet BookStack wie immer.
window.addEventListener('message', (event) => {
  if (typeof event.data !== 'string') return;
  const rahmen = [...document.querySelectorAll('iframe')].find((f) => f.contentWindow === event.source);
  if (!rahmen || !rahmen.src.includes('embed=1')) return;
  let nachricht;
  try { nachricht = JSON.parse(event.data); } catch (e) { return; }
  if (nachricht.event !== 'save') return;
  event.stopImmediatePropagation();
  rahmen.contentWindow.postMessage(JSON.stringify({
    action: 'export', format: 'xmlpng', xml: nachricht.xml, spin: 'Zeichnung wird gespeichert', border: 10,
  }), event.origin);
});

// Codeblöcke, Schritt 1: BookStack ersetzt <pre> beim Laden durch CodeMirror und verliert dabei die Sprachangabe.
// Deshalb merken wir sie vorher in einem unsichtbaren Marker direkt vor dem Block: CodeMirror setzt
// seine Hülle genau an die Stelle des <pre>, also direkt hinter den Marker.
document.addEventListener('readystatechange', () => {
  if (document.readyState !== 'interactive') return;
  document.querySelectorAll('.page-content pre').forEach((pre) => {
    if (pre.previousElementSibling?.matches('[data-fundus-code-sprache]')) return;
    const kurz = (pre.querySelector('code[class^="language-"]')?.className.match(/language-([\w#+-]+)/) || [])[1] || '';
    const marker = document.createElement('span');
    marker.hidden = true;
    marker.dataset.fundusCodeSprache = kurz;
    pre.before(marker);
  });
});

// Dasselbe dunkle Farbschema wie im Chat, aber nur für Codeblöcke im Artikel –
// Editoren und Einstellungen behalten BookStacks helles Schema (dokumentiertes BookStack-Ereignis).
const CODE_FARBEN = {
  grund: '#1B1B20', text: '#E6E6EA', grau: '#8B8B96', schluessel: '#8FCBA2', zeichenkette: '#F2C98B',
  zahl: '#F4A09A', funktion: '#9CC3F5', typ: '#7FD6C2', variable: '#E0B8F0', auswahl: '#33333D',
};
window.addEventListener('library-cm6::configure-theme', (event) => {
  if (!event.target.closest?.('.page-content') || event.detail.darkModeActive) return;
  const f = CODE_FARBEN;
  event.detail.registerViewTheme(() => ({
    '&': { color: f.text, backgroundColor: f.grund },
    '.cm-content': { caretColor: f.text },
    '.cm-gutters': { backgroundColor: f.grund, color: '#5C5C66', border: 'none' },
    '&.cm-focused .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': { backgroundColor: f.auswahl },
    '.cm-activeLine, .cm-activeLineGutter': { backgroundColor: 'transparent' },
  }));
  event.detail.registerHighlightStyle((t) => [
    { tag: [t.keyword, t.controlKeyword, t.moduleKeyword, t.operatorKeyword], color: f.schluessel },
    { tag: [t.string, t.special(t.string), t.regexp], color: f.zeichenkette },
    { tag: [t.number, t.bool, t.atom, t.null], color: f.zahl },
    // Ältere Sprachmodi (z. B. PowerShell) liefern „builtin“/„def“ statt Funktionsnamen.
    { tag: [t.function(t.variableName), t.function(t.propertyName), t.standard(t.variableName), t.definition(t.variableName), t.macroName], color: f.funktion },
    { tag: [t.standard(t.name), t.constant(t.name), t.labelName], color: f.typ },
    { tag: [t.typeName, t.className, t.namespace], color: f.typ },
    { tag: [t.variableName, t.special(t.variableName), t.self], color: f.variable },
    { tag: [t.propertyName, t.attributeName, t.tagName], color: f.funktion },
    { tag: [t.comment, t.lineComment, t.blockComment], color: f.grau, fontStyle: 'italic' },
    { tag: [t.operator, t.punctuation, t.bracket], color: '#C8C8D0' },
    { tag: [t.meta, t.processingInstruction], color: f.grau },
  ]);
});

// Skeleton beim Seitenwechsel: BookStack rendert jede Seite auf dem Server; bis die Antwort da ist,
// stünde die alte Seite unverändert da. Nach einem Klick auf einen normalen Link (oder dem Absenden
// eines Formulars) zeigt wiki.css deshalb kurz ein Skelett (html.fundus-navigiert). Kurze Verzögerung,
// damit schnelle Wechsel nicht flackern; Downloads und neue Tabs verlassen die Seite nicht.
(function ladeSkelett() {
  const html = document.documentElement;
  let zeitgeber = null;
  const beenden = () => { clearTimeout(zeitgeber); html.classList.remove('fundus-navigiert'); };
  const starten = () => {
    clearTimeout(zeitgeber);
    zeitgeber = setTimeout(() => {
      html.classList.add('fundus-navigiert');
      // Falls doch keine neue Seite kommt (Abbruch, Datei-Antwort), nicht ewig im Skelett hängen.
      zeitgeber = setTimeout(beenden, 10000);
    }, 120);
  };
  // Bubbling auf window: läuft nach BookStacks eigenen Handlern, die z. B. Menü-Links mit preventDefault abfangen.
  window.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest?.('a[href]');
    if (!a || a.hasAttribute('download') || (a.target && a.target !== '_self')) return;
    const ziel = new URL(a.href, location.href);
    if (ziel.origin !== location.origin) return;
    if (ziel.pathname === location.pathname && ziel.search === location.search) return; // Sprungmarke auf derselben Seite
    if (/\/(export|attachments)(\/|$)/.test(ziel.pathname) || a.getAttribute('href') === '#') return;
    starten();
  });
  window.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || (form.target && form.target !== '_self')) return;
    starten();
  });
  // Zurück-Taste aus dem Browser-Cache: die Seite kommt mit gesetzter Klasse zurück.
  window.addEventListener('pageshow', beenden);
})();

// ============================================================================
// 3. Seitenaufbau
//    Layout nach dem Vorbild von Microsoft Learn: Aktionen über dem Inhalt,
//    Kennzahlen unter dem Titel, Details rechts, Symbole aus dem Schlagwort „Symbol“.
// ============================================================================

// Aktionen (Bearbeiten, Versionen …) aus der rechten Leiste in eine Leiste über dem Inhalt,
// neben den Pfad. Häufiges steht direkt da, Seltenes (Kopieren, Verschieben, Rechte, Löschen)
// im Menü „Mehr“. Gilt für Artikel, Abschnitte, Themen und Bereiche; Übersichtslisten haben
// keinen Pfad und bleiben unverändert.
function aktionsleiste() {
  // Rechts zuerst: In der Themenansicht trägt links auch „Bereiche“ die Klasse actions.
  const box = document.querySelector('.tri-layout-right-contents > .actions') || document.querySelector('.tri-layout-left-contents > .actions:has(> .icon-list)');
  const liste = box?.querySelector(':scope > .icon-list');
  const mitte = document.querySelector('.tri-layout-middle-contents');
  let krumen = mitte?.querySelector('.breadcrumbs');
  const listenKarte = mitte?.querySelector(':scope > main.content-wrap h1.list-heading');
  // Die Startseite (Baustein fundus/start) blendet BookStacks Liste samt Aktionen bewusst aus.
  if (!liste || (!krumen && !listenKarte) || document.body.classList.contains('fundus-start')) return;

  const leiste = document.createElement('div');
  leiste.className = 'fundus-aktionsleiste print-hidden';
  if (krumen) {
    const krumenHuelle = krumen.closest('.tri-layout-middle-contents > *');
    krumenHuelle.replaceWith(leiste);
    leiste.append(krumenHuelle);
    // BookStack beginnt den Pfad mit den Listen aller Bereiche und aller Themen („Bereiche ›
    // Ausbildung … › Themen › So funktioniert das Wiki › …“). Beide stehen schon in der
    // Kopfleiste; im Pfad kosten sie nur den Platz, den tiefe Pfade nicht haben.
    const wurzel = (document.querySelector('meta[name="base-url"]')?.content || '').replace(/\/$/, '');
    for (const punkt of [...krumen.querySelectorAll(':scope > a.icon-list-item')]) {
      const ziel = punkt.getAttribute('href');
      if (ziel !== `${wurzel}/shelves` && ziel !== `${wurzel}/books`) continue;
      // Der Trenner dahinter ist ein .separator oder, wo man zu Nachbarn springen kann, ein
      // .dropdown-search mit Pfeil; blieb er stehen, begann der Pfad mit einem einsamen „›“.
      if (punkt.nextElementSibling?.matches('.separator, .dropdown-search')) punkt.nextElementSibling.remove();
      punkt.remove();
    }
  } else {
    // Listen aller Themen oder Bereiche haben keinen Pfad; ein kurzer „Start › Themen“ hält die Leiste gleich.
    const basis = document.querySelector('meta[name="base-url"]')?.content || '';
    krumen = document.createElement('nav');
    krumen.className = 'breadcrumbs text-center';
    krumen.setAttribute('aria-label', 'Pfad');
    krumen.innerHTML = `<a href="${kiEscape(basis)}/" class="icon-list-item"><span></span><span>Start</span></a>`
      + '<div class="separator" aria-hidden="true">›</div>'
      + `<div class="icon-list-item" aria-current="page"><span></span><span>${kiEscape(listenKarte.textContent.trim())}</span></div>`;
    const huelle = document.createElement('div');
    huelle.append(krumen);
    leiste.append(huelle);
    mitte.prepend(leiste);
  }

  const aktionen = document.createElement('div');
  aktionen.className = 'fundus-aktionen';
  aktionen.setAttribute('role', 'toolbar');
  aktionen.setAttribute('aria-label', box.querySelector('h5')?.textContent.trim() || 'Aktionen');

  const mehr = document.createElement('div');
  mehr.className = 'fundus-mehr-menue';
  mehr.innerHTML = '<button type="button" class="fundus-aktion nur-symbol" aria-haspopup="true" aria-expanded="false" title="Weitere Aktionen" aria-label="Weitere Aktionen">'
    + '<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg></button>'
    + '<div class="fundus-mehr-liste" role="menu" hidden></div>';
  const mehrKnopf = mehr.querySelector('button');
  const mehrListe = mehr.querySelector('.fundus-mehr-liste');

  // BookStacks gefüllte Symbole durch Linien-Symbole wie im restlichen Theme ersetzen.
  const linienSymbol = (el) => {
    const alt = el.querySelector('svg[data-icon]');
    const pfad = alt && AKTION_SYMBOLE[alt.dataset.icon];
    if (!pfad) return;
    const gefuellt = alt.dataset.icon === 'star';
    alt.outerHTML = `<svg class="svg-icon" data-icon="${alt.dataset.icon}" viewBox="0 0 24 24" aria-hidden="true" fill="${gefuellt ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">${pfad}</svg>`;
  };
  const beschriften = (el) => {
    const text = [...el.querySelectorAll(':scope > span')].pop()?.textContent.trim();
    if (text) { el.title = text; el.setAttribute('aria-label', text); }
  };
  const beschriftet = [];
  const symbole = [];
  for (const kind of [...liste.children]) {
    if (kind.matches('hr')) continue;
    const eintrag = kind.matches('.icon-list-item') ? kind : kind.querySelector('.icon-list-item');
    if (!eintrag) continue;
    const kurz = eintrag.dataset.shortcut || '';
    eintrag.classList.add('fundus-aktion');
    linienSymbol(eintrag);
    if (kurz === 'new') {
      beschriftet.push(kind);
    } else if (kurz === 'edit' || kurz === 'revisions' || kind.matches('form, .dropdown-container') || kind.querySelector(':scope > form')) {
      // Bearbeiten (nur der Stift, der Name steht im Titel), Versionen, Beobachten, Favorit,
      // Ansicht wechseln (Formular in einem div) und Exportieren
      eintrag.classList.add('nur-symbol');
      beschriften(eintrag);
      symbole.push(kind.querySelector(':scope > form') || kind);
    } else {
      eintrag.setAttribute('role', 'menuitem');
      if (kurz === 'delete') eintrag.classList.add('gefahr');
      mehrListe.append(kind);
    }
  }
  // Beschriftet bleibt nur das Anlegen („Neuer Artikel“, „Neues Thema“); der erste davon ist die
  // Hauptaktion. In der Artikelansicht bleibt damit nur die Pille übrig.
  beschriftet[0]?.classList.add('haupt');
  // Symbolknöpfe und „Mehr“ sitzen zusammen in einer Pille, statt als lose Reihe von Kreisen.
  const gruppe = document.createElement('div');
  gruppe.className = 'fundus-aktionen-gruppe';
  gruppe.append(...symbole);
  if (mehrListe.children.length) gruppe.append(mehr);
  aktionen.append(...beschriftet);
  if (gruppe.children.length) aktionen.append(gruppe);
  leiste.append(aktionen);
  box.remove();

  const zu = () => { mehrListe.hidden = true; mehrKnopf.setAttribute('aria-expanded', 'false'); };
  mehrKnopf.addEventListener('click', (e) => {
    e.stopPropagation();
    const auf = mehrListe.hidden;
    mehrListe.hidden = !auf;
    mehrKnopf.setAttribute('aria-expanded', String(auf));
    if (auf) mehrListe.querySelector('a, button')?.focus();
  });
  document.addEventListener('click', (e) => { if (!mehr.contains(e.target)) zu(); });
  mehr.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !mehrListe.hidden) { zu(); mehrKnopf.focus(); }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      const punkte = [...mehrListe.querySelectorAll('a, button')];
      if (!punkte.length || mehrListe.hidden) return;
      e.preventDefault();
      const i = punkte.indexOf(document.activeElement);
      punkte[(i + (e.key === 'ArrowDown' ? 1 : -1) + punkte.length) % punkte.length].focus();
    }
  });
  mehr.addEventListener('focusout', (e) => { if (!mehr.contains(e.relatedTarget)) zu(); });
}

// Lucide-Linien (ISC-Lizenz, wie theme/fundus/symbole) je BookStack-Symbolname.
const AKTION_SYMBOLE = {
  edit: '<path d="M12 20h9"/><path d="M16.4 3.6a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4z"/>',
  add: '<path d="M12 5v14M5 12h14"/>',
  history: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
  watch: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  star: '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/>',
  'star-outline': '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/>',
  export: '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
  copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
  folder: '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9l-.8-1.2A2 2 0 0 0 7.9 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"/><path d="m12 10 3 3-3 3M8 13h7"/>',
  lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  delete: '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/>',
  sort: '<path d="m3 16 4 4 4-4M7 20V4M21 8l-4-4-4 4M17 4v16"/>',
  grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
  list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
};

// Kennzahlen eines Themas oder Bereichs (Baustein fundus/uebersicht) unter Titel und Beschreibung;
// das Symbol kommt vor den Titel.
function uebersichtEinsetzen() {
  // Listen aller Themen/Bereiche: Kennzahlen unter die Kopfzeile mit Titel und Sortierung.
  const listenKopf = document.querySelector('[data-fundus-liste-kopf]');
  const listenTitel = document.querySelector('.tri-layout-middle-contents > main.content-wrap > .grid.half:has(> h1.list-heading)');
  if (listenKopf && listenTitel) listenTitel.after(listenKopf);

  const block = document.querySelector('[data-fundus-uebersicht]');
  const karte = document.querySelector('.tri-layout-middle-contents > main.content-wrap');
  if (!block || !karte) return;
  const titel = karte.querySelector('h1');
  const beschreibung = karte.querySelector('.book-content > .text-muted');
  const symbol = block.querySelector('[data-fundus-uebersicht-symbol]');
  if (titel && symbol) {
    titel.classList.add('fundus-uebersicht-titel');
    titel.prepend(symbol);
  }
  if (beschreibung) beschreibung.after(block); else titel?.after(block);
}

// Bereichsansicht: BookStack stellt „Details“ links auf; wie bei Artikeln und Themen nach rechts.
function detailsNachRechts() {
  const details = document.querySelector('.tri-layout-left-contents > #details');
  const rechts = document.querySelector('.tri-layout-right-contents');
  if (details && rechts) rechts.prepend(details);
}

// Symbole aus dem Schlagwort „Symbol“ (Baustein fundus/symbol) in die Kachel setzen.
function symboleEinsetzen() {
  for (const vorlage of document.querySelectorAll('template[data-fundus-symbol]')) {
    const karte = vorlage.previousElementSibling;
    const alt = karte?.querySelector('.featured-image-container-wrap .svg-icon');
    const svg = vorlage.content.querySelector('svg');
    if (alt && svg) {
      svg.classList.add('svg-icon');
      svg.setAttribute('aria-hidden', 'true');
      alt.replaceWith(svg);
    }
    const meta = vorlage.content.querySelector('.fundus-karte-meta');
    if (meta) karte?.querySelector('.grid-card-content')?.append(meta);
    vorlage.remove();
  }
}

// Kompakte Listen in den Seitenleisten (Kürzlich angesehen, Beliebt, Bereiche eines Themas):
// Standardsymbole durch das Symbol des Themas oder Bereichs ersetzen (fundus/symbole-json).
function listenSymbole() {
  let daten;
  try { daten = JSON.parse(document.getElementById('fundus-symbole')?.textContent || 'null'); } catch (e) { return; }
  if (!daten) return;
  document.querySelectorAll('.tri-layout-left-contents .entity-list-item[data-entity-type][data-entity-id]').forEach((eintrag) => {
    const name = daten.zu[`${eintrag.dataset.entityType}:${eintrag.dataset.entityId}`];
    const svg = name && daten.svg[name];
    const icon = eintrag.querySelector(':scope > .icon');
    if (!svg || !icon) return;
    icon.innerHTML = svg;
    icon.querySelector('svg')?.classList.add('svg-icon', 'fundus-linie');
    icon.querySelector('svg')?.setAttribute('aria-hidden', 'true');
  });
}

// ============================================================================
// 4. Kopfleiste & Navigation
// ============================================================================

// Ein Knopf in der Kopfleiste schaltet den Zen-Modus ein: nur der Artikel auf Weiß (wiki.css „Zen-Modus“).
// Die Kopfleiste samt Knopf verschwindet dabei; zurück geht es mit Esc oder dem „Esc“ oben rechts.
// Die Klasse steht auf <html>; beim Laden setzt sie schon der Baustein fundus/kopf, damit nichts aufblitzt.
function leistenKnopf() {
  const knopf = document.querySelector('[data-fundus-leisten-knopf]');
  if (!knopf) return;
  const html = document.documentElement;

  const raus = document.createElement('button');
  raus.type = 'button';
  raus.className = 'fundus-zen-raus print-hidden';
  raus.innerHTML = '<span class="text">Zen beenden</span><kbd>Esc</kbd>';
  raus.setAttribute('aria-label', 'Zen-Modus beenden (Esc)');
  document.body.append(raus);

  const schalten = (an) => {
    html.classList.toggle('fundus-zen', an);
    merken('fundus-zen', an ? '1' : '0');
    knopf.setAttribute('aria-pressed', String(an));
  };
  knopf.title = 'Zen-Modus: nur der Artikel (zurück mit Esc)';
  knopf.setAttribute('aria-label', 'Zen-Modus: nur der Artikel');
  knopf.setAttribute('aria-pressed', String(html.classList.contains('fundus-zen')));

  knopf.addEventListener('click', () => schalten(true));
  raus.addEventListener('click', () => schalten(false));
  // Esc gehört zuerst offenen Dialogen (Strg+K, Bild-Zoom), Menüs und Eingabefeldern.
  // Nur ein „freies“ Esc beendet den Zen-Modus.
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || e.defaultPrevented || !html.classList.contains('fundus-zen')) return;
    if (document.querySelector('dialog[open], .dropdown-menu[style*="block"]')) return;
    if (e.target.closest?.('input, textarea, [contenteditable="true"]')) return;
    schalten(false);
  });
}

// Suchdialog auf Strg+K / Cmd+K. Die Treffer kommen von BookStacks Suchergebnisseite:
// Nur dort liefert BookStack Textausschnitte, in denen der Suchbegriff markiert ist.
// Suchergebnisse (/search): Unter der Überschrift steht lesbar, wonach gesucht wird, statt der Filtersprache
// „{created_by:admin} {type:page}“. BookStack gibt die ganze Anfrage im Suchfeld der Ergebniskarte mit.
function suchseite() {
  const system = document.getElementById('search-system');
  const titel = system?.querySelector('h1.list-heading');
  const anfrage = system?.querySelector('.search-box input[name="term"]')?.value;
  if (!titel || !anfrage) return;

  const personen = personenLesen()?.personen || {};
  const person = (slug) => (slug === 'me' ? 'dir' : personen[slug]?.name || slug);
  const datum = (d) => (/^\d{4}-\d{2}-\d{2}$/.test(d) ? d.split('-').reverse().join('.') : d);
  const TYPEN = { page: 'Artikel', chapter: 'Abschnitte', book: 'Themen', bookshelf: 'Bereiche' };
  const teile = [];
  let art = '';
  const dazu = (text, wert) => teile.push(wert === undefined ? kiEscape(text) : `${kiEscape(text)} <b>${kiEscape(wert)}</b>`);

  let rest = anfrage.replace(/\{([a-z_]+)(?::([^}]*))?\}/g, (_, name, wert = '') => {
    if (name === 'type') {
      const namen = wert.split('|').map((t) => TYPEN[t] || t);
      art = `<b>${kiEscape(namen.join(' und '))}</b>`;
    } else if (name === 'created_by') dazu('angelegt von', person(wert));
    else if (name === 'updated_by') dazu('zuletzt bearbeitet von', person(wert));
    else if (name === 'owned_by') dazu('im Besitz von', person(wert));
    else if (name === 'viewed_by_me') dazu('von dir gelesen');
    else if (name === 'not_viewed_by_me') dazu('von dir noch nicht gelesen');
    else if (name === 'is_restricted') dazu('mit eigenen Rechten');
    else if (name === 'created_after') dazu('angelegt nach', datum(wert));
    else if (name === 'created_before') dazu('angelegt vor', datum(wert));
    else if (name === 'updated_after') dazu('bearbeitet nach', datum(wert));
    else if (name === 'updated_before') dazu('bearbeitet vor', datum(wert));
    else if (name === 'in_name') dazu('im Titel', wert);
    else if (name === 'in_body') dazu('im Text', wert);
    else if (name !== 'sort_by') dazu(name, wert);
    return ' ';
  });
  rest = rest.replace(/\[([^\]=]+)(?:=([^\]]*))?\]/g, (_, name, wert) => {
    dazu('Schlagwort', wert ? `${name} = ${wert}` : name);
    return ' ';
  });
  rest = rest.replace(/"([^"]+)"/g, (_, genau) => { dazu('genau', `„${genau}“`); return ' '; });
  const woerter = rest.replace(/\s+/g, ' ').trim();
  // Reihenfolge: erst die Art, dann der Suchbegriff, dann die übrigen Filter.
  if (woerter) teile.unshift(`<b>„${kiEscape(woerter)}“</b>`);
  if (art) teile.unshift(art);
  if (!teile.length) return;

  const zeile = document.createElement('p');
  zeile.className = 'fundus-suche-worte';
  zeile.innerHTML = teile.map((t) => `<span>${t}</span>`).join('');
  titel.after(zeile);
}

// Berufstitel im Formular einer Person (Baustein fundus/titel-feld): Vorschau der Plakette beim Tippen.
function titelVorschau() {
  const feld = document.querySelector('[data-fundus-titel-feld]');
  if (!feld) return;
  const text = feld.querySelector('input');
  const stufe = feld.querySelector('select');
  const vorschau = feld.querySelector('[data-fundus-titel-vorschau]');
  const STUFEN = { leitung: 'Leitung', senior: 'Senior', junior: 'Junior', azubi: 'Azubi', team: 'Team' };
  // Immer sichtbar, damit auch die Auswahl der Stufe sofort etwas zeigt: ohne Titel blass „Dein Titel“.
  const zeigen = () => {
    const titel = text.value.trim();
    const abgeleitet = stufe.options[0].dataset.abgeleitet || 'team';
    stufe.options[0].textContent = `Automatisch aus dem Titel (${STUFEN[abgeleitet]})`;
    vorschau.hidden = false;
    vorschau.textContent = titel || 'Dein Titel';
    vorschau.className = `fundus-titel stufe-${stufe.value || abgeleitet}${titel ? '' : ' leer'}`;
  };
  // „Automatisch“ zeigt die Farbe, die der Server aus dem Titel ableiten würde (Anmeldung::stufeAusTitel).
  const ableiten = (t) => {
    t = t.toLowerCase();
    if (/\b(ceo|cto|cfo|coo)\b|geschäftsführ|vorstand|inhaber|bereichsleit|head of/.test(t)) return 'leitung';
    if (/senior|teamleit|team lead|principal|lead\b/.test(t)) return 'senior';
    if (/auszubild|azubi|werkstudent|praktikant|trainee/.test(t)) return 'azubi';
    if (/consultant|berater|engineer|entwickler|administrator|techniker/.test(t)) return 'junior';
    return 'team';
  };
  const aktualisieren = () => { stufe.options[0].dataset.abgeleitet = ableiten(text.value); zeigen(); };
  text.addEventListener('input', aktualisieren);
  stufe.addEventListener('change', zeigen);
  aktualisieren();
}

function sucheStrgK() {
  const dialog = document.querySelector('[data-fundus-suche]');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  const feld = dialog.querySelector('input[name="term"]');
  const liste = dialog.querySelector('.fundus-suche-treffer');
  const basis = document.querySelector('meta[name="base-url"]')?.content || '';
  let zeitgeber = null;
  let anfrage = 0;
  let auswahl = -1;

  const treffer = () => [...liste.querySelectorAll('a.entity-list-item')];
  const markieren = (index) => {
    const alle = treffer();
    auswahl = alle.length ? (index + alle.length) % alle.length : -1;
    alle.forEach((a, i) => a.classList.toggle('fundus-gewaehlt', i === auswahl));
    alle[auswahl]?.scrollIntoView({ block: 'nearest' });
  };
  const hinweis = (text) => {
    liste.replaceChildren(Object.assign(document.createElement('p'), { className: 'fundus-suche-hinweis', textContent: text }));
    auswahl = -1;
  };

  const suchen = async () => {
    const begriff = feld.value.trim();
    if (begriff.length < 2) {
      hinweis('Mindestens zwei Zeichen eingeben.');
      return;
    }
    const nummer = ++anfrage;
    liste.classList.add('fundus-laedt');
    try {
      const antwort = await fetch(`${basis}/search?term=${encodeURIComponent(begriff)}`, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (nummer !== anfrage) return; // Eine neuere Eingabe ist schon unterwegs.
      // Die Antwort ist von BookStack gerendertes und maskiertes HTML derselben Seite.
      const html = new DOMParser().parseFromString(await antwort.text(), 'text/html');
      const eintraege = [...html.querySelectorAll('.book-contents a.entity-list-item')].slice(0, 8);
      // Schlagwörter und Seitennavigation der Ergebnisseite braucht der Dialog nicht.
      eintraege.forEach((a) => a.querySelectorAll('.entity-item-tags').forEach((t) => t.remove()));
      if (!eintraege.length) {
        hinweis(`Nichts gefunden zu „${begriff}“.`);
      } else {
        liste.replaceChildren(...eintraege);
        markieren(0);
      }
    } catch (e) {
      hinweis('Die Suche ist gerade nicht erreichbar.');
    } finally {
      if (nummer === anfrage) liste.classList.remove('fundus-laedt');
    }
  };

  const oeffnen = () => {
    if (dialog.open) return;
    dialog.showModal();
    feld.select();
    if (!feld.value) hinweis('Artikel, Themen und Abschnitte, die du lesen darfst.');
  };

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      dialog.open ? dialog.close() : oeffnen();
    }
  });

  // Das große Suchfeld der Startseite öffnet denselben Dialog.
  for (const knopf of document.querySelectorAll('[data-fundus-suche-oeffnen]')) {
    knopf.addEventListener('click', oeffnen);
  }
  // Neben offenem Chat ist das Suchfeld der Kopfleiste nur Lupe und „Strg K“ (wiki.css): Klick öffnet den Dialog.
  document.querySelector('header .search-box input')?.addEventListener('pointerdown', (e) => {
    if (!document.documentElement.classList.contains('fundus-ki-offen')) return;
    e.preventDefault();
    oeffnen();
  });

  feld.addEventListener('input', () => {
    clearTimeout(zeitgeber);
    zeitgeber = setTimeout(suchen, 180);
  });

  feld.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      markieren(auswahl + (e.key === 'ArrowDown' ? 1 : -1));
    } else if (e.key === 'Enter' && auswahl >= 0) {
      // Ohne Auswahl schickt das Formular zur vollständigen Suchseite.
      e.preventDefault();
      treffer()[auswahl].click();
    } else if (e.key === 'Escape') {
      // Ein Suchfeld leert bei Esc sonst erst den Text; hier soll Esc sofort schließen.
      e.preventDefault();
      dialog.close();
    }
  });

  // Klick auf den abgedunkelten Hintergrund schließt.
  dialog.addEventListener('click', (e) => {
    if (e.target === dialog) dialog.close();
  });
}

// BookStacks Nach-oben-Knopf misst die Kopfleiste, um die Scrollweite zu bestimmen. Seit die
// Kopfleiste klebt, liegt sie immer bei 0: Die Seite springt dann ohne Animation, auf schmalen
// Bildschirmen (Kopfleiste nicht klebend) teils gar nicht ganz nach oben. Hier sanft und immer ganz.
function nachObenKnopf() {
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.back-to-top')) return;
    e.stopPropagation();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }, true);
}

// Bei langen Seiten bleibt der aktuelle Abschnitt in „In diesem Artikel“ sichtbar.
// BookStack setzt die Klasse current-heading; wir scrollen nur die Leiste, nie die Seite.
function inhaltMitlaufen(nav) {
  if (!nav) return;

  let geplant = false;
  new MutationObserver(() => {
    if (geplant) return;
    geplant = true;
    requestAnimationFrame(() => {
      geplant = false;
      // Je nach Breite scrollt die rechte Spalte selbst oder die gemeinsame Seitenspalte.
      const leiste = scrollbarerVorfahr(nav);
      const aktuell = nav.querySelector('.current-heading');
      if (!leiste || !aktuell) return;
      const oben = aktuell.getBoundingClientRect().top - leiste.getBoundingClientRect().top;
      const unten = oben + aktuell.offsetHeight;
      if (oben < 40 || unten > leiste.clientHeight - 40) {
        leiste.scrollTop += oben - leiste.clientHeight / 3;
      }
    });
  }).observe(nav, { subtree: true, attributes: true, attributeFilter: ['class'] });
}

function scrollbarerVorfahr(el) {
  for (let e = el.parentElement; e && e !== document.body; e = e.parentElement) {
    const y = getComputedStyle(e).overflowY;
    if ((y === 'auto' || y === 'scroll') && e.scrollHeight > e.clientHeight) return e;
  }
  return null;
}

// ============================================================================
// 5. Artikel
// ============================================================================

// Lesehilfen im Artikel:
// Tastenkürzel („Strg + K“) als Tastenkappen, Menüpfade („Einstellungen → Konten“) als Pfad,
// Bilder mit Bildunterschrift aus dem Alternativtext und Vergrößern per Klick.
// Nur in der Ansicht; im Editor bleibt der Text, wie er geschrieben wurde.
const TASTEN = /^(Strg|Ctrl|Cmd|Alt|AltGr|Umschalt|Shift|Windows|Win|Enter|Eingabe|Esc|Tab|Entf|Rücktaste|Leertaste|F\d{1,2}|Pfeiltasten|[A-Z0-9])$/;
function lesehilfen() {
  const artikel = document.querySelector('.page-content');
  if (!artikel || document.body.classList.contains('flexbox')) return;

  artikel.querySelectorAll('strong, b').forEach((el) => {
    if (el.children.length || el.closest('pre, code, a, table th')) return;
    const text = el.textContent.trim();
    const teile = text.split(/\s*\+\s*/);
    if (teile.length && teile.every((t) => TASTEN.test(t)) && (teile.length > 1 || /^(Enter|Esc|Tab|Entf|Strg|Umschalt)$/.test(text))) {
      const tasten = document.createElement('span');
      tasten.className = 'fundus-tasten';
      teile.forEach((t, i) => {
        if (i) tasten.append(Object.assign(document.createElement('span'), { className: 'plus', textContent: '+' }));
        tasten.append(Object.assign(document.createElement('kbd'), { textContent: t }));
      });
      el.replaceWith(tasten);
    } else if (/\s→\s/.test(text)) {
      const pfad = document.createElement('span');
      pfad.className = 'fundus-pfad';
      text.split(/\s*→\s*/).forEach((t, i) => {
        if (i) pfad.append(Object.assign(document.createElement('span'), { className: 'pfeil', textContent: '›', ariaHidden: 'true' }));
        pfad.append(Object.assign(document.createElement('span'), { className: 'schritt', textContent: t }));
      });
      el.replaceWith(pfad);
    }
  });

  // Bilder: Unterschrift aus dem Alternativtext (nicht bei Dateinamen), Klick öffnet groß.
  artikel.querySelectorAll('img').forEach((img) => {
    // Nur Bilder aus dem Artikeltext: Profilbilder im Artikelkopf und Rückmeldungen nicht anfassen.
    if (img.matches('.fundus-avatar, .avatar') || img.closest('a, [drawio-diagram], .fundus-bild, [data-fundus-artikelkopf], .fundus-person')) return;
    const absatz = img.parentElement;
    const alleinImAbsatz = absatz?.matches('p') && absatz.textContent.trim() === '' && absatz.querySelectorAll('img').length === 1;
    const alt = (img.getAttribute('alt') || '').trim();
    const figur = document.createElement('figure');
    figur.className = 'fundus-bild';
    // Die Absatz-Kennung (bkmrk-…) behalten: Direktlinks und Fundstellen von Fundus zeigen darauf.
    if (alleinImAbsatz && absatz.id) figur.id = absatz.id;
    (alleinImAbsatz ? absatz : img).replaceWith(figur);
    const knopf = document.createElement('button');
    knopf.type = 'button';
    knopf.className = 'fundus-bild-knopf';
    knopf.setAttribute('aria-label', 'Bild vergrößern');
    knopf.append(img);
    figur.append(knopf);
    if (alt && !/\.(png|jpe?g|gif|webp)$/i.test(alt) && !/^(image|bild|screenshot)[\s_-]*\d*$/i.test(alt)) {
      // „…, nummeriert 1 bis 6“ ist für Screenreader gedacht; als Bildunterschrift reicht die Beschreibung.
      const unterschrift = alt.replace(/,\s*(nummeriert|markiert mit)\s[^,]*$/i, '');
      figur.append(Object.assign(document.createElement('figcaption'), { textContent: unterschrift }));
    }
    knopf.addEventListener('click', () => bildGross(img));
  });

  ueberschriftenVerlinken(artikel);
}

// Direktlink zu einem Abschnitt, wie bei Microsoft Learn: Hinter jeder Überschrift ein Link-Symbol,
// ein Klick kopiert die Adresse mit #Kennung und setzt sie in die Adresszeile. Die Kennungen (bkmrk-…)
// vergibt BookStack beim Speichern; sie bleiben gleich, solange die Überschrift nicht neu angelegt wird.
const LINK_SYMBOL = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>';
function ueberschriftenVerlinken(artikel) {
  artikel.querySelectorAll(':is(h2, h3, h4)[id]').forEach((ueberschrift) => {
    if (ueberschrift.querySelector('.fundus-anker')) return;
    const titel = ueberschrift.textContent.trim();
    const knopf = document.createElement('button');
    knopf.type = 'button';
    knopf.className = 'fundus-anker';
    knopf.innerHTML = LINK_SYMBOL;
    knopf.title = 'Link zu diesem Abschnitt kopieren';
    knopf.setAttribute('aria-label', `Link zu „${titel}“ kopieren`);
    knopf.addEventListener('click', async () => {
      const adresse = `${location.origin}${location.pathname}#${ueberschrift.id}`;
      history.replaceState(null, '', `#${ueberschrift.id}`);
      const kopiert = await textKopieren(adresse);
      knopf.dataset.meldung = kopiert ? 'Link kopiert' : 'Link steht in der Adresszeile';
      knopf.classList.add('gemeldet');
      clearTimeout(knopf.wecker);
      knopf.wecker = setTimeout(() => knopf.classList.remove('gemeldet'), 1800);
    });
    ueberschrift.append(knopf);
  });
}

// Zwischenablage; ohne HTTPS (http im Hausnetz) gibt es navigator.clipboard nicht, dann der alte Weg.
async function textKopieren(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch (e) {
    const feld = Object.assign(document.createElement('textarea'), { value: text });
    feld.style.cssText = 'position:fixed;opacity:0';
    document.body.append(feld);
    feld.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e2) { /* nicht unterstützt */ }
    feld.remove();
    return ok;
  }
}

function bildGross(img) {
  const dialog = document.createElement('dialog');
  dialog.className = 'fundus-bild-gross';
  const gross = img.cloneNode();
  // Hochgeladene Bilder zeigt BookStack als Vorschau (/thumbs-…/); groß lieber das Original.
  gross.src = (img.currentSrc || img.src).replace(/\/thumbs-\d+-\d+\//, '/');
  const zu = Object.assign(document.createElement('button'), { type: 'button', className: 'zu', textContent: '×' });
  zu.setAttribute('aria-label', 'Schließen');
  dialog.append(zu, gross);
  if (img.alt) dialog.append(Object.assign(document.createElement('p'), { textContent: img.alt }));
  document.body.append(dialog);
  dialog.showModal();
  const schliessen = () => { dialog.close(); dialog.remove(); };
  dialog.addEventListener('click', (e) => { if (e.target === dialog || e.target === zu) schliessen(); });
  dialog.addEventListener('cancel', (e) => { e.preventDefault(); schliessen(); });
}

// Codeblöcke, Schritt 2: Sprache aus dem Marker als Kopfzeile an die CodeMirror-Hülle schreiben.
function codeBloeckeBeschriften(wurzel = document) {
  wurzel.querySelectorAll('.page-content .cm-editor').forEach((editor) => {
    const huelle = editor.parentElement;
    if (!huelle || huelle.classList.contains('fundus-code')) return;
    const marker = huelle.previousElementSibling;
    huelle.classList.add('fundus-code');
    huelle.dataset.sprache = codeSprache(marker?.matches('[data-fundus-code-sprache]') ? marker.dataset.fundusCodeSprache : '');
  });
}

const CODE_SPRACHEN = {
  powershell: 'PowerShell', ps1: 'PowerShell', ps: 'PowerShell', bash: 'Bash', sh: 'Bash', shell: 'Shell', zsh: 'Shell',
  cmd: 'CMD', bat: 'CMD', dos: 'CMD', json: 'JSON', yaml: 'YAML', yml: 'YAML', xml: 'XML', html: 'HTML',
  sql: 'SQL', ini: 'INI', javascript: 'JavaScript', js: 'JavaScript', typescript: 'TypeScript', ts: 'TypeScript',
  python: 'Python', py: 'Python', php: 'PHP', csharp: 'C#', cs: 'C#', css: 'CSS', diff: 'Diff', plaintext: 'Text', text: 'Text',
};
const codeSprache = (kurz) => CODE_SPRACHEN[(kurz || '').toLowerCase()] || (kurz ? kurz.toUpperCase() : 'Code');

// „Inhalt geprüft“ in der rechten Leiste (Baustein fundus/pruefung-details, pruefung/Pruefung.php).
// Nach dem Speichern zeigt der Kasten das neue Datum; die Plakette im Artikelkopf verschwindet beim nächsten Laden.
function pruefungKnopf() {
  const kasten = document.querySelector('[data-fundus-pruefung]');
  const knopf = kasten?.querySelector('[data-pruefen]');
  if (!knopf) return;
  knopf.addEventListener('click', async () => {
    knopf.disabled = true;
    try {
      const antwort = await fetch(knopf.dataset.pruefen, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="token"]')?.content || '' },
        body: '{}',
      });
      const daten = await antwort.json();
      if (!antwort.ok) throw new Error();
      const heute = new Date().toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
      kasten.querySelector('[data-zuletzt]').textContent = heute + ' von dir';
      const faellig = kasten.querySelector('[data-faellig]');
      if (faellig && daten.faellig_am) {
        faellig.textContent = daten.faellig_am;
        faellig.previousElementSibling.textContent = 'Nächste Prüfung';
      }
      kasten.classList.remove('faellig', 'bald');
      const danke = kasten.querySelector('[data-geprueft]');
      danke.querySelector('span').textContent = daten.faellig_am || '';
      danke.hidden = false;
      knopf.hidden = true;
    } catch (e) {
      knopf.disabled = false;
    }
  });
}

// Gelesen melden (aktivitaet/Aktivitaet.php): erst nach LESEZEIT Sekunden, in denen der Tab sichtbar war,
// damit Durchklicken und Hintergrund-Tabs nicht zählen. Einmal je Seitenaufruf; der Server zählt je Tag nur einmal.
// Seite und Adresse stehen am Rückmeldungs-Baustein, den es nur unter Artikeln gibt.
function gelesenMelden() {
  const box = document.querySelector('[data-fundus-rueckmeldung][data-gelesen-adresse]');
  if (!box) return;
  const noetig = Number(box.dataset.lesezeit || 20) * 1000;
  let gelesen = 0;
  let seit = document.visibilityState === 'visible' ? Date.now() : null;
  const pruefen = () => {
    if (seit !== null) { gelesen += Date.now() - seit; seit = document.visibilityState === 'visible' ? Date.now() : null; }
    else if (document.visibilityState === 'visible') seit = Date.now();
    if (gelesen < noetig) return;
    clearInterval(uhr);
    document.removeEventListener('visibilitychange', pruefen);
    fetch(box.dataset.gelesenAdresse, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="token"]')?.content || '' },
      body: JSON.stringify({ page_id: Number(box.dataset.seite) }),
    }).catch(() => {});
  };
  const uhr = setInterval(pruefen, 2000);
  document.addEventListener('visibilitychange', pruefen);
}

// Rückmeldungen unter Artikeln (Bausteine fundus/rueckmeldung und rueckmeldung-details).
function rueckmeldungen() {
  const token = document.querySelector('meta[name="token"]')?.content || '';
  const senden = (adresse, daten) => fetch(adresse, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
    body: JSON.stringify(daten || {}),
  }).then(async (r) => ({ ok: r.ok, daten: await r.json().catch(() => ({})) }));

  const box = document.querySelector('[data-fundus-rueckmeldung]');
  if (box) {
    const adresse = box.dataset.adresse;
    const seite = Number(box.dataset.seite);
    const danke = box.querySelector('[data-danke]');
    const melden = box.querySelector('[data-melden]');
    const formular = box.querySelector('[data-formular]');
    const gesendet = box.querySelector('[data-gesendet]');
    const feld = formular.querySelector('textarea');
    const zaehler = formular.querySelector('[data-zaehler]');
    const fehler = formular.querySelector('[data-fehler]');
    const sendenKnopf = formular.querySelector('[data-senden]');

    box.querySelectorAll('[data-art]').forEach((knopf) => knopf.addEventListener('click', async () => {
      const vorher = [...box.querySelectorAll('[data-art]')].map((k) => k.getAttribute('aria-pressed'));
      box.querySelectorAll('[data-art]').forEach((k) => k.setAttribute('aria-pressed', String(k === knopf)));
      const { ok } = await senden(adresse, { page_id: seite, art: knopf.dataset.art });
      if (!ok) {
        box.querySelectorAll('[data-art]').forEach((k, i) => k.setAttribute('aria-pressed', vorher[i]));
        return;
      }
      danke.hidden = false;
      // Wer „Nein“ sagt, hat meist einen Grund: das Formular gleich anbieten.
      if (knopf.dataset.art === 'nein' && formular.hidden) formularZeigen(true);
    }));

    const formularZeigen = (auf) => {
      formular.hidden = !auf;
      gesendet.hidden = true;
      melden.setAttribute('aria-expanded', String(auf));
      if (auf) {
        // Den Abschnitt vorwählen, den man gerade liest (BookStack markiert ihn in „In diesem Artikel“).
        const aktuell = document.querySelector('#page-navigation .current-heading a')?.textContent.trim();
        const auswahl = formular.querySelector('[data-abschnitt]');
        if (aktuell && !auswahl.value && [...auswahl.options].some((o) => o.value === aktuell)) auswahl.value = aktuell;
        formular.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        feld.focus({ preventScroll: true });
      } else {
        melden.focus();
      }
    };
    melden.addEventListener('click', () => formularZeigen(formular.hidden));
    formular.querySelectorAll('[data-abbrechen]').forEach((k) => k.addEventListener('click', () => formularZeigen(false)));
    formular.addEventListener('keydown', (e) => { if (e.key === 'Escape') formularZeigen(false); });

    // Grund wählen: Beispiel im Beschreibungsfeld passt sich an.
    formular.querySelectorAll('input[name="grund"]').forEach((r) => r.addEventListener('change', () => {
      feld.placeholder = r.dataset.vorschlag;
    }));
    const zaehlen = () => {
      zaehler.textContent = `${feld.value.length} / ${feld.maxLength}`;
      zaehler.classList.toggle('knapp', feld.value.length > feld.maxLength - 100);
    };
    feld.addEventListener('input', () => { zaehlen(); fehler.hidden = true; feld.removeAttribute('aria-invalid'); });

    formular.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!feld.value.trim()) {
        fehler.textContent = 'Bitte kurz beschreiben, was nicht stimmt.';
        fehler.hidden = false;
        feld.setAttribute('aria-invalid', 'true');
        feld.focus();
        return;
      }
      sendenKnopf.disabled = true;
      sendenKnopf.textContent = 'Wird gesendet …';
      const { ok, daten } = await senden(adresse, {
        page_id: seite,
        art: 'veraltet',
        grund: formular.querySelector('input[name="grund"]:checked')?.value,
        abschnitt: formular.querySelector('[data-abschnitt]').value,
        text: feld.value,
      });
      sendenKnopf.disabled = false;
      sendenKnopf.textContent = 'Hinweis senden';
      if (!ok) {
        fehler.textContent = daten.fehler || 'Der Hinweis konnte nicht gesendet werden. Bitte später noch einmal versuchen.';
        fehler.hidden = false;
        return;
      }
      feld.value = '';
      zaehlen();
      formular.hidden = true;
      melden.setAttribute('aria-expanded', 'false');
      gesendet.hidden = false;
    });
  }

  // Details: Hinweis als erledigt markieren.
  document.querySelectorAll('[data-erledigt]').forEach((knopf) => knopf.addEventListener('click', async () => {
    knopf.disabled = true;
    const { ok } = await senden(knopf.dataset.erledigt);
    if (ok) knopf.closest('li')?.remove(); else knopf.disabled = false;
  }));
}

// ============================================================================
// 6. Personen
// ============================================================================

// Profilbild und Titel neben Namen.
// Daten aus fundus/kopf (#fundus-personen): Profiladresse → Name, hochgeladenes Bild, Titel, Stufe.
// Ohne hochgeladenes Bild gibt es Initialen auf einer ruhigen Farbe, die sich aus dem Namen ergibt.
let personenDaten;
function personenLesen() {
  if (personenDaten !== undefined) return personenDaten;
  try { personenDaten = JSON.parse(document.getElementById('fundus-personen')?.textContent || 'null'); } catch (e) { personenDaten = null; }
  return personenDaten;
}
function personZuLink(a) {
  const daten = personenLesen();
  const slug = (new URL(a.href, location.href).pathname.match(/\/user\/([^/?#]+)\/?$/) || [])[1];
  return slug && daten?.personen?.[decodeURIComponent(slug)] ? { slug, ...daten.personen[decodeURIComponent(slug)] } : null;
}
function initialen(name) {
  const teile = String(name || '?').trim().split(/\s+/);
  return ((teile[0]?.[0] || '') + (teile.length > 1 ? teile[teile.length - 1][0] : '')).toUpperCase() || '?';
}
function avatarElement(person, groesse) {
  if (person.bild) {
    const img = document.createElement('img');
    img.src = person.bild;
    img.alt = '';
    img.className = 'fundus-avatar';
    img.style.setProperty('--groesse', `${groesse}px`);
    return img;
  }
  let summe = 0;
  for (const z of person.name || '') summe = (summe * 31 + z.codePointAt(0)) >>> 0;
  const el = document.createElement('span');
  el.className = `fundus-avatar fundus-initialen farbe-${summe % 6}`;
  el.style.setProperty('--groesse', `${groesse}px`);
  el.setAttribute('aria-hidden', 'true');
  el.textContent = initialen(person.name);
  return el;
}
function titelElement(person) {
  if (!person.titel) return null;
  const el = document.createElement('span');
  el.className = `fundus-titel stufe-${String(person.stufe || 'team').replace(/[^a-z]/g, '')}`;
  el.textContent = person.titel;
  return el;
}

function personenSchmuecken(wurzel = document) {
  const daten = personenLesen();
  if (!daten) return;

  // Namen als Links: kleines Profilbild davor, Titel dahinter.
  const orte = [
    ['.fundus-artikelkopf', 20], ['.entity-meta-item', 18], ['.fundus-uebersicht-neu', 18],
    ['.comment-box .meta', 0], ['.activity-list-item', 0], ['.fundus-person-zeile', 32],
  ];
  for (const [ort, groesse] of orte) {
    wurzel.querySelectorAll(`${ort} a[href*="/user/"]:not([data-fundus-person]), a${ort}[href*="/user/"]:not([data-fundus-person])`).forEach((a) => {
      const person = personZuLink(a);
      if (!person) return;
      a.dataset.fundusPerson = '1';
      if (a.matches('.fundus-person-zeile')) {
        a.prepend(avatarElement(person, groesse));
        const titel = titelElement(person);
        if (titel) a.querySelector('.name')?.after(titel);
        return;
      }
      if (groesse) {
        const huelle = document.createElement('span');
        huelle.className = 'fundus-person';
        a.before(huelle);
        huelle.append(avatarElement(person, groesse), a);
        const titel = titelElement(person);
        if (titel) huelle.append(titel);
      } else {
        const titel = titelElement(person);
        if (titel) a.after(titel);
      }
    });
  }

  // Vorhandene BookStack-Avatare: Standardbild durch Initialen ersetzen (Kommentare, Aktivität, Kopfleiste, Profil).
  wurzel.querySelectorAll('img.avatar:not([data-fundus-person])').forEach((img) => {
    img.dataset.fundusPerson = '1';
    const link = img.closest('.comment-box')?.querySelector('.meta a[href*="/user/"]')
      || img.closest('.activity-list-item')?.querySelector('a[href*="/user/"]');
    let person = link ? personZuLink(link) : null;
    if (!person && img.closest('.user-name')) person = daten.personen[daten.ich];
    if (!person && /\/user\/[^/]+\/?$/.test(location.pathname) && img.matches('.huge')) {
      person = daten.personen[decodeURIComponent(location.pathname.split('/').filter(Boolean).pop())];
    }
    if (!person) return;
    if (/\/user_avatar\.png(\?|$)/.test(img.getAttribute('src') || '') || person.bild) {
      const groesse = img.matches('.huge') ? 120 : (Number(img.getAttribute('width')) || img.getBoundingClientRect().width || 30);
      const neu = avatarElement(person, Math.round(groesse));
      neu.classList.add(...[...img.classList].filter((k) => k !== 'avatar'));
      neu.dataset.fundusPerson = '1';
      img.replaceWith(neu);
    }
  });

  // Kopfleiste: eigener Titel neben dem Namen.
  const kopfName = wurzel.querySelector?.('header .user-name .name:not([data-fundus-person])');
  if (kopfName && daten.personen[daten.ich]) {
    kopfName.dataset.fundusPerson = '1';
    const titel = titelElement(daten.personen[daten.ich]);
    if (titel) kopfName.after(titel);
  }

  // Profilseite: Titel unter dem Namen.
  const profilName = wurzel.querySelector?.('.content-wrap .grid.half h4.mt-md:not([data-fundus-person])');
  const profilSlug = (location.pathname.match(/\/user\/([^/]+)\/?$/) || [])[1];
  if (profilName && profilSlug && daten.personen[decodeURIComponent(profilSlug)]) {
    profilName.dataset.fundusPerson = '1';
    const titel = titelElement(daten.personen[decodeURIComponent(profilSlug)]);
    if (titel) profilName.after(titel);
  }
}

// ============================================================================
// 7. KI-Chat Fundus
//    Server-Seite: theme/fundus/ki/Ki.php, Markup: fundus/ki.blade.php.
// ============================================================================

// Chat (Baustein fundus/ki) als Seitenleiste rechts; der Inhalt rückt zur Seite (html.fundus-ki-offen).
// Die Antwort kommt als Datenstrom: je Zeile ein JSON-Ereignis {quellen}, {text}, {fehler} oder {fertig}.
// Jede Antwort hat eine Lauf-ID: Wechselt die Seite, rechnet der Server weiter, und die neue Seite holt den
// Stand ab (antwortFolgen). Das offene Gespräch bleibt beim Seitenwechsel erhalten (sessionStorage);
// abgeschlossene Gespräche liegen im Archiv (localStorage, je Person), das im Vollbild links steht.
const KI_DENKWOERTER = [
  'Manifestierend', 'Sinnierend', 'Ergründend', 'Enträtselnd', 'Entziffernd', 'Beschwörend',
  'Orakelnd', 'Weissagend', 'Kontemplierend', 'Destillierend', 'Kristallisierend', 'Transmutierend',
  'Konsultierend', 'Meditierend', 'Divinierend', 'Heraufbeschwörend', 'Verdichtend', 'Erleuchtend',
];
// Das Zeichen der Statuszeile läuft hin und zurück durch diese Formen (wie in Claude Code).
const KI_DENKZEICHEN = ['·', '✢', '✳', '✶', '✻', '✽', '✻', '✶', '✳', '✢'];
const KI_ARCHIV_MAX = 30;

function kiChat() {
  const panel = document.querySelector('[data-fundus-ki]');
  const knopf = document.querySelector('[data-fundus-ki-knopf]');
  if (!panel || !knopf) return;
  const form = panel.querySelector('[data-fundus-ki-form]');
  const feld = panel.querySelector('[data-fundus-ki-feld]');
  const liste = panel.querySelector('[data-fundus-ki-verlauf]');
  const senden = panel.querySelector('[data-fundus-ki-senden]');
  const leer = panel.querySelector('.fundus-ki-leer');
  const basis = document.querySelector('meta[name="base-url"]')?.content || '';
  const token = document.querySelector('meta[name="token"]')?.content || '';
  const SPEICHER = 'fundus-ki';
  // Je Person, damit sich zwei Konten im selben Browser nicht gegenseitig die Gespräche zeigen.
  const ARCHIV = `fundus-ki-archiv-${panel.dataset.nutzer || '0'}`;
  const archivListe = panel.querySelector('[data-fundus-ki-archiv]');
  const fundusListe = panel.querySelector('[data-fundus-ki-fundus]');
  const fundusLeer = panel.querySelector('[data-fundus-ki-fundus-leer]');
  const feldHuelle = panel.querySelector('.fundus-ki-feld');
  const wurzel = document.documentElement;
  const LAUF = 'fundus-ki-lauf';
  let nachrichten = [];
  let chatId = null;
  // Die laufende Antwort: { stoppen() }. Solange sie läuft, ist Senden der Stopp-Knopf.
  let laufend = null;

  // Mitlaufen: Wer unten ist, bleibt unten, auch wenn die Antwort wächst. Aus geht es erst, wenn die Person
  // selbst hochscrollt; wieder an, wenn sie nach unten scrollt oder etwas fragt. Die Lage wird nicht bei jedem
  // Zeichnen geraten: Wurde dazwischen etwas höher (etwa frühere Antworten nach dem Laden von Markdown),
  // hielt die alte Abstandsmessung das für Hochscrollen und hörte auf mitzulaufen.
  let folgen = true;
  let selbstGescrollt = false;
  const nachUnten = () => {
    selbstGescrollt = true;
    liste.scrollTop = liste.scrollHeight;
    requestAnimationFrame(() => { selbstGescrollt = false; });
  };
  const mitlaufen = () => { if (folgen) nachUnten(); };
  liste.addEventListener('scroll', () => {
    if (selbstGescrollt) return;
    folgen = liste.scrollHeight - liste.scrollTop - liste.clientHeight < 60;
  }, { passive: true });
  const laufMerken = (lauf) => {
    try { if (lauf) sessionStorage.setItem(LAUF, JSON.stringify(lauf)); else sessionStorage.removeItem(LAUF); } catch (e) { /* ohne Speicher: kein Fortsetzen */ }
  };
  const speichern = () => {
    try { sessionStorage.setItem(SPEICHER, JSON.stringify({ offen: !panel.hidden, gross: panel.classList.contains('gross'), chatId, nachrichten })); } catch (e) { /* ohne Speicher weiter */ }
    fundusZeigen();
  };

  // ---- Archiv früherer Gespräche ----
  const archivLesen = () => { try { return JSON.parse(localStorage.getItem(ARCHIV) || '[]'); } catch (e) { return []; } };
  const archivSchreiben = (eintraege) => {
    try { localStorage.setItem(ARCHIV, JSON.stringify(eintraege.slice(0, KI_ARCHIV_MAX))); } catch (e) { /* Speicher voll oder gesperrt */ }
    archivZeigen();
  };
  const archivieren = () => {
    if (!nachrichten.length) return;
    chatId = chatId || `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
    const titel = (nachrichten.find((n) => n.rolle === 'nutzer')?.text || 'Gespräch').replace(/\s+/g, ' ').slice(0, 80);
    const rest = archivLesen().filter((c) => c.id !== chatId);
    archivSchreiben([{ id: chatId, titel, zeit: Date.now(), nachrichten }, ...rest]);
  };
  const zeitText = (ms) => {
    const d = new Date(ms);
    const heute = new Date(); heute.setHours(0, 0, 0, 0);
    const tage = Math.floor((heute - new Date(d).setHours(0, 0, 0, 0)) / 864e5);
    const uhr = d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    if (tage <= 0) return `Heute, ${uhr}`;
    if (tage === 1) return `Gestern, ${uhr}`;
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
  };
  const archivZeigen = () => {
    if (!archivListe) return;
    const eintraege = archivLesen();
    archivListe.innerHTML = eintraege.length
      ? eintraege.map((c) => `
        <li class="${c.id === chatId ? 'aktiv' : ''}">
          <button type="button" class="fundus-ki-archiv-oeffnen" data-chat="${kiEscape(c.id)}">
            <span class="titel">${kiEscape(c.titel)}</span>
            <span class="zeit">${zeitText(c.zeit)}</span>
          </button>
          <button type="button" class="fundus-ki-archiv-loeschen" data-chat-loeschen="${kiEscape(c.id)}" title="Gespräch löschen" aria-label="Gespräch löschen">
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
          </button>
        </li>`).join('')
      : '<li class="leer">Noch keine Gespräche.</li>';
  };
  archivListe?.addEventListener('click', (e) => {
    const loeschen = e.target.closest('[data-chat-loeschen]');
    if (loeschen) {
      const id = loeschen.dataset.chatLoeschen;
      archivSchreiben(archivLesen().filter((c) => c.id !== id));
      if (id === chatId) neuesGespraech();
      return;
    }
    const oeffnen = e.target.closest('[data-chat]');
    if (!oeffnen) return;
    const chat = archivLesen().find((c) => c.id === oeffnen.dataset.chat);
    if (!chat) return;
    laufend?.stoppen();
    chatId = chat.id;
    nachrichten = chat.nachrichten || [];
    verlaufZeigen();
    speichern();
    archivZeigen();
    feld.focus();
  });

  // ---- Rechte Spalte: alle Artikel, aus denen im Gespräch zitiert wurde ----
  const fundusZeigen = () => {
    if (!fundusListe) return;
    const artikel = new Map();
    for (const n of nachrichten) {
      for (const q of n.quellen || []) {
        if (!artikel.has(q.url)) artikel.set(q.url, q);
      }
    }
    fundusListe.innerHTML = [...artikel.values()].map((q) => `
      <li><a href="${kiEscape(q.url)}" data-fundus-stelle="${kiEscape(q.stelle || '')}" data-fundus-abschnitt="${kiEscape(q.abschnitt || '')}">
        <span class="titel">${kiEscape(q.titel)}</span><span class="thema">${kiEscape(q.thema || '')}</span>
      </a></li>`).join('');
    fundusLeer.hidden = artikel.size > 0;
  };
  fundusListe?.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-fundus-stelle]');
    if (!a) return;
    try {
      sessionStorage.setItem('fundus-ki-stelle', JSON.stringify({ pfad: new URL(a.href).pathname, stelle: a.dataset.fundusStelle, abschnitt: a.dataset.fundusAbschnitt }));
    } catch (err) { /* ohne Markierung weiter */ }
    // Wie bei den Quellen unter einer Antwort: Der Chat bleibt als Seitenleiste neben dem Artikel offen.
    zurSeitenleiste();
  });

  // ---- Seitenleiste: offen/zu, Vollbild, Breite ----
  // Unter rund 1400 px neben dem Chat passen BookStacks drei Spalten nicht mehr; dann klappen sie ein.
  const KI_DREI_SPALTEN = 1400;
  const seitenleisteSetzen = () => {
    const offen = !panel.hidden && !panel.classList.contains('gross');
    wurzel.classList.toggle('fundus-ki-offen', offen);
    wurzel.classList.toggle('fundus-ki-eng', offen && window.innerWidth - panel.getBoundingClientRect().width < KI_DREI_SPALTEN);
  };
  window.addEventListener('resize', () => seitenleisteSetzen());
  const umschalten = (auf, { still = false } = {}) => {
    const vorher = !panel.hidden;
    panel.hidden = !auf;
    knopf.setAttribute('aria-expanded', String(auf));
    knopf.classList.toggle('offen', auf);
    // Nur beim Öffnen von Hand gleitet die Leiste herein, nicht bei jedem Seitenwechsel.
    if (auf && !vorher && !still) {
      wurzel.classList.add('fundus-ki-neu-offen');
      setTimeout(() => wurzel.classList.remove('fundus-ki-neu-offen'), 300);
    }
    seitenleisteSetzen();
    if (auf && !still) feld.focus();
    if (auf) nachUnten();
    speichern();
  };
  const grossKnopf = panel.querySelector('[data-fundus-ki-gross]');
  const gross = (an) => {
    panel.classList.toggle('gross', an);
    grossKnopf.setAttribute('aria-pressed', String(an));
    grossKnopf.title = an ? 'Vollbild verlassen' : 'Vollbild';
    grossKnopf.setAttribute('aria-label', grossKnopf.title);
    seitenleisteSetzen();
  };
  // Beim Klick auf eine Quelle: aus dem Vollbild in die Seitenleiste, damit der Artikel daneben Platz hat.
  const zurSeitenleiste = () => {
    if (panel.classList.contains('gross')) { gross(false); speichern(); }
  };

  const griff = panel.querySelector('[data-fundus-ki-griff]');
  const breiteSetzen = (px, merken) => {
    const breite = Math.round(Math.min(Math.max(px, 320), Math.max(320, Math.min(760, window.innerWidth - 360))));
    wurzel.style.setProperty('--ki-breite', `${breite}px`);
    seitenleisteSetzen();
    if (merken) { try { localStorage.setItem('fundus-ki-breite', String(breite)); } catch (e) { /* nur für diese Seite */ } }
  };
  griff?.addEventListener('pointerdown', (e) => {
    if (e.button !== 0) return;
    e.preventDefault();
    griff.setPointerCapture(e.pointerId);
    wurzel.classList.add('fundus-ki-zieht');
    const bewegen = (ev) => breiteSetzen(window.innerWidth - ev.clientX, false);
    const ende = (ev) => {
      breiteSetzen(window.innerWidth - ev.clientX, true);
      wurzel.classList.remove('fundus-ki-zieht');
      griff.removeEventListener('pointermove', bewegen);
      griff.removeEventListener('pointerup', ende);
      griff.removeEventListener('pointercancel', ende);
    };
    griff.addEventListener('pointermove', bewegen);
    griff.addEventListener('pointerup', ende);
    griff.addEventListener('pointercancel', ende);
  });
  griff?.addEventListener('keydown', (e) => {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    e.preventDefault();
    breiteSetzen(panel.getBoundingClientRect().width + (e.key === 'ArrowLeft' ? 24 : -24), true);
  });
  griff?.addEventListener('dblclick', () => breiteSetzen(420, true));
  grossKnopf.addEventListener('click', () => { gross(!panel.classList.contains('gross')); speichern(); nachUnten(); feld.focus(); });
  const blase = (rolle) => {
    leer.hidden = true;
    const el = document.createElement('div');
    el.className = `fundus-ki-blase ${rolle}`;
    liste.append(el);
    return el;
  };
  // Fertige Antwort: Text, aufklappbare Quellen mit Zitat und eine Aktionsleiste.
  const kiBlaseFuellen = (el, text, quellen, frage = '', dauer = null) => {
    el.innerHTML = `<div class="fundus-ki-text">${kiRender(text, quellen)}</div>`;
    if (quellen.length) el.append(kiQuellenliste(text, quellen));
    const leiste = document.createElement('div');
    leiste.className = 'fundus-ki-aktionen';
    leiste.innerHTML = `<button type="button" data-aktion="kopieren" title="Antwort kopieren">${KI_SYMBOL.kopieren}</button>`
      + (frage ? `<button type="button" data-aktion="nochmal" title="Nochmal fragen">${KI_SYMBOL.nochmal}</button>` : '')
      + (dauer ? `<span>${dauer} s</span>` : '');
    leiste.dataset.frage = frage;
    leiste.dataset.text = text;
    el.append(leiste);
  };

  // Alle Nachrichten neu zeichnen (Wiederherstellen, Gespräch aus dem Archiv öffnen).
  const verlaufZeigen = () => {
    liste.querySelectorAll('.fundus-ki-blase').forEach((b) => b.remove());
    leer.hidden = nachrichten.length > 0;
    const kiBlasen = [];
    for (const n of nachrichten) {
      const el = blase(n.rolle);
      if (n.rolle === 'nutzer') el.textContent = n.text; else { kiBlaseFuellen(el, n.text, n.quellen || [], n.frage, n.dauer); kiBlasen.push([el, n]); }
    }
    if (kiBlasen.length) {
      kiBibliotheken().then(() => {
        kiBlasen.forEach(([el, n]) => kiBlaseFuellen(el, n.text, n.quellen || [], n.frage, n.dauer));
        mitlaufen();
      }).catch(() => {});
    }
    folgen = true;
    nachUnten();
  };
  const neuesGespraech = () => {
    laufend?.stoppen();
    nachrichten = [];
    chatId = null;
    verlaufZeigen();
    speichern();
    archivZeigen();
    feld.focus();
  };

  // Gespeicherten Chat wiederherstellen.
  try {
    const alt = JSON.parse(sessionStorage.getItem(SPEICHER) || 'null');
    nachrichten = alt?.nachrichten || [];
    chatId = alt?.chatId || null;
    verlaufZeigen();
    gross(Boolean(alt?.gross));
    if (alt?.offen) umschalten(true, { still: true });
  } catch (e) { /* kaputter Speicherinhalt: frisch anfangen */ }
  archivZeigen();
  fundusZeigen();

  knopf.addEventListener('click', () => umschalten(panel.hidden));
  panel.querySelector('[data-fundus-ki-zu]').addEventListener('click', () => umschalten(false));
  // Esc bricht eine laufende Antwort ab (wie in Claude Code), sonst schließt es den Chat.
  panel.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (laufend) laufend.stoppen(); else umschalten(false);
  });
  panel.querySelectorAll('[data-fundus-ki-neu]').forEach((b) => b.addEventListener('click', neuesGespraech));

  // Eingabefeld wächst mit; Enter sendet, Umschalt+Enter macht eine neue Zeile.
  // Der Senden-Knopf wird erst dunkel, wenn etwas drinsteht.
  const feldAnpassen = () => {
    feld.style.height = 'auto';
    feld.style.height = Math.min(feld.scrollHeight, 180) + 'px';
    feldHuelle.classList.toggle('hat-text', feld.value.trim() !== '');
  };
  feld.addEventListener('input', feldAnpassen);
  feld.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });
  panel.querySelectorAll('[data-fundus-ki-beispiel]').forEach((b) => b.addEventListener('click', () => {
    feld.value = b.textContent; form.requestSubmit();
  }));

  // Klicks im Verlauf: Quelle öffnen (Chat schließen, Fundstelle im Artikel markieren),
  // Zitat aufklappen, Antwort kopieren, Frage erneut stellen.
  liste.addEventListener('click', async (e) => {
    const quelle = e.target.closest('a[data-fundus-stelle]');
    if (quelle) {
      try {
        sessionStorage.setItem('fundus-ki-stelle', JSON.stringify({
          pfad: new URL(quelle.href).pathname, stelle: quelle.dataset.fundusStelle, abschnitt: quelle.dataset.fundusAbschnitt,
        }));
      } catch (err) { /* ohne Markierung weiter */ }
      zurSeitenleiste();
      return; // der Link selbst navigiert; die Seitenleiste bleibt auf der nächsten Seite offen
    }
    const aufklapper = e.target.closest('[data-fundus-zitat]');
    if (aufklapper) {
      const karte = aufklapper.closest('.fundus-ki-quelle');
      const offen = karte.classList.toggle('offen');
      aufklapper.setAttribute('aria-expanded', String(offen));
      return;
    }
    const aktion = e.target.closest('[data-aktion]');
    if (!aktion) return;
    const leiste = aktion.closest('.fundus-ki-aktionen');
    const bestaetigen = () => {
      const vorher = aktion.innerHTML;
      aktion.innerHTML = aktion.dataset.aktion === 'code-kopieren' ? `${KI_SYMBOL.haken}<b>Kopiert</b>` : KI_SYMBOL.haken;
      setTimeout(() => { aktion.innerHTML = vorher; }, 1500);
    };
    if (aktion.dataset.aktion === 'code-kopieren') {
      try { await navigator.clipboard.writeText(aktion.closest('.fundus-ki-code').querySelector('code').textContent); bestaetigen(); } catch (err) { /* Zwischenablage gesperrt */ }
    } else if (aktion.dataset.aktion === 'kopieren') {
      try { await navigator.clipboard.writeText(leiste.dataset.text.replace(/\s?\[\d{1,2}\]/g, '')); bestaetigen(); } catch (err) { /* Zwischenablage gesperrt */ }
    } else if (aktion.dataset.aktion === 'nochmal' && !laufend) {
      feld.value = leiste.dataset.frage;
      form.requestSubmit();
    }
  });

  // Während eine Antwort läuft, wird der Senden-Knopf zum Stopp-Knopf.
  senden.addEventListener('click', (e) => {
    if (laufend) { e.preventDefault(); laufend.stoppen(); }
  });

  // Statuszeile wie in Claude Code, bis das erste Wort kommt: ein Zeichen, das pulsiert, ein Wort für die
  // ganze Antwort, das schimmert, dahinter gedämpft Zeit und „Esc zum Abbrechen“.
  const denkAnzeige = (antwort, beginn) => {
    const denkt = document.createElement('div');
    denkt.className = 'fundus-ki-denkt';
    denkt.setAttribute('role', 'status');
    denkt.innerHTML = '<span class="zeichen" aria-hidden="true"></span><span class="wort"></span><span class="info"></span>';
    antwort.append(denkt);
    const wort = KI_DENKWOERTER[Math.floor(Math.random() * KI_DENKWOERTER.length)];
    denkt.querySelector('.wort').textContent = `${wort} …`;
    let bild = 0;
    const zeigen = () => {
      denkt.querySelector('.zeichen').textContent = KI_DENKZEICHEN[bild++ % KI_DENKZEICHEN.length];
      const sek = Math.floor((Date.now() - beginn) / 1000);
      const zeit = sek < 60 ? `${sek} s` : `${Math.floor(sek / 60)} min ${sek % 60} s`;
      const info = `(${zeit} · Esc zum Abbrechen)`;
      if (denkt.querySelector('.info').textContent !== info) denkt.querySelector('.info').textContent = info;
    };
    zeigen();
    const takt = setInterval(() => { if (denkt.isConnected) zeigen(); else clearInterval(takt); }, 260);
    return () => { clearInterval(takt); denkt.remove(); };
  };

  const warte = (ms, signal) => new Promise((fertig, fehler) => {
    const uhr = setTimeout(fertig, ms);
    signal?.addEventListener('abort', () => { clearTimeout(uhr); fehler(new DOMException('abgebrochen', 'AbortError')); }, { once: true });
  });

  // Einer Antwort folgen: erst dem Datenstrom (holen), reißt er ab oder gibt es keinen (neue Seite nach einem
  // Wechsel), fragt die Seite beim Server nach, bis die Antwort fertig ist. Ein Stopp gilt auch auf dem Server.
  const antwortFolgen = async ({ lauf, frage, beginn, antwort, holen }) => {
    const steuerung = new AbortController();
    let gestoppt = false;
    laufend = {
      stoppen: () => {
        if (gestoppt) return;
        gestoppt = true;
        steuerung.abort();
        fetch(`${basis}/fundus/ki/lauf/${lauf}/stopp`, {
          method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'X-CSRF-TOKEN': token },
        }).catch(() => {});
      },
    };
    panel.classList.add('laeuft');
    const denkEnde = denkAnzeige(antwort, beginn);
    // Erst jetzt steht die Statuszeile da; ohne das lag sie halb unter dem Rand, bis das erste Wort kam.
    mitlaufen();
    const anzeige = kiStromAnzeige(antwort, mitlaufen);
    let stand = { text: '', quellen: [], fertig: false, fehler: '' };
    const aufnehmen = (neu) => {
      stand = { ...stand, ...neu };
      // Sobald Text kommt, ist das Denken vorbei: Statuszeile weg.
      if (stand.text) denkEnde();
      anzeige.setzen(stand);
    };

    try {
      if (holen) {
        try {
          const res = await holen(steuerung.signal);
          // Abgelehnt (abgelaufene Anmeldung, ungültige Anfrage): Auf dem Server läuft dann nichts, dem man folgen könnte.
          if (!res.ok) {
            throw Object.assign(new Error(`HTTP ${res.status}`), {
              fehler: res.status === 419 || res.status === 401
                ? 'Deine Anmeldung ist abgelaufen. Lade die Seite neu und frag noch einmal.'
                : `Die Frage kam nicht an (Fehler ${res.status}). Bitte gleich noch einmal versuchen.`,
            });
          }
          if (!res.body) throw new Error('ohne Datenstrom');
          const leser = res.body.getReader();
          const dekoder = new TextDecoder();
          let puffer = '';
          let text = '';
          for (;;) {
            const { value, done } = await leser.read();
            if (done) break;
            puffer += dekoder.decode(value, { stream: true });
            let pos;
            while ((pos = puffer.indexOf('\n')) >= 0) {
              const zeile = puffer.slice(0, pos).trim();
              puffer = puffer.slice(pos + 1);
              if (!zeile) continue; // Lebenszeichen, während das Modell lädt
              const e = JSON.parse(zeile);
              if (e.quellen) aufnehmen({ quellen: e.quellen });
              else if (e.text) { text += e.text; aufnehmen({ text }); }
              else if (e.fehler) aufnehmen({ fehler: e.fehler });
              else if (e.fertig) aufnehmen({ fertig: true });
            }
          }
        } catch (err) {
          if (gestoppt) throw err;
          if (err.fehler) aufnehmen({ fehler: err.fehler });
          // Sonst ist die Verbindung weg, der Server rechnet vielleicht weiter: unten nachfragen.
        }
      }
      while (!stand.fertig && !stand.fehler && !gestoppt) {
        const res = await fetch(`${basis}/fundus/ki/lauf/${lauf}`, {
          credentials: 'same-origin', signal: steuerung.signal, headers: { Accept: 'application/json' },
        });
        if (res.status === 404) { stand.weg = true; break; }
        if (res.ok) aufnehmen(await res.json());
        if (!stand.fertig && !stand.fehler) await warte(400, steuerung.signal);
      }
    } catch (err) {
      if (!gestoppt) stand.fehler = stand.fehler || 'Das hat nicht geklappt. Bitte gleich noch einmal versuchen.';
    }

    denkEnde();
    anzeige.beenden();
    if (!stand.text) {
      const grund = gestoppt ? 'Abgebrochen.'
        : stand.fehler || (stand.weg ? 'Die Antwort ist unterwegs verloren gegangen. Bitte noch einmal fragen.' : 'Keine Antwort erhalten.');
      antwort.innerHTML = `<p class="fundus-ki-fehler">${kiEscape(grund)}</p>`;
    } else {
      let roh = stand.text;
      // Ohne „fertig“ ist die Antwort abgeschnitten; das soll man sehen.
      if (gestoppt) roh += '\n\n_(Abgebrochen)_';
      else if (!stand.fertig) roh += '\n\n_(Antwort unvollständig, die Verbindung wurde unterbrochen)_';
      const dauer = Math.round((Date.now() - beginn) / 1000);
      kiBlaseFuellen(antwort, roh, stand.quellen || [], frage, dauer);
      nachrichten.push({ rolle: 'ki', text: roh, quellen: stand.quellen || [], frage, dauer });
    }
    laufend = null;
    laufMerken(null);
    panel.classList.remove('laeuft');
    speichern();
    archivieren();
    mitlaufen();
    if (!panel.hidden) feld.focus({ preventScroll: true });
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const frage = feld.value.trim();
    if (!frage || laufend) return;
    feld.value = '';
    feldAnpassen();

    // Der Server nimmt je Nachricht höchstens 4000 Zeichen (Ki::antworten); lange Antworten gehen gekürzt mit,
    // sonst würde jede weitere Frage in diesem Gespräch abgelehnt.
    const verlauf = nachrichten.slice(-4).map(({ rolle, text }) => ({ rolle, text: text.slice(0, 4000) }));
    nachrichten.push({ rolle: 'nutzer', text: frage });
    blase('nutzer').textContent = frage;
    folgen = true; // wer fragt, will die Antwort sehen
    speichern();
    archivieren();

    const lauf = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
    const beginn = Date.now();
    laufMerken({ lauf, frage, beginn });
    const antwort = blase('ki');
    nachUnten();
    kiBibliotheken().catch(() => {});
    await antwortFolgen({
      lauf, frage, beginn, antwort,
      holen: (signal) => fetch(`${basis}/fundus/ki`, {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: { 'Content-Type': 'application/json', Accept: 'application/x-ndjson', 'X-CSRF-TOKEN': token },
        body: JSON.stringify({ frage, verlauf, lauf }),
      }),
    });
  });

  // Lief beim Seitenwechsel eine Antwort, weiter folgen: Der Server hat inzwischen weitergeschrieben.
  try {
    const offen = JSON.parse(sessionStorage.getItem(LAUF) || 'null');
    if (offen?.lauf && Date.now() - offen.beginn < 15 * 60 * 1000) {
      const antwort = blase('ki');
      nachUnten();
      kiBibliotheken().catch(() => {});
      antwortFolgen({ lauf: offen.lauf, frage: offen.frage, beginn: offen.beginn, antwort, holen: null });
    } else {
      laufMerken(null);
    }
  } catch (e) { /* nichts fortzusetzen */ }
}

// Antwort während des Schreibens anzeigen, ohne zu ruckeln:
//  - höchstens einmal pro Bild neu zeichnen (requestAnimationFrame), statt bei jedem Wortstück;
//  - der Text läuft gleichmäßig nach, statt im Takt des Modells in Brocken zu erscheinen;
//  - fertige Absätze bleiben stehen, nur der letzte, unfertige wird neu gezeichnet;
//  - Code wird erst eingefärbt, wenn sein Block fertig ist;
//  - mitlaufen (kiChat entscheidet: wer selbst hochgescrollt hat, wird nicht zurückgezogen).
function kiStromAnzeige(huelle, mitlaufen) {
  const text = document.createElement('div');
  text.className = 'fundus-ki-text';
  let roh = '';
  let quellen = [];
  let gezeigt = 0;
  let fertige = 0;
  let letzter = null;
  let geplant = 0;
  let aus = false;

  const zeichnen = () => {
    geplant = 0;
    if (aus || !roh) return;
    // Vor die Statuszeile, die bis zum Ende darunter stehen bleibt.
    if (!text.isConnected) huelle.prepend(text);
    const rest = roh.length - gezeigt;
    if (rest > 0) gezeigt += Math.max(3, Math.ceil(rest / 6));
    const bloecke = kiBloecke(roh.slice(0, gezeigt));
    if (!letzter) {
      letzter = document.createElement('div');
      letzter.className = 'fundus-ki-block';
      text.append(letzter);
    }
    while (fertige < bloecke.length - 1) {
      const block = document.createElement('div');
      block.className = 'fundus-ki-block';
      block.innerHTML = kiRender(bloecke[fertige], quellen);
      text.insertBefore(block, letzter);
      fertige++;
    }
    // Eine eben begonnene Zeile aus nur „1.“ oder „-“ erschiene kurz als nackte „1“; sie wartet auf ihren Text.
    const unfertig = bloecke[bloecke.length - 1].replace(/\n?[ \t]*(\d+\.?|[-*+])[ \t]*$/, '');
    letzter.innerHTML = kiRender(unfertig, quellen, { hervorheben: false });
    mitlaufen();
    if (gezeigt < roh.length) planen();
  };
  const planen = () => { if (!geplant && !aus) geplant = requestAnimationFrame(zeichnen); };

  return {
    setzen(stand) {
      if (stand.quellen?.length) quellen = stand.quellen;
      if (stand.text && stand.text.length > roh.length) { roh = stand.text; planen(); }
    },
    beenden() { aus = true; if (geplant) cancelAnimationFrame(geplant); },
  };
}

// Markdown in Absätze teilen, an Leerzeilen außerhalb von Codeblöcken. Der letzte Teil ist der unfertige.
function kiBloecke(md) {
  const bloecke = [];
  let teil = [];
  let imCode = false;
  for (const zeile of md.split('\n')) {
    if (/^\s*(```|~~~)/.test(zeile)) imCode = !imCode;
    if (!imCode && zeile.trim() === '') {
      if (teil.length) { bloecke.push(teil.join('\n')); teil = []; }
      continue;
    }
    teil.push(zeile);
  }
  bloecke.push(teil.join('\n'));
  return bloecke;
}

const KI_SYMBOL = {
  kopieren: '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>',
  haken: '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
  nochmal: '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 21v-5h5"/></svg>',
};

// Markdown, Bereinigung und Hervorhebung für den Chat werden erst beim ersten Bedarf geladen.
let kiBibliothekenLaden = null;
function kiBibliotheken() {
  if (window.marked && window.DOMPurify && window.hljs) return Promise.resolve();
  if (!kiBibliothekenLaden) {
    kiBibliothekenLaden = new Promise((fertig, fehler) => {
      const skript = document.createElement('script');
      skript.src = document.querySelector('[data-fundus-ki]')?.dataset.bibliotheken || '';
      const nonce = document.querySelector('script[nonce]')?.nonce;
      if (nonce) skript.nonce = nonce;
      skript.onload = fertig;
      skript.onerror = fehler;
      document.head.append(skript);
    });
  }
  return kiBibliothekenLaden;
}

// Antwort des Modells als sicheres HTML: marked (Markdown) → DOMPurify (nur harmlose Elemente)
// → Quellenverweise [n] verlinken → Codeblöcke einfärben und mit Kopfzeile versehen.
function kiRender(roh, quellen, { hervorheben = true } = {}) {
  if (!window.marked || !window.DOMPurify) {
    return `<p>${kiEscape(roh).replace(/\n/g, '<br>')}</p>`;
  }
  let md = roh;
  // Während des Streamens ist ein Codeblock oft noch offen; kurz schließen, damit nichts springt.
  if ((md.match(/^\s*```/gm) || []).length % 2) md += '\n```';
  const html = window.DOMPurify.sanitize(window.marked.parse(md, { gfm: true, breaks: true }), {
    FORBID_TAGS: ['style', 'img', 'iframe', 'form', 'input', 'button', 'video', 'audio'],
    FORBID_ATTR: ['style'],
  });
  const box = document.createElement('div');
  box.innerHTML = html;

  box.querySelectorAll('a[href]').forEach((a) => {
    if (a.host !== location.host) { a.target = '_blank'; a.rel = 'noopener'; }
  });

  // [n] nur im Fließtext ersetzen, nicht in Code.
  const laeufer = document.createTreeWalker(box, NodeFilter.SHOW_TEXT, {
    acceptNode: (k) => (k.parentElement.closest('code, pre, a') || !/\[\d{1,2}\]/.test(k.nodeValue) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
  });
  const knoten = [];
  while (laeufer.nextNode()) knoten.push(laeufer.currentNode);
  for (const k of knoten) {
    const teile = k.nodeValue.split(/(\[\d{1,2}\])/);
    const frag = document.createDocumentFragment();
    for (const teil of teile) {
      const m = teil.match(/^\[(\d{1,2})\]$/);
      const q = m && quellen[Number(m[1]) - 1];
      if (!q) { frag.append(teil); continue; }
      const a = document.createElement('a');
      a.className = 'fundus-ki-ref';
      a.href = q.url;
      a.title = `${q.titel} · ${q.abschnitt}`;
      a.dataset.fundusStelle = q.stelle || '';
      a.dataset.fundusAbschnitt = q.abschnitt || '';
      a.textContent = m[1];
      frag.append(a);
    }
    k.replaceWith(frag);
  }

  box.querySelectorAll('pre > code').forEach((code) => {
    const kurz = (code.className.match(/language-([\w#+-]+)/) || [])[1] || '';
    if (window.hljs && hervorheben) {
      if (kurz && window.hljs.getLanguage(kurz)) {
        window.hljs.highlightElement(code);
      } else {
        const ergebnis = window.hljs.highlightAuto(code.textContent);
        code.innerHTML = ergebnis.value;
        code.classList.add('hljs');
      }
    }
    const huelle = document.createElement('div');
    huelle.className = 'fundus-ki-code';
    huelle.innerHTML = `<div class="fundus-ki-code-kopf"><span>${kiEscape(codeSprache(kurz))}</span>`
      + `<button type="button" data-aktion="code-kopieren" title="Code kopieren">${KI_SYMBOL.kopieren}<b>Kopieren</b></button></div>`;
    const pre = code.parentElement;
    pre.replaceWith(huelle);
    huelle.append(pre);
  });

  return box.innerHTML;
}

// Quellen unter einer Antwort: je Artikel eine Zeile, aufklappbar mit den wörtlichen Ausschnitten.
// Zitiert das Modell keine Nummer, stehen trotzdem die gefundenen Artikel da.
function kiQuellenliste(roh, quellen) {
  const genutzt = new Set([...roh.matchAll(/\[(\d{1,2})\]/g)].map((m) => Number(m[1])));
  const zitiert = quellen.some((_, i) => genutzt.has(i + 1));
  const artikel = new Map();
  quellen.forEach((q, i) => {
    if (zitiert && !genutzt.has(i + 1)) return;
    const eintrag = artikel.get(q.url) || { titel: q.titel, thema: q.thema, url: q.url, stuecke: [] };
    eintrag.stuecke.push({ ...q, n: i + 1 });
    artikel.set(q.url, eintrag);
  });
  const box = document.createElement('div');
  box.className = 'fundus-ki-quellen';
  if (!artikel.size) return box;
  box.innerHTML = `<div class="fundus-ki-quellen-titel">${zitiert ? 'Quellen' : 'Gefunden in'}</div>`
    + [...artikel.values()].map((a) => `
    <div class="fundus-ki-quelle">
      <button type="button" data-fundus-zitat aria-expanded="false">
        <span class="nr">${a.stuecke.map((s) => s.n).join(', ')}</span>
        <span class="text"><span class="titel">${kiEscape(a.titel)}</span><span class="thema">${kiEscape(a.thema)}</span></span>
        <svg class="pfeil" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
      </button>
      <div class="fundus-ki-zitate">
        ${a.stuecke.map((s) => `
          <div class="fundus-ki-zitat">
            <div class="abschnitt">${kiEscape(s.abschnitt)}</div>
            <p>${kiEscape(s.auszug || '')}</p>
            <a href="${kiEscape(a.url)}" data-fundus-stelle="${kiEscape(s.stelle || '')}" data-fundus-abschnitt="${kiEscape(s.abschnitt)}">Im Artikel ansehen</a>
          </div>`).join('')}
      </div>
    </div>`).join('');
  return box;
}

// Nach dem Klick auf eine Quelle: den ganzen verwendeten Abschnitt im Artikel hervorheben,
// von seiner Überschrift bis zur nächsten gleichrangigen Überschrift.
function kiFundstelleMarkieren() {
  let ziel;
  try {
    ziel = JSON.parse(sessionStorage.getItem('fundus-ki-stelle') || 'null');
    sessionStorage.removeItem('fundus-ki-stelle');
  } catch (e) { return; }
  const wurzel = document.querySelector('.page-content > div');
  if (!ziel || !wurzel || decodeURI(location.pathname) !== decodeURI(ziel.pfad)) return;

  const glatt = (t) => (t || '').replace(/\s+/g, ' ').trim().toLowerCase();
  const kinder = [...wurzel.children].filter((k) => k.id !== 'bkmrk-page-title' && !k.matches('[data-fundus-artikelkopf], [style*="clear"]'));
  const istTitel = (el) => /^H[1-6]$/.test(el.tagName);
  const stelle = glatt(ziel.stelle).slice(0, 60);

  let anfang = kinder.findIndex((k) => istTitel(k) && glatt(k.textContent) === glatt(ziel.abschnitt));
  if (anfang < 0 && stelle) {
    const treffer = kinder.findIndex((k) => glatt(k.textContent).includes(stelle));
    if (treffer >= 0) {
      anfang = treffer;
      // Text aus Anhängen und Bildern (eingeklappter Block am Ende): aufklappen und nur ihn markieren.
      if (kinder[treffer].matches('details')) kinder[treffer].open = true;
      else while (anfang > 0 && !istTitel(kinder[anfang])) anfang--;
    }
  }
  if (anfang < 0) return;
  const ebene = istTitel(kinder[anfang]) ? Number(kinder[anfang].tagName[1]) : 7;
  let ende = anfang + 1;
  while (ende < kinder.length && !(istTitel(kinder[ende]) && Number(kinder[ende].tagName[1]) <= ebene)) ende++;

  const rahmen = document.createElement('div');
  // Schlicht: Der Abschnitt leuchtet kurz auf, hinter der Überschrift steht eine kleine Plakette.
  // Kein Kasten und keine Linie, damit Hinweiskästen, Tabellen und Code darin unverändert bleiben.
  rahmen.className = 'fundus-ki-fundstelle';
  const plakette = document.createElement('span');
  plakette.className = 'fundus-ki-quelle-plakette';
  plakette.innerHTML = '<span class="fundus-monogramm" aria-hidden="true">F</span>'
    + '<span>Quelle von Fundus</span>'
    + '<button type="button" aria-label="Markierung entfernen"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M7 7l10 10M17 7L7 17"/></svg></button>';
  kinder[anfang].before(rahmen);
  kinder.slice(anfang, ende).forEach((k) => rahmen.append(k));
  (istTitel(kinder[anfang]) ? kinder[anfang] : rahmen).append(plakette);
  if (!istTitel(kinder[anfang])) rahmen.prepend(plakette);
  plakette.querySelector('button').addEventListener('click', () => plakette.remove());
  setTimeout(() => rahmen.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150);
}

// Gespräche mit Fundus liegen nur im Browser. Beim Abmelden und auf der Anmeldeseite (z. B. nach
// abgelaufener Sitzung) werden sie gelöscht, damit am geteilten Rechner niemand fremde Gespräche sieht.
function kiGespraecheAufraeumen() {
  const loeschen = () => {
    try {
      Object.keys(localStorage).filter((k) => k.startsWith('fundus-ki-archiv-')).forEach((k) => localStorage.removeItem(k));
      sessionStorage.removeItem('fundus-ki');
      sessionStorage.removeItem('fundus-ki-stelle');
    } catch (e) { /* Speicher gesperrt: nichts zu löschen */ }
  };
  if (document.querySelector('form[action$="/login"], form[action*="/oidc/login"]') && !document.querySelector('[data-fundus-ki]')) {
    loeschen();
  }
  document.addEventListener('submit', (e) => {
    if (e.target.matches?.('form[action$="/logout"]')) loeschen();
  }, true);
}

// Benachrichtigungen durch Fundus (Baustein fundus/hinweise, Server hinweise/Hinweise.php).
// Nur Neues wird gemeldet: eine Sprechblase über dem Fundus-Knopf, aufgeklappt die Einträge.
// „Alles gesehen“ merkt sich der Server; „Später“ blendet sie nur für diese Sitzung aus.
// Was gesehen, aber nicht erledigt ist, bleibt auf der Startseite (Kästen „Offene Hinweise“, „Zu prüfen“).
async function hinweise() {
  const halter = document.querySelector('[data-fundus-hinweise]');
  if (!halter) return;
  let daten;
  try {
    const antwort = await fetch(halter.dataset.adresse, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!antwort.ok) return;
    daten = await antwort.json();
  } catch (e) {
    return;
  }
  const neu = daten.eintraege.filter((e) => e.neu);
  if (!neu.length) return;

  const SPAETER = 'fundus-hinweise-spaeter';
  const fingerabdruck = neu.map((e) => e.schluessel).sort().join('|');
  try { if (sessionStorage.getItem(SPAETER) === fingerabdruck) return; } catch (e) { /* ohne Speicher immer zeigen */ }

  const token = document.querySelector('meta[name="token"]')?.content || '';
  const alsGesehen = (schluessel) => fetch(halter.dataset.gesehen, {
    method: 'POST',
    credentials: 'same-origin',
    keepalive: true,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
    body: JSON.stringify({ schluessel }),
  }).catch(() => {});

  // Mit KI hängt die Zahl am Knopf „Frag Fundus“; ohne KI bekommt der Halter einen eigenen runden Knopf.
  const kiKnopf = document.querySelector('[data-fundus-ki-knopf]');
  const symbol = { rueckmeldung: HINWEIS_SYMBOLE.flagge, pruefung: HINWEIS_SYMBOLE.uhr, sicherung: HINWEIS_SYMBOLE.sicherung };
  const ueberschrift = neu.length === 1 ? '1 neuer Hinweis' : `${neu.length} neue Hinweise`;
  const titel = [...new Set(neu.map((e) => e.titel))].join(' · ');
  halter.innerHTML = `
    <div class="fundus-hinweise-karte" id="fundus-hinweise-karte" role="dialog" aria-labelledby="fundus-hinweise-titel" hidden>
      <div class="kopf">
        <span class="fundus-monogramm" aria-hidden="true">F</span>
        <div>
          <strong id="fundus-hinweise-titel">Hallo ${kiEscape(daten.vorname)}, das steht für dich an</strong>
          <span>Fundus · ${ueberschrift}</span>
        </div>
      </div>
      <ul>${neu.map((e) => `
        <li class="${e.art}${e.dringend ? ' dringend' : ''}">
          <span class="symbol" aria-hidden="true">${symbol[e.art] || HINWEIS_SYMBOLE.flagge}</span>
          <a href="${kiEscape(e.adresse)}" data-schluessel="${kiEscape(e.schluessel)}">
            <strong>${kiEscape(e.titel)}</strong>
            <span>${kiEscape(e.text)}</span>
          </a>
        </li>`).join('')}
      </ul>
      <div class="fuss">
        <button type="button" data-spaeter>Später</button>
        <button type="button" class="voll" data-alles-gesehen>Alles gesehen</button>
      </div>
    </div>
    <div class="fundus-hinweise-nachricht" role="status">
      <button type="button" class="oeffnen" aria-expanded="false" aria-controls="fundus-hinweise-karte">
        <strong>${ueberschrift}</strong>
        <span>${kiEscape(titel)}</span>
      </button>
      <button type="button" class="zu" data-spaeter aria-label="Später erinnern">
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>
    ${kiKnopf ? '' : `<button type="button" class="fundus-ki-knopf fundus-hinweise-knopf" aria-label="Hinweise von Fundus" aria-controls="fundus-hinweise-karte">
      <span class="fundus-monogramm" aria-hidden="true">F</span><span class="text">Hinweise</span></button>`}`;
  halter.hidden = false;
  const knopf = kiKnopf || halter.querySelector('.fundus-hinweise-knopf');
  knopf.dataset.anzahl = String(neu.length);

  const karte = halter.querySelector('.fundus-hinweise-karte');
  const oeffnen = halter.querySelector('.oeffnen');
  const umschalten = (auf) => {
    karte.hidden = !auf;
    oeffnen.setAttribute('aria-expanded', String(auf));
    halter.classList.toggle('offen', auf);
  };
  const schliessen = () => {
    halter.hidden = true;
    delete knopf.dataset.anzahl;
  };
  oeffnen.addEventListener('click', () => umschalten(karte.hidden));
  if (!kiKnopf) knopf.addEventListener('click', () => umschalten(karte.hidden));
  halter.querySelector('[data-alles-gesehen]').addEventListener('click', () => {
    alsGesehen(neu.map((e) => e.schluessel));
    schliessen();
  });
  halter.querySelectorAll('[data-spaeter]').forEach((b) => b.addEventListener('click', () => {
    try { sessionStorage.setItem(SPAETER, fingerabdruck); } catch (e) { /* ohne Speicher */ }
    schliessen();
  }));
  // Wer einem Eintrag folgt, hat ihn gesehen; keepalive trägt die Anfrage über den Seitenwechsel.
  halter.querySelectorAll('[data-schluessel]').forEach((a) => a.addEventListener('click', () => alsGesehen([a.dataset.schluessel])));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !karte.hidden) umschalten(false); });
  document.addEventListener('click', (e) => {
    if (!karte.hidden && !karte.contains(e.target) && !oeffnen.contains(e.target) && !knopf.contains(e.target)) umschalten(false);
  });
}

const HINWEIS_SYMBOLE = {
  flagge: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22V4a1 1 0 0 1 .4-.8A6 6 0 0 1 8 2c3 0 5 2 8 2a6 6 0 0 0 3.6-1.2 1 1 0 0 1 1.4.9v10a1 1 0 0 1-.4.8A6 6 0 0 1 17 16c-3 0-5-2-8-2a8 8 0 0 0-5 2"/></svg>',
  uhr: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
  // Datenbank-Zylinder für Hinweise zur Sicherung.
  sicherung: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>',
};

// ============================================================================
// 8. Hilfsfunktionen
// ============================================================================

// HTML maskieren. Heißt aus historischen Gründen ki…, wird aber überall genutzt.
function kiEscape(t) {
  return String(t).replace(/[&<>"']/g, (z) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[z]));
}

// localStorage kann gesperrt sein (z. B. privates Fenster); dann eben ohne Merken.
function merken(schluessel, wert) {
  try {
    if (wert === undefined) return localStorage.getItem(schluessel);
    localStorage.setItem(schluessel, wert);
  } catch (e) {
    return null;
  }
}
