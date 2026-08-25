<?php
/**
 * WebUntis JSON-RPC Authentifizierung
 * ====================================
 * Datei: src/WebUntisAuth.php
 *
 * Authentifiziert Benutzer (Lehrer und Schüler) gegen die
 * WebUntis JSON-RPC-API. Vollständig eigenständig – keine
 * externen Abhängigkeiten außer PHP 8.1+, PDO und cURL.
 *
 * VERWENDUNG:
 * -----------
 * 1. config.example.php → config.php kopieren und anpassen
 * 2. WebUntisAuth und WebUntisSession einbinden
 * 3. Im Router: WebUntisSession::prepare() ganz oben aufrufen
 * 4. Beim Login: WebUntisAuth::authenticate() aufrufen
 *
 * WIEDERVERWENDUNG IN ANDEREN PROJEKTEN:
 * ----------------------------------------
 * Dieses Modul ist bewusst projektunabhängig gehalten.
 * Es benötigt nur:
 *   - Eine PDO-Datenbankverbindung (MariaDB/MySQL oder SQLite)
 *   - Ein Config-Array (siehe config.example.php)
 *   - PHP 8.1+ mit cURL und PDO
 *
 * PERSONTYPE-WERTE (WebUntis):
 *   2  = Lehrkraft
 *   5  = Schüler
 *   16 = WebUntis-Administrator (personId = -1, kein Stundenplan-Eintrag)
 *
 * BEKANNTE EIGENHEITEN:
 *   - WebUntis-Admins (personType 16) haben personId = -1
 *     → getTeachers() liefert keinen Treffer
 *     → Name muss aus DB oder Fallback kommen
 *   - Session-Cookie braucht JSESSIONID aus Set-Cookie-Header
 *     → jsonRpc() speichert Cookie intern für Folgeaufrufe
 *   - Hinter SSL-Proxy (z.B. Uberspace): session_name() und
 *     $_SERVER['HTTPS'] = 'on' müssen im Router ganz oben stehen
 *     (vor jedem require) – siehe WebUntisSession::prepare()
 *
 * Copyright (C) 2026 Sebastian Horn, Friedrich-Rückert-Gymnasium Düsseldorf
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

class WebUntisAuth
{
    // ── personType-Konstanten ───────────────────────────────────────────────
    /** Lehrkraft – hat Stundenplan-Eintrag, personId > 0 */
    public const TYPE_LEHRER   = 2;
    /** Schüler – key entspricht Schild-ID */
    public const TYPE_SCHUELER = 5;
    /** WebUntis-Administrator – personId = -1, kein Stundenplan-Eintrag */
    public const TYPE_ADMIN    = 16;

    /** Interner Session-Cookie für Folgeaufrufe nach authenticate */
    private string $sessionCookie = '';

    /** Ob authenticate() die WebUntis-Sitzung im Erfolgsfall offenlässt */
    private readonly bool $sitzungOffenHalten;

    /**
     * @param PDO   $db      Datenbankverbindung (für Login-Log und Brute-Force)
     * @param array $config  WebUntis-Konfiguration (siehe config.example.php)
     */
    public function __construct(
        private readonly PDO   $db,
        private readonly array $config
    ) {
        $this->sitzungOffenHalten = (bool)($config['sitzung_offen_halten'] ?? false);
    }

    // ── Öffentliche API ─────────────────────────────────────────────────────

    /**
     * Authentifiziert einen Benutzer und gibt Profildaten zurück.
     *
     * Rückgabe bei Lehrkraft (personType 2):
     *   ['personType'=>2, 'personId'=>1013, 'kuerzel'=>'Ho',
     *    'vorname'=>'Sebastian', 'nachname'=>'Horn']
     *
     * Rückgabe bei WebUntis-Admin (personType 16):
     *   ['personType'=>16, 'personId'=>-1, 'kuerzel'=>'adminho',
     *    'vorname'=>'', 'nachname'=>'']
     *   → Name muss aus lokaler DB nachgeschlagen werden
     *
     * Rückgabe bei Schüler (personType 5):
     *   ['personType'=>5, 'personId'=>34397, 'key'=>'123456',
     *    'vorname'=>'Max', 'nachname'=>'Mustermann']
     *   → key entspricht der Schild-ID
     *
     * @return array|null  null = Login fehlgeschlagen
     */
    public function authenticate(string $username, string $password, string $ip): ?array
    {
        $username = trim($username);
        if ($username === '' || $password === '') return null;

        // Brute-Force-Schutz
        if ($this->tooManyAttempts($username)) {
            $this->log($username, false, 'zu_viele_versuche', $ip);
            return null;
        }

        // WebUntis-Login – Cookie aus Response-Header speichern
        $authResponse = $this->jsonRpc('authenticate', [
            'user'     => $username,
            'password' => $password,
            'client'   => $this->config['client'] ?? 'WebUntisAuth',
        ], saveCookie: true);

        if ($authResponse === null || isset($authResponse['error'])) {
            $this->log($username, false, 'falsches_passwort_oder_netzwerk', $ip);
            return null;
        }

        $result     = $authResponse['result'] ?? null;
        $personType = (int)($result['personType'] ?? 0);
        $personId   = (int)($result['personId']   ?? 0);

        // Erlaubte personTypes prüfen
        $erlaubt = $this->config['allowed_person_types'] ?? [self::TYPE_LEHRER];
        if (!in_array($personType, $erlaubt, true)) {
            // Abgemeldet und zurückgesetzt unabhängig vom Schalter: eine
            // abgewiesene Anmeldung darf keine Sitzung hinterlassen.
            $this->jsonRpc('logout', []);
            $this->sessionCookie = '';
            $this->log($username, false, 'falsche_rolle', $ip);
            return null;
        }

        // Profildaten mit aktiver Session holen
        $details = $this->fetchDetails($username, $personType, $personId);

        // Session freigeben – außer sie soll ausdrücklich offenbleiben
        if (!$this->sitzungOffenHalten) {
            $this->jsonRpc('logout', []);
            $this->sessionCookie = '';
        }
        $this->log($username, true, null, $ip);

        return $details;
    }

    /**
     * Prüft ob ein Benutzername durch zu viele Fehlversuche gesperrt ist.
     */
    public function isLocked(string $username): bool
    {
        return $this->tooManyAttempts(trim($username));
    }

    /**
     * JSESSIONID der laufenden WebUntis-Sitzung.
     *
     * Liefert nur dann einen Wert, wenn 'sitzung_offen_halten' gesetzt ist
     * und die Anmeldung erfolgreich war — sonst null. Ohne den Schalter
     * meldet authenticate() die Sitzung selbst wieder ab.
     *
     * Der Wert ist ein Zugangsdatum: nicht protokollieren, nicht in
     * Berichte, nicht in URL-Parameter.
     */
    public function sessionCookie(): ?string
    {
        return $this->sessionCookie !== '' ? $this->sessionCookie : null;
    }

    // ── Profildaten ─────────────────────────────────────────────────────────

    /**
     * Holt Profildaten nach erfolgreichem Login.
     * Interne Methode – wird von authenticate() aufgerufen.
     */
    private function fetchDetails(string $username, int $personType, int $personId): array
    {
        $details = [
            'personType' => $personType,
            'personId'   => $personId,
        ];

        // ── Lehrkraft oder Admin ──
        if ($personType === self::TYPE_LEHRER || $personType === self::TYPE_ADMIN) {
            $details['kuerzel']  = $username; // Fallback
            $details['vorname']  = '';
            $details['nachname'] = '';

            // WebUntis-Admins haben personId = -1 → kein Eintrag in getTeachers()
            if ($personId > 0) {
                $teachers = $this->jsonRpc('getTeachers', []);
                if ($teachers && isset($teachers['result'])) {
                    foreach ($teachers['result'] as $t) {
                        if ((int)$t['id'] === $personId) {
                            $details['kuerzel']  = $t['name']     ?? $username;
                            $details['vorname']  = $t['foreName'] ?? '';
                            $details['nachname'] = $t['longName'] ?? '';
                            break;
                        }
                    }
                }
            }

            // Name noch leer → aus lokaler DB per Kürzel nachschlagen
            if (empty($details['vorname'])) {
                $details = array_merge($details, $this->lookupNameFromDb($details['kuerzel']));
            }
        }

        // ── Schüler ──
        if ($personType === self::TYPE_SCHUELER) {
            $details['key']      = ''; // Schild-ID
            $details['vorname']  = '';
            $details['nachname'] = '';
            $details['gender']   = '';

            $students = $this->jsonRpc('getStudents', []);
            if ($students && isset($students['result'])) {
                foreach ($students['result'] as $s) {
                    if ((int)$s['id'] === $personId) {
                        $details['key']      = (string)($s['key']      ?? '');
                        $details['vorname']  = $s['foreName'] ?? '';
                        $details['nachname'] = $s['longName'] ?? '';
                        $details['gender']   = $s['gender']   ?? '';
                        break;
                    }
                }
            }
        }

        return $details;
    }

    /**
     * Sucht Vor- und Nachname in der lokalen DB anhand des Kürzels.
     * Projektspezifisch: Tabellenname 'benutzer', Spalten 'vorname'/'nachname'.
     * Kann in eigenen Projekten überschrieben werden.
     */
    protected function lookupNameFromDb(string $kuerzel): array
    {
        try {
            // Versuche standard Projektstunden-Schema
            $stmt = $this->db->prepare(
                'SELECT vorname, nachname FROM benutzer
                 WHERE kuerzel = ? AND aktiv = 1 LIMIT 1'
            );
            $stmt->execute([$kuerzel]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: ['vorname' => '', 'nachname' => ''];
        } catch (\Throwable) {
            return ['vorname' => '', 'nachname' => ''];
        }
    }

    // ── JSON-RPC ────────────────────────────────────────────────────────────

    /**
     * Führt einen WebUntis JSON-RPC-Aufruf durch.
     *
     * @param bool $saveCookie  Beim ersten Aufruf (authenticate) true setzen
     *                          um JSESSIONID aus Set-Cookie zu speichern
     */
    protected function jsonRpc(string $method, array $params, bool $saveCookie = false): ?array
    {
        $baseUrl = rtrim($this->config['base_url'], '/');

        if (!str_starts_with($baseUrl, 'https://')) {
            throw new \RuntimeException('webuntis.base_url muss mit https:// beginnen.');
        }

        $url  = $baseUrl . '/WebUntis/jsonrpc.do?school=' . urlencode($this->config['school']);
        $body = json_encode([
            'id'      => 'wu-' . bin2hex(random_bytes(4)),
            'method'  => $method,
            'params'  => $params,
            'jsonrpc' => '2.0',
        ]);

        $headers = ['Content-Type: application/json'];
        if ($this->sessionCookie !== '') {
            $headers[] = 'Cookie: ' . $this->sessionCookie;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->config['connect_timeout'] ?? 5,
            CURLOPT_TIMEOUT        => $this->config['timeout']         ?? 10,
            CURLOPT_HEADER         => $saveCookie,
        ]);

        $raw        = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErr    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log("WebUntisAuth: cURL-Fehler bei {$method}: {$curlErr}");
            return null;
        }
        if ($httpCode !== 200) {
            error_log("WebUntisAuth: HTTP {$httpCode} bei {$method}");
            return null;
        }

        // JSESSIONID aus Response-Header extrahieren
        if ($saveCookie) {
            $responseHeaders = substr($raw, 0, $headerSize);
            $raw             = substr($raw, $headerSize);
            if (preg_match('/Set-Cookie:\s*(JSESSIONID=[^;]+)/i', $responseHeaders, $m)) {
                $this->sessionCookie = $m[1];
            }
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log("WebUntisAuth: Ungültige JSON-Antwort bei {$method}");
            return null;
        }

        return $decoded;
    }

    // ── Brute-Force-Schutz ──────────────────────────────────────────────────

    /**
     * Prüft ob zu viele Fehlversuche in kurzer Zeit vorliegen.
     * Erfordert Tabelle 'webuntis_login_log' (siehe migration.sql).
     */
    private function tooManyAttempts(string $username): bool
    {
        $max     = $this->config['max_failed_logins'] ?? 5;
        $minutes = $this->config['lockout_minutes']   ?? 15;

        $seit = (new \DateTimeImmutable("-{$minutes} minutes"))->format('Y-m-d H:i:s');

        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM webuntis_login_log
                 WHERE benutzername = ? AND erfolgreich = 0
                   AND zeitpunkt >= ?'
            );
            $stmt->execute([$username, $seit]);
            return (int)$stmt->fetchColumn() >= $max;
        } catch (\Throwable $e) {
            error_log('WebUntisAuth Bremse: ' . $e->getMessage());
            return true; // Voraussetzung fehlt → im Zweifel abweisen
        }
    }

    /**
     * Protokolliert einen Login-Versuch.
     */
    private function log(string $username, bool $ok, ?string $grund, string $ip): void
    {
        try {
            $this->db->prepare(
                'INSERT INTO webuntis_login_log (benutzername, erfolgreich, grund, ip)
                 VALUES (?, ?, ?, ?)'
            )->execute([$username, $ok ? 1 : 0, $grund, $ip]);
        } catch (\Throwable $e) {
            error_log('WebUntisAuth log: ' . $e->getMessage());
        }
    }
}
