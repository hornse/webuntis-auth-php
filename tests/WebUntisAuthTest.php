<?php
/**
 * Prüfungen für WebUntisAuth::sessionCookie() / 'sitzung_offen_halten'
 * =====================================================================
 * Ohne Netzzugriff: ein Test-Doppel überschreibt das jetzt protected
 * jsonRpc() und liefert nachgebaute Antworten. Ausführen:
 *
 *   php tests/WebUntisAuthTest.php
 *
 * Exit-Code 0 bei vollem Erfolg, sonst 1. Bei Rot melden, nicht mit
 * `|| true` überdecken.
 *
 * Copyright (C) 2026 Sebastian Horn, Friedrich-Rückert-Gymnasium Düsseldorf
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/WebUntisAuth.php';

/**
 * Überschreibt jsonRpc() mit nachgebauten Antworten statt cURL.
 *
 * Setzt bei saveCookie=true und gültiger Antwort das private
 * $sessionCookie der Basisklasse per Reflection — genau das, was das
 * echte jsonRpc() aus dem Set-Cookie-Header tut. Nur so lässt sich
 * sessionCookie() prüfen, ohne die Sichtbarkeit der Eigenschaft
 * anzutasten (Auftrag ändert nur die Methode).
 */
class JsonRpcTestDouble extends WebUntisAuth
{
    /** @param array<string, array|null> $antworten Methode => kanonische Antwort */
    public function __construct(PDO $db, array $config, private readonly array $antworten)
    {
        parent::__construct($db, $config);
    }

    protected function jsonRpc(string $method, array $params, bool $saveCookie = false): ?array
    {
        $antwort = $this->antworten[$method] ?? null;

        if ($saveCookie && $antwort !== null && !isset($antwort['error'])) {
            $prop = new ReflectionProperty(WebUntisAuth::class, 'sessionCookie');
            $prop->setValue($this, 'JSESSIONID=test-' . bin2hex(random_bytes(4)));
        }

        return $antwort;
    }
}

/** In-Memory-SQLite mit der Login-Log-Tabelle aus migration.sql (SQLite-Variante). */
function neueTestDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec(
        'CREATE TABLE webuntis_login_log (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            benutzername TEXT    NOT NULL,
            erfolgreich  INTEGER NOT NULL DEFAULT 0,
            grund        TEXT    NULL,
            ip           TEXT    NULL,
            zeitpunkt    TEXT    NOT NULL DEFAULT (datetime(\'now\'))
        )'
    );
    return $db;
}

/** @return array{result: array} Nachgebaute WebUntis-Antwort auf 'authenticate'. */
function antwortLehrer(): array
{
    return ['result' => ['personType' => WebUntisAuth::TYPE_LEHRER, 'personId' => 1013]];
}

/** @return array{result: array} Nachgebaute WebUntis-Antwort auf 'authenticate' – Schüler. */
function antwortSchueler(): array
{
    return ['result' => ['personType' => WebUntisAuth::TYPE_SCHUELER, 'personId' => 34397]];
}

$antwortenLehrer = [
    'authenticate' => antwortLehrer(),
    'getTeachers'  => ['result' => [['id' => 1013, 'name' => 'Ho', 'foreName' => 'Sebastian', 'longName' => 'Horn']]],
    'logout'       => ['result' => null],
];

$antwortenSchueler = [
    'authenticate' => antwortSchueler(),
    'logout'       => ['result' => null],
];

$bestanden = 0;
$gesamt    = 0;

/**
 * @param bool $bedingung
 */
function pruefe(string $name, bool $bedingung, string $detail = ''): void
{
    global $bestanden, $gesamt;
    $gesamt++;
    if ($bedingung) {
        $bestanden++;
        echo "  OK   {$name}\n";
    } else {
        echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

// ── Prüfung 1: Ohne Schalter liefert sessionCookie() nach Erfolg null ───────
$db1  = neueTestDb();
$wu1  = new JsonRpcTestDouble($db1, ['allowed_person_types' => [WebUntisAuth::TYPE_LEHRER]], $antwortenLehrer);
$res1 = $wu1->authenticate('ho', 'geheim', '127.0.0.1');
pruefe(
    '1) ohne Schalter: authenticate() erfolgreich, sessionCookie() liefert null',
    $res1 !== null && $wu1->sessionCookie() === null,
    'sessionCookie()=' . var_export($wu1->sessionCookie(), true)
);

// ── Prüfung 2: Mit Schalter liefert sessionCookie() eine JSESSIONID ─────────
$db2 = neueTestDb();
$wu2 = new JsonRpcTestDouble(
    $db2,
    ['allowed_person_types' => [WebUntisAuth::TYPE_LEHRER], 'sitzung_offen_halten' => true],
    $antwortenLehrer
);
$res2 = $wu2->authenticate('ho', 'geheim', '127.0.0.1');
pruefe(
    '2) mit Schalter: sessionCookie() beginnt mit JSESSIONID=',
    $res2 !== null && is_string($wu2->sessionCookie()) && str_starts_with($wu2->sessionCookie(), 'JSESSIONID='),
    'sessionCookie()=' . var_export($wu2->sessionCookie(), true)
);

// ── Prüfung 3: Ohne vorheriges authenticate() liefert sessionCookie() null ──
$db3 = neueTestDb();
$wu3 = new JsonRpcTestDouble($db3, ['sitzung_offen_halten' => true], []);
pruefe(
    '3) ohne authenticate(): sessionCookie() liefert null, nicht Leerstring',
    $wu3->sessionCookie() === null,
    'sessionCookie()=' . var_export($wu3->sessionCookie(), true)
);

// ── Prüfung 4: Mit Schalter, aber unerlaubte Rolle → sessionCookie() null ───
$db4 = neueTestDb();
$wu4 = new JsonRpcTestDouble(
    $db4,
    ['allowed_person_types' => [WebUntisAuth::TYPE_LEHRER], 'sitzung_offen_halten' => true],
    $antwortenSchueler
);
$res4 = $wu4->authenticate('schueler', 'geheim', '127.0.0.1');
pruefe(
    '4) mit Schalter, unerlaubte Rolle: authenticate() null, sessionCookie() null',
    $res4 === null && $wu4->sessionCookie() === null,
    'authenticate()=' . var_export($res4, true) . ' sessionCookie()=' . var_export($wu4->sessionCookie(), true)
);

echo "\nPrüfungen: {$gesamt}, bestanden: {$bestanden}\n";

exit($bestanden === $gesamt ? 0 : 1);
