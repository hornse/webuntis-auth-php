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

## Was das Repo ist

**Dies ist eine Quelle, kein Projekt.** Es liefert die WebUntis-Anmeldung
und wird in die Anwendungen **vendored** — kopiert, nicht eingebunden.

| | |
|---|---|
| Inhalt | `src/WebUntisAuth.php`, `src/WebUntisSession.php` |
| Beispiel | `examples/router.php` |
| Remote | **`origin`**, nicht `github` wie im Rest der Reihe |
| Testskript | **keines** |
| Lizenz | GPL (`LICENSE`) |

## Drei Dinge, die beim nächsten Mal Zeit sparen

**Der Remote heißt `origin`.** Jedes andere Repo der Reihe nennt ihn
`github`. Ein Befehl aus dem Gedächtnis (`git push github main`) läuft
hier ins Leere.

**Es gibt kein Testskript.** Solange das so ist, sind „Ausgangsstand" und
„erwartete Prüfungszahl" in jedem Auftrag leer. Das ist eine bekannte
Lücke, keine Nachlässigkeit im Einzelfall — und für ein Repo, aus dem
vier Anwendungen ihre Anmeldung beziehen, die unangenehmste der Reihe.

**Dieses Repo führt keine Tags.** Ein Rückstand einer Kopie lässt sich
deshalb nur auf ein **Datum** beziehen, nicht auf eine Versionsnummer.
Belegbar bleibt er, benennbar nicht. `REIHENREGELN.md` verlangt, dass
eine vergebene Nummer getaggt wird; hier ist das nachzuholen, bevor die
nächste Nummer vergeben wird.

## `WebUntisAuth` gibt es zweimal in der Reihe

Auch `hornse/webuntis-client-php` deklariert eine Klasse `WebUntisAuth`,
im globalen Namensraum. **Zwei `require` im selben Request sind ein
Fatal.** Eine Anwendung bezieht die Klasse aus genau einem der beiden
Repos — nicht aus beiden.

Die Fassungen sind auseinandergelaufen und haben teils eigene
Methodennamen. Sie zusammenzuführen ist ein eigener Vorgang mit eigenem
Ausgangsstand, nicht etwas, das nebenher passiert.
