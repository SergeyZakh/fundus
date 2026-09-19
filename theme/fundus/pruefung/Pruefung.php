<?php

namespace FundusPruefung;

use BookStack\Entities\Models\Page;
use BookStack\Permissions\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Regelmäßige Prüfung von Artikeln: Wer ist verantwortlich, wann war die letzte Prüfung, wann ist die nächste fällig?
 *
 * Verantwortlich ist der Besitzer des Artikels (BookStack, änderbar unter „Rechte“). Das Intervall kommt
 * aus dem Schlagwort „Prüfintervall“ (Monate oder „nie“): erst am Artikel, dann an seinem Thema, sonst
 * FUNDUS_PRUEFINTERVALL. Gezählt wird ab der letzten Prüfung, bei nie geprüften Artikeln ab dem Anlegen –
 * eine Bearbeitung ist keine Prüfung, sonst hielte jeder Tippfehler-Fix einen veralteten Artikel „frisch“.
 * Eigene Tabelle mit Verlauf, damit BookStack-Updates nichts daran ändern.
 */
class Pruefung
{
    public const TABELLE = 'fundus_pruefungen';
    public const SCHLAGWORT = 'Prüfintervall';

    /** So viele Tage vor der Frist gilt ein Artikel als „bald fällig“. */
    public const VORLAUF_TAGE = 30;

    protected static bool $angelegt = false;

    public static function tabelleAnlegen(): void
    {
        if (self::$angelegt || Cache::get('fundus-pruefung-schema') === 1) {
            self::$angelegt = true;
            return;
        }
        DB::statement('CREATE TABLE IF NOT EXISTS ' . self::TABELLE . ' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            page_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            notiz VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            KEY page_zeit (page_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        Cache::forever('fundus-pruefung-schema', 1);
        self::$angelegt = true;
    }

    /** Vorgabe in Monaten aus der Umgebung; 0 heißt: ohne Schlagwort wird nichts geprüft. */
    public static function vorgabe(): int
    {
        return max(0, (int) env('FUNDUS_PRUEFINTERVALL', 12));
    }

    /**
     * Wert eines Schlagworts „Prüfintervall“ in Monate: "6", "6 Monate" → 6; "nie", "0", "aus" → 0; Unlesbares → null.
     */
    public static function monateAusText(?string $wert): ?int
    {
        $wert = mb_strtolower(trim((string) $wert));
        if ($wert === '') {
            return null;
        }
        if (in_array($wert, ['nie', 'aus', 'keine', 'kein', '0'], true)) {
            return 0;
        }
        return preg_match('/^(\d{1,3})\b/', $wert, $m) ? (int) $m[1] : null;
    }

    /**
     * Intervall eines Artikels mit Herkunft.
     * @return array{monate: int, quelle: 'artikel'|'thema'|'vorgabe'}
     */
    public static function intervall(Page $seite): array
    {
        $ausArtikel = self::monateAusText(self::schlagwort($seite->tags));
        if ($ausArtikel !== null) {
            return ['monate' => $ausArtikel, 'quelle' => 'artikel'];
        }
        $ausThema = $seite->book ? self::monateAusText(self::schlagwort($seite->book->tags)) : null;
        if ($ausThema !== null) {
            return ['monate' => $ausThema, 'quelle' => 'thema'];
        }
        return ['monate' => self::vorgabe(), 'quelle' => 'vorgabe'];
    }

    protected static function schlagwort(Collection $tags): ?string
    {
        $tag = $tags->first(fn ($t) => mb_strtolower(trim($t->name)) === mb_strtolower(self::SCHLAGWORT));
        return $tag?->value;
    }

    /**
     * Stand eines Artikels.
     * @return array{monate: int, quelle: string, zuletzt: ?object, faellig_am: ?CarbonImmutable, lage: 'aus'|'ok'|'bald'|'faellig'}
     */
    public static function stand(Page $seite): array
    {
        self::tabelleAnlegen();
        $intervall = self::intervall($seite);
        $zuletzt = DB::table(self::TABELLE . ' AS p')->leftJoin('users AS u', 'u.id', '=', 'p.user_id')
            ->where('p.page_id', $seite->id)->orderByDesc('p.created_at')->orderByDesc('p.id')
            ->first(['p.created_at', 'p.notiz', 'u.name', 'u.slug']);
        return $intervall + ['zuletzt' => $zuletzt] + self::frist($seite->created_at, $zuletzt?->created_at, $intervall['monate']);
    }

    /** @return array{faellig_am: ?CarbonImmutable, lage: 'aus'|'ok'|'bald'|'faellig'} */
    public static function frist($angelegt, $geprueft, int $monate): array
    {
        if ($monate === 0) {
            return ['faellig_am' => null, 'lage' => 'aus'];
        }
        $basis = CarbonImmutable::parse($geprueft ?? $angelegt);
        $faellig = $basis->addMonthsNoOverflow($monate);
        $jetzt = CarbonImmutable::now();
        $lage = $faellig->lte($jetzt) ? 'faellig' : ($faellig->lte($jetzt->addDays(self::VORLAUF_TAGE)) ? 'bald' : 'ok');
        return ['faellig_am' => $faellig, 'lage' => $lage];
    }

    /** POST /fundus/pruefung/{id}: als geprüft markieren. Nur mit Bearbeitungsrecht; nicht lesbare Artikel sind 404. */
    public static function pruefen(Request $request, int $id): JsonResponse
    {
        $daten = $request->validate(['notiz' => ['nullable', 'string', 'max:500']]);
        $seite = Page::query()->scopes('visible')->where('draft', false)->findOrFail($id);
        abort_unless(userCan(Permission::PageUpdate, $seite), 403);
        self::tabelleAnlegen();
        DB::table(self::TABELLE)->insert([
            'page_id' => $seite->id,
            'user_id' => user()->id,
            'notiz' => trim((string) ($daten['notiz'] ?? '')) ?: null,
            'created_at' => now(),
        ]);
        $stand = self::stand($seite);
        return response()->json([
            'ok' => true,
            'faellig_am' => $stand['faellig_am']?->setTimezone(config('app.display_timezone'))->format('d.m.Y'),
        ]);
    }

    /**
     * Artikel, die eine Person verantwortet und die fällig oder bald fällig sind, dringendste zuerst.
     * Nur Artikel, die sie lesen darf (BookStacks Rechtefilter), ohne Entwürfe und Vorlagen.
     * @return Collection<int, array{seite: Page, faellig_am: CarbonImmutable, lage: string}>
     */
    public static function anstehendFuer(int $userId, int $grenze = 50): Collection
    {
        self::tabelleAnlegen();
        $seiten = Page::query()->scopes('visible')->where('draft', false)->where('template', false)
            ->where('owned_by', $userId)->with(['tags', 'book.tags'])->get();
        if ($seiten->isEmpty()) {
            return collect();
        }
        $letzte = DB::table(self::TABELLE)->whereIn('page_id', $seiten->pluck('id'))
            ->groupBy('page_id')->selectRaw('page_id, MAX(created_at) AS zuletzt')->pluck('zuletzt', 'page_id');

        return $seiten->map(function (Page $seite) use ($letzte) {
            $frist = self::frist($seite->created_at, $letzte[$seite->id] ?? null, self::intervall($seite)['monate']);
            return ['seite' => $seite] + $frist;
        })->filter(fn ($e) => in_array($e['lage'], ['faellig', 'bald'], true))
            ->sortBy(fn ($e) => $e['faellig_am']->timestamp)->values()->take($grenze);
    }
}
