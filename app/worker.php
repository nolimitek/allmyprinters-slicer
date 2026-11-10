<?php
/**
 * AllMyPrinters Slicer — Worker: arbeitet wartende Aufträge nacheinander ab (immer nur ein Orca gleichzeitig, wie auf
 * 3DSEARCH), räumt alte Aufträge weg (PS_KEEP_HOURS). Läuft dauerhaft neben dem HTTP-Server (docker/entrypoint.sh).
 */
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/slice_engine.php';
@mkdir(PS_DIR, 0750, true);
// Neustart mitten im Slicen (Container gestoppt): solche Aufträge bleiben sonst ewig „slicing“
foreach (glob(PS_DIR . '/*/job.json') ?: [] as $f) {
    $j = json_decode((string)file_get_contents($f), true);
    if (($j['status'] ?? '') === 'slicing') { $j['status'] = 'error'; $j['error'] = 'interrupted'; $j['finished'] = time(); ps_job_save($j); }
}
$clean = 0;
while (true) {
    if (time() - $clean > 600) { ps_cleanup(); $clean = time(); }
    try {
        if (!ps_worker_run_one()) sleep(1);
    } catch (Throwable $e) {
        fwrite(STDERR, gmdate('c') . ' worker: ' . $e->getMessage() . "\n");
        sleep(2);
    }
}
