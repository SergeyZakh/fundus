<?php

namespace FundusAnmeldung;

use BookStack\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Berufstitel neben den Namen. Gepflegt von Admins im Wiki: Einstellungen → Benutzer → Person, Abschnitt
 * „Berufstitel“ (Baustein fundus/titel-feld, gespeichert in ausFormular()).
 *
 * Optional kann der Titel stattdessen aus dem Anmeldedienst kommen (Keycloak, Authentik, Entra …); das ist
 * standardmäßig aus (FUNDUS_TITEL_CLAIM leer), damit Wiki und Anmeldedienst sich nicht überschreiben:
 *
 * Bei einer OIDC-Anmeldung liefert BookStack dem Theme zuerst die Claims des ID-Tokens
 * (OIDC_ID_TOKEN_PRE_VALIDATE) und im selben Aufruf danach das angemeldete Konto (AUTH_LOGIN).
 * Die Claims werden zwischengemerkt und beim Login in fundus_titel geschrieben.
 *
 * Claim-Namen über FUNDUS_TITEL_CLAIM (Vorgabe „titel“) und FUNDUS_STUFE_CLAIM („titel_stufe“).
 * Fehlt die Stufe, wird sie aus dem Titel abgeleitet. Fehlt der Titel im Token, bleibt der gespeicherte
 * Wert unangetastet; ein leerer Claim löscht ihn. Konten ohne Eintrag fallen auf theme/fundus/titel.php zurück.
 */
class Anmeldung
{
    public const TABELLE = 'fundus_titel';
    public const STUFEN = ['leitung', 'senior', 'junior', 'azubi', 'team'];

    protected static ?array $claims = null;
    protected static bool $angelegt = false;

    public static function tabelleAnlegen(): void
    {
        if (self::$angelegt || Cache::get('fundus-titel-schema') === 1) {
            self::$angelegt = true;
            return;
        }
        DB::statement('CREATE TABLE IF NOT EXISTS ' . self::TABELLE . ' (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            titel VARCHAR(120) NOT NULL,
            stufe VARCHAR(16) NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        Cache::forever('fundus-titel-schema', 1);
        self::$angelegt = true;
    }

    /** ThemeEvents::OIDC_ID_TOKEN_PRE_VALIDATE: Claims merken, Token unverändert lassen. */
    public static function claimsMerken(array $idToken, array $zugang): ?array
    {
        self::$claims = $idToken;
        return null;
    }

    /** ThemeEvents::AUTH_LOGIN: Titel aus den gemerkten Claims übernehmen. */
    public static function nachAnmeldung(string $verfahren, User $nutzer): void
    {
        if ($verfahren !== 'oidc' || self::$claims === null) {
            return;
        }
        $claims = self::$claims;
        self::$claims = null;
        self::titelUebernehmen($nutzer->id, $claims);
    }

    /** Titel und Stufe aus Claims speichern. Öffentlich für Tests. */
    public static function titelUebernehmen(int $userId, array $claims): void
    {
        // Leer (Vorgabe in docker-compose.yml): Titel pflegen die Admins im Wiki, der Anmeldedienst liefert keinen.
        $titelClaim = (string) env('FUNDUS_TITEL_CLAIM', 'titel');
        if ($titelClaim === '' || !array_key_exists($titelClaim, $claims)) {
            return;
        }
        self::tabelleAnlegen();
        $titel = trim((string) (is_array($claims[$titelClaim]) ? ($claims[$titelClaim][0] ?? '') : $claims[$titelClaim]));
        if ($titel === '') {
            DB::table(self::TABELLE)->where('user_id', $userId)->delete();
            return;
        }
        $stufe = mb_strtolower(trim((string) ($claims[env('FUNDUS_STUFE_CLAIM', 'titel_stufe')] ?? '')));
        DB::table(self::TABELLE)->updateOrInsert(['user_id' => $userId], [
            'titel' => mb_substr($titel, 0, 120),
            'stufe' => in_array($stufe, self::STUFEN, true) ? $stufe : self::stufeAusTitel($titel),
            'updated_at' => now(),
        ]);
    }

    /**
     * Titel und Stufe aus dem Formular einer Person (Einstellungen → Benutzer). Läuft nach dem Speichern der
     * Person (ACTIVITY_LOGGED user_create/user_update), nur mit den Feldern im Formular und nur für Admins.
     * Leerer Titel löscht; ohne gültige Stufe wird sie aus dem Titel abgeleitet.
     */
    public static function ausFormular(User $nutzer, Request $request): void
    {
        if (!$request->has('fundus_titel') || !userCan('users-manage')) {
            return;
        }
        self::tabelleAnlegen();
        $titel = mb_substr(trim((string) $request->input('fundus_titel')), 0, 120);
        if ($titel === '') {
            DB::table(self::TABELLE)->where('user_id', $nutzer->id)->delete();
            return;
        }
        $stufe = (string) $request->input('fundus_stufe', '');
        DB::table(self::TABELLE)->updateOrInsert(['user_id' => $nutzer->id], [
            'titel' => $titel,
            'stufe' => in_array($stufe, self::STUFEN, true) ? $stufe : self::stufeAusTitel($titel),
            'updated_at' => now(),
        ]);
    }

    /** Gespeicherter Titel einer Person für das Formular, ohne Rückfall auf titel.php. */
    public static function gespeichert(?int $userId): ?object
    {
        if (!$userId) {
            return null;
        }
        self::tabelleAnlegen();
        return DB::table(self::TABELLE)->where('user_id', $userId)->first();
    }

    /** Stufe für die Farbe der Plakette, wenn keine gewählt oder geliefert wurde. */
    public static function stufeAusTitel(string $titel): string
    {
        $t = mb_strtolower($titel);
        return match (true) {
            (bool) preg_match('/\b(ceo|cto|cfo|coo)\b|geschäftsführ|vorstand|inhaber|bereichsleit|head of/u', $t) => 'leitung',
            (bool) preg_match('/senior|teamleit|team lead|principal|lead\b/u', $t) => 'senior',
            (bool) preg_match('/auszubild|azubi|werkstudent|praktikant|trainee/u', $t) => 'azubi',
            (bool) preg_match('/consultant|berater|engineer|entwickler|administrator|techniker/u', $t) => 'junior',
            default => 'team',
        };
    }

    /**
     * Titel aller Konten: user_id → [titel, stufe]. Einträge aus dem Anmeldedienst gehen vor,
     * sonst die Liste in theme/fundus/titel.php (nach E-Mail).
     * @param iterable<User> $nutzer
     */
    public static function titelFuer(iterable $nutzer): array
    {
        self::tabelleAnlegen();
        $datei = theme_path('titel.php');
        $liste = is_file($datei) ? array_change_key_case((array) require $datei, CASE_LOWER) : [];
        $ausDienst = DB::table(self::TABELLE)->get()->keyBy('user_id');
        $ergebnis = [];
        foreach ($nutzer as $n) {
            if (isset($ausDienst[$n->id])) {
                $ergebnis[$n->id] = ['titel' => $ausDienst[$n->id]->titel, 'stufe' => $ausDienst[$n->id]->stufe];
            } elseif (isset($liste[strtolower((string) $n->email)])) {
                $e = $liste[strtolower((string) $n->email)];
                $ergebnis[$n->id] = ['titel' => $e['titel'] ?? null, 'stufe' => $e['stufe'] ?? 'team'];
            }
        }
        return $ergebnis;
    }
}
