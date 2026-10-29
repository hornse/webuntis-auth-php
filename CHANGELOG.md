# Changelog

Nennenswerte Änderungen an diesem Modul, mit Begründung wo nötig.

## [2.0.1] – 2026-10-29

**Berichtigung der Prüfung, keine Änderung am Code.**

- **v2.0.0 ist als getaggter Stand rot: 63 von 64.** Prüfung 7 (kein
  falscher Satz zum Secure-Flag) durchsucht die Dateien aus
  `git ls-files`, und `tests/mutationen.php` setzt einen dieser Sätze als
  Gegenprobe zu Prüfung 7 ein. Die Suite wurde vor dem Commit gemessen,
  als die Datei noch nicht verfolgt war – die genannten 64/64 galten für
  den Arbeitsbaum, nicht für den getaggten Stand. Der Tag `v2.0.0` bleibt
  unverändert.
- `tests/WebUntisAuthTest.php`: `tests/mutationen.php` steht in der
  Ausnahmeliste von Prüfung 7, aus demselben Grund wie die Suite selbst.
- `tests/mutationen.php`: zwei Gegenproben dazu – ohne die Ausnahme wird
  Prüfung 7 rot, und derselbe Satz in einer anderen Testdatei macht sie
  weiterhin rot (die Ausnahme ist nicht breiter als diese eine Datei).
- Prüfungen: 64 (mit MariaDB), gemessen auf dem committeten Stand in
  sauberem Baum. Mutationen: 49 → 51.

## [2.0.0] – 2026-10-26

**Bricht die Schnittstelle.** Die Sperre in `WebUntisAuth` ist entfernt.
Wer eine Bremse will, nimmt die neue Klasse `Anmeldebremse`.

### Neu: `src/Anmeldebremse.php`

- **Wartezeit statt Sperre**, nach der Reihenentscheidung (koordination
  B74): ab acht Fehlversuchen 60 Sekunden zwischen zwei Versuchen, ohne
  Staffel, nie eine Sperre. Schwelle, Wartezeit, Zählfenster (15 Minuten)
  und Aufbewahrung (7 Tage) einstellbar.
- `beginnen(kennung, ziel)` trägt den Versuch ein, zählt und liefert
  `erlaubt` (mit Versuchsnummer), `warten` (mit Sekunden) oder `fehler`
  (Datenbank antwortet nicht – abweisen). `abschliessen(versuch, ausgang)`
  mit `erfolg` (setzt zurück), `fehlschlag` (zählt) oder `ohne_entscheid`
  (zählt nicht, Zeile entfernt).
- **Eigenständig:** keine Abhängigkeit von einer anderen Datei des Moduls.
  Ein Projekt vendort nur diese Datei.
- **Gezählt nach Kennung und Ziel, nie nach Adresse** – die Schnittstelle
  nimmt keine, die Tabelle hat keine Spalte dafür (M53). Groß- und
  Kleinschreibung der Kennung zählen zusammen.
- **Die Uhr ist übergebbar.** Die Zeitpunkte sind Unix-Sekunden aus PHP,
  nicht `NOW()` – MariaDB und SQLite rechnen damit gleich.
- **Nachgelesen statt gezählt:** `abschliessen()` meldet, ob der Zustand
  in der Datenbank steht, und liest dafür nach – nicht `rowCount()`, das
  unter MariaDB geänderte und unter SQLite getroffene Zeilen meldet.
- Tabelle: `sql/anmeldebremse.mariadb.sql` und
  `sql/anmeldebremse.sqlite.sql`, je im Kopf benannt.
- **Bekannte Grenze:** Ein Skript, das einmal je Minute fehlschlägt, hält
  eine Kennung in der Wartezeit. Als Prüfung festgehalten (B10).

### `WebUntisAuth`

- **Entfernt:** `isLocked()`, die privaten `tooManyAttempts()` und `log()`,
  die Konfiguration `max_failed_logins` und `lockout_minutes`, die Tabelle
  `webuntis_login_log` und `migration.sql`. Das Modul schreibt nichts mehr
  in die Datenbank; ein Protokoll der Anmeldungen ist Sache des Projekts.
- **`authenticate(username, password)`** – der dritte Parameter `$ip`
  entfällt. Achtung: PHP verwirft ein überzähliges Argument an einer
  Methode **ohne Fehler**; ein Aufruf mit drei Argumenten läuft weiter und
  fällt nicht auf. Er wird beim Nachziehen trotzdem umgestellt.
- **Neu: `ausgang()`** nach `authenticate()`: `erfolg`, `fehlschlag` oder
  `ohne_entscheid` – dieselben Namen wie in `Anmeldebremse` (Konstanten
  `AUSGANG_*`). `ohne_entscheid` nur bei Transportfehlern: nicht
  erreichbar, Zeitüberschreitung, keine JSON-Antwort, HTTP 5xx – und bei
  leerer Eingabe, die WebUntis gar nicht erst erreicht. **Jede Antwort mit
  einem Fehlercode ist ein Fehlschlag, auch ein unbekannter**; ebenso
  jeder andere HTTP-Status und die unerlaubte Rolle. Im Zweifel zählt der
  Versuch.
- Neu: `http()` (protected) – der HTTP-Austausch als eigene Methode, damit
  die Auswertung in `jsonRpc()` mit nachgebauten Antworten geprüft wird.

### Was `kurse` beim Nachziehen ändern muss

Stand der Kopie dort: v1.2.0. Gelesen am 26.10.2026, nicht geändert.

- `backend/anmeldung.php:130` – `$auth->authenticate($benutzer,
  $passwort, $ip)` → zwei Argumente. Davor `Anmeldebremse::beginnen()`
  (bei `warten` 429, bei `fehler` 503), danach `abschliessen()` mit
  `$auth->ausgang()`. Die Bremse ersetzt die Sperre, die bisher im Modul
  saß – ohne sie hat `kurse` danach **keine**.
- `backend/anmeldung.php:138–143` – „nicht freigegeben“: WebUntis hat
  zugestimmt, der Versuch ist für die Bremse ein **Erfolg**. Abgeschlossen
  wird mit dem Ausgang von WebUntis, nicht mit der Entscheidung der
  Anwendung; nach außen bleibt die Ablehnung einheitlich.
- `backend/config.php.beispiel:36–37` – `max_failed_logins` und
  `lockout_minutes` entfallen; sie wirken nicht mehr.
- **Der Verlauf wird projekteigen, ohne IP-Spalte.**
  `kurse_verlauf_eintragen()` (`backend/anmeldung.php:245`) und
  `backend/api/verlauf.php` lesen und schreiben `webuntis_login_log`; das
  Modul legt diese Tabelle nicht mehr an und schreibt nicht mehr hinein.
  **Die bestehende Tabelle in der Datenbank von `kurse` fasst das Modul
  nicht an** – was mit ihren Daten und der IP-Spalte geschieht, entscheidet
  `kurse` beim Nachziehen (vergleichbar `sprechtag` E36).
- `tests/pruefe_alles.sh:548` – der Byte-Vergleich mit
  `../webuntis-auth-php/migration.sql` findet die Datei nicht mehr und
  meldet dann „Modul-Repo nicht daneben“ – **ein falscher Grund**. Wird
  beim Nachziehen umgestellt, nicht vorher.
- `sql/01_schema.sql:12, 66–77`, `deploy.sh:8`, `ROLLOUT.md` – berufen
  sich auf `webuntis_login_log` aus dem Modul.
- **Zu prüfen:** der Notfall-Zugang (`backend/anmeldung.php` ab 186)
  entscheidet über ein Passwort. Eine Suche dort nach Bremse, Versuch,
  Sperre und Zählung fand nichts; gelesen ist der Zweig nicht. Ob er
  gebremst werden muss, gehört zum Nachziehen.
- Neu einzuspielen: `sql/anmeldebremse.mariadb.sql`.

### Prüfungen

- `tests/WebUntisAuthTest.php`: 7 → 21. Die Prüfungen 5 und 6 galten der
  Sperre und entfallen; ihre Nummern bleiben unbesetzt. Neu A1–A16 zu
  `ausgang()`, zur entfernten Sperre und zur Signatur.
- Neu `tests/AnmeldebremseTest.php`: 24 gegen SQLite; die 19
  datenbankgebundenen laufen gegen MariaDB ein zweites Mal (43).
- Neu `tests/alle.sh`: ermittelt die Suiten, startet einen MariaDB-Server
  zum Wegwerfen, wenn ein `mariadbd` gefunden wird, sonst ÜBERSPRUNGEN.
  Summe: 64 mit MariaDB, 45 ohne.
- Neu `tests/mutationen.php` (`tests/alle.sh mutationen`): 49 Mutationen,
  jede Prüfung mindestens einmal rot, Rücknahme je per Prüfsumme belegt.
  Darunter die, die sich auf `rowCount()` verlässt: unter SQLite grün,
  unter MariaDB rot.

## [1.2.1] – 2026-10-14

**Berichtigung, keine Verhaltensänderung.** Der Code ist tokengleich mit
1.2.0; geändert sind Kommentare, README und eine Prüfung.

- **Der Satz, `$_SERVER['HTTPS'] = 'on'` sorge für das Secure-Flag am
  Sitzungscookie, war falsch.** Er stand seit der ersten Fassung
  (1.0.0, Commit `c257d19`, 2026-07-09) in `src/WebUntisSession.php`
  (Dateikopf, Docblock und Kommentar in `prepare()`, Kommentar an
  `'secure'` in `start()`), sinngemäß in `src/WebUntisAuth.php` und in
  `README.md` Abschnitt „SSL-Proxy“. Wörtlich zum Beispiel:
  „`$_SERVER['HTTPS'] = 'on'  → PHP setzt Secure-Cookie-Flag`“ und
  „Ohne `$_SERVER['HTTPS'] = 'on'` setzt PHP keinen
  `Secure`-Cookie-Flag“.
- Richtig ist: Die Flags setzt **allein** `session_set_cookie_params()`
  – im Modul in `WebUntisSession::start()`, das `secure`, `httponly`
  und `samesite=Lax` ausdrücklich setzt. Gemessen am 2026-10-14 mit dem
  eingebauten Server (PHP 8.5.8): `prepare()` plus eigenes
  `session_start()` liefert `Set-Cookie: …; path=/`, `start()` – mit oder
  ohne `prepare()` – liefert `path=/; secure; HttpOnly; SameSite=Lax`.
  Wer `start()` umgeht, hat also keinen Schutz, gleich was der Router mit
  `$_SERVER['HTTPS']` tut.
- Die falschen Sätze sind ersetzt, nicht ergänzt. `prepare()` setzt
  `$_SERVER['HTTPS']` weiterhin; die Zeile ist jetzt als „wirkt nicht
  auf das Cookie“ beschriftet.
- `tests/WebUntisAuthTest.php`: neue Prüfung 7 sucht die bekannten
  falschen Fassungen im ganzen Baum (ohne diesen Changelog, der sie
  zitiert, und ohne die vendorten Regeldateien). Prüfungen: 6 → 7.

## 1.2.0 – 2026-08-25

**Verhaltensänderung:** Die Brute-Force-Bremse scheitert künftig
geschlossen statt still zu öffnen. `migration.sql` muss vor dem ersten
Login eingespielt sein – vorher wird jede Anmeldung abgewiesen, nicht mehr
durchgelassen.

- `tooManyAttempts()` bildet den Zeitraum jetzt in PHP (`zeitpunkt >= ?`
  mit einem in PHP berechneten Zeitpunkt) statt mit
  `DATE_SUB(NOW(), INTERVAL ? MINUTE)` in SQL – die Prüfungen laufen damit
  gegen dieselbe Abfrage wie der Betrieb, nicht gegen eine, die nur unter
  MariaDB funktioniert. Wirft die Zählabfrage einen Fehler (z.B. weil die
  Tabelle `webuntis_login_log` noch fehlt), sperrt sie jetzt statt
  durchzulassen, und meldet das im Fehlerprotokoll.
- `log()` gibt zurück, ob der Eintrag gelungen ist. Scheitert das
  Vermerken eines **Fehlversuchs**, weist `authenticate()` ab – eine
  Bremse, die nicht zählen kann, ist keine. Beim erfolgreichen Login
  bleibt der Eintrag reines Protokoll: Scheitert er, wird trotzdem
  durchgelassen.

## 1.1.0 – 2026-08-25

- `sitzung_offen_halten`-Schalter in der Konfiguration (voreingestellt
  `false`) und neuer Getter `sessionCookie()`: `authenticate()` kann die
  WebUntis-Sitzung auf ausdrücklichen Wunsch offenhalten, statt sie im
  Erfolgsfall sofort wieder abzumelden. Ohne den Schalter bleibt das
  Verhalten wie zuvor.
- Fehler behoben: Im Zweig „unerlaubte Rolle" rief `authenticate()` zwar
  `logout()` auf, setzte das interne Sitzungs-Cookie aber nicht zurück.
  Ohne den neuen Schalter folgenlos, mit ihm hätte eine abgewiesene
  Anmeldung eine scheinbar gültige Sitzung hinterlassen.
- `jsonRpc()` von `private` auf `protected`: ein Test-Doppel kann die
  Methode jetzt überschreiben, `authenticate()` lässt sich ohne
  Netzzugriff prüfen. Neues `tests/WebUntisAuthTest.php` mit vier
  Prüfungen rund um `sitzung_offen_halten` und `sessionCookie()`.

## 1.0.0 – 2026-07-09

- Initiale Veröffentlichung.
