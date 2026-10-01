<?php

namespace FundusRueckmeldung;

use BookStack\Entities\Models\Page;
use BookStack\Permissions\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rückmeldungen zu Artikeln: „War das hilfreich?“ (ja/nein, eine Stimme je Person und Artikel)
 * und „Veraltet oder falsch melden“ mit kurzem Hinweis. Wer den Artikel bearbeiten darf,
 * sieht die Zahlen und offenen Hinweise in den Details und kann Hinweise erledigen.
 * Eigene Tabelle, damit BookStack-Updates nichts daran ändern.
 */
class Rueckmeldung
{
    public const TABELLE = 'fundus_rueckmeldungen';

    /** Gründe beim Melden: Wert → [Beschriftung, Vorschlag im Beschreibungsfeld]. */
    public const GRUENDE = [
        'veraltet' => ['Veraltet', 'z. B. Der Menüpunkt heißt inzwischen „Freigaben“, nicht mehr „Berechtigungen“.'],
        'fehler' => ['Fehler im Text', 'z. B. In Schritt 3 fehlt, dass man vorher speichern muss.'],
        'bild' => ['Bild passt nicht', 'z. B. Der Screenshot zeigt noch die alte Oberfläche.'],
        'link' => ['Link geht nicht', 'z. B. Der Link zum Admin Center führt auf eine Fehlerseite.'],
        'fehlt' => ['Etwas fehlt', 'z. B. Wie es bei Kunden ohne Lizenz funktioniert, steht nirgends.'],
    ];

    protected static bool $angelegt = false;

    public static function tabelleAnlegen(): void
    {
        // Einmal je Schema-Stand statt bei jeder Anfrage (ALTER TABLE sperrt die Tabelle kurz).
        if (self::$angelegt || \Illuminate\Support\Facades\Cache::get('fundus-rueckmeldung-schema') === 2) {
            self::$angelegt = true;
            return;
        }
        DB::statement('CREATE TABLE IF NOT EXISTS ' . self::TABELLE . ' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            page_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            art VARCHAR(16) NOT NULL,
            text VARCHAR(1000) NULL,
            erledigt_von INT UNSIGNED NULL,
            erledigt_am DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY page_art (page_id, art),
            KEY page_user (page_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        // Später dazugekommen: Grund und Stelle im Artikel (MariaDB kennt ADD COLUMN IF NOT EXISTS).
        DB::statement('ALTER TABLE ' . self::TABELLE . ' ADD COLUMN IF NOT EXISTS grund VARCHAR(16) NULL AFTER art, ADD COLUMN IF NOT EXISTS abschnitt VARCHAR(255) NULL AFTER grund');
        \Illuminate\Support\Facades\Cache::forever('fundus-rueckmeldung-schema', 2);
        self::$angelegt = true;
    }

    /** Nur Artikel, die die angemeldete Person lesen darf; sonst 404 wie bei BookStack. */
    protected static function sichtbareSeite(int $id): Page
    {
        return Page::query()->scopes('visible')->where('draft', false)->findOrFail($id);
    }

    public static function speichern(Request $request): JsonResponse
    {
        $daten = $request->validate([
            'page_id' => ['required', 'integer'],
            'art' => ['required', 'in:ja,nein,veraltet'],
            'text' => ['nullable', 'string', 'max:1000'],
            'grund' => ['nullable', 'in:' . implode(',', array_keys(self::GRUENDE))],
            'abschnitt' => ['nullable', 'string', 'max:255'],
        ]);
        $seite = self::sichtbareSeite((int) $daten['page_id']);
        self::tabelleAnlegen();
        $jetzt = now();
        $nutzer = user()->id;

        if ($daten['art'] === 'veraltet') {
            $text = trim((string) ($daten['text'] ?? ''));
            if ($text === '') {
                return response()->json(['fehler' => 'Bitte kurz beschreiben, was nicht mehr stimmt.'], 422);
            }
            DB::table(self::TABELLE)->insert([
                'page_id' => $seite->id, 'user_id' => $nutzer, 'art' => 'veraltet', 'text' => $text,
                'grund' => $daten['grund'] ?? 'veraltet',
                'abschnitt' => trim((string) ($daten['abschnitt'] ?? '')) ?: null,
                'created_at' => $jetzt, 'updated_at' => $jetzt,
            ]);
        } else {
            // Eine Stimme je Person: eine neue ersetzt die alte.
            DB::table(self::TABELLE)->where('page_id', $seite->id)->where('user_id', $nutzer)->whereIn('art', ['ja', 'nein'])->delete();
            DB::table(self::TABELLE)->insert([
                'page_id' => $seite->id, 'user_id' => $nutzer, 'art' => $daten['art'],
                'created_at' => $jetzt, 'updated_at' => $jetzt,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public static function erledigen(Request $request, int $id): JsonResponse
    {
        self::tabelleAnlegen();
        $eintrag = DB::table(self::TABELLE)->where('id', $id)->where('art', 'veraltet')->first();
        abort_if(!$eintrag, 404);
        $seite = self::sichtbareSeite((int) $eintrag->page_id);
        abort_unless(userCan(Permission::PageUpdate, $seite), 403);
        DB::table(self::TABELLE)->where('id', $id)->update([
            'erledigt_von' => user()->id, 'erledigt_am' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /** @return array{ja: int, nein: int, meine: ?string, hinweise: \Illuminate\Support\Collection} */
    public static function fuerSeite(Page $seite): array
    {
        self::tabelleAnlegen();
        $zahlen = DB::table(self::TABELLE)->where('page_id', $seite->id)->whereIn('art', ['ja', 'nein'])
            ->selectRaw('art, COUNT(*) AS anzahl')->groupBy('art')->pluck('anzahl', 'art');
        $meine = DB::table(self::TABELLE)->where('page_id', $seite->id)->where('user_id', user()->id)
            ->whereIn('art', ['ja', 'nein'])->value('art');
        $hinweise = DB::table(self::TABELLE . ' AS r')
            ->leftJoin('users AS u', 'u.id', '=', 'r.user_id')
            ->where('r.page_id', $seite->id)->where('r.art', 'veraltet')->whereNull('r.erledigt_am')
            ->orderByDesc('r.created_at')->limit(10)
            ->get(['r.id', 'r.text', 'r.grund', 'r.abschnitt', 'r.created_at', 'u.name', 'u.slug']);

        return ['ja' => (int) ($zahlen['ja'] ?? 0), 'nein' => (int) ($zahlen['nein'] ?? 0), 'meine' => $meine, 'hinweise' => $hinweise];
    }
}
