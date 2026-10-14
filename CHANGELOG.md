# Changelog

Nennenswerte Änderungen an diesem Modul, mit Begründung wo nötig.

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
