<?php
/**
 * Erzeugt app/slice_engine.php aus 3DSEARCH public_html/print_slice_lib.php — eine Quelle für Website und Container.
 *
 *   php tools/sync_engine.php [/pfad/zu/print_slice_lib.php]
 *
 * Übernommen wird nur, was die Schnittstelle (app/api.php) und der Worker (app/worker.php) brauchen: die Wurzel-Funktionen
 * unten plus alles, was sie (auch über weitere Funktionen) aufrufen, und die Konstanten, die darin vorkommen. Senden an
 * Drucker-Clouds, Dateibibliothek, Tester-Listen u. Ä. bleiben draußen. Bricht ab, wenn der übernommene Teil doch Cloud-
 * (pc_*) oder Datenbankfunktionen braucht — dann ist in der Website etwas umgebaut worden, das hier nachgezogen werden muss.
 */
$src = $argv[1] ?? __DIR__ . '/../../public_html/print_slice_lib.php';
$out = __DIR__ . '/../app/slice_engine.php';
$code = @file_get_contents($src);
if ($code === false) { fwrite(STDERR, "nicht lesbar: $src\n"); exit(1); }

// Wurzeln: was app/api.php und app/worker.php direkt aufrufen
const ROOTS = ['ps_machine_list', 'ps_vendors', 'ps_profile_index', 'ps_machine_for', 'ps_machine_switches_by_t', 'ps_machine_caps',
    'ps_flatten', 'ps_process_for', 'ps_job_load', 'ps_job_dir', 'ps_file_name', 'ps_progress', 'ps_paint_view', 'ps_job_paint',
    'ps_job_create', 'ps_job_save', 'ps_map_view', 'ps_job_set_map', 'ps_worker_run_one', 'ps_cleanup'];
const ROOT_CONSTS = ['PS_DIR', 'PS_MATERIALS', 'PS_X_PROC', 'PS_ORCA'];
// Pfade der Website → Ordner im Container (Datenträger /data)
const PATHS = ['PS_DIR' => '/data/jobs', 'PS_PROFILE_DIR' => '/data/profiles', 'PS_THUMB_DIR' => '/data/thumbs', 'PS_FILES_DIR' => '/data/files'];

// ── oberste Ebene zerlegen: Funktionen (samt Doku-Kommentar davor) und Konstanten ──
$t = token_get_all($code);
$funcs = $consts = [];
$depth = 0; $buf = ''; $doc = ''; $kind = null; $name = null;
foreach ($t as $x) {
    $s = is_array($x) ? $x[1] : $x;
    if ($depth === 0 && $kind === null) {
        if (is_array($x) && in_array($x[0], [T_DOC_COMMENT, T_COMMENT], true)) { $doc .= $s . "\n"; continue; }
        if (is_array($x) && in_array($x[0], [T_WHITESPACE, T_OPEN_TAG], true)) { if (substr_count($s, "\n") > 1) $doc = ''; continue; }
        if (is_array($x) && $x[0] === T_FUNCTION) $kind = 'function';
        elseif (is_array($x) && $x[0] === T_CONST) $kind = 'const';
        else $kind = 'other';
    }
    if ($kind === null) continue;
    $buf .= $s;
    if ($name === null && is_array($x) && $x[0] === T_STRING) $name = $x[1];
    if ($x === '{' || (is_array($x) && in_array($x[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $depth++;
    if ($x === '}') $depth--;
    $end = $depth === 0 && (($kind === 'function' && $x === '}') || ($kind !== 'function' && $x === ';'));
    if ($end) {
        if ($kind === 'function') $funcs[$name] = rtrim($doc) . ($doc !== '' ? "\n" : '') . $buf;
        elseif ($kind === 'const') $consts[$name] = rtrim($doc) . ($doc !== '' ? "\n" : '') . $buf;
        elseif (!preg_match('/^require_once\s+__DIR__\s*\.\s*\'\/printer_cloud_lib\.php\'\s*;$/', trim($buf))) {
            fwrite(STDERR, "unerwarteter Code auf oberster Ebene: " . substr(trim($buf), 0, 120) . "\n"); exit(1);
        }
        $buf = $doc = ''; $kind = $name = null;
    }
}

// Code ohne Kommentare (Erwähnungen wie „ps_printers()“ in einem Doku-Kommentar sind kein Aufruf)
$bare = fn(string $body): string => implode('', array_map(fn($x) => is_array($x) ? (in_array($x[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $x[1]) : $x,
    array_slice(token_get_all("<?php\n" . $body), 1)));

// ── Hülle: was von den Wurzeln aus erreichbar ist ──
$need = []; $needC = array_fill_keys(ROOT_CONSTS, true); $todo = ROOTS;
while ($todo) {
    $f = array_pop($todo);
    if (isset($need[$f])) continue;
    if (!isset($funcs[$f])) { fwrite(STDERR, "Funktion fehlt in der Quelle: $f\n"); exit(1); }
    $need[$f] = true;
    $b = $bare($funcs[$f]);
    preg_match_all("/\b(ps_[a-z0-9_]+)\s*\(|'(ps_[a-z0-9_]+)'/", $b, $m);
    foreach (array_filter(array_merge($m[1], $m[2])) as $g) if (isset($funcs[$g]) && !isset($need[$g])) $todo[] = $g;
    preg_match_all('/\b(PS_[A-Z0-9_]+)\b/', $b, $m);
    foreach ($m[1] as $c) $needC[$c] = true;
}
// Konstanten, die in Konstanten vorkommen
do {
    $n = count($needC);
    foreach (array_keys($needC) as $c) if (isset($consts[$c]) && preg_match_all('/\b(PS_[A-Z0-9_]+)\b/', $bare($consts[$c]), $m)) foreach ($m[1] as $d) $needC[$d] = true;
} while (count($needC) > $n);

// ── Prüfen: nichts aus Cloud/Datenbank ──
$bad = [];
foreach (array_keys($need) as $f) {
    if (preg_match_all('/\b(pc_[a-z0-9_]+|get_db|auth_user|mail_send)\s*\(|\$pdo\s*->/', $bare($funcs[$f]), $m)) $bad[$f] = array_unique($m[0]);
}
if ($bad) { foreach ($bad as $f => $c) fwrite(STDERR, "$f braucht " . implode(', ', $c) . "\n"); exit(1); }

// ── Schreiben: Quelle in Reihenfolge der Website-Datei ──
$h = "<?php\n/**\n * AllMyPrinters Slicer — Slice-Kern, GENERIERT aus 3DSEARCH print_slice_lib.php (tools/sync_engine.php). Nicht von Hand ändern.\n"
   . " * Quelle: " . basename($src) . ', sha256 ' . substr(hash('sha256', $code), 0, 16) . ', ' . gmdate('Y-m-d H:i') . " UTC\n"
   . ' * ' . count($need) . ' von ' . count($funcs) . ' Funktionen, ' . count(array_intersect_key($consts, $needC)) . " Konstanten.\n */\n\n";
foreach ($consts as $c => $body) {
    if (!isset($needC[$c])) continue;
    if (isset(PATHS[$c])) { $h .= "define('$c', rtrim(getenv('AMP_DATA') ?: '/data', '/') . '" . substr(PATHS[$c], 5) . "');\n"; continue; }
    if (str_contains($body, '__DIR__')) { fwrite(STDERR, "Konstante mit Website-Pfad ohne Zuordnung: $c\n"); exit(1); }
    $h .= $body . "\n";
}
$h .= "\n";
foreach ($funcs as $f => $body) if (isset($need[$f])) $h .= $body . "\n\n";
$missingC = array_diff(array_keys($needC), array_keys($consts));
if ($missingC) { fwrite(STDERR, 'Konstanten fehlen: ' . implode(', ', $missingC) . "\n"); exit(1); }
file_put_contents($out . '.tmp', $h);
exec('php -l ' . escapeshellarg($out . '.tmp') . ' 2>&1', $lo, $rc);
if ($rc) { fwrite(STDERR, implode("\n", $lo) . "\n"); @unlink($out . '.tmp'); exit(1); }
rename($out . '.tmp', $out);
echo 'app/slice_engine.php: ' . count($need) . ' Funktionen (' . implode(' ', array_diff(array_keys($funcs), array_keys($need))) . " bleiben draußen)\n";
