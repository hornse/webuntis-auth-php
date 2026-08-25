<?php
/**
 * WebUntis Auth – Beispielkonfiguration
 * =======================================
 * Diese Datei kopieren → config.php und Werte anpassen.
 * config.php NICHT in git einchecken (zu .gitignore hinzufügen).
 *
 * WebUntis-URL deiner Schule findest du indem du dich im Browser
 * bei WebUntis anmeldest und die URL anschaust:
 *   https://SERVERNAME.webuntis.com/WebUntis/?school=SCHULKUERZEL
 *             ↑ base_url bis hier                  ↑ school
 */

return [
    'webuntis' => [
        // ── Verbindung ──────────────────────────────────────────────────────
        'base_url' => 'https://SERVERNAME.webuntis.com', // ohne / am Ende
        'school'   => 'SCHULKUERZEL',                    // aus der WebUntis-URL
        'client'   => 'MeinProjekt',                     // frei wählbar, nur informativ

        // ── Erlaubte Benutzertypen ───────────────────────────────────────────
        // 2  = Lehrkraft
        // 16 = WebUntis-Administrator (personId=-1, kein Stundenplan-Eintrag)
        // 5  = Schüler
        'allowed_person_types' => [2, 16, 5],

        // ── Admin-Kürzel ─────────────────────────────────────────────────────
        // Lehrkräfte (personType 2) die Admin-Rechte erhalten sollen.
        // WebUntis-Admins (personType 16) werden automatisch Admin.
        'admin_kuerzel' => ['Hor', 'Mue'],

        // ── Timeouts ─────────────────────────────────────────────────────────
        'connect_timeout' => 5,  // Sekunden
        'timeout'         => 10, // Sekunden

        // ── Brute-Force-Schutz ───────────────────────────────────────────────
        'max_failed_logins' => 5,  // Versuche bevor gesperrt
        'lockout_minutes'   => 15, // Sperrzeit in Minuten

        // ── Sitzung offenhalten ──────────────────────────────────────────────
        // Im Normalfall aus: authenticate() meldet die WebUntis-Sitzung nach
        // dem Holen der Profildaten selbst wieder ab. Nur einschalten, wenn
        // die Sitzung darüber hinaus gebraucht wird (z.B. für weitere
        // JSON-RPC-Aufrufe) – dann liefert sessionCookie() die JSESSIONID.
        'sitzung_offen_halten' => false,
    ],

    'session' => [
        'name'             => 'app_session', // Cookie-Name
        'lifetime_seconds' => 8 * 3600,      // 8 Stunden
    ],
];
