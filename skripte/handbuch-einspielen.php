<?php

/*
 * Spielt das Handbuch (handbuch/*.html mit handbuch/bilder) in das laufende Wiki ein.
 *
 * Läuft im Wiki-Container, damit kein API-Token nötig ist; Aufruf über skripte/handbuch-einspielen.sh.
 * Legt fehlende Abschnitte und Artikel an, verschiebt Artikel in den richtigen Abschnitt, lädt die Bilder
 * zum jeweiligen Artikel hoch und speichert den Text als neue Version (die alte bleibt in den Versionen).
 * Die Struktur (welcher Artikel in welchem Abschnitt) kommt aus skripte/einrichten.py.
 *
 *   php handbuch-einspielen.php <Ordner mit struktur.json, *.html, bilder/> <ID des Admin-Kontos> [--trockenlauf]
 */

use BookStack\Entities\Models\Book;
use BookStack\Entities\Models\Chapter;
use BookStack\Entities\Models\Page;
use BookStack\Entities\Repos\ChapterRepo;
use BookStack\Entities\Repos\PageRepo;
use BookStack\Uploads\ImageRepo;
use BookStack\Users\Models\User;
use Illuminate\Support\Facades\Auth;

require '/app/www/vendor/autoload.php';
$app = require '/app/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$_, $ordner, $adminId] = $argv + [null, null, null];
$trocken = in_array('--trockenlauf', $argv, true);
if (!$ordner || !$adminId) {
    fwrite(STDERR, "Aufruf: php handbuch-einspielen.php <Ordner> <Admin-ID> [--trockenlauf]\n");
    exit(1);
}
$admin = User::query()->findOrFail((int) $adminId);
Auth::setUser($admin);

$struktur = json_decode(file_get_contents("{$ordner}/struktur.json"), true, flags: JSON_THROW_ON_ERROR);
$buch = Book::query()->where('name', $struktur['buch'])->firstOrFail();
$pageRepo = app(PageRepo::class);
$chapterRepo = app(ChapterRepo::class);
$imageRepo = app(ImageRepo::class);
$zusammenfassung = 'Handbuch aktualisiert: neue Texte und Bilder mit Markierungen';

foreach ($struktur['kapitel'] as $kNr => $k) {
    $kapitel = Chapter::query()->where('book_id', $buch->id)->where('name', $k['name'])->first();
    if (!$kapitel) {
        echo "+ Abschnitt {$k['name']}\n";
        if (!$trocken) {
            $kapitel = $chapterRepo->create(['name' => $k['name'], 'description_html' => ''], $buch);
        }
    }
    if ($kapitel && !$trocken) {
        $kapitel->priority = ($kNr + 1) * 10;
        $kapitel->save();
    }

    foreach ($k['seiten'] as $sNr => $s) {
        $datei = "{$ordner}/{$s['datei']}.html";
        $html = file_get_contents($datei);
        $seite = Page::query()->where('book_id', $buch->id)->where('draft', false)->where('name', $s['name'])->first();

        if (!$seite) {
            echo "+ Artikel {$k['name']} / {$s['name']}\n";
            if ($trocken) {
                continue;
            }
            $entwurf = $pageRepo->getNewDraftPage($kapitel);
            $seite = $pageRepo->publishDraft($entwurf, ['name' => $s['name'], 'html' => '<p></p>']);
        } elseif ($kapitel && $seite->chapter_id !== $kapitel->id) {
            echo "→ verschiebe {$s['name']} nach {$k['name']}\n";
            if (!$trocken) {
                $pageRepo->move($seite, 'chapter:' . $kapitel->id);
                $seite->refresh();
            }
        }

        // Bilder hochladen und im Text auf die Adressen im Wiki umstellen.
        preg_match_all('/src="(bilder\/[^"]+)"/', $html, $treffer);
        $bilder = 0;
        foreach (array_unique($treffer[1]) as $pfad) {
            $bildDatei = "{$ordner}/{$pfad}";
            if (!is_file($bildDatei)) {
                // Wie einrichten.py: Artikel ohne das Bild einspielen; ein späterer Lauf bringt es nach.
                fwrite(STDERR, "! Bild fehlt, Artikel ohne Bild: {$pfad} ({$s['datei']})\n");
                $html = preg_replace('/<p><img [^>]*src="' . preg_quote($pfad, '/') . '"[^>]*><\/p>\s*/', '', $html);
                continue;
            }
            $bilder++;
            if ($trocken) {
                continue;
            }
            $bild = $imageRepo->saveNewFromData(basename($pfad), file_get_contents($bildDatei), 'gallery', $seite->id);
            $html = str_replace("src=\"{$pfad}\"", 'src="' . $bild->url . '"', $html);
        }

        echo "✓ {$k['name']} / {$s['name']}" . ($bilder ? " ({$bilder} Bilder)" : '') . "\n";
        if ($trocken) {
            continue;
        }
        $seite = $pageRepo->update($seite, ['html' => $html, 'summary' => $zusammenfassung]);
        $seite->priority = ($sNr + 1) * 10;
        $seite->save();
    }
}
echo $trocken ? "Trockenlauf: nichts geändert.\n" : "Fertig.\n";
