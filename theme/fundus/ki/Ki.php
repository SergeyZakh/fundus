<?php

namespace FundusKi;

use BookStack\Entities\Models\Page;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fundus, die KI im Wiki: Fragen an das Wiki über das lokale Ollama
 * (Entwicklerdoku, Kapitel „KI-Chat Fundus“).
 *
 * Inhalt dieser Datei
 *   Ki           Index (Tabelle, Zerlegen, Einbetten), Suche mit Rechteprüfung, Antwort als Datenstrom
 *   IndexJob     Warteschlangen-Auftrag: einen Artikel nach dem Speichern neu einbetten
 *   IndexCommand artisan fundus:ki-index [--neu]: alle Artikel einbetten
 *
 * Artikel werden an ihren Überschriften zerlegt, mit einem Einbettungsmodell in Vektoren
 * umgewandelt und in einer eigenen MariaDB-Tabelle abgelegt (MariaDB kann Vektoren ab 11.7).
 * Bei einer Frage werden die ähnlichsten Stücke gesucht, aber nur aus Artikeln, die die
 * fragende Person lesen darf. Erst diese Stücke gehen an das Sprachmodell.
 */
class Ki
{
    public const TABELLE = 'fundus_ki_stuecke';

    public static function aktiv(): bool
    {
        return (bool) env('OLLAMA_URL');
    }

    protected static function ollama(): string
    {
        return rtrim((string) env('OLLAMA_URL'), '/');
    }

    protected static function dimension(): int
    {
        return (int) env('KI_DIMENSION', 1024);
    }

    public static function tabelleAnlegen(bool $neu = false): void
    {
        if ($neu) {
            DB::statement('DROP TABLE IF EXISTS ' . self::TABELLE);
        }
        DB::statement('CREATE TABLE IF NOT EXISTS ' . self::TABELLE . ' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            page_id INT UNSIGNED NOT NULL,
            nummer SMALLINT UNSIGNED NOT NULL,
            ueberschrift VARCHAR(255) NOT NULL,
            text TEXT NOT NULL,
            vektor VECTOR(' . self::dimension() . ') NOT NULL,
            KEY page_id (page_id),
            VECTOR INDEX (vektor) DISTANCE=cosine
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /**
     * @param string[] $texte
     * @return array<int, float[]>
     */
    public static function einbetten(array $texte): array
    {
        $antwort = Http::timeout(300)->post(self::ollama() . '/api/embed', [
            'model' => env('KI_EINBETTUNG', 'bge-m3'),
            'input' => array_values($texte),
        ]);
        $antwort->throw();

        return $antwort->json('embeddings');
    }

    /**
     * Zerlegt einen Artikel an seinen Überschriften. Dank der Vorlagen (Ziel, Schritte, …)
     * sind die Stücke inhaltlich sauber abgegrenzt. Zu lange Abschnitte werden an Absätzen geteilt.
     * Text aus Anhängen und Bildern (theme/fundus/dateitext) wird je Datei zu eigenen Stücken.
     *
     * @return array<int, array{ueberschrift: string, text: string}>
     */
    public static function zerlegen(Page $page): array
    {
        // Der Block „Text aus Anhängen und Bildern“ hat keine Überschrift und würde den letzten Abschnitt verwässern;
        // sein Text kommt unten je Datei dazu, mit dem Dateinamen als Überschrift.
        $html = \FundusDateitext\Dateitext::ohneBlock((string) $page->html);
        $teile = preg_split('/(<h[1-4][^>]*>.*?<\/h[1-4]>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $abschnitte = [];
        $ueberschrift = 'Einleitung';
        foreach ($teile as $teil) {
            if (preg_match('/^<h[1-4]/i', $teil)) {
                $ueberschrift = self::klartext($teil) ?: $ueberschrift;
                continue;
            }
            $text = self::klartext($teil);
            if (mb_strlen($text) < 20) {
                continue;
            }
            foreach (self::teilen($text, 1400) as $stueck) {
                $abschnitte[] = ['ueberschrift' => mb_substr($ueberschrift, 0, 250), 'text' => $stueck];
            }
        }
        $dateien = $page->id ? \FundusDateitext\Dateitext::texte((int) $page->id, $html) : [];
        foreach ($dateien as $datei) {
            if (mb_strlen($datei['text']) < 20) {
                continue;
            }
            $ueberschrift = ($datei['art'] === 'anhang' ? 'Anhang' : 'Bild') . ' „' . $datei['name'] . '“';
            foreach (self::teilen($datei['text'], 1400) as $stueck) {
                $abschnitte[] = ['ueberschrift' => mb_substr($ueberschrift, 0, 250), 'text' => $stueck];
            }
        }

        return $abschnitte;
    }

    protected static function klartext(string $html): string
    {
        $html = preg_replace('/<\/(p|li|tr|pre|h[1-6]|div|blockquote)>|<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/t[dh]>/i', ' | ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);

        return trim(preg_replace("/\n\s*\n+/", "\n", $text));
    }

    /** @return string[] */
    protected static function teilen(string $text, int $max): array
    {
        if (mb_strlen($text) <= $max) {
            return [$text];
        }
        $stuecke = [];
        $aktuell = '';
        foreach (explode("\n", $text) as $zeile) {
            if ($aktuell !== '' && mb_strlen($aktuell) + mb_strlen($zeile) > $max) {
                $stuecke[] = trim($aktuell);
                $aktuell = '';
            }
            $aktuell .= $zeile . "\n";
        }
        if (trim($aktuell) !== '') {
            $stuecke[] = trim($aktuell);
        }

        return $stuecke;
    }

    /** Bettet einen Artikel neu ein; Entwürfe, Vorlagen und gelöschte Artikel fliegen raus. */
    public static function indexieren(int $pageId): int
    {
        self::tabelleAnlegen();
        $page = Page::query()->find($pageId);
        DB::table(self::TABELLE)->where('page_id', $pageId)->delete();
        if (!$page || $page->draft || $page->template || $page->trashed()) {
            return 0;
        }

        $abschnitte = self::zerlegen($page);
        if (!$abschnitte) {
            return 0;
        }
        // Titel und Überschrift gehören in den eingebetteten Text, sonst fehlt Stücken wie
        // „Schritte“ der Zusammenhang, worum es geht.
        $vektoren = self::einbetten(array_map(
            fn ($a) => "{$page->name} – {$a['ueberschrift']}\n{$a['text']}",
            $abschnitte
        ));
        foreach ($abschnitte as $i => $a) {
            DB::insert(
                'INSERT INTO ' . self::TABELLE . ' (page_id, nummer, ueberschrift, text, vektor) VALUES (?, ?, ?, ?, VEC_FromText(?))',
                [$pageId, $i, $a['ueberschrift'], $a['text'], json_encode($vektoren[$i])]
            );
        }

        return count($abschnitte);
    }

    /** Dateien in vendor/, in dieser Reihenfolge (Sprachen von highlight.js brauchen hljs). */
    protected const BIBLIOTHEKEN = ['marked.umd.js', 'purify.min.js', 'highlight.min.js', 'hljs-powershell.min.js', 'hljs-dos.min.js'];

    public static function bibliothekenVersion(): string
    {
        return substr(md5(implode('|', array_map(fn ($d) => filemtime(__DIR__ . "/vendor/{$d}"), self::BIBLIOTHEKEN))), 0, 10);
    }

    public static function bibliotheken(): \Illuminate\Http\Response
    {
        $inhalt = implode(";\n", array_map(fn ($d) => file_get_contents(__DIR__ . "/vendor/{$d}"), self::BIBLIOTHEKEN));

        return response($inhalt, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            // Die Adresse enthält die Version; ändert sich eine Datei, ändert sich die Adresse.
            'Cache-Control' => 'private, max-age=2592000, immutable',
        ]);
    }

    public static function entfernen(int $pageId): void
    {
        self::tabelleAnlegen();
        DB::table(self::TABELLE)->where('page_id', $pageId)->delete();
    }

    /** Wie lange der Stand einer Antwort nach dem letzten Wort abrufbar bleibt (Sekunden). */
    public const LAUF_DAUER = 900;

    /** So viele Fragen darf eine Person je Minute stellen. Im normalen Gespräch kommt niemand darauf. */
    public const FRAGEN_PRO_MINUTE = 10;

    /**
     * Beantwortet eine Frage als Datenstrom (eine JSON-Zeile je Ereignis):
     * {"quellen": [...]}, dann {"text": "..."} je Wortstück, am Ende {"fertig": true}.
     *
     * Mit einer Lauf-ID überlebt die Antwort einen Seitenwechsel: PHP rechnet weiter, auch wenn der Browser
     * die Verbindung schließt (ignore_user_abort), und legt den Stand im Cache ab. Die nächste Seite holt ihn
     * über GET /fundus/ki/lauf/{lauf} (lauf()). Ohne Warteschlange, damit lange Texterkennungen den Chat
     * nicht aufhalten.
     */
    public static function antworten(Request $request): StreamedResponse|JsonResponse
    {
        // Ollama rechnet eine Antwort nach der anderen. Ohne Grenze könnte eine Person (oder ein Skript mit ihrer
        // Sitzung) den Chat für alle anderen blockieren.
        $zaehler = 'fundus-ki-fragen:' . user()->id;
        if (RateLimiter::tooManyAttempts($zaehler, self::FRAGEN_PRO_MINUTE)) {
            return response()->json(['fehler' => 'Zu viele Fragen in kurzer Zeit.'], 429);
        }
        RateLimiter::hit($zaehler, 60);

        $daten = $request->validate([
            'frage' => ['required', 'string', 'max:1000'],
            'verlauf' => ['array', 'max:6'],
            'verlauf.*.rolle' => ['required', 'in:nutzer,ki'],
            'verlauf.*.text' => ['required', 'string', 'max:4000'],
            'lauf' => ['nullable', 'string', 'regex:/^[a-z0-9]{8,40}$/'],
        ]);
        $frage = trim($daten['frage']);
        $verlauf = $daten['verlauf'] ?? [];
        $schluessel = !empty($daten['lauf']) ? self::laufSchluessel($daten['lauf']) : null;

        return response()->stream(function () use ($frage, $verlauf, $schluessel) {
            // PHP-FPM puffert im Image bis 4 KB (output_buffering = 4096). Ohne das Leeren kämen
            // Wörter und Lebenszeichen verspätet an, und nginx bricht nach 60 s ohne Daten ab.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ob_implicit_flush(true);
            set_time_limit(0);
            if ($schluessel) {
                ignore_user_abort(true);
            }

            // Stand für die nächste Seite: höchstens alle 250 ms in den Cache, Quellen und Ende sofort.
            $stand = ['quellen' => [], 'text' => '', 'fertig' => false, 'fehler' => ''];
            $zuletzt = 0.0;
            $gestoppt = false;
            $senden = function (array $ereignis) use (&$stand, &$zuletzt, &$gestoppt, $schluessel): bool {
                if (!connection_aborted()) {
                    echo json_encode($ereignis, JSON_UNESCAPED_UNICODE) . "\n";
                    flush();
                }
                if (!$schluessel) {
                    return true;
                }
                if (isset($ereignis['quellen'])) {
                    $stand['quellen'] = $ereignis['quellen'];
                }
                $stand['text'] .= $ereignis['text'] ?? '';
                $stand['fehler'] = $ereignis['fehler'] ?? $stand['fehler'];
                $stand['fertig'] = $stand['fertig'] || !empty($ereignis['fertig']);
                $wichtig = !isset($ereignis['text']);
                if ($wichtig || microtime(true) - $zuletzt >= 0.25) {
                    Cache::put($schluessel, $stand, self::LAUF_DAUER);
                    $zuletzt = microtime(true);
                    // Stopp-Knopf auf irgendeiner Seite: Ollama nicht umsonst weiterrechnen lassen.
                    $gestoppt = $gestoppt || Cache::get($schluessel . ':stopp') === true;
                }
                return !$gestoppt;
            };

            try {
                $quellen = self::suchen($frage, $verlauf);
            } catch (\Throwable $e) {
                report($e);
                $senden(['fehler' => 'Die KI ist gerade nicht erreichbar. Läuft Ollama?']);
                return;
            }
            // Auszug und erste Zeile gehen mit, damit der Chat zeigen kann, woher eine Aussage
            // stammt, und der Artikel die Stelle markieren kann. Es ist Text, den die Person ohnehin lesen darf.
            $senden(['quellen' => array_map(fn ($q) => [
                'titel' => $q['titel'], 'abschnitt' => $q['ueberschrift'], 'thema' => $q['thema'], 'url' => $q['url'],
                'auszug' => mb_strimwidth($q['text'], 0, 420, ' …'),
                'stelle' => mb_substr(strtok($q['text'], "\n") ?: $q['text'], 0, 80),
            ], $quellen)]);

            if (!$quellen) {
                $senden(['text' => 'Dazu habe ich im Wiki nichts gefunden, das du lesen darfst.']);
                $senden(['fertig' => true]);
                return;
            }

            $kontext = '';
            foreach ($quellen as $i => $q) {
                $kontext .= '[' . ($i + 1) . "] {$q['titel']} – {$q['ueberschrift']}\n{$q['text']}\n\n";
            }
            $nachrichten = [['role' => 'system', 'content' => self::anweisung()]];
            foreach ($verlauf as $eintrag) {
                $nachrichten[] = ['role' => $eintrag['rolle'] === 'ki' ? 'assistant' : 'user', 'content' => $eintrag['text']];
            }
            $nachrichten[] = ['role' => 'user', 'content' => "Ausschnitte aus dem Wiki:\n\n{$kontext}Frage: {$frage}"];

            self::chatStreamen($nachrichten, $senden);
            $senden(['fertig' => true]);
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-cache',
            // Sonst puffert nginx die Antwort und sie erscheint erst am Ende.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    protected static function laufSchluessel(string $lauf): string
    {
        // Je Person: Eine fremde Lauf-ID führt nie zu einer fremden Antwort.
        return 'fundus-ki-lauf:' . user()->id . ':' . $lauf;
    }

    /** GET /fundus/ki/lauf/{lauf}: Stand einer laufenden oder eben fertigen Antwort. */
    public static function lauf(string $lauf): JsonResponse
    {
        if (!preg_match('/^[a-z0-9]{8,40}$/', $lauf)) {
            abort(404);
        }
        $stand = Cache::get(self::laufSchluessel($lauf));
        return $stand ? response()->json($stand) : response()->json(['weg' => true], 404);
    }

    /** POST /fundus/ki/lauf/{lauf}/stopp: Antwort beenden, auch wenn sie auf einer anderen Seite begann. */
    public static function stoppen(string $lauf): JsonResponse
    {
        if (!preg_match('/^[a-z0-9]{8,40}$/', $lauf)) {
            abort(404);
        }
        Cache::put(self::laufSchluessel($lauf) . ':stopp', true, self::LAUF_DAUER);
        return response()->json(['ok' => true]);
    }

    protected static function anweisung(): string
    {
        return <<<'TEXT'
Du bist Fundus, die KI-Hilfe in diesem Firmenwiki. Du beantwortest Fragen der Beschäftigten aus den Artikeln des Wikis.
Du bist freundlich, hilfsbereit und sachlich.

Stil:
- Deutsch, immer mit „du“, niemals „Sie“.
- Beginne mit höchstens einem kurzen, freundlichen Halbsatz, dann direkt zur Sache. Kein Rollenspiel, keine Floskeln.
- Knapp und klar. Schritte als nummerierte Liste, Befehle in Codeblöcken mit Sprachangabe (z. B. ```powershell).

Regeln:
- Antworte nur mit Informationen aus den mitgelieferten Ausschnitten. Erfinde nichts dazu.
- Belege Aussagen mit der Nummer des Ausschnitts in eckigen Klammern, z. B. [1].
- Steht die Antwort nicht in den Ausschnitten, sag das ehrlich in einem Satz und nenne, welcher Artikel am ehesten weiterhilft.
- Gib niemals Passwörter, PINs, Schlüssel oder andere Zugangsdaten aus. Verweise auf den Passwort-Manager der Firma.
TEXT;
    }

    /**
     * Sucht die passendsten Textstücke, beschränkt auf Artikel, die die angemeldete Person lesen darf.
     *
     * @return array<int, array{titel: string, ueberschrift: string, text: string, thema: string, url: string}>
     */
    protected static function suchen(string $frage, array $verlauf): array
    {
        self::tabelleAnlegen();
        // Kurze Nachfragen („und bei Outlook?“) brauchen die vorige Frage als Zusammenhang.
        $suchtext = $frage;
        $vorige = array_values(array_filter($verlauf, fn ($e) => $e['rolle'] === 'nutzer'));
        // str_word_count zählt Umlaute als Worttrenner („Größe prüfen“ wären vier Wörter).
        if ($vorige && preg_match_all('/[\p{L}\p{N}]+/u', $frage) < 6) {
            $suchtext = end($vorige)['text'] . "\n" . $frage;
        }
        $vektor = self::einbetten([$suchtext])[0];

        // BookStacks eigene Rechteabfrage: dieselbe Filterung wie beim Öffnen eines Artikels.
        $sichtbar = Page::query()->scopes('visible')->where('draft', false)->where('template', false)->pluck('id')->all();
        if (!$sichtbar) {
            return [];
        }

        $kandidaten = DB::table(self::TABELLE)
            ->select(['page_id', 'ueberschrift', 'text', DB::raw('VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) AS abstand')])
            ->addBinding(json_encode($vektor), 'select')
            ->whereIn('page_id', $sichtbar)
            ->orderBy('abstand')
            ->limit(12)
            ->get();

        // Kurze Fragen („was ist vpn“) liegen weiter weg als ausführliche; eine feste Grenze
        // würde sie verwerfen. Deshalb relativ zum besten Treffer filtern, mit Obergrenze.
        // Stücke, die ein Wort der Frage wörtlich enthalten (z. B. Produktnamen), bleiben immer drin.
        $bester = $kandidaten->first()->abstand ?? 1.0;
        $grenze = min((float) env('KI_MAX_ABSTAND', 0.72), $bester + 0.15);
        $woerter = array_filter(
            preg_split('/[^\p{L}\p{N}-]+/u', mb_strtolower($frage)),
            fn ($w) => mb_strlen($w) >= 4 && !in_array($w, ['was', 'wie', 'wer', 'wann', 'warum', 'wofür', 'welche', 'welcher', 'welches', 'kann', 'muss', 'mache', 'machen', 'gibt', 'eine', 'einen', 'einem', 'einer', 'der', 'die', 'das', 'und', 'oder', 'nicht', 'beim', 'wenn', 'dann', 'habe', 'haben'], true)
        );
        $treffer = $kandidaten->filter(function ($t) use ($grenze, $woerter) {
            if ($t->abstand <= $grenze) {
                return true;
            }
            $inhalt = mb_strtolower($t->ueberschrift . ' ' . $t->text);
            foreach ($woerter as $w) {
                if (str_contains($inhalt, $w)) {
                    return true;
                }
            }
            return false;
        })->take(6);

        $seiten = Page::query()->scopes('visible')->with('book')->whereIn('id', $treffer->pluck('page_id')->unique())->get()->keyBy('id');

        return $treffer->filter(fn ($t) => $seiten->has($t->page_id))->map(fn ($t) => [
            'titel' => $seiten[$t->page_id]->name,
            'ueberschrift' => $t->ueberschrift,
            'text' => $t->text,
            'thema' => $seiten[$t->page_id]->book?->name ?? '',
            'url' => $seiten[$t->page_id]->getUrl(),
        ])->values()->all();
    }

    protected static function chatStreamen(array $nachrichten, callable $senden): void
    {
        $rest = '';
        $letzterPuls = time();
        $curl = curl_init(self::ollama() . '/api/chat');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => env('KI_MODELL', 'qwen3.5:4b'),
                'messages' => $nachrichten,
                'stream' => true,
                'think' => false,
                'options' => ['temperature' => 0.1, 'num_ctx' => 8192],
            ]),
            CURLOPT_TIMEOUT => 600,
            CURLOPT_WRITEFUNCTION => function ($curl, $block) use (&$rest, $senden) {
                $rest .= $block;
                while (($pos = strpos($rest, "\n")) !== false) {
                    $zeile = json_decode(substr($rest, 0, $pos), true);
                    $rest = substr($rest, $pos + 1);
                    if (isset($zeile['message']['content']) && $zeile['message']['content'] !== '') {
                        if (!$senden(['text' => $zeile['message']['content']])) {
                            return 0; // gestoppt: curl bricht ab
                        }
                    }
                    if (isset($zeile['error'])) {
                        $senden(['fehler' => 'Ollama: ' . $zeile['error']]);
                    }
                }
                return strlen($block);
            },
            // Solange das Modell noch lädt, kommt nichts; eine leere Zeile alle paar Sekunden
            // verhindert, dass nginx oder der Browser die Verbindung als tot abbricht.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function () use (&$letzterPuls) {
                if (time() - $letzterPuls >= 5 && !connection_aborted()) {
                    echo "\n";
                    flush();
                    $letzterPuls = time();
                }
                return 0;
            },
        ]);
        if (curl_exec($curl) === false && curl_errno($curl) !== CURLE_WRITE_ERROR) {
            // Die curl-Meldung nennt interne Adressen; sie gehört ins Protokoll, nicht in den Chat.
            report(new \RuntimeException('Ollama-Chat: ' . curl_error($curl)));
            $senden(['fehler' => 'Die KI ist gerade nicht erreichbar. Läuft Ollama?']);
        }
        curl_close($curl);
    }
}

/** Bettet einen Artikel im Hintergrund neu ein, damit das Speichern nicht wartet. */
class IndexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $pageId)
    {
    }

    public function handle(): void
    {
        Ki::indexieren($this->pageId);
    }
}

/** php artisan fundus:ki-index [--neu] – baut den Index aller Artikel auf. */
class IndexCommand extends Command
{
    protected $signature = 'fundus:ki-index {--neu : Tabelle löschen und neu anlegen, z. B. nach Wechsel des Einbettungsmodells}';
    protected $description = 'Bettet alle Artikel für die KI Fundus ein';

    public function handle(): int
    {
        if (!Ki::aktiv()) {
            $this->error('OLLAMA_URL ist nicht gesetzt.');
            return 1;
        }
        Ki::tabelleAnlegen((bool) $this->option('neu'));
        $ids = Page::query()->where('draft', false)->where('template', false)->pluck('id');
        // Reste von Artikeln, die es nicht mehr gibt oder die im Papierkorb liegen: Mit einem Thema oder Abschnitt
        // gelöscht, meldet BookStack die Artikel darin nicht einzeln. Beim Wiederherstellen kommen sie wieder hinein.
        DB::table(Ki::TABELLE)->whereNotIn('page_id', $ids)->delete();
        $stuecke = 0;
        $this->withProgressBar($ids, function ($id) use (&$stuecke) {
            $stuecke += Ki::indexieren((int) $id);
        });
        $this->newLine();
        $this->info("{$ids->count()} Artikel, {$stuecke} Textstücke eingebettet.");

        return 0;
    }
}
