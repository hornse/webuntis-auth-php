-- =============================================================================
-- Anmeldebremse – Tabelle für SQLite
-- =============================================================================
-- Datenbank: SQLite 3. Für MariaDB gilt sql/anmeldebremse.mariadb.sql –
-- nicht diese Datei.
--
-- Einspielen: sqlite3 datenbank.sqlite < sql/anmeldebremse.sqlite.sql
-- Zweimal einspielen ist unschädlich (IF NOT EXISTS).
--
-- Keine Spalte für eine Adresse: Gezählt wird nach Kennung und Ziel.
--
-- AUTOINCREMENT ist Absicht: Die Bremse zählt ab der Nummer der letzten
-- gelungenen Anmeldung. Ohne AUTOINCREMENT darf SQLite die Nummer einer
-- gelöschten letzten Zeile wieder vergeben.
-- =============================================================================

CREATE TABLE IF NOT EXISTS anmeldebremse (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    kennung   TEXT    NOT NULL,
    ziel      TEXT    NOT NULL DEFAULT '',
    zeitpunkt INTEGER NOT NULL,
    erfolg    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_anmeldebremse_zaehlung ON anmeldebremse (kennung, ziel, zeitpunkt);
CREATE INDEX IF NOT EXISTS idx_anmeldebremse_zeitpunkt ON anmeldebremse (zeitpunkt);
