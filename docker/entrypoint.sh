#!/bin/sh
# AllMyPrinters Slicer: Worker im Hintergrund, HTTP-Server im Vordergrund. tini (PID 1) räumt Orca-Prozesse ab.
set -e
mkdir -p "${AMP_DATA:-/data}/jobs" "${AMP_DATA:-/data}/log"
# Worker neu starten, falls er je abbricht — sonst blieben Aufträge stehen
(while true; do php -d memory_limit=-1 /app/worker.php; sleep 2; done) &
export PHP_CLI_SERVER_WORKERS="${AMP_HTTP_WORKERS:-4}"
# -q: keine Zeile je Anfrage (sonst stünden Adressen unbefristet im Docker-Protokoll)
exec php -S "${AMP_LISTEN:-0.0.0.0}:${AMP_PORT:-8080}" -q /app/router.php
