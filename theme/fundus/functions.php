<?php

use FundusKi\IndexCommand;
use FundusKi\IndexJob;
use FundusKi\Ki;
use BookStack\Activity\ActivityType;
use BookStack\Entities\Models\Page;
use BookStack\Facades\Theme;
use BookStack\Theming\ThemeEvents;
use BookStack\Theming\ThemeViews;
use FundusDateitext\Dateitext;
use FundusDateitext\DateitextCommand;
use BookStack\Uploads\Attachment;
use BookStack\Uploads\Image;
use Illuminate\Routing\Router;

require_once __DIR__ . '/aktivitaet/Aktivitaet.php';
require_once __DIR__ . '/anmeldung/Anmeldung.php';
require_once __DIR__ . '/dateitext/Dateitext.php';
require_once __DIR__ . '/hinweise/Hinweise.php';
require_once __DIR__ . '/ki/Ki.php';
require_once __DIR__ . '/pruefung/Pruefung.php';
require_once __DIR__ . '/rueckmeldung/Rueckmeldung.php';

/*
 * Einstieg des Themes „fundus“ für Fundus. BookStack lädt diese Datei bei jeder Anfrage.
 *
 * Inhalt
 *   1. Bausteine (Views)   an welcher Stelle welcher Baustein aus fundus/ erscheint
 *   2. Routen              KI-Chat, Rückmeldungen, gelesene Artikel, Prüfung, Hinweise
 *   3. Ereignisse          KI-Index nach dem Speichern aktuell halten, Text aus Anhängen anhängen
 *   4. Befehle             php artisan fundus:ki-index, fundus:datei-text
 *
 * Achtung: Änderungen hier sind sofort live. Ein halb fertiger Stand wirft im laufenden Wiki Fehler.
 */

/*
 * 0. App-Icon: „F“ auf Schwarz (erzeugt mit skripte/icon-bauen.mjs). BookStack nimmt diese Vorgabe
 * für Favicon, PWA-Manifest und OpenSearch, solange unter Einstellungen → Anpassung kein Icon hochgeladen ist.
 */
$iconBasis = rtrim((string) config('app.url'), '/') . '/theme/fundus/';
config([
    'setting-defaults.app-icon' => $iconBasis . 'icon.png',
    'setting-defaults.app-icon-180' => $iconBasis . 'icon-180.png',
    'setting-defaults.app-icon-128' => $iconBasis . 'icon-128.png',
    'setting-defaults.app-icon-64' => $iconBasis . 'icon-64.png',
    'setting-defaults.app-icon-32' => $iconBasis . 'icon-32.png',
    'setting-defaults.app-name' => 'Fundus',
]);

// Startseite des Themes (fundus/start: Begrüßung, Aktivitätsraster, Offene Hinweise) hängt an der Liste
// der Bereiche. Ein frisches Wiki zeigt sonst BookStacks eigenes Dashboard. Eine Vorgabe über
// setting-defaults greift hier nicht: BookStack fragt setting('app-homepage-type', 'default') mit eigenem
// Ersatzwert ab. Deshalb einmal speichern, solange nichts gespeichert ist; eine spätere Wahl des Admins bleibt.
// Erst bei einer Web-Anfrage: Beim ersten Start (Migrationen) gibt es die Tabelle settings noch nicht.
Theme::listen(ThemeEvents::WEB_MIDDLEWARE_BEFORE, function () {
    if (setting()->get('app-homepage-type', 'fehlt') === 'fehlt') {
        setting()->put('app-homepage-type', 'bookshelves');
    }
    return null;
});

/*
 * 1. Fundus-Theme: hängt eigene Bausteine an vorhandene BookStack-Views an,
 * statt diese zu überschreiben. So bleibt das Original bei Updates erhalten;
 * nach einem Update reicht es zu prüfen, ob die Ziel-Views noch so heißen.
 * Die Bausteine liegen unter themes/fundus/fundus/.
 */
Theme::listen(ThemeEvents::THEME_REGISTER_VIEWS, function (ThemeViews $views) {
    // Eigene Schrift, Stylesheet und Skript in jeden <head>.
    $views->renderAfter('layouts.parts.custom-head', 'fundus.kopf');

    // Kopfleiste: ein Knopf für beide Seitenleisten, danach der Strg+K-Suchdialog.
    $views->renderBefore('layouts.parts.header-links', 'fundus.leisten-knopf');
    $views->renderAfter('layouts.parts.header', 'fundus.suche');
    // Berufstitel im Formular einer Person, nur für Admins (Einstellungen → Benutzer).
    $views->renderAfter('users.parts.form', 'fundus.titel-feld');

    // Seite: Artikelkopf mit Stand und Lesezeit direkt über dem Inhalt.
    $views->renderBefore('pages.parts.page-display', 'fundus.artikelkopf');
    // Unter dem Artikel: „War das hilfreich?“ und „Veraltet melden“; in den Details die Auswertung.
    $views->renderBefore('entities.sibling-navigation', 'fundus.rueckmeldung');
    $views->renderAfter('pages.parts.show-sidebar-section-details', 'fundus.pruefung-details');
    $views->renderAfter('pages.parts.show-sidebar-section-details', 'fundus.rueckmeldung-details');

    // Übersicht eines Themas oder Bereichs: Kennzahlen und zuletzt geänderter Artikel.
    $views->renderAfter('entities.breadcrumbs', 'fundus.uebersicht');
    // Listen aller Themen und Bereiche: Kennzahlen unter dem Titel.
    $views->renderBefore('books.parts.list', 'fundus.liste-kopf');
    $views->renderBefore('shelves.parts.list', 'fundus.liste-kopf');
    // … und rechts „Zuletzt geändert“ und „Aktiv“, statt einer leeren Spalte.
    $views->renderAfter('books.parts.index-sidebar-section-actions', 'fundus.liste-rechts');
    $views->renderAfter('shelves.parts.index-sidebar-section-actions', 'fundus.liste-rechts');
    // Themenansicht: Symbole für die Liste „Bereiche“ links.
    $views->renderAfter('books.parts.show-sidebar-section-shelves', 'fundus.symbole-json');

    // Kacheln von Regalen und Büchern: passendes Symbol aus dem Schlagwort „Symbol“.
    $views->renderAfter('entities.grid-item', 'fundus.symbol');

    // Startseite: Begrüßung mit Suche, Regale mit Büchern, Listen (ersetzt die Regalliste).
    $views->renderAfter('shelves.parts.list', 'fundus.start');

    // KI-Chatfenster auf allen Seiten, wenn OLLAMA_URL gesetzt ist.
    $views->renderAfter('layouts.parts.base-body-end', 'fundus.ki');
    // Benachrichtigungen durch Fundus auf allen Seiten, auch ohne KI.
    $views->renderAfter('layouts.parts.base-body-end', 'fundus.hinweise');
});

/*
 * 2. Eigene Routen: KI-Chat Fundus (theme/fundus/ki/Ki.php, nur aktiv mit OLLAMA_URL)
 * und Rückmeldungen (theme/fundus/rueckmeldung/Rueckmeldung.php).
 */
Theme::listen(ThemeEvents::ROUTES_REGISTER_WEB_AUTH, function (Router $router) {
    // Liegt in der Routengruppe für angemeldete Personen: Anmeldung und CSRF-Schutz gelten.
    $router->post('/fundus/ki', fn (\Illuminate\Http\Request $request) => Ki::aktiv() ? Ki::antworten($request) : abort(404));
    // Stand einer Antwort nach einem Seitenwechsel und Stopp von jeder Seite aus.
    $router->get('/fundus/ki/lauf/{lauf}', fn (string $lauf) => Ki::aktiv() ? Ki::lauf($lauf) : abort(404));
    $router->post('/fundus/ki/lauf/{lauf}/stopp', fn (string $lauf) => Ki::aktiv() ? Ki::stoppen($lauf) : abort(404));
    // Markdown, HTML-Bereinigung und Code-Hervorhebung für den Chat (theme/fundus/ki/vendor).
    // Über eine eigene Route, weil BookStack den Dateityp von Theme-Dateien aus dem Inhalt rät.
    $router->get('/fundus/ki/bibliotheken.js', fn () => Ki::bibliotheken());
    // Rückmeldungen zu Artikeln (theme/fundus/rueckmeldung).
    $router->post('/fundus/rueckmeldung', fn (\Illuminate\Http\Request $request) => \FundusRueckmeldung\Rueckmeldung::speichern($request));
    $router->post('/fundus/rueckmeldung/{id}/erledigt', fn (\Illuminate\Http\Request $request, int $id) => \FundusRueckmeldung\Rueckmeldung::erledigen($request, $id));

    // Gelesene Artikel für Aktivitätsraster und Serie (theme/fundus/aktivitaet).
    $router->post('/fundus/gelesen', fn (\Illuminate\Http\Request $request) => \FundusAktivitaet\Aktivitaet::gelesen($request));

    // Artikel als geprüft markieren (theme/fundus/pruefung).
    $router->post('/fundus/pruefung/{id}', fn (\Illuminate\Http\Request $request, int $id) => \FundusPruefung\Pruefung::pruefen($request, $id));

    // Benachrichtigungen durch Fundus (theme/fundus/hinweise).
    $router->get('/fundus/hinweise', fn () => \FundusHinweise\Hinweise::abrufen());
    $router->post('/fundus/hinweise/gesehen', fn (\Illuminate\Http\Request $request) => \FundusHinweise\Hinweise::gesehen($request));
});

// 3. Geänderte Artikel im Hintergrund neu einbetten (Warteschlange, Dienst svc-queue-worker im Image),
//    gelöschte sofort aus dem KI-Index entfernen.
Theme::listen(ThemeEvents::ACTIVITY_LOGGED, function (string $type, $detail) {
    if (!Ki::aktiv() || !$detail instanceof Page) {
        return null;
    }
    if (in_array($type, [ActivityType::PAGE_CREATE, ActivityType::PAGE_UPDATE, ActivityType::PAGE_RESTORE, ActivityType::PAGE_MOVE], true)) {
        IndexJob::dispatch($detail->id);
    } elseif ($type === ActivityType::PAGE_DELETE) {
        Ki::entfernen($detail->id);
    }
    return null;
});

// Berufstitel aus dem Formular einer Person (Baustein fundus/titel-feld), erst nachdem BookStack die Person gespeichert hat.
Theme::listen(ThemeEvents::ACTIVITY_LOGGED, function (string $type, $detail) {
    if (in_array($type, [ActivityType::USER_CREATE, ActivityType::USER_UPDATE], true) && $detail instanceof \BookStack\Users\Models\User) {
        \FundusAnmeldung\Anmeldung::ausFormular($detail, request());
    }
    return null;
});

// Text aus Anhängen und Bildern (theme/fundus/dateitext): Block bei jedem Speichern frisch aus der Tabelle anhängen.
// Ein Fehler hier darf das Speichern nie verhindern, dann bleibt das HTML, wie es ist.
Theme::listen(ThemeEvents::PAGE_CONTENT_PRE_STORE, function (string $html, Page $page) {
    try {
        return Dateitext::mitBlock($html, $page);
    } catch (\Throwable $e) {
        report($e);
        return null;
    }
});
// Bindet ein Artikel einen anderen ein ({{@id}}), bleibt dessen Block „Text aus Anhängen“ draußen: Der Text gehört
// zum eingebundenen Artikel, und zweimal dieselbe id auf einer Seite wäre kaputtes HTML.
Theme::listen(ThemeEvents::PAGE_INCLUDE_PARSE, function (string $verweis, string $ersatz, Page $seite, ?Page $eingebunden) {
    try {
        return Dateitext::ohneBlock($ersatz);
    } catch (\Throwable $e) {
        report($e);
        return null;
    }
});

// Anhänge und Bilder schreiben keine Aktivität, deshalb Model-Events: hochgeladen oder ersetzt → Texterkennung,
// gelöscht → Text weg. Auch hier gilt: Ein Fehler darf den Upload nicht verhindern.
$dateiEreignis = fn (callable $aufruf) => function ($datei) use ($aufruf) {
    try {
        $aufruf($datei);
    } catch (\Throwable $e) {
        report($e);
    }
};
Attachment::saved($dateiEreignis(fn ($a) => Dateitext::anhangGespeichert($a)));
Attachment::deleted($dateiEreignis(fn ($a) => Dateitext::geloescht($a)));
Image::saved($dateiEreignis(fn ($b) => Dateitext::bildGespeichert($b)));
Image::deleted($dateiEreignis(fn ($b) => Dateitext::geloescht($b)));

// Berufstitel aus dem Anmeldedienst (theme/fundus/anmeldung): Claims des ID-Tokens merken, beim Login speichern.
Theme::listen(ThemeEvents::OIDC_ID_TOKEN_PRE_VALIDATE, fn (array $idToken, array $zugang) => \FundusAnmeldung\Anmeldung::claimsMerken($idToken, $zugang));
Theme::listen(ThemeEvents::AUTH_LOGIN, fn (string $verfahren, $nutzer) => \FundusAnmeldung\Anmeldung::nachAnmeldung($verfahren, $nutzer));

// 4. Befehl zum Neuaufbau des KI-Index (als Benutzer abc ausführen, siehe Entwicklerdoku).
Theme::registerCommand(new IndexCommand());
// Vorhandene Anhänge und Bilder erkennen lassen (theme/fundus/dateitext).
Theme::registerCommand(new DateitextCommand());
