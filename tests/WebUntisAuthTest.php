<?php
/**
 * Prüfungen für WebUntisAuth
 * ==========================
 * Ohne Netzzugriff: Zwei Test-Doppel liefern nachgebaute Antworten –
 * JsonRpcTestDouble überschreibt jsonRpc(), HttpTestDouble eine Ebene
 * tiefer http(), damit die Auswertung von Transportfehlern in jsonRpc()
 * selbst geprüft wird. Ausführen:
 *
 *   php tests/WebUntisAuthTest.php       (alle Suiten: tests/alle.sh)
 *
 * Die Prüfungen 5 und 6 galten der Sperre, die mit 2.0.0 entfallen ist.
 * Ihre Nummern bleiben unbesetzt.
 *
 * Exit-Code 0 bei vollem Erfolg, sonst 1. Bei Rot melden, nicht mit
 * `|| true` überdecken.
 *
 * Copyright (C) 2026 Sebastian Horn, Friedrich-Rückert-Gymnasium Düsseldorf
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/WebUntisAuth.php';
require_once __DIR__ . '/../src/Anmeldebremse.php'; // nur für A13: dieselben Namen

/**
 * Überschreibt jsonRpc() mit nachgebauten Antworten statt cURL.
 *
 * Setzt bei saveCookie=true und gültiger Antwort das private
 * $sessionCookie der Basisklasse per Reflection — genau das, was das
 * echte jsonRpc() aus dem Set-Cookie-Header tut.
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

/**
 * Überschreibt nur den HTTP-Austausch. jsonRpc() läuft echt und wertet aus,
 * was hier zurückkommt. Nicht hinterlegte Methoden: nicht erreichbar.
 */
class HttpTestDouble extends WebUntisAuth
{
    public int $aufrufe = 0;

    /** @param array<string, array> $http Methode => Ergebnis wie http() */
    public function __construct(PDO $db, array $config, public array $http)
    {
        parent::__construct($db, $config + ['base_url' => 'https://beispiel.invalid', 'school' => 'probe']);
    }

    protected function http(string $url, string $body, array $headers, bool $mitKopf): array
    {
        $this->aufrufe++;
        $methode = json_decode($body, true)['method'] ?? '';
        return $this->http[$methode] ?? netz(7);
    }
}

function json200(array $antwort): array
{
    return ['raw' => json_encode($antwort), 'status' => 200, 'kopfgroesse' => 0, 'errno' => 0, 'fehler' => ''];
}

function netz(int $errno): array
{
    return ['raw' => false, 'status' => 0, 'kopfgroesse' => 0, 'errno' => $errno, 'fehler' => "curl {$errno}"];
}

function status(int $status, string $raw = ''): array
{
    return ['raw' => $raw, 'status' => $status, 'kopfgroesse' => 0, 'errno' => 0, 'fehler' => ''];
}

/** In-Memory-SQLite. WebUntisAuth braucht sie nur noch für lookupNameFromDb(). */
function neueTestDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

$httpLehrer = array_map('json200', $antwortenLehrer);
$nurLehrer  = ['allowed_person_types' => [WebUntisAuth::TYPE_LEHRER]];

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

/** authenticate() mit einer Antwort auf 'authenticate'; liefert [Ergebnis, ausgang()]. */
function anmeldung(array $antwortAufAuthenticate, array $config = []): array
{
    global $httpLehrer, $nurLehrer;
    $wu = new HttpTestDouble(neueTestDb(), $config + $nurLehrer,
        ['authenticate' => $antwortAufAuthenticate] + $httpLehrer);
    $res = $wu->authenticate('ho', 'geheim');
    return [$res, $wu->ausgang()];
}

// ── Prüfung 1: Ohne Schalter liefert sessionCookie() nach Erfolg null ───────
$db1  = neueTestDb();
$wu1  = new JsonRpcTestDouble($db1, $nurLehrer, $antwortenLehrer);
$res1 = $wu1->authenticate('ho', 'geheim');
pruefe(
    '1) ohne Schalter: authenticate() erfolgreich, sessionCookie() liefert null',
    $res1 !== null && $wu1->sessionCookie() === null,
    'sessionCookie()=' . var_export($wu1->sessionCookie(), true)
);

// ── Prüfung 2: Mit Schalter liefert sessionCookie() eine JSESSIONID ─────────
$db2 = neueTestDb();
$wu2 = new JsonRpcTestDouble($db2, $nurLehrer + ['sitzung_offen_halten' => true], $antwortenLehrer);
$res2 = $wu2->authenticate('ho', 'geheim');
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
$wu4 = new JsonRpcTestDouble($db4, $nurLehrer + ['sitzung_offen_halten' => true], $antwortenSchueler);
$res4 = $wu4->authenticate('schueler', 'geheim');
pruefe(
    '4) mit Schalter, unerlaubte Rolle: authenticate() null, sessionCookie() null',
    $res4 === null && $wu4->sessionCookie() === null,
    'authenticate()=' . var_export($res4, true) . ' sessionCookie()=' . var_export($wu4->sessionCookie(), true)
);

// ── A) ausgang(): Hat WebUntis über das Passwort entschieden? ──────────────
// Ohne Entscheid nur bei Transportfehlern. Jede Antwort mit einem
// Fehlercode ist ein Fehlschlag, auch ein unbekannter – im Zweifel zählt
// der Versuch.
[$res, $aus] = anmeldung(json200(antwortLehrer()));
pruefe('A1) Erfolg: ausgang() erfolg', $res !== null && $aus === 'erfolg', var_export($aus, true));

[$res, $aus] = anmeldung(json200(['error' => ['code' => -8504, 'message' => 'bad credentials']]));
pruefe('A2) Fehlercode -8504: fehlschlag', $res === null && $aus === 'fehlschlag', var_export($aus, true));

[$res, $aus] = anmeldung(json200(['error' => ['code' => -1234567, 'message' => 'erfunden']]));
pruefe('A3) erfundener Fehlercode: fehlschlag', $res === null && $aus === 'fehlschlag', var_export($aus, true));

[$res, $aus] = anmeldung(netz(7));
pruefe('A4) nicht erreichbar (curl 7): ohne_entscheid', $res === null && $aus === 'ohne_entscheid', var_export($aus, true));

[$res, $aus] = anmeldung(netz(28));
pruefe('A5) Zeitüberschreitung (curl 28): ohne_entscheid', $res === null && $aus === 'ohne_entscheid', var_export($aus, true));

[$res, $aus] = anmeldung(status(200, '<html><body>Wartung</body></html>'));
pruefe('A6) keine JSON-Antwort: ohne_entscheid', $res === null && $aus === 'ohne_entscheid', var_export($aus, true));

[$res, $aus] = anmeldung(status(500));
pruefe('A7) HTTP 500: ohne_entscheid', $res === null && $aus === 'ohne_entscheid', var_export($aus, true));

[$res, $aus] = anmeldung(status(599));
pruefe('A8) HTTP 599: ohne_entscheid', $res === null && $aus === 'ohne_entscheid', var_export($aus, true));

[$res, $aus] = anmeldung(status(499));
pruefe('A9) HTTP 499: fehlschlag', $res === null && $aus === 'fehlschlag', var_export($aus, true));

[$res, $aus] = anmeldung(json200(antwortSchueler()));
pruefe('A10) unerlaubte Rolle: fehlschlag', $res === null && $aus === 'fehlschlag', var_export($aus, true));

$wu = new HttpTestDouble(neueTestDb(), $nurLehrer, $httpLehrer);
pruefe('A11) vor authenticate(): ausgang() null', $wu->ausgang() === null, var_export($wu->ausgang(), true));

$wu = new HttpTestDouble(neueTestDb(), $nurLehrer, $httpLehrer);
$res = $wu->authenticate('ho', '');
pruefe('A12) leeres Passwort: ohne_entscheid, WebUntis nicht gefragt',
    $res === null && $wu->ausgang() === 'ohne_entscheid' && $wu->aufrufe === 0,
    var_export($wu->ausgang(), true) . " aufrufe={$wu->aufrufe}");

$paare = [
    [WebUntisAuth::AUSGANG_ERFOLG, Anmeldebremse::ERFOLG],
    [WebUntisAuth::AUSGANG_FEHLSCHLAG, Anmeldebremse::FEHLSCHLAG],
    [WebUntisAuth::AUSGANG_OHNE_ENTSCHEID, Anmeldebremse::OHNE_ENTSCHEID],
];
pruefe('A13) ausgang() spricht die Namen von Anmeldebremse::abschliessen()',
    array_filter($paare, fn($p) => $p[0] !== $p[1]) === [], json_encode($paare));

// Die alte Sperre ist fort – im Code (Tokenizer: Bezeichner und
// Zeichenketten, nicht Kommentare) und in der README. Der Changelog nennt
// die Namen, die Testdatei auch; beide werden nicht durchsucht.
$alt    = ['max_failed_logins', 'lockout_minutes', 'webuntis_login_log', 'tooManyAttempts', 'isLocked'];
$wurzel = dirname(__DIR__);
$funde  = [];
$php    = array_merge(glob($wurzel . '/src/*.php'), glob($wurzel . '/examples/*.php'), [$wurzel . '/config.example.php']);
foreach ($php as $datei) {
    foreach (token_get_all(file_get_contents($datei)) as $t) {
        if (!is_array($t) || !in_array($t[0], [T_STRING, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) continue;
        foreach ($alt as $name) if (str_contains($t[1], $name)) $funde[] = basename($datei) . ":{$name}";
    }
}
foreach ($alt as $name) if (str_contains(file_get_contents($wurzel . '/README.md'), $name)) $funde[] = "README.md:{$name}";
if (file_exists($wurzel . '/migration.sql')) $funde[] = 'migration.sql vorhanden';
pruefe('A14) die alte Sperre ist fort (' . count($php) . ' PHP-Dateien)', count($php) >= 3 && $funde === [], implode(', ', $funde));

$param = array_map(fn($p) => $p->getName(), (new ReflectionMethod(WebUntisAuth::class, 'authenticate'))->getParameters());
pruefe('A15) authenticate() nimmt Benutzer und Passwort, keine Adresse', $param === ['username', 'password'], implode(',', $param));

// Der Transportfehler des ersten Aufrufs darf am zweiten nicht hängen.
$wu = new HttpTestDouble(neueTestDb(), $nurLehrer, ['authenticate' => netz(7)] + $httpLehrer);
$wu->authenticate('ho', 'geheim');
$erst = $wu->ausgang();
$wu->http['authenticate'] = status(499);
$wu->authenticate('ho', 'geheim');
pruefe('A16) zweiter Aufruf erbt keinen Transportfehler des ersten', $erst === 'ohne_entscheid' && $wu->ausgang() === 'fehlschlag',
    var_export([$erst, $wu->ausgang()], true));

// ── 7) Der falsche Satz zum Secure-Flag steht nicht mehr da ─────────────
// Bis 1.2.0 hieß es, $_SERVER['HTTPS'] = 'on' sorge für das Secure-Flag am
// Sitzungscookie. Das stimmt nicht: Die Flags setzt allein
// session_set_cookie_params() in WebUntisSession::start(). Gesucht werden
// die bekannten falschen Fassungen, im ganzen Baum (git ls-files), ohne:
//   - CHANGELOG.md, der sie zitiert,
//   - FALLSTRICKE.md / REIHENREGELN.md, vendorte Kopien aus koordination
//     (Berichtigung dort, Verteilung ist ein eigener Lauf),
//   - diese Datei, die die Suchmuster trägt,
//   - tests/mutationen.php, das einen der Sätze als Gegenprobe zu dieser
//     Prüfung einsetzt (bis 2.0.0 nicht ausgenommen: v2.0.0 ist rot).
$falscheFassungen = [
    'php setzt secure-cookie-flag',
    'und der secure-flag fehlt',
    'setzt php keinen secure-cookie-flag',
    'funktioniert mit prepare() oben',
    'php denkt sonst die verbindung sei unsicher',
];
$ausgenommen = ['CHANGELOG.md', 'FALLSTRICKE.md', 'REIHENREGELN.md', 'tests/' . basename(__FILE__), 'tests/mutationen.php'];
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
