<?php

/*
 * Tests für das Theme „fundus“: Rechte, Rückmeldungen und KI-Chat.
 *
 * Läuft im Wiki-Container des Teststapels, nie im echten Wiki: Die Tests legen Rückmeldungen und
 * Freigaben an und bauen den KI-Index mit Testvektoren neu. Aufruf über skripte/testen.sh.
 *
 * Vorausgesetzt werden der Admin (1) und die Rollen aus skripte/einrichten.py. Die Testkonten
 * Mitarbeiterin und Azubi legen die Tests bei Bedarf an, ebenso alle Inhalte
 * (Abschnitt „Testdaten“), damit das echte Wiki keine Beispielseiten braucht.
 */

use FundusAktivitaet\Aktivitaet;
use FundusAnmeldung\Anmeldung;
use FundusDateitext\Dateitext;
use FundusDateitext\ErkennenJob;
use FundusDateitext\SeiteJob;
use FundusHinweise\Hinweise;
use FundusKi\Ki;
use FundusPruefung\Pruefung;
use FundusRueckmeldung\Rueckmeldung;
use BookStack\Entities\Models\Book;
use BookStack\Entities\Models\Page;
use BookStack\Permissions\JointPermissionBuilder;
use BookStack\Permissions\Permission;
use BookStack\Uploads\Attachment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require '/app/www/vendor/autoload.php';
$app = require '/app/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (env('FUNDUS_TESTSTAPEL') !== '1') {
    fwrite(STDERR, "Abbruch: Diese Tests schreiben Daten und laufen nur im Teststapel (FUNDUS_TESTSTAPEL=1).\n");
    exit(2);
}

const ADMIN = 1;
const MARKER = 'fundus-test';

// Testkonten über die E-Mail-Adresse, bei Bedarf angelegt: Ein frisch installiertes Wiki hat nur den Admin.
// Die Rollen „Mitarbeiter“ und „Azubi“ legt skripte/einrichten.py an.
$testkonto = function (string $email, string $name, string $rolle): int {
    $konto = \BookStack\Users\Models\User::query()->where('email', $email)->first();
    if (!$konto) {
        // Über BookStacks UserRepo: Es setzt auch den eindeutigen slug, ohne den das zweite Konto scheitert.
        $konto = app(\BookStack\Users\UserRepo::class)->createWithoutActivity(['name' => $name, 'email' => $email, 'password' => null], true);
    }
    $rollenId = \BookStack\Users\Models\Role::query()->where('display_name', $rolle)->value('id');
    if (!$rollenId) {
        fwrite(STDERR, "Abbruch: Rolle „{$rolle}“ fehlt. Erst skripte/einrichten.py gegen das lokale Wiki laufen lassen.\n");
        exit(2);
    }
    $konto->roles()->sync([$rollenId]);
    return $konto->id;
};
define('MITARBEITER', $testkonto('mitarbeiter@firma.intern', 'Mia Mitarbeiterin', 'Mitarbeiter'));
define('AZUBI', $testkonto('azubi@firma.intern', 'Alex Azubi', 'Azubi'));

// ---------------------------------------------------------------------------
// Kleines Test-Gerüst
// ---------------------------------------------------------------------------

$bestanden = 0;
$fehlgeschlagen = [];

function abschnitt(string $titel): void
{
    echo "\n{$titel}\n";
}

/** Führt einen Test aus. Die Funktion gibt true zurück oder einen Text, der erklärt, was nicht stimmt. */
function test(string $name, callable $pruefung): void
{
    global $bestanden, $fehlgeschlagen;
    try {
        $ergebnis = $pruefung();
    } catch (Throwable $e) {
        $ergebnis = get_class($e) . ': ' . $e->getMessage();
    }
    if ($ergebnis === true) {
        $bestanden++;
        echo "  ✓ {$name}\n";
    } else {
        $fehlgeschlagen[] = $name;
        echo "  ✗ {$name}\n      " . (is_string($ergebnis) ? $ergebnis : 'Bedingung nicht erfüllt') . "\n";
    }
}

/** true, wenn die Funktion die erwartete Ausnahme wirft (bei HTTP-Fehlern auch mit passendem Status). */
function wirft(callable $aufruf, string $klasse, ?int $status = null): bool|string
{
    try {
        $aufruf();
    } catch (Throwable $e) {
        if (!$e instanceof $klasse) {
            return 'erwartet ' . $klasse . ', bekommen ' . get_class($e) . ': ' . $e->getMessage();
        }
        if ($status !== null && $e instanceof HttpExceptionInterface && $e->getStatusCode() !== $status) {
            return "erwartet Status {$status}, bekommen {$e->getStatusCode()}";
        }
        return true;
    }
    return "keine Ausnahme, erwartet {$klasse}";
}

function als(int $person): void
{
    auth()->loginUsingId($person);
}

/** Rendert eine Seite wie ein Browseraufruf der angegebenen Person. */
function aufruf(string $pfad, int $person): array
{
    $app = app();
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $anfrage = Request::create($pfad, 'GET');
    $app->instance('request', $anfrage);
    als($person);
    $antwort = $kernel->handle($anfrage);
    return ['status' => $antwort->getStatusCode(), 'html' => (string) $antwort->getContent()];
}

/**
 * Anfrage als Gast. Mit $oeffentlich wie bei eingeschaltetem öffentlichem Zugriff (Einstellungen → Sicherheit):
 * Dann lässt BookStacks „auth“ Gäste durch, und das Theme muss sie selbst abweisen.
 */
function gastAufruf(string $pfad, bool $oeffentlich): array
{
    $vorher = (bool) setting('app-public');
    setting()->put('app-public', $oeffentlich ? 'true' : 'false');
    try {
        auth()->logout();
        $app = app();
        $anfrage = Request::create($pfad, 'GET');
        $app->instance('request', $anfrage);
        $antwort = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($anfrage);
        return ['status' => $antwort->getStatusCode(), 'html' => (string) $antwort->getContent()];
    } finally {
        setting()->put('app-public', $vorher ? 'true' : 'false');
    }
}

/** Setzt die Freigabe einer Rolle auf ein Thema, wie unter „Rechte“ im Wiki; leere Rechte entfernen sie. */
function freigabe(Book $buch, string $rolle, array $rechte): void
{
    $rolleId = DB::table('roles')->where('display_name', $rolle)->value('id');
    DB::table('entity_permissions')->where('entity_type', 'book')->where('entity_id', $buch->id)->where('role_id', $rolleId)->delete();
    if ($rechte) {
        // Bei „+“ gewinnt der erste Wert: erst die gesetzten Rechte, dann die übrigen mit 0.
        DB::table('entity_permissions')->insert(['entity_type' => 'book', 'entity_id' => $buch->id, 'role_id' => $rolleId]
            + array_fill_keys($rechte, 1) + array_fill_keys(['view', 'create', 'update', 'delete'], 0));
    }
    app(JointPermissionBuilder::class)->rebuildForEntity($buch->refresh());
}

function freigabeFuerAzubi(Book $buch, bool $an): void
{
    freigabe($buch, 'Azubi', $an ? ['view'] : []);
}

// ---------------------------------------------------------------------------
// Testdaten: eigene Themen und Artikel, angelegt wie im Wiki (Suche, Rechte, Aktivität).
// Bei --nur-tests bestehen sie schon und werden wiederverwendet.
// ---------------------------------------------------------------------------

als(ADMIN);

function testThema(string $name, array $rechteMitarbeiter): Book
{
    $buch = Book::query()->where('name', $name)->first()
        ?? app(BookStack\Entities\Repos\BookRepo::class)->create(['name' => $name, 'description_html' => '<p>' . MARKER . '</p>']);
    freigabe($buch, 'Mitarbeiter', $rechteMitarbeiter);
    freigabe($buch, 'Azubi', $rechteMitarbeiter);
    return $buch;
}

function testArtikel(Book $buch, string $name, string $html): Page
{
    $seite = Page::query()->where('book_id', $buch->id)->where('name', $name)->where('draft', false)->first();
    if ($seite) {
        return $seite;
    }
    $repo = app(BookStack\Entities\Repos\PageRepo::class);
    return $repo->publishDraft($repo->getNewDraftPage($buch), ['name' => $name, 'html' => $html]);
}

$abschnitte = '<h2>Ziel</h2><p>Ein Postfach für das ganze Team einrichten, damit alle Anfragen sehen.</p>'
    . '<h2>Schritte</h2><ol><li>Admin Center öffnen und anmelden</li><li>Postfach anlegen und Personen hinzufügen</li></ol>';

// Kundenthema ohne Freigaben: nur der Admin sieht es. Liegt wie echte Kunden im Bereich „Kunden“, falls vorhanden.
$kundenThema = testThema('Testkunde Fundus-Test', []);
$kundenArtikel = testArtikel($kundenThema, 'Kundenüberblick Testkunde', '<h2>Umgebung</h2><p>Der Testkunde hat einen Server und zwanzig Arbeitsplätze.</p>');
if ($kundenRegal = BookStack\Entities\Models\Bookshelf::query()->where('name', 'Kunden')->first()) {
    $kundenRegal->appendBook($kundenThema);
}

// Anleitungen: lesen, anlegen, bearbeiten wie die Technik-Themen; nicht löschen.
$anleitungen = testThema('Testanleitungen Fundus-Test', ['view', 'create', 'update']);
$anleitung = testArtikel($anleitungen, 'Testpostfach einrichten', $abschnitte);

// Handbuch: nur lesen.
$handbuch = testThema('Testhandbuch Fundus-Test', ['view']);
$handbuchArtikel = testArtikel($handbuch, 'Testhandbuch Überblick', $abschnitte);

$vorlage = testArtikel($anleitungen, 'Testvorlage', $abschnitte);
$vorlage->forceFill(['template' => true])->save();

$kundenPfad = '/books/' . $kundenThema->slug;

// ---------------------------------------------------------------------------
abschnitt('Grundlagen');
// ---------------------------------------------------------------------------

// Das Theme legt seine Tabellen erst bei der ersten Nutzung an. In einem frischen Wiki gibt es sie also noch nicht.
test('Theme-Tabellen lassen sich anlegen', function () {
    Rueckmeldung::tabelleAnlegen();
    Ki::tabelleAnlegen();
    return DB::getSchemaBuilder()->hasTable(Rueckmeldung::TABELLE) && DB::getSchemaBuilder()->hasTable(Ki::TABELLE);
});

test('Eigene Routen verlangen eine Anmeldung', function () {
    $router = app('router');
    foreach ([['POST', '/fundus/ki'], ['GET', '/fundus/ki/bibliotheken.js'], ['POST', '/fundus/rueckmeldung'], ['POST', '/fundus/rueckmeldung/1/erledigt']] as [$methode, $pfad]) {
        $route = $router->getRoutes()->match(Request::create($pfad, $methode));
        if (!in_array('auth', $route->gatherMiddleware(), true)) {
            return "{$methode} {$pfad} ohne auth-Middleware";
        }
    }
    return true;
});

test('Gäste werden von den Theme-Routen abgewiesen', function () {
    auth()->logout();
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $antwort = $kernel->handle(Request::create('/fundus/rueckmeldung', 'POST', ['page_id' => 1, 'art' => 'ja']));
    return $antwort->getStatusCode() !== 200 ? true : 'Status 200 für Gast';
});

test('Auch bei öffentlichem Zugriff weisen die Theme-Routen Gäste ab (403)', function () {
    $falsch = [];
    foreach (['/fundus/hinweise', '/fundus/ki/lauf/fundustest1', '/fundus/ki/bibliotheken.js'] as $pfad) {
        $status = gastAufruf($pfad, true)['status'];
        if ($status !== 403) {
            $falsch[] = "{$pfad}: {$status}";
        }
    }
    return $falsch ? implode(', ', $falsch) : true;
});

test('Gäste sehen bei öffentlichem Zugriff weder KI-Chat noch Hinweise', function () {
    // Nach dem Markup der Bausteine suchen: wiki.js steht in jeder Seite und nennt die data-Attribute ohnehin.
    $seite = gastAufruf('/', true);
    $mitarbeiterin = aufruf('/', MITARBEITER)['html'];
    if ($seite['status'] !== 200) {
        return "Status {$seite['status']}";
    }
    if (!str_contains($mitarbeiterin, 'id="fundus-ki"') || !str_contains($mitarbeiterin, 'class="fundus-hinweise"')) {
        return 'Gegenprobe: Mitarbeiterin sieht Chat oder Hinweise nicht';
    }
    return !str_contains($seite['html'], 'id="fundus-ki"') && !str_contains($seite['html'], 'class="fundus-hinweise"')
        ? true : 'Chat oder Hinweise im HTML für Gäste';
});

// ---------------------------------------------------------------------------
abschnitt('Rechte: Seiten');
// ---------------------------------------------------------------------------

test('Admin sieht das Kundenthema', fn () => aufruf($kundenPfad, ADMIN)['status'] === 200);
test('Mitarbeiterin sieht das Kundenthema nicht (404)', fn () => aufruf($kundenPfad, MITARBEITER)['status'] === 404);
test('Mitarbeiterin sieht keinen Kundenartikel (404)', fn () => aufruf($kundenArtikel->getUrl(), MITARBEITER)['status'] === 404);
test('Azubi sieht das Kundenthema ohne Freigabe nicht (404)', fn () => aufruf($kundenPfad, AZUBI)['status'] === 404);

test('Azubi sieht das Kundenthema nach Einzelfreigabe, Mitarbeiterin weiterhin nicht', function () use ($kundenThema, $kundenPfad) {
    freigabeFuerAzubi($kundenThema, true);
    try {
        $azubi = aufruf($kundenPfad, AZUBI)['status'];
        $mitarbeiterin = aufruf($kundenPfad, MITARBEITER)['status'];
    } finally {
        freigabeFuerAzubi($kundenThema, false);
    }
    return $azubi === 200 && $mitarbeiterin === 404 ? true : "Azubi {$azubi}, Mitarbeiterin {$mitarbeiterin}";
});

test('Freigabe wieder entfernt: Azubi sieht das Kundenthema nicht mehr', fn () => aufruf($kundenPfad, AZUBI)['status'] === 404);

test('Mitarbeiterin darf Anleitungen bearbeiten, aber nicht löschen', function () use ($anleitung) {
    als(MITARBEITER);
    return userCan(Permission::PageUpdate, $anleitung) && !userCan(Permission::PageDelete, $anleitung) ? true : 'Rechte passen nicht';
});

test('Mitarbeiterin darf das Handbuch nur lesen', function () use ($handbuchArtikel) {
    als(MITARBEITER);
    return aufruf($handbuchArtikel->getUrl(), MITARBEITER)['status'] === 200 && !userCan(Permission::PageUpdate, $handbuchArtikel) ? true : 'Handbuch bearbeitbar oder nicht lesbar';
});

// ---------------------------------------------------------------------------
abschnitt('Rechte: keine Lecks in Übersichten des Themes');
// ---------------------------------------------------------------------------

foreach (['/' => 'Startseite', '/books' => 'Liste der Themen', '/shelves' => 'Liste der Bereiche', '/search?term=Testkunde' => 'Suche'] as $pfad => $name) {
    foreach ([MITARBEITER => 'Mitarbeiterin', AZUBI => 'Azubi'] as $person => $wer) {
        test("{$name}: kein Link auf Kundeninhalte für {$wer}", function () use ($pfad, $person, $kundenPfad) {
            $seite = aufruf($pfad, $person);
            if ($seite['status'] !== 200) {
                return "Status {$seite['status']}";
            }
            return !str_contains($seite['html'], $kundenPfad) ? true : "HTML enthält {$kundenPfad}";
        });
    }
}

test('Gegenprobe: Suche des Admins findet das Kundenthema', fn () => str_contains(aufruf('/search?term=Testkunde', ADMIN)['html'], $kundenPfad) ? true : 'Test würde Lecks nicht erkennen');

test('Symbole für die Seitenleisten verraten keine Themen, die man nicht lesen darf', function () use ($kundenThema) {
    $kundenThema->tags()->where('name', 'Symbol')->delete();
    $kundenThema->tags()->create(['name' => 'Symbol', 'value' => 'kunde', 'order' => 0]);
    $zuordnung = function (int $person) {
        preg_match('/<script type="application\/json" id="fundus-symbole">(.*?)<\/script>/s', aufruf('/books', $person)['html'], $treffer);
        return array_keys(json_decode($treffer[1] ?? '{}', true)['zu'] ?? []);
    };
    $admin = $zuordnung(ADMIN);
    $mitarbeiterin = $zuordnung(MITARBEITER);
    $kundenThema->tags()->where('name', 'Symbol')->delete();
    $schluessel = 'book:' . $kundenThema->id;
    if (!in_array($schluessel, $admin, true)) {
        return 'Gegenprobe: Admin bekommt das Symbol des Kundenthemas nicht';
    }
    return !in_array($schluessel, $mitarbeiterin, true) ? true : 'Mitarbeiterin bekommt das Symbol des Kundenthemas';
});

test('Kennzahl „Artikel“ auf /books zählt nur lesbare Artikel', function () {
    $zahl = function (int $person) {
        preg_match('/<dd>(\d+)<\/dd><dt>Artikel<\/dt>/', aufruf('/books', $person)['html'], $treffer);
        return isset($treffer[1]) ? (int) $treffer[1] : null;
    };
    als(MITARBEITER);
    $erwartet = Page::query()->scopes('visible')->where('draft', false)->where('template', false)->count();
    $mitarbeiterin = $zahl(MITARBEITER);
    $admin = $zahl(ADMIN);
    if ($mitarbeiterin !== $erwartet) {
        return "Mitarbeiterin sieht {$mitarbeiterin}, lesbar sind {$erwartet}";
    }
    return $admin > $mitarbeiterin ? true : "Admin {$admin} nicht größer als Mitarbeiterin {$mitarbeiterin}";
});

// ---------------------------------------------------------------------------
abschnitt('Rückmeldungen');
// ---------------------------------------------------------------------------

$senden = fn (array $daten) => Rueckmeldung::speichern(Request::create('/fundus/rueckmeldung', 'POST', $daten));
DB::table(Rueckmeldung::TABELLE)->where('user_id', MITARBEITER)->whereIn('page_id', [$anleitung->id, $handbuchArtikel->id])->delete();

test('Stimme wird gespeichert', function () use ($senden, $anleitung) {
    als(MITARBEITER);
    return $senden(['page_id' => $anleitung->id, 'art' => 'ja'])->getStatusCode() === 200;
});

test('Neue Stimme ersetzt die alte (eine Stimme je Person)', function () use ($senden, $anleitung) {
    als(MITARBEITER);
    $senden(['page_id' => $anleitung->id, 'art' => 'nein']);
    $stimmen = DB::table(Rueckmeldung::TABELLE)->where('page_id', $anleitung->id)->where('user_id', MITARBEITER)->whereIn('art', ['ja', 'nein'])->pluck('art')->all();
    return $stimmen === ['nein'] ? true : 'Stimmen: ' . json_encode($stimmen);
});

test('Eigene Stimme wird in der Auswertung erkannt', function () use ($anleitung) {
    als(MITARBEITER);
    return Rueckmeldung::fuerSeite($anleitung)['meine'] === 'nein';
});

test('Hinweis ohne Beschreibung wird abgelehnt (422)', function () use ($senden, $anleitung) {
    als(MITARBEITER);
    $status = $senden(['page_id' => $anleitung->id, 'art' => 'veraltet', 'text' => '   '])->getStatusCode();
    return $status === 422 ? true : "Status {$status}";
});

test('Unbekannter Grund wird abgelehnt', fn () => wirft(function () use ($senden, $anleitung) {
    als(MITARBEITER);
    $senden(['page_id' => $anleitung->id, 'art' => 'veraltet', 'text' => MARKER, 'grund' => 'quatsch']);
}, ValidationException::class));

test('Rückmeldung zu einem nicht lesbaren Artikel wird abgewiesen (404)', fn () => wirft(function () use ($senden, $kundenArtikel) {
    als(MITARBEITER);
    $senden(['page_id' => $kundenArtikel->id, 'art' => 'veraltet', 'text' => MARKER]);
}, ModelNotFoundException::class));

test('Hinweis mit Grund und Abschnitt wird gespeichert', function () use ($senden, $anleitung) {
    als(MITARBEITER);
    $senden(['page_id' => $anleitung->id, 'art' => 'veraltet', 'text' => MARKER, 'grund' => 'bild', 'abschnitt' => 'Schritte']);
    $eintrag = DB::table(Rueckmeldung::TABELLE)->where('page_id', $anleitung->id)->where('text', MARKER)->first();
    return $eintrag && $eintrag->grund === 'bild' && $eintrag->abschnitt === 'Schritte' ? true : 'Eintrag fehlt oder unvollständig';
});

test('Wer bearbeiten darf, kann den Hinweis erledigen', function () use ($anleitung) {
    als(MITARBEITER);
    $id = DB::table(Rueckmeldung::TABELLE)->where('page_id', $anleitung->id)->where('text', MARKER)->value('id');
    Rueckmeldung::erledigen(Request::create('/', 'POST'), $id);
    return DB::table(Rueckmeldung::TABELLE)->where('id', $id)->value('erledigt_von') === MITARBEITER ? true : 'erledigt_von nicht gesetzt';
});

test('Wer nur lesen darf, kann Hinweise nicht erledigen (403)', function () use ($senden, $handbuchArtikel) {
    als(MITARBEITER);
    $senden(['page_id' => $handbuchArtikel->id, 'art' => 'veraltet', 'text' => MARKER]);
    $id = DB::table(Rueckmeldung::TABELLE)->where('page_id', $handbuchArtikel->id)->where('text', MARKER)->value('id');
    return wirft(fn () => Rueckmeldung::erledigen(Request::create('/', 'POST'), $id), HttpExceptionInterface::class, 403);
});

test('Hinweis zu einem nicht lesbaren Artikel kann niemand ohne Leserecht erledigen (404)', function () use ($kundenArtikel) {
    als(ADMIN);
    $id = DB::table(Rueckmeldung::TABELLE)->insertGetId(['page_id' => $kundenArtikel->id, 'user_id' => ADMIN, 'art' => 'veraltet', 'text' => MARKER, 'created_at' => now(), 'updated_at' => now()]);
    als(MITARBEITER);
    return wirft(fn () => Rueckmeldung::erledigen(Request::create('/', 'POST'), $id), ModelNotFoundException::class);
});

DB::table(Rueckmeldung::TABELLE)->where('text', MARKER)->delete();
DB::table(Rueckmeldung::TABELLE)->where('user_id', MITARBEITER)->where('page_id', $anleitung->id)->delete();

// ---------------------------------------------------------------------------
abschnitt('KI-Chat: Zerlegen');
// ---------------------------------------------------------------------------

// forceFill: „html“ ist nicht massenzuweisbar, new Page([...]) ließe es stillschweigend weg.
$testArtikel = fn (string $html) => (new Page())->forceFill(['name' => 'Testartikel', 'html' => $html]);

test('Artikel wird an Überschriften zerlegt', function () use ($testArtikel) {
    $teile = Ki::zerlegen($testArtikel('<h2>Ziel</h2><p>Ein Postfach für das ganze Team einrichten.</p><h2>Schritte</h2><ol><li>Admin Center öffnen und anmelden</li><li>Postfach anlegen</li></ol>'));
    return array_column($teile, 'ueberschrift') === ['Ziel', 'Schritte'] ? true : json_encode($teile, JSON_UNESCAPED_UNICODE);
});

test('Text vor der ersten Überschrift heißt „Einleitung“, zu kurze Absätze fallen weg', function () use ($testArtikel) {
    $teile = Ki::zerlegen($testArtikel('<p>Dieser Artikel erklärt die Einrichtung.</p><h2>Kurz</h2><p>zu kurz</p>'));
    return count($teile) === 1 && $teile[0]['ueberschrift'] === 'Einleitung' ? true : json_encode($teile, JSON_UNESCAPED_UNICODE);
});

test('Tabellenzellen bleiben als „A | B“ lesbar', function () use ($testArtikel) {
    $teile = Ki::zerlegen($testArtikel('<h2>Fehler</h2><table><tr><td>Anmeldung schlägt fehl</td><td>Kennwort abgelaufen</td></tr></table>'));
    return str_contains($teile[0]['text'] ?? '', 'Anmeldung schlägt fehl | Kennwort abgelaufen') ? true : json_encode($teile, JSON_UNESCAPED_UNICODE);
});

test('Lange Abschnitte werden in Stücke bis 1 400 Zeichen geteilt, ohne Text zu verlieren', function () use ($testArtikel) {
    $zeilen = array_map(fn ($i) => "<p>Absatz {$i}: " . str_repeat('Wort ', 18) . '</p>', range(1, 60));
    $teile = Ki::zerlegen($testArtikel('<h2>Lang</h2>' . implode('', $zeilen)));
    $zuLang = array_filter($teile, fn ($t) => mb_strlen($t['text']) > 1400);
    $alle = implode("\n", array_column($teile, 'text'));
    if (count($teile) < 2 || $zuLang) {
        return count($teile) . ' Stücke, davon ' . count($zuLang) . ' zu lang';
    }
    return str_contains($alle, 'Absatz 1:') && str_contains($alle, 'Absatz 60:') ? true : 'Text verloren';
});

// ---------------------------------------------------------------------------
abschnitt('KI-Chat: Index und Suche mit Rechten');
// ---------------------------------------------------------------------------

// Statt Ollama feste Vektoren: Kundenartikel zeigen in eine Richtung, alles andere in eine andere.
$dimension = (int) env('KI_DIMENSION', 1024);
$richtung = fn (int $achse) => array_map(fn ($i) => $i === $achse ? 1.0 : 0.0, range(0, $dimension - 1));
$GLOBALS['testvektor'] = $richtung(1);
Http::fake(['*' => fn ($anfrage) => Http::response(['embeddings' => array_fill(0, count($anfrage['input']), $GLOBALS['testvektor'])])]);

test('Vorlagen und Entwürfe werden nicht eingebettet', function () use ($vorlage) {
    $stuecke = Ki::indexieren($vorlage->id);
    $zeilen = DB::table(Ki::TABELLE)->where('page_id', $vorlage->id)->count();
    return $stuecke === 0 && $zeilen === 0 ? true : "{$stuecke} Stücke, {$zeilen} Zeilen";
});

// Vor dem Aufbau unten: Der Befehl bettet alle Artikel mit demselben Testvektor ein, der Aufbau setzt die Richtungen neu.
test('fundus:ki-index entfernt Stücke von Artikeln, die es nicht mehr gibt', function () use ($richtung) {
    Ki::tabelleAnlegen();
    $weg = (int) Page::query()->withTrashed()->max('id') + 1000;
    DB::insert('INSERT INTO ' . Ki::TABELLE . ' (page_id, nummer, ueberschrift, text, vektor) VALUES (?, 0, ?, ?, VEC_FromText(?))',
        [$weg, 'Rest', 'Rest eines gelöschten Artikels', json_encode($richtung(1))]);
    $code = Illuminate\Support\Facades\Artisan::call('fundus:ki-index');
    $rest = DB::table(Ki::TABELLE)->where('page_id', $weg)->exists();
    return $code === 0 && !$rest ? true : "Rückgabe {$code}, Rest noch da: " . json_encode($rest);
});

test('Index aller Artikel lässt sich aufbauen', function () use ($kundenThema, $richtung) {
    Ki::tabelleAnlegen(true);
    $gesamt = 0;
    foreach (Page::query()->where('draft', false)->where('template', false)->get() as $seite) {
        $GLOBALS['testvektor'] = $richtung($seite->book_id === $kundenThema->id ? 0 : 1);
        $gesamt += Ki::indexieren($seite->id);
    }
    return $gesamt > 0 ? true : 'keine Stücke eingebettet';
});

$suchen = new ReflectionMethod(Ki::class, 'suchen');
$kundenFrage = fn () => $suchen->invoke(null, 'Wie ist die Umgebung beim Kunden aufgebaut?', []);

test('Admin: Suche nach Kundenwissen findet den Kundenartikel', function () use ($kundenFrage, $richtung, $kundenThema) {
    als(ADMIN);
    $GLOBALS['testvektor'] = $richtung(0);
    $treffer = $kundenFrage();
    return collect($treffer)->contains(fn ($t) => str_contains($t['url'], '/books/' . $kundenThema->slug)) ? true : 'kein Kundentreffer: ' . json_encode(array_column($treffer, 'titel'), JSON_UNESCAPED_UNICODE);
});

foreach ([MITARBEITER => 'Mitarbeiterin', AZUBI => 'Azubi'] as $person => $wer) {
    test("{$wer}: KI liefert keine Ausschnitte aus Kundenartikeln", function () use ($kundenFrage, $richtung, $kundenThema, $person) {
        als($person);
        $GLOBALS['testvektor'] = $richtung(0);
        $leck = collect($kundenFrage())->filter(fn ($t) => str_contains($t['url'], '/books/' . $kundenThema->slug));
        return $leck->isEmpty() ? true : 'Leck: ' . $leck->pluck('titel')->join(', ');
    });
}

test('Frage ohne Text und zu langer Verlauf werden abgelehnt', function () {
    als(MITARBEITER);
    $leer = wirft(fn () => Ki::antworten(Request::create('/fundus/ki', 'POST', ['frage' => ''])), ValidationException::class);
    $verlauf = array_fill(0, 7, ['rolle' => 'nutzer', 'text' => 'Hallo']);
    $lang = wirft(fn () => Ki::antworten(Request::create('/fundus/ki', 'POST', ['frage' => 'Test', 'verlauf' => $verlauf])), ValidationException::class);
    return $leer === true && $lang === true ? true : "leer: {$leer}; Verlauf: {$lang}";
});

// ---------------------------------------------------------------------------
abschnitt('Prüfung von Artikeln');
// ---------------------------------------------------------------------------

Pruefung::tabelleAnlegen();
$schlagwortSetzen = function ($entity, ?string $wert) {
    $entity->tags()->where('name', Pruefung::SCHLAGWORT)->delete();
    if ($wert !== null) {
        $entity->tags()->create(['name' => Pruefung::SCHLAGWORT, 'value' => $wert, 'order' => 0]);
    }
    $entity->unsetRelation('tags');
    if ($entity instanceof Page) {
        $entity->unsetRelation('book');
    }
};
$pruefungZuruecksetzen = function (Page $seite) use ($schlagwortSetzen) {
    DB::table(Pruefung::TABELLE)->where('page_id', $seite->id)->delete();
    $schlagwortSetzen($seite, null);
    $schlagwortSetzen($seite->book, null);
};
$pruefungZuruecksetzen($anleitung);
$pruefungZuruecksetzen($handbuchArtikel);
$pruefungZuruecksetzen($kundenArtikel);

test('Schlagwort „Prüfintervall“: Zahl, „6 Monate“, „nie“, Unlesbares', function () {
    $f = [Pruefung::monateAusText('6'), Pruefung::monateAusText('6 Monate'), Pruefung::monateAusText('nie'), Pruefung::monateAusText('bald'), Pruefung::monateAusText('')];
    return $f === [6, 6, 0, null, null] ? true : json_encode($f);
});

test('Intervall: Vorgabe, dann Thema, dann Artikel', function () use ($anleitung, $schlagwortSetzen) {
    $vorgabe = Pruefung::intervall($anleitung->refresh());
    $schlagwortSetzen($anleitung->book, '6');
    $thema = Pruefung::intervall($anleitung->refresh());
    $schlagwortSetzen($anleitung, 'nie');
    $artikel = Pruefung::intervall($anleitung->refresh());
    $schlagwortSetzen($anleitung, null);
    $schlagwortSetzen($anleitung->book, null);
    $ist = [$vorgabe['quelle'], $thema['monate'], $thema['quelle'], $artikel['monate'], $artikel['quelle']];
    return $ist === ['vorgabe', 6, 'thema', 0, 'artikel'] && $vorgabe['monate'] === Pruefung::vorgabe() ? true : json_encode($ist);
});

test('Frist: fällig, bald fällig, in Ordnung, ohne Prüfung', function () {
    $lagen = [
        Pruefung::frist(now()->subYears(3), now()->subMonths(13), 12)['lage'],
        Pruefung::frist(now()->subYears(3), now()->subMonths(12)->addDays(10), 12)['lage'],
        Pruefung::frist(now()->subYears(3), now()->subMonth(), 12)['lage'],
        Pruefung::frist(now()->subYears(3), null, 12)['lage'],
        Pruefung::frist(now()->subYears(3), null, 0)['lage'],
    ];
    return $lagen === ['faellig', 'bald', 'ok', 'faellig', 'aus'] ? true : json_encode($lagen);
});

test('Wer bearbeiten darf, markiert als geprüft; die Frist beginnt neu', function () use ($anleitung) {
    als(MITARBEITER);
    DB::table(Pruefung::TABELLE)->insert(['page_id' => $anleitung->id, 'user_id' => ADMIN, 'created_at' => now()->subMonths(13)]);
    $vorher = Pruefung::stand($anleitung->refresh())['lage'];
    $antwort = Pruefung::pruefen(Request::create('/', 'POST'), $anleitung->id);
    $nachher = Pruefung::stand($anleitung->refresh());
    return $vorher === 'faellig' && $antwort->getStatusCode() === 200 && $nachher['lage'] === 'ok' && $nachher['zuletzt']->name !== null
        ? true : "vorher {$vorher}, nachher {$nachher['lage']}";
});

test('Wer nur lesen darf, kann nicht als geprüft markieren (403)', fn () => wirft(function () use ($handbuchArtikel) {
    als(MITARBEITER);
    Pruefung::pruefen(Request::create('/', 'POST'), $handbuchArtikel->id);
}, HttpExceptionInterface::class, 403));

test('Nicht lesbarer Artikel lässt sich nicht prüfen (404)', fn () => wirft(function () use ($kundenArtikel) {
    als(MITARBEITER);
    Pruefung::pruefen(Request::create('/', 'POST'), $kundenArtikel->id);
}, ModelNotFoundException::class));

test('„Zu prüfen“ zeigt nur eigene, lesbare, fällige Artikel', function () use ($anleitung, $kundenArtikel) {
    // Beide Artikel gehören kurzzeitig der Mitarbeiterin und sind fällig; den Kundenartikel darf sie nicht lesen.
    $besitzer = [$anleitung->owned_by, $kundenArtikel->owned_by];
    foreach ([$anleitung, $kundenArtikel] as $s) {
        DB::table('entities')->where('id', $s->id)->where('type', 'page')->update(['owned_by' => MITARBEITER]);
        DB::table(Pruefung::TABELLE)->where('page_id', $s->id)->delete();
        DB::table(Pruefung::TABELLE)->insert(['page_id' => $s->id, 'user_id' => ADMIN, 'created_at' => now()->subYears(2)]);
    }
    als(MITARBEITER);
    $ids = Pruefung::anstehendFuer(MITARBEITER)->map(fn ($e) => $e['seite']->id)->all();
    $startseite = aufruf('/', MITARBEITER)['html'];
    foreach ([$anleitung, $kundenArtikel] as $i => $s) {
        DB::table('entities')->where('id', $s->id)->where('type', 'page')->update(['owned_by' => $besitzer[$i]]);
    }
    if (!in_array($anleitung->id, $ids, true)) return 'eigener fälliger Artikel fehlt';
    if (in_array($kundenArtikel->id, $ids, true)) return 'nicht lesbarer Artikel wird gezeigt';
    return str_contains($startseite, 'fundus-zu-pruefen') && str_contains($startseite, e($anleitung->name)) ? true : 'Kasten „Zu prüfen“ fehlt auf der Startseite';
});

test('Artikelkopf zeigt „Prüfung überfällig“, Details den Knopf', function () use ($anleitung) {
    $html = aufruf(parse_url($anleitung->getUrl(), PHP_URL_PATH), MITARBEITER)['html'];
    return str_contains($html, 'Prüfung überfällig') && str_contains($html, 'data-pruefen') ? true : 'Plakette oder Knopf fehlt';
});

// ---------------------------------------------------------------------------
abschnitt('Benachrichtigungen durch Fundus');
// ---------------------------------------------------------------------------

Hinweise::tabelleAnlegen();
// Die Anleitung gehört für diese Tests der Mitarbeiterin; Admin meldet einen Hinweis.
$anleitungBesitzer = $anleitung->owned_by;
DB::table('entities')->where('id', $anleitung->id)->where('type', 'page')->update(['owned_by' => MITARBEITER]);
DB::table(Hinweise::TABELLE)->whereIn('user_id', [ADMIN, MITARBEITER])->delete();
DB::table(Rueckmeldung::TABELLE)->where('text', 'like', MARKER . '%')->delete();
DB::table(Pruefung::TABELLE)->where('page_id', $anleitung->id)->delete();
DB::table(Pruefung::TABELLE)->insert(['page_id' => $anleitung->id, 'user_id' => ADMIN, 'created_at' => now()->subYears(2)]);
$hinweisId = DB::table(Rueckmeldung::TABELLE)->insertGetId(['page_id' => $anleitung->id, 'user_id' => ADMIN, 'art' => 'veraltet', 'grund' => 'bild',
    'text' => MARKER . ' Screenshot veraltet', 'created_at' => now(), 'updated_at' => now()]);
$schluessel = fn (int $person) => array_column(Hinweise::fuer($person), 'schluessel');

test('Verantwortliche Person bekommt den Hinweis und die fällige Prüfung', function () use ($schluessel, $hinweisId, $anleitung) {
    als(MITARBEITER);
    $s = $schluessel(MITARBEITER);
    $pruefung = array_filter($s, fn ($k) => str_starts_with($k, 'pruefung:' . $anleitung->id . ':'));
    return in_array('rueckmeldung:' . $hinweisId, $s, true) && $pruefung ? true : json_encode($s);
});

test('Wer den Hinweis selbst gemeldet hat, bekommt ihn nicht', function () use ($schluessel, $hinweisId) {
    als(ADMIN);
    return !in_array('rueckmeldung:' . $hinweisId, $schluessel(ADMIN), true) ? true : 'Admin sieht seinen eigenen Hinweis';
});

test('Hinweis zu einem nicht mehr lesbaren Artikel erscheint nicht', function () use ($schluessel, $kundenArtikel) {
    $besitzer = $kundenArtikel->owned_by;
    DB::table('entities')->where('id', $kundenArtikel->id)->where('type', 'page')->update(['owned_by' => MITARBEITER]);
    $id = DB::table(Rueckmeldung::TABELLE)->insertGetId(['page_id' => $kundenArtikel->id, 'user_id' => ADMIN, 'art' => 'veraltet',
        'text' => MARKER . ' Kunde', 'created_at' => now(), 'updated_at' => now()]);
    als(MITARBEITER);
    $s = $schluessel(MITARBEITER);
    DB::table('entities')->where('id', $kundenArtikel->id)->where('type', 'page')->update(['owned_by' => $besitzer]);
    return !in_array('rueckmeldung:' . $id, $s, true) ? true : 'Hinweis zum Kundenartikel sichtbar';
});

test('„Gesehen“ nimmt die Meldung zurück, der Eintrag bleibt bis zur Erledigung', function () use ($hinweisId) {
    als(MITARBEITER);
    $schl = 'rueckmeldung:' . $hinweisId;
    Hinweise::gesehen(Request::create('/', 'POST', ['schluessel' => [$schl]]));
    $eintrag = collect(Hinweise::fuer(MITARBEITER))->firstWhere('schluessel', $schl);
    return $eintrag && $eintrag['neu'] === false ? true : json_encode($eintrag);
});

test('Erledigter Hinweis verschwindet', function () use ($hinweisId, $schluessel) {
    DB::table(Rueckmeldung::TABELLE)->where('id', $hinweisId)->update(['erledigt_am' => now(), 'erledigt_von' => MITARBEITER]);
    als(MITARBEITER);
    return !in_array('rueckmeldung:' . $hinweisId, $schluessel(MITARBEITER), true) ? true : 'noch da';
});

test('Nach einer Prüfung und neuer Frist gilt die Prüfung wieder als neu', function () use ($anleitung) {
    als(MITARBEITER);
    $alt = collect(Hinweise::fuer(MITARBEITER))->first(fn ($e) => $e['art'] === 'pruefung');
    Hinweise::gesehen(Request::create('/', 'POST', ['schluessel' => [$alt['schluessel']]]));
    // Letzte Prüfung vor elf Monaten und zwei Wochen: neue Frist in zwei Wochen, also „bald“ und ein neuer Schlüssel.
    DB::table(Pruefung::TABELLE)->insert(['page_id' => $anleitung->id, 'user_id' => ADMIN, 'created_at' => now()->subMonths(12)->addDays(14)]);
    $neu = collect(Hinweise::fuer(MITARBEITER))->first(fn ($e) => $e['art'] === 'pruefung');
    return $neu && $neu['schluessel'] !== $alt['schluessel'] && $neu['neu'] === true && $neu['dringend'] === false ? true : json_encode([$alt, $neu]);
});

test('Ungültige Schlüssel werden abgelehnt', fn () => wirft(function () {
    als(MITARBEITER);
    Hinweise::gesehen(Request::create('/', 'POST', ['schluessel' => ["x'; DROP TABLE users; --"]]));
}, ValidationException::class));

test('Abruf als JSON, Halter auf jeder Seite, Kasten „Offene Hinweise“ auf der Startseite', function () use ($anleitung) {
    $id = DB::table(Rueckmeldung::TABELLE)->insertGetId(['page_id' => $anleitung->id, 'user_id' => ADMIN, 'art' => 'veraltet',
        'text' => MARKER . ' Startseite', 'created_at' => now(), 'updated_at' => now()]);
    $json = json_decode(aufruf('/fundus/hinweise', MITARBEITER)['html'], true);
    $start = aufruf('/', MITARBEITER)['html'];
    $artikel = aufruf(parse_url($anleitung->getUrl(), PHP_URL_PATH), MITARBEITER)['html'];
    DB::table(Rueckmeldung::TABELLE)->where('id', $id)->delete();
    $fehler = [];
    if (!isset($json['eintraege']) || !in_array('rueckmeldung:' . $id, array_column($json['eintraege'], 'schluessel'), true)) $fehler[] = 'JSON ohne Hinweis';
    if (!str_contains($start, 'fundus-offene-hinweise')) $fehler[] = 'Kasten fehlt';
    if (!str_contains($artikel, 'data-fundus-hinweise')) $fehler[] = 'Halter fehlt';
    return $fehler ? implode('; ', $fehler) : true;
});

DB::table('entities')->where('id', $anleitung->id)->where('type', 'page')->update(['owned_by' => $anleitungBesitzer]);

// ---------------------------------------------------------------------------
abschnitt('Aktivität und Serie');
// ---------------------------------------------------------------------------

// Tage als Schlüssel wie in Aktivitaet::fuer(); 2026-09-14 ist ein Montag.
$tage = fn (string ...$daten) => array_fill_keys($daten, ['geschrieben' => 1, 'gelesen' => 0]);
$montag = CarbonImmutable::parse('2026-09-14', config('app.display_timezone'));

test('Serie: Wochenende unterbricht nicht und zählt nicht mit', fn () =>
    ($n = Aktivitaet::serie($tage('2026-09-14', '2026-09-11', '2026-09-10'), $montag)) === 3 ? true : "Serie {$n}, erwartet 3");

test('Serie: fehlender Werktag unterbricht', fn () =>
    ($n = Aktivitaet::serie($tage('2026-09-14', '2026-09-10'), $montag)) === 1 ? true : "Serie {$n}, erwartet 1");

test('Serie: heute noch leer unterbricht nicht', fn () =>
    ($n = Aktivitaet::serie($tage('2026-09-11', '2026-09-10'), $montag)) === 2 ? true : "Serie {$n}, erwartet 2");

test('Serie: am Samstag zählt der Freitag noch', fn () =>
    ($n = Aktivitaet::serie($tage('2026-09-11'), $montag->subDays(2))) === 1 ? true : "Serie {$n}, erwartet 1");

$gelesen = fn (int $seite) => Aktivitaet::gelesen(Request::create('/fundus/gelesen', 'POST', ['page_id' => $seite]));
Aktivitaet::tabelleAnlegen();
DB::table(Aktivitaet::TABELLE)->where('user_id', MITARBEITER)->delete();

test('Gelesen wird je Artikel und Tag nur einmal gespeichert', function () use ($gelesen, $anleitung) {
    als(MITARBEITER);
    $gelesen($anleitung->id);
    $gelesen($anleitung->id);
    $n = DB::table(Aktivitaet::TABELLE)->where('user_id', MITARBEITER)->where('page_id', $anleitung->id)->count();
    return $n === 1 ? true : "{$n} Einträge";
});

test('Gelesen zu einem nicht lesbaren Artikel wird abgewiesen (404)', fn () => wirft(function () use ($gelesen, $kundenArtikel) {
    als(MITARBEITER);
    $gelesen($kundenArtikel->id);
}, ModelNotFoundException::class));

test('Lesen zählt im Raster, in den Zahlen und für die Serie', function () {
    $a = Aktivitaet::fuer(MITARBEITER);
    $heute = $a['heute']->toDateString();
    $fehler = [];
    if (($a['proTag'][$heute]['gelesen'] ?? 0) < 1) $fehler[] = 'heute nicht als gelesen im Raster';
    if ($a['gelesen'] < 1) $fehler[] = 'Zahl „Artikel gelesen“ ist 0';
    if (!$a['heute']->isWeekend() && $a['serie'] < 1) $fehler[] = 'Serie 0';
    return $fehler ? implode('; ', $fehler) : true;
});

test('Startseite zeigt die Flamme, sobald eine Serie läuft', fn () =>
    str_contains(aufruf('/', MITARBEITER)['html'], 'class="fundus-serie') || CarbonImmutable::now()->isWeekend() ? true : 'keine Flamme auf der Startseite');

// ---------------------------------------------------------------------------
abschnitt('Text aus Anhängen und Bildern');
// ---------------------------------------------------------------------------

// Die Tests tragen den erkannten Text zuerst selbst ein (Dateien gibt es dafür nicht), danach läuft die Erkennung
// gegen einen nachgestellten Dienst. Die Warteschlange ist nachgestellt: Sonst griffe der Worker des Teststapels
// die Jobs auf und überschriebe die Testdaten mit Fehlern vom unerreichbaren Dienst.
Queue::fake();
$seitenRepo = app(BookStack\Entities\Repos\PageRepo::class);
$testAnhang = fn (Page $seite, string $name, bool $link = false) => Attachment::query()->forceCreate([
    'name' => MARKER . ' ' . $name,
    'path' => $link ? 'https://beispiel.invalid/' . $name : 'uploads/files/fundus-test/' . $name,
    'extension' => $link ? '' : pathinfo($name, PATHINFO_EXTENSION),
    'uploaded_to' => $seite->id,
    'external' => $link,
    'order' => 99,
    'created_by' => ADMIN,
    'updated_by' => ADMIN,
]);
// Bilder werden wie im Editor angelegt (ImageService), damit Typ, Pfad und Datei stimmen.
$testBild = function (Page $seite, string $name, string $typ = 'gallery') {
    $punkte = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    return app(BookStack\Uploads\ImageService::class)->saveNew(MARKER . ' ' . $name, base64_decode($punkte), $typ, $seite->id);
};
// Ein Bild zählt nur, solange der Artikel es zeigt (Dateitext::texte): wie im Editor in den Text setzen und speichern.
$bildEinfuegen = fn (Page $seite, $bild, string $text) => $seitenRepo->update($seite->refresh(),
    ['html' => $text . '<p><img src="' . e($bild->url) . '" alt=""></p>']);
$anhaengeAufraeumen = function () {
    Dateitext::tabelleAnlegen();
    $anhaenge = Attachment::query()->where('name', 'like', MARKER . ' %')->get();
    $anhaenge->where('external', false)->each(fn ($a) => app(BookStack\Uploads\AttachmentService::class)->deleteFileInStorage($a));
    $bilder = BookStack\Uploads\Image::query()->where('name', 'like', MARKER . ' %')->get();
    DB::table(Dateitext::TABELLE)->where(fn ($a) => $a->where('art', 'anhang')->whereIn('quelle_id', $anhaenge->pluck('id')))
        ->orWhere(fn ($a) => $a->where('art', 'bild')->whereIn('quelle_id', $bilder->pluck('id')))->delete();
    Attachment::query()->whereIn('id', $anhaenge->pluck('id'))->delete();
    $bilder->each(fn ($b) => app(BookStack\Uploads\ImageService::class)->destroy($b));
    $anhaenge->pluck('uploaded_to')->concat($bilder->pluck('uploaded_to'))->unique()
        ->each(fn ($id) => Dateitext::seiteAktualisieren((int) $id));
};
$blockAnzahl = fn (string $html) => substr_count($html, 'id="' . Dateitext::BLOCK_ID);
$sucheZeigt = fn (string $wort, int $person, Page $seite) => str_contains(aufruf('/search?term=' . rawurlencode($wort), $person)['html'], e($seite->name));

als(ADMIN);
$anhaengeAufraeumen();
$scanArtikel = testArtikel($anleitungen, 'Testanhang Wartungsvertrag', $abschnitte);

test('Artikel ohne Dateitext bleibt beim Speichern Zeichen für Zeichen gleich', function () use ($scanArtikel) {
    $html = '<p id="bkmrk-preis">Preis&nbsp;100 € <strong>fett</strong></p><details id="bkmrk-mehr"><summary>Mehr</summary><p>Eigenes Detail</p></details>';
    return Dateitext::mitBlock($html, $scanArtikel) === $html ? true : 'HTML verändert';
});

test('Erkannter Text kommt ohne Revision, Aktivität und neues Änderungsdatum in den Artikel', function () use ($scanArtikel, $testAnhang, $blockAnzahl) {
    $stand = fn (Page $s) => [$s->revision_count, (string) $s->updated_at, (int) $s->updated_by,
        DB::table('activities')->where('loggable_type', 'page')->where('loggable_id', $s->id)->count()];
    $seite = $scanArtikel->refresh();
    $vorher = $stand($seite);
    Dateitext::speichern('anhang', $testAnhang($seite, 'wartungsvertrag.pdf')->id, 'fertig', "Wartungsvertragsnummer Drosselklappenprotokoll\n\nLaufzeit 24 Monate Vertragsendezeitpunkt", 91.5);
    $seite->refresh();
    if ($stand($seite) !== $vorher) {
        return 'geändert: ' . json_encode([$vorher, $stand($seite)]);
    }
    return $blockAnzahl($seite->html) === 1 && str_contains($seite->html, 'Drosselklappenprotokoll') && str_contains($seite->text, 'Laufzeit 24 Monate')
        ? true : 'Block oder Klartext fehlt';
});

test('BookStack-Suche findet den Artikel über Wörter aus dem Anhang, auch das erste und letzte', function () use ($scanArtikel, $sucheZeigt) {
    $fehlt = array_filter(['Wartungsvertragsnummer', 'Drosselklappenprotokoll', 'Vertragsendezeitpunkt'], fn ($wort) => !$sucheZeigt($wort, MITARBEITER, $scanArtikel));
    return $fehlt ? 'kein Treffer für: ' . implode(', ', $fehlt) : true;
});

test('Suche nach Dateitext beachtet die Rechte des Artikels', function () use ($kundenArtikel, $testAnhang, $sucheZeigt) {
    Dateitext::speichern('anhang', $testAnhang($kundenArtikel, 'schrank.pdf')->id, 'fertig', 'Serverschrankschlüsselliste liegt im Tresor');
    if (!$sucheZeigt('Serverschrankschlüsselliste', ADMIN, $kundenArtikel)) {
        return 'Admin findet den Kundenartikel nicht';
    }
    $leck = array_filter([MITARBEITER => 'Mitarbeiterin', AZUBI => 'Azubi'], fn ($wer, $person) => $sucheZeigt('Serverschrankschlüsselliste', $person, $kundenArtikel), ARRAY_FILTER_USE_BOTH);
    return $leck ? 'Leck: ' . implode(', ', $leck) : true;
});

test('Speichern im Editor ohne Block oder mit verändertem Block ergibt genau einen frischen Block', function () use ($scanArtikel, $seitenRepo, $abschnitte, $blockAnzahl) {
    als(ADMIN);
    $seite = $seitenRepo->update($scanArtikel->refresh(), ['html' => $abschnitte . '<p>Neuer Absatz</p>']);
    $ohne = $blockAnzahl($seite->html);
    // So kommt der Block aus dem Lexical-Editor zurück: nur die id bleibt, der Inhalt ist verändert. Dazu eine Kopie mit anderer id.
    $seite = $seitenRepo->update($seite, ['html' => $abschnitte
        . '<details id="' . Dateitext::BLOCK_ID . '"><summary>' . Dateitext::UEBERSCHRIFT . '</summary><p>im Editor verändert</p></details>'
        . '<details id="bkmrk-kopie"><summary>' . Dateitext::UEBERSCHRIFT . '</summary><p>im Editor verändert</p></details>']);
    $html = $seite->html;
    return $ohne === 1 && $blockAnzahl($html) === 1 && !str_contains($html, 'im Editor verändert') && str_contains($html, 'Drosselklappenprotokoll')
        ? true : "ohne Block: {$ohne}, mit verändertem Block: " . $blockAnzahl($html);
});

test('Markdown-Artikel: Block im HTML, Markdown bleibt ohne Dateitext', function () use ($anleitungen, $abschnitte, $testAnhang, $seitenRepo, $blockAnzahl) {
    als(ADMIN);
    $seite = testArtikel($anleitungen, 'Testanhang Markdown', $abschnitte);
    Dateitext::speichern('anhang', $testAnhang($seite, 'typenschild.png')->id, 'fertig', 'Typenschild Seriennummer SN-4711');
    $seite = $seitenRepo->update($seite->refresh(), ['markdown' => "## Ziel\n\nFoto vom Typenschild der Anlage."]);
    return $seite->editor === 'markdown' && $blockAnzahl($seite->html) === 1 && str_contains($seite->html, 'SN-4711') && !str_contains($seite->markdown, 'SN-4711')
        ? true : "Editor {$seite->editor}, Blöcke " . $blockAnzahl($seite->html);
});

test('Text aus dem Anhang wird maskiert und auf die Obergrenze gekürzt', function () use ($scanArtikel, $testAnhang) {
    $anhang = $testAnhang($scanArtikel, 'boese.pdf');
    Dateitext::speichern('anhang', $anhang->id, 'fertig', '<script>alert(1)</script> ' . str_repeat('x', Dateitext::MAX_ZEICHEN + 50));
    $html = $scanArtikel->refresh()->html;
    $laenge = mb_strlen((string) DB::table(Dateitext::TABELLE)->where('art', 'anhang')->where('quelle_id', $anhang->id)->value('text'));
    $anhang->delete();
    Dateitext::seiteAktualisieren($scanArtikel->id);
    return !str_contains($html, '<script>alert') && str_contains($html, '&lt;script&gt;') && $laenge === Dateitext::MAX_ZEICHEN
        ? true : "Länge {$laenge}";
});

test('Fehlgeschlagene, leere und Link-Anhänge erzeugen keinen Block', function () use ($anleitungen, $abschnitte, $testAnhang, $blockAnzahl) {
    $seite = testArtikel($anleitungen, 'Testanhang ohne Text', $abschnitte);
    $fehler = $testAnhang($seite, 'kaputt.pdf');
    $leer = $testAnhang($seite, 'leer.pdf');
    $link = $testAnhang($seite, 'extern', true);
    Dateitext::speichern('anhang', $fehler->id, 'fehler', null, null, null, 'Zeitüberschreitung');
    Dateitext::speichern('anhang', $leer->id, 'fertig', "  \n \n ");
    Dateitext::speichern('anhang', $link->id, 'fertig', 'Text eines Links');
    $status = DB::table(Dateitext::TABELLE)->where('art', 'anhang')->where('quelle_id', $leer->id)->value('status');
    $bloecke = $blockAnzahl($seite->refresh()->html);
    return $bloecke === 0 && $status === 'ohne_text' ? true : "Blöcke {$bloecke}, Status leerer Text: {$status}";
});

test('Gelöschter Anhang: Text verschwindet aus Artikel und Suche', function () use ($scanArtikel, $blockAnzahl, $sucheZeigt) {
    Attachment::query()->where('uploaded_to', $scanArtikel->id)->where('name', MARKER . ' wartungsvertrag.pdf')->delete();
    Dateitext::seiteAktualisieren($scanArtikel->id);
    $html = $scanArtikel->refresh()->html;
    if ($blockAnzahl($html) !== 0 || str_contains($html, 'Drosselklappenprotokoll')) {
        return 'Block noch da';
    }
    return $sucheZeigt('Drosselklappenprotokoll', ADMIN, $scanArtikel) ? 'Suche findet den Artikel noch' : true;
});

test('KI zerlegt Artikel ohne den Anhang-Block', function () use ($testArtikel) {
    $teile = Ki::zerlegen($testArtikel('<h2>Schritte</h2><p>Postfach im Admin Center anlegen und Personen hinzufügen.</p><details id="'
        . Dateitext::BLOCK_ID . '"><summary>Text aus Anhängen</summary><p>Drosselklappenprotokoll mit sehr viel Scantext</p></details>'));
    return count($teile) === 1 && !str_contains($teile[0]['text'], 'Drosselklappenprotokoll') ? true : json_encode($teile, JSON_UNESCAPED_UNICODE);
});

test('KI bekommt den Text aus Anhängen je Datei als eigenes Stück, mit dem Dateinamen als Überschrift', function () use ($anleitungen, $abschnitte, $testAnhang) {
    als(ADMIN);
    $seite = testArtikel($anleitungen, 'Testanhang KI', $abschnitte);
    $anhang = $testAnhang($seite, 'vertrag.pdf');
    Dateitext::speichern('anhang', $anhang->id, 'fertig', "Wartungsvertrag Laufzeit 36 Monate\n\nKündigungsfrist drei Monate zum Quartalsende");
    $teile = Ki::zerlegen($seite->refresh());
    $ueberschrift = 'Anhang „' . MARKER . ' vertrag.pdf“';
    $datei = array_values(array_filter($teile, fn ($t) => $t['ueberschrift'] === $ueberschrift));
    $vermischt = array_filter($teile, fn ($t) => $t['ueberschrift'] !== $ueberschrift && str_contains($t['text'], 'Kündigungsfrist'));
    return count($datei) === 1 && str_contains($datei[0]['text'], 'Kündigungsfrist') && !$vermischt
        ? true : json_encode(array_column($teile, 'ueberschrift'), JSON_UNESCAPED_UNICODE);
});

test('Links zwischen Artikeln überstehen das Anhängen und Entfernen des Blocks', function () use ($anleitungen, $handbuchArtikel, $testAnhang, $seitenRepo) {
    als(ADMIN);
    $ziel = url($handbuchArtikel->getUrl());
    $inhalt = '<h2>Verweise</h2><p>Mehr dazu im <a href="' . e($ziel) . '">Handbuch</a> und weiter unten bei <a href="#bkmrk-verweise">Verweisen</a>.</p>';
    $seite = $seitenRepo->update(testArtikel($anleitungen, 'Testanhang Verweise', $inhalt), ['html' => $inhalt]);
    $vorher = $seite->html;
    $anhang = $testAnhang($seite, 'verweise.pdf');
    Dateitext::speichern('anhang', $anhang->id, 'fertig', 'Dateitext zu den Verweisen');
    $mitBlock = $seite->refresh()->html;
    $anhang->delete();
    Dateitext::seiteAktualisieren($seite->id);
    $nachher = $seite->refresh()->html;
    if (!str_contains($mitBlock, 'href="' . e($ziel) . '"') || !str_contains($mitBlock, 'href="#bkmrk-verweise"')) {
        return 'Links fehlen, solange der Block hängt';
    }
    return $nachher === $vorher ? true : "HTML nach dem Entfernen verändert:\n{$vorher}\n{$nachher}";
});

test('Ein eingebundener Artikel bringt seinen Dateitext nicht mit', function () use ($anleitungen, $abschnitte, $testAnhang, $seitenRepo, $blockAnzahl) {
    als(ADMIN);
    $quelle = testArtikel($anleitungen, 'Testanhang Einbindung Quelle', $abschnitte);
    $anhang = $testAnhang($quelle, 'einbindung.pdf');
    Dateitext::speichern('anhang', $anhang->id, 'fertig', 'Geheimschrankcode aus dem Anhang');
    $ziel = testArtikel($anleitungen, 'Testanhang Einbindung Ziel', '<p>Platzhalter</p>');
    $seitenRepo->update($ziel, ['html' => '<p>Vorher</p><p>{{@' . $quelle->id . '}}</p>']);
    $html = aufruf(parse_url($ziel->refresh()->getUrl(), PHP_URL_PATH), ADMIN)['html'];
    return str_contains($html, 'Admin Center öffnen') && !str_contains($html, 'Geheimschrankcode') && $blockAnzahl($html) === 0
        ? true : 'Einbindung: Inhalt ' . json_encode(str_contains($html, 'Admin Center öffnen')) . ', Dateitext ' . json_encode(str_contains($html, 'Geheimschrankcode')) . ', Blöcke ' . $blockAnzahl($html);
});

// --- Anstoßen über Model-Events ---

$angestossen = fn (string $klasse) => Queue::pushed($klasse)->map(fn ($job) => $job instanceof ErkennenJob ? $job->quelleId : $job->pageId)->all();

test('Hochgeladene PDF-Datei stößt die Erkennung an, Word-Datei und Link nicht', function () use ($scanArtikel, $testAnhang, $angestossen) {
    $pdf = $testAnhang($scanArtikel, 'angebot.PDF');
    $word = $testAnhang($scanArtikel, 'angebot.docx');
    $link = $testAnhang($scanArtikel, 'angebot', true);
    $ids = $angestossen(ErkennenJob::class);
    return in_array($pdf->id, $ids, true) && !in_array($word->id, $ids, true) && !in_array($link->id, $ids, true)
        ? true : 'angestoßen: ' . json_encode($ids);
});

test('Umbenennen und Verschieben bauen den Block beider Artikel neu, ersetzte Datei wird neu erkannt', function () use ($scanArtikel, $handbuchArtikel, $testAnhang, $angestossen) {
    $anhang = $testAnhang($scanArtikel, 'umzug.pdf');
    Queue::fake();
    $anhang->forceFill(['uploaded_to' => $handbuchArtikel->id])->save();
    $verschoben = $angestossen(SeiteJob::class);
    Queue::fake();
    $anhang->forceFill(['path' => 'uploads/files/fundus-test/umzug-neu.pdf'])->save();
    $ersetzt = $angestossen(ErkennenJob::class);
    $fehlt = array_diff([$scanArtikel->id, $handbuchArtikel->id], $verschoben);
    return !$fehlt && $ersetzt === [$anhang->id] ? true : 'Seiten: ' . json_encode($verschoben) . ', Erkennung: ' . json_encode($ersetzt);
});

test('Löschen entfernt den Text, der Job räumt den Artikel auf', function () use ($anleitungen, $abschnitte, $testAnhang, $angestossen, $blockAnzahl) {
    $seite = testArtikel($anleitungen, 'Testanhang Löschen', $abschnitte);
    $anhang = $testAnhang($seite, 'weg.pdf');
    Dateitext::speichern('anhang', $anhang->id, 'fertig', 'Verschwindetext bleibt nicht');
    $vorher = $blockAnzahl($seite->refresh()->html);
    Queue::fake();
    $anhang->delete();
    $zeile = DB::table(Dateitext::TABELLE)->where('art', 'anhang')->where('quelle_id', $anhang->id)->exists();
    $jobs = $angestossen(SeiteJob::class);
    (new SeiteJob($seite->id))->handle();
    $nachher = $blockAnzahl($seite->refresh()->html);
    return $vorher === 1 && !$zeile && $jobs === [$seite->id] && $nachher === 0 ? true : "vorher {$vorher}, Zeile " . json_encode($zeile) . ', Jobs ' . json_encode($jobs) . ", nachher {$nachher}";
});

// --- Erkennung gegen einen nachgestellten Dienst ---

// Frische HTTP-Fabrik: Die KI-Tests haben „*“ schon mit Vektoren belegt, und der erste passende Stub gewinnt.
Http::swap(new Illuminate\Http\Client\Factory());
$GLOBALS['ocr'] = ['anfragen' => [], 'antwort' => fn () => Http::response(['text' => 'leer'])];
Http::fake(['*' => function ($anfrage) {
    $GLOBALS['ocr']['anfragen'][] = ['url' => $anfrage->url(), 'body' => $anfrage->body()];
    return ($GLOBALS['ocr']['antwort'])();
}]);
$hochladen = fn (Page $seite, string $name, string $inhalt) => app(BookStack\Uploads\AttachmentService::class)
    ->saveNewUpload(Illuminate\Http\UploadedFile::fake()->createWithContent(MARKER . ' ' . $name, $inhalt), $seite->id);
$scanInhalt = "%PDF-1.4\nfundus-test " . bin2hex(random_bytes(8));

test('Erkennung schickt die Datei an den Dienst, der Text landet im Artikel und in der Suche', function () use ($scanArtikel, $hochladen, $scanInhalt, $sucheZeigt) {
    als(ADMIN);
    $anhang = $hochladen($scanArtikel, 'lieferschein.pdf', $scanInhalt);
    $GLOBALS['ocr']['antwort'] = fn () => Http::response(['text' => "Lieferscheinnummer Kupferrohrbestellung\nMenge 12", 'konfidenz' => 88.4]);
    (new ErkennenJob('anhang', $anhang->id))->handle();
    $anfrage = end($GLOBALS['ocr']['anfragen']);
    $zeile = DB::table(Dateitext::TABELLE)->where('art', 'anhang')->where('quelle_id', $anhang->id)->first();
    if (!$anfrage || $anfrage['url'] !== 'http://ocr-attrappe.invalid:8080/erkennen' || $anfrage['body'] !== $scanInhalt) {
        return 'Anfrage an den Dienst stimmt nicht: ' . json_encode($anfrage ? ['url' => $anfrage['url'], 'bytes' => strlen($anfrage['body'])] : null);
    }
    if ($zeile?->status !== 'fertig' || (float) $zeile->konfidenz !== 88.4 || $zeile->sha256 !== hash('sha256', $scanInhalt)) {
        return 'Tabelle: ' . json_encode($zeile);
    }
    return $sucheZeigt('Kupferrohrbestellung', MITARBEITER, $scanArtikel) ? true : 'Suche findet den Text nicht';
});

test('Dieselbe Datei an einem zweiten Artikel wird nicht erneut an den Dienst geschickt', function () use ($anleitungen, $abschnitte, $hochladen, $scanInhalt) {
    $seite = testArtikel($anleitungen, 'Testanhang Kopie', $abschnitte);
    $anhang = $hochladen($seite, 'lieferschein-kopie.pdf', $scanInhalt);
    $anfragen = count($GLOBALS['ocr']['anfragen']);
    (new ErkennenJob('anhang', $anhang->id))->handle();
    $status = DB::table(Dateitext::TABELLE)->where('art', 'anhang')->where('quelle_id', $anhang->id)->value('status');
    return count($GLOBALS['ocr']['anfragen']) === $anfragen && $status === 'fertig' && str_contains($seite->refresh()->html, 'Kupferrohrbestellung')
        ? true : 'Anfragen ' . (count($GLOBALS['ocr']['anfragen']) - $anfragen) . ", Status {$status}";
});

test('Unlesbare Datei endet ohne Wiederholung als Fehler, Serverfehler wird wiederholt', function () use ($scanArtikel, $hochladen) {
    $kaputt = $hochladen($scanArtikel, 'kaputt.pdf', "%PDF-1.4\n" . random_bytes(16));
    $GLOBALS['ocr']['antwort'] = fn () => Http::response(['fehler' => 'pdfinfo: Syntax Error'], 422);
    (new ErkennenJob('anhang', $kaputt->id))->handle();
    $zeile = DB::table(Dateitext::TABELLE)->where('art', 'anhang')->where('quelle_id', $kaputt->id)->first();
    if ($zeile?->status !== 'fehler' || $zeile->fehler !== 'pdfinfo: Syntax Error') {
        return '422: ' . json_encode($zeile);
    }
    $spaeter = $hochladen($scanArtikel, 'spaeter.pdf', "%PDF-1.4\n" . random_bytes(16));
    $GLOBALS['ocr']['antwort'] = fn () => Http::response('Bad Gateway', 502);
    $wirft = wirft(fn () => (new ErkennenJob('anhang', $spaeter->id))->handle(), Illuminate\Http\Client\RequestException::class);
    (new ErkennenJob('anhang', $spaeter->id))->failed(new RuntimeException('endgültig'));
    $status = DB::table(Dateitext::TABELLE)->where('art', 'anhang')->where('quelle_id', $spaeter->id)->value('status');
    return $wirft === true && $status === 'fehler' ? true : "502 wirft: {$wirft}, nach letztem Versuch: {$status}";
});

// --- Bilder im Artikel ---

test('Eingefügtes Bild stößt die Erkennung an, draw.io-Diagramm und Profilbild nicht', function () use ($scanArtikel, $testBild, $angestossen) {
    als(ADMIN);
    Queue::fake();
    $bild = $testBild($scanArtikel, 'fehlermeldung.png');
    $diagramm = $testBild($scanArtikel, 'ablauf.png', 'drawio');
    $profil = $testBild($scanArtikel, 'profil.png', 'user');
    $ids = Queue::pushed(ErkennenJob::class)->map(fn ($job) => [$job->art, $job->quelleId])->all();
    return $ids === [['bild', $bild->id]] ? true : 'angestoßen: ' . json_encode($ids) . ' (Diagramm ' . $diagramm->id . ', Profil ' . $profil->id . ')';
});

test('Text aus einem Bild steht mit Bildnamen im Block und ist auffindbar', function () use ($anleitungen, $abschnitte, $testBild, $bildEinfuegen, $sucheZeigt, $blockAnzahl) {
    als(ADMIN);
    $seite = testArtikel($anleitungen, 'Testanhang Bildschirmfoto', $abschnitte);
    $bild = $testBild($seite, 'fehlermeldung.png');
    $bildEinfuegen($seite, $bild, $abschnitte);
    Dateitext::speichern('bild', $bild->id, 'fertig', 'Fehlercode Bremsstaubmeldung 0x80070005', 77.0);
    $html = $seite->refresh()->html;
    if ($blockAnzahl($html) !== 1 || !str_contains($html, 'Bremsstaubmeldung') || !str_contains($html, e($bild->name))) {
        return 'Block: ' . $blockAnzahl($html) . ', Text ' . json_encode(str_contains($html, 'Bremsstaubmeldung'));
    }
    return $sucheZeigt('Bremsstaubmeldung', MITARBEITER, $seite) ? true : 'Suche findet den Bildtext nicht';
});

test('Aus dem Text genommenes Bild: sein Text verschwindet aus Artikel und Suche, das Bild bleibt in der Galerie', function () use ($anleitungen, $abschnitte, $testBild, $bildEinfuegen, $seitenRepo, $sucheZeigt, $blockAnzahl) {
    als(ADMIN);
    $seite = testArtikel($anleitungen, 'Testanhang Bild entfernt', $abschnitte);
    $bild = $testBild($seite, 'zettel.png');
    $bildEinfuegen($seite, $bild, $abschnitte);
    Dateitext::speichern('bild', $bild->id, 'fertig', 'Kennwortzettel Tresorkombination Heizungskeller');
    $vorher = $sucheZeigt('Tresorkombination', ADMIN, $seite);
    // Wie im Editor: Bild markieren, löschen, Artikel speichern.
    $html = $seitenRepo->update($seite->refresh(), ['html' => $abschnitte])->html;
    $inGalerie = BookStack\Uploads\Image::query()->whereKey($bild->id)->exists();
    $nachher = $sucheZeigt('Tresorkombination', ADMIN, $seite);
    return $vorher && $inGalerie && $blockAnzahl($html) === 0 && !str_contains($html, 'Tresorkombination') && !$nachher
        ? true : 'vorher gefunden ' . json_encode($vorher) . ', Block ' . $blockAnzahl($html) . ', nachher gefunden ' . json_encode($nachher);
});

test('Gelöschtes Bild: Text verschwindet, Anhangtext desselben Artikels bleibt', function () use ($anleitungen, $abschnitte, $testBild, $bildEinfuegen, $testAnhang, $blockAnzahl) {
    als(ADMIN);
    $seite = testArtikel($anleitungen, 'Testanhang Bild und Anhang', $abschnitte);
    $anhang = $testAnhang($seite, 'beides.pdf');
    $bild = $testBild($seite, 'beides.png');
    $bildEinfuegen($seite, $bild, $abschnitte);
    Dateitext::speichern('anhang', $anhang->id, 'fertig', 'Anhangwort Kesselschein');
    Dateitext::speichern('bild', $bild->id, 'fertig', 'Bildwort Schaltschrankfoto');
    $beide = $seite->refresh()->html;
    app(BookStack\Uploads\ImageService::class)->destroy($bild);
    Dateitext::seiteAktualisieren($seite->id);
    $html = $seite->refresh()->html;
    $zeile = DB::table(Dateitext::TABELLE)->where('art', 'bild')->where('quelle_id', $bild->id)->exists();
    return str_contains($beide, 'Kesselschein') && str_contains($beide, 'Schaltschrankfoto') && !$zeile
        && $blockAnzahl($html) === 1 && str_contains($html, 'Kesselschein') && !str_contains($html, 'Schaltschrankfoto')
        ? true : 'vorher ' . json_encode(str_contains($beide, 'Schaltschrankfoto')) . ', nachher Block ' . $blockAnzahl($html) . ' Zeile ' . json_encode($zeile);
});

test('Befehl fundus:datei-text stellt nur noch nicht erkannte Anhänge und Bilder in die Warteschlange', function () use ($scanArtikel, $testAnhang, $testBild, $angestossen) {
    $offen = $testAnhang($scanArtikel, 'offen.pdf');
    $offenesBild = $testBild($scanArtikel, 'offen.png');
    $erledigt = DB::table(Dateitext::TABELLE)->where('status', 'fertig')->where('art', 'anhang')->value('quelle_id');
    Queue::fake();
    $code = Illuminate\Support\Facades\Artisan::call('fundus:datei-text');
    // Anhang und Bild können dieselbe id haben, deshalb mit Art vergleichen.
    $ids = Queue::pushed(ErkennenJob::class)->map(fn ($job) => "{$job->art}:{$job->quelleId}")->all();
    $fehlt = array_diff(["anhang:{$offen->id}", "bild:{$offenesBild->id}"], $ids);
    return $code === 0 && !$fehlt && !in_array("anhang:{$erledigt}", $ids, true)
        ? true : "Rückgabe {$code}, fehlt: " . json_encode($fehlt) . ', angestoßen: ' . json_encode($ids);
});

$anhaengeAufraeumen();

// ---------------------------------------------------------------------------
abschnitt('KI-Index und Papierkorb');
// ---------------------------------------------------------------------------

// Die Warteschlange ist seit „Text aus Anhängen“ nachgestellt: Geprüft wird, welche Artikel neu eingebettet werden.
$wiederherstellen = function ($element) {
    $eintrag = BookStack\Entities\Models\Deletion::query()->where('deletable_type', $element->getMorphClass())
        ->where('deletable_id', $element->id)->latest('id')->firstOrFail();
    Queue::fake();
    app(BookStack\Entities\Repos\DeletionRepo::class)->restore($eintrag->id);
    return Queue::pushed(FundusKi\IndexJob::class)->map(fn ($job) => $job->pageId)->all();
};

test('Aus dem Papierkorb geholter Artikel kommt zurück in den KI-Index', function () use ($anleitungen, $abschnitte, $wiederherstellen) {
    als(ADMIN);
    $seite = testArtikel($anleitungen, 'Testartikel Papierkorb', $abschnitte);
    app(BookStack\Entities\Repos\PageRepo::class)->destroy($seite);
    $ids = $wiederherstellen($seite);
    return in_array($seite->id, $ids, true) ? true : 'nicht neu eingebettet, angestoßen: ' . json_encode($ids);
});

test('Ein wiederhergestelltes Thema bringt seine Artikel zurück in den KI-Index', function () use ($abschnitte, $wiederherstellen) {
    als(ADMIN);
    $thema = testThema('Testpapierkorb Fundus-Test', ['view']);
    $seite = testArtikel($thema, 'Testartikel im Papierkorb-Thema', $abschnitte);
    app(BookStack\Entities\Repos\BookRepo::class)->destroy($thema);
    $ids = $wiederherstellen($thema);
    return in_array($seite->id, $ids, true) ? true : 'nicht neu eingebettet, angestoßen: ' . json_encode($ids);
});

// ---------------------------------------------------------------------------
abschnitt('Sicherung: Hinweise an Admins');
// ---------------------------------------------------------------------------

$statusDatei = sys_get_temp_dir() . '/fundus-sicherung-test.json';
$statusSchreiben = function (array $status) use ($statusDatei) {
    file_put_contents($statusDatei, json_encode($status));
};
$sicherungHinweise = fn (int $person, ?Carbon\Carbon $jetzt = null) => Hinweise::sicherung($person, $statusDatei, $jetzt);
$gestern = Carbon\Carbon::parse('2026-09-18 02:30:00', 'Europe/Berlin');
$heute = Carbon\Carbon::parse('2026-09-18 09:00:00', 'Europe/Berlin');

test('Ohne Statusdatei (vor der ersten Nacht) meldet Fundus nichts', function () use ($statusDatei, $sicherungHinweise) {
    @unlink($statusDatei);
    return $sicherungHinweise(ADMIN) === [] ? true : json_encode($sicherungHinweise(ADMIN));
});

test('Sicherung und Kopie in Ordnung: kein Hinweis', function () use ($statusSchreiben, $sicherungHinweise, $gestern, $heute) {
    $statusSchreiben(['zeit' => $gestern->toIso8601String(), 'ergebnis' => 'ok', 'kopie' => 'an', 'verschluesselt' => 'ja', 'meldung' => '']);
    return $sicherungHinweise(ADMIN, $heute) === [] ? true : json_encode($sicherungHinweise(ADMIN, $heute));
});

test('Kopie ohne Verschlüsselung: ruhiger Hinweis', function () use ($statusSchreiben, $sicherungHinweise, $gestern, $heute) {
    $statusSchreiben(['zeit' => $gestern->toIso8601String(), 'ergebnis' => 'ok', 'kopie' => 'an', 'verschluesselt' => 'nein', 'meldung' => '']);
    $h = $sicherungHinweise(ADMIN, $heute);
    return count($h) === 1 && $h[0]['schluessel'] === 'sicherung:3' && !$h[0]['dringend'] ? true : json_encode($h);
});

test('Ohne Kopie außer Haus: ruhiger Hinweis, nicht dringend', function () use ($statusSchreiben, $sicherungHinweise, $gestern, $heute) {
    $statusSchreiben(['zeit' => $gestern->toIso8601String(), 'ergebnis' => 'ok', 'kopie' => 'aus', 'meldung' => '']);
    $h = $sicherungHinweise(ADMIN, $heute);
    return count($h) === 1 && $h[0]['schluessel'] === 'sicherung:0' && !$h[0]['dringend'] && $h[0]['art'] === 'sicherung'
        ? true : json_encode($h);
});

test('Gescheiterte Kopie: dringend, mit der Meldung aus dem Protokoll', function () use ($statusSchreiben, $sicherungHinweise, $gestern, $heute) {
    $statusSchreiben(['zeit' => $gestern->toIso8601String(), 'ergebnis' => 'kopie-fehler', 'kopie' => 'an',
        'meldung' => 'Die Kopie außer Haus ist gescheitert: connection refused']);
    $h = $sicherungHinweise(ADMIN, $heute);
    return count($h) === 1 && $h[0]['dringend'] && $h[0]['titel'] === 'Kopie außer Haus fehlgeschlagen'
        && str_contains($h[0]['text'], 'connection refused') && str_contains($h[0]['text'], '18.09.2026 um 02:30')
        ? true : json_encode($h);
});

test('Gescheiterte Sicherung: dringend', function () use ($statusSchreiben, $sicherungHinweise, $gestern, $heute) {
    $statusSchreiben(['zeit' => $gestern->toIso8601String(), 'ergebnis' => 'sicherung-fehler', 'kopie' => 'an', 'meldung' => '']);
    $h = $sicherungHinweise(ADMIN, $heute);
    return count($h) === 1 && $h[0]['dringend'] && $h[0]['titel'] === 'Sicherung fehlgeschlagen' ? true : json_encode($h);
});

test('Seit über 26 Stunden keine Sicherung: dringend, auch wenn die letzte gut war', function () use ($statusSchreiben, $sicherungHinweise, $gestern) {
    $statusSchreiben(['zeit' => $gestern->toIso8601String(), 'ergebnis' => 'ok', 'kopie' => 'an', 'meldung' => '']);
    $h = $sicherungHinweise(ADMIN, $gestern->copy()->addHours(27));
    return count($h) === 1 && $h[0]['dringend'] && $h[0]['titel'] === 'Keine Sicherung seit 18.09.2026' ? true : json_encode($h);
});

test('Nur Admins bekommen Hinweise zur Sicherung', function () use ($statusSchreiben, $sicherungHinweise, $gestern, $heute) {
    $statusSchreiben(['zeit' => $gestern->toIso8601String(), 'ergebnis' => 'sicherung-fehler', 'kopie' => 'aus', 'meldung' => '']);
    return $sicherungHinweise(MITARBEITER, $heute) === [] && $sicherungHinweise(AZUBI, $heute) === []
        ? true : 'Mitarbeiterin oder Azubi sehen den Hinweis';
});

test('Schlüssel lassen sich als gesehen merken (Muster der Route)', function () use ($statusSchreiben, $sicherungHinweise, $gestern, $heute) {
    $statusSchreiben(['zeit' => $gestern->toIso8601String(), 'ergebnis' => 'kopie-fehler', 'kopie' => 'an', 'meldung' => 'x']);
    $a = $sicherungHinweise(ADMIN, $heute)[0]['schluessel'];
    $statusSchreiben(['zeit' => $gestern->toIso8601String(), 'ergebnis' => 'ok', 'kopie' => 'aus', 'meldung' => '']);
    $b = $sicherungHinweise(ADMIN, $heute)[0]['schluessel'];
    $muster = '/^[a-z]+:[0-9:]+$/';
    return preg_match($muster, $a) && preg_match($muster, $b) ? true : "{$a}, {$b}";
});

@unlink($statusDatei);

// ---------------------------------------------------------------------------
abschnitt('Anmeldung: Berufstitel aus dem Token');
// ---------------------------------------------------------------------------

Anmeldung::tabelleAnlegen();
$titelVon = fn (int $id) => DB::table(Anmeldung::TABELLE)->where('user_id', $id)->first();
DB::table(Anmeldung::TABELLE)->where('user_id', MITARBEITER)->delete();

test('Stufe aus dem Titel, wenn der Anmeldedienst keine liefert', function () {
    $erwartet = [
        'CEO' => 'leitung', 'Geschäftsführerin' => 'leitung', 'Head of Sales' => 'leitung',
        'Senior Consultant' => 'senior', 'Teamleitung Support' => 'senior',
        'Auszubildender Fachinformatiker' => 'azubi', 'Werkstudentin' => 'azubi',
        'Junior Consultant' => 'junior', 'Systemadministrator' => 'junior',
        'Buchhaltung' => 'team',
    ];
    $falsch = [];
    foreach ($erwartet as $titel => $stufe) {
        if (Anmeldung::stufeAusTitel($titel) !== $stufe) {
            $falsch[] = "{$titel} → " . Anmeldung::stufeAusTitel($titel) . " statt {$stufe}";
        }
    }
    return $falsch ? implode(', ', $falsch) : true;
});

test('Titel und Stufe aus dem Token werden gespeichert', function () use ($titelVon) {
    Anmeldung::titelUebernehmen(MITARBEITER, ['titel' => 'Junior Consultant', 'titel_stufe' => 'Senior']);
    $e = $titelVon(MITARBEITER);
    return $e && $e->titel === 'Junior Consultant' && $e->stufe === 'senior' ? true : json_encode($e);
});

test('Unbekannte Stufe im Token: aus dem Titel abgeleitet', function () use ($titelVon) {
    Anmeldung::titelUebernehmen(MITARBEITER, ['titel' => 'Auszubildende', 'titel_stufe' => 'chef']);
    return $titelVon(MITARBEITER)->stufe === 'azubi' ? true : json_encode($titelVon(MITARBEITER));
});

test('Keycloak liefert Attribute als Liste: der erste Wert zählt', function () use ($titelVon) {
    Anmeldung::titelUebernehmen(MITARBEITER, ['titel' => ['Systemadministrator', 'anderes']]);
    return $titelVon(MITARBEITER)->titel === 'Systemadministrator' ? true : json_encode($titelVon(MITARBEITER));
});

test('Fehlt der Claim im Token, bleibt der gespeicherte Titel', function () use ($titelVon) {
    Anmeldung::titelUebernehmen(MITARBEITER, ['name' => 'Mia']);
    return $titelVon(MITARBEITER)?->titel === 'Systemadministrator' ? true : json_encode($titelVon(MITARBEITER));
});

test('Ein leerer Claim löscht den Titel', function () use ($titelVon) {
    Anmeldung::titelUebernehmen(MITARBEITER, ['titel' => '  ']);
    return $titelVon(MITARBEITER) === null ? true : json_encode($titelVon(MITARBEITER));
});

test('Überlange Titel werden auf 120 Zeichen gekürzt', function () use ($titelVon) {
    Anmeldung::titelUebernehmen(MITARBEITER, ['titel' => str_repeat('x', 200)]);
    return mb_strlen($titelVon(MITARBEITER)->titel) === 120 ? true : (string) mb_strlen($titelVon(MITARBEITER)->titel);
});

test('Titel aus dem Anmeldedienst gehen vor titel.php, sonst gilt titel.php nach E-Mail', function () {
    // Das lokale Wiki kann dem Admin schon einen Titel gegeben haben; für diesen Fall darf er keinen haben.
    DB::table(Anmeldung::TABELLE)->where('user_id', ADMIN)->delete();
    Anmeldung::titelUebernehmen(MITARBEITER, ['titel' => 'Teamleitung Support']);
    $admin = \BookStack\Users\Models\User::query()->find(ADMIN);
    $liste = (array) require theme_path('titel.php');
    $ausDatei = $liste[strtolower((string) $admin->email)]['titel'] ?? null;
    $t = Anmeldung::titelFuer([\BookStack\Users\Models\User::query()->find(MITARBEITER), $admin]);
    $mia = $t[MITARBEITER]['titel'] ?? null;
    $adm = $t[ADMIN]['titel'] ?? null;
    return $mia === 'Teamleitung Support' && $adm === $ausDatei ? true : "Mitarbeiterin: {$mia}, Admin: {$adm} (titel.php: {$ausDatei})";
});

test('Nur eine OIDC-Anmeldung übernimmt den Titel', function () use ($titelVon) {
    DB::table(Anmeldung::TABELLE)->where('user_id', MITARBEITER)->delete();
    $nutzer = \BookStack\Users\Models\User::query()->find(MITARBEITER);
    Anmeldung::claimsMerken(['titel' => 'Standard-Anmeldung'], []);
    Anmeldung::nachAnmeldung('standard', $nutzer);
    $nachStandard = $titelVon(MITARBEITER);
    Anmeldung::claimsMerken(['titel' => 'OIDC-Anmeldung'], []);
    Anmeldung::nachAnmeldung('oidc', $nutzer);
    $nachOidc = $titelVon(MITARBEITER);
    return $nachStandard === null && $nachOidc?->titel === 'OIDC-Anmeldung'
        ? true : 'standard: ' . json_encode($nachStandard) . ', oidc: ' . json_encode($nachOidc);
});

DB::table(Anmeldung::TABELLE)->where('user_id', MITARBEITER)->delete();

// ---------------------------------------------------------------------------
abschnitt('Berufstitel im Wiki pflegen');
// ---------------------------------------------------------------------------

$formular = fn (array $felder) => Request::create('/settings/users/' . MITARBEITER, 'PUT', $felder);
$mia = \BookStack\Users\Models\User::query()->find(MITARBEITER);

test('Admin setzt Titel und Stufe im Formular', function () use ($formular, $mia, $titelVon) {
    als(ADMIN);
    Anmeldung::ausFormular($mia, $formular(['fundus_titel' => 'Senior Consultant', 'fundus_stufe' => 'senior']));
    $e = $titelVon(MITARBEITER);
    return $e && $e->titel === 'Senior Consultant' && $e->stufe === 'senior' ? true : json_encode($e);
});

test('Stufe „Automatisch“ wird aus dem Titel abgeleitet', function () use ($formular, $mia, $titelVon) {
    als(ADMIN);
    Anmeldung::ausFormular($mia, $formular(['fundus_titel' => 'Auszubildende Kauffrau', 'fundus_stufe' => '']));
    return $titelVon(MITARBEITER)->stufe === 'azubi' ? true : json_encode($titelVon(MITARBEITER));
});

test('Ohne Recht „Benutzer verwalten“ ändert das Formular nichts', function () use ($formular, $mia, $titelVon) {
    als(MITARBEITER);
    Anmeldung::ausFormular($mia, $formular(['fundus_titel' => 'CEO', 'fundus_stufe' => 'leitung']));
    als(ADMIN);
    return $titelVon(MITARBEITER)->titel === 'Auszubildende Kauffrau' ? true : 'Mitarbeiterin konnte ihren Titel setzen';
});

test('Formulare ohne das Feld (etwa „Mein Profil“) lassen den Titel stehen', function () use ($formular, $mia, $titelVon) {
    als(ADMIN);
    Anmeldung::ausFormular($mia, $formular(['name' => 'Mia']));
    return $titelVon(MITARBEITER)?->titel === 'Auszubildende Kauffrau' ? true : json_encode($titelVon(MITARBEITER));
});

test('Leerer Titel entfernt ihn', function () use ($formular, $mia, $titelVon) {
    als(ADMIN);
    Anmeldung::ausFormular($mia, $formular(['fundus_titel' => ' ', 'fundus_stufe' => 'senior']));
    return $titelVon(MITARBEITER) === null ? true : json_encode($titelVon(MITARBEITER));
});

test('Das Formular einer Person zeigt Admins das Feld, mit gespeichertem Wert', function () use ($formular, $mia) {
    als(ADMIN);
    Anmeldung::ausFormular($mia, $formular(['fundus_titel' => 'Teamleitung Support', 'fundus_stufe' => '']));
    $seite = aufruf('/settings/users/' . MITARBEITER, ADMIN);
    return $seite['status'] === 200 && str_contains($seite['html'], 'name="fundus_titel"') && str_contains($seite['html'], 'value="Teamleitung Support"')
        ? true : 'Status ' . $seite['status'];
});

// Im Wiki kommt eine leere Variable gar nicht an: PHP-FPM lässt leere Umgebungsvariablen weg. Beides muss „aus“ heißen.
test('Mit leerem oder fehlendem FUNDUS_TITEL_CLAIM überschreibt die Anmeldung den Titel nicht', function () use ($titelVon) {
    $vorher = $_ENV['FUNDUS_TITEL_CLAIM'] ?? null;
    $ergebnis = [];
    try {
        foreach (['leer' => '', 'fehlt' => null] as $fall => $wert) {
            if ($wert === null) {
                unset($_ENV['FUNDUS_TITEL_CLAIM'], $_SERVER['FUNDUS_TITEL_CLAIM']);
                putenv('FUNDUS_TITEL_CLAIM');
            } else {
                $_ENV['FUNDUS_TITEL_CLAIM'] = $_SERVER['FUNDUS_TITEL_CLAIM'] = $wert;
                putenv('FUNDUS_TITEL_CLAIM=' . $wert);
            }
            Anmeldung::titelUebernehmen(MITARBEITER, ['titel' => 'Aus dem Token']);
            $ergebnis[$fall] = $titelVon(MITARBEITER)?->titel;
        }
    } finally {
        $_ENV['FUNDUS_TITEL_CLAIM'] = $_SERVER['FUNDUS_TITEL_CLAIM'] = $vorher ?? 'titel';
        putenv('FUNDUS_TITEL_CLAIM=' . ($vorher ?? 'titel'));
    }
    return $ergebnis === ['leer' => 'Teamleitung Support', 'fehlt' => 'Teamleitung Support'] ? true : json_encode($ergebnis, JSON_UNESCAPED_UNICODE);
});

DB::table(Anmeldung::TABELLE)->where('user_id', MITARBEITER)->delete();

// ---------------------------------------------------------------------------
abschnitt('Alle Seiten');
// ---------------------------------------------------------------------------

test('Jede Seite, jedes Thema und jeder Bereich liefert dem Admin 200', function () {
    $pfade = collect(['/', '/books', '/shelves'])
        ->merge(Page::query()->where('draft', false)->get()->map->getUrl())
        ->merge(Book::query()->get()->map->getUrl())
        ->merge(BookStack\Entities\Models\Bookshelf::query()->get()->map->getUrl())
        ->map(fn ($url) => parse_url($url, PHP_URL_PATH));
    $fehler = $pfade->map(fn ($p) => [$p, aufruf($p, ADMIN)['status']])->filter(fn ($e) => $e[1] !== 200);
    return $fehler->isEmpty() ? true : $fehler->map(fn ($e) => "{$e[1]} {$e[0]}")->join(', ');
});

test('Mitarbeiterin bekommt nur 200 oder 404, nie einen Serverfehler', function () {
    $pfade = Page::query()->where('draft', false)->get()->map(fn ($p) => parse_url($p->getUrl(), PHP_URL_PATH));
    $fehler = $pfade->map(fn ($p) => [$p, aufruf($p, MITARBEITER)['status']])->filter(fn ($e) => !in_array($e[1], [200, 404], true));
    return $fehler->isEmpty() ? true : $fehler->map(fn ($e) => "{$e[1]} {$e[0]}")->join(', ');
});

// ---------------------------------------------------------------------------

echo "\n" . $bestanden . ' bestanden, ' . count($fehlgeschlagen) . " fehlgeschlagen\n";
exit($fehlgeschlagen ? 1 : 0);
