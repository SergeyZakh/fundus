<?php

namespace FundusDateitext;

use FundusKi\IndexJob;
use FundusKi\Ki;
use BookStack\Entities\Models\Page;
use BookStack\Entities\Tools\PageContent;
use BookStack\Search\SearchIndex;
use BookStack\Uploads\Attachment;
use BookStack\Uploads\AttachmentService;
use BookStack\Uploads\Image;
use BookStack\Uploads\ImageService;
use BookStack\Util\HtmlDocument;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Text aus Dateien (PDFs, Bilder) für Suche und KI-Chat.
 *
 * Zwei Quellen, in BookStack zwei verschiedene Dinge: Anhänge eines Artikels (Attachment) und Bilder, die im Artikel
 * eingefügt sind (Image, Typ „gallery“). draw.io-Diagramme bleiben außen vor, ihr Text steckt schon als Daten im Bild.
 *
 * BookStack durchsucht nur Name, Inhalt und Schlagwörter eines Artikels, nie seine Dateien. Der erkannte Text liegt
 * deshalb in einer eigenen Tabelle und hängt als eingeklappter Block „Text aus Anhängen und Bildern“ am Ende des
 * Artikels. So findet ihn BookStacks Suche mit ihren Rechten, ohne dass wir in den Suchindex schreiben (den baut
 * BookStack bei jedem Speichern neu).
 *
 * Der Block wird bei jedem Speichern frisch aus der Tabelle gebaut (ThemeEvents::PAGE_CONTENT_PRE_STORE, feuert für
 * WYSIWYG und Markdown). Was im Editor am Block geändert oder gelöscht wurde, zählt also nicht, und Text gelöschter
 * Dateien verschwindet. Erkannt wird der Block an seiner id: Der neue Editor (Lexical) behält an <details> nur id und dir.
 * Bindet ein Artikel einen anderen ein, bleibt dessen Block draußen (ThemeEvents::PAGE_INCLUDE_PARSE).
 *
 * Neuer Text kommt ohne Revision, Aktivität und neues Änderungsdatum in den Artikel (seiteAktualisieren):
 * Eine erkannte Datei ist keine Bearbeitung, niemand soll dafür benachrichtigt werden.
 *
 * Ablauf: Datei hochgeladen oder ersetzt (Model-Events, Anhänge und Bilder schreiben keine Aktivität) → ErkennenJob in
 * der Warteschlange → Datei an den Dienst OCR_URL (Ordner ocr/) → Text in die Tabelle → Artikel aktualisieren.
 * Gleiche Dateien (sha256) werden nur einmal erkannt. Vorhandene Dateien: php artisan fundus:datei-text.
 */
class Dateitext
{
    public const TABELLE = 'fundus_datei_texte';
    /** Muss mit „bkmrk“ beginnen: Andere ids ersetzt BookStack beim Speichern (PageContent::setUniqueId). */
    public const BLOCK_ID = 'bkmrk-fundus-dateitext';
    public const UEBERSCHRIFT = 'Text aus Anhängen und Bildern';

    /** Anhang eines Artikels oder eingefügtes Bild. */
    public const ARTEN = ['anhang', 'bild'];

    /** Dateiendungen, die der Dienst liest. Er prüft den Inhalt selbst noch einmal. */
    public const ENDUNGEN = ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff', 'gif', 'webp', 'bmp'];

    /** Obergrenze pro Datei, damit große Scans Artikel, Editor und Suchgewichtung nicht aufblähen. */
    public const MAX_ZEICHEN = 100000;

    /** wartet: noch nicht erkannt; fertig: Text da; ohne_text: nichts Brauchbares erkannt; fehler: Erkennung gescheitert. */
    public const STATUS = ['wartet', 'fertig', 'ohne_text', 'fehler'];

    /** Sekunden, die eine Erkennung dauern darf (50 Scan-Seiten auf zwei Kernen). */
    public const ZEITLIMIT = 900;

    /** „Bild ersetzen“ speichert erst das Modell und tauscht danach die Datei; so lange wartet der Job. */
    public const VERZUG = 10;

    protected static bool $angelegt = false;

    public static function aktiv(): bool
    {
        return self::url() !== '';
    }

    public static function url(): string
    {
        return rtrim(trim((string) env('OCR_URL', '')), '/');
    }

    // -----------------------------------------------------------------------
    // Quellen: Anhang oder Bild
    // -----------------------------------------------------------------------

    public static function quelle(string $art, int $id): Attachment|Image|null
    {
        return $art === 'anhang' ? Attachment::query()->find($id) : Image::query()->find($id);
    }

    /** Liest der Dienst diese Datei? Bilder nur aus Artikeln, keine draw.io-Diagramme und keine Profilbilder. */
    public static function unterstuetzt(Model $quelle): bool
    {
        if ($quelle instanceof Attachment) {
            $endung = $quelle->extension;
            $passt = !$quelle->external;
        } else {
            $endung = pathinfo((string) $quelle->path, PATHINFO_EXTENSION);
            $passt = $quelle->type === 'gallery' && (int) $quelle->uploaded_to > 0;
        }

        return $passt && in_array(mb_strtolower((string) $endung), self::ENDUNGEN, true);
    }

    public static function art(Model $quelle): string
    {
        return $quelle instanceof Attachment ? 'anhang' : 'bild';
    }

    /** Datei zum Lesen; null, wenn im Speicher nichts (mehr) liegt. */
    protected static function datei(Model $quelle)
    {
        $strom = $quelle instanceof Attachment
            ? app(AttachmentService::class)->streamAttachmentFromStorage($quelle)
            : app(ImageService::class)->getImageStream($quelle);

        return is_resource($strom) ? $strom : null;
    }

    // -----------------------------------------------------------------------
    // Model-Events (laufen in der Anfrage, womöglich in einer Transaktion:
    // nur Jobs anstoßen, nichts anlegen)
    // -----------------------------------------------------------------------

    public static function anhangGespeichert(Attachment $anhang): void
    {
        // wasRecentlyCreated bleibt nach weiteren save() am selben Objekt true; neu ist nur das Anlegen selbst.
        $neu = $anhang->wasRecentlyCreated && !$anhang->getChanges();
        // Neue oder ersetzte Datei. Beim Ersetzen immer, damit alter Text auch verschwindet, wenn die neue
        // Datei nicht lesbar ist.
        if (self::aktiv() && !$anhang->external && (($neu && self::unterstuetzt($anhang)) || (!$neu && $anhang->wasChanged('path')))) {
            ErkennenJob::dispatch('anhang', (int) $anhang->id)->afterCommit();
        }
        if ($neu) {
            return;
        }
        // Umbenannt, verschoben oder zu einem Link geworden: Block der betroffenen Artikel neu bauen.
        if ($anhang->wasChanged(['name', 'uploaded_to', 'external'])) {
            SeiteJob::dispatch((int) $anhang->uploaded_to)->afterCommit();
        }
        if ($anhang->wasChanged('uploaded_to')) {
            SeiteJob::dispatch((int) ($anhang->getPrevious()['uploaded_to'] ?? 0))->afterCommit();
        }
    }

    public static function bildGespeichert(Image $bild): void
    {
        $neu = $bild->wasRecentlyCreated && !$bild->getChanges();
        if (!self::aktiv() || !self::unterstuetzt($bild)) {
            return;
        }
        if ($neu) {
            ErkennenJob::dispatch('bild', (int) $bild->id)->afterCommit();
            return;
        }
        // „Bild ersetzen“ berührt nur die Zeitstempel und tauscht danach die Datei (ImageRepo::updateImageFile).
        if ($bild->wasChanged(['updated_at', 'path'])) {
            ErkennenJob::dispatch('bild', (int) $bild->id)->afterCommit()->delay(now()->addSeconds(self::VERZUG));
        }
        if ($bild->wasChanged('name')) {
            SeiteJob::dispatch((int) $bild->uploaded_to)->afterCommit();
        }
    }

    /** Model-Event „deleted“: Text entfernen, Artikel im Hintergrund aktualisieren. */
    public static function geloescht(Model $quelle): void
    {
        if (!self::tabelleDa()) {
            return;
        }
        DB::table(self::TABELLE)->where('art', self::art($quelle))->where('quelle_id', $quelle->id)->delete();
        SeiteJob::dispatch((int) $quelle->uploaded_to)->afterCommit();
    }

    // -----------------------------------------------------------------------
    // Tabelle
    // -----------------------------------------------------------------------

    /**
     * Anlegen nur außerhalb von Transaktionen (Warteschlange, Befehl): CREATE TABLE committet in MariaDB implizit
     * und bricht so BookStacks Speicher-Transaktion ab („There is no active transaction“). Im Speicher-Hook
     * deshalb nur tabelleDa().
     */
    public static function tabelleAnlegen(): void
    {
        if (self::$angelegt || Cache::get('fundus-dateitext-schema') === 1) {
            self::$angelegt = true;
            return;
        }
        DB::statement('CREATE TABLE IF NOT EXISTS ' . self::TABELLE . ' (
            art VARCHAR(10) NOT NULL,
            quelle_id INT UNSIGNED NOT NULL,
            sha256 CHAR(64) NULL,
            status VARCHAR(20) NOT NULL,
            text MEDIUMTEXT NULL,
            konfidenz DECIMAL(5,2) NULL,
            fehler VARCHAR(500) NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (art, quelle_id),
            KEY sha256 (sha256)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        Cache::forever('fundus-dateitext-schema', 1);
        self::$angelegt = true;
    }

    /** Gibt es die Tabelle schon? Liest nur, legt nichts an; darf also in Transaktionen laufen. */
    public static function tabelleDa(): bool
    {
        if (self::$angelegt || Cache::get('fundus-dateitext-schema') === 1) {
            return true;
        }
        if (!Schema::hasTable(self::TABELLE)) {
            return false;
        }
        Cache::forever('fundus-dateitext-schema', 1);
        self::$angelegt = true;

        return true;
    }

    /**
     * Ergebnis der Erkennung speichern und den Artikel der Datei aktualisieren.
     * Gibt es die Datei nicht mehr, wird nur ein alter Eintrag entfernt.
     */
    public static function speichern(string $art, int $quelleId, string $status, ?string $text = null, ?float $konfidenz = null, ?string $sha256 = null, ?string $fehler = null): void
    {
        if (!in_array($status, self::STATUS, true) || !in_array($art, self::ARTEN, true)) {
            throw new \InvalidArgumentException("Unbekannt: {$art}/{$status}");
        }
        self::tabelleAnlegen();
        $quelle = self::quelle($art, $quelleId);
        if (!$quelle) {
            DB::table(self::TABELLE)->where('art', $art)->where('quelle_id', $quelleId)->delete();
            return;
        }

        $text = self::aufbereiten($text);
        if ($status === 'fertig' && $text === '') {
            $status = 'ohne_text';
        }
        DB::table(self::TABELLE)->updateOrInsert(['art' => $art, 'quelle_id' => $quelleId], [
            'sha256' => $sha256,
            'status' => $status,
            'text' => $text === '' ? null : $text,
            'konfidenz' => $konfidenz,
            'fehler' => $fehler === null ? null : mb_substr($fehler, 0, 500),
            'updated_at' => now(),
        ]);
        self::seiteAktualisieren((int) $quelle->uploaded_to);
    }

    /** Leerzeichen und Leerzeilen zusammenfassen, auf MAX_ZEICHEN kürzen. */
    public static function aufbereiten(?string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\f"], "\n", (string) $text);
        $text = preg_replace("/[ \t\u{00A0}]+/u", ' ', $text) ?? '';
        $text = preg_replace("/ *\n */", "\n", $text) ?? '';
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? '');

        return mb_substr($text, 0, self::MAX_ZEICHEN);
    }

    // -----------------------------------------------------------------------
    // Block im Artikel
    // -----------------------------------------------------------------------

    /** Block mit dem Text aller Dateien eines Artikels: erst Anhänge in ihrer Reihenfolge, dann Bilder. */
    public static function block(int $pageId): string
    {
        if (!self::tabelleDa()) {
            return '';
        }
        $fertig = fn ($anfrage) => $anfrage->where('t.status', 'fertig')->whereNotNull('t.text');
        $anhaenge = $fertig(DB::table(self::TABELLE . ' AS t')
            ->join('attachments AS a', 'a.id', '=', 't.quelle_id')
            ->where('t.art', 'anhang')->where('a.uploaded_to', $pageId)->where('a.external', false))
            ->orderBy('a.order')->orderBy('a.id')->get(['a.name', 't.text']);
        $bilder = $fertig(DB::table(self::TABELLE . ' AS t')
            ->join('images AS i', 'i.id', '=', 't.quelle_id')
            ->where('t.art', 'bild')->where('i.uploaded_to', $pageId)->where('i.type', 'gallery'))
            ->orderBy('i.id')->get(['i.name', 't.text']);

        $zeilen = $anhaenge->concat($bilder);
        if ($zeilen->isEmpty()) {
            return '';
        }

        // Zeilenumbrüche zwischen den Elementen sind nötig: BookStacks Suchindex liest den textContent des ganzen
        // Blocks, ohne sie verschmelzen Wörter benachbarter Absätze („pdfWartungsvertrag“).
        $inhalt = $zeilen->map(function ($z) {
            $absaetze = array_map(
                fn ($absatz) => '<p>' . nl2br(e($absatz), false) . '</p>',
                preg_split("/\n\n/", $z->text)
            );
            return '<p><strong>' . e($z->name) . "</strong></p>\n" . implode("\n", $absaetze);
        })->join("\n");

        return '<details id="' . self::BLOCK_ID . '">' . "\n<summary>" . e(self::UEBERSCHRIFT) . "</summary>\n" . $inhalt . "\n</details>";
    }

    /** HTML ohne den Block. Ohne Block bleibt das HTML unangetastet (kein Umweg über DOM). */
    public static function ohneBlock(string $html): string
    {
        if (!str_contains($html, self::BLOCK_ID) && !str_contains($html, self::UEBERSCHRIFT)) {
            return $html;
        }
        $doc = new HtmlDocument($html);
        // Rückfall über die Zusammenfassung: Ein kopierter Block bekommt von BookStack eine neue id.
        $knoten = iterator_to_array($doc->queryXPath('//*[@id="' . self::BLOCK_ID . '"] | //details[summary[normalize-space()="' . self::UEBERSCHRIFT . '"]]'));
        if (!$knoten) {
            return $html;
        }
        foreach ($knoten as $k) {
            $k->parentNode?->removeChild($k);
        }

        // Wie PageContent::formatHtml: geschützte Leerzeichen als Entität speichern.
        return str_replace("\u{00A0}", '&nbsp;', $doc->getBodyInnerHtml());
    }

    /** HTML mit frischem Block am Ende. Für PAGE_CONTENT_PRE_STORE und seiteAktualisieren. */
    public static function mitBlock(string $html, Page $page): string
    {
        $ohne = self::ohneBlock($html);
        $block = $page->id ? self::block((int) $page->id) : '';

        return $block === '' ? $ohne : $ohne . $block;
    }

    /**
     * Block im gespeicherten Artikel erneuern, ohne Revision, Aktivität und Änderungsdatum.
     * Danach Suchindex und KI-Index neu, wie nach einem Speichern. true, wenn sich etwas geändert hat.
     */
    public static function seiteAktualisieren(int $pageId): bool
    {
        $page = Page::query()->find($pageId);
        if (!$page) {
            return false;
        }
        $neu = self::mitBlock((string) $page->html, $page);
        if ($neu === (string) $page->html) {
            return false;
        }

        $page->html = $neu;
        $page->text = (new PageContent($page))->toPlainText();
        // Entity::save ruft touch() auf; ohne Zeitstempel bleibt „Zuletzt geändert“ unverändert.
        $page->timestamps = false;
        $page->saveQuietly();

        if (!$page->draft) {
            app(SearchIndex::class)->indexEntity($page);
            if (Ki::aktiv() && !$page->template) {
                IndexJob::dispatch($page->id);
            }
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Erkennung
    // -----------------------------------------------------------------------

    /**
     * Datei erkennen lassen und das Ergebnis speichern. Wirft bei Netz- und Serverfehlern, damit die Warteschlange
     * es später erneut versucht; unlesbare Dateien enden sofort als „fehler“.
     */
    public static function erkennen(string $art, int $quelleId): void
    {
        self::tabelleAnlegen();
        $quelle = self::quelle($art, $quelleId);
        if (!$quelle || !self::unterstuetzt($quelle)) {
            $geloescht = DB::table(self::TABELLE)->where('art', $art)->where('quelle_id', $quelleId)->delete();
            if ($geloescht && $quelle) {
                self::seiteAktualisieren((int) $quelle->uploaded_to);
            }
            return;
        }

        $strom = self::datei($quelle);
        if (!$strom) {
            self::speichern($art, $quelleId, 'fehler', fehler: 'Datei nicht im Speicher gefunden');
            return;
        }
        $datei = Utils::streamFor($strom);
        $sha256 = Utils::hash($datei, 'sha256');
        $datei->rewind();

        // Dieselbe Datei hängt schon woanders (oder hier, unverändert): Ergebnis übernehmen statt neu erkennen.
        $bekannt = DB::table(self::TABELLE)->where('sha256', $sha256)->whereIn('status', ['fertig', 'ohne_text'])->first();
        if ($bekannt) {
            $datei->close();
            self::speichern($art, $quelleId, $bekannt->status, $bekannt->text, $bekannt->konfidenz === null ? null : (float) $bekannt->konfidenz, $sha256);
            return;
        }

        $antwort = Http::connectTimeout(10)->timeout(self::ZEITLIMIT)
            ->withBody($datei, 'application/octet-stream')
            ->post(self::url() . '/erkennen');
        $datei->close();

        // 415: kein PDF/Bild trotz Endung. 413/422: zu groß, kaputt, verschlüsselt – erneut versuchen hilft nicht.
        if (in_array($antwort->status(), [413, 415, 422], true)) {
            self::speichern($art, $quelleId, $antwort->status() === 415 ? 'ohne_text' : 'fehler', sha256: $sha256,
                fehler: (string) ($antwort->json('fehler') ?? 'HTTP ' . $antwort->status()));
            return;
        }
        $antwort->throw();

        $konfidenz = $antwort->json('konfidenz');
        self::speichern($art, $quelleId, 'fertig', (string) $antwort->json('text'), $konfidenz === null ? null : (float) $konfidenz, $sha256);
    }
}

/**
 * Erkennt eine Datei im Hintergrund. Drei Versuche mit Pause, falls der Dienst gerade nicht erreichbar ist
 * (die Angabe am Job gilt vor --tries=1 des Workers). Der Worker kann ohne pcntl nicht abbrechen, die Grenze
 * setzt deshalb der HTTP-Timeout.
 */
class ErkennenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300];

    public function __construct(public string $art, public int $quelleId)
    {
    }

    public function handle(): void
    {
        Dateitext::erkennen($this->art, $this->quelleId);
    }

    public function failed(\Throwable $e): void
    {
        Dateitext::speichern($this->art, $this->quelleId, 'fehler', fehler: $e->getMessage());
    }
}

/** Baut den Block eines Artikels neu, z. B. nach Löschen oder Verschieben einer Datei. */
class SeiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $pageId)
    {
    }

    public function handle(): void
    {
        Dateitext::tabelleAnlegen();
        Dateitext::seiteAktualisieren($this->pageId);
    }
}

/** php artisan fundus:datei-text [--neu] – stellt vorhandene Anhänge und Bilder zur Erkennung in die Warteschlange. */
class DateitextCommand extends Command
{
    protected $signature = 'fundus:datei-text {--neu : Auch Dateien, die schon erkannt sind (z. B. nach geänderten Schwellen)}';
    protected $description = 'Lässt den Text vorhandener Anhänge und Bilder erkennen (fehlende und fehlgeschlagene)';

    public function handle(): int
    {
        if (!Dateitext::aktiv()) {
            $this->error('OCR_URL ist nicht gesetzt.');
            return 1;
        }
        Dateitext::tabelleAnlegen();
        if ($this->option('neu')) {
            // Sonst übernähme die Erkennung das alte Ergebnis über die Prüfsumme.
            DB::table(Dateitext::TABELLE)->update(['sha256' => null]);
        }

        $endungen = Dateitext::ENDUNGEN;
        $offen = fn (string $art, $anfrage) => $this->option('neu') ? $anfrage : $anfrage->whereNotIn('id',
            DB::table(Dateitext::TABELLE)->where('art', $art)->whereIn('status', ['fertig', 'ohne_text'])->select('quelle_id'));

        $anhaenge = $offen('anhang', Attachment::query()->where('external', false)
            ->whereIn(DB::raw('LOWER(extension)'), $endungen))->pluck('id');
        $bilder = $offen('bild', Image::query()->where('type', 'gallery')->where('uploaded_to', '>', 0)
            ->whereIn(DB::raw("LOWER(SUBSTRING_INDEX(path, '.', -1))"), $endungen))->pluck('id');

        foreach ($anhaenge as $id) {
            ErkennenJob::dispatch('anhang', (int) $id);
        }
        foreach ($bilder as $id) {
            ErkennenJob::dispatch('bild', (int) $id);
        }
        $this->info("{$anhaenge->count()} Anhänge und {$bilder->count()} Bilder in der Warteschlange. Fortschritt: Tabelle " . Dateitext::TABELLE . '.');

        return 0;
    }
}
