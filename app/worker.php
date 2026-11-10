<?php
/**
 * AllMyPrinters Slicer — Worker: arbeitet wartende Aufträge nacheinander ab (immer nur ein Orca gleichzeitig, wie auf
 * 3DSEARCH), räumt alte Aufträge weg (PS_KEEP_HOURS). Läuft dauerhaft neben dem HTTP-Server (docker/entrypoint.sh).
 *
 * Protokoll (stdout → docker logs): Start des Containers und je Auftrag eine Zeile beim Start und eine beim Ende — Drucker,
 * Dateiart, Größe, Dauer, Ergebnis bzw. Fehler. Nie Dateinamen, Modellnamen oder Adressen (Adam, 10.11.2026: „the logs of
 * the server are not telling anything meaningful when slicing“).
 */
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/slice_engine.php';
@mkdir(PS_DIR, 0750, true);

function amp_log(string $msg): void {
    fwrite(STDOUT, gmdate('Y-m-d H:i:s') . ' ' . $msg . "\n");
}
/** Ältester wartender Auftrag (dieselbe Wahl wie ps_worker_run_one). */
function amp_next_job(): ?array {
    $next = null;
    foreach (glob(PS_DIR . '/*/job.json') ?: [] as $f) {
        $j = json_decode((string)file_get_contents($f), true);
        if (($j['status'] ?? '') === 'queued' && (!$next || $j['created'] < $next['created'])) $next = $j;
    }
    return $next;
}
function amp_job(string $id): ?array {
    $j = json_decode((string)@file_get_contents(ps_job_dir($id) . '/job.json'), true);
    return is_array($j) ? $j : null;
}
function amp_job_printer(array $j): string {
    $p = is_array($j['printer'] ?? null) ? $j['printer'] : [];
    $m = trim((string)($p['model'] ?? '')) ?: trim((string)($p['name'] ?? ''));
    return $m !== '' ? mb_substr($m, 0, 60) : 'unknown printer';
}
function amp_job_size(array $j): string {
    $b = 0;
    foreach (glob(ps_job_dir($j['id']) . '/*.{stl,3mf,gcode}', GLOB_BRACE) ?: [] as $f) $b += (int)@filesize($f);
    return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
}
function amp_dur(int $s): string {
    return $s >= 3600 ? sprintf('%dh%02dm', intdiv($s, 3600), intdiv($s % 3600, 60)) : ($s >= 60 ? sprintf('%dm%02ds', intdiv($s, 60), $s % 60) : $s . 's');
}

// Neustart mitten im Slicen (Container gestoppt): solche Aufträge bleiben sonst ewig „slicing“
foreach (glob(PS_DIR . '/*/job.json') ?: [] as $f) {
    $j = json_decode((string)file_get_contents($f), true);
    if (($j['status'] ?? '') === 'slicing') { $j['status'] = 'error'; $j['error'] = 'interrupted'; $j['finished'] = time(); ps_job_save($j); }
}
$ver = trim((string)@file_get_contents(__DIR__ . '/VERSION')) ?: 'dev';
$orca = trim((string)@file_get_contents('/opt/orcaslicer/VERSION')) ?: '?';
amp_log("AllMyPrinters Slicer $ver ready (OrcaSlicer $orca, port " . (getenv('AMP_PORT') ?: '8080') . ', limits ' . (getenv('AMP_LIMITS') === '1' ? 'on' : 'off')
    . ', request log ' . (getenv('AMP_LOG') === '1' ? 'on' : 'off') . ')');

$clean = 0;
while (true) {
    if (time() - $clean > 600) { ps_cleanup(); $clean = time(); }
    try {
        $job = amp_next_job();
        if (!$job) { sleep(1); continue; }
        $id = substr((string)$job['id'], 0, 8);
        $what = ($job['ext'] ?? '') === 'gcode' ? 'G-code check' : 'slice';
        amp_log("job $id: $what started — " . strtoupper((string)($job['ext'] ?? '?')) . ', ' . amp_job_size($job) . ', ' . amp_job_printer($job)
            . (!empty($job['parts']) && count($job['parts']) > 1 ? ', ' . count($job['parts']) . ' parts' : ''));
        $t0 = time();
        if (!ps_worker_run_one()) { sleep(1); continue; }
        $r = amp_job((string)$job['id']) ?: [];
        $took = amp_dur(max(0, (int)($r['finished'] ?? time()) - (int)($r['started'] ?? $t0)));
        if (($r['status'] ?? '') === 'done') {
            $s = is_array($r['stats'] ?? null) ? $r['stats'] : [];
            $bits = [];
            if (!empty($s['time'])) $bits[] = 'print time ' . amp_dur((int)$s['time'] * 60);   // stats.time in Minuten
            if (isset($s['grams']) && $s['grams'] !== null) $bits[] = round((float)$s['grams'], 1) . ' g';
            if (!empty($s['layers'])) $bits[] = (int)$s['layers'] . ' layers';
            amp_log("job $id: done in $took" . ($bits ? ' — ' . implode(', ', $bits) : ''));
        } else {
            $err = preg_replace('/\s+/', ' ', (string)($r['error'] ?? 'unknown error'));
            if (!empty($r['error_bed'])) $err .= ' (model does not fit the bed)';
            amp_log("job $id: FAILED after $took — " . mb_substr($err, 0, 300));
        }
    } catch (Throwable $e) {
        fwrite(STDERR, gmdate('Y-m-d H:i:s') . ' worker: ' . $e->getMessage() . "\n");
        sleep(2);
    }
}
