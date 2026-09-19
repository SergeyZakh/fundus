// Welche Bilder das Handbuch braucht: Seite, Person, Vorbereitung, Markierungen, Ausschnitt.
// person: 1 = Admin, 3 = Mia (Mitarbeiterin). Markierungen: nr passt zur nummerierten Liste im Artikel.
// seite: wo die Nummer am Rahmen sitzt (links-oben, rechts-oben, links-unten, rechts-unten).
//
// Die Chat-Bilder brauchen ein Beispielgespräch. Es steht nur im Browser-Speicher der Aufnahme,
// nicht im Wiki.
const beispielQuellen = [
  { titel: 'Freigegebenes Postfach einrichten', abschnitt: 'Schritte', thema: 'Microsoft 365', url: 'http://localhost:6875/books/microsoft-365/page/freigegebenes-postfach-einrichten', auszug: 'Im Exchange Admin Center → Empfänger → Postfächer auf „Freigegebenes Postfach hinzufügen“ klicken. Anzeigenamen und Adresse eintragen, speichern.', stelle: 'Im Exchange Admin Center' },
  { titel: 'Weiterleitung für ein Postfach einrichten', abschnitt: 'Schritte', thema: 'Microsoft 365', url: 'http://localhost:6875/books/microsoft-365/page/weiterleitung-fur-ein-postfach-einrichten', auszug: 'Im Exchange Admin Center das Postfach öffnen, E-Mail-Fluss → Weiterleitung verwalten.', stelle: '' },
];
const beispielGespraech = [
  { rolle: 'nutzer', text: 'Wie richte ich ein freigegebenes Postfach ein?' },
  {
    rolle: 'ki',
    text: 'So legst du ein freigegebenes Postfach an:\n\n1. Im **Exchange Admin Center** unter *Empfänger → Postfächer* auf **Freigegebenes Postfach hinzufügen** klicken [1].\n2. Anzeigenamen und Adresse eintragen und speichern [1].\n3. Unter **Mitglieder** die Personen hinzufügen, die das Postfach nutzen [1].\n\nSoll die Post zusätzlich an eine andere Adresse gehen, richtest du danach eine Weiterleitung ein [2].',
    quellen: beispielQuellen,
    frage: 'Wie richte ich ein freigegebenes Postfach ein?',
    dauer: 6,
  },
];
const chatSpeicher = (gross) => `sessionStorage.setItem('fundus-ki', ${JSON.stringify(JSON.stringify({ offen: true, gross, chatId: 'beispiel', nachrichten: beispielGespraech }))});`
  + `localStorage.setItem('fundus-ki-archiv-mia-mitarbeiter', ${JSON.stringify(JSON.stringify([
    { id: 'beispiel', titel: 'Wie richte ich ein freigegebenes Postfach ein?', zeit: Date.now() - 5 * 60e3, nachrichten: beispielGespraech },
    { id: 'b2', titel: 'Was tun, wenn beim Kunden das Internet ausfällt?', zeit: Date.now() - 26 * 3600e3, nachrichten: [] },
    { id: 'b3', titel: 'MFA für ein Konto zurücksetzen', zeit: Date.now() - 4 * 86400e3, nachrichten: [] },
  ]))});`;
// Chat-Bilder: Markdown, Bereinigung und Hervorhebung vor wiki.js laden (die Route dafür braucht eine Anmeldung).
export const CHAT_SKRIPTE = ['marked.umd.js', 'purify.min.js', 'highlight.min.js']
  .map((d) => new URL(`../../theme/fundus/ki/vendor/${d}`, import.meta.url).href);

export const BILDER = [
  // ---------- Lesen und finden ----------
  {
    name: 'startseite', adresse: '/', person: 3, breite: 1440, hoehe: 900,
    marken: [
      { nr: 1, ziel: '.fundus-grosse-suche', seite: 'links-oben' },
      { nr: 2, ziel: '.fundus-aktivitaet', seite: 'links-oben' },
      { nr: 3, ziel: '.fundus-start-regale > .fundus-regal:first-child', seite: 'links-oben' },
      { nr: 4, ziel: '.fundus-start-listen', seite: 'rechts-oben' },
    ],
  },
  {
    name: 'suche-strg-k', adresse: '/books/microsoft-365/page/freigegebenes-postfach-einrichten', person: 3, breite: 1200, hoehe: 760,
    // Treffer aus der echten Suchseite in den Dialog setzen (fetch geht in der Aufnahme nicht).
    vorbereiten: `
      const dialog = document.querySelector('[data-fundus-suche]');
      dialog.showModal();
      dialog.querySelector('input').value = 'postfach';
      const html = new DOMParser().parseFromString(window.__suche, 'text/html');
      const treffer = [...html.querySelectorAll('.book-contents a.entity-list-item')].slice(0, 3);
      treffer.forEach((a) => a.querySelectorAll('.entity-item-tags').forEach((t) => t.remove()));
      const liste = dialog.querySelector('.fundus-suche-treffer');
      liste.replaceChildren(...treffer);
      liste.querySelector('a')?.classList.add('fundus-gewaehlt');
    `,
    suche: '/search?term=postfach',
    marken: [
      { nr: 1, ziel: '.fundus-suche-feld', seite: 'links-oben' },
      { nr: 2, ziel: '.fundus-suche-treffer a.fundus-gewaehlt', seite: 'links-oben' },
      { nr: 3, ziel: '.fundus-suche-fuss', seite: 'links-unten' },
    ],
    ausschnitt: '.fundus-suche', rand: 40,
  },
  {
    name: 'artikel-aufbau', adresse: '/books/microsoft-365/page/freigegebenes-postfach-einrichten', person: 3, breite: 1440, hoehe: 820,
    marken: [
      { nr: 1, ziel: '.fundus-aktionsleiste .breadcrumbs', seite: 'links-oben' },
      { nr: 2, ziel: '.fundus-aktionen', seite: 'rechts-oben' },
      { nr: 3, ziel: '.fundus-artikelkopf', seite: 'links-oben' },
      { nr: 4, ziel: '#book-tree', seite: 'links-oben' },
      { nr: 5, ziel: '#page-navigation', seite: 'links-oben' },
      { nr: 6, ziel: '#page-details', seite: 'links-oben' },
    ],
  },
  {
    name: 'aktionsleiste', adresse: '/books/microsoft-365/page/freigegebenes-postfach-einrichten', person: 1, breite: 1440, hoehe: 700,
    vorbereiten: `document.querySelector('.fundus-mehr-menue > button').click();`,
    marken: [
      // Auf Artikelseiten ist Bearbeiten ein Symbol in der Gruppe; eine schwarze Hauptaktion gibt es nur zum Anlegen.
      { nr: 1, ziel: '.fundus-aktionen-gruppe [data-shortcut="edit"]', seite: 'links-unten', abstand: 2 },
      { nr: 2, ziel: '.fundus-aktionen-gruppe [data-shortcut="revisions"]', seite: 'links-unten', abstand: 2 },
      { nr: 3, ziel: '.fundus-aktionen-gruppe form[action$="/watching/update"] button', seite: 'links-unten', abstand: 2 },
      { nr: 4, ziel: '.fundus-aktionen-gruppe [data-shortcut="favourite"]', seite: 'links-unten', abstand: 2 },
      { nr: 5, ziel: '.fundus-aktionen-gruppe #export-menu', seite: 'links-unten', abstand: 2 },
      { nr: 6, ziel: '.fundus-mehr-liste', seite: 'links-unten' },
    ],
    ausschnitt: '.fundus-aktionen, .fundus-mehr-liste, .fundus-aktionsleiste .breadcrumbs', rand: 30,
  },
  {
    name: 'leisten-knopf', adresse: '/books/microsoft-365/page/freigegebenes-postfach-einrichten', person: 3, breite: 1440, hoehe: 400,
    marken: [{ nr: 1, ziel: '[data-fundus-leisten-knopf]', seite: 'links-unten', abstand: 3 }],
    ausschnitt: 'header .links, header .user-name, [data-fundus-leisten-knopf]', rand: 10,
  },
  {
    name: 'rueckmeldung', adresse: '/books/microsoft-365/page/freigegebenes-postfach-einrichten', person: 3, breite: 1440, hoehe: 900,
    vorbereiten: `document.querySelector('[data-fundus-rueckmeldung]').scrollIntoView({ block: 'center' });`,
    marken: [
      { nr: 1, ziel: '.fundus-rueckmeldung-knoepfe', seite: 'links-oben' },
      { nr: 2, ziel: '.fundus-rueckmeldung-melden', seite: 'rechts-oben' },
      { nr: 3, ziel: '#sibling-navigation', seite: 'links-unten' },
    ],
    ausschnitt: '.fundus-rueckmeldung, #sibling-navigation', rand: 30,
  },
  {
    name: 'fundus', adresse: '/books/microsoft-365/page/freigegebenes-postfach-einrichten', person: 3, breite: 1440, hoehe: 880,
    speicher: chatSpeicher(false), chat: true, warten: 2200,
    vorbereiten: `document.querySelector('[data-fundus-ki-knopf]').click(); document.querySelector('[data-fundus-ki-knopf]').click();
      document.querySelector('.fundus-ki-quelle > button')?.click();`,
    marken: [
      { nr: 1, ziel: '.fundus-ki-feld', seite: 'links-oben' },
      { nr: 2, ziel: '.fundus-ki-ref', seite: 'rechts-oben', abstand: 3 },
      { nr: 3, ziel: '.fundus-ki-quellen', seite: 'links-oben' },
      { nr: 4, ziel: '[data-fundus-ki-gross]', seite: 'links-unten', abstand: 2 },
    ],
    ausschnitt: '.fundus-ki', rand: 14,
  },
  {
    name: 'fundus-vollbild', adresse: '/books/microsoft-365/page/freigegebenes-postfach-einrichten', person: 3, breite: 1440, hoehe: 820,
    speicher: chatSpeicher(true), chat: true, warten: 2200,
    vorbereiten: `document.querySelector('[data-fundus-ki-knopf]').click(); document.querySelector('[data-fundus-ki-knopf]').click();`,
    marken: [
      { nr: 1, ziel: '.fundus-ki-archiv', seite: 'rechts-oben', abstand: -8 },
      { nr: 2, ziel: '.fundus-ki-mitte .fundus-ki-verlauf', seite: 'links-oben', abstand: -8 },
      { nr: 3, ziel: '.fundus-ki-fundus', seite: 'links-oben', abstand: -8 },
    ],
  },
  {
    name: 'fundstelle', adresse: '/books/microsoft-365/page/freigegebenes-postfach-einrichten', person: 3, breite: 1440, hoehe: 900,
    speicher: `sessionStorage.setItem('fundus-ki-stelle', JSON.stringify({ pfad: location.pathname, stelle: '', abschnitt: 'Schritte' }));`,
    vorbereiten: `window.scrollTo(0, 0); await new Promise((ok) => setTimeout(ok, 900));`,
    marken: [],
    ausschnitt: '.fundus-ki-fundstelle-label, .fundus-ki-fundstelle > h2, .fundus-ki-fundstelle > ol, .fundus-ki-fundstelle > .callout', rand: 36,
  },
  {
    // Titel kommen aus dem Wiki (Personen → Titel); im Aufnahme-Wiki brauchen Mia und der Admin einen.
    // Die Übersicht eines Themas zeigt keine Person mehr, deshalb der Name im Artikelkopf.
    name: 'personen-titel', adresse: '/books/microsoft-365/page/freigegebenes-postfach-einrichten', person: 3, breite: 1440, hoehe: 900,
    marken: [
      { nr: 1, ziel: 'header .user-name', seite: 'links-unten' },
      { nr: 2, ziel: '.fundus-artikelkopf [data-fundus-person]', seite: 'rechts-oben', abstand: 3 },
    ],
    ausschnitt: 'header .user-name, .fundus-artikelkopf, .page-content h1', rand: 24,
  },

  // ---------- Schreiben ----------
  {
    name: 'thema-neuer-artikel', adresse: '/books/microsoft-365', person: 3, breite: 1440, hoehe: 820,
    marken: [
      { nr: 1, ziel: '.fundus-aktion.haupt', seite: 'links-oben' },
      { nr: 2, ziel: '.book-contents > .chapter.entity-list-item', seite: 'links-oben' },
    ],
    ausschnitt: '.fundus-aktionsleiste, main.content-wrap', rand: 24,
  },
  {
    name: 'versionen', adresse: '/books/microsoft-365/page/freigegebenes-postfach-einrichten/revisions', person: 1, breite: 1300, hoehe: 700,
    marken: [
      { nr: 1, ziel: 'main.content-wrap table tbody tr:last-child td:nth-child(4), main.content-wrap .item-list-row:last-child > div:nth-child(4)', seite: 'links-oben' },
      { nr: 2, ziel: 'main.content-wrap .item-list-row:last-child .actions', seite: 'rechts-oben' },
    ],
    ausschnitt: 'main.content-wrap', rand: 10,
  },

  // ---------- Für die Redaktion ----------
  {
    name: 'thema-kopieren', adresse: '/books/musterkunde-gmbh/copy', person: 1, breite: 1300, hoehe: 900,
    marken: [
      { nr: 1, ziel: '.content-wrap input[name="name"]', seite: 'links-oben' },
      { nr: 2, ziel: '.content-wrap button.button:not(.outline)', seite: 'rechts-oben' },
    ],
    ausschnitt: '.content-wrap', rand: 10,
  },
  {
    name: 'thema-rechte', adresse: '/books/musterkunde-gmbh/permissions', person: 1, breite: 1300, hoehe: 900,
    marken: [{ nr: 1, ziel: 'select[name="role_id"], [refs*="role-select"], .content-wrap select', seite: 'links-oben' }],
    ausschnitt: 'main.content-wrap', rand: 10,
  },
  {
    name: 'thema-schlagwort-symbol', adresse: '/books/netzwerk/edit', person: 1, breite: 1300, hoehe: 1500,
    vorbereiten: `
      const knopf = [...document.querySelectorAll('.content-wrap button')].find((b) => /Schlagwörter/.test(b.textContent));
      if (knopf && knopf.getAttribute('aria-expanded') !== 'true') knopf.click();
      await new Promise((ok) => setTimeout(ok, 500));
      window.scrollTo(0, 0);`,
    marken: [{ nr: 1, ziel: '.content-wrap input[name^="tags"][name$="[name]"]', seite: 'links-oben' }],
    ausschnitt: '.content-wrap', rand: 10,
  },
  {
    name: 'rueckmeldungen-details', adresse: '/books/microsoft-365/page/freigegebenes-postfach-einrichten', person: 1, breite: 1440, hoehe: 900,
    beispielRueckmeldung: true,
    marken: [
      { nr: 1, ziel: '.fundus-rueckmeldung-auswertung .zahlen', seite: 'links-oben' },
      { nr: 2, ziel: '.fundus-rueckmeldung-auswertung .hinweise li', seite: 'links-oben' },
      { nr: 3, ziel: '.fundus-rueckmeldung-auswertung [data-erledigt]', seite: 'rechts-unten', abstand: 2 },
    ],
    ausschnitt: '#page-details, .fundus-rueckmeldung-auswertung', rand: 20,
  },
  {
    name: 'personen-rollen', adresse: '/settings/roles', person: 1, breite: 1300, hoehe: 900,
    marken: [],
    ausschnitt: 'main.content-wrap, .container .card', rand: 10,
  },
];
