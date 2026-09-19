<?php

namespace FundusAktivitaet;

use BookStack\Activity\Models\Activity;
use BookStack\Entities\Models\Page;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Zahlen der Startseite für eine Person: was sie an welchem Tag der letzten 52 Wochen getan hat, und die Serie.
 * Schreiben: eigene Aktionen an Inhalten aus BookStacks Aktivitätsprotokoll (Anmeldungen, Rechte, Rollen nicht).
 * Lesen: eigene Tabelle, ein Eintrag je Person, Artikel und Tag. BookStacks Tabelle `views` taugt dafür nicht,
 * sie merkt sich je Artikel nur den letzten Aufruf. Der Browser meldet einen Artikel erst, wenn er
 * LESEZEIT Sekunden sichtbar offen war (wiki.js, „gelesenMelden“), damit Durchklicken nicht zählt.
 * Als Klasse, weil Begrüßung und Raster dieselben Zahlen brauchen.
 */
class Aktivitaet
{
    public const TABELLE = 'fundus_gelesen';

    public const SCHREIBEN = [
        'page_create', 'page_update', 'page_restore', 'page_move',
        'chapter_create', 'chapter_update', 'book_create', 'book_update', 'bookshelf_create', 'bookshelf_update',
    ];

    /** Ab so vielen Werktagen leuchtet die Serie voll. */
    public const VOLL = 14;

    /** Sekunden sichtbarer Lesezeit, bevor ein Artikel als gelesen gilt (wiki.js liest den Wert aus dem Baustein). */
    public const LESEZEIT = 20;

    protected static array $zwischenspeicher = [];
    protected static bool $angelegt = false;

    public static function tabelleAnlegen(): void
    {
        if (self::$angelegt || Cache::get('fundus-gelesen-schema') === 1) {
            self::$angelegt = true;
            return;
        }
        DB::statement('CREATE TABLE IF NOT EXISTS ' . self::TABELLE . ' (
            user_id INT UNSIGNED NOT NULL,
            page_id INT UNSIGNED NOT NULL,
            datum DATE NOT NULL,
            PRIMARY KEY (user_id, datum, page_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        Cache::forever('fundus-gelesen-schema', 1);
        self::$angelegt = true;
    }

    /** POST /fundus/gelesen: nur Artikel, die die Person lesen darf (sonst 404 wie bei BookStack); je Tag einmal. */
    public static function gelesen(Request $request): JsonResponse
    {
        $daten = $request->validate(['page_id' => ['required', 'integer']]);
        $seite = Page::query()->scopes('visible')->where('draft', false)->findOrFail((int) $daten['page_id']);
        self::tabelleAnlegen();
        DB::table(self::TABELLE)->insertOrIgnore([
            'user_id' => user()->id,
            'page_id' => $seite->id,
            'datum' => CarbonImmutable::now(config('app.display_timezone'))->toDateString(),
        ]);
        unset(self::$zwischenspeicher[user()->id]);
        return response()->json(['ok' => true]);
    }

    /**
     * @return array{beginn: CarbonImmutable, heute: CarbonImmutable, proTag: array<string, array{geschrieben: int, gelesen: int}>,
     *               angelegt: int, bearbeitet: int, gelesen: int, serie: int, glut: float}
     */
    public static function fuer(int $userId): array
    {
        if (isset(static::$zwischenspeicher[$userId])) {
            return static::$zwischenspeicher[$userId];
        }

        $zone = config('app.display_timezone');
        $heute = CarbonImmutable::now($zone)->startOfDay();
        // Das Raster beginnt an einem Montag, damit die Wochenspalten sauber sind.
        $beginn = $heute->subWeeks(51)->startOfWeek(CarbonInterface::MONDAY);

        $eintraege = Activity::query()
            ->where('user_id', $userId)
            ->whereIn('type', static::SCHREIBEN)
            ->where('created_at', '>=', $beginn->setTimezone('UTC'))
            ->get(['type', 'created_at']);

        $proTag = [];
        foreach ($eintraege as $e) {
            $tag = $e->created_at->copy()->setTimezone($zone)->toDateString();
            $proTag[$tag]['geschrieben'] = ($proTag[$tag]['geschrieben'] ?? 0) + 1;
        }

        self::tabelleAnlegen();
        $gelesen = DB::table(self::TABELLE)->where('user_id', $userId)->where('datum', '>=', $beginn->toDateString());
        foreach ((clone $gelesen)->selectRaw('datum, COUNT(*) AS anzahl')->groupBy('datum')->get() as $zeile) {
            $proTag[$zeile->datum]['gelesen'] = (int) $zeile->anzahl;
        }
        foreach ($proTag as $tag => $werte) {
            $proTag[$tag] = ['geschrieben' => $werte['geschrieben'] ?? 0, 'gelesen' => $werte['gelesen'] ?? 0];
        }

        $serie = static::serie($proTag, $heute);

        return static::$zwischenspeicher[$userId] = [
            'beginn' => $beginn,
            'heute' => $heute,
            'proTag' => $proTag,
            'angelegt' => $eintraege->where('type', 'page_create')->count(),
            'bearbeitet' => $eintraege->where('type', 'page_update')->count(),
            'gelesen' => (int) (clone $gelesen)->distinct()->count('page_id'),
            'serie' => $serie,
            // 0 bis 1: ab dem ersten Tag deutlich violett, jeder weitere Tag mehr, ab VOLL Tagen ganz.
            'glut' => $serie === 0 ? 0.0 : round(0.35 + 0.65 * (min($serie, static::VOLL) - 1) / (static::VOLL - 1), 2),
        ];
    }

    /**
     * Aufeinanderfolgende Werktage mit Aktivität (Schlüssel = Datum). Wochenenden zählen nicht und unterbrechen
     * nicht (Feiertage und Urlaub schon, dafür gibt es keine Quelle). Ein heute noch leerer Tag unterbricht nicht.
     */
    public static function serie(array $proTag, CarbonImmutable $heute): int
    {
        $t = $heute->isWeekend() || isset($proTag[$heute->toDateString()]) ? $heute : $heute->subDay();
        $serie = 0;
        for (; ; $t = $t->subDay()) {
            if ($t->isWeekend()) {
                continue;
            }
            if (!isset($proTag[$t->toDateString()])) {
                return $serie;
            }
            $serie++;
        }
    }
}
