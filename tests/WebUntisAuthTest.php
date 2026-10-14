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

/** In-Memory-SQLite ganz ohne webuntis_login_log – bildet den ersten Deploy vor der Migration nach. */
function neueTestDbOhneTabelle(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

/**
 * Tabelle vorhanden – die Zählabfrage (SELECT) funktioniert – aber
 * schreibgeschützt: das Eintragen (INSERT) scheitert. Bildet nach, dass die
 * Zählabfrage und das Eintragen unabhängig voneinander scheitern können
 * (z.B. eine schreibgeschützte Replik).
 */
function neueSchreibgeschuetzteTestDb(): PDO
{
    $db = neueTestDb();
    $db->exec('PRAGMA query_only = ON');
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

$antwortenFalschesPasswort = [
    'authenticate' => ['error' => ['code' => -8991, 'message' => 'Wrong username or password.']],
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

// ── Prüfung 5: Zählabfrage wirft Fehler (Tabelle fehlt) ──────────────────────
// Der Fall aus der Wirklichkeit: erster Deploy vor migration.sql. Sowohl
// tooManyAttempts() als auch log() scheitern hier – gilt für authenticate(),
// nicht sperren wäre hier das gefährlichere Verhalten.
$db5  = neueTestDbOhneTabelle();
$wu5  = new JsonRpcTestDouble($db5, ['allowed_person_types' => [WebUntisAuth::TYPE_LEHRER]], $antwortenLehrer);
$res5 = $wu5->authenticate('ho', 'geheim', '127.0.0.1');
pruefe(
    '5) Zählabfrage scheitert (Tabelle fehlt): authenticate() weist ab, keine Profildaten',
    $res5 === null,
    'authenticate()=' . var_export($res5, true)
);

// ── Prüfung 6: log() meldet einen Fehlschlag zurück ──────────────────────────
// Tabelle vorhanden (Zählabfrage funktioniert), aber schreibgeschützt: das
// Eintragen scheitert unabhängig von der Zählabfrage. Fehlversuch → log()
// liefert false und authenticate() weist ab; Erfolg → log() liefert
// ebenfalls false, aber authenticate() weist NICHT ab (Protokoll, keine
// Voraussetzung).
// Seit PHP 8.1 kein setAccessible(true) mehr nötig, um eine private
// Methode über Reflection aufzurufen.
$logMethode = new ReflectionMethod(WebUntisAuth::class, 'log');

$dbLog            = neueSchreibgeschuetzteTestDb();
$wuLog            = new JsonRpcTestDouble($dbLog, [], []);
$logEintragScheitert = $logMethode->invoke($wuLog, 'ho', false, 'falsches_passwort_oder_netzwerk', '127.0.0.1');

$db6a = neueSchreibgeschuetzteTestDb();
$wu6a = new JsonRpcTestDouble($db6a, ['allowed_person_types' => [WebUntisAuth::TYPE_LEHRER]], $antwortenFalschesPasswort);
$res6a = $wu6a->authenticate('ho', 'falsch', '127.0.0.1');

$db6b = neueSchreibgeschuetzteTestDb();
$wu6b = new JsonRpcTestDouble($db6b, ['allowed_person_types' => [WebUntisAuth::TYPE_LEHRER]], $antwortenLehrer);
$res6b = $wu6b->authenticate('ho', 'geheim', '127.0.0.1');

pruefe(
    '6) log() scheitert (Tabelle schreibgeschützt): Fehlversuch weist ab, Erfolg nicht',
    $logEintragScheitert === false && $res6a === null && $res6b !== null,
    'log()=' . var_export($logEintragScheitert, true)
        . ' res6a=' . var_export($res6a, true)
        . ' res6b=' . var_export($res6b, true)
);

// ── 7) Der falsche Satz zum Secure-Flag steht nicht mehr da ─────────────
// Bis 1.2.0 hieß es, $_SERVER['HTTPS'] = 'on' sorge für das Secure-Flag am
// Sitzungscookie. Das stimmt nicht: Die Flags setzt allein
// session_set_cookie_params() in WebUntisSession::start(). Gesucht werden
// die bekannten falschen Fassungen, im ganzen Baum (git ls-files), ohne:
//   - CHANGELOG.md, der sie zitiert,
//   - FALLSTRICKE.md / REIHENREGELN.md, vendorte Kopien aus koordination
//     (Berichtigung dort, Verteilung ist ein eigener Lauf),
//   - diese Datei, die die Suchmuster trägt.
$falscheFassungen = [
    'php setzt secure-cookie-flag',
    'und der secure-flag fehlt',
    'setzt php keinen secure-cookie-flag',
    'funktioniert mit prepare() oben',
    'php denkt sonst die verbindung sei unsicher',
];
$ausgenommen = ['CHANGELOG.md', 'FALLSTRICKE.md', 'REIHENREGELN.md', 'tests/' . basename(__FILE__)];
$wurzel      = dirname(__DIR__);
$liste       = [];
$rc          = 0;
exec('git -C ' . escapeshellarg($wurzel) . ' ls-files', $liste, $rc);
$durchsucht = [];
$funde      = [];
foreach ($liste as $datei) {
    if ($rc !== 0 || in_array($datei, $ausgenommen, true)) continue;
    if (!preg_match('/\.(php|md)$/', $datei)) continue;
    $text = file_get_contents($wurzel . '/' . $datei);
    if ($text === false) continue;
    // Kommentarzeichen und Auszeichnung entfernen, Leerraum zusammenziehen:
    // Ein Satz über zwei Kommentarzeilen soll wie einer gelesen werden.
    $text = preg_replace('~^\s*(\*|//)\s?~m', '', $text);
    $text = str_replace(['`', '→'], ['', ' '], $text);
    $text = strtolower(preg_replace('/\s+/', ' ', $text));
    $durchsucht[] = $datei;
    foreach ($falscheFassungen as $fassung) {
        if (str_contains($text, $fassung)) $funde[] = "{$datei}: „{$fassung}“";
    }
}
// Ohne Voraussetzung kein Bestehen: Die Suche muss die Datei sehen, in der
// der Satz stand.
$voraussetzung = $rc === 0 && in_array('src/WebUntisSession.php', $durchsucht, true);
pruefe(
    '7) kein falscher Satz „HTTPS sorgt für Secure-Flag“ (' . count($durchsucht) . ' Dateien durchsucht)',
    $voraussetzung && $funde === [],
    $voraussetzung ? implode('; ', $funde) : 'git ls-files gescheitert oder src/WebUntisSession.php nicht durchsucht'
);

echo "\nPrüfungen: {$gesamt}, bestanden: {$bestanden}\n";

exit($bestanden === $gesamt ? 0 : 1);
