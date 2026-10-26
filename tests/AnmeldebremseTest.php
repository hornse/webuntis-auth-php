<?php
/**
 * Prüfungen für Anmeldebremse
 * ===========================
 * Ausführen:
 *
 *   php tests/AnmeldebremseTest.php      – gegen SQLite
 *   tests/alle.sh                        – alle Suiten, MariaDB dazu, wenn
 *                                          ein mariadbd gefunden wird
 *
 * Gegen MariaDB laufen die datenbankgebundenen Prüfungen ein zweites Mal,
 * wenn ANMELDEBREMSE_MARIADB_DSN gesetzt ist (Benutzer und Passwort aus
 * ANMELDEBREMSE_MARIADB_USER / _PASS). Sonst steht eine Zeile
 * ÜBERSPRUNGEN in der Ausgabe – nie stilles Fehlen.
 *
 * Die Uhr beginnt nicht bei 0 (koordination B72): Eine Prüfung, deren Uhr
 * bei 0 steht, sieht Fehler nicht, die eine Null als „kein Wert“ lesen.
 *
 * Exit-Code 0 bei vollem Erfolg, sonst 1.
 *
 * Copyright (C) 2026 Sebastian Horn, Friedrich-Rückert-Gymnasium Düsseldorf
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

// Nur diese eine Datei: Die Suite belegt dadurch mit, dass die Klasse ohne
// den Rest des Moduls läuft.
require_once __DIR__ . '/../src/Anmeldebremse.php';

const T0         = 1_760_000_000;
const SQL_SQLITE = __DIR__ . '/../sql/anmeldebremse.sqlite.sql';
const SQL_MARIA  = __DIR__ . '/../sql/anmeldebremse.mariadb.sql';

final class Uhr
{
    public int $jetzt = T0;
    public function __invoke(): int { return $this->jetzt; }
}

$bestanden = 0;
$gesamt    = 0;

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

/**
 * Ein Versuch zur Zeit T0 + $t. Ergebnis als Kürzel: E (erlaubt),
 * W<sekunden> (warten), F (Fehler). Ist er erlaubt und $ausgang gesetzt,
 * wird er so abgeschlossen; $ausgang null lässt ihn laufen.
 */
function versuch(Anmeldebremse $b, Uhr $uhr, int $t, string $kennung, ?string $ausgang, string $ziel = ''): string
{
    $uhr->jetzt = T0 + $t;
    $r = $b->beginnen($kennung, $ziel);
    if ($r['ergebnis'] === Anmeldebremse::ERLAUBT) {
        if ($ausgang !== null) $b->abschliessen($r['versuch'], $ausgang);
        return 'E';
    }
    if ($r['ergebnis'] === Anmeldebremse::WARTEN) return 'W' . $r['sekunden'];
    return 'F';
}

/** Mehrere Versuche, Ergebnisse durch Leerzeichen getrennt. */
function folge(Anmeldebremse $b, Uhr $uhr, array $zeiten, string $kennung, ?string $ausgang, string $ziel = ''): string
{
    return implode(' ', array_map(fn(int $t) => versuch($b, $uhr, $t, $kennung, $ausgang, $ziel), $zeiten));
}

function zeilen(PDO $db): int
{
    return (int)$db->query('SELECT COUNT(*) FROM anmeldebremse')->fetchColumn();
}

// ── Datenbanken ──────────────────────────────────────────────────────────
$backends = [
    'SQLite' => function (): PDO {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(file_get_contents(SQL_SQLITE));
        return $db;
    },
];
$dsn = getenv('ANMELDEBREMSE_MARIADB_DSN');
if ($dsn !== false && $dsn !== '') {
    $backends['MariaDB'] = function () use ($dsn): PDO {
        $db = new PDO($dsn, (string)getenv('ANMELDEBREMSE_MARIADB_USER'), (string)getenv('ANMELDEBREMSE_MARIADB_PASS'));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('DROP TABLE IF EXISTS anmeldebremse');
        $db->exec(file_get_contents(SQL_MARIA));
        return $db;
    };
} else {
    echo "  ÜBERSPRUNGEN MariaDB: ANMELDEBREMSE_MARIADB_DSN nicht gesetzt\n";
}

foreach ($backends as $name => $neueDb) {
    $p = "[{$name}] ";
    if ($name === 'MariaDB') {
        echo '  -- MariaDB ' . $neueDb()->query('SELECT VERSION()')->fetchColumn() . "\n";
    }

    // Im Tempo einer Person: alle 15 s ein Fehlversuch.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    $ist = folge($b, $u, range(0, 180, 15), 'ho', Anmeldebremse::FEHLSCHLAG);
    pruefe($p . 'B1) Tempo einer Person: acht durch, dann warten', $ist === 'E E E E E E E E W45 W30 W15 E W45', $ist);

    // Genau auf der Grenze: acht Fehlversuche bis t=7, dann t=66 und t=67.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    folge($b, $u, range(0, 7), 'ho', Anmeldebremse::FEHLSCHLAG);
    $ist = versuch($b, $u, 66, 'ho', Anmeldebremse::FEHLSCHLAG);
    pruefe($p . 'B2) nach 59 s abgewiesen', $ist === 'W1', $ist);
    $ist = versuch($b, $u, 67, 'ho', Anmeldebremse::FEHLSCHLAG);
    pruefe($p . 'B3) nach genau 60 s durch', $ist === 'E', $ist);

    // Rücksetzen: nach dem Erfolg wieder acht Versuche im Sekundentakt.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    folge($b, $u, range(0, 7), 'ho', Anmeldebremse::FEHLSCHLAG);
    $ist = versuch($b, $u, 67, 'ho', Anmeldebremse::ERFOLG) . ' | '
         . folge($b, $u, range(68, 76), 'ho', Anmeldebremse::FEHLSCHLAG);
    pruefe($p . 'B4) Erfolg setzt zurück', $ist === 'E | E E E E E E E E W59', $ist);

    // Abgewiesene zählen nicht: Klicks in der Wartezeit verlängern sie nicht.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    folge($b, $u, range(0, 7), 'ho', Anmeldebremse::FEHLSCHLAG);
    $ist = folge($b, $u, [10, 20, 30, 40, 50], 'ho', Anmeldebremse::FEHLSCHLAG) . ' | ' . zeilen($db)
         . ' | ' . versuch($b, $u, 67, 'ho', Anmeldebremse::FEHLSCHLAG);
    pruefe($p . 'B5) abgewiesene zählen nicht', $ist === 'W57 W47 W37 W27 W17 | 8 | E', $ist);

    // Ohne Entscheid zählt nicht, und die Zeile ist fort.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    $ist = folge($b, $u, range(0, 11), 'ho', Anmeldebremse::OHNE_ENTSCHEID) . ' | ' . zeilen($db);
    pruefe($p . 'B6) ohne Entscheid zählt nicht', $ist === 'E E E E E E E E E E E E | 0', $ist);

    // Laufende Versuche zählen: acht begonnen, keiner abgeschlossen.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    $ist = folge($b, $u, array_fill(0, 9, 0), 'ho', null);
    pruefe($p . 'B7) laufende Versuche zählen mit', $ist === 'E E E E E E E E W60', $ist);

    // Datenbankfehler: abgewiesen, unterscheidbar von warten und erlaubt.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    $db->exec('DROP TABLE anmeldebremse');
    $r = $b->beginnen('ho', '');
    pruefe($p . 'B8) Datenbankfehler weist ab, eigenes Ergebnis',
        $r['ergebnis'] === Anmeldebremse::FEHLER && $r['versuch'] === null && $r['sekunden'] === null
            && Anmeldebremse::FEHLER !== Anmeldebremse::WARTEN && Anmeldebremse::FEHLER !== Anmeldebremse::ERLAUBT,
        var_export($r, true));

    // Erfolg nicht vermerkbar: abschliessen() sagt es.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    $r = $b->beginnen('ho', '');
    $db->exec('DROP TABLE anmeldebremse');
    $ok = $b->abschliessen($r['versuch'], Anmeldebremse::ERFOLG);
    pruefe($p . 'B9) Erfolg nicht vermerkbar: abschliessen() liefert false', $ok === false, var_export($ok, true));

    // Die bekannte Grenze: Ein Skript schlägt einmal je Minute fehl.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    $ist = folge($b, $u, range(0, 600, 60), 'ho', Anmeldebremse::FEHLSCHLAG)
         . ' | ' . versuch($b, $u, 610, 'ho', Anmeldebremse::ERFOLG);
    pruefe($p . 'B10) bekannte Grenze: Skript je Minute hält den Inhaber in der Wartezeit',
        $ist === 'E E E E E E E E E E E | W50', $ist);

    // Keine Adresse: die Spalten der Tabelle, vollständig.
    $db = $neueDb();
    $st = $db->query('SELECT * FROM anmeldebremse LIMIT 0');
    $spalten = [];
    for ($i = 0; $i < $st->columnCount(); $i++) $spalten[] = $st->getColumnMeta($i)['name'];
    pruefe($p . 'B11) Tabelle: genau id, kennung, ziel, zeitpunkt, erfolg',
        $spalten === ['id', 'kennung', 'ziel', 'zeitpunkt', 'erfolg'], implode(',', $spalten));

    // Doppelt abgeschlossen: nachgelesen, nicht gezählt. MariaDB meldet beim
    // zweiten UPDATE 0 geänderte Zeilen, SQLite 1 getroffene.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    $r = $b->beginnen('ho', '');
    $ok1 = $b->abschliessen($r['versuch'], Anmeldebremse::ERFOLG);
    $ok2 = $b->abschliessen($r['versuch'], Anmeldebremse::ERFOLG);
    pruefe($p . 'B12) Erfolg zweimal abgeschlossen: beide Male true', $ok1 === true && $ok2 === true,
        var_export([$ok1, $ok2], true));

    // Ohne Entscheid: nachgelesen, die Zeile ist fort.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    $r = $b->beginnen('ho', '');
    $ok = $b->abschliessen($r['versuch'], Anmeldebremse::OHNE_ENTSCHEID);
    pruefe($p . 'B13) ohne Entscheid: true und keine Zeile', $ok === true && zeilen($db) === 0,
        var_export($ok, true) . ' zeilen=' . zeilen($db));

    // Das Ziel trennt.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    folge($b, $u, range(0, 7), 'ho', Anmeldebremse::FEHLSCHLAG, 'spielwiese');
    $ist = versuch($b, $u, 8, 'ho', Anmeldebremse::FEHLSCHLAG, 'anwendung')
         . ' ' . versuch($b, $u, 9, 'ho', Anmeldebremse::FEHLSCHLAG, 'spielwiese');
    pruefe($p . 'B14) Ziel trennt die Zählung', $ist === 'E W58', $ist);

    // Die Kennung trennt.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    folge($b, $u, range(0, 7), 'ho', Anmeldebremse::FEHLSCHLAG);
    $ist = versuch($b, $u, 8, 'mu', Anmeldebremse::FEHLSCHLAG) . ' ' . versuch($b, $u, 9, 'ho', Anmeldebremse::FEHLSCHLAG);
    pruefe($p . 'B15) Kennung trennt die Zählung', $ist === 'E W58', $ist);

    // Groß- und Kleinschreibung zählen zusammen, Leerraum außen auch.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    folge($b, $u, range(0, 3), 'Ho', Anmeldebremse::FEHLSCHLAG);
    folge($b, $u, range(4, 7), ' hO ', Anmeldebremse::FEHLSCHLAG);
    $ist = versuch($b, $u, 8, 'HO', Anmeldebremse::FEHLSCHLAG);
    pruefe($p . 'B16) Schreibweise der Kennung zählt zusammen', $ist === 'W59', $ist);

    // Das SQL zweimal einspielen.
    $db = $neueDb();
    $fehler = '';
    try {
        $db->exec(file_get_contents($name === 'SQLite' ? SQL_SQLITE : SQL_MARIA));
    } catch (PDOException $e) {
        $fehler = $e->getMessage();
    }
    pruefe($p . 'B17) SQL zweimal eingespielt ohne Fehler', $fehler === '', $fehler);

    // Das Zählfenster: Fehlversuche, die älter sind, zählen nicht.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    folge($b, $u, range(0, 7), 'ho', Anmeldebremse::FEHLSCHLAG);
    $ist = folge($b, $u, range(1000, 1008), 'ho', Anmeldebremse::FEHLSCHLAG);
    pruefe($p . 'B18) Fehlversuche außerhalb des Zählfensters zählen nicht', $ist === 'E E E E E E E E W59', $ist);

    // Aufbewahrung: alte Zeilen gehen beim nächsten Versuch.
    $db = $neueDb(); $u = new Uhr(); $b = new Anmeldebremse($db, [], $u(...));
    versuch($b, $u, 0, 'ho', Anmeldebremse::FEHLSCHLAG);
    versuch($b, $u, 7 * 86400 + 1, 'mu', Anmeldebremse::FEHLSCHLAG);
    $alt = (int)$db->query('SELECT COUNT(*) FROM anmeldebremse WHERE zeitpunkt = ' . T0)->fetchColumn();
    pruefe($p . 'B19) Zeilen älter als die Aufbewahrung werden entfernt', $alt === 0 && zeilen($db) === 1,
        "alt={$alt} zeilen=" . zeilen($db));
}

// ── Ohne Datenbank ───────────────────────────────────────────────────────
$db = $backends['SQLite']();

$namen = fn(string $methode) => array_map(fn($p) => $p->getName(),
    (new ReflectionMethod(Anmeldebremse::class, $methode))->getParameters());
$ist = implode(',', $namen('__construct')) . ' | ' . implode(',', $namen('beginnen')) . ' | ' . implode(',', $namen('abschliessen'));
pruefe('B20) Schnittstelle nimmt keine Adresse', $ist === 'db,einstellungen,uhr | kennung,ziel | versuch,ausgang', $ist);

$b = new Anmeldebremse($db);
$r = $b->beginnen('ho', '');
$zp = (int)$db->query('SELECT zeitpunkt FROM anmeldebremse')->fetchColumn();
pruefe('B21) ohne übergebene Uhr gilt time()', $r['ergebnis'] === Anmeldebremse::ERLAUBT && abs($zp - time()) <= 5,
    "zeitpunkt={$zp} time=" . time());

$geworfen = '';
try { $b->abschliessen($r['versuch'], 'vielleicht'); } catch (ValueError $e) { $geworfen = 'ValueError'; }
pruefe('B22) unbekannter Ausgang wirft ValueError', $geworfen === 'ValueError', $geworfen);

$geworfen = '';
try { new Anmeldebremse($db, ['schwelle' => 0]); } catch (ValueError $e) { $geworfen = 'ValueError'; }
pruefe('B23) Schwelle 0 wirft ValueError', $geworfen === 'ValueError', $geworfen);

// Eigenständig: kein require/include, kein Name aus dem Rest des Moduls –
// im Code, nicht in Kommentaren (Tokenizer).
$verboten = [];
foreach (token_get_all(file_get_contents(__DIR__ . '/../src/Anmeldebremse.php')) as $t) {
    if (!is_array($t)) continue;
    if (in_array($t[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) $verboten[] = $t[1];
    if (in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING], true)
        && preg_match('/WebUntis(Auth|Session)/', $t[1])) $verboten[] = $t[1];
}
pruefe('B24) Anmeldebremse.php hängt an keiner anderen Datei des Moduls', $verboten === [], implode(',', $verboten));

echo "\nPrüfungen: {$gesamt}, bestanden: {$bestanden}\n";
exit($bestanden === $gesamt ? 0 : 1);
