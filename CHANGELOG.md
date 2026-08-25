# Changelog

Nennenswerte Änderungen an diesem Modul, mit Begründung wo nötig.

## 1.1.0 – 2026-08-25

- `sitzung_offen_halten`-Schalter in der Konfiguration (voreingestellt
  `false`) und neuer Getter `sessionCookie()`: `authenticate()` kann die
  WebUntis-Sitzung auf ausdrücklichen Wunsch offenhalten, statt sie im
  Erfolgsfall sofort wieder abzumelden. Ohne den Schalter bleibt das
  Verhalten wie zuvor.

## 1.0.0 – 2026-07-09

- Initiale Veröffentlichung.
