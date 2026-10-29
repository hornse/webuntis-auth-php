# webuntis-auth-php

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP: 8.1+](https://img.shields.io/badge/PHP-8.1+-blue.svg)](https://php.net)

Die Fassung steht am Tag (`git tag`) und im `CHANGELOG.md`.

Wiederverwendbares PHP-Modul zur Authentifizierung gegen die WebUntis
JSON-RPC-API. Unterstützt Lehrkräfte, WebUntis-Administratoren und Schüler.
Ohne externe Abhängigkeiten – nur PHP 8.1+, PDO und cURL.

Entwickelt am Friedrich-Rückert-Gymnasium Düsseldorf.

---

## Funktionen

- WebUntis JSON-RPC Authentifizierung (Lehrkraft, Admin, Schüler)
- Automatische Profildaten-Abfrage nach Login (Name, Kürzel, Schild-ID)
- Anmeldebremse als eigenständige Klasse: Wartezeit statt Sperre,
  gezählt nach Kennung, nie nach Adresse
- Session-Management als eigenständige Klasse
- Vollständige Uberspace-Kompatibilität (SSL-Proxy, PHP built-in Server)
- Keine externen Abhängigkeiten (kein Composer nötig)

---

## Dateistruktur

```
webuntis-auth-php/
├── src/
│   ├── WebUntisAuth.php      ← Authentifizierung gegen WebUntis-API
│   ├── WebUntisSession.php   ← Session-Management
│   └── Anmeldebremse.php     ← Bremse, eigenständig vendorbar
├── sql/
│   ├── anmeldebremse.mariadb.sql  ← Tabelle der Bremse, MariaDB
│   └── anmeldebremse.sqlite.sql   ← Tabelle der Bremse, SQLite
├── tests/
│   └── alle.sh               ← alle Prüfungen, MariaDB dazu, wenn vorhanden
├── examples/
│   └── router.php            ← Vollständiges Verwendungsbeispiel
├── config.example.php        ← Konfigurationsvorlage
├── LICENSE
├── LICENSE-GPL-2.0           ← Text der GPL-2.0, für src/Anmeldebremse.php
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

### 2. Tabelle der Bremse (nur wer `Anmeldebremse` nutzt)

```bash
# MariaDB:
mariadb DATENBANKNAME < sql/anmeldebremse.mariadb.sql

# SQLite:
sqlite3 datenbank.sqlite < sql/anmeldebremse.sqlite.sql
```

`WebUntisAuth` selbst schreibt nichts in die Datenbank. Ein Protokoll der
Anmeldungen ist Sache des Projekts.

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

### 2. Cookie-Flags (Secure, HttpOnly, SameSite)

Ob das Sitzungscookie geschützt ist, entscheidet **allein**
`session_set_cookie_params()` – `secure`, `httponly` und `samesite`
ausdrücklich. `WebUntisSession::start()` setzt alle drei
(`SameSite=Lax`). `$_SERVER['HTTPS']` hat darauf **keinen Einfluss**,
auch hinter einem SSL-Proxy (Uberspace, nginx) nicht.

Wer `start()` umgeht und `session_start()` selbst aufruft, bekommt ein
Cookie mit nur `path=/`. **Belegt wird es am Betrieb**, nicht am Code:
Die Antwortkopfzeile `Set-Cookie` muss alle drei Flags zeigen.

**`Lax`, nicht `Strict`:** `Strict` schickt das Cookie bei einem Link
von außen nicht mit, und wer von der Startseite kommt, steht abgemeldet
da.

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
$result = $wu->authenticate(string $username, string $password): ?array;
// null = fehlgeschlagen
// array = ['personType'=>2, 'personId'=>1013, 'kuerzel'=>'Ho', 'vorname'=>'...', ...]

// Warum – für Anmeldebremse::abschliessen():
$ausgang = $wu->ausgang(): ?string;
// WebUntisAuth::AUSGANG_ERFOLG, _FEHLSCHLAG (jeder Fehlercode von WebUntis,
// auch die unerlaubte Rolle) oder _OHNE_ENTSCHEID (nicht erreichbar,
// Zeitüberschreitung, keine JSON-Antwort, HTTP 5xx)

// JSESSIONID der WebUntis-Sitzung – nur mit 'sitzung_offen_halten' => true
// und nach erfolgreicher Anmeldung, sonst null:
$cookie = $wu->sessionCookie(): ?string;
```

### `Anmeldebremse`

Eigenständig: Ein Projekt vendort nur `src/Anmeldebremse.php` und spielt
die Tabelle aus `sql/` ein.

```php
$bremse = new Anmeldebremse(PDO $pdo, array $einstellungen = [], ?Closure $uhr = null);
// Einstellungen und Voreinstellung: schwelle 8, wartezeit_s 60,
// fenster_s 900, aufbewahrung_s 604800

$r = $bremse->beginnen(string $kennung, string $ziel = ''): array;
// ['ergebnis' => Anmeldebremse::ERLAUBT, 'versuch' => 17,   'sekunden' => null]
// ['ergebnis' => Anmeldebremse::WARTEN,  'versuch' => null, 'sekunden' => 45]
// ['ergebnis' => Anmeldebremse::FEHLER,  'versuch' => null, 'sekunden' => null]

$bremse->abschliessen(int $versuch, string $ausgang): bool;
// ERFOLG setzt zurück, FEHLSCHLAG zählt, OHNE_ENTSCHEID zählt nicht.
// true, wenn der Zustand danach in der Datenbank steht (nachgelesen).
```

Ab der Schwelle wird zwischen zwei Versuchen gewartet, nie gesperrt.
Abgewiesene Versuche zählen nicht, laufende zählen mit. **Bekannte
Grenze:** Ein Skript, das einmal je Minute fehlschlägt, hält eine Kennung
in der Wartezeit.

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
`src/Anmeldebremse.php` steht abweichend unter GPL-2.0-or-later, damit sie auch in GPL-2-Projekten laufen kann – Text der GPL-2.0 siehe [LICENSE-GPL-2.0](LICENSE-GPL-2.0).
