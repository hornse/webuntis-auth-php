#!/bin/bash
# =============================================================================
# Alle Prüfungen des Moduls
# =============================================================================
# Ausführen: tests/alle.sh
#
# Die Suiten werden ermittelt (tests/*Test.php), nicht aufgezählt. Das fängt
# eine vergessene Zeile, aber keine gelöschte Datei – eine gesunkene Summe
# wird erklärt, nicht hingenommen.
#
# MariaDB: Findet das Skript ein mariadbd (MARIADB_BIN, sonst PATH, sonst eine
# Homebrew-Fassung mariadb*), startet es einen Server zum Wegwerfen – eigenes
# Datenverzeichnis, nur Socket, kein Netz – und lässt die Suiten zusätzlich
# dagegen laufen. Findet es keines, steht ÜBERSPRUNGEN in der Ausgabe.
#
# Exit-Code 0 nur, wenn jede Suite gelaufen und grün ist.
# =============================================================================
set -euo pipefail
export LC_ALL=C

cd "$(dirname "$0")/.."

# ── MariaDB suchen ───────────────────────────────────────────────────────────
bin=""
if [ -n "${MARIADB_BIN:-}" ]; then
  bin="$MARIADB_BIN"
elif command -v mariadbd >/dev/null; then
  bin="$(dirname "$(command -v mariadbd)")"
elif command -v brew >/dev/null; then
  for d in "$(brew --prefix)"/opt/mariadb*/bin; do
    if [ -x "$d/mariadbd" ]; then bin="$d"; break; fi
  done
fi

tmp=""
pid=""
aufraeumen() {
  if [ -n "$pid" ]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  if [ -n "$tmp" ]; then rm -rf "$tmp"; fi
}
trap aufraeumen EXIT

if [ -n "$bin" ]; then
  tmp="$(mktemp -d)"
  sock="$tmp/mariadb.sock"
  "$bin/mariadb-install-db" --no-defaults --datadir="$tmp/daten" \
      --auth-root-authentication-method=normal --skip-test-db >"$tmp/install.log" 2>&1 \
    || { echo "MariaDB: Einrichten gescheitert, siehe Ausgabe:"; cat "$tmp/install.log"; exit 1; }
  "$bin/mariadbd" --no-defaults --datadir="$tmp/daten" --socket="$sock" \
      --skip-networking --pid-file="$tmp/mariadb.pid" >"$tmp/server.log" 2>&1 &
  pid=$!
  for _ in $(seq 1 60); do
    if "$bin/mariadb" --no-defaults -S "$sock" -uroot -e 'SELECT 1' >/dev/null 2>&1; then break; fi
    sleep 0.5
  done
  "$bin/mariadb" --no-defaults -S "$sock" -uroot -e 'CREATE DATABASE probe' \
    || { echo "MariaDB: Server nicht erreichbar, siehe Ausgabe:"; cat "$tmp/server.log"; exit 1; }
  export ANMELDEBREMSE_MARIADB_DSN="mysql:unix_socket=$sock;dbname=probe"
  export ANMELDEBREMSE_MARIADB_USER=root
  export ANMELDEBREMSE_MARIADB_PASS=""
  echo "MariaDB: $("$bin/mariadbd" --version | sed 's/^.*Ver //')"
else
  echo "MariaDB: ÜBERSPRUNGEN – kein mariadbd gefunden"
fi

# ── Gegenproben statt Suiten: tests/alle.sh mutationen ───────────────────────
if [ "${1:-}" = "mutationen" ]; then
  php tests/mutationen.php
  exit $?
fi

# ── Suiten ───────────────────────────────────────────────────────────────────
gesamt=0
bestanden=0
fehler=0
anzahl=0
for suite in tests/*Test.php; do
  anzahl=$((anzahl + 1))
  echo "== ${suite} =="
  rc=0
  ausgabe="$(php "$suite" 2>&1)" || rc=$?
  printf '%s\n' "$ausgabe"
  zeile="$(printf '%s\n' "$ausgabe" | grep -E '^Prüfungen: [0-9]+, bestanden: [0-9]+$' || true)"
  if [ -z "$zeile" ]; then
    echo "!! ${suite}: keine Summenzeile – Suite abgebrochen?"
    fehler=1
    continue
  fi
  g="$(printf '%s\n' "$zeile" | sed -E 's/^Prüfungen: ([0-9]+), bestanden: ([0-9]+)$/\1/')"
  b="$(printf '%s\n' "$zeile" | sed -E 's/^Prüfungen: ([0-9]+), bestanden: ([0-9]+)$/\2/')"
  gesamt=$((gesamt + g))
  bestanden=$((bestanden + b))
  if [ "$rc" -ne 0 ]; then fehler=1; fi
done

if [ "$anzahl" -eq 0 ]; then
  echo "!! keine Suite gefunden"
  exit 1
fi

echo
echo "Suiten: ${anzahl}, Prüfungen: ${gesamt}, bestanden: ${bestanden}"
if [ "$fehler" -ne 0 ] || [ "$gesamt" -ne "$bestanden" ]; then exit 1; fi
