# Projektgedächtnis: webuntis-auth-php

Diese Datei wird von Claude Code bei **jedem** Sessionstart automatisch
gelesen. Sie liegt im Repo und wird per Push geteilt. Was hier steht,
muss nicht mehr erklärt werden.

Nicht hier hinein gehören Tagesaufgaben. Hier stehen Dinge, die in sechs
Monaten noch gelten sollen.

Ergänzend:
- Regeln der Reihe: @REIHENREGELN.md
- Fallstricke PHP/Router/WebUntis: @FALLSTRICKE.md

---

## Was hier nicht steht

**Die Regeln der Reihe stehen in `REIHENREGELN.md`**, die technischen
Fallstricke in `FALLSTRICKE.md`. Beide sind vendorte Kopien aus
`hornse/koordination` und oben importiert. `FALLSTRICKE.md` ist hier
besonders einschlägig: Was dort über Sitzungen, Schuljahres-IDs und
Personentypen steht, beschreibt genau das, was dieses Repo umsetzt.

**Was über die WebUntis-API gemessen ist, liegt verstreut.** Die Liste
aller Fundstellen steht in
`~/Projekte/koordination/docs/BEFUND-2026-10-11-webuntis-api-bestand.md`
— 846 Stellen in zehn Repos, Stand 11.10.2026. Sie sagt, **wo** etwas
steht, nicht was gilt. **Vor jedem neuen Aufruf dort nachsehen**, nicht
nur im eigenen Repo.

**Die Fundstellen widersprechen sich** (Abschnitt 4 der Liste), darunter:
Die Felder von `getStudents` werden in mehreren Fassungen beschrieben;
für den Mitteilungsversand nennen Modul-Doku und Modul-Code verschiedene
Wege; die Schülerzahl aus `getStudents` steht je nach Datum bei 3563,
3782 oder 3802. **Und keine Messung trägt die WebUntis-Version** — was
vor Wochen gemessen wurde, kann ein Update überholt haben.

## Was das Repo ist

**Dies ist eine Quelle, kein Projekt.** Es liefert die WebUntis-Anmeldung
und wird in die Anwendungen **vendored** — kopiert, nicht eingebunden.

| | |
|---|---|
| Inhalt | `src/WebUntisAuth.php`, `src/WebUntisSession.php`, `src/Anmeldebremse.php` (eigenständig vendorbar) |
| SQL | `sql/anmeldebremse.mariadb.sql`, `sql/anmeldebremse.sqlite.sql` |
| Beispiel | `examples/router.php` |
| Remote | **`origin`**, nicht `github` wie im Rest der Reihe |
| Testskript | `tests/alle.sh`; Gegenproben `tests/alle.sh mutationen` |
| Lizenz | GPL (`LICENSE`) |

## Drei Dinge, die beim nächsten Mal Zeit sparen

**Der Remote heißt `origin`.** Jedes andere Repo der Reihe nennt ihn
`github`. Ein Befehl aus dem Gedächtnis (`git push github main`) läuft
hier ins Leere.

**`tests/alle.sh` startet MariaDB selbst.** Findet es ein `mariadbd`
(lokal `mariadb@10.11` über Homebrew, wie der Server), läuft ein Server
zum Wegwerfen im Temp-Verzeichnis, nur über Socket. Ohne steht
ÜBERSPRUNGEN da, und die Summe ist kleiner — eine kleinere Summe ist
deshalb erst zu erklären, bevor sie als Rückschritt gilt. Die
Mutationsserie verlangt MariaDB und ist ohne sie rot.

**Getaggt wird ab v1.2.1.** Die Fassungen davor (1.0.0 bis 1.2.0) haben
keinen Tag; ein Rückstand einer Kopie auf eine davon lässt sich nur über
das Datum belegen.

## `WebUntisAuth` gibt es zweimal in der Reihe

Auch `hornse/webuntis-client-php` deklariert eine Klasse `WebUntisAuth`,
im globalen Namensraum. **Zwei `require` im selben Request sind ein
Fatal.** Eine Anwendung bezieht die Klasse aus genau einem der beiden
Repos — nicht aus beiden.

Die Fassungen sind auseinandergelaufen und haben teils eigene
Methodennamen. Sie zusammenzuführen ist ein eigener Vorgang mit eigenem
Ausgangsstand, nicht etwas, das nebenher passiert.
