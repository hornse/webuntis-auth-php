<?php
/**
 * Anmeldebremse – Wartezeit statt Sperre
 * ======================================
 * Datei: src/Anmeldebremse.php
 *
 * Eigenständig: hängt an keiner anderen Datei des Moduls. Ein Projekt
 * vendort nur diese Datei und spielt die Tabelle aus sql/ ein –
 * anmeldebremse.mariadb.sql oder anmeldebremse.sqlite.sql.
 *
 * DIE BAUART (Reihenentscheidung, koordination B74):
 *   - Ab der Schwelle eine Wartezeit zwischen zwei Versuchen, ohne Staffel.
 *     Nie eine Sperre. Voreinstellung: acht Fehlversuche, 60 Sekunden.
 *   - Erst eintragen, dann zählen, dann entscheidet der Aufrufer (WebUntis
 *     oder ein lokales Passwort). So sehen gleichzeitige Versuche einander.
 *   - Laufende Versuche zählen mit.
 *   - Abgewiesene Versuche zählen nicht; ihre Zeile wird entfernt.
 *   - Ein Erfolg setzt zurück: Gezählt wird ab der Nummer der letzten
 *     gelungenen Anmeldung, nicht ab ihrer Uhrzeit (zwei Zeilen können
 *     dieselbe Sekunde tragen). Zurücksetzen kann nur, wer das Passwort
 *     kennt.
 *   - Ohne Entscheid (etwa: WebUntis nicht erreichbar) zählt nicht; die
 *     Zeile wird entfernt.
 *   - Antwortet die Datenbank nicht, wird abgewiesen – mit dem Ergebnis
 *     FEHLER, unterscheidbar von WARTEN und von einem falschen Passwort.
 *
 * GEZÄHLT NACH KENNUNG UND ZIEL, NIE NACH ADRESSE. Hinter dem Proxy steht
 * als Adresse immer derselbe Wert (koordination M53). Das Ziel trennt
 * Systeme, die dieselbe Kennung kennen (etwa Spielwiese und Anwendung).
 *
 * BEKANNTE GRENZE: Ein Skript, das einmal je Minute fehlschlägt, hält eine
 * Kennung in der Wartezeit. Mühsamer als die Sperre, nicht ausgeschlossen.
 *
 * VERWENDUNG:
 *   $bremse = new Anmeldebremse($pdo);
 *   $r = $bremse->beginnen($benutzername, 'anwendung');
 *   if ($r['ergebnis'] === Anmeldebremse::WARTEN)  → 429, „noch {$r['sekunden']} Sekunden“
 *   if ($r['ergebnis'] === Anmeldebremse::FEHLER)  → 503, eigener Satz
 *   … Passwort prüfen …
 *   $bremse->abschliessen($r['versuch'], Anmeldebremse::ERFOLG
 *                                        | FEHLSCHLAG | OHNE_ENTSCHEID);
 *
 * Die Meldung an den Benutzer formuliert das Projekt; die Klasse liefert
 * die Sekunden. Sie protokolliert keine Kennung.
 *
 * Copyright (C) 2026 Sebastian Horn, Friedrich-Rückert-Gymnasium Düsseldorf
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Abweichend vom übrigen Modul (GPL-3.0-or-later) steht diese Datei unter
 * GPL-2.0-or-later, damit sie auch in GPL-2-Projekten laufen kann. Die
 * GPL-3.0 bleibt damit wählbar. Text der GPL-2.0: LICENSE-GPL-2.0.
 */

declare(strict_types=1);

final class Anmeldebremse
{
    // ── Ergebnis von beginnen() ─────────────────────────────────────────────
    public const ERLAUBT = 'erlaubt';
    public const WARTEN  = 'warten';
    public const FEHLER  = 'fehler';

    // ── Ausgang für abschliessen() ──────────────────────────────────────────
    public const ERFOLG         = 'erfolg';
    public const FEHLSCHLAG     = 'fehlschlag';
    public const OHNE_ENTSCHEID = 'ohne_entscheid';

    private const VOREINSTELLUNGEN = [
        'schwelle'       => 8,          // Fehlversuche, ab denen gewartet wird
        'wartezeit_s'    => 60,         // Sekunden zwischen zwei Versuchen
        'fenster_s'      => 15 * 60,    // nur Versuche dieses Zeitraums zählen
        'aufbewahrung_s' => 7 * 86400,  // danach wird die Zeile entfernt
    ];

    private readonly int $schwelle;
    private readonly int $wartezeit;
    private readonly int $fenster;
    private readonly int $aufbewahrung;
    private readonly Closure $uhr;

    /**
     * @param PDO          $db             Verbindung mit der Tabelle anmeldebremse
     * @param array        $einstellungen  schwelle, wartezeit_s, fenster_s, aufbewahrung_s
     * @param Closure|null $uhr            liefert Unix-Sekunden; Voreinstellung time()
     */
    public function __construct(
        private readonly PDO $db,
        array $einstellungen = [],
        ?Closure $uhr = null
    ) {
        $e = array_merge(self::VOREINSTELLUNGEN, $einstellungen);
        foreach (self::VOREINSTELLUNGEN as $schluessel => $_) {
            if (!is_int($e[$schluessel]) || $e[$schluessel] < 1) {
                throw new ValueError("Anmeldebremse: {$schluessel} muss eine ganze Zahl ab 1 sein.");
            }
        }
        if ($e['aufbewahrung_s'] < $e['fenster_s']) {
            throw new ValueError('Anmeldebremse: aufbewahrung_s darf nicht kürzer sein als fenster_s.');
        }
        $this->schwelle     = $e['schwelle'];
        $this->wartezeit    = $e['wartezeit_s'];
        $this->fenster      = $e['fenster_s'];
        $this->aufbewahrung = $e['aufbewahrung_s'];
        $this->uhr          = $uhr ?? time(...);
    }

    /**
     * Ein Versuch beginnt: aufräumen, eintragen, zählen, urteilen.
     *
     * @return array{ergebnis: string, versuch: ?int, sekunden: ?int}
     *   ERLAUBT – versuch ist die Nummer für abschliessen()
     *   WARTEN  – sekunden bis zum nächsten möglichen Versuch
     *   FEHLER  – die Datenbank antwortet nicht; abweisen
     */
    public function beginnen(string $kennung, string $ziel = ''): array
    {
        $kennung = self::vereinheitlichen($kennung);
        $jetzt   = ($this->uhr)();
        $id      = null;

        // Nur Betriebsfehler (PDOException). Ein Programmierfehler (Error)
        // fällt nicht hier hinein – abgewiesen ist beides.
        try {
            $this->ausfuehren('DELETE FROM anmeldebremse WHERE zeitpunkt < ?', [$jetzt - $this->aufbewahrung]);
            $this->ausfuehren(
                'INSERT INTO anmeldebremse (kennung, ziel, zeitpunkt, erfolg) VALUES (?, ?, ?, 0)',
                [$kennung, $ziel, $jetzt]
            );
            $id = (int)$this->db->lastInsertId();
            if ($id < 1) throw new RuntimeException('keine Nummer für den eingetragenen Versuch');

            $zeilen = $this->ausfuehren(
                'SELECT id, erfolg, zeitpunkt FROM anmeldebremse
                  WHERE kennung = ? AND ziel = ? AND zeitpunkt > ?
                  ORDER BY id',
                [$kennung, $ziel, $jetzt - $this->fenster]
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Anmeldebremse: Zählung gescheitert, abgewiesen (' . get_class($e) . ' ' . $e->getCode() . ')');
            if ($id !== null) $this->entfernen($id);
            return ['ergebnis' => self::FEHLER, 'versuch' => null, 'sekunden' => null];
        }

        // Gezählt wird nach der letzten gelungenen Anmeldung, über die Nummer.
        $letzterErfolg = 0;
        foreach ($zeilen as $z) {
            if ((int)$z['erfolg'] === 1) $letzterErfolg = max($letzterErfolg, (int)$z['id']);
        }
        $anzahl = 0;
        $juengster = null;
        foreach ($zeilen as $z) {
            if ((int)$z['id'] === $id || (int)$z['id'] <= $letzterErfolg) continue;
            $anzahl++;
            $juengster = max($juengster ?? (int)$z['zeitpunkt'], (int)$z['zeitpunkt']);
        }

        if ($anzahl >= $this->schwelle && $juengster !== null) {
            $seit = max(0, $jetzt - $juengster);
            if ($seit < $this->wartezeit) {
                // Abgewiesene zählen nicht – sonst verlängerte jeder Klick die Wartezeit.
                $this->entfernen($id);
                return ['ergebnis' => self::WARTEN, 'versuch' => null, 'sekunden' => $this->wartezeit - $seit];
            }
        }
        return ['ergebnis' => self::ERLAUBT, 'versuch' => $id, 'sekunden' => null];
    }

    /**
     * Ein Versuch endet.
     *   ERFOLG          – die Zeile wird zum Erfolg und setzt die Zählung zurück
     *   FEHLSCHLAG      – die Zeile bleibt, wie sie eingetragen wurde
     *   OHNE_ENTSCHEID  – die Zeile wird entfernt; es hat kein Passwort entschieden
     *
     * @return bool  ob der Zustand danach in der Datenbank steht – nachgelesen,
     *               nicht aus der Zahl betroffener Zeilen (MariaDB meldet
     *               geänderte, SQLite getroffene).
     */
    public function abschliessen(int $versuch, string $ausgang): bool
    {
        if (!in_array($ausgang, [self::ERFOLG, self::FEHLSCHLAG, self::OHNE_ENTSCHEID], true)) {
            throw new ValueError("Anmeldebremse: unbekannter Ausgang '{$ausgang}'.");
        }
        try {
            if ($ausgang === self::OHNE_ENTSCHEID) {
                $this->ausfuehren('DELETE FROM anmeldebremse WHERE id = ?', [$versuch]);
                return $this->erfolgDerZeile($versuch) === null;
            }
            if ($ausgang === self::ERFOLG) {
                $this->ausfuehren('UPDATE anmeldebremse SET erfolg = 1 WHERE id = ?', [$versuch]);
                return $this->erfolgDerZeile($versuch) === 1;
            }
            return $this->erfolgDerZeile($versuch) === 0;
        } catch (Exception $e) {
            error_log('Anmeldebremse: Ausgang nicht vermerkt (' . $ausgang . ', '
                . get_class($e) . ' ' . $e->getCode() . ')');
            return false;
        }
    }

    // ── Intern ──────────────────────────────────────────────────────────────

    /**
     * Groß- und Kleinschreibung und Leerraum außen zählen zusammen. Sonst
     * hätte ein Angreifer je Schreibweise eine eigene Zählung.
     */
    private static function vereinheitlichen(string $kennung): string
    {
        $kennung = trim($kennung);
        return function_exists('mb_strtolower') ? mb_strtolower($kennung, 'UTF-8') : strtolower($kennung);
    }

    /** erfolg der Zeile (0 oder 1), null wenn es sie nicht gibt. */
    private function erfolgDerZeile(int $id): ?int
    {
        $wert = $this->ausfuehren('SELECT erfolg FROM anmeldebremse WHERE id = ?', [$id])->fetchColumn();
        return $wert === false ? null : (int)$wert;
    }

    /** Entfernt eine Zeile; scheitert das, steht es im Protokoll. */
    private function entfernen(int $id): void
    {
        try {
            $this->ausfuehren('DELETE FROM anmeldebremse WHERE id = ?', [$id]);
        } catch (Exception $e) {
            error_log('Anmeldebremse: Versuch nicht entfernt (' . get_class($e) . ' ' . $e->getCode() . ')');
        }
    }

    /**
     * Führt eine Anweisung aus und wirft bei jedem Fehlschlag – auch wenn der
     * Aufrufer die Verbindung nicht auf Ausnahmen gestellt hat. Sonst schlüge
     * die Bremse dort still fehl und ließe durch.
     */
    private function ausfuehren(string $sql, array $werte): PDOStatement
    {
        $st = $this->db->prepare($sql);
        if ($st === false || $st->execute($werte) === false) {
            throw new RuntimeException('Anweisung gescheitert');
        }
        return $st;
    }
}
