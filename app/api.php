<?php
/**
 * AllMyPrinters Slicer — HTTP-Schnittstelle (gleich wie 3DSEARCH api/amp_slice.php, damit die App nur die Adresse wechselt).
 *
 *   GET  ?a=version                        Name, Schnittstellen-Stufe, Orca-Version (Verbindungstest der App)
 *   GET  ?a=machines                       Orca-Druckerprofile (0,4 mm) je Hersteller
 *   GET  ?a=printer_info&model=…[&vendor&machine]   Profil zum Druckermodell, Fächerwechsel per T, Pause/Schichthöhen
 *   GET  ?a=xdef&vendor=…&machine=…        Standardwerte für die Experten-Einstellungen
 *   POST a=slice (multipart) + printer_json
 *   POST a=map  {job, map, printer}        Mehrfarbig: Filament → Fach
 *   GET  ?a=job&job=…                      Stand, dazu plan = Gramm je Fach
 *   GET  ?a=preview&job=…                  3D-Vorschau (binär, gzip)
 *   GET  ?a=gcode&job=…                    fertiger G-Code
 *
 * Kein Konto: das Gerät nennt eine zufällige Kennung (X-AMP-Device, 32 hex). Aufträge gehören nur dieser Kennung.
 * Umgebung (alle optional):
 *   AMP_LIMITS=1        Grenzen wie auf allmyprinters.com: je Gerät 40 Slices/Tag, je Adresse 80/Tag (Standard: aus)
 *   AMP_QUEUE_MAX=8     wartende/laufende Aufträge gesamt; je Gerät immer nur einer
 *   AMP_CORS=…          erlaubte Herkunft für Browser-Aufrufe (Standard: die App-WebView)
 *   AMP_TRUST_PROXY=1   Client-Adresse aus X-Real-IP (nur hinter eigenem Reverse-Proxy setzen)
 *   AMP_LOG=1           Protokoll /data/log/slice.log, 30 Tage
 */
require __DIR__ . '/slice_engine.php';

const AMP_API = 1;                 // Schnittstellen-Stufe; die App prüft sie beim Verbindungstest
const AMP_DAY_DEVICE = 40;
const AMP_DAY_IP = 80;
define('AMP_VERSION', trim((string)@file_get_contents(__DIR__ . '/VERSION')) ?: 'dev');
define('AMP_DATA', rtrim(getenv('AMP_DATA') ?: '/data', '/'));
define('AMP_ORIGIN', getenv('AMP_CORS') ?: 'https://appassets.androidplatform.net');

header('Vary: Origin');
if (($_SERVER['HTTP_ORIGIN'] ?? '') === AMP_ORIGIN) {
    header('Access-Control-Allow-Origin: ' . AMP_ORIGIN);
    header('Access-Control-Allow-Headers: X-AMP-Device, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Max-Age: 86400');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function amp_json($d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function amp_ip(): string {
    $ip = getenv('AMP_TRUST_PROXY') === '1' ? (string)($_SERVER['HTTP_X_REAL_IP'] ?? '') : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : (string)($_SERVER['REMOTE_ADDR'] ?? '-');
}
/** Protokoll höchstens 30 Tage (so steht es in der Datenschutzerklärung der App); gekürzt etwa einmal am Tag. */
function amp_log(string $dev, string $a, string $extra = ''): void {
    if (getenv('AMP_LOG') !== '1') return;
    $f = AMP_DATA . '/log/slice.log';
    @mkdir(dirname($f), 0750, true);
    @file_put_contents($f, gmdate('Y-m-d H:i:s') . ' ' . substr(hash('sha256', $dev), 0, 10) . ' ' . amp_ip() . " $a $extra\n", FILE_APPEND | LOCK_EX);
    $mark = "$f.pruned";
    if (@filemtime($mark) > time() - 86400) return;
    @touch($mark);
    $cut = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
    $fh = @fopen($f, 'c+'); if (!$fh) return;
    if (flock($fh, LOCK_EX)) {
        $keep = '';
        while (($l = fgets($fh)) !== false) if (substr($l, 0, 19) >= $cut) $keep .= $l;
        ftruncate($fh, 0); rewind($fh); fwrite($fh, $keep); fflush($fh); flock($fh, LOCK_UN);
    }
    fclose($fh);
}
/** Tageszähler (SQLite statt APCu: der eingebaute Server läuft mit mehreren Prozessen, APCu teilt nichts zwischen ihnen). */
function amp_count(string $key, int $max): bool {
    static $db = null;
    if (!$db) {
        $db = new PDO('sqlite:' . AMP_DATA . '/limits.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS hits (k TEXT NOT NULL, day TEXT NOT NULL, n INTEGER NOT NULL, PRIMARY KEY (k, day))');
    }
    $day = gmdate('Y-m-d');
    $db->beginTransaction();
    $db->prepare('INSERT INTO hits (k, day, n) VALUES (?, ?, 1) ON CONFLICT(k, day) DO UPDATE SET n = n + 1')->execute([$key, $day]);
    $st = $db->prepare('SELECT n FROM hits WHERE k = ? AND day = ?'); $st->execute([$key, $day]);
    $n = (int)$st->fetchColumn();
    if (random_int(1, 200) === 1) $db->prepare('DELETE FROM hits WHERE day < ?')->execute([gmdate('Y-m-d', time() - 2 * 86400)]);
    $db->commit();
    return $n <= $max;
}

$a = (string)($_GET['a'] ?? $_POST['a'] ?? '');
if ($a === 'version') {
    $orca = trim((string)@file_get_contents('/opt/orcaslicer/VERSION')) ?: null;
    // features: was dieser Container kann — die App sagt Selbsthostern, wenn eine ihrer Funktionen einen neueren Container braucht
    // (multi_start ab 1.0.3: Mehrfarbig für Drucker, deren Startbefehl die Fächer zuordnet — Bambu, Anycubic LAN, Elegoo, Creality, Flashforge)
    amp_json(['name' => 'allmyprinters-slicer', 'api' => AMP_API, 'version' => AMP_VERSION, 'orca' => $orca, 'limits' => getenv('AMP_LIMITS') === '1',
              'features' => ['multi_start']]);
}

$dev = strtolower((string)($_SERVER['HTTP_X_AMP_DEVICE'] ?? ''));
if (!preg_match('/^[0-9a-f]{32}$/', $dev)) amp_json(['error' => 'device'], 403);
// Pseudo-Nutzernummer (negativ) wie auf 3DSEARCH — ps_job_create legt Aufträge damit an
$uid = -1 - (crc32($dev) & 0x7fffffff);
$in = $_GET;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
        ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_POST;
    $a = (string)($in['a'] ?? $a);
}

/** Auftrag nur für das Gerät, das ihn angelegt hat. */
function amp_job(string $id, string $dev): ?array {
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) return null;
    $j = ps_job_load($id);
    return $j && hash_equals((string)($j['amp_dev'] ?? ''), $dev) ? $j : null;
}

/**
 * Druckerbeschreibung der App → dieselbe Form wie ps_printers() auf 3DSEARCH. Profil nur aus den echten Orca-Profilen,
 * Fächer bereinigt; Werkzeugwahl wie dort (ACE/MMU: per T, sonst nur das Fach von T0).
 */
function amp_printer(array $r): ?array {
    $vendor = (string)($r['vendor'] ?? ''); $machine = (string)($r['machine'] ?? '');
    if (!in_array($vendor, ps_vendors(), true) || !isset(ps_profile_index($vendor, 'machine')[$machine])) return null;
    $slots = []; $has_box = $any = false;
    foreach (array_slice((array)($r['slots'] ?? []), 0, 32) as $s) {
        if (!is_array($s)) continue;
        $i = (int)($s['index'] ?? -1); if ($i < 0 || $i > 255) continue;
        $mat = trim(mb_substr((string)($s['material'] ?? ''), 0, 30)) ?: null;
        $col = preg_match('/^#[0-9a-f]{6}$/i', (string)($s['color'] ?? '')) ? strtolower($s['color']) : null;
        if ($mat && empty($s['single'])) $has_box = true;
        if ($mat) $any = true;
        $slots[] = ['index' => $i, 'label' => mb_substr((string)($s['label'] ?? $i + 1), 0, 8), 'material' => $mat, 'color' => $col, 'remain' => null, 'locked' => false];
    }
    $tool0 = $ttg = null;
    // Bambu/Elegoo/Creality/Flashforge/Anycubic-LAN: das Fach setzt der Startbefehl — kein eingefügtes T<n>, kein Mehrfarb-Umschreiben
    $own = in_array((string)($r['kind'] ?? ''), ['bambu', 'sdcp', 'cc2', 'creality', 'flashforge', 'acl'], true);
    if ($has_box && !$own) {
        $ttg = array_map('intval', array_slice((array)($r['ttg'] ?? []), 0, 32)) ?: array_column($slots, 'index');
        if (!ps_machine_switches_by_t($vendor, $machine)) $tool0 = $ttg[0] ?? 0;
        foreach ($slots as &$s) $s['locked'] = ($tool0 !== null && $s['index'] !== $tool0) || ($tool0 === null && !in_array($s['index'], $ttg, true));
        unset($s);
    }
    $id = preg_replace('/[^\w-]/', '', (string)($r['id'] ?? 'p')) ?: 'p';
    return ['key' => "moonraker:$id", 'provider' => 'moonraker', 'id' => $id, 'via' => 'moonraker', 'name' => mb_substr((string)($r['name'] ?? 'Printer'), 0, 60),
            'model' => mb_substr((string)($r['model'] ?? ''), 0, 60), 'online' => true, 'busy' => false, 'img' => '', 'can_print' => true, 'testers_only' => false,
            'box' => $has_box, 'slots' => $slots, 'slot_mode' => $any ? 'slots' : 'material', 'tool0' => $tool0, 'ttg' => $tool0 === null ? $ttg : null,
            'machine' => $machine, 'vendor' => $vendor, 'exact' => true, 'chosen' => true, 'level' => ['mode' => 'none'], 'cfs' => false,
            'caps' => ps_machine_caps($vendor, $machine),
            // Mehrfarbig auch, wo der Startbefehl die Fächer zuordnet (10.11.2026, User: „kommt keine Farbzuordnung“): Anycubic LAN mit T
            // lückenlos wie die Anycubic-Cloud ('seq'), Bambu/Elegoo/Creality/Flashforge mit T wie von Orca ('keep') — ps_slice/ps_job_paint
            'multi' => $has_box && ($own || ($tool0 === null && $ttg !== null))] + ($has_box && $own ? ['tools' => (string)$r['kind'] === 'acl' ? 'seq' : 'keep'] : []);
}

// ── Lesen ────────────────────────────────────────────────────────────────────
if ($a === 'machines') amp_json(ps_machine_list());

if ($a === 'printer_info') {
    $v = (string)($in['vendor'] ?? ''); $m = (string)($in['machine'] ?? '');
    $prof = $v && $m && in_array($v, ps_vendors(), true) && isset(ps_profile_index($v, 'machine')[$m]) ? [$v, $m, true]
        : ps_machine_for('moonraker', mb_substr((string)($in['model'] ?? ''), 0, 80));
    amp_json(['vendor' => $prof[0] ?? null, 'machine' => $prof[1] ?? null, 'exact' => $prof[2] ?? false,
              'switches_by_t' => $prof ? ps_machine_switches_by_t($prof[0], $prof[1]) : null,
              'caps' => $prof ? ps_machine_caps($prof[0], $prof[1]) : null, 'materials' => PS_MATERIALS]);
}

if ($a === 'xdef') {
    $v = (string)($in['vendor'] ?? ''); $m = (string)($in['machine'] ?? '');
    if (!in_array($v, ps_vendors(), true) || !isset(ps_profile_index($v, 'machine')[$m])) amp_json(['vals' => (object)[]]);
    $vals = [];
    try {
        $pr = ps_flatten($v, 'process', ps_process_for($v, $m));
        foreach (array_keys(PS_X_PROC) as $k) if (isset($pr[$k]) && !is_array($pr[$k])) $vals[$k] = (string)$pr[$k];
        if (($pr['support_style'] ?? '') === 'organic' && ($vals['support_type'] ?? '') === 'tree(auto)') $vals['support_type'] = 'organic';
    } catch (Throwable $e) { /* ohne Platzhalter */ }
    amp_json(['vals' => (object)$vals, 'caps' => ps_machine_caps($v, $m)]);
}

if ($a === 'job' || $a === 'preview' || $a === 'gcode') {
    $job = amp_job((string)($in['job'] ?? ''), $dev);
    if (!$job) amp_json(['error' => 'not_found'], 404);
    $dir = ps_job_dir($job['id']);
    if ($a === 'preview') {
        $f = "$dir/preview.bin.gz";
        if ($job['status'] !== 'done' || !is_file($f)) amp_json(['error' => 'not_ready'], 404);
        header('Content-Type: application/octet-stream');
        header('Content-Encoding: gzip');
        header('Content-Length: ' . filesize($f));
        header('Cache-Control: private, max-age=3600, no-transform');
        readfile($f);
        exit;
    }
    if ($a === 'gcode') {
        $f = "$dir/out/plate_1.gcode";
        if ($job['status'] !== 'done' || !is_file($f)) amp_json(['error' => 'not_ready'], 404);
        amp_log($dev, 'gcode', $job['id'] . ' ' . filesize($f));
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($f));
        header('Content-Disposition: attachment; filename="' . str_replace('3DSEARCH_', 'AMP_', ps_file_name($job)) . '"');
        readfile($f);
        exit;
    }
    $out = ['status' => $job['status'], 'job_error' => $job['error'] ?? null, 'job_bed' => $job['error_bed'] ?? null, 'job_fit' => $job['error_fit'] ?? null, 'sent' => false,
            'bed' => $job['bed'] ?? 'textured', 'name' => $job['name']];
    if (($job['ext'] ?? '') === 'gcode') $out['gcode'] = ['printer' => $job['gcode']['printer'] ?? '', 'mismatch' => $job['gcode']['mismatch'] ?? null, 'mat' => $job['gcode']['mat_mismatch'] ?? null];
    if ($job['status'] === 'slicing') { $out['elapsed'] = time() - (int)$job['started']; $out['progress'] = ps_progress($job['id']); }
    if ($job['status'] === 'queued') {
        $ahead = 0;
        foreach (glob(PS_DIR . '/*/job.json') ?: [] as $f) {
            $o = json_decode((string)file_get_contents($f), true);
            if (in_array($o['status'] ?? '', ['queued', 'slicing'], true) && $o['created'] < $job['created']) $ahead++;
        }
        $out['ahead'] = $ahead;
    }
    if ($job['status'] === 'done') {
        $out += ['stats' => $job['stats'], 'cost' => null, 'profiles' => $job['profiles'], 'fallback' => $job['fallback'] ?? null, 'upload_only' => true,
                 'paint' => ps_paint_view($job), 'mix_temp' => $job['mix_temp'] ?? null, 'skipped_objs' => count($job['info3mf']['skip'] ?? []),
                 'rearranged' => !empty($job['rearranged']), 'x_applied' => count($job['x_applied'] ?? []), 'x_clamped' => $job['x_clamped'] ?? null,
                 'pause' => $job['x']['pause'] ?? [], 'scale' => $job['x']['scale'] ?? 1, 'objs' => array_sum(array_column($job['parts'] ?? [], 'copies')),
                 'files' => count($job['parts'] ?? []), 'plan' => ps_job_paint($job), 'size' => @filesize(ps_job_dir($job['id']) . '/out/plate_1.gcode') ?: null];
    }
    amp_json($out);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') amp_json(['error' => 'unknown'], 400);

if ($a === 'slice') {
    $printer = amp_printer(json_decode((string)($in['printer_json'] ?? ''), true) ?: []);
    if (!$printer) amp_json(['error' => 'no_profile']);
    // je Gerät nur ein offener Auftrag, gesamt höchstens AMP_QUEUE_MAX
    $mine = $open = 0;
    foreach (glob(PS_DIR . '/*/job.json') ?: [] as $f) {
        $o = json_decode((string)file_get_contents($f), true);
        if (!in_array($o['status'] ?? '', ['queued', 'slicing', 'mapping'], true) || empty($o['amp_dev'])) continue;
        if ($o['status'] !== 'mapping') $open++;
        if ($o['amp_dev'] === $dev && $o['status'] !== 'mapping') $mine++;
    }
    if ($mine >= 1 || $open >= max(1, (int)(getenv('AMP_QUEUE_MAX') ?: 8))) amp_json(['error' => 'queue_full']);
    if (getenv('AMP_LIMITS') === '1' && (!amp_count('dev:' . $dev, AMP_DAY_DEVICE) || !amp_count('ip:' . amp_ip(), AMP_DAY_IP))) amp_json(['error' => 'too_many']);
    $ups = [];
    if (is_array($_FILES['models']['name'] ?? null)) {
        foreach ($_FILES['models']['name'] as $i => $n)
            $ups[] = ['name' => $n, 'type' => $_FILES['models']['type'][$i] ?? '', 'tmp_name' => $_FILES['models']['tmp_name'][$i] ?? '',
                      'error' => $_FILES['models']['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => (int)($_FILES['models']['size'][$i] ?? 0)];
    }
    $main = $ups ? array_shift($ups) : ($_FILES['model'] ?? []);
    $sup = $in['supports'] ?? 'off';
    $x = [];
    foreach ($in as $k => $v) if (is_string($k) && str_starts_with($k, 'x_') && is_string($v)) $x[substr($k, 2)] = mb_substr($v, 0, 60);
    // ps_job_create will eine Datenbank (auf 3DSEARCH für Tester-Prüfungen) — hier nicht gebraucht, eine leere im Speicher genügt
    $r = ps_job_create(new PDO('sqlite::memory:'), $uid, $printer, $main, ['slot' => (int)($in['slot'] ?? -1), 'bed' => $in['bed'] ?? 'textured', 'level' => '',
        'plate' => (int)($in['plate'] ?? 1), 'skip' => (string)($in['skip'] ?? ''), 'arrange' => !empty($in['arrange']), 'supports' => $sup === 'on',
        'supports_project' => $sup === 'project', 'material' => (string)($in['material'] ?? ''), 'more' => $ups,
        'copies' => is_array($in['copies'] ?? null) ? $in['copies'] : [], 'fslot' => is_array($in['fslot'] ?? null) ? $in['fslot'] : [], 'x' => $x]);
    if (is_string($r)) { amp_log($dev, 'slice_err', $r); amp_json(['error' => $r]); }
    $r['amp_dev'] = $dev;
    if ($sup === 'project' && $r['info3mf']) $r['supports'] = !empty($r['info3mf']['supports']) || !empty($r['info3mf']['painted']);
    ps_job_save($r);
    amp_log($dev, 'slice', $r['id'] . ' ' . $r['ext'] . ' ' . (int)($main['size'] ?? 0) . ' ' . $printer['vendor'] . '|' . $printer['machine']);
    amp_json(['ok' => true, 'job' => $r['id'], 'supports' => $r['supports'], 'gcode' => !empty($r['gcode']),
              'info3mf' => $r['info3mf'] ? array_diff_key($r['info3mf'], ['keep' => 1]) + ['keep' => array_keys($r['info3mf']['keep'])] : null,
              'mapping' => $r['status'] === 'mapping' ? ps_map_view($r, $printer) : null]);
}

if ($a === 'map') {
    $job = amp_job((string)($in['job'] ?? ''), $dev);
    if (!$job) amp_json(['error' => 'not_found']);
    $printer = amp_printer(is_array($in['printer'] ?? null) ? $in['printer'] : []);
    if (!$printer) amp_json(['error' => 'no_profile']);
    $err = ps_job_set_map($job, $printer, (array)($in['map'] ?? []));
    amp_json($err ? ['error' => $err] : ['ok' => true]);
}

amp_json(['error' => 'unknown'], 400);
