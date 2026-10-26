-- =============================================================================
-- Anmeldebremse – Tabelle für MariaDB
-- =============================================================================
-- Datenbank: MariaDB (geprüft gegen 10.11). Für SQLite gilt
-- sql/anmeldebremse.sqlite.sql – nicht diese Datei.
--
-- Einspielen: mariadb DATENBANKNAME < sql/anmeldebremse.mariadb.sql
-- Zweimal einspielen ist unschädlich (IF NOT EXISTS).
--
-- Keine Spalte für eine Adresse: Gezählt wird nach Kennung und Ziel. Hinter
-- dem Proxy steht als Adresse immer derselbe Wert (koordination M53).
--
-- zeitpunkt sind Unix-Sekunden aus der Uhr der Anwendung, nicht NOW(): So
-- rechnen MariaDB und SQLite gleich, und die Prüfungen können die Uhr stellen.
--
-- kennung mit utf8mb4_bin: Die Klasse vereinheitlicht die Schreibweise selbst.
-- Eine Sortierung, die Groß- und Kleinschreibung zusammenlegt, gäbe MariaDB
-- eine zweite Regel, die SQLite nicht hat.
-- =============================================================================

CREATE TABLE IF NOT EXISTS anmeldebremse (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kennung   VARCHAR(190)    NOT NULL,
    ziel      VARCHAR(100)    NOT NULL DEFAULT '',
    zeitpunkt BIGINT          NOT NULL,
    erfolg    TINYINT(1)      NOT NULL DEFAULT 0,
    INDEX idx_anmeldebremse_zaehlung (kennung, ziel, zeitpunkt),
    INDEX idx_anmeldebremse_zeitpunkt (zeitpunkt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
