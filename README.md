# webuntis-auth-php

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP: 8.1+](https://img.shields.io/badge/PHP-8.1+-blue.svg)](https://php.net)

Wiederverwendbares PHP-Modul zur Authentifizierung gegen die WebUntis
JSON-RPC-API. Unterstützt Lehrkräfte, WebUntis-Administratoren und Schüler.
Ohne externe Abhängigkeiten – nur PHP 8.1+, PDO und cURL.

Entwickelt an der Friedrich-Rückert-Gymnasium Düsseldorf und eingesetzt in
[Projektstunden NRW](https://github.com/hornse/projektstunden) und
[Schulprozesse](https://github.com/hornse/schulprozesse).

---

## Funktionen

- WebUntis JSON-RPC Authentifizierung (Lehrkraft, Admin, Schüler)
- Automatische Profildaten-Abfrage nach Login (Name, Kürzel, Schild-ID)
- Brute-Force-Schutz mit konfigurierbarer Sperrzeit
- Session-Management als eigenständige Klasse
- Vollständige Uberspace-Kompatibilität (SSL-Proxy, PHP built-in Server)
- Keine externen Abhängigkeiten (kein Composer nötig)

---

## Dateistruktur

```
webuntis-auth-php/
├── src/
│   ├── WebUntisAuth.php      ← Authentifizierung gegen WebUntis-API
│   └── WebUntisSession.php   ← Session-Management
├── examples/
│   └── router.php            ← Vollständiges Verwendungsbeispiel
├── config.example.php        ← Konfigurationsvorlage
├── migration.sql             ← Datenbank-Migration (Login-Log)
├── LICENSE
└── README.md
```

---

## Schnellstart

### 1. Einbinden

```bash
# Als Submodul in ein bestehendes Projekt:
git submodule add https://github.com/hornse/webuntis-auth-php.git auth

# Oder einfach kopieren:
cp -r webuntis-auth-php/src/ mein-projekt/auth/
```

### 2. Datenbank-Migration

```bash
# MariaDB/MySQL:
mysql DATENBANKNAME < migration.sql

# SQLite (kommentierte Version in migration.sql nutzen):
sqlite3 datenbank.sqlite < migration.sql
```

### 3. Konfiguration

```bash
cp config.example.php config.php
nano config.php  # base_url, school, admin_kuerzel anpassen
```

### 4. Router einrichten

```php
<?php
// WICHTIG: Zuerst Session vorbereiten (vor jedem require!)
require_once 'auth/WebUntisSession.php';
WebUntisSession::prepare('mein_session_name');

// Dann Rest laden
$config = require 'config.php';
require_once 'auth/WebUntisAuth.php';
WebUntisSession::start($config['session']);

// ... Rest des Routers
```

Vollständiges Beispiel: `examples/router.php`

---

## PersonType-Werte

| personType | Bedeutung | personId | Besonderheit |
|---|---|---|---|
| 2 | Lehrkraft | > 0 | Name über `getTeachers()` |
| 16 | WebUntis-Admin | **-1** | Kein Stundenplan-Eintrag → Name aus DB |
| 5 | Schüler | > 0 | `key` = Schild-ID |

---

## Bekannte Fallstricke

### 1. `session_name()` muss vor jedem `require` stehen

```php
// ✓ Richtig
require_once 'WebUntisSession.php';
WebUntisSession::prepare('mein_session');
require 'config.php'; // DANACH

// ✗ Falsch – Session-Wiederherstellung schlägt fehl
require 'config.php';
WebUntisSession::prepare('mein_session');
```

**Warum:** PHP liest den Cookie-Namen beim allerersten Aufruf von
`session_name()`. Wird er zu spät gesetzt, sucht PHP nach dem
Standard-Cookie-Namen `PHPSESSID` und findet die Session nicht.

### 2. SSL-Proxy (Uberspace, nginx)

PHP built-in Server läuft intern über HTTP, auch wenn Uberspace/nginx
nach außen HTTPS macht. Ohne `$_SERVER['HTTPS'] = 'on'` setzt PHP
keinen `Secure`-Cookie-Flag – moderne Browser verwerfen solche Cookies
auf HTTPS-Seiten.

`WebUntisSession::prepare()` setzt `$_SERVER['HTTPS'] = 'on'` automatisch.

### 3. `empty(0)` ist `true`

WebUntis-Lehrer ohne lokalen DB-Eintrag bekommen `benutzer_id = 0`.
`empty(0) === true` → würde fälschlich als "nicht eingeloggt" behandelt.

```php
// ✗ Falsch
if (empty($_SESSION['benutzer_id'])) { /* 401 */ }

// ✓ Richtig
if (!isset($_SESSION['benutzer_id']) || $_SESSION['benutzer_id'] === null) { /* 401 */ }

// Oder: WebUntisSession::requireAuth() nutzen – macht das richtig
```

### 4. WebUntis-Admin hat `personId = -1`

Reine WebUntis-Administratoren (personType 16) tauchen nicht in der
Lehrerliste auf. `getTeachers()` findet sie nicht. Name muss aus der
lokalen Datenbank per Kürzel nachgeschlagen werden.

### 5. `key` bei Schülern = Schild-ID

Der `key`-Wert aus `getStudents()` entspricht der Schild-NRW-internen
ID (`schild_id`). Kein manuelles Mapping nötig wenn beide Systeme
(WebUntis und Schild) dieselbe Schule führen.

---

## API-Referenz

### `WebUntisAuth`

```php
$wu = new WebUntisAuth($pdo, $config['webuntis']);

// Authentifizieren und Profildaten holen
$result = $wu->authenticate(string $username, string $password, string $ip): ?array;
// null = fehlgeschlagen
// array = ['personType'=>2, 'personId'=>1013, 'kuerzel'=>'Ho', 'vorname'=>'...', ...]

// Prüfen ob gesperrt
$gesperrt = $wu->isLocked(string $username): bool;
```

### `WebUntisSession`

```php
// Im Router ganz oben (vor require):
WebUntisSession::prepare(string $sessionName): void;

// Session starten:
WebUntisSession::start(array $config): void;

// Logins speichern:
WebUntisSession::loginLehrer(array $details, string $rolle, int $dbId, int $schuleId): void;
WebUntisSession::loginLokal(array $user): void;
WebUntisSession::loginSchueler(array $schueler, int $schuleId): void;

// Aktuellen Benutzer lesen:
WebUntisSession::currentUser(): ?array;
WebUntisSession::displayName(): string;

// Schutz:
WebUntisSession::requireAuth(): array;   // 401 wenn nicht eingeloggt
WebUntisSession::requireAdmin(): array;  // 403 wenn kein Admin

// Ausloggen:
WebUntisSession::logout(): void;
```

---

## Getestete Umgebungen

| Umgebung | Status |
|---|---|
| Uberspace 7 (PHP 8.1, MariaDB 10.6) | ✓ Getestet |
| PHP built-in Server hinter nginx/SSL | ✓ Getestet |
| WebUntis (neilo.webuntis.com) | ✓ Getestet |
| WebUntis (frg-dusseldorf.webuntis.com) | ✓ Getestet |

---

## Lizenz

Copyright (C) 2026 Sebastian Horn, Friedrich-Rückert-Gymnasium Düsseldorf
GNU General Public License v3.0 – Details siehe [LICENSE](LICENSE).
