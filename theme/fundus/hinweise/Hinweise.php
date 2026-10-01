<?php

namespace FundusHinweise;

use FundusPruefung\Pruefung;
use FundusRueckmeldung\Rueckmeldung;
use BookStack\Entities\Models\Page;
use BookStack\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Benachrichtigungen, die Fundus überbringt: Was steht für die angemeldete Person an?
 *
 * Quellen (je eine Methode, leicht zu erweitern):
 *   rueckmeldung  offene Hinweise „veraltet/falsch“ zu Artikeln, die die Person verantwortet
 *   pruefung      eigene Artikel, deren Prüfung fällig ist oder bald ansteht
 *   sicherung     nur Admins: gescheiterte oder ausbleibende Sicherung, fehlende Kopie außer Haus
 *
 * Jeder Eintrag hat einen Schlüssel. „Gesehen“ merkt sich Schlüssel je Person; ein Eintrag bleibt
 * trotzdem in der Liste, bis er erledigt ist – er wird nur nicht mehr als neu gemeldet. Der Schlüssel
 * einer Prüfung enthält das Fälligkeitsdatum, damit die nächste Runde wieder als neu gilt.
 * Die Texte sind fest formuliert, nicht vom Sprachmodell: Sie funktionieren ohne Ollama und erfinden nichts.
 */
class Hinweise
{
    public const TABELLE = 'fundus_hinweise_gesehen';
    /** status.json des Dienstes sicherung, nur lesend ins Wiki gebunden (docker-compose.yml). */
    public const SICHERUNG_STATUS = '/fundus-sicherungen/status.json';

    protected static bool $angelegt = false;

    public static function tabelleAnlegen(): void
    {
        if (self::$angelegt || Cache::get('fundus-hinweise-schema') === 1) {
            self::$angelegt = true;
            return;
        }
        DB::statement('CREATE TABLE IF NOT EXISTS ' . self::TABELLE . ' (
            user_id INT UNSIGNED NOT NULL,
            schluessel VARCHAR(80) NOT NULL,
            gesehen_am DATETIME NOT NULL,
            PRIMARY KEY (user_id, schluessel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        Cache::forever('fundus-hinweise-schema', 1);
        self::$angelegt = true;
    }

    /**
     * Alle Einträge für eine Person, dringendste zuerst.
     * @return array<int, array{schluessel: string, art: string, dringend: bool, titel: string, text: string, adresse: string, zeit: string, neu: bool}>
     */
    public static function fuer(int $userId): array
    {
        self::tabelleAnlegen();
        $eintraege = array_merge(self::sicherung($userId), self::rueckmeldungen($userId), self::pruefungen($userId));
        $gesehen = DB::table(self::TABELLE)->where('user_id', $userId)
            ->whereIn('schluessel', array_column($eintraege, 'schluessel'))->pluck('schluessel')->flip();
        foreach ($eintraege as &$e) {
            $e['neu'] = !isset($gesehen[$e['schluessel']]);
        }
        unset($e);
        // Dringendes nach vorn; sonst bleibt die Reihenfolge der Quellen (usort ist stabil):
        // Rückmeldungen neueste zuerst, Prüfungen nach Frist.
        usort($eintraege, fn ($a, $b) => $b['dringend'] <=> $a['dringend']);
        return $eintraege;
    }

    /** Offene Hinweise zu eigenen Artikeln, ohne selbst gemeldete. Auch für den Kasten auf der Startseite. */
    public static function rueckmeldungen(int $userId): array
    {
        Rueckmeldung::tabelleAnlegen();
        $zeilen = DB::table(Rueckmeldung::TABELLE . ' AS r')
            ->join('entities AS e', fn ($j) => $j->on('e.id', '=', 'r.page_id')->where('e.type', '=', 'page'))
            ->where('e.owned_by', $userId)->where('r.art', 'veraltet')->whereNull('r.erledigt_am')
            ->where('r.user_id', '!=', $userId)->whereNull('e.deleted_at')
            ->orderByDesc('r.created_at')->limit(30)
            ->get(['r.id', 'r.page_id', 'r.text', 'r.grund', 'r.abschnitt', 'r.created_at']);
        if ($zeilen->isEmpty()) {
            return [];
        }
        // Nur Artikel, die die Person (noch) lesen darf.
        $seiten = Page::query()->scopes('visible')->whereIn('id', $zeilen->pluck('page_id')->unique())->get()->keyBy('id');

        return $zeilen->filter(fn ($z) => $seiten->has($z->page_id))->map(function ($z) use ($seiten) {
            $seite = $seiten[$z->page_id];
            $grund = Rueckmeldung::GRUENDE[$z->grund ?? 'veraltet'][0] ?? 'Hinweis';
            return [
                'schluessel' => 'rueckmeldung:' . $z->id,
                'art' => 'rueckmeldung',
                'dringend' => true,
                'titel' => $seite->name,
                'text' => '„' . $grund . '“' . ($z->abschnitt ? ' bei „' . $z->abschnitt . '“' : '') . ': ' . mb_strimwidth($z->text, 0, 140, '…'),
                'adresse' => $seite->getUrl(),
                'zeit' => Carbon::parse($z->created_at)->toIso8601String(),
            ];
        })->values()->all();
    }

    /** Eigene Artikel mit fälliger oder bald fälliger Prüfung. */
    protected static function pruefungen(int $userId): array
    {
        $zone = config('app.display_timezone');
        return Pruefung::anstehendFuer($userId)->map(function ($e) use ($zone) {
            $faellig = $e['lage'] === 'faellig';
            $datum = $e['faellig_am']->setTimezone($zone);
            return [
                'schluessel' => 'pruefung:' . $e['seite']->id . ':' . $datum->format('Ymd'),
                'art' => 'pruefung',
                'dringend' => $faellig,
                'titel' => $e['seite']->name,
                'text' => $faellig ? 'Prüfung fällig seit ' . $datum->format('d.m.Y') : 'Prüfung steht bis ' . $datum->format('d.m.Y') . ' an',
                'adresse' => $e['seite']->getUrl(),
                'zeit' => $datum->toIso8601String(),
            ];
        })->all();
    }

    /**
     * Nur für Admins: Wie ging die letzte Sicherung aus? skripte/sicherung.sh schreibt nach jedem Lauf
     * status.json. Ohne Datei (vor der ersten Nacht, lokal ohne Volume) gibt es nichts zu melden.
     * Datei und Zeitpunkt lassen sich für Tests übergeben.
     */
    public static function sicherung(int $userId, ?string $datei = null, ?Carbon $jetzt = null): array
    {
        $nutzer = User::query()->find($userId);
        $datei ??= self::SICHERUNG_STATUS;
        if (!$nutzer || !$nutzer->hasSystemRole('admin') || !is_file($datei)) {
            return [];
        }
        $s = json_decode((string) file_get_contents($datei), true);
        if (!is_array($s) || empty($s['zeit'])) {
            return [];
        }
        $zone = config('app.display_timezone');
        $zeit = Carbon::parse($s['zeit']);
        $wann = $zeit->copy()->setTimezone($zone)->format('d.m.Y') . ' um ' . $zeit->copy()->setTimezone($zone)->format('H:i');
        $eintrag = fn (string $schluessel, bool $dringend, string $titel, string $text) => [
            'schluessel' => $schluessel, 'art' => 'sicherung', 'dringend' => $dringend,
            'titel' => $titel, 'text' => $text, 'adresse' => url('/settings'), 'zeit' => $zeit->toIso8601String(),
        ];

        if (($s['ergebnis'] ?? '') !== 'ok') {
            $was = ($s['ergebnis'] ?? '') === 'kopie-fehler' ? 'Kopie außer Haus fehlgeschlagen' : 'Sicherung fehlgeschlagen';
            $meldung = (string) ($s['meldung'] ?? '');
            return [$eintrag('sicherung:1:' . $zeit->timestamp, true, $was,
                $meldung !== '' ? $meldung . ' (' . $wann . ')' : 'Lauf vom ' . $wann . '.')];
        }
        if ($zeit->lt(($jetzt ?? now())->copy()->subHours(26))) {
            return [$eintrag('sicherung:2:' . $zeit->timestamp, true, 'Keine Sicherung seit ' . $zeit->copy()->setTimezone($zone)->format('d.m.Y'),
                'Die letzte lief am ' . $wann . '. Vermutlich läuft der Dienst sicherung nicht.')];
        }
        if (($s['kopie'] ?? 'aus') !== 'an') {
            return [$eintrag('sicherung:0', false, 'Sicherung nur auf dem Server',
                'Fällt der Server aus, sind auch die Sicherungen weg. Kopie außer Haus mit SICHERUNG_KOPIE einrichten.')];
        }
        if (($s['verschluesselt'] ?? 'nein') !== 'ja') {
            return [$eintrag('sicherung:3', false, 'Kopie außer Haus unverschlüsselt',
                'Wer Zugriff auf das Ziel hat, kann das ganze Wiki lesen. SICHERUNG_KOPIE_SCHLUESSEL setzen.')];
        }
        return [];
    }

    /** GET /fundus/hinweise */
    public static function abrufen(): JsonResponse
    {
        $eintraege = self::fuer(user()->id);
        return response()->json([
            'vorname' => explode(' ', trim(user()->name))[0],
            'neu' => count(array_filter($eintraege, fn ($e) => $e['neu'])),
            'eintraege' => $eintraege,
        ]);
    }

    /** POST /fundus/hinweise/gesehen mit { schluessel: [...] } */
    public static function gesehen(Request $request): JsonResponse
    {
        $daten = $request->validate([
            'schluessel' => ['required', 'array', 'max:100'],
            'schluessel.*' => ['string', 'max:80', 'regex:/^[a-z]+:[0-9:]+$/'],
        ]);
        self::tabelleAnlegen();
        $jetzt = now();
        DB::table(self::TABELLE)->insertOrIgnore(array_map(
            fn ($s) => ['user_id' => user()->id, 'schluessel' => $s, 'gesehen_am' => $jetzt],
            array_values(array_unique($daten['schluessel']))
        ));
        return response()->json(['ok' => true]);
    }
}
