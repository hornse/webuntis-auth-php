# Changelog

Nennenswerte Änderungen an diesem Modul, mit Begründung wo nötig.

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
