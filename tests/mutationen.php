<?php
/**
 * Gegenproben als Mutationsserie
 * ==============================
 * Ausführen: tests/alle.sh mutationen   (startet MariaDB, wenn vorhanden)
 *
 * Je Mutation:
 *   1. Vorprüfung: Der zu ersetzende Text steht genau einmal in der Datei,
 *      und jede erwartete Prüfung steht wörtlich und genau einmal in ihrer
 *      Suite (ihre Beschriftung wird aus der Suite gelesen, nicht abgetippt).
 *   2. Sicherungskopie, Eingriff, Prüfsumme muss sich ändern.
 *   3. Suite laufen lassen: Jede erwartete Zeile muss erscheinen.
 *   4. Rücknahme aus der Sicherungskopie, Prüfsumme muss wieder die alte sein.
 * Am Ende: Prüfsummen aller Dateien wie vorher, und jede Prüfung beider
 * Suiten ist von mindestens einer Mutation getroffen worden.
 *
 * Exit-Code 0 nur, wenn alles davon zutrifft.
 *
 * Copyright (C) 2026 Sebastian Horn, Friedrich-Rückert-Gymnasium Düsseldorf
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

$wurzel = dirname(__DIR__);
chdir($wurzel);

const BREMSE = 'src/Anmeldebremse.php';
const AUTH   = 'src/WebUntisAuth.php';
const SB     = 'tests/AnmeldebremseTest.php';
const SA     = 'tests/WebUntisAuthTest.php';

$mariadb = (string)getenv('ANMELDEBREMSE_MARIADB_DSN') !== '';

/**
 * [Datei, alt, neu, Suite, erwartet]. erwartet: Liste von [Zustand, Backend, Nummer]
 * – Zustand FAIL oder OK, Backend 'SQLite', 'MariaDB' oder '' (ohne Datenbank).
 */
$m = [];
$fail = fn(string $nr, string ...$backends) => array_map(fn($b) => ['FAIL', $b, $nr], $backends ?: ['']);
$beide = ['SQLite', 'MariaDB'];

// ── Anmeldebremse ────────────────────────────────────────────────────────
$m[] = [BREMSE, 'if ($seit < $this->wartezeit) {', 'if ($seit < 10) {', SB, $fail('B1', ...$beide)];
$m[] = [BREMSE, 'if ($seit < $this->wartezeit) {', 'if ($seit < $this->wartezeit - 1) {', SB, $fail('B2', ...$beide)];
$m[] = [BREMSE, 'if ($seit < $this->wartezeit) {', 'if ($seit <= $this->wartezeit) {', SB, $fail('B3', ...$beide)];
$m[] = [BREMSE, "if ((int)\$z['erfolg'] === 1) \$letzterErfolg", "if (false) \$letzterErfolg", SB, $fail('B4', ...$beide)];
$m[] = [BREMSE, "\$this->entfernen(\$id);\n                return ['ergebnis' => self::WARTEN",
                "return ['ergebnis' => self::WARTEN", SB, $fail('B5', ...$beide)];
$m[] = [BREMSE, "\$this->ausfuehren('DELETE FROM anmeldebremse WHERE id = ?', [\$versuch]);",
                "\$this->ausfuehren('SELECT ?', [\$versuch]);", SB, $fail('B6', ...$beide)];
$m[] = [BREMSE, "if ((int)\$z['id'] === \$id || (int)\$z['id'] <= \$letzterErfolg) continue;",
                "if ((int)\$z['id'] === \$id || (int)\$z['id'] <= \$letzterErfolg || (int)\$z['zeitpunkt'] === \$jetzt) continue;",
                SB, $fail('B7', ...$beide)];
$m[] = [BREMSE, "return ['ergebnis' => self::FEHLER, 'versuch' => null, 'sekunden' => null];",
                "return ['ergebnis' => self::ERLAUBT, 'versuch' => null, 'sekunden' => null];", SB, $fail('B8', ...$beide)];
$m[] = [BREMSE, "get_class(\$e) . ' ' . \$e->getCode() . ')');\n            return false;",
                "get_class(\$e) . ' ' . \$e->getCode() . ')');\n            return true;", SB, $fail('B9', ...$beide)];
$m[] = [BREMSE, 'if ($seit < $this->wartezeit) {', 'if ($seit < $this->wartezeit * 2) {', SB, $fail('B10', ...$beide)];
$m[] = ['sql/anmeldebremse.sqlite.sql', "erfolg    INTEGER NOT NULL DEFAULT 0\n);",
        "erfolg    INTEGER NOT NULL DEFAULT 0,\n    ip        TEXT    NULL\n);", SB,
        [['FAIL', 'SQLite', 'B11'], ['OK', 'MariaDB', 'B11']]];
$m[] = ['sql/anmeldebremse.mariadb.sql', "erfolg    TINYINT(1)      NOT NULL DEFAULT 0,",
        "erfolg    TINYINT(1)      NOT NULL DEFAULT 0,\n    ip        VARCHAR(45)     NULL,", SB,
        [['OK', 'SQLite', 'B11'], ['FAIL', 'MariaDB', 'B11']]];
// Der Grund dieses Laufs: sich auf die gemeldete Zahl verlassen. SQLite
// meldet getroffene Zeilen und bleibt grün – MariaDB meldet geänderte.
$m[] = [BREMSE, "\$this->ausfuehren('UPDATE anmeldebremse SET erfolg = 1 WHERE id = ?', [\$versuch]);\n                return \$this->erfolgDerZeile(\$versuch) === 1;",
                "return \$this->ausfuehren('UPDATE anmeldebremse SET erfolg = 1 WHERE id = ?', [\$versuch])->rowCount() === 1;",
                SB, [['OK', 'SQLite', 'B12'], ['FAIL', 'MariaDB', 'B12']]];
$m[] = [BREMSE, 'return $this->erfolgDerZeile($versuch) === null;', 'return $this->erfolgDerZeile($versuch) !== null;', SB, $fail('B13', ...$beide)];
$m[] = [BREMSE, 'AND ziel = ? AND zeitpunkt > ?', 'AND (ziel = ? OR 1 = 1) AND zeitpunkt > ?', SB, $fail('B14', ...$beide)];
$m[] = [BREMSE, 'WHERE kennung = ? AND ziel', 'WHERE (kennung = ? OR 1 = 1) AND ziel', SB, $fail('B15', ...$beide)];
$m[] = [BREMSE, '$kennung = self::vereinheitlichen($kennung);', '$kennung = $kennung;', SB, $fail('B16', ...$beide)];
$m[] = ['sql/anmeldebremse.sqlite.sql', 'CREATE TABLE IF NOT EXISTS anmeldebremse', 'CREATE TABLE anmeldebremse', SB,
        [['FAIL', 'SQLite', 'B17'], ['OK', 'MariaDB', 'B17']]];
$m[] = ['sql/anmeldebremse.mariadb.sql', 'CREATE TABLE IF NOT EXISTS anmeldebremse', 'CREATE TABLE anmeldebremse', SB,
        [['OK', 'SQLite', 'B17'], ['FAIL', 'MariaDB', 'B17']]];
$m[] = [BREMSE, '[$kennung, $ziel, $jetzt - $this->fenster]', '[$kennung, $ziel, $jetzt - $this->fenster - 100000]', SB, $fail('B18', ...$beide)];
$m[] = [BREMSE, '[$jetzt - $this->aufbewahrung]', '[$jetzt - $this->aufbewahrung - 1000000]', SB, $fail('B19', ...$beide)];
$m[] = [BREMSE, "public function beginnen(string \$kennung, string \$ziel = ''): array",
                "public function beginnen(string \$kennung, string \$ziel = '', string \$ip = ''): array", SB, $fail('B20')];
$m[] = [BREMSE, '$uhr ?? time(...);', '$uhr ?? fn() => 0;', SB, $fail('B21')];
$m[] = [BREMSE, 'if (!in_array($ausgang, [self::ERFOLG, self::FEHLSCHLAG, self::OHNE_ENTSCHEID], true)) {', 'if (false) {', SB, $fail('B22')];
$m[] = [BREMSE, '|| $e[$schluessel] < 1)', '|| $e[$schluessel] < 0)', SB, $fail('B23')];
$m[] = [BREMSE, "declare(strict_types=1);\n", "declare(strict_types=1);\nrequire_once __DIR__ . '/WebUntisAuth.php';\n", SB, $fail('B24')];
// Gegenprobe zur Gegenprobe: derselbe Name nur im Kommentar darf nicht anschlagen.
$m[] = [BREMSE, "declare(strict_types=1);\n", "declare(strict_types=1);\n// require_once WebUntisAuth.php – nur ein Kommentar\n", SB, [['OK', '', 'B24']]];

// ── WebUntisAuth ─────────────────────────────────────────────────────────
$m[] = [AUTH, 'if (!$this->sitzungOffenHalten) {', 'if (false) {', SA, $fail('1')];
$m[] = [AUTH, 'if (!$this->sitzungOffenHalten) {', 'if (true) {', SA, $fail('2')];
$m[] = [AUTH, "return \$this->sessionCookie !== '' ? \$this->sessionCookie : null;", 'return $this->sessionCookie;', SA, $fail('3')];
$m[] = [AUTH, "\$this->sessionCookie = '';\n            \$this->ausgang = self::AUSGANG_FEHLSCHLAG;",
              '$this->ausgang = self::AUSGANG_FEHLSCHLAG;', SA, $fail('4')];
$m[] = [AUTH, "\$this->ausgang = self::AUSGANG_ERFOLG;\n", "\$this->ausgang = self::AUSGANG_FEHLSCHLAG;\n", SA, $fail('A1')];
$m[] = [AUTH, "? self::AUSGANG_OHNE_ENTSCHEID\n                : self::AUSGANG_FEHLSCHLAG;",
              "? self::AUSGANG_OHNE_ENTSCHEID\n                : self::AUSGANG_OHNE_ENTSCHEID;", SA, $fail('A2')];
// Die falsche Fassung, die nahe liegt: nur bekannte Codes zählen.
$m[] = [AUTH, '$this->ausgang = ($authResponse === null && $this->transportfehler)',
              "\$this->ausgang = ((\$authResponse === null && \$this->transportfehler) || ((\$authResponse['error']['code'] ?? 0) !== -8504))",
              SA, [['OK', '', 'A2'], ['FAIL', '', 'A3']]];
$m[] = [AUTH, "{\$antwort['fehler']}\");\n            \$this->transportfehler = true;", "{\$antwort['fehler']}\");", SA, $fail('A4')];
$m[] = [AUTH, "if (\$raw === false || \$antwort['errno'] !== 0) {", "if (\$antwort['errno'] === 7) {", SA, [['OK', '', 'A4'], ['FAIL', '', 'A5']]];
$m[] = [AUTH, "Ungültige JSON-Antwort bei {\$method}\");\n            \$this->transportfehler = true;",
              "Ungültige JSON-Antwort bei {\$method}\");", SA, $fail('A6')];
$m[] = [AUTH, 'if ($httpCode >= 500 && $httpCode <= 599) {', 'if ($httpCode >= 501 && $httpCode <= 599) {', SA, $fail('A7')];
$m[] = [AUTH, 'if ($httpCode >= 500 && $httpCode <= 599) {', 'if ($httpCode >= 500 && $httpCode <= 598) {', SA, $fail('A8')];
$m[] = [AUTH, 'if ($httpCode >= 500 && $httpCode <= 599) {', 'if ($httpCode >= 499 && $httpCode <= 599) {', SA, $fail('A9')];
$m[] = [AUTH, "\$this->sessionCookie = '';\n            \$this->ausgang = self::AUSGANG_FEHLSCHLAG;",
              "\$this->sessionCookie = '';\n            \$this->ausgang = self::AUSGANG_OHNE_ENTSCHEID;", SA, $fail('A10')];
$m[] = [AUTH, 'private ?string $ausgang = null;', "private ?string \$ausgang = 'erfolg';", SA, $fail('A11')];
$m[] = [AUTH, "\$this->ausgang = self::AUSGANG_OHNE_ENTSCHEID;\n        \$username = trim(\$username);",
              "\$this->ausgang = self::AUSGANG_FEHLSCHLAG;\n        \$username = trim(\$username);", SA, $fail('A12')];
$m[] = [AUTH, "AUSGANG_OHNE_ENTSCHEID = 'ohne_entscheid';", "AUSGANG_OHNE_ENTSCHEID = 'ohne entscheid';", SA, $fail('A13')];
$m[] = ['config.example.php', "'timeout'         => 10, // Sekunden\n",
        "'timeout'         => 10, // Sekunden\n        'max_failed_logins' => 5,\n", SA, $fail('A14')];
$m[] = ['config.example.php', "'timeout'         => 10, // Sekunden\n",
        "'timeout'         => 10, // Sekunden\n        // früher: max_failed_logins – entfallen\n", SA, [['OK', '', 'A14']]];
$m[] = [AUTH, 'public function authenticate(string $username, string $password): ?array',
              "public function authenticate(string \$username, string \$password, string \$ip = ''): ?array", SA, $fail('A15')];
$m[] = [AUTH, "\$this->transportfehler = false;\n        \$baseUrl", '$baseUrl', SA, $fail('A16')];
$m[] = ['README.md', '## Bekannte Fallstricke', "## Bekannte Fallstricke\n\nOhne HTTPS setzt PHP keinen `Secure`-Cookie-Flag.", SA, $fail('7')];

// ── Lauf ─────────────────────────────────────────────────────────────────
/** Beschriftung einer Prüfung aus der Suite – muss genau einmal dastehen. */
function beschriftung(string $suite, string $nr): ?string
{
    preg_match_all("/'(" . preg_quote($nr, '/') . "\\) [^']*)'/", file_get_contents($suite), $t);
    return count($t[1]) === 1 ? $t[1][0] : null;
}

function pruefsummen(): array
{
    $s = [];
    foreach (['src/*.php', 'sql/*.sql', 'tests/*.php', 'config.example.php', 'README.md', 'examples/*.php'] as $g) {
        foreach (glob($g) as $d) $s[$d] = sha1_file($d);
    }
    return $s;
}

$vorher   = pruefsummen();
$fehler   = 0;
$getroffen = [];
foreach ($m as $i => [$datei, $alt, $neu, $suite, $erwartet]) {
    $nr = sprintf('M%02d', $i + 1);
    $text = file_get_contents($datei);
    $mal = substr_count($text, $alt);
    $zeilen = [];
    foreach ($erwartet as [$zustand, $backend, $pnr]) {
        $b = beschriftung($suite, $pnr);
        if ($b === null) { echo "  !! {$nr}: Prüfung {$pnr} steht nicht genau einmal in {$suite}\n"; $fehler++; continue 2; }
        if ($backend === 'MariaDB' && !$mariadb) { echo "  !! {$nr}: braucht MariaDB – NICHT GEFAHREN\n"; $fehler++; continue 2; }
        $zeilen[] = [$zustand, ($zustand === 'FAIL' ? '  FAIL ' : '  OK   ') . ($backend !== '' ? "[{$backend}] " : '') . $b, $suite . '#' . $pnr];
    }
    if ($mal !== 1) { echo "  !! {$nr}: Text steht {$mal}-mal in {$datei}, nicht genau einmal\n"; $fehler++; continue; }

    $sicherung = tempnam(sys_get_temp_dir(), 'mut');
    copy($datei, $sicherung);
    $soll = sha1_file($datei);
    file_put_contents($datei, str_replace($alt, $neu, $text));
    $ist = sha1_file($datei);
    $ausgabe = shell_exec('php ' . escapeshellarg($suite) . ' 2>/dev/null') ?? '';
    copy($sicherung, $datei);
    unlink($sicherung);
    $zurueck = sha1_file($datei);

    $fehlt = [];
    foreach ($zeilen as [$zustand, $zeile, $schluessel]) {
        // Am Zeilenanfang: Manche Beschriftungen werden zur Laufzeit ergänzt.
        if (!preg_match('/^' . preg_quote($zeile, '/') . '/m', $ausgabe)) $fehlt[] = trim($zeile);
        elseif ($zustand === 'FAIL') $getroffen[$schluessel] = true;
    }
    $wirkung = $ist !== $soll ? substr($soll, 0, 8) . '→' . substr($ist, 0, 8) : 'KEINE WIRKUNG';
    $rueck   = $zurueck === $soll ? 'zurück ' . substr($zurueck, 0, 8) : 'RÜCKNAHME FEHLGESCHLAGEN';
    $ok = $ist !== $soll && $zurueck === $soll && $fehlt === [];
    if (!$ok) $fehler++;
    $was = implode(', ', array_map(fn($z) => $z[0] . ' ' . explode('#', $z[2])[1], $zeilen));
    echo ($ok ? '  OK   ' : '  FAIL ') . "{$nr} {$datei}: erwartet {$was} | {$wirkung} | {$rueck}"
        . ($fehlt ? ' | fehlt: ' . implode(' / ', $fehlt) : '') . "\n";
}

// Jede Prüfung beider Suiten muss mindestens einmal rot geworden sein.
$ungetroffen = [];
foreach ([SB, SA] as $suite) {
    preg_match_all("/'((?:[AB]?\\d+))\\) [^']*'/", file_get_contents($suite), $t);
    foreach (array_unique($t[1]) as $pnr) {
        if (!isset($getroffen[$suite . '#' . $pnr])) $ungetroffen[] = basename($suite) . ':' . $pnr;
    }
}
$nachher = pruefsummen();
echo "\nMutationen: " . count($m) . ", Fehler: {$fehler}\n";
echo 'Ohne Gegenprobe: ' . ($ungetroffen ? implode(', ', $ungetroffen) : 'keine') . "\n";
echo 'Prüfsummen aller Dateien: ' . ($vorher === $nachher ? 'wie vorher (' . count($vorher) . ' Dateien)' : 'ABWEICHUNG') . "\n";
exit($fehler === 0 && $ungetroffen === [] && $vorher === $nachher ? 0 : 1);
