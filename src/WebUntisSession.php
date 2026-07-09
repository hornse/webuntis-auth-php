<?php
/**
 * WebUntis Session-Management
 * ============================
 * Datei: src/WebUntisSession.php
 *
 * Verwaltet PHP-Sessions für WebUntis-authentifizierte Benutzer.
 *
 * WICHTIG – Aufruf-Reihenfolge im Router:
 * ----------------------------------------
 * WebUntisSession::prepare() MUSS ganz oben im Router stehen,
 * vor jedem require/include. Grund: session_name() und
 * $_SERVER['HTTPS'] müssen vor dem ersten session_start()
 * gesetzt werden, sonst liest PHP den Cookie-Namen falsch
 * und der Secure-Flag fehlt (kritisch bei SSL-Proxy wie Uberspace).
 *
 * Empfohlene router.php:
 *   <?php
 *   WebUntisSession::prepare('proj_session'); // ZUERST
 *   require 'config.php';
 *   // ... Rest des Routers
 *
 * BEKANNTE FALLE – empty(0):
 * ---------------------------
 * WebUntis-Lehrer ohne lokalen DB-Eintrag bekommen benutzer_id=0.
 * empty(0) === true in PHP → würde fälschlich als "nicht eingeloggt"
 * behandelt. Immer isset() statt empty() für benutzer_id verwenden:
 *   if (!isset($_SESSION['benutzer_id']) || $_SESSION['benutzer_id'] === null)
 *
 * Copyright (C) 2026 Sebastian Horn, Friedrich-Rückert-Gymnasium Düsseldorf
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

class WebUntisSession
{
    /**
     * Muss GANZ OBEN im Router aufgerufen werden – vor jedem require.
     *
     * Setzt:
     *   - $_SERVER['HTTPS'] = 'on'  → PHP setzt Secure-Cookie-Flag
     *   - session_name()            → PHP liest richtigen Cookie-Namen
     *
     * Ohne diesen Aufruf schlägt die Session-Wiederherstellung fehl
     * wenn der Server hinter einem SSL-Proxy (Uberspace, nginx, etc.) läuft.
     *
     * @param string $sessionName  Name des Session-Cookies (Standard: 'app_session')
     */
    public static function prepare(string $sessionName = 'app_session'): void
    {
        // SSL-Proxy-Flag – PHP denkt sonst die Verbindung sei unsicher
        $_SERVER['HTTPS'] = 'on';
        // Muss vor session_start() gesetzt werden
        session_name($sessionName);
    }

    /**
     * Startet die Session mit sicheren Cookie-Parametern.
     *
     * @param array $config  Session-Konfiguration:
     *   'name'             → Cookie-Name (muss mit prepare() übereinstimmen)
     *   'lifetime_seconds' → Session-Lebensdauer in Sekunden (Standard: 8h)
     */
    public static function start(array $config = []): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $name     = $config['name']             ?? 'app_session';
        $lifetime = $config['lifetime_seconds'] ?? 8 * 3600;

        session_name($name);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => true,   // Nur HTTPS – funktioniert mit prepare() oben
            'httponly' => true,   // Kein JavaScript-Zugriff
            'samesite' => 'Lax',  // CSRF-Basisschutz
        ]);
        session_start();
    }

    /**
     * Speichert Lehrer-Login in der Session.
     *
     * @param array  $details   Rückgabe von WebUntisAuth::authenticate()
     * @param string $rolle     'admin' oder 'lernbegleiter'
     * @param int    $dbId      Lokale DB-ID (0 wenn kein DB-Eintrag)
     * @param int    $schuleId  Mandanten-ID
     */
    public static function loginLehrer(
        array  $details,
        string $rolle,
        int    $dbId,
        int    $schuleId
    ): void {
        session_regenerate_id(true); // Session-Fixation verhindern
        $_SESSION = [
            'benutzer_id'  => $dbId,           // 0 = WebUntis-only, kein DB-Eintrag
            'wu_person_id' => $details['personId'],
            'wu_kuerzel'   => $details['kuerzel']  ?? '',
            'wu_vorname'   => $details['vorname']  ?? '',
            'wu_nachname'  => $details['nachname'] ?? '',
            'rolle'        => $rolle,
            'schule_id'    => $schuleId,
            'typ'          => 'benutzer',
        ];
    }

    /**
     * Speichert lokalen Benutzer-Login (E-Mail + Passwort) in der Session.
     *
     * @param array $user      DB-Zeile mit id, rolle, schule_id
     */
    public static function loginLokal(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION = [
            'benutzer_id' => (int)$user['id'],
            'rolle'       => $user['rolle'],
            'schule_id'   => (int)$user['schule_id'],
            'typ'         => 'benutzer',
        ];
    }

    /**
     * Speichert Schüler-Login in der Session.
     *
     * @param array $schueler  DB-Zeile mit id
     * @param int   $schuleId  Mandanten-ID
     */
    public static function loginSchueler(array $schueler, int $schuleId): void
    {
        session_regenerate_id(true);
        $_SESSION = [
            'benutzer_id' => (int)$schueler['id'],
            'rolle'       => 'schueler',
            'schule_id'   => $schuleId,
            'typ'         => 'schueler',
        ];
    }

    /**
     * Gibt den aktuellen Benutzer zurück oder null wenn nicht eingeloggt.
     *
     * WICHTIG: Prüft mit isset() statt empty() weil benutzer_id=0
     * (WebUntis-Lehrer ohne DB-Eintrag) sonst fälschlich als
     * "nicht eingeloggt" behandelt würde (empty(0) === true).
     */
    public static function currentUser(): ?array
    {
        if (!isset($_SESSION['benutzer_id']) || $_SESSION['benutzer_id'] === null) {
            return null;
        }
        return [
            'id'        => (int)$_SESSION['benutzer_id'],
            'rolle'     => $_SESSION['rolle']     ?? 'lernbegleiter',
            'schule_id' => (int)($_SESSION['schule_id'] ?? 1),
            'typ'       => $_SESSION['typ']       ?? 'benutzer',
        ];
    }

    /**
     * Gibt den Anzeigenamen des aktuellen Benutzers zurück.
     * Für WebUntis-Lehrer aus wu_vorname/wu_nachname.
     */
    public static function displayName(): string
    {
        if (isset($_SESSION['wu_vorname'])) {
            return trim(($_SESSION['wu_vorname'] ?? '') . ' ' . ($_SESSION['wu_nachname'] ?? ''));
        }
        return '';
    }

    /**
     * Beendet die Session sicher.
     */
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Prüft ob der Benutzer eingeloggt ist und bricht mit 401 ab wenn nicht.
     * Convenience-Wrapper für API-Endpunkte.
     */
    public static function requireAuth(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            http_response_code(401);
            die(json_encode(['error' => 'Nicht eingeloggt.']));
        }
        return $user;
    }

    /**
     * Prüft ob der Benutzer Admin ist und bricht mit 403 ab wenn nicht.
     */
    public static function requireAdmin(): array
    {
        $user = self::requireAuth();
        if ($user['rolle'] !== 'admin') {
            http_response_code(403);
            die(json_encode(['error' => 'Nur Administratoren.']));
        }
        return $user;
    }
}
