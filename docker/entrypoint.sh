#!/bin/sh
# AllMyPrinters Slicer: Worker im Hintergrund, HTTP-Server im Vordergrund. tini -g (PID 1) gibt Signale an alle Prozesse
# weiter und räumt Orca-Prozesse ab.
set -e
mkdir -p "${AMP_DATA:-/data}/jobs" "${AMP_DATA:-/data}/log"
# Worker neu starten, falls er je abbricht — sonst blieben Aufträge stehen. Er schreibt eine Zeile je Auftrag ins Protokoll.
(while true; do php -d memory_limit=-1 /app/worker.php; sleep 2; done) &
export PHP_CLI_SERVER_WORKERS="${AMP_HTTP_WORKERS:-4}"
# -q: keine Zeile je Anfrage (sonst stünden Adressen unbefristet im Docker-Protokoll).
# „Failed to poll event“ meldet der eingebaute PHP-Server mit mehreren Workern bei jeder Anfrage (alle warten auf denselben
# Socket, nur einer bekommt sie) — harmlos, aber es verdeckte alles andere (Adam, 10.11.2026) → herausfiltern.
php -S "${AMP_LISTEN:-0.0.0.0}:${AMP_PORT:-8080}" -q /app/router.php 2>&1 | grep --line-buffered -v 'Failed to poll event'
