-- =============================================================================
-- WebUntis Auth – Datenbank-Migration
-- =============================================================================
-- Erstellt die Tabelle für den Login-Log (Brute-Force-Schutz).
-- Einmalig ausführen:
--   MariaDB/MySQL: mysql DATENBANKNAME < migration.sql
--   SQLite:        sqlite3 datenbank.sqlite < migration.sql (ohne ENGINE/CHARSET)
-- =============================================================================

-- Login-Log für Brute-Force-Schutz
CREATE TABLE IF NOT EXISTS webuntis_login_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    benutzername VARCHAR(100) NOT NULL COMMENT 'WebUntis-Kürzel oder Schüler-Username',
    erfolgreich  TINYINT(1)   NOT NULL DEFAULT 0,
    grund        VARCHAR(50)  NULL     COMMENT 'z.B. falsches_passwort, zu_viele_versuche',
    ip           VARCHAR(45)  NULL,
    zeitpunkt    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_benutzername_zeitpunkt (benutzername, zeitpunkt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Protokoll aller WebUntis-Login-Versuche';

-- SQLite-Version (ohne ENGINE/CHARSET):
-- CREATE TABLE IF NOT EXISTS webuntis_login_log (
--     id           INTEGER PRIMARY KEY AUTOINCREMENT,
--     benutzername TEXT    NOT NULL,
--     erfolgreich  INTEGER NOT NULL DEFAULT 0,
--     grund        TEXT    NULL,
--     ip           TEXT    NULL,
--     zeitpunkt    TEXT    NOT NULL DEFAULT (datetime('now'))
-- );
-- CREATE INDEX IF NOT EXISTS idx_wu_log ON webuntis_login_log (benutzername, zeitpunkt);
