<?php
/**
 * AllMyPrinters Slicer — Slice-Kern, GENERIERT aus 3DSEARCH print_slice_lib.php (tools/sync_engine.php). Nicht von Hand ändern.
 * Quelle: print_slice_lib.php, sha256 a1ad2a73cd756062, 2026-11-26 06:55 UTC
 * 76 von 116 Funktionen, 19 Konstanten.
 */

define('PS_DIR', rtrim(getenv('AMP_DATA') ?: '/data', '/') . '/jobs');
const PS_ORCA = '/opt/orcaslicer/squashfs-root/AppRun';
const PS_PROFILES = '/opt/orcaslicer/squashfs-root/resources/profiles';
const PS_MAX_BYTES = 80 * 1024 * 1024;
const PS_TIMEOUT = 240;
// Sekunden je Slice
const PS_KEEP_HOURS = 24;
// Eigener G-Code (16.10.2026, Wunsch Lukas): fertig geslicte Datei hochladen → nicht neu slicen, nur Vorschau, dann senden.
// Vorerst einfarbig (ein Werkzeug); mehrfarbige G-Codes bräuchten eine Zuordnung je Werkzeug → dafür die 3MF nehmen.
const PS_GCODE_EXT = ['gcode', 'gco', 'g'];
const PS_MATERIALS = ['PLA', 'PLA+', 'PLA Silk', 'PETG', 'ABS', 'ASA', 'TPU'];
// vom Nutzer gewählte Druckerprofile (Klipper ohne Modell)
const PS_BED_TYPES = ['textured' => 'Textured PEI Plate', 'smooth' => 'High Temp Plate'];
const PS_MIX_TEMP_NOTE = 25;
// ab so viel °C Unterschied zwischen benutzten Filamenten ein Hinweis nach dem Slicen
// Orcas Fortschrittsmeldungen (--pipe) → Schritt für die Anzeige; Reihenfolge zählt („Generating infill toolpath“ = Füllung)
const PS_PROGRESS_STEPS = ['load' => '/load files|prepare slicing|slicing begins/i', 'mesh' => '/slicing mesh/i', 'walls' => '/walls|perimeter/i',
    'infill' => '/infill/i', 'support' => '/support/i', 'optimize' => '/overhang|optimiz/i', 'gcode' => '/g-?code/i', 'finish' => '/finished|all done/i'];
/** Designer-Werte aus einer 3MF, die wir in unser Prozessprofil übernehmen. */
const PS_3MF_KEEP = ['enable_support', 'support_type', 'support_style', 'support_threshold_angle', 'support_on_build_plate_only',
    'support_critical_regions_only', 'support_remove_small_overhang', 'sparse_infill_density', 'sparse_infill_pattern', 'wall_loops',
    'top_shell_layers', 'bottom_shell_layers', 'brim_type', 'brim_width', 'seam_position', 'layer_height', 'initial_layer_print_height',
    // Wischturm: die Herstellerprofile setzen ihn fest an — abgeschaltet in der 3MF wurde er sonst wieder eingeschaltet (07.10., Feedback #43)
    'enable_prime_tower', 'prime_tower_width', 'prime_volume',
    // Skirt: „Anycubic Kobra“ setzt 4 Runden, die 3MF hatte 0 — kam trotzdem mit (07.10., Denny/Kobra Go)
    'skirt_loops', 'skirt_distance', 'skirt_height', 'skirt_type', 'min_skirt_length', 'draft_shield',
    // Stützen/Kontaktschicht aus eigenem Filament (z. B. PETG unter PLA) samt Abständen — die Filament-Nummern prüft
    // ps_job_support_fils, bevor sie Orca erreichen (08.10.2026, Feedback #43, Denny: CoPE als Kontaktschicht)
    'support_filament', 'support_interface_filament', 'support_top_z_distance', 'support_bottom_z_distance',
    'support_interface_top_layers', 'support_interface_bottom_layers', 'support_interface_spacing', 'support_interface_pattern',
    'support_object_xy_distance', 'support_interface_not_for_body'];
// Experten-Einstellungen (23.10.2026, User: „alles, was die Orca-CLI hergibt, plus Stützen und mehrere STL auf einer
// Platte, aufklappbar“). Prozesswerte landen in p.json und gewinnen über Herstellerprofil und 3MF; leer = wie bisher.
// Art: f = Zahl (min, max), i = ganze Zahl, pct = Prozent, b = ja/nein, e = Liste erlaubter Orca-Werte.
const PS_X_PROC = [
    'layer_height' => ['f', 0.04, 0.6],   // zusätzlich auf min/max_layer_height des Druckers begrenzt (ps_slice)
    'wall_loops' => ['i', 1, 20], 'top_shell_layers' => ['i', 0, 50], 'bottom_shell_layers' => ['i', 0, 50],
    'sparse_infill_density' => ['pct', 0, 100],
    'sparse_infill_pattern' => ['e', ['grid', 'gyroid', 'honeycomb', 'cubic', 'adaptivecubic', 'triangles', 'tri-hexagon', 'rectilinear', 'line', 'lightning', 'crosshatch', '3dhoneycomb']],
    'seam_position' => ['e', ['aligned', 'nearest', 'back', 'random']],
    'ironing_type' => ['e', ['no ironing', 'top', 'topmost', 'solid']],
    'fuzzy_skin' => ['e', ['none', 'external', 'all']],
    'print_sequence' => ['e', ['by layer', 'by object']],
    // „organic“ gibt es als Stützenart nicht — das ist tree(auto) mit support_style organic (ps_x_clean)
    'support_type' => ['e', ['tree(auto)', 'normal(auto)', 'organic']],
    'support_threshold_angle' => ['i', 0, 90], 'support_on_build_plate_only' => ['b'],
    'support_top_z_distance' => ['f', 0, 1], 'support_bottom_z_distance' => ['f', 0, 1], 'support_object_xy_distance' => ['f', 0, 5],
    'support_interface_top_layers' => ['i', 0, 10],
    'brim_type' => ['e', ['auto_brim', 'outer_only', 'inner_only', 'outer_and_inner', 'brim_ears', 'no_brim']],
    'brim_width' => ['f', 0, 30], 'skirt_loops' => ['i', 0, 10],
    // Reinigungsturm an/aus (25.11.2026, User: „einstellen, ob ich den Reinigungsturm drucken will“); leer = Profil bzw. 3MF
    'enable_prime_tower' => ['b'],
];
// Temperatur (15.11.2026, Community-Wünsche „Set nozzle and bed temperature“): überschreibt das Filamentprofil — gilt für alle
// Filamente des Drucks. Leer = aus dem Profil. Düse auch für die Stahl-/Messing-Varianten (Orca 2.5: nozzle_temperature_HS/_BRASS).
const PS_X_FIL = ['nozzle_temperature' => ['i', 150, 350], 'nozzle_temperature_initial_layer' => ['i', 150, 350], 'bed_temperature' => ['i', 0, 130]];
const PS_STL_MAX = 20;
// STL-Dateien je Platte (PHP max_file_uploads = 20)
const PS_STL_MAX_OBJS = 200;
// Teile je Platte samt Kopien (Orca erlaubt 512 Kopien je Datei)
const PS_PAUSE_MAX = 5;
const PS_PREVIEW_CATS = ['outer', 'inner', 'infill', 'solid', 'support', 'brim', 'other'];
define('PS_THUMB_DIR', rtrim(getenv('AMP_DATA') ?: '/data', '/') . '/thumbs');

/** Alle Profile eines Herstellers und Typs: Name => Datei. */
function ps_profile_index(string $vendor, string $kind): array {
    return array_map(fn($e) => $e['file'], ps_profile_meta($vendor, $kind));
}

/** Name => [file, compat, cond, inst]; compat = compatible_printers des Profils selbst (nicht geerbt),
 *  cond = compatible_printers_condition (Prusa ordnet so zu, 15.11.2026), inst = instantiation != "false". */
function ps_profile_meta(string $vendor, string $kind): array {
    static $cache = [];
    $key = "$vendor/$kind";
    if (isset($cache[$key])) return $cache[$key];
    $idx = [];
    // Auch eine Ebene tiefer: OrcaFilamentLibrary legt die Grundprofile (fdm_filament_pla …) in filament/base/ ab —
    // ohne sie scheiterte jeder Drucker ohne eigene Filamente (Voron & Co.) mit „Profil fehlt: filament/fdm_filament_pla“
    foreach (array_merge(glob(PS_PROFILES . '/' . $vendor . '/' . $kind . '/*.json') ?: [], glob(PS_PROFILES . '/' . $vendor . '/' . $kind . '/*/*.json') ?: []) as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (!empty($d['name']) && !isset($idx[$d['name']])) $idx[$d['name']] = ['file' => $f, 'compat' => (array)($d['compatible_printers'] ?? []),
            'cond' => trim((string)($d['compatible_printers_condition'] ?? '')), 'inst' => ($d['instantiation'] ?? 'true') !== 'false'];
    }
    return $cache[$key] = $idx;
}

/** Profile eines Typs, die zur Maschine passen (Anycubic benennt uneinheitlich: „@Kobra X“ vs. „@Kobra 3 0.4 nozzle“). */
function ps_profiles_for(string $vendor, string $kind, string $machine): array {
    $out = [];
    $mach = null;
    foreach (ps_profile_meta($vendor, $kind) as $n => $e) {
        if (in_array($machine, $e['compat'], true) || str_ends_with($n, "@$machine")) { $out[] = $n; continue; }
        // Prusa (MK4S, CORE One …): keine Druckerliste, sondern eine Bedingung über das Maschinenprofil (15.11.2026, vorher „Kein Prozessprofil“)
        if (!$e['compat'] && $e['cond'] !== '' && $e['inst']) {
            $mach ??= (function () use ($vendor, $machine) { try { return ps_flatten($vendor, 'machine', $machine); } catch (Throwable $x) { return []; } })();
            if ($mach && ps_cond_match($e['cond'], $mach)) $out[] = $n;
        }
    }
    return $out;
}

/**
 * compatible_printers_condition auswerten — so weit, wie es in den Orca-Profilen vorkommt (geprüft 15.11.2026, nur Prusa/process):
 * printer_notes=~/re/, printer_notes!~/re/, nozzle_diameter[0]==0.4 (auch !=), single_extruder_multi_material (auch !…), mit „and“ verknüpft.
 * Unbekannte Form (or, Klammern, Vergleiche mit Text) → false: lieber kein Profil als ein falsches.
 */
function ps_cond_match(string $cond, array $m): bool {
    if (preg_match('/\bor\b|[()]/', $cond)) return false;
    foreach (preg_split('/\s+and\s+/', trim($cond)) as $c) {
        $c = trim($c);
        if (preg_match('@^(\w+)\s*(=~|!~)\s*/(.*)/$@', $c, $x)) {
            $v = $m[$x[1]] ?? '';
            $v = is_array($v) ? implode("\n", $v) : (string)$v;
            $hit = @preg_match('~' . str_replace('~', '\~', $x[3]) . '~s', $v);
            if ($hit === false || ($x[2] === '=~') !== ($hit === 1)) return false;
        } elseif (preg_match('~^(\w+)\[(\d+)\]\s*(==|!=)\s*([0-9.]+)$~', $c, $x)) {
            $a = (array)($m[$x[1]] ?? []);
            $eq = abs((float)($a[(int)$x[2]] ?? $a[0] ?? -1) - (float)$x[4]) < 1e-6;
            if (($x[3] === '==') !== $eq) return false;
        } elseif (preg_match('~^(!?)(\w+)$~', $c, $x)) {
            $b = $m[$x[2]] ?? '0';
            $on = in_array(is_array($b) ? (string)($b[0] ?? '0') : (string)$b, ['1', 'true'], true);
            if (($x[1] === '!') === $on) return false;
        } else return false;
    }
    return true;
}

/** Profil mit aufgelöster inherits-Kette, als „User“-Profil. */
function ps_flatten(string $vendor, string $kind, string $name, array $over = []): array {
    $idx = ps_profile_index($vendor, $kind);
    $chain = [];
    for ($n = $name; $n !== ''; ) {
        if (!isset($idx[$n]) || count($chain) > 20) throw new RuntimeException("Profil fehlt: $kind/$n");
        $d = json_decode((string)file_get_contents($idx[$n]), true);
        $chain[] = $d;
        $n = (string)($d['inherits'] ?? '');
    }
    // Orca ab 2.5: „include“ = Vorlagen auf derselben Stufe, die eigenen Werte des Profils gehen vor. Bambu legt Start-, End-,
    // Wechsel-, Schicht- und Zeitraffer-G-Code je Drucker in solche Dateien („… template machine_start_gcode“), Filamente ihre
    // Rückzugswerte je Düsenart. Ohne Auflösen bekam jeder Bambu den allgemeinen Start-G-Code aus fdm_machine_common — kein
    // Laden aus dem AMS (M620/T/M621), fest 205 °C → P1S druckte ohne Filament (Meldung #22, 17.11.2026; seit Slicer 1.0.4).
    $res = [];
    foreach (array_reverse($chain) as $d) {
        foreach ((array)($d['include'] ?? []) as $i) {
            if (!isset($idx[$i])) throw new RuntimeException("Vorlage fehlt: $kind/$i");
            $t = json_decode((string)file_get_contents($idx[$i]), true) ?: [];
            $res = array_merge($res, array_diff_key($t, array_flip(['name', 'type', 'from', 'instantiation', 'inherits', 'include', 'setting_id'])));
        }
        $res = array_merge($res, $d);
    }
    // „nil“ ist bei den Rückzugs-Überschreibungen der Filamente gültig (= Wert des Druckers), bei diesen beiden nicht: Anycubic
    // Kobra S1 Max 0.6/0.8 (46 Profile) → CLI „filament_flush_temp: nil not in range“, rc -18 (15.11.2026). Weglassen = Orca-Standard.
    foreach (['filament_flush_temp', 'filament_flush_volumetric_speed'] as $k)
        if (isset($res[$k]) && ($res[$k] === 'nil' || (is_array($res[$k]) && in_array('nil', $res[$k], true)))) unset($res[$k]);
    unset($res['inherits'], $res['include']);
    $res['from'] = 'User';
    if ($kind !== 'machine') { $res['compatible_printers'] = []; $res['compatible_printers_condition'] = ''; }
    return array_merge($res, $over);
}

/**
 * Maschinenprofil zum Modellnamen aus der Cloud („Anycubic Kobra S1“ → „Anycubic Kobra S1 0.4 nozzle“).
 * Rückgabe [vendor, machine, exact]; exact=false heißt „ähnliches Profil“ (z. B. Kobra 3 V2 → Kobra 3).
 */
function ps_machine_for(string $provider, string $model): ?array {
    $vendor = ['anycubic' => 'Anycubic', 'creality' => 'Creality', 'bambu' => 'BBL'][$provider] ?? null;
    // Klipper: Hersteller steht vorn im Modellnamen, den der Agent meldet („Anycubic Kobra S1“, „Creality K1 Max“)
    if ($provider === 'moonraker') {
        foreach (ps_vendors() as $v) if (stripos($model, "$v ") === 0) { $vendor = $v; break; }
    }
    if (!$vendor || $model === '') return null;
    $idx = ps_profile_index($vendor, 'machine');
    if (stripos($model, $vendor) !== 0 && $vendor !== 'BBL') $model = "$vendor $model";
    $try = [$model];
    $short = trim(preg_replace('/\s+(V\d+|Combo|SE)$/i', '', $model));
    if ($short !== $model) $try[] = $short;
    foreach ($try as $i => $m) {
        // Creality K1: „Creality K1 (0.4 nozzle)“ statt „… 0.4 nozzle“
        foreach (["$m 0.4 nozzle", "$m (0.4 nozzle)"] as $n) if (isset($idx[$n])) return [$vendor, $n, $i === 0];
    }
    return null;
}

/**
 * Wechselt dieses Maschinenprofil Fächer per T-Befehl? Ja, wenn change_filament_gcode „T[next_extruder]“ enthält
 * (Kobra S1/S1 Max/X) oder leer ist (Kobra 3 — dann schreibt Orca das T selbst). T0…Tn ist die übliche
 * ACE/MMU-Schnittstelle unter Klipper (Rinkhals, Happy Hare). Nein nur bei eigenem Wechsel-Code ohne T.
 * Auch per Skript geschrieben („"T" + next_extruder“: Snapmaker U1, Prusa XL 5T) — vorher als „kein T“ gewertet, dann war beim
 * U1 im Slicen nur Kopf 1 wählbar (Meldung #33, 24.11.2026).
 */
function ps_machine_switches_by_t(string $vendor, string $machine): bool {
    static $cache = [];
    if (!isset($cache["$vendor|$machine"])) {
        try { $g = (string)(ps_flatten($vendor, 'machine', $machine)['change_filament_gcode'] ?? ''); } catch (Throwable $e) { $g = ''; }
        $code = trim(implode("\n", array_filter(array_map('trim', explode("\n", $g)), fn($l) => $l !== '' && $l[0] !== ';')));
        $cache["$vendor|$machine"] = $code === '' || (bool)preg_match('/^T[\[{]next_extruder[\]}]/m', $code)
            || (bool)preg_match('/["\']T["\']\s*\+\s*next_extruder\b/', $code);
    }
    return $cache["$vendor|$machine"];
}

/** Herstellerordner der Orca-Profile (längste zuerst, damit „Creality“ nicht an „Creality CR-…“ scheitert). */
function ps_vendors(): array {
    static $v = null;
    if ($v === null) {
        $v = array_map('basename', glob(PS_PROFILES . '/*', GLOB_ONLYDIR) ?: []);
        usort($v, fn($a, $b) => strlen($b) - strlen($a));
    }
    return $v;
}

/** Alle 0,4-mm-Maschinenprofile als [vendor => [machine, …]] — für die Auswahl bei Klipper ohne Modell. */
function ps_machine_list(): array {
    $out = [];
    foreach (ps_vendors() as $v) {
        $m = array_values(array_filter(array_keys(ps_profile_index($v, 'machine')), fn($n) => preg_match('/\(?0\.4 nozzle\)?$/', $n)));
        if ($m) { sort($m, SORT_NATURAL); $out[$v] = $m; }
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Düsengrößen, für die es dieses Druckermodell als Orca-Profil gibt (15.11.2026, Wunsch Denny: 0,6 mm am Kobra S1 Max).
 * „Anycubic Kobra S1 Max 0.4 nozzle“ → 0.25/0.4/0.6/0.8; „Creality K1 (0.4 nozzle)“ → … (0.6 nozzle). Nur instanzierbare Profile.
 * Rückgabe [['d' => 0.6, 'machine' => Name, 'lh' => [min, max]], …] aufsteigend; ohne Größe im Namen: leer.
 */
function ps_machine_nozzles(?string $vendor, ?string $machine): array {
    static $cache = [];
    if (!$vendor || !$machine) return [];
    $re = '/^(.*?)\s*\(?(\d+(?:\.\d+)?) ?nozzle\)?$/i';
    if (!preg_match($re, $machine, $mm)) return [];
    $k = "$vendor|$machine";
    if (isset($cache[$k])) return $cache[$k];
    $out = [];
    foreach (ps_profile_meta($vendor, 'machine') as $n => $e) {
        if (!$e['inst'] || !preg_match($re, $n, $x) || strcasecmp(trim($x[1]), trim($mm[1])) !== 0) continue;
        $d = round((float)$x[2], 2);
        if ($d <= 0 || isset($out[(string)$d])) continue;
        $out[(string)$d] = ['d' => $d, 'machine' => $n, 'lh' => ps_machine_caps($vendor, $n)['lh']];
    }
    usort($out, fn($a, $b) => $a['d'] <=> $b['d']);
    return $cache[$k] = $out;
}

/**
 * Betttemperatur für die gewählte Platte nachtragen (17.11.2026, Snapmaker U1): viele Herstellerprofile kennen nur eine PEI-Platte —
 * „Snapmaker PLA @U1“ hat hot_plate_temp 55, aber textured_plate_temp 0 (42 von 93 U1-Filamenten in Orca 2.5.0). 0 heißt für Orca
 * „Platte nicht geeignet“ → „Textured PEI Plate does not support filament 1“. Dann die Temperatur der anderen PEI-Platte nehmen
 * (sonst der Engineering Plate). Bewusst NICHT auf die andere Platte umschalten: der U1-Startcode setzt bei „High Temp Plate“
 * Z_OFFSET -0.07 — auf der Strukturplatte wäre die erste Schicht falsch.
 */
function ps_bed_temp_fill(array $f, string $bed): array {
    $own = $bed === 'smooth' ? 'hot_plate_temp' : 'textured_plate_temp';
    $from = [$bed === 'smooth' ? 'textured_plate_temp' : 'hot_plate_temp', 'eng_plate_temp'];
    $val = fn(array $a, string $k) => (string)(((array)($a[$k] ?? []))[0] ?? '');   // $f immer übergeben (fn fängt nur eine Kopie)
    $zero = fn($x) => $x === '' || !is_numeric($x) || (float)$x <= 0;
    foreach (['', '_initial_layer'] as $sfx) {
        if (!$zero($val($f, $own . $sfx))) continue;
        foreach ($from as $src) if (!$zero($x = $val($f, $src . $sfx))) { $f[$own . $sfx] = [$x]; break; }
    }
    // Nur eine der beiden Angaben gefunden → die andere davon ableiten
    if ($zero($val($f, $own)) && !$zero($x = $val($f, $own . '_initial_layer'))) $f[$own] = [$x];
    if ($zero($val($f, $own . '_initial_layer')) && !$zero($x = $val($f, $own))) $f[$own . '_initial_layer'] = [$x];
    return $f;
}

/**
 * Platzhalter der Experten-Einstellungen (print_xdef auf 3DSEARCH, xdef im Container): Prozesswerte des Profils für diese Düse,
 * dazu die Temperaturen des Filamentprofils für das gewählte Material und die Platte. Rückgabe ['vals' => …, 'caps' => …].
 */
function ps_xdef(string $vendor, string $machine, string $nozzle = '', string $material = '', string $bed = 'textured'): array {
    if ($nozzle !== '' && ($var = ps_nozzle_variant($vendor, $machine, $nozzle))) $machine = $var;
    $vals = [];
    try {
        $pr = ps_flatten($vendor, 'process', ps_process_for($vendor, $machine));
        foreach (array_keys(PS_X_PROC) as $k) if (isset($pr[$k]) && !is_array($pr[$k])) $vals[$k] = (string)$pr[$k];
        if (($pr['support_style'] ?? '') === 'organic' && ($vals['support_type'] ?? '') === 'tree(auto)') $vals['support_type'] = 'organic';
    } catch (Throwable $e) { /* ohne Platzhalter */ }
    if ($material !== '' && mb_strlen($material) <= 30) {
        try {
            $prof = ps_filament_for($vendor, $machine, $material);
            [$fv, $fname] = str_contains($prof, '|') ? explode('|', $prof, 2) : [$vendor, $prof];
            $f = ps_bed_temp_fill(ps_flatten($fv, 'filament', $fname), $bed);
            $one = fn($k) => ($x = ((array)($f[$k] ?? []))[0] ?? null) !== null && is_numeric($x) ? (string)(int)$x : null;
            foreach (['nozzle_temperature', 'nozzle_temperature_initial_layer'] as $k) if (($x = $one($k)) !== null) $vals[$k] = $x;
            if (($x = $one($bed === 'smooth' ? 'hot_plate_temp' : 'textured_plate_temp')) !== null && $x !== '0') $vals['bed_temperature'] = $x;
        } catch (Throwable $e) { /* ohne Temperatur-Platzhalter */ }
    }
    return ['vals' => $vals, 'caps' => ps_machine_caps($vendor, $machine)];
}

/** Maschinenprofil für diese Düse (gleiches Modell) oder null. */
function ps_nozzle_variant(?string $vendor, ?string $machine, $d): ?string {
    if (!is_numeric($d)) return null;
    foreach (ps_machine_nozzles($vendor, $machine) as $n) if (abs($n['d'] - (float)$d) < 0.001) return $n['machine'];
    return null;
}

function ps_process_for(string $vendor, string $machine): string {
    $cands = ps_profiles_for($vendor, 'process', $machine);
    // Andere Düse als 0,4 mm (15.11.2026): Schichthöhe nahe der halben Düse (0,6 → 0,30 mm, 0,8 → 0,40, 0,2 → 0,10), lieber
    // „Standard“ — vorher nahm der erste Treffer die feinste Schicht (0,6 mm-Düse mit 0,18 mm)
    $nd = 0.4;
    try { $nd = (float)(((array)(ps_flatten($vendor, 'machine', $machine)['nozzle_diameter'] ?? [0.4]))[0] ?? 0.4); } catch (Throwable $e) {}
    if (abs($nd - 0.4) < 0.001) foreach (['0.20mm Standard', '0.20mm'] as $pre) {
        // „SOLUBLE“ (Prusa) ist für wasserlösliches Stützmaterial, nie als Vorgabe — bei MK4S/CORE One wird es so „0.20mm SPEED“
        foreach ($cands as $n) if (str_starts_with($n, $pre) && stripos($n, 'SOLUBLE') === false) return $n;
    }
    // Sonst (andere Düse, oder Namen ohne „mm“ wie Snapmaker „0.20 Standard“ — dort kam bis 15.11. „0.08 Extra Fine“):
    // Schicht nahe der halben Düse, „Standard“ bevorzugt
    if ($nd > 0) {
        $best = null; $bs = INF;
        foreach ($cands as $n) {
            if (stripos($n, 'SOLUBLE') !== false || !preg_match('/^(\d+\.\d+)/', $n, $x)) continue;
            $sc = abs((float)$x[1] - $nd / 2) + (stripos($n, 'Standard') !== false ? 0 : 0.02);
            if ($sc < $bs - 1e-9) { $bs = $sc; $best = $n; }
        }
        if ($best !== null) return $best;
    }
    if (!$cands) throw new RuntimeException("Kein Prozessprofil für $machine");
    return $cands[0];
}

/** Filamentprofil zum Material im Fach („PLA Silk“ → „Anycubic PLA Silk @…“, sonst Generic). */
function ps_filament_for(string $vendor, string $machine, string $material): string {
    $idx = ps_profile_index($vendor, 'filament');
    $mat = strtoupper(trim($material));
    $names = [];
    foreach (ps_profiles_for($vendor, 'filament', $machine) as $n) $names[strtoupper(trim(explode('@', $n)[0]))] = $n;
    $v = strtoupper($vendor);
    if (isset($names["$v $mat"])) return $names["$v $mat"];
    // Generic für genau diese Maschine (Creality: „Creality Generic PLA @K2-all“), dann allgemein — erst das
    // Material selbst (PLA-CF, PLA Silk …), dann das Grundmaterial
    if (isset($names["$v GENERIC $mat"])) return $names["$v GENERIC $mat"];
    foreach (array_keys($idx) as $n) if (strtoupper($n) === "$v GENERIC $mat") return $n;
    $base = preg_match('/PETG|PET|ABS|ASA|TPU|PC|PA|PLA/', $mat, $m) ? $m[0] : 'PLA';
    foreach (["$v $base", "$v GENERIC $base"] as $k) if (isset($names[$k])) return $names[$k];
    if (isset($idx["$vendor Generic $base"])) return "$vendor Generic $base";
    // Hersteller ohne eigene Filamente (viele Klipper-Selbstbauten): Orca-Grundprofil
    foreach (array_keys(ps_profile_index('OrcaFilamentLibrary', 'filament')) as $n) {
        if (strtoupper(trim(explode('@', $n)[0])) === "GENERIC $base") return "OrcaFilamentLibrary|$n";
    }
    throw new RuntimeException("Kein Filamentprofil für $material");
}

/**
 * Verbundene Drucker mit Fächern und Profil. can_print=false bei Anschlüssen, die keinen Druck starten
 * können (Bambu). slot_mode: 'slots' = Fach wählen, 'material' = Material wählen (Drucker meldet keine Fächer).
 * Klipper mit ACE/MMU: der G-Code wechselt kein Werkzeug → gedruckt wird mit T0, wählbar ist nur dessen Fach.
 */
/**
 * Was das Druckerprofil für die Experten-Einstellungen hergibt: Pause (machine_pause_gcode, z. B. M601 bzw. PAUSE)
 * und erlaubte Schichthöhen. Je Profil einmal je Aufruf gelesen.
 */
/**
 * Bettfläche und Bauhöhe des Druckers in mm: [Breite, Tiefe, Höhe] aus printable_area/printable_height des Orca-Profils, null wenn
 * unbekannt. Für „passt auf“ in der Bibliothek von AllMyPrinters (Slicer 1.0.10, 18.11.2026).
 */
function ps_machine_bed(?string $vendor, ?string $machine): ?array {
    static $cache = [];
    if (!$vendor || !$machine) return null;
    $k = "$vendor|$machine";
    if (array_key_exists($k, $cache)) return $cache[$k];
    try { $m = ps_flatten($vendor, 'machine', $machine); } catch (Throwable $e) { return $cache[$k] = null; }
    $xs = $ys = [];
    foreach ((array)($m['printable_area'] ?? []) as $pt) {
        if (preg_match('/^\s*(-?[\d.]+)x(-?[\d.]+)\s*$/', (string)$pt, $mm)) { $xs[] = (float)$mm[1]; $ys[] = (float)$mm[2]; }
    }
    if (count($xs) < 3) return $cache[$k] = null;
    $w = max($xs) - min($xs); $d = max($ys) - min($ys);
    if ($w < 20 || $d < 20 || $w > 3000 || $d > 3000) return $cache[$k] = null;
    $hv = $m['printable_height'] ?? null; $h = (float)(is_array($hv) ? ($hv[0] ?? 0) : ($hv ?? 0));
    return $cache[$k] = [round($w, 1), round($d, 1), $h > 0 ? round($h, 1) : null];
}

function ps_machine_caps(?string $vendor, ?string $machine): array {
    static $cache = [];
    $none = ['pause' => false, 'lh' => [0.08, 0.32]];
    if (!$vendor || !$machine) return $none;
    $k = "$vendor|$machine";
    if (isset($cache[$k])) return $cache[$k];
    try { $m = ps_flatten($vendor, 'machine', $machine); } catch (Throwable $e) { return $cache[$k] = $none; }
    $pg = $m['machine_pause_gcode'] ?? '';
    $pg = is_array($pg) ? implode("\n", $pg) : (string)$pg;
    $lo = (float)(((array)($m['min_layer_height'] ?? []))[0] ?? 0);
    $hi = (float)(((array)($m['max_layer_height'] ?? []))[0] ?? 0);
    return $cache[$k] = ['pause' => trim(preg_replace('/;.*$/m', '', $pg)) !== '', 'lh' => [$lo > 0 ? $lo : 0.04, $hi > 0 ? $hi : 0.32]];
}

/**
 * Experten-Einstellungen aus dem Formular (x_<schlüssel>) prüfen. Rückgabe ['proc' => Orca-Werte für p.json, 'orient',
 * 'rot', 'scale', 'pause' => [mm …]] oder Fehlercode. Unbekannte Schlüssel fallen still weg, leere Felder heißen „Standard“.
 */
function ps_x_clean(array $raw, string $ext): array|string {
    $num = fn($v) => is_numeric($v = str_replace(',', '.', trim((string)$v))) ? (float)$v : null;
    $fmt = fn(float $v) => rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');
    $x = ['proc' => [], 'fil' => [], 'orient' => true, 'rot' => false, 'scale' => 1.0, 'pause' => []];
    foreach (PS_X_FIL as $k => $d) {
        $v = trim((string)($raw[$k] ?? ''));
        if ($v === '') continue;
        $n = $num($v);
        if ($n === null || $n < $d[1] || $n > $d[2]) return 'x_bad';
        $x['fil'][$k] = (int)round($n);
    }
    foreach (PS_X_PROC as $k => $d) {
        $v = trim((string)($raw[$k] ?? ''));
        if ($v === '') continue;
        if ($d[0] === 'e') { if (!in_array($v, $d[1], true)) return 'x_bad'; $x['proc'][$k] = $v; continue; }
        if ($d[0] === 'b') { if ($v !== '0' && $v !== '1') return 'x_bad'; $x['proc'][$k] = $v; continue; }
        $n = $num($v);
        if ($n === null || $n < $d[1] || $n > $d[2]) return 'x_bad';
        $x['proc'][$k] = $d[0] === 'i' ? (string)(int)round($n) : ($d[0] === 'pct' ? (int)round($n) . '%' : $fmt($n));
    }
    if (($x['proc']['support_type'] ?? '') === 'organic') { $x['proc']['support_type'] = 'tree(auto)'; $x['proc']['support_style'] = 'organic'; }
    elseif (isset($x['proc']['support_type'])) $x['proc']['support_style'] = 'default';   // Stil aus der 3MF passt sonst nicht zur Art
    // Ein Teil nach dem anderen (print_sequence): Orca prüft den Abstand zum Druckkopf selbst und meldet, wenn Teile zu nah stehen
    $x['orient'] = $ext !== 'stl' || ($raw['orient'] ?? '1') !== '0';   // nur STL: auf die beste Seite drehen
    $x['rot'] = ($raw['rot'] ?? '') === '1';
    if (($s = trim((string)($raw['scale'] ?? ''))) !== '') {
        $n = $num(rtrim($s, '% '));
        if ($n === null || $n < 1 || $n > 1000) return 'x_bad';
        $x['scale'] = round($n / 100, 4);
    }
    if (($pz = trim((string)($raw['pause'] ?? ''))) !== '') {
        foreach (preg_split('/[;\s]+/', $pz, -1, PREG_SPLIT_NO_EMPTY) as $v) {
            $n = $num($v);
            if ($n === null || $n < 0.1 || $n > 2000) return 'x_bad';
            $x['pause'][] = round($n, 2);
        }
        $x['pause'] = array_values(array_unique($x['pause']));
        sort($x['pause']);
        if (count($x['pause']) > PS_PAUSE_MAX) return 'x_bad';
        // Orca setzt Pausen nur Schicht für Schicht ein — „ein Teil nach dem anderen“ verwirft sie still (getestet)
        if (($x['proc']['print_sequence'] ?? '') === 'by object') return 'x_pause_seq';
    }
    return $x;
}

/** Filament → Fach je Eintrag [ext, slot (Fachindex), color, type]: Fach samt Werkzeug (Klipper ttg) oder Fehlercode 'slot'. */
function ps_fils_resolve(array $printer, array $want): array|string {
    $slots = array_column($printer['slots'], null, 'index');
    $fils = [];
    foreach ($want as $w) {
        $s = $w['slot'] !== null && $w['slot'] !== '' ? ($slots[(int)$w['slot']] ?? null) : null;
        if (!$s || !$s['material'] || !empty($s['locked'])) return 'slot';
        $tool = null;
        if (!empty($printer['ttg'])) {
            $t = array_search((int)$s['index'], $printer['ttg'], true);
            if ($t === false) return 'slot';
            $tool = (int)$t;
        }
        $fils[] = ['ext' => (int)$w['ext'], 'slot' => $s, 'tool' => $tool, 'project_color' => $w['color'] ?? null, 'project_type' => $w['type'] ?? null];
    }
    return $fils ?: 'slot';
}

/**
 * Nivellier-Befehl in den Start-G-Code: direkt nach dem letzten Referenzfahren (G28 oder Startmakro wie START_PRINT/
 * PRINT_START, das selbst referenziert) vor der ersten Extrusion (Spülstreifen). Nicht hinter M190/M109: Profile
 * fahren vorher an die Startposition des Spülstreifens (Kobra: G1 Y1.0 Z0.3), die die Messfahrt zunichtemachen würde.
 * null = keine sichere Stelle.
 */
function ps_level_insert(string $start, string $cmd): ?string {
    $lines = explode("\n", $start);
    $at = null;
    foreach ($lines as $i => $l) {
        $c = strtoupper(trim(preg_replace('/;.*$/', '', $l)));
        if ($c === '') continue;
        if (preg_match('/^G[01]\b.*\bE\d*\.?\d/', $c)) break;   // erste Extrusion
        if (preg_match('/^G28\b/', $c) || (!preg_match('/^[GMT]\d/', $c) && preg_match('/^\w*(START_PRINT|PRINT_START)\w*\b/', $c))) $at = $i;
    }
    if ($at === null) return null;
    array_splice($lines, $at + 1, 0, ["$cmd ; 3DSEARCH: Bett vor dem Druck nivellieren"]);
    return implode("\n", $lines);
}

/** Wahl für den Auftrag: true/false, null = nichts zu wählen (Drucker entscheidet selbst). */
function ps_level_choice(array $info, string $posted): ?bool {
    if (!in_array($info['mode'] ?? 'none', ['cloud', 'rinkhals', 'u1', 'insert'], true)) return null;
    if (in_array($info['mode'], ['rinkhals', 'u1'], true) && empty($info['agent_ok'])) return null;
    if ($info['mode'] === 'rinkhals' && ($info['lan'] ?? null) === false) return null;
    return $posted === '' ? (bool)($info['def'] ?? false) : $posted === '1';
}

/**
 * Vorbelegung Filament → Fach für einen Mehrfarbdruck: je Projekt-Filament das belegte Fach mit dem gleichen
 * Grundmaterial und der ähnlichsten Farbe, jedes Fach nur einmal, solange noch freie da sind.
 * $fils = info3mf['fils'], Rückgabe [ext => slot_index].
 */
function ps_auto_map(array $fils, array $slots): array {
    $rgb = fn(?string $h) => $h && preg_match('/^#?([0-9a-f]{6})/i', $h, $m) ? array_map('hexdec', str_split($m[1], 2)) : null;
    $base = fn(?string $t) => preg_match('/PETG|PET|ABS|ASA|TPU|PC|PA|PLA/i', (string)$t, $m) ? strtoupper($m[0]) : '';
    $usable = array_values(array_filter($slots, fn($s) => $s['material'] && empty($s['locked'])));
    $map = []; $taken = [];
    foreach ($fils as $f) {
        $fc = $rgb($f['color'] ?? null); $fb = $base($f['type'] ?? null);
        $best = null; $bestd = INF;
        foreach ($usable as $s) {
            $sc = $rgb($s['color'] ?? null);
            // gewichteter RGB-Abstand + Unterschied der Buntheit (Grau soll eher auf Schwarz/Weiß als auf Blau)
            $d = $fc && $sc ? sqrt(2 * ($fc[0] - $sc[0]) ** 2 + 4 * ($fc[1] - $sc[1]) ** 2 + 3 * ($fc[2] - $sc[2]) ** 2)
                              + abs((max($fc) - min($fc)) - (max($sc) - min($sc))) : 500;
            if ($fb !== '' && $base($s['material']) !== $fb) $d += 1000;   // anderes Material nur, wenn nichts passt
            if (isset($taken[$s['index']])) $d += 3000;                    // Fach doppelt nur, wenn Fächer fehlen
            if ($s['remain'] === 0) $d += 200;
            if ($d < $bestd) { $bestd = $d; $best = $s['index']; }
        }
        if ($best === null) continue;
        $map[$f['ext']] = $best; $taken[$best] = true;
    }
    return $map;
}

/**
 * Was steckt in der 3MF? plates, filaments (Anzahl benutzter Extruder), extruders (welche), colors (deren Farben),
 * painted_color (aufgemalte Farben), supports (Projekt-Einstellung, null = keine), painted (gemalte Stützen),
 * keep (Designer-Werte aus PS_3MF_KEEP), multi = mehrfarbig.
 */
function ps_inspect_3mf(string $path, int $plate = 1, array $skip = []): array {
    $info = ['plates' => 1, 'plate' => 1, 'filaments' => 1, 'extruders' => [1], 'colors' => [], 'painted_color' => false, 'supports' => null, 'painted' => false,
             'keep' => [], 'printer' => null, 'multi' => false, 'nfil' => 1, 'fils' => [], 'plate_objs' => null, 'skip' => [], 'skip_inst' => []];
    $fil_colors = []; $fil_types = [];
    $z = new ZipArchive();
    if ($z->open($path) !== true) throw new RuntimeException('3mf_bad');
    // Entpackte Größe vorher prüfen: die Angabe in der ZIP ist frei wählbar, getFromName() lädt alles in den Speicher
    foreach (['Metadata/project_settings.config', 'Metadata/model_settings.config'] as $n) {
        if (($stt = $z->statName($n)) && $stt['size'] > 8 * 1024 * 1024) throw new RuntimeException('3mf_bad');
    }
    $ps = $z->getFromName('Metadata/project_settings.config');
    if ($ps !== false && is_array($cfg = json_decode($ps, true))) {
        foreach (PS_3MF_KEEP as $k) if (isset($cfg[$k]) && !is_array($cfg[$k])) $info['keep'][$k] = (string)$cfg[$k];
        if (isset($cfg['enable_support'])) $info['supports'] = (string)$cfg['enable_support'] === '1';
        $info['printer'] = $cfg['printer_settings_id'] ?? null;
        $fil_colors = array_values((array)($cfg['filament_colour'] ?? []));
        $fil_types = array_values((array)($cfg['filament_type'] ?? []));
    }
    $ms = $z->getFromName('Metadata/model_settings.config');
    if ($ms !== false) {
        $info['plates'] = max(1, preg_match_all('/<plate>/', $ms));
        // Benutzte Extruder: Teil mit eigenem Extruder → dieser, sonst der des Objekts (Standard 1).
        // Vorher zählte Extruder 1 immer mit → ein Projekt, das nur Filament 2 nutzt, galt als mehrfarbig.
        // Nur Objekte der gewählten Platte (nur die wird gesliced): „Dancing Skeleton“ = Platte 1 PLA, Platte 2 PETG —
        // über das ganze Projekt gezählt galt es als zweifarbig, ist aber je Platte einfarbig.
        $ext = [];
        $xml = @simplexml_load_string($ms);
        // Gewählte Platte (Plattenauswahl seit 09.10.2026); Orca zählt --slice N in derselben Reihenfolge
        $pi = $xml && isset($xml->plate[$plate - 1]) ? $plate - 1 : 0;
        if ($xml && isset($xml->plate[$pi])) {
            $info['plate'] = $pi + 1;
            $objs = [];
            // Teile weglassen (12.10.2026): Orcas --skip-objects nimmt die identify_id je Instanz. Ein Objekt, dessen Instanzen
            // alle weggelassen werden, zählt für Filamente, Bemalung und den Geometrie-Rückfall nicht mehr.
            $kept = [];
            foreach ($xml->plate[$pi]->model_instance as $mi) {
                $md = [];
                foreach ($mi->metadata as $x) $md[(string)$x['key']] = (string)$x['value'];
                if (($md['object_id'] ?? '') === '') continue;
                $objs[$md['object_id']] = true;
                $iid = $md['identify_id'] ?? '';
                if ($iid !== '' && in_array($iid, $skip, true)) {
                    $info['skip'][] = $iid;
                    $info['skip_inst'][] = $md['object_id'] . '/' . (int)($md['instance_id'] ?? 0);
                } else $kept[$md['object_id']] = true;
            }
            if ($objs && !$kept) $info['skip_all'] = true;
            if ($info['skip'] && $kept) $objs = $kept;
            if ($objs) $info['plate_objs'] = array_map('strval', array_keys($objs));   // Zahlen-Schlüssel werden int
        }
        // Teile auf KEINER Platte (in Bambu Studio neben dem Bett „geparkt“) druckt auch Bambu nicht — der Nutzer
        // sieht sonst nur einen Teil des Modells ohne Erklärung („Dancing Skeleton“ 09.10.: 1 von 5 Teilen)
        if ($xml && $info['plate_objs'] !== null) {
            $on = [];
            foreach ($xml->plate as $pl) foreach ($pl->model_instance as $mi) foreach ($mi->metadata as $md) if ((string)$md['key'] === 'object_id') $on[(string)$md['value']] = true;
            $off = [];
            foreach ($xml->object as $o) {
                if (isset($on[(string)$o['id']])) continue;
                $nm = '';
                foreach ($o->metadata as $md) if ((string)$md['key'] === 'name') $nm = (string)$md['value'];
                $off[] = mb_substr($nm !== '' ? $nm : '#' . $o['id'], 0, 40);
            }
            if ($off) $info['off_plate'] = $off;
        }
        if ($xml) {
            foreach ($xml->object as $o) {
                if ($info['plate_objs'] !== null && !in_array((string)$o['id'], $info['plate_objs'], true)) continue;
                $oe = 1;
                foreach ($o->metadata as $md) if ((string)$md['key'] === 'extruder' && (int)$md['value'] > 0) $oe = (int)$md['value'];
                $parts = 0;
                foreach ($o->part as $pt) {
                    if ((string)$pt['subtype'] !== '' && (string)$pt['subtype'] !== 'normal_part') continue;
                    $pe = $oe;
                    foreach ($pt->metadata as $md) if ((string)$md['key'] === 'extruder' && (int)$md['value'] > 0) $pe = (int)$md['value'];
                    $ext[$pe] = true; $parts++;
                }
                if (!$parts) $ext[$oe] = true;
            }
        }
        if ($ext) { ksort($ext); $info['extruders'] = array_keys($ext); }
        $info['filaments'] = count($info['extruders']);
    }
    $info['nfil'] = max(1, count($fil_colors), max($info['extruders']));
    // Gemalte Stützen und Farben stehen als Attribut an den Dreiecken (bis 100 MB XML → streamen, nicht laden).
    // Nur die Netzdateien der gewählten Platte — eine bemalte Platte 2 machte sonst auch Platte 1 „mehrfarbig“.
    $only = $info['plate_objs'] !== null ? ps_3mf_plate_files($z, $info['plate_objs']) : null;
    for ($i = 0; $i < $z->numFiles && !($info['painted'] && $info['painted_color']); $i++) {
        $n = $z->getNameIndex($i);
        if (!str_ends_with($n, '.model')) continue;
        if ($only !== null && !in_array($n, $only, true)) continue;
        $fp = $z->getStream($n);
        $tail = '';
        while ($fp && !feof($fp)) {
            $buf = $tail . fread($fp, 1 << 20);
            if (strpos($buf, 'paint_supports=') !== false || strpos($buf, 'slic3rpe:custom_supports=') !== false) $info['painted'] = true;
            if (strpos($buf, 'paint_color=') !== false || strpos($buf, 'slic3rpe:mmu_segmentation=') !== false) $info['painted_color'] = true;
            if ($info['painted'] && $info['painted_color']) break;
            $tail = substr($buf, -40);
        }
        if ($fp) fclose($fp);
    }
    $z->close();
    // Bemalt zählt nur, wenn es überhaupt ein zweites Filament gibt (Wespenfalle: Deckel komplett mit Filament 1
    // „bemalt“, paint_color="4", Projekt hat genau ein Filament → einfarbig)
    $info['multi'] = $info['filaments'] > 1 || ($info['painted_color'] && count($fil_colors) > 1);
    // Bemalt: welche Filamente die Farbe nutzt, steht kodiert an jedem Dreieck → alle Projekt-Filamente zuordnen
    // lassen (nicht benutzte kosten nur eine Auswahl; Orca rechnet für sie 0 g)
    if ($info['multi'] && $info['painted_color']) $info['extruders'] = range(1, $info['nfil']);
    $fil = function (int $e) use ($fil_colors, $fil_types): array {
        $c = $fil_colors[$e - 1] ?? null;
        return ['ext' => $e, 'color' => is_string($c) && preg_match('/^#[0-9a-f]{6}/i', $c) ? strtolower(substr($c, 0, 7)) : null,
                'type' => isset($fil_types[$e - 1]) ? (string)$fil_types[$e - 1] : null];
    };
    foreach ($info['extruders'] as $e) {
        $info['colors'][] = $fil_colors[$e - 1] ?? null;
        $info['fils'][] = $fil($e);
    }
    // Stützen bzw. Kontaktschicht aus einem Filament, das sonst kein Objekt nutzt (0 = wie das Objekt): vormerken —
    // ob es als eigenes Filament zugeordnet wird, entscheidet ps_job_support_fils je Auftrag (Stützen an, Drucker mehrfarbig)
    $info['sup_fils'] = [];
    foreach (['support_filament' => 'support', 'support_interface_filament' => 'interface'] as $k => $role) {
        $e = (int)($info['keep'][$k] ?? 0);
        if ($e < 1 || $e > $info['nfil']) { unset($info['keep'][$k]); continue; }
        if (!in_array($e, $info['extruders'], true) && !isset($info['sup_fils'][$e])) $info['sup_fils'][$e] = $fil($e) + ['role' => $role];
    }
    $info['sup_fils'] = array_values($info['sup_fils']);
    return $info;
}

/**
 * Netzdateien der Objekte einer Platte: Bambu/Orca verweisen im 3D/3dmodel.model je Objekt per
 * <component p:path="/3D/Objects/…"> auf die Datei mit dem Netz. Netz direkt im 3dmodel.model (andere Slicer), Datei zu
 * groß oder nichts gefunden → null = wie früher alle .model-Dateien prüfen.
 */
function ps_3mf_plate_files(ZipArchive $z, array $objs): ?array {
    $st = $z->statName('3D/3dmodel.model');
    if (!$st || $st['size'] > 16 * 1024 * 1024) return null;
    if (!preg_match_all('/<object\b[^>]*\bid="(\d+)"[^>]*>(.*?)<\/object>/s', (string)$z->getFromName('3D/3dmodel.model'), $mm, PREG_SET_ORDER)) return null;
    $files = [];
    foreach ($mm as [, $id, $body]) {
        if (!in_array($id, $objs, true)) continue;
        if (!preg_match_all('/<component\b[^>]*\bp:path="\/?([^"]+)"/', $body, $pp)) return null;
        foreach ($pp[1] as $f) $files[$f] = true;
    }
    return $files ? array_keys($files) : null;
}

/**
 * Stützen-/Kontaktschicht-Filament der 3MF (sup_fils aus ps_inspect_3mf) nur mit Stützen auf einem Mehrfarb-Drucker als
 * eigenes Filament zuordnen lassen. Sonst fliegen die Nummern aus keep: einfarbig bekommt Orca genau ein Filament, eine
 * Nummer 2 zeigte dann ins Leere — Stützen kommen dann wie bisher aus dem Objekt-Filament.
 */
function ps_job_support_fils(array $info, bool $supports, bool $multi_printer): array {
    $sup = $info['sup_fils'] ?? [];
    unset($info['sup_fils']);
    if (!$supports || !$multi_printer) {
        unset($info['keep']['support_filament'], $info['keep']['support_interface_filament']);
        return $info;
    }
    foreach ($sup as $f) { $info['extruders'][] = $f['ext']; $info['colors'][] = $f['color']; $info['fils'][] = $f; }
    $info['filaments'] = count($info['extruders']);
    $info['multi'] = !empty($info['multi']) || $info['filaments'] > 1;
    return $info;
}

function ps_job_dir(string $id): string {
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) throw new RuntimeException('bad job id');
    return PS_DIR . '/' . $id;
}

function ps_job_load(string $id): ?array {
    try { $f = ps_job_dir($id) . '/job.json'; } catch (Throwable $e) { return null; }
    return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: null) : null;
}

/** Letzter Fortschritt des laufenden Orca-Aufrufs: ['pct' => 0–100, 'step' => Schlüssel aus PS_PROGRESS_STEPS oder null]. */
function ps_progress(string $id): ?array {
    $f = ps_job_dir($id) . '/progress.jsonl';
    $fh = @fopen($f, 'r');
    if (!$fh) return null;
    fseek($fh, max(0, (int)fstat($fh)['size'] - 2048));
    $lines = explode("\n", (string)stream_get_contents($fh));
    fclose($fh);
    foreach (array_reverse($lines) as $l) {
        $d = json_decode($l, true);
        if (!is_array($d) || !isset($d['total_percent'])) continue;   // letzte Zeile evtl. noch halb geschrieben
        $step = null;
        foreach (PS_PROGRESS_STEPS as $k => $re) if (preg_match($re, (string)($d['message'] ?? ''))) { $step = $k; break; }
        return ['pct' => max(0, min(100, (int)$d['total_percent'])), 'step' => $step];
    }
    return null;
}

function ps_job_save(array $job): void {
    $f = ps_job_dir($job['id']) . '/job.json';
    file_put_contents("$f.tmp", json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    rename("$f.tmp", $f);
}

/** Auftrag anlegen. $upload = Eintrag aus $_FILES. Rückgabe: Job oder Fehlercode (string). */
function ps_job_create(PDO $pdo, int $uid, array $printer, array $upload, array $opt) {
    if (($upload['error'] ?? 1) !== UPLOAD_ERR_OK) return 'upload';
    if ($upload['size'] > PS_MAX_BYTES) return 'too_big';
    $ext = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
    $is_gc = in_array($ext, PS_GCODE_EXT, true);
    if (!in_array($ext, ['stl', '3mf'], true) && !$is_gc) return 'type';
    if (empty($printer['can_print'])) return 'provider';
    if (!$printer['machine'] && !$is_gc) return 'no_profile';   // eigener G-Code braucht kein Slicerprofil
    // Düse (15.11.2026): gleiches Modell mit anderer Düsengröße — nur Größen, die es als Orca-Profil gibt
    if (!$is_gc && ($nz = trim((string)($opt['nozzle'] ?? ''))) !== '') {
        $var = ps_nozzle_variant($printer['vendor'], $printer['machine'], $nz);
        if ($var === null) return 'nozzle';
        $printer['machine'] = $var;
        $printer['caps'] = ps_machine_caps($printer['vendor'], $var);
    }
    $slot = null;
    if ($printer['slot_mode'] === 'material') {
        // Drucker meldet keine Fächer: nur das Material (für Filamentprofil und Temperaturen)
        $mat = (string)($opt['material'] ?? '');
        if (!in_array($mat, PS_MATERIALS, true)) return 'slot';
        $slot = ['index' => -1, 'label' => '', 'material' => $mat, 'color' => null, 'remain' => null];
    } else {
        foreach ($printer['slots'] as $s) if ($s['index'] === (int)$opt['slot']) $slot = $s;
        if (!$slot || !$slot['material'] || !empty($slot['locked'])) return 'slot';
    }
    // Klipper mit Werkzeugwahl: Werkzeug, das laut ttg_map auf dieses Fach zeigt
    $tool = null;
    if (!empty($printer['ttg']) && $slot['index'] >= 0) {
        $t = array_search((int)$slot['index'], $printer['ttg'], true);
        if ($t === false) return 'slot';
        $tool = (int)$t;
    }
    // Experten-Einstellungen (nicht bei eigenem G-Code — da ist nichts mehr zu slicen)
    $x = null;
    if (!$is_gc) {
        $x = ps_x_clean((array)($opt['x'] ?? []), $ext);
        if (is_string($x)) return $x;
        if ($x['pause'] && !ps_machine_caps($printer['vendor'], $printer['machine'])['pause']) return 'no_pause';
    }
    // Mehrere STL auf einer Platte (23.10.2026): weitere Dateien, Kopien je Datei und — auf einem Mehrfarb-Drucker — ein
    // eigenes Fach je Datei (Orca --load-filament-ids). Ohne Angabe druckt jede Datei aus dem Fach von Schritt 3.
    $more = $ext === 'stl' ? array_values((array)($opt['more'] ?? [])) : [];
    if (count($more) + 1 > PS_STL_MAX) return 'too_many_files';
    $sum = (int)$upload['size'];
    foreach ($more as $up) {
        if (($up['error'] ?? 1) !== UPLOAD_ERR_OK) return 'upload';
        if (strtolower(pathinfo((string)($up['name'] ?? ''), PATHINFO_EXTENSION)) !== 'stl') return 'type';
        if (($sum += (int)($up['size'] ?? 0)) > PS_MAX_BYTES) return 'too_big';
    }
    $copies = $pslots = []; $distinct = []; $fils = null;
    if ($ext === 'stl') {
        $cps = array_values((array)($opt['copies'] ?? [])); $fsl = array_values((array)($opt['fslot'] ?? []));
        for ($i = 0; $i <= count($more); $i++) {
            $copies[] = max(1, min(PS_STL_MAX_OBJS, (int)($cps[$i] ?? 1)));
            $fs = trim((string)($fsl[$i] ?? ''));
            $pslots[] = !empty($printer['multi']) && $fs !== '' && ctype_digit($fs) ? (int)$fs : (int)$slot['index'];
        }
        if (array_sum($copies) > PS_STL_MAX_OBJS) return 'too_many_objs';
        $distinct = array_values(array_unique($pslots));
        if (count($distinct) > 1) {
            $fils = ps_fils_resolve($printer, array_map(fn($si, $n) => ['ext' => $n + 1, 'slot' => $si], $distinct, array_keys($distinct)));
            if (is_string($fils)) return $fils;
        } elseif ($distinct[0] !== (int)$slot['index']) {
            // Alle Dateien auf demselben anderen Fach: dann ist das einfach das Fach des Drucks
            $one = ps_fils_resolve($printer, [['ext' => 1, 'slot' => $distinct[0]]]);
            if (is_string($one)) return $one;
            $slot = $one[0]['slot']; $tool = $one[0]['tool'];
        }
    }

    if (!is_dir(PS_DIR)) mkdir(PS_DIR, 0770, true);
    // Platte schützen: je Nutzer höchstens 40 Uploads am Tag (Konten brauchen keine Bestätigung), nie unter 5 GB frei.
    // $opt['local']: Datei liegt schon auf dem Server (ps_file_retarget) — kein Upload, zählt nicht mit
    if (empty($opt['local']) && function_exists('apcu_inc')) {
        apcu_add('ps_day_' . $uid, 0, 86400);
        if ((int)apcu_inc('ps_day_' . $uid) > 40) return 'too_many';
    }
    if (($free = @disk_free_space(PS_DIR)) !== false && $free < 5 * 1024 ** 3) return 'disk';
    $id = bin2hex(random_bytes(8));
    $dir = PS_DIR . "/$id";
    mkdir($dir, 0770);
    $model = "$dir/model." . ($is_gc ? 'gcode' : $ext);
    if (!(empty($opt['local']) ? move_uploaded_file($upload['tmp_name'], $model) : rename($upload['tmp_name'], $model))) return 'upload';
    // Bricht die Prüfung hart ab (Speichergrenze), bliebe der Ordner sonst 24 h ohne job.json liegen
    register_shutdown_function(function () use ($dir) { if (is_dir($dir) && !is_file("$dir/job.json")) ps_rmdir($dir); });
    $info = null;
    if ($ext === '3mf') {
        $skip = array_slice(array_values(array_filter(explode(',', (string)($opt['skip'] ?? '')), 'ctype_digit')), 0, 500);
        try { $info = ps_inspect_3mf($model, max(1, min(99, (int)($opt['plate'] ?? 1))), $skip); } catch (Throwable $e) { ps_rmdir($dir); return '3mf_bad'; }
        if (!empty($info['skip_all'])) { ps_rmdir($dir); return 'skip_all'; }
    } elseif ($is_gc) {
        $gi = ps_gcode_inspect($model);
        if (is_string($gi)) { ps_rmdir($dir); return $gi; }
    } elseif (strncmp((string)file_get_contents($model, false, null, 0, 5), 'solid', 5) !== 0 && filesize($model) < 84) {
        ps_rmdir($dir); return 'type';
    }
    $nm = fn(array $u) => mb_substr(preg_replace('/[^\w .()+-]/u', '_', pathinfo((string)$u['name'], PATHINFO_FILENAME)), 0, 80);
    $parts = null;
    if ($ext === 'stl') {
        $fil_of = fn(int $i) => $fils ? array_search($pslots[$i], $distinct, true) + 1 : null;
        $parts = [['file' => basename($model), 'name' => $nm($upload), 'copies' => $copies[0], 'fil' => $fil_of(0)]];
        foreach ($more as $i => $up) {
            $f = "$dir/model_" . ($i + 2) . '.stl';
            if (!move_uploaded_file($up['tmp_name'], $f)) { ps_rmdir($dir); return 'upload'; }
            if (strncmp((string)file_get_contents($f, false, null, 0, 5), 'solid', 5) !== 0 && filesize($f) < 84) { ps_rmdir($dir); return 'type'; }
            $parts[] = ['file' => basename($f), 'name' => $nm($up), 'copies' => $copies[$i + 1], 'fil' => $fil_of($i + 1)];
        }
    }
    $job = ['id' => $id, 'user_id' => $uid, 'status' => 'queued', 'created' => time(),
            'file' => basename($model), 'name' => $nm($upload) . ($parts && count($parts) > 1 ? ' +' . (count($parts) - 1) : ''),
            'ext' => $is_gc ? 'gcode' : $ext, 'printer' => ['provider' => $printer['provider'], 'id' => $printer['id'], 'name' => $printer['name'], 'model' => $printer['model'],
                                          'vendor' => $printer['vendor'], 'machine' => $printer['machine'], 'cfs' => !empty($printer['cfs'])]
                                          // AllMyPrinters-App (amp_printer): Fach im Startbefehl — 'seq' T lückenlos wie Anycubic, 'keep' T wie Orca (10.11.2026)
                                          + (in_array($printer['tools'] ?? '', ['seq', 'keep'], true) ? ['tools' => $printer['tools']] : []),
            'slot' => $slot, 'tool' => $tool, 'supports' => !empty($opt['supports']), 'bed' => isset(PS_BED_TYPES[$opt['bed'] ?? '']) ? $opt['bed'] : 'textured',
            'info3mf' => $info, 'arrange' => $ext === '3mf' && !empty($opt['arrange']), 'parts' => $parts, 'x' => $x];
    // Jede STL in ihrem eigenen Fach: wie ein zugeordneter Mehrfarbdruck (ps_job_set_map) — Werkzeugwechsel stehen im G-Code
    if ($fils) { $job['fils'] = $fils; $job['slot'] = $fils[0]['slot']; $job['tool'] = null; }
    $li = $printer['level'] ?? ['mode' => 'none'];
    // Eigener G-Code: den Startcode schreibt sein Slicer — einfügen (Modus „insert“) geht dort nicht, Cloud/Firmware schon
    if ($is_gc && ($li['mode'] ?? '') === 'insert') $li = ['mode' => 'none'];
    $job['level'] = ps_level_choice($li, (string)($opt['level'] ?? ''));
    if ($job['level'] !== null) { $job['level_mode'] = $li['mode']; $job['level_cmd'] = $li['cmd'] ?? null; }
    if ($is_gc) {
        $job['supports'] = false;
        $job['gcode'] = $gi + ['mismatch' => ps_gcode_mismatch($gi, $printer),
                               'mat_mismatch' => $gi['types'] && $slot['material'] && stripos((string)$slot['material'], (string)$gi['types'][0]) === false
                                   && stripos((string)$gi['types'][0], (string)$slot['material']) === false ? [$gi['types'][0], (string)$slot['material']] : null];
    }
    // „Wie in der 3MF“ schon hier auflösen: davon hängt ab, ob ein Stützen-Filament zugeordnet werden muss
    if (!empty($opt['supports_project']) && $info) $job['supports'] = !empty($info['supports']) || !empty($info['painted']);
    if ($info) $job['info3mf'] = $info = ps_job_support_fils($info, $job['supports'], !empty($printer['multi']));
    // Mehrfarbig auf einem Drucker, der das kann: erst Filament → Fach zuordnen lassen (ps_job_set_map), dann slicen
    if ($info && !empty($info['multi']) && !empty($printer['multi'])) {
        $job['status'] = 'mapping';
        $job['map_auto'] = ps_auto_map($info['fils'], $printer['slots']);
    }
    ps_job_save($job);
    return $job;
}

/**
 * Zuordnung Projekt-Filament → Fach setzen und den Auftrag in die Warteschlange stellen. $printer frisch aus
 * ps_printers(), $map = [ext => slot_index]. Jedes benutzte Filament braucht ein belegtes, freigegebenes Fach;
 * ein Fach darf mehrere Filamente bekommen (gleiche Farbe zweimal im Projekt). Rückgabe null oder Fehlercode.
 */
function ps_job_set_map(array &$job, array $printer, array $map): ?string {
    if ($job['status'] !== 'mapping') return 'not_ready';
    if (empty($printer['multi']) || $printer['key'] !== $job['printer']['provider'] . ':' . $job['printer']['id']) return 'provider';
    $fils = ps_fils_resolve($printer, array_map(fn($f) => ['ext' => $f['ext'], 'slot' => $map[$f['ext']] ?? $map[(string)$f['ext']] ?? null,
                                                          'color' => $f['color'], 'type' => $f['type']], $job['info3mf']['fils']));
    if (is_string($fils)) return $fils;
    $job['fils'] = $fils;
    $job['slot'] = $fils[0]['slot'];   // Hauptfach für alles, was nur eins kennt (Kosten-Rückfall, Anzeige)
    $job['tool'] = null;               // Werkzeugwechsel stehen im G-Code selbst
    $job['status'] = 'queued';
    $job['created'] = time();
    ps_job_save($job);
    return null;
}

function ps_rmdir(string $dir): void {
    foreach (glob("$dir/{,.}[!.]*", GLOB_BRACE) ?: [] as $f) is_dir($f) ? ps_rmdir($f) : @unlink($f);
    @rmdir($dir);
}

/** Alte Aufträge wegräumen (läuft im Worker). */
function ps_cleanup(): void {
    foreach (glob(PS_DIR . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (filemtime($d) < time() - PS_KEEP_HOURS * 3600) ps_rmdir($d);
    }
    foreach (glob(PS_THUMB_DIR . '/*/*.webp') ?: [] as $f) if (filemtime($f) < time() - 90 * 86400) @unlink($f);
}

/** Ältesten wartenden Auftrag slicen. false = nichts zu tun. */
function ps_worker_run_one(): bool {
    $next = null;
    foreach (glob(PS_DIR . '/*/job.json') ?: [] as $f) {
        $j = json_decode((string)file_get_contents($f), true);
        if (($j['status'] ?? '') === 'queued' && (!$next || $j['created'] < $next['created'])) $next = $j;
    }
    if (!$next) return false;
    $next['status'] = 'slicing';
    $next['started'] = time();
    ps_job_save($next);
    try {
        $next = ($next['ext'] ?? '') === 'gcode' ? ps_gcode_prepare($next) : ps_slice($next);
        $next['status'] = 'done';
    } catch (Throwable $e) {
        $next['status'] = 'error';
        $next['error'] = mb_substr($e->getMessage(), 0, 300);
        // Orca meldet ein zu großes Modell nur als „plate is empty / no object fully inside“ → nachmessen und klar sagen
        if (preg_match('/plate is empty|fully inside|Nothing to be sliced/i', $next['error'])) {
            try { if ($fit = ps_bed_misfit($next)) $next['error_bed'] = $fit; } catch (Throwable $e2) {}
        }
        // Mehr STL-Teile als aufs Bett passen (ps_slice): wie viele passen
        if (preg_match('/^overflow:(\d+)\/(\d+)$/', $next['error'], $mo)) $next['error_fit'] = [(int)$mo[1], (int)$mo[2]];
    }
    $next['finished'] = time();
    ps_job_save($next);
    return true;
}

/** Außenmaße (mm) der STL-Dateien eines Auftrags (model.stl bzw. aus der 3MF gezogene geo/*.stl), binär oder ASCII. */
function ps_mesh_size(array $files): ?array {
    $mn = [INF, INF, INF]; $mx = [-INF, -INF, -INF];
    foreach ($files as $f) {
        $fh = @fopen($f, 'rb'); if (!$fh) continue;
        $head = fread($fh, 84);
        $n = strlen($head) === 84 ? unpack('V', substr($head, 80, 4))[1] : 0;
        if ($n > 0 && filesize($f) === 84 + $n * 50) {
            while (($rec = fread($fh, 50 * 2000)) !== '' && $rec !== false) {
                for ($o = 0; $o + 50 <= strlen($rec); $o += 50) {
                    $v = unpack('g9', substr($rec, $o + 12, 36));
                    for ($k = 0; $k < 9; $k++) { $a = $k % 3; $mn[$a] = min($mn[$a], $v[$k + 1]); $mx[$a] = max($mx[$a], $v[$k + 1]); }
                }
            }
        } else {
            rewind($fh);
            while (($line = fgets($fh)) !== false) {
                if (preg_match('/^\s*vertex\s+(\S+)\s+(\S+)\s+(\S+)/', $line, $m)) for ($a = 0; $a < 3; $a++) { $mn[$a] = min($mn[$a], (float)$m[$a + 1]); $mx[$a] = max($mx[$a], (float)$m[$a + 1]); }
            }
        }
        fclose($fh);
    }
    return $mn[0] === INF ? null : [round($mx[0] - $mn[0], 1), round($mx[1] - $mn[1], 1), round($mx[2] - $mn[2], 1)];
}

/** Passt das Modell nicht aufs Bett (auch nicht um 90° gedreht)? → ['model' => [x,y,z], 'bed' => [x,y,z]], sonst null. */
function ps_bed_misfit(array $job): ?array {
    $dir = ps_job_dir($job['id']);
    // Mehrere STL (23.10.2026): jede für sich messen — ihre Koordinaten haben nichts miteinander zu tun
    if ($job['ext'] === 'stl' && count($job['parts'] ?? []) > 1) {
        foreach ($job['parts'] as $pt) if ($r = ps_bed_misfit(['parts' => null, 'file' => $pt['file']] + $job)) return $r;
        return null;
    }
    $files = $job['ext'] === 'stl' ? ["$dir/" . ($job['file'] ?? 'model.stl')] : (glob("$dir/geo/*.stl") ?: []);
    $size = ps_mesh_size($files);
    if (!$size) return null;
    $m = ps_flatten($job['printer']['vendor'], 'machine', $job['printer']['machine'], []);
    $xs = $ys = [];
    foreach ((array)($m['printable_area'] ?? []) as $pt) { [$x, $y] = array_map('floatval', explode('x', $pt)); $xs[] = $x; $ys[] = $y; }
    if (!$xs) return null;
    $bed = [round(max($xs) - min($xs)), round(max($ys) - min($ys)), round((float)($m['printable_height'] ?? 0))];
    $fits = fn($a, $b) => $a <= $bed[0] + 0.5 && $b <= $bed[1] + 0.5;
    $too_high = $bed[2] > 0 && $size[2] > $bed[2] + 0.5;
    return (!$fits($size[0], $size[1]) && !$fits($size[1], $size[0])) || $too_high ? ['model' => $size, 'bed' => $bed] : null;
}

function ps_slice(array $job): array {
    $dir = ps_job_dir($job['id']);
    $v = $job['printer']['vendor']; $machine = $job['printer']['machine'];
    $xo = $job['x'] ?? null;
    // Größe (Experten-Einstellungen): Orcas --scale stürzt bei STL ab (150 %: Segfault) und verschiebt 3MF-Teile
    // gegeneinander → selbst skalieren: STL-Punkte bzw. die Build-Items der gewählten Platte. Nur einmal je Auftrag.
    if ($xo && abs(($xo['scale'] ?? 1) - 1) > 1e-6 && !is_file("$dir/.scaled")) {
        if ($job['ext'] === 'stl') foreach ($job['parts'] ?? [['file' => $job['file']]] as $pt) ps_stl_scale("$dir/{$pt['file']}", (float)$xo['scale']);
        else ps_3mf_scale("$dir/{$job['file']}", (float)$xo['scale'], $job['info3mf']['plate_objs'] ?? null);
        touch("$dir/.scaled");
    }
    $process = ps_process_for($v, $machine);
    $filament = ps_filament_for($v, $machine, (string)$job['slot']['material']);

    $m = ps_flatten($v, 'machine', $machine, ps_orca_self_inherit() ? ['inherits' => $machine] : []);   // inherits = eigener Name, sonst „nicht kompatibel“ (bis Orca 2.4)
    // Kobra 3: Randstreifen als Ring (ganzes Bett + inneres Quadrat) — die CLI liest das als EINE Fläche über das
    // ganze Bett → „One of the plate is empty“. Sperrfläche, die mit dem kompletten Bett beginnt, weglassen.
    $ex = array_values((array)($m['bed_exclude_area'] ?? []));
    $area = array_values((array)($m['printable_area'] ?? []));
    if ($area && count($ex) > count($area) && array_slice($ex, 0, count($area)) === $area) $m['bed_exclude_area'] = ['0x0'];
    // Bett nivellieren (ps_level_info): Befehl in den Start-Code, wenn der Drucker es nicht selbst steuern kann
    if (!empty($job['level']) && ($job['level_mode'] ?? '') === 'insert' && !empty($job['level_cmd']) && is_string($m['machine_start_gcode'] ?? null))
        $m['machine_start_gcode'] = ps_level_insert($m['machine_start_gcode'], $job['level_cmd']) ?? $m['machine_start_gcode'];
    $pover = ['compatible_printers' => [$machine], 'curr_bed_type' => PS_BED_TYPES[$job['bed']]];
    // Designer-Werte aus der 3MF zuerst, unsere Wahl (Stützen) danach — die gewinnt
    foreach ($job['info3mf']['keep'] ?? [] as $k => $val) {
        if ($k === 'layer_height' && ((float)$val < 0.08 || (float)$val > 0.32)) continue;
        $pover[$k] = $val;
    }
    // Bambu Studio 2.7: „Unknown label object id“ → Orca bricht ab (auch einfarbig, daher kam der Geometrie-Rückfall).
    // Ohne Objekt-Beschriftung im G-Code liest Orca die 3MF direkt — am 02.10. mit „Dancing Skeleton“ belegt.
    // Kostet nur „einzelnes Objekt abbrechen“ am Drucker.
    if ($job['ext'] === '3mf') $pover['exclude_object'] = '0';
    $pover['enable_support'] = $job['supports'] ? '1' : '0';
    if ($job['supports'] && empty($job['info3mf']['keep']['support_type'])) $pover['support_type'] = 'tree(auto)';
    // Experten-Einstellungen zuletzt: sie gewinnen über Profil und 3MF. Stützenwerte nur, wenn Stützen gedruckt werden.
    $applied = [];
    foreach ($xo['proc'] ?? [] as $k => $val) {
        if (str_starts_with($k, 'support_') && !$job['supports']) continue;
        if ($k === 'layer_height') {
            $lim = ps_machine_caps($v, $machine)['lh'];
            $lh = max($lim[0], min($lim[1], (float)$val));
            if (abs($lh - (float)$val) > 1e-6) $job['x_clamped'] = ['layer_height', (float)$val, $lh];
            $val = rtrim(rtrim(sprintf('%.3F', $lh), '0'), '.');
        }
        $pover[$k] = $val;
        if ($k !== 'support_style') $applied[] = $k;
    }
    $job['x_applied'] = $applied;
    $p = ps_flatten($v, 'process', $process, $pover);
    // Filamentprofil aus dem Fach; Farbe des Fachs in den G-Code (Creality CFS und die Anycubic-Cloud gleichen danach ab)
    $xf = $xo['fil'] ?? [];
    foreach (array_keys($xf) as $k) $applied[] = $k;
    $job['x_applied'] = $applied;
    $bed = $job['bed'];
    $fil_json = function (array $slot, string $file, string $suffix = '') use ($v, $machine, $xf, $bed): string {
        $prof = ps_filament_for($v, $machine, (string)$slot['material']);
        [$fv, $fname] = str_contains($prof, '|') ? explode('|', $prof, 2) : [$v, $prof];
        $fover = ['compatible_printers' => [$machine]];
        if (!empty($slot['color']) && preg_match('/^#[0-9a-f]{6}$/i', $slot['color'])) $fover['filament_colour'] = [strtoupper($slot['color'])];
        $f = ps_flatten($fv, 'filament', $fname, $fover);
        // Temperatur aus den Experten-Einstellungen (15.11.2026): Düse samt Stahl-/Messing-Varianten, Bereich mitziehen
        // (sonst warnt Orca „außerhalb des empfohlenen Bereichs“), Bett für jede Platte, die das Profil kennt (0 = Platte nicht geeignet)
        foreach (['nozzle_temperature' => ['nozzle_temperature', 'nozzle_temperature_BRASS', 'nozzle_temperature_HS'],
                  'nozzle_temperature_initial_layer' => ['nozzle_temperature_initial_layer', 'nozzle_temperature_initial_layer_BRASS', 'nozzle_temperature_initial_layer_HS']] as $xk => $keys) {
            if (!isset($xf[$xk])) continue;
            foreach ($keys as $fk) if ($fk === $xk || isset($f[$fk])) $f[$fk] = [(string)$xf[$xk]];
        }
        foreach (['nozzle_temperature', 'nozzle_temperature_initial_layer'] as $xk) {
            if (!isset($xf[$xk])) continue;
            $lo = (int)(((array)($f['nozzle_temperature_range_low'] ?? []))[0] ?? 0); $hi = (int)(((array)($f['nozzle_temperature_range_high'] ?? []))[0] ?? 0);
            if ($lo && $xf[$xk] < $lo) $f['nozzle_temperature_range_low'] = [(string)$xf[$xk]];
            if ($hi && $xf[$xk] > $hi) $f['nozzle_temperature_range_high'] = [(string)$xf[$xk]];
        }
        if (isset($xf['bed_temperature'])) {
            foreach ($f as $fk => $fv_) if (preg_match('/_plate_temp(_initial_layer)?$/', $fk) && (string)(((array)$fv_)[0] ?? '0') !== '0') $f[$fk] = [(string)$xf['bed_temperature']];
            foreach (['hot_plate_temp', 'hot_plate_temp_initial_layer', 'textured_plate_temp', 'textured_plate_temp_initial_layer'] as $fk) $f[$fk] = [(string)$xf['bed_temperature']];
        }
        $f = ps_bed_temp_fill($f, $bed);   // Profil ohne Temperatur für diese Platte (Snapmaker U1, 17.11.2026)
        // Prusa-Startskripte lesen filament_notes[initial_tool] (MBL-Temperatur) — fehlt das Feld, bricht Orca mehrfarbig mit „Indexing an
        // empty vector variable“ ab (XL 5T, MK4/CORE One MMU3, CORE One INDX; Durchlauf aller gängigen Drucker 26.11.2026)
        if (!isset($f['filament_notes'])) $f['filament_notes'] = [''];
        if ($suffix !== '') $f['name'] = ($f['name'] ?? $fname) . $suffix;   // gleiche Namen mehrfach mag Orca nicht
        file_put_contents($file, json_encode($f, JSON_UNESCAPED_SLASHES));
        return $prof;
    };
    foreach (['m' => $m, 'p' => $p] as $k => $d) file_put_contents("$dir/$k.json", json_encode($d, JSON_UNESCAPED_SLASHES));
    $multi = !empty($job['fils']);
    $fil_files = ["$dir/f.json"];
    if ($multi) {
        // Je Projekt-Filament (1…nfil) ein Profil in genau dieser Reihenfolge — Orca nummeriert T0, T1 … danach.
        // Nicht zugeordnete (auf Platte 1 unbenutzte) Filamente bekommen das Profil des ersten Fachs.
        $by_ext = array_column($job['fils'], null, 'ext');
        $first = $job['fils'][0]['slot'];
        $fil_files = []; $filament = [];
        for ($e = 1; $e <= max((int)($job['info3mf']['nfil'] ?? 1), max(array_keys($by_ext))); $e++) {
            $fil_files[] = "$dir/f$e.json";
            $prof = $fil_json($by_ext[$e]['slot'] ?? $first, "$dir/f$e.json", " #$e");
            if (isset($by_ext[$e])) $filament[] = $prof;
        }
        $filament = implode(' + ', array_unique($filament));
    } else {
        $filament = $fil_json($job['slot'], "$dir/f.json");
    }

    $t0 = microtime(true);
    // STL: Orca dreht auf die günstigste Seite. 3MF: Ausrichtung des Designers behalten.
    // Mehrfarbig (oder nur Filament 2+ benutzt): Orca bräuchte je Extruder ein Filament → vorerst einfarbig im
    // gewählten Fach über den Geometrie-Weg. Mehrfarbdruck mit Fach-Zuordnung ist die nächste Ausbaustufe.
    $i3 = $job['info3mf'] ?? null;
    $pl = (string)(int)($i3['plate'] ?? 1);   // gewählte Platte der 3MF
    $as_single = !$multi && $i3 && (!empty($i3['multi']) || ($i3['extruders'] ?? [1]) !== [1]);
    // Teile weglassen (identify_id je Instanz, ps_inspect_3mf) — gilt für jeden 3MF-Lauf; der Geometrie-Rückfall lässt sie selbst weg
    $x3 = !empty($i3['skip']) ? ['--skip-objects', implode(',', $i3['skip'])] : [];
    // Für jeden Lauf (Experten-Einstellungen): Drehen beim Anordnen, Pausen bei Höhe (Orca setzt dort machine_pause_gcode
    // des Druckers ein, z. B. M601 bzw. PAUSE — ersetzt Pausen/Farbwechsel aus der 3MF)
    $xs = [];
    // Flashforge Creator 5 / 5 Pro (22.11.2026): zusätzlich die vollständige Projektdatei mit G-Code, wie sie der Flashforge-Slicer
    // an den Drucker schickt (ps_ff_full3mf). --min-save lässt die Modellgeometrie weg (SkipModel im Slicer).
    $ff_c5 = ps_ff_c5_model($machine);
    if ($ff_c5) array_push($xs, '--export-3mf', 'full.3mf', '--min-save');
    if (!empty($xo['rot'])) $xs[] = '--allow-rotations';
    if (!empty($xo['pause'])) {
        file_put_contents("$dir/cg.json", json_encode(['mode' => $multi ? 'MultiExtruder' : 'SingleExtruder',
            'gcodes' => array_map(fn($z) => ['type' => 'PausePrint', 'print_z' => (float)$z, 'color' => '', 'extruder' => 1, 'extra' => ''], $xo['pause'])]));
        array_push($xs, '--load-custom-gcodes', "$dir/cg.json");
    }
    // STL (eine oder mehrere Dateien auf einer Platte): Ausrichten, Kopien je Datei, Filament je Datei
    $parts = $job['parts'] ?? null;
    $inputs = $job['ext'] === 'stl' && $parts ? array_map(fn($pt) => "$dir/{$pt['file']}", $parts) : ["$dir/{$job['file']}"];
    $xstl = ['--orient', ($xo['orient'] ?? true) ? '1' : '0'];
    if ($parts && max(array_column($parts, 'copies')) > 1) array_push($xstl, '--clone-objects', implode(',', array_column($parts, 'copies')));
    if ($parts && $multi) array_push($xstl, '--load-filament-ids', implode(',', array_map(fn($pt) => (int)$pt['fil'], $parts)));
    $is_stl = $job['ext'] === 'stl';
    // Anordnung (20.10.2026): Häkchen „Automatisch anordnen“ → --arrange 1, Orca verteilt neu und dreht dabei auch ohne
    // --allow-rotations (Battery Butler am Kobra S1: Deckel um 45°). Sonst bleibt die Anordnung aus der Datei — Orca rückt
    // sie nur auf die Bettmitte (Bambu 256 → Kobra 250); passt sie nicht aufs Bett, ordnet Orca neu an (rearranged).
    $auto = !empty($job['arrange']);
    if ($as_single) { $rc = 1; $res = ['error_string' => 'multicolor']; }
    elseif ($multi) {
        // Wischturm: --load-settings verwirft die Position aus der 3MF, Orca nimmt dann X15/Y220 (die Herstellerprofile
        // setzen keine). Der Turm eines K2 mit 4 Filamenten ragt von dort übers Bett („unprintable area“, Prüfcode 4 =
        // außerhalb des Betts; 04.10., Koala Clicker). Erst wie bisher (am Kobra S1 so gedruckt), nur bei diesem Fehler
        // an den Bettrand, wo er in Y wachsen kann; --arrange legt die Objekte daneben.
        // Zuerst ohne --arrange: das dreht bei CoreXY-Druckern mehrfarbig das Modell um 90° (Kobra S1 ja, Kobra X/i3 nein;
        // 07.10., Feedback #43). Vom Turm schiebt Orca es auch so weg; liegt es außerhalb des Betts → wie bisher mit --arrange.
        $tries = $auto || $is_stl ? [] : [[null, '0']];   // STL ordnet Orca immer an
        foreach (array_merge([null], ps_tower_spots((array)($m['printable_area'] ?? []))) as $s) $tries[] = [$s, '1'];
        foreach ($tries as [$spot, $arrange]) {
            if ($spot) {
                $p['wipe_tower_x'] = [(string)$spot[0]]; $p['wipe_tower_y'] = [(string)$spot[1]];
                file_put_contents("$dir/p.json", json_encode($p, JSON_UNESCAPED_SLASHES));
            }
            [$rc, $res] = ps_run_orca($dir, "$dir/out", $inputs, array_merge($is_stl ? $xstl : $x3, $xs), $is_stl ? '0' : $pl, $fil_files, $arrange);
            if ($rc === 0) break;
            // Turm über dem Bettrand: Orca sagt „unprintable area“ oder (Creality Hi, 26.11.2026) „Object conflicts“ + im Protokoll
            // „Prime tower is partially outside the printable area“ → nächste Turmposition probieren
            $tower_out = str_contains((string)($res['error_string'] ?? ''), 'unprintable area') || str_contains((string)@file_get_contents("$dir/out.log"), 'Prime tower is partially outside');
            if ($arrange === '1' && !$tower_out) break;
        }
        $job['tower'] = $spot;
        if ($rc === 0 && $arrange === '1' && !$auto && !$is_stl) $job['rearranged'] = true;
    }
    elseif ($job['ext'] === '3mf') {
        [$rc, $res] = ps_run_orca($dir, "$dir/out", $inputs, array_merge($x3, $xs), $pl, $fil_files, $auto ? '1' : '0');
        if ($rc !== 0 && $rc !== 124 && !$auto) {
            [$rc, $res] = ps_run_orca($dir, "$dir/out", $inputs, array_merge($x3, $xs), $pl, $fil_files, '1');
            if ($rc === 0) $job['rearranged'] = true;
        }
    }
    else [$rc, $res] = ps_run_orca($dir, "$dir/out", $inputs, array_merge($xstl, $xs), '0', $fil_files);
    // Mehrfarbig gibt es keinen Rückfall: der Geometrie-Weg verliert die Farbzuordnung
    if ($rc !== 0 && $multi) throw new RuntimeException('multicolor: ' . ($res['error_string'] ?? ($rc === 124 ? 'Zeitlimit überschritten' : "Orca rc=$rc")));
    if ($rc !== 0 && $job['ext'] === '3mf') {
        // Rückfall: Projekt aus neuerem Bambu Studio (2.7: „Unknown label object id“, Segfault ohne Metadaten) →
        // Geometrie selbst auslesen, je Objekt eine STL, Designer-Werte bleiben über PS_3MF_KEEP erhalten
        $first = $res['error_string'] ?? "rc=$rc";
        $conv = ps_3mf_to_stls("$dir/{$job['file']}", "$dir/geo", $i3['plate_objs'] ?? null, array_fill_keys($i3['skip_inst'] ?? [], true));
        if ($conv['files']) {
            [$rc, $res] = ps_run_orca($dir, "$dir/out_geo", $conv['files'], $xs, '0', $fil_files);
            $job['fallback'] = ['reason' => mb_substr($first, 0, 160), 'objects' => count($conv['files']), 'skipped' => $conv['skipped'], 'single' => $as_single];
            if ($rc === 0) { @rename("$dir/out", "$dir/out_3mf"); rename("$dir/out_geo", "$dir/out"); }
        }
    }
    $gcode = "$dir/out/plate_1.gcode";
    // Orca nennt die Ausgabe nach der Platte (plate_3.gcode) — alles danach (Senden, Vorschau, Anycubic) kennt nur plate_1
    if ($rc === 0 && $pl !== '1' && !is_file($gcode) && is_file("$dir/out/plate_$pl.gcode")) rename("$dir/out/plate_$pl.gcode", $gcode);
    if ($rc !== 0 || !is_file($gcode)) {
        throw new RuntimeException($res['error_string'] ?? ($rc === 124 ? 'Zeitlimit überschritten' : "Orca rc=$rc"));
    }
    // Passt nicht alles aufs Bett, legt Orca still weitere Platten an (plate_2 …) — gedruckt würde nur Platte 1
    if ($is_stl && is_file("$dir/out/plate_2.gcode")) {
        $fit = (int)trim((string)shell_exec("grep -a -o '^; printing object .* id:[0-9]* copy [0-9]*' " . escapeshellarg($gcode) . ' | sort -u | wc -l'));
        throw new RuntimeException('overflow:' . $fit . '/' . array_sum(array_column($parts ?: [['copies' => 1]], 'copies')));
    }
    if (isset($job['tool'])) {
        // CFS (K2): das Startmakro des Profils lädt schon mit „T[initial_no_support_extruder]“ (= T0) — dieses T auf das
        // gewählte Fach umschreiben. Ein zusätzliches T dahinter hieße: erst 1A laden, dann schneiden, wechseln, spülen.
        if (!empty($job['printer']['cfs']) && preg_match('/^T0\b/m', (string)file_get_contents($gcode, false, null, 0, 65536)))
            ps_remap_tools($gcode, [0 => [(int)$job['tool'], (string)$job['slot']['label']]]);
        // Werkzeugwechsler (Snapmaker U1): Start-G-Code gleich für den gewählten Kopf (Meldung #33)
        elseif (ps_machine_toolchanger((string)$job['printer']['vendor'], (string)$job['printer']['machine'])) ps_toolchanger_start($gcode, (int)$job['tool'], (string)$job['slot']['label']);
        else ps_insert_toolchange($gcode, (int)$job['tool'], (string)$job['slot']['label']);
    }
    if ($multi) {
        $by = null;
        if (preg_match('/^; filament used \[g\] = ([\d., ]+)/m', (string)shell_exec('tail -c 40000 ' . escapeshellarg($gcode)), $mg)) $by = array_map('floatval', explode(',', $mg[1]));
        $tools = [];
        $tm = (string)($job['printer']['tools'] ?? '');   // nur App-Drucker: 'seq' / 'keep' (sonst leer → wie bisher)
        if ($job['printer']['provider'] === 'anycubic' || $tm === 'seq') {
            // Anycubic: T-Nummern lückenlos machen (Platte nutzt Filament 1 und 4 → T0, T1 statt T0, T3) — die Fachzuordnung
            // im Startbefehl hat dann genau einen Eintrag je T in Reihenfolge, egal ob die Firmware nach paint_index
            // oder nach Position nachschlägt. Unbenutzte Filamente (bemalte Projekte) bekommen kein Werkzeug.
            $n = 0;
            foreach ($job['fils'] as &$f) {
                $used = $by === null || ($by[$f['ext'] - 1] ?? 0) > 0;
                $f['tool'] = $used ? $n++ : null;
                if ($used) $tools[$f['ext'] - 1] = [$f['tool'], (string)$f['slot']['label']];
            }
            unset($f);
        } elseif ($job['printer']['provider'] === 'creality' || $tm === 'keep') {
            // Creality: G-Code bleibt wie von Orca (T<Filament-1>), welches CFS-Fach je Filament, steht im Druckauftrag
            // (filamentsList, ps_creality_task). tool = T-Nummer nur für Vorschaufarben und „benutzt?“.
            foreach ($job['fils'] as &$f) $f['tool'] = $by === null || ($by[$f['ext'] - 1] ?? 0) > 0 ? $f['ext'] - 1 : null;
            unset($f);
        } else {
            // Klipper: Orca schreibt T<Filament-1>, der Drucker braucht das Werkzeug des gewählten Fachs
            foreach ($job['fils'] as $f) if ($f['tool'] !== null) $tools[$f['ext'] - 1] = [(int)$f['tool'], (string)$f['slot']['label']];
        }
        // Gemischte Temperaturen (PLA 220 + PETG 255): Orca bricht seit --allow-mix-temp nicht mehr ab, in der Oberfläche
        // warnt es nur — wir sagen es nach dem Slicen dazu, die Schichten haften schlechter aneinander
        $temps = [];
        foreach ($job['fils'] as $f) {
            if ($by !== null && ($by[$f['ext'] - 1] ?? 0) <= 0) continue;
            $fj = json_decode((string)@file_get_contents("$dir/f{$f['ext']}.json"), true);
            if ($tc = (int)(((array)($fj['nozzle_temperature'] ?? []))[0] ?? 0)) $temps[] = $tc;
        }
        if ($temps && max($temps) - min($temps) >= PS_MIX_TEMP_NOTE) $job['mix_temp'] = [min($temps), max($temps)];
        // Immer auch G90 + Extrusionsart nach jedem Wechsel (Wechsel-Makro ließ am 02.10. G91 stehen) — nicht bei Creality,
        // dort gehört der Wechsel der Firmware
        // 'keep' (App: Bambu, Elegoo, Creality, Flashforge): T bleibt — Bambu wechselt per M620 S<n>, ein Umschreiben nur der T-Zeilen passte nicht dazu
        // Werkzeugwechsler (U1): alle Kopfangaben umsetzen, nicht nur T-Zeilen (ps_tool_perm, 25.11.2026)
        if ($job['printer']['provider'] !== 'creality' && $tm !== 'keep') ps_remap_tools($gcode, $tools, $job['printer']['provider'] === 'moonraker' && $tm === '',
            $job['printer']['provider'] === 'moonraker' && ps_machine_toolchanger((string)$job['printer']['vendor'], (string)$job['printer']['machine']));
    }
    $job['slice_seconds'] = round(microtime(true) - $t0, 1);
    $job['profiles'] = ['machine' => $machine, 'process' => $process, 'filament' => $filament];
    // Mehrfarbig: Vorschau in den Farben der gewählten Fächer (T-Nummer im fertigen G-Code → Fachfarbe)
    $pal = [];
    if ($multi) foreach ($job['fils'] as $f) {
        $c = (string)($f['slot']['color'] ?? '');
        if ($f['tool'] !== null && preg_match('/^#[0-9a-f]{6}$/i', $c)) $pal[(int)$f['tool']] = strtolower($c);
    }
    $job['stats'] = ps_preview_build($gcode, "$dir/preview.bin.gz", $m, $pal);
    try { ps_thumbs_after_slice($dir, $gcode, $m, $job['slot']['color'] ?? null); } catch (Throwable $e) {}
    // Nach allem, was den G-Code noch ändert (Werkzeugwechsel, Vorschaubilder): die Datei bekommt genau diesen G-Code
    unset($job['full3mf']);
    if ($ff_c5) { try { if (ps_ff_full3mf($dir, $gcode, $ff_c5)) $job['full3mf'] = true; } catch (Throwable $e) {} }
    return $job;
}

/** „Flashforge-Creator-5-Pro“ / „Flashforge-Creator-5“ (model_id der Orca-Profile) für diese Drucker, sonst null */
function ps_ff_c5_model(string $machine): ?string {
    if (!preg_match('/^Flashforge Creator 5( Pro)?\b/i', $machine, $mm)) return null;
    return 'Flashforge-Creator-5' . (!empty($mm[1]) ? '-Pro' : '');
}

/**
 * Vollständige Druckdatei für den Flashforge Creator 5 → $dir/out/plate_1.3mf. Grundlage ist Orcas eigene Ausgabe
 * (--export-3mf --min-save: project_settings, model_settings, plate_1.json, leeres 3dmodel). Angeglichen an das, was der
 * Flashforge-Slicer schickt (Orca-Flashforge 1.7.15 SendToPrinter.cpp: immer .3mf, WithGcode | SkipModel; Beispieldatei
 * FlashStudio 1.7.6 „c5pro-2color“): slice_info mit printer_model_id, extruder_type / nozzle_volume_type je Kopf und uuid,
 * ohne die Felder, die nur Orca 2.5 schreibt; Vorschaubilder samt Verweisen; G-Code + Prüfsumme = der fertige G-Code.
 * false = nicht gebaut (z. B. andere Platte als 1) — die App nimmt dann ihren bisherigen Weg.
 */
function ps_ff_full3mf(string $dir, string $gcode, string $model_id): bool {
    $src = "$dir/out/full.3mf"; $dst = "$dir/out/plate_1.3mf";
    @unlink($dst);
    if (!is_file($src) || !class_exists('ZipArchive')) return false;
    $z = new ZipArchive();
    if ($z->open($src) !== true) return false;
    $info = $z->getFromName('Metadata/slice_info.config');
    $ms = $z->getFromName('Metadata/model_settings.config');
    $rels = $z->getFromName('_rels/.rels');
    if ($info === false || $ms === false || $rels === false || $z->locateName('Metadata/plate_1.gcode') === false
        || !preg_match('/<metadata key="nozzle_diameters" value="([^"]*)"/', $info, $nd)) { $z->close(); @unlink($src); return false; }
    $heads = max(1, count(explode(',', $nd[1])));
    $zeros = implode(' ', array_fill(0, $heads, '0'));
    $info = preg_replace('/(<header_item key="X-BBL-Client-Version" value=")[^"]*"/', '$1"', $info);
    $info = preg_replace('/^\s*<header_item key="OrcaSlicer-Version"[^>]*>\R/m', '', $info);
    $info = preg_replace('/^(\s*)<\/header>/m', '$1  <uuid value="' . bin2hex(random_bytes(16)) . '"/>' . "\n" . '$1</header>', $info, 1);
    $info = preg_replace('/(<metadata key="(?:extruder_type|nozzle_volume_type)" value=")[^"]*"/', '${1}' . $zeros . '"', $info);
    $info = preg_replace('/(<metadata key="printer_model_id" value=")[^"]*"/', '${1}' . $model_id . '"', $info);
    $info = preg_replace('/^\s*<metadata key="(?:enable_filament_dynamic_map|has_filament_switcher)"[^>]*>\R/m', '', $info);
    $info = preg_replace('/^\s*<nozzle [^>]*>\R/m', '', $info);
    $info = preg_replace('/(<filament id="[^"]*" tray_info_idx="[^"]*" type="[^"]*" color="[^"]*" used_m="[^"]*" used_g="[^"]*")[^>]*\/>/', '$1 />', $info);
    $z->addFromString('Metadata/slice_info.config', $info);
    // Bilder: ohne Oberfläche erzeugt Orca keine — unser Modellbild (ps_thumbs_after_slice) in den Größen des Slicers
    if (is_file("$dir/thumb.png") && ($im = @imagecreatefrompng("$dir/thumb.png"))) {
        imagesavealpha($im, true);
        $png = function (int $w) use ($im): string {
            $o = imagecreatetruecolor($w, $w);
            imagealphablending($o, false); imagesavealpha($o, true);
            imagefill($o, 0, 0, imagecolorallocatealpha($o, 0, 0, 0, 127));
            imagecopyresampled($o, $im, 0, 0, 0, 0, $w, $w, imagesx($im), imagesy($im));
            ob_start(); imagepng($o, null, 9); imagedestroy($o);
            return (string)ob_get_clean();
        };
        $big = $png(512);
        foreach (['plate_1.png' => $big, 'plate_no_light_1.png' => $big, 'top_1.png' => $big, 'pick_1.png' => $big, 'plate_1_small.png' => $png(128)] as $n => $d) $z->addFromString("Metadata/$n", $d);
        imagedestroy($im);
        if (!str_contains($ms, 'thumbnail_file')) {
            $ms = preg_replace('/^(\s*)(<metadata key="gcode_file"[^>]*>)/m', '$1$2' . "\n" . '$1<metadata key="thumbnail_file" value="Metadata/plate_1.png"/>' . "\n"
                . '$1<metadata key="thumbnail_no_light_file" value="Metadata/plate_no_light_1.png"/>' . "\n" . '$1<metadata key="top_file" value="Metadata/top_1.png"/>' . "\n"
                . '$1<metadata key="pick_file" value="Metadata/pick_1.png"/>' . "\n" . '$1<metadata key="pattern_bbox_file" value="Metadata/plate_1.json"/>', $ms, 1);
            $z->addFromString('Metadata/model_settings.config', $ms);
        }
        if (!str_contains($rels, 'plate_1.png')) {
            $rels = str_replace('</Relationships>', ' <Relationship Target="/Metadata/plate_1.png" Id="rel-2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/thumbnail"/>' . "\n"
                . ' <Relationship Target="/Metadata/plate_1.png" Id="rel-4" Type="http://schemas.bambulab.com/package/2021/cover-thumbnail-middle"/>' . "\n"
                . ' <Relationship Target="/Metadata/plate_1_small.png" Id="rel-5" Type="http://schemas.bambulab.com/package/2021/cover-thumbnail-small"/>' . "\n" . '</Relationships>', $rels);
            $z->addFromString('_rels/.rels', $rels);
        }
    }
    $z->addFile($gcode, 'Metadata/plate_1.gcode');
    $z->addFromString('Metadata/plate_1.gcode.md5', strtoupper((string)md5_file($gcode)));
    $ok = $z->close();
    if (!$ok) { @unlink($src); return false; }
    return rename($src, $dst);
}

/**
 * Wischturm-Positionen (unten rechts, unten links) aus printable_area [„0x0“, „260x0“, …] — 65 mm Platz für Turm
 * (35–40 mm breit) samt Rand, 10 mm vom Bettrand. Tiefe wächst in Y mit der Spülmenge, deshalb unten.
 */
function ps_tower_spots(array $area): array {
    $w = $h = 0.0;
    foreach ($area as $pt) { $xy = explode('x', (string)$pt); $w = max($w, (float)$xy[0]); $h = max($h, (float)($xy[1] ?? 0)); }
    return $w > 100 && $h > 100 ? [[(int)round($w - 65), 10], [10, 10]] : [];
}

/**
 * Fachwahl für Klipper mit ACE/MMU: „T<n> ; change extruder“ an genau die Stelle, an die Orca sie bei einem
 * Modell auf Filament n+1 selbst schreibt — direkt nach „M83“ hinter dem Startmakro (am 01.10. mit dem
 * Kobra-S1-Profil verglichen). Fallback: vor der ersten Schicht.
 */
/**
 * Polster aus reinen Kommentarzeilen (nur „;“) direkt nach einem Fachwechsel, das die nächsten zwei 4-KiB-Grenzen der Datei
 * überdeckt. Anlass (05.10.2026): Klipper-Drucke auf dem Kobra S1 (Rinkhals, Anycubic-Firmware „gklib“) brachen 3 von 5
 * Mal kurz nach dem Start ab, immer an der ERSTEN 4-KiB-Grenze (file_position 4083/4087): Die Firmware setzte dort beim
 * Weiterlesen ein paar Byte zu früh an und klebte zwei Zeilenstücke zusammen („G1 X107.8“ + „0.597 …“ → ParseFloat-Fehler,
 * am 02.10. „Move out of range“). Unsere Dateien haben kein Vorschaubild (Orca ohne Grafik), normale Slicer-Dateien schon —
 * dort liegen die ersten Grenzen in Kommentaren und der Fehler bleibt folgenlos. Mit dem Polster liegt jede betroffene Stelle
 * in Kommentaren. Die Grenzen fallen bewusst mitten in eine Zeile: Das angeklebte Stück hängt dann immer an einer „;“-Zeile.
 */
function ps_pad_comments($out): void {
    $pos = ftell($out);
    $b1 = (intdiv($pos, 4096) + 1) * 4096;          // nächste Grenze
    $end = $b1 + 4096 + 1024;                        // + die übernächste, mit Rand dahinter
    $first = ($b1 - $pos - 32) % 64;                 // erste Zeile so lang, dass die Grenzen 32 Byte in eine Zeile fallen
    if ($first < 2) $first += 64;
    $pad = str_repeat(';', $first - 1) . "\n";
    $pad .= str_repeat(str_repeat(';', 63) . "\n", intdiv($end - $pos - $first, 64) + 1);
    fwrite($out, $pad);
}

/** Werkzeugwechsler mit mehreren Düsen (Snapmaker U1, Prusa XL)? Das Maschinenprofil hat dann mehr als einen Düsendurchmesser. */
function ps_machine_toolchanger(string $vendor, string $machine): bool {
    static $c = [];
    if (!isset($c["$vendor|$machine"])) {
        try { $n = count((array)(ps_flatten($vendor, 'machine', $machine)['nozzle_diameter'] ?? [])); } catch (Throwable $e) { $n = 1; }
        $c["$vendor|$machine"] = $n > 1;
    }
    return $c["$vendor|$machine"];
}

/**
 * Einfarbig auf einem Werkzeugwechsler mit Kopf $tool (Meldung #33, 24.11.2026, Snapmaker U1): Orca schreibt den Start-G-Code
 * für Kopf 1 (T0 heizen, vorextrudieren, die anderen Köpfe vorheizen und abschalten). Ein T<n> erst danach (ps_insert_toolchange)
 * hieße: Kopf 1 vorbereitet, Kopf n kalt. Darum im Start-G-Code (bis zur ersten Schicht) Kopf 1 und Kopf n tauschen — T-Zeilen,
 * T-Angaben von M104/M109/M568/G10, EXTRUDER=/INDEX=/TOOL= und extruder/extruderN — das ist der Start-G-Code, den Orca mit
 * Kopf n als Startkopf schreibt. Spätere Werkzeugwahl entfällt (einfarbig gibt es nur diesen Kopf).
 */
function ps_toolchanger_start(string $gcode, int $tool, string $label): void {
    $sw = fn(int $n): int => $n === 0 ? $tool : ($n === $tool ? 0 : $n);
    $in = fopen($gcode, 'r');
    $out = fopen("$gcode.tmp", 'w');
    $body = false;
    while (($l = fgets($in)) !== false) {
        if (!$body && strncmp($l, ';LAYER_CHANGE', 13) === 0) $body = true;
        if (trim($l) === '' || $l[0] === ';') { fwrite($out, $l); continue; }
        if ($body) {
            if ($l[0] === 'T' && preg_match('/^T\d+\b/', $l)) { fwrite($out, '; ' . rtrim($l) . " ; 3DSEARCH: Orcas Werkzeugwahl entfernt, Kopf $label bleibt\n"); continue; }
            fwrite($out, $l); continue;
        }
        $k = strpos($l, ';');
        $code = $k === false ? rtrim($l, "\r\n") : substr($l, 0, $k);
        $rest = $k === false ? "\n" : substr($l, $k);
        $new = preg_replace_callback('/^T(\d+)\b/', fn($m) => 'T' . $sw((int)$m[1]), $code);
        if (preg_match('/^(M104|M109|M568|G10)\b/', $new)) $new = preg_replace_callback('/\bT(\d+)\b/', fn($m) => 'T' . $sw((int)$m[1]), $new);
        $new = preg_replace_callback('/\b(EXTRUDER|INDEX|TOOL)=(\d+)\b/i', fn($m) => $m[1] . '=' . $sw((int)$m[2]), $new);
        $new = preg_replace_callback('/\b(HEATER|EXTRUDER)=extruder(\d*)\b/i', fn($m) => $m[1] . '=extruder' . (($x = $sw($m[2] === '' ? 0 : (int)$m[2])) ? $x : ''), $new);
        fwrite($out, $new . ($new !== $code ? ($k === false ? " ; 3DSEARCH: Kopf $label statt Kopf 1\n" : $rest) : $rest));
    }
    fclose($in); fclose($out);
    rename("$gcode.tmp", $gcode);
}

function ps_insert_toolchange(string $gcode, int $tool, string $label): void {
    $in = fopen($gcode, 'r');
    $out = fopen("$gcode.tmp", 'w');
    // Nach dem Wechsel Positionierung neu setzen: Das Fachwechsel-Makro von Rinkhals ließ am 02.10.2026 (T3) offenbar den
    // relativen Modus stehen → erste Fahrt „Move out of range“, zweimal reproduzierbar an derselben Stelle. Schadet sonst nie.
    $line_t = "; 3DSEARCH: nach dem Fachwechsel folgt ein Kommentar-Polster gegen einen Lesefehler der Firmware an 4-KiB-Grenzen\n"
            . "T$tool ; change extruder (3DSEARCH: slot $label)\nG90 ; 3DSEARCH: absolute Positionierung nach dem Fachwechsel\nM83 ; 3DSEARCH: relative Extrusion\n";
    $done = false; $custom = false;
    while (($l = fgets($in)) !== false) {
        if (!$done && strncmp($l, ';LAYER_CHANGE', 13) === 0) { fwrite($out, $line_t); ps_pad_comments($out); $done = true; }
        // Projekte mit mehreren Filamenten (Bambu-3MF, 8 Farben): Orca wählt nach dem Filament-Startcode selbst „T0“ —
        // das schaltete am 20.10.2026 hinter unserem T1 zurück auf Fach 1 (Battery Butler, Kobra S1: Orange gewählt, Blau
        // gedruckt). Einfarbig gibt es nur unser Fach, also jede spätere Werkzeugwahl auskommentieren.
        if ($done && $l[0] === 'T' && preg_match('/^T\d+\b/', $l)) { fwrite($out, '; ' . rtrim($l) . " ; 3DSEARCH: Orcas Werkzeugwahl entfernt, Fach $label bleibt\n"); continue; }
        fwrite($out, $l);
        if (strncmp($l, ';TYPE:Custom', 12) === 0) $custom = true;
        if (!$done && $custom && preg_match('/^M83\b/', $l)) { fwrite($out, $line_t); ps_pad_comments($out); $done = true; }
    }
    fclose($in); fclose($out);
    rename("$gcode.tmp", $gcode);
}

/**
 * Mehrfarbig über Klipper: jedes „T<n>“ (n = Projekt-Filament − 1) auf das Werkzeug des zugeordneten Fachs
 * umschreiben, danach wie bei ps_insert_toolchange G90/M83 (Rinkhals' Wechsel-Makro lässt G91 stehen).
 * $tools = [n => [werkzeug, fachname]]. Unbekannte n bleiben, wie sie sind.
 */
/**
 * Orca ab 2.5 löst „inherits“ über einen system-Ordner auf und bricht ohne ihn ab („can not resolve preset … "system"“) —
 * das flache Maschinenprofil braucht den Selbstverweis dort nicht mehr, die Kompatibilität passt über compatible_printers
 * (Test 11.11.2026, AllMyPrinters Slicer 1.0.4). Version aus /opt/orcaslicer/VERSION (Container); fehlt sie (Website-Server),
 * bleibt alles wie bisher.
 */
function ps_orca_self_inherit(): bool {
    static $v = null;
    if ($v === null) { $ver = trim((string)@file_get_contents(dirname(PS_ORCA, 2) . '/VERSION')); $v = $ver === '' || version_compare($ver, '2.5.0', '<'); }
    return $v;
}

/**
 * Werkzeugwechsler (Snapmaker U1, 25.11.2026 — Chris' U1: „Düsentemperatur zu niedrig“ beim Wechsel auf den anderen Kopf): Orcas
 * Kopfwechsel-Code heizt per „M109 S… T<n>“ und extrudiert per „SM_PRINT_PREEXTRUDE_FILAMENT INDEX=<n>“ vor — wurde nur die T-Zeile
 * umgesetzt, heizte der Drucker den Kopf aus Orcas Reihenfolge und wechselte auf einen kalten. Darum hier ALLE Kopfangaben im ganzen
 * G-Code (auch Startcode: initial_extruder) über eine vollständige Vertauschung: gewählte Köpfe wie zugeordnet, übrige Orca-Nummern
 * auf die übrigen Köpfe (Startcode spricht alle 4 Köpfe an — Vorheizen, Einziehen, Flusskalibrierung bleiben je Kopf einmal).
 * Gleiche Regeln wie ps_toolchanger_start (einfarbig): T-Zeilen, T bei M104/M109/M568/G10, EXTRUDER=/INDEX=/TOOL=, extruderN.
 */
function ps_tool_perm(array $tools): array {
    $perm = [];
    foreach ($tools as $src => $v) $perm[(int)$src] = (int)$v[0];
    $max = max(array_merge(array_keys($perm), array_values($perm), [3]));
    for ($s = 0; $s <= $max; $s++) if (!isset($perm[$s]) && !in_array($s, $perm, true)) $perm[$s] = $s;
    $free = array_values(array_diff(range(0, $max), array_values($perm)));
    for ($s = 0; $s <= $max; $s++) if (!isset($perm[$s])) $perm[$s] = array_shift($free) ?? $s;
    ksort($perm);
    return $perm;
}

function ps_remap_tools(string $gcode, array $tools, bool $pad = false, bool $tc = false): void {
    $in = fopen($gcode, 'r');
    $out = fopen("$gcode.tmp", 'w');
    $e_mode = 'M83';   // Extrusionsart, die vor dem Wechsel galt, wiederherstellen (nicht blind M83)
    $perm = $tc ? ps_tool_perm($tools) : null;
    $sw = fn(int $n): int => $perm[$n] ?? $n;
    while (($l = fgets($in)) !== false) {
        if ($l[0] === 'M' && preg_match('/^M8[23]\b/', $l)) $e_mode = substr($l, 0, 3);
        if ($perm !== null && $l[0] !== ';' && $l[0] !== 'T' && trim($l) !== '') {
            $k = strpos($l, ';');
            $code = $k === false ? rtrim($l, "\r\n") : substr($l, 0, $k);
            $new = $code;
            if (preg_match('/^(M104|M109|M568|G10)\b/', $new)) $new = preg_replace_callback('/\bT(\d+)\b/', fn($m) => 'T' . $sw((int)$m[1]), $new);
            $new = preg_replace_callback('/\b(EXTRUDER|INDEX|TOOL)=(\d+)\b/i', fn($m) => $m[1] . '=' . $sw((int)$m[2]), $new);
            $new = preg_replace_callback('/\b(HEATER|EXTRUDER)=extruder(\d*)\b/i', fn($m) => $m[1] . '=extruder' . (($x = $sw($m[2] === '' ? 0 : (int)$m[2])) ? $x : ''), $new);
            if ($new !== $code) { fwrite($out, $new . ($k === false ? " ; 3DSEARCH: Kopf umgesetzt\n" : substr($l, $k))); continue; }
        }
        if ($l[0] === 'T' && preg_match('/^T(\d+)\b/', $l, $m) && ($perm !== null ? isset($perm[(int)$m[1]]) : isset($tools[(int)$m[1]]))) {
            [$t, $label] = $tools[(int)$m[1]] ?? [$sw((int)$m[1]), (string)($sw((int)$m[1]) + 1)];
            fwrite($out, "T$t ; change extruder (3DSEARCH: filament " . ((int)$m[1] + 1) . " -> slot $label)\nG90\n$e_mode\n");
            if ($pad) ps_pad_comments($out);   // Klipper/Kobra: siehe ps_pad_comments
            continue;
        }
        fwrite($out, $l);
    }
    fclose($in); fclose($out);
    rename("$gcode.tmp", $gcode);
}

/**
 * Orca-Aufruf mit einer Korrekturrunde: Bambu-Projekte enthalten Werte, die Orca ablehnt („Param values in
 * 3mf/config error: raft_first_layer_expansion: -1 not in range [0,…]“ — bei Bambu heißt -1 „automatisch“). Fällt
 * nur auf, wenn unser Prozessprofil den Wert nicht selbst setzt (Voron ja, Anycubic nein). Dann den Wert auf die
 * nächste gültige Grenze in p.json schreiben und einmal neu slicen.
 */
function ps_run_orca(string $dir, string $outdir, array $inputs, array $extra, string $plate, array $fils = [], string $arrange = '1'): array {
    [$rc, $res] = ps_run_orca_once($dir, $outdir, $inputs, $extra, $plate, $fils, $arrange);
    if ($rc === 0) return [$rc, $res];
    $log = (string)@file_get_contents($outdir . '.log');
    if (!preg_match_all('/^(\w+): (-?[\d.]+) not in range \[(-?[\d.e+]+),\s*(-?[\d.e+]+)\]/m', $log, $mm, PREG_SET_ORDER)) return [$rc, $res];
    $p = json_decode((string)file_get_contents("$dir/p.json"), true);
    foreach ($mm as [, $k, $v, $lo, $hi]) $p[$k] = (string)((float)$v < (float)$lo ? (float)$lo : (float)$hi);
    file_put_contents("$dir/p.json", json_encode($p, JSON_UNESCAPED_SLASHES));
    @rename($outdir . '.log', $outdir . '.first.log');
    return ps_run_orca_once($dir, $outdir, $inputs, $extra, $plate, $fils, $arrange);
}

/** Ein Orca-Aufruf. Rückgabe [rc, result.json]. $plate: '0' = alle Platten, '1' = Platte 1. $fils: Filamentprofile in T-Reihenfolge. */
function ps_run_orca_once(string $dir, string $outdir, array $inputs, array $extra, string $plate, array $fils = [], string $arrange = '1'): array {
    @mkdir($outdir);
    // Fortschritt (12.10.2026): --pipe schreibt je Schritt eine JSON-Zeile — auch in eine normale Datei, die aber schon
    // existieren muss (sonst schreibt Orca still nichts). Je Lauf leeren: Orca schreibt ab dem Anfang, ohne zu kürzen.
    file_put_contents("$dir/progress.jsonl", '');
    // --allow-mix-temp OHNE Wert: „--allow-mix-temp 1“ hielt Orca die 1 für eine Eingabedatei (rc 253). Ohne den Schalter
    // bricht die CLI bei PLA + PETG ab („temperature difference … too large“), die Oberfläche warnt nur (Feedback #43)
    // --skip-modified-gcodes (23.10.2026): eigener G-Code aus fremden 3MF („Custom“ in custom_gcode_per_layer.xml, z. B.
    // „M117 …“ auf Höhe 5) kam sonst ungeprüft in den Druck; Pausen und Farbwechsel der 3MF bleiben, unsere Profile auch (getestet)
    $args = array_merge([PS_ORCA, '--slice', $plate, '--allow-newer-file', '--allow-mix-temp', '--skip-modified-gcodes', '--pipe', "$dir/progress.jsonl", '--load-settings', "$dir/m.json;$dir/p.json", '--load-filaments', implode(';', $fils ?: ["$dir/f.json"]),
                         '--arrange', $arrange, '--outputdir', $outdir], $extra, $inputs);
    // HOME/TMPDIR je Auftrag: Orca entpackt 3MF nach $TMPDIR/orcaslicer_model — ein fremder (root-)Ordner in /tmp
    // ließ jedes 3MF mit „basic_ios::clear: iostream error“ scheitern
    $cmd = 'cd ' . escapeshellarg($dir) . ' && HOME=' . escapeshellarg($dir) . ' TMPDIR=' . escapeshellarg($dir) . ' timeout ' . PS_TIMEOUT . ' nice -n 10 '
         . implode(' ', array_map('escapeshellarg', $args)) . ' > ' . escapeshellarg($outdir . '.log') . ' 2>&1';
    exec($cmd, $o, $rc);
    return [$rc, json_decode((string)@file_get_contents("$outdir/result.json"), true) ?: []];
}

/**
 * STL um $k skalieren, in place. Um die Mitte der Grundfläche: Boden bleibt auf z = 0, Orca legt die Datei danach
 * wie jede andere aufs Bett. Binär in Blöcken (große Dateien), ASCII zeilenweise. Normalen bleiben (gleichmäßig skaliert).
 */
function ps_stl_scale(string $path, float $k): void {
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException('scale: ' . basename($path));
    $head = fread($fh, 84);
    $n = strlen($head) === 84 ? unpack('V', substr($head, 80, 4))[1] : 0;
    $bin = $n > 0 && filesize($path) === 84 + $n * 50;
    // 1. Durchgang: Umriss
    $mn = [INF, INF, INF]; $mx = [-INF, -INF, -INF];
    $see = function (array $v) use (&$mn, &$mx) { for ($a = 0; $a < 3; $a++) { if ($v[$a] < $mn[$a]) $mn[$a] = $v[$a]; if ($v[$a] > $mx[$a]) $mx[$a] = $v[$a]; } };
    if ($bin) {
        while (($rec = fread($fh, 50 * 4000)) !== '' && $rec !== false) {
            for ($o = 0; $o + 50 <= strlen($rec); $o += 50) { $q = unpack('g9', substr($rec, $o + 12, 36)); $see([$q[1], $q[2], $q[3]]); $see([$q[4], $q[5], $q[6]]); $see([$q[7], $q[8], $q[9]]); }
        }
    } else {
        rewind($fh);
        while (($line = fgets($fh)) !== false) if (preg_match('/^\s*vertex\s+(\S+)\s+(\S+)\s+(\S+)/', $line, $m)) $see([(float)$m[1], (float)$m[2], (float)$m[3]]);
    }
    if ($mn[0] === INF) { fclose($fh); throw new RuntimeException('scale: empty'); }
    $c = [($mn[0] + $mx[0]) / 2, ($mn[1] + $mx[1]) / 2, $mn[2]];
    // 2. Durchgang: umrechnen in eine neue Datei, dann tauschen
    $tmp = $path . '.scale';
    $out = fopen($tmp, 'wb');
    rewind($fh);
    if ($bin) {
        fwrite($out, fread($fh, 84));
        while (($rec = fread($fh, 50 * 4000)) !== '' && $rec !== false) {
            $buf = '';
            for ($o = 0; $o + 50 <= strlen($rec); $o += 50) {
                $q = unpack('g9', substr($rec, $o + 12, 36));
                $buf .= substr($rec, $o, 12) . pack('g9', ($q[1] - $c[0]) * $k, ($q[2] - $c[1]) * $k, ($q[3] - $c[2]) * $k,
                    ($q[4] - $c[0]) * $k, ($q[5] - $c[1]) * $k, ($q[6] - $c[2]) * $k, ($q[7] - $c[0]) * $k, ($q[8] - $c[1]) * $k, ($q[9] - $c[2]) * $k) . substr($rec, $o + 48, 2);
            }
            fwrite($out, $buf);
        }
    } else {
        while (($line = fgets($fh)) !== false) {
            if (preg_match('/^(\s*vertex\s+)(\S+)\s+(\S+)\s+(\S+)/', $line, $m))
                $line = $m[1] . sprintf('%.6F %.6F %.6F', ((float)$m[2] - $c[0]) * $k, ((float)$m[3] - $c[1]) * $k, ((float)$m[4] - $c[2]) * $k) . "\n";
            fwrite($out, $line);
        }
    }
    fclose($fh); fclose($out);
    rename($tmp, $path);
}

/**
 * 3MF um $k skalieren: Build-Items der gewählten Platte ($objs = Objekt-IDs, null = alle) — Matrix ×k, Höhe ×k (Boden
 * bleibt auf dem Bett), Lage um die gemeinsame Mitte gestreckt. So bleiben mehrteilige Objekte maßhaltig zusammen
 * (12.10.2026 bei 80/150/400 % geprüft); Orcas --scale verschiebt die Teile gegeneinander.
 */
function ps_3mf_scale(string $path, float $k, ?array $objs): void {
    $z = new ZipArchive();
    if ($z->open($path) !== true) throw new RuntimeException('3mf_bad');
    $st = $z->statName('3D/3dmodel.model');
    if (!$st || $st['size'] > 64 * 1024 * 1024) { $z->close(); throw new RuntimeException('scale: 3dmodel.model zu groß'); }
    $xml = (string)$z->getFromName('3D/3dmodel.model');
    $bi = strpos($xml, '<build'); $be = $bi === false ? false : strpos($xml, '</build>', $bi);
    if ($bi === false || $be === false) { $z->close(); throw new RuntimeException('scale: kein <build>'); }
    $build = substr($xml, $bi, $be - $bi);
    preg_match_all('/<item\b[^>]*>/', $build, $mm, PREG_OFFSET_CAPTURE);
    $sel = [];
    foreach ($mm[0] as [$tag, $off]) {
        $oid = preg_match('/\bobjectid="(\d+)"/', $tag, $o) ? $o[1] : null;
        if ($oid === null || ($objs !== null && !in_array($oid, $objs, true))) continue;
        $t = preg_match('/\btransform="([^"]+)"/', $tag, $tm) ? array_map('floatval', preg_split('/\s+/', trim($tm[1]))) : [];
        if (count($t) !== 12) $t = [1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0];
        $sel[] = [$tag, $off, $t];
    }
    if (!$sel) { $z->close(); return; }
    $cx = array_sum(array_map(fn($e) => $e[2][9], $sel)) / count($sel);
    $cy = array_sum(array_map(fn($e) => $e[2][10], $sel)) / count($sel);
    $new = $build;
    foreach (array_reverse($sel) as [$tag, $off, $t]) {   // von hinten, damit die Fundstellen stimmen
        $n = array_merge(array_map(fn($v) => $v * $k, array_slice($t, 0, 9)), [$cx + $k * ($t[9] - $cx), $cy + $k * ($t[10] - $cy), $t[11] * $k]);
        $tr = implode(' ', array_map(fn($v) => in_array($q = rtrim(rtrim(sprintf('%.7F', $v), '0'), '.'), ['', '-', '-0'], true) ? '0' : $q, $n));
        $tag2 = preg_match('/\btransform="[^"]*"/', $tag) ? preg_replace('/\btransform="[^"]*"/', 'transform="' . $tr . '"', $tag)
                                                           : preg_replace('/\s*(\/?)>$/', ' transform="' . $tr . '"$1>', $tag);
        $new = substr_replace($new, $tag2, $off, strlen($tag));
    }
    $z->addFromString('3D/3dmodel.model', substr($xml, 0, $bi) . $new . substr($xml, $be));
    if (!$z->close()) throw new RuntimeException('scale: 3MF nicht geschrieben');
}

/** 3MF-Matrix „m00 m01 m02 m10 m11 m12 m20 m21 m22 m30 m31 m32“ (Zeilenvektoren) */
function ps_m_parse(?string $t): array {
    $v = $t ? array_map('floatval', preg_split('/\s+/', trim($t))) : [];
    return count($v) === 12 ? $v : [1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0];
}

/** Erst $a, dann $b anwenden. */
function ps_m_mul(array $a, array $b): array {
    $r = [];
    for ($i = 0; $i < 4; $i++) {
        for ($j = 0; $j < 3; $j++) {
            $r[$i * 3 + $j] = $a[$i * 3] * $b[$j] + $a[$i * 3 + 1] * $b[3 + $j] + $a[$i * 3 + 2] * $b[6 + $j] + ($i === 3 ? $b[9 + $j] : 0);
        }
    }
    return $r;
}

/** Objekte (Mesh ja/nein, Komponenten) und Druckobjekte einer Modelldatei im 3MF. */
function ps_3mf_scan(string $zip, string $entry): array {
    $out = ['objects' => [], 'build' => []];
    $icount = [];
    $r = new XMLReader();
    if (!@$r->open('zip://' . $zip . '#' . ltrim($entry, '/'), null, LIBXML_PARSEHUGE)) return $out;
    $obj = null;
    while (@$r->read()) {
        if ($r->nodeType === XMLReader::DOC_TYPE) return $out;   // 3MF hat nie eine DTD; mit PARSEHUGE drohte Entity-Vervielfachung
        if ($r->nodeType !== XMLReader::ELEMENT) continue;
        switch ($r->localName) {
            case 'object':
                $obj = $r->getAttribute('id');
                $out['objects'][$obj] = ['mesh' => false, 'components' => [], 'type' => $r->getAttribute('type') ?: 'model'];
                break;
            case 'mesh':
                if ($obj !== null) $out['objects'][$obj]['mesh'] = true;
                $r->next();   // Mesh-Inhalt hier überspringen, gelesen wird er erst beim Schreiben
                break;
            case 'component':
                if ($obj === null) break;
                $path = $r->getAttributeNs('path', 'http://schemas.microsoft.com/3dmanufacturing/production/2015/06') ?: $r->getAttribute('p:path') ?: $entry;
                $out['objects'][$obj]['components'][] = [$path, $r->getAttribute('objectid'), ps_m_parse($r->getAttribute('transform'))];
                break;
            case 'item':
                // Instanz = wievielter Eintrag dieses Objekts (so zählt auch instance_id in model_settings.config)
                $oid = (string)$r->getAttribute('objectid');
                $icount[$oid] = isset($icount[$oid]) ? $icount[$oid] + 1 : 0;
                if ($r->getAttribute('printable') === '0') break;
                $out['build'][] = [$oid, ps_m_parse($r->getAttribute('transform')), $icount[$oid]];
                break;
        }
    }
    $r->close();
    return $out;
}

/** Dreiecke eines Mesh-Objekts transformiert in die STL schreiben. Rückgabe: Anzahl Dreiecke. */
function ps_3mf_write_mesh(string $zip, string $entry, string $id, array $m, $fh): int {
    $r = new XMLReader();
    if (!@$r->open('zip://' . $zip . '#' . ltrim($entry, '/'), null, LIBXML_PARSEHUGE)) return 0;
    $in = false; $verts = ''; $n = 0;
    while (@$r->read()) {
        if ($r->nodeType === XMLReader::DOC_TYPE) return 0;
        if ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'object' && $in) break;
        if ($r->nodeType !== XMLReader::ELEMENT) continue;
        if ($r->localName === 'object') { $in = $r->getAttribute('id') === $id; continue; }
        if (!$in) continue;
        if ($r->localName === 'vertex') {
            $x = (float)$r->getAttribute('x'); $y = (float)$r->getAttribute('y'); $z = (float)$r->getAttribute('z');
            $verts .= pack('g3', $x * $m[0] + $y * $m[3] + $z * $m[6] + $m[9], $x * $m[1] + $y * $m[4] + $z * $m[7] + $m[10], $x * $m[2] + $y * $m[5] + $z * $m[8] + $m[11]);
        } elseif ($r->localName === 'triangle') {
            $a = (int)$r->getAttribute('v1') * 12; $b = (int)$r->getAttribute('v2') * 12; $c = (int)$r->getAttribute('v3') * 12;
            fwrite($fh, pack('g3', 0, 0, 0) . substr($verts, $a, 12) . substr($verts, $b, 12) . substr($verts, $c, 12) . "\0\0");
            $n++;
        }
    }
    $r->close();
    return $n;
}

/**
 * Rückfall für 3MF, die Orca nicht lesen kann: je Druckobjekt (build item) eine binäre STL mit Lage und
 * Drehung aus dem Projekt. Teile, die keine normalen Teile sind (Modifier, Negativ-Teile, Stützen-Maler
 * laut model_settings.config), fallen weg. Rückgabe ['files' => [...], 'skipped' => n].
 */
function ps_3mf_to_stls(string $path, string $outdir, ?array $only = null, array $skip_inst = []): array {
    @mkdir($outdir);
    $z = new ZipArchive();
    if ($z->open($path) !== true) return ['files' => [], 'skipped' => 0];
    $main = '3D/3dmodel.model';
    if (($rels = $z->getFromName('_rels/.rels')) && preg_match('/Target="\/?([^"]+\.model)"/i', $rels, $mm)) $main = $mm[1];
    // Nicht-normale Teile: <object id=X><part id=Y subtype="modifier_part"> → Komponente Y von Objekt X überspringen
    $skip = [];
    if ($ms = $z->getFromName('Metadata/model_settings.config')) {
        if (preg_match_all('/<object id="(\d+)">(.*?)<\/object>/s', $ms, $objs, PREG_SET_ORDER)) {
            foreach ($objs as [, $oid, $body]) {
                if (preg_match_all('/<part id="(\d+)" subtype="([a-z_]+)"/', $body, $parts, PREG_SET_ORDER)) {
                    foreach ($parts as [, $pid, $sub]) if ($sub !== 'normal_part') $skip["$oid/$pid"] = true;
                }
            }
        }
    }
    $z->close();
    $scans = [];
    $scan = function (string $e) use (&$scans, $path) { $e = ltrim($e, '/'); return $scans[$e] ??= ps_3mf_scan($path, $e); };
    $files = []; $skipped = 0;
    foreach ($scan($main)['build'] as $i => [$oid, $bm, $inst]) {
        // Nur Objekte von Platte 1 — sonst lagen die Teile aller Platten zusammen auf einem Bett
        if ($only !== null && !in_array((string)$oid, $only, true)) continue;
        if (isset($skip_inst["$oid/$inst"])) continue;   // vom Nutzer weggelassen
        $f = "$outdir/object_" . ($i + 1) . '.stl';
        $fh = fopen($f, 'wb');
        fwrite($fh, str_pad('3DSEARCH 3mf fallback', 80, ' ') . pack('V', 0));
        $tri = 0;
        // Tiefensuche über Komponenten; Transformation: Kind zuerst, dann Eltern
        $walk = function (string $entry, string $id, array $m, int $depth, ?string $top) use (&$walk, $scan, $path, $fh, &$tri, $skip, &$skipped) {
            $o = $scan($entry)['objects'][$id] ?? null;
            if (!$o || $depth > 8) return;
            if ($o['mesh']) $tri += ps_3mf_write_mesh($path, ltrim($entry, '/'), $id, $m, $fh);
            foreach ($o['components'] as [$cpath, $cid, $cm]) {
                if ($top !== null && isset($skip["$top/$cid"])) { $skipped++; continue; }
                $walk($cpath, $cid, ps_m_mul($cm, $m), $depth + 1, null);
            }
        };
        $walk($main, (string)$oid, $bm, 0, (string)$oid);
        fseek($fh, 80);
        fwrite($fh, pack('V', $tri));
        fclose($fh);
        if ($tri > 0) $files[] = $f; else @unlink($f);
    }
    return ['files' => $files, 'skipped' => $skipped];
}

/**
 * Binärformat für den WebGL-Viewer (vorher JSON: Wespenfalle 9,2 MB, 830.000 Strecken — Canvas 2D brauchte
 * Sekunden je Bild). Little Endian:
 *   "PSV3", f32 ox, f32 oy, f32 höhe, u32 nbed, nbed×(f32 x, f32 y), u8 nfarben, nfarben×(u8 r, g, b), u32 nlayers, u32 nsegmente,
 *   je Schicht: f32 z, u32 npoly; je Linienzug: u8 (kategorie | farbe << 3; farbe 0 = keine, sonst 1-basiert in der Tabelle),
 *   u32 npunkte, i16 x0, i16 y0, (n−1)×(i16 dx, i16 dy)
 * Koordinaten in 0,1 mm relativ zu (ox, oy), Folgepunkte als Differenz — komprimiert gut mit gzip.
 */
function ps_preview_pack(array $layers, array $bed, float $height, array $colors = []): string {
    $ox = $oy = 0.0;
    if ($bed) { $ox = (min(array_column($bed, 0)) + max(array_column($bed, 0))) / 2; $oy = (min(array_column($bed, 1)) + max(array_column($bed, 1))) / 2; }
    $cats = array_flip(PS_PREVIEW_CATS);
    $body = ''; $segs = 0;
    foreach ($layers as [$z, $byCat]) {
        $polys = '';
        $np = 0;
        foreach ($byCat as $key => $lines) {
            [$cat, $fi] = explode(':', $key) + [1 => 0];
            foreach ($lines as $pts) {
                $n = intdiv(count($pts), 2);
                if ($n < 2) continue;
                $px = (int)round(($pts[0] - $ox) * 10); $py = (int)round(($pts[1] - $oy) * 10);
                $buf = pack('CVvv', ($cats[$cat] ?? 6) | (min(31, (int)$fi) << 3), $n, $px & 0xffff, $py & 0xffff);
                for ($i = 1; $i < $n; $i++) {
                    $x = (int)round(($pts[2 * $i] - $ox) * 10); $y = (int)round(($pts[2 * $i + 1] - $oy) * 10);
                    $buf .= pack('vv', ($x - $px) & 0xffff, ($y - $py) & 0xffff);
                    $px = $x; $py = $y;
                }
                $polys .= $buf;
                $np++;
                $segs += $n - 1;
            }
        }
        $body .= pack('gV', $z, $np) . $polys;
    }
    $head = 'PSV3' . pack('gggV', $ox, $oy, $height, count($bed));
    foreach ($bed as [$bx, $by]) $head .= pack('gg', $bx, $by);
    // Farbtabelle (mehrfarbig, sonst leer): u8 n, n × (u8 r, g, b)
    $head .= pack('C', count($colors));
    foreach ($colors as $c) $head .= pack('CCC', ...array_map('hexdec', str_split(substr(ltrim($c, '#') . '000000', 0, 6), 2)));
    return $head . pack('VV', count($layers), $segs) . $body;
}

/**
 * Standbild des geslicten Modells (23.10.2026, User: „beim Drucken statt dem Namen das Bild zeigen, geslict wurde es ja“) —
 * Orca rendert auf dem Server keine Vorschaubilder (kein OpenGL). Aus der Vorschau (PSV3, ps_preview_pack): nur Außenwände und Deck-/Bodenflächen,
 * Z-Puffer, Schrägansicht von vorn oben, Fachfarbe mit Licht. Transparenter Hintergrund, WebP.
 * $color = Fachfarbe (einfarbig); mehrfarbig kommen die Farben aus der Datei.
 */
function ps_thumb_build(string $src, string $out, ?string $color = null, int $size = 240): bool {
    $cv = ps_thumb_canvas($src, $color, $size * 2);
    if (!$cv) return false;
    $th = ps_thumb_fit($cv, $size, $size, (int)round($size * 0.06));
    $ok = imagewebp($th, $out . '.tmp', 84) && rename($out . '.tmp', $out);
    imagedestroy($th); imagedestroy($cv[0]);
    return $ok;
}

/**
 * Nach dem Slicen (14.11.2026, User: „am Display des Druckers sehe ich nicht, was gedruckt wird“): Orca schreibt ohne OpenGL keine
 * Vorschaubilder in den G-Code — das Display (Kobra, K1/K2, Centauri, U1, Prusa …) und Moonraker hatten nichts zu zeigen.
 * Einmal rendern, daraus: thumb.webp (Website), thumb.png (App: Druckkarte, Bambu plate_1.png) und die Bilder, die das
 * Druckerprofil verlangt (thumbnails/thumbnails_format), im Orca-Format im Kopf des G-Codes.
 */
function ps_thumbs_after_slice(string $dir, string $gcode, array $m, ?string $color): void {
    $specs = ps_thumb_specs($m);
    $max = 256;
    foreach ($specs as [$w, $h]) $max = max($max, $w, $h);
    $cv = ps_thumb_canvas("$dir/preview.bin.gz", $color, min(1024, 2 * $max));
    if (!$cv) return;
    $im = ps_thumb_fit($cv, 240, 240, 14);
    if (imagewebp($im, "$dir/thumb.webp.tmp", 84)) rename("$dir/thumb.webp.tmp", "$dir/thumb.webp");
    imagedestroy($im);
    $im = ps_thumb_fit($cv, 256, 256, 10);
    if (imagepng($im, "$dir/thumb.png.tmp", 9)) rename("$dir/thumb.png.tmp", "$dir/thumb.png");
    imagedestroy($im);
    if ($specs) ps_gcode_thumbs_insert($gcode, $cv, $specs);
    imagedestroy($cv[0]);
}

/**
 * Vorschaubilder laut Druckerprofil: „230x110/PNG“, [„320x320“, „160x160“], „96x96/PNG, 300x300/PNG“ — ohne Format gilt thumbnails_format
 * (sonst PNG). Nur PNG, JPG und QOI; COLPIC (Qidi, ältere Elegoo) und BTT_TFT fehlen — die Qidi-Profile verlangen zusätzlich PNG.
 * @return list<array{int, int, string}>
 */
function ps_thumb_specs(array $m): array {
    $t = $m['thumbnails'] ?? '';
    $t = is_array($t) ? implode(',', $t) : (string)$t;
    $df = $m['thumbnails_format'] ?? '';
    $df = strtoupper(trim(is_array($df) ? (string)($df[0] ?? '') : (string)$df)) ?: 'PNG';
    $out = [];
    foreach (preg_split('/[,;]/', $t) as $e) {
        if (!preg_match('~^\s*(\d+)\s*x\s*(\d+)\s*(?:/\s*([A-Za-z_]+))?\s*$~', $e, $mm)) continue;
        [$w, $h, $f] = [(int)$mm[1], (int)$mm[2], strtoupper($mm[3] ?? '') ?: $df];
        if ($w < 8 || $h < 8 || $w > 1024 || $h > 1024 || !in_array($f, ['PNG', 'JPG', 'QOI'], true)) continue;
        $out[] = [$w, $h, $f];
    }
    return array_slice($out, 0, 8);
}

/** Belegten Bereich der Leinwand (ps_thumb_canvas) mittig in ein Bild $w × $h einpassen, Rand $pad px; $bg = [r, g, b] statt transparent (JPG). */
function ps_thumb_fit(array $cv, int $w, int $h, int $pad = 0, ?array $bg = null): GdImage {
    [$im, [$x0, $y0, $x1, $y1]] = $cv;
    $cw = $x1 - $x0 + 1; $ch = $y1 - $y0 + 1;
    $k = min(max(1, $w - 2 * $pad) / $cw, max(1, $h - 2 * $pad) / $ch);
    $dw = max(1, (int)round($cw * $k)); $dh = max(1, (int)round($ch * $k));
    $out = imagecreatetruecolor($w, $h);
    if ($bg) { imagefill($out, 0, 0, imagecolorallocate($out, $bg[0], $bg[1], $bg[2])); imagealphablending($out, true); }
    else { imagealphablending($out, false); imagesavealpha($out, true); imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127)); }
    imagecopyresampled($out, $im, intdiv($w - $dw, 2), intdiv($h - $dh, 2), $x0, $y0, $dw, $dh, $cw, $ch);
    return $out;
}

/** QOI (qoiformat.org, Prusa-Displays): RGBA, Pixel für Pixel wie der Referenz-Encoder. */
function ps_qoi_encode(GdImage $im): string {
    $w = imagesx($im); $h = imagesy($im);
    $out = 'qoif' . pack('NN', $w, $h) . "\x04\x00";
    $idx = array_fill(0, 64, 0);
    $pr = $pg = $pb = 0; $pa = 255; $run = 0; $n = 0; $end = $w * $h;
    $s8 = fn(int $v): int => (($v + 128) & 255) - 128;
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        $c = imagecolorat($im, $x, $y); $n++;
        $a = 255 - (int)round((($c >> 24) & 127) * 255 / 127);
        [$r, $g, $b] = $a === 0 ? [0, 0, 0] : [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
        if ($r === $pr && $g === $pg && $b === $pb && $a === $pa) {
            if (++$run === 62 || $n === $end) { $out .= chr(0xc0 | ($run - 1)); $run = 0; }
            continue;
        }
        if ($run) { $out .= chr(0xc0 | ($run - 1)); $run = 0; }
        $hi = ($r * 3 + $g * 5 + $b * 7 + $a * 11) % 64; $key = ($r << 24) | ($g << 16) | ($b << 8) | $a;
        if ($idx[$hi] === $key) $out .= chr($hi);
        else {
            $idx[$hi] = $key;
            if ($a === $pa) {
                $vr = $s8($r - $pr); $vg = $s8($g - $pg); $vb = $s8($b - $pb); $vgr = $s8($vr - $vg); $vgb = $s8($vb - $vg);
                if ($vr > -3 && $vr < 2 && $vg > -3 && $vg < 2 && $vb > -3 && $vb < 2) $out .= chr(0x40 | (($vr + 2) << 4) | (($vg + 2) << 2) | ($vb + 2));
                elseif ($vgr > -9 && $vgr < 8 && $vg > -33 && $vg < 32 && $vgb > -9 && $vgb < 8) $out .= chr(0x80 | ($vg + 32)) . chr((($vgr + 8) << 4) | ($vgb + 8));
                else $out .= "\xfe" . chr($r) . chr($g) . chr($b);
            } else $out .= "\xff" . chr($r) . chr($g) . chr($b) . chr($a);
        }
        $pr = $r; $pg = $g; $pb = $b; $pa = $a;
    }
    return $out . "\0\0\0\0\0\0\0\1";
}

/**
 * Vorschaubilder in den G-Code-Kopf, wie Orca sie schreibt (GCodeThumbnails: hinter „; HEADER_BLOCK_END“, Base64 in Zeilen zu 78 Zeichen,
 * „thumbnail“ = PNG, „thumbnail_JPG“, „thumbnail_QOI“). Hat die Datei schon welche, bleibt sie, wie sie ist.
 */
function ps_gcode_thumbs_insert(string $gcode, array $cv, array $specs): bool {
    $fh = @fopen($gcode, 'rb');
    if (!$fh) return false;
    $head = (string)fread($fh, 65536);
    $p = strpos($head, "; HEADER_BLOCK_END\n");
    if ($p === false || preg_match('/^; thumbnail(_[A-Z]+)? begin /m', $head)) { fclose($fh); return false; }
    $p += 19;
    if (($head[$p] ?? '') === "\n") $p++;
    $blk = '';
    foreach ($specs as [$w, $h, $f]) {
        $im = ps_thumb_fit($cv, $w, $h, min($w, $h) >= 64 ? (int)round(min($w, $h) * 0.04) : 0, $f === 'JPG' ? [0, 0, 0] : null);
        if ($f === 'QOI') $data = ps_qoi_encode($im);
        else { ob_start(); $f === 'JPG' ? imagejpeg($im, null, 85) : imagepng($im, null, 9); $data = (string)ob_get_clean(); }
        imagedestroy($im);
        $b = base64_encode($data); $tag = $f === 'PNG' ? 'thumbnail' : 'thumbnail_' . $f;
        $blk .= "; THUMBNAIL_BLOCK_START\n\n;\n; $tag begin {$w}x{$h} " . strlen($b) . "\n";
        foreach (str_split($b, 78) as $ln) $blk .= "; $ln\n";
        $blk .= "; $tag end\n; THUMBNAIL_BLOCK_END\n\n";
    }
    $tmp = $gcode . '.thumbs';
    $out = @fopen($tmp, 'wb');
    if (!$out) { fclose($fh); return false; }
    $ok = fwrite($out, substr($head, 0, $p) . $blk . substr($head, $p)) !== false && stream_copy_to_stream($fh, $out) !== false;
    fclose($fh); $ok = fclose($out) && $ok;
    if (!$ok || filesize($tmp) < filesize($gcode)) { @unlink($tmp); return false; }
    return rename($tmp, $gcode);
}

/**
 * Leinwand $S × $S (transparent) mit dem Modell, wie ps_thumb_build es zeigt, und dem belegten Bereich [x0, y0, x1, y1] — oder null.
 */
function ps_thumb_canvas(string $src, ?string $color, int $S): ?array {
    $raw = @gzdecode((string)@file_get_contents($src));
    if (!$raw || strncmp($raw, 'PSV3', 4) !== 0) return null;
    $hd = unpack('gox/goy/gh/Vnbed', $raw, 4);
    $o = 20 + 8 * $hd['nbed'];
    $nc = ord($raw[$o++]);
    $hex = fn(string $c) => array_map('hexdec', str_split(substr(ltrim($c, '#') . '000000', 0, 6), 2));
    $base = preg_match('/^#?[0-9a-f]{6}$/i', (string)$color) ? $hex($color) : [255, 106, 20];
    $pal = [$base];
    for ($i = 0; $i < $nc; $i++, $o += 3) $pal[] = [ord($raw[$o]), ord($raw[$o + 1]), ord($raw[$o + 2])];
    $nl = unpack('Vnl', $raw, $o)['nl']; $o += 8;
    // Linienzüge sammeln: [z, farbe, deckfläche?, punkte (0,1 mm)]
    $polys = []; $zs = [];
    for ($l = 0; $l < $nl; $l++) {
        $L = unpack('gz/Vnp', $raw, $o); $o += 8;
        $z = $L['z'] * 10;
        for ($p = 0; $p < $L['np']; $p++) {
            $c = ord($raw[$o]); $n = unpack('V', $raw, $o + 1)[1]; $o += 5;
            $cat = $c & 7;
            if ($cat === 0 || $cat === 3) {   // outer, solid
                $v = unpack('v*', substr($raw, $o, 4 * $n));
                $pts = []; $x = $y = 0;
                for ($i = 0; $i < $n; $i++) {
                    $dx = $v[2 * $i + 1]; $dy = $v[2 * $i + 2];
                    $x += $dx >= 32768 ? $dx - 65536 : $dx; $y += $dy >= 32768 ? $dy - 65536 : $dy;
                    $pts[] = $x; $pts[] = $y;
                }
                $polys[] = [$z, $c >> 3, $cat === 3, $pts, $l];
            }
            $o += 4 * $n;
        }
        $zs[] = $z;
    }
    if (!$polys) return null;
    // Schichthöhe (für die Höhe einer Bahn im Bild): Median der Abstände
    $d = []; for ($i = 1; $i < count($zs); $i++) if ($zs[$i] > $zs[$i - 1]) $d[] = $zs[$i] - $zs[$i - 1];
    sort($d); $lh = $d ? $d[intdiv(count($d), 2)] : 2.0;
    // Ansicht: um die Hochachse gedreht, von vorn oben
    $a = deg2rad(-32); $e = deg2rad(30);
    $ca = cos($a); $sa = sin($a); $ce = cos($e); $se = sin($e);
    $minx = $miny = INF; $maxx = $maxy = -INF;
    foreach ($polys as [$z, , , $pts]) for ($i = 0, $m = count($pts); $i < $m; $i += 2) {
        $xr = $pts[$i] * $ca - $pts[$i + 1] * $sa; $yr = $pts[$i] * $sa + $pts[$i + 1] * $ca;
        $sy = -($z * $ce + $yr * $se);
        if ($xr < $minx) $minx = $xr; if ($xr > $maxx) $maxx = $xr;
        if ($sy < $miny) $miny = $sy; if ($sy + $lh * $ce > $maxy) $maxy = $sy + $lh * $ce;
    }
    $pad = $S * 0.06;
    $k = ($S - 2 * $pad) / max(1, $maxx - $minx, $maxy - $miny);
    $offx = ($S - ($maxx - $minx) * $k) / 2 - $minx * $k; $offy = ($S - ($maxy - $miny) * $k) / 2 - $miny * $k;
    $r = max(0, (int)floor(2.2 * $k));               // halbe Bahnbreite (0,45 mm) in Pixeln
    $hv = max(1, (int)ceil($lh * $ce * $k));         // Schichthöhe im Bild
    $hs = max(1, (int)ceil(4.5 * $se * $k));         // Bahnabstand einer Deckfläche im Bild (verkürzt)
    $zb = array_fill(0, $S * $S, INF); $px = [];
    $Lx = -0.55; $Ly = -0.62; $Lz = 0.56;            // Licht von vorn links oben (gedrehte Koordinaten)
    foreach ($polys as [$z, $fi, $top, $pts, $li]) {
        $col = $pal[$fi] ?? $base;
        $band = ($li & 1) ? 0.94 : 1.0;
        for ($i = 2, $m = count($pts); $i < $m; $i += 2) {
            $x1 = $pts[$i - 2]; $y1 = $pts[$i - 1]; $x2 = $pts[$i]; $y2 = $pts[$i + 1];
            $xr1 = $x1 * $ca - $y1 * $sa; $yr1 = $x1 * $sa + $y1 * $ca; $xr2 = $x2 * $ca - $y2 * $sa; $yr2 = $x2 * $sa + $y2 * $ca;
            if ($top) $I = 0.36 + 0.64 * $Lz;
            else {
                $nx = -($yr2 - $yr1); $ny = $xr2 - $xr1; $nn = sqrt($nx * $nx + $ny * $ny) ?: 1;
                $nx /= $nn; $ny /= $nn;
                if ($ny > 0) { $nx = -$nx; $ny = -$ny; }   // die zur Kamera gewandte Seite
                $I = 0.36 + 0.64 * max(0, $nx * $Lx + $ny * $Ly);
            }
            $I *= $band;
            $cr = min(255, (int)($col[0] * $I * 0.86 + 42 * $I)); $cg = min(255, (int)($col[1] * $I * 0.86 + 42 * $I)); $cb = min(255, (int)($col[2] * $I * 0.86 + 42 * $I));
            $rgb = ($cr << 16) | ($cg << 8) | $cb;
            $sx1 = $xr1 * $k + $offx; $sy1 = -($z * $ce + $yr1 * $se) * $k + $offy;
            $sx2 = $xr2 * $k + $offx; $sy2 = -($z * $ce + $yr2 * $se) * $k + $offy;
            $dp1 = $yr1 * $ce - $z * $se; $dp2 = $yr2 * $ce - $z * $se;
            $steps = max(1, (int)ceil(max(abs($sx2 - $sx1), abs($sy2 - $sy1)) / max(1, $r * 0.8)));   // breite Bahnen (kleine Modelle) gröber abtasten
            $h = $top ? $hs : $hv;
            for ($s = 0; $s <= $steps; $s++) {
                $t = $s / $steps;
                $cx = (int)($sx1 + ($sx2 - $sx1) * $t); $cy = (int)($sy1 + ($sy2 - $sy1) * $t); $dp = $dp1 + ($dp2 - $dp1) * $t;
                for ($yy = max(0, $cy - $r); $yy <= min($S - 1, $cy + $h); $yy++) {
                    $row = $yy * $S;
                    for ($xx = max(0, $cx - $r); $xx <= min($S - 1, $cx + $r); $xx++) {
                        $q = $row + $xx;
                        if ($dp < $zb[$q]) { $zb[$q] = $dp; $px[$q] = $rgb; }
                    }
                }
            }
        }
    }
    // Umriss: an Kanten (leerer Nachbar oder Tiefensprung > 2 mm) dunkler
    $im = imagecreatetruecolor($S, $S);
    imagealphablending($im, false); imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    $bx0 = $by0 = $S; $bx1 = $by1 = 0;
    foreach ($px as $q => $rgb) {
        $x = $q % $S; $y = intdiv($q, $S); $dz = $zb[$q];
        if ($x < $bx0) $bx0 = $x; if ($x > $bx1) $bx1 = $x; if ($y < $by0) $by0 = $y; if ($y > $by1) $by1 = $y;
        $edge = false;
        foreach ([$q - 1, $q + 1, $q - $S, $q + $S] as $nq) if (!isset($px[$nq]) || $zb[$nq] - $dz > 20) { $edge = true; break; }
        if ($edge) $rgb = ((int)((($rgb >> 16) & 255) * .62) << 16) | ((int)((($rgb >> 8) & 255) * .62) << 8) | (int)(($rgb & 255) * .62);
        imagesetpixel($im, $x, $y, $rgb);
    }
    if (!$px) { imagedestroy($im); return null; }
    return [$im, [$bx0, $by0, $bx1, $by1]];
}

/**
 * G-Code → Schichtdaten für den Viewer (ps_preview_pack) und Kennzahlen aus dem Fußblock.
 * Koordinaten auf 0,1 mm, Punkte unter 0,4 mm Abstand werden zusammengelegt.
 */
function ps_preview_build(string $gcode, string $out, array $machine, array $pal = []): array {
    $cat_of = function (string $t): string {
        $t = strtolower($t);
        if (str_contains($t, 'support')) return 'support';
        if (str_contains($t, 'outer wall') || str_contains($t, 'external perimeter') || str_contains($t, 'overhang')) return 'outer';
        if (str_contains($t, 'wall') || str_contains($t, 'perimeter')) return 'inner';
        if (str_contains($t, 'skirt') || str_contains($t, 'brim')) return 'brim';
        if (str_contains($t, 'top') || str_contains($t, 'bottom') || str_contains($t, 'solid') || str_contains($t, 'bridge') || str_contains($t, 'ironing')) return 'solid';
        if (str_contains($t, 'infill') || str_contains($t, 'gap')) return 'infill';
        return 'other';
    };
    $layers = []; $cur = null; $z = 0.0; $x = $y = 0.0; $cat = 'other'; $line = null; $abs_e = false; $e_last = 0.0;
    // Ohne „;Z:“-Marken (Cura) zählen „;LAYER:“-Marken, die Höhe kommt aus den Z-Fahrten
    $head = (string)file_get_contents($gcode, false, null, 0, 1 << 20);
    $no_z = !str_contains($head, "\n;Z:") && str_contains($head, "\n;LAYER:");
    $gz = 0.0; $cura_new = false;
    $stats = ['time' => null, 'grams' => null, 'layers' => null, 'mm' => null];
    // Mehrfarbig: $pal = [T-Nummer im fertigen G-Code => Fachfarbe] → je Linie die Farbe des aktiven Werkzeugs
    $colors = array_values(array_unique(array_values($pal)));
    $tool_fi = [];
    foreach ($pal as $t => $c) $tool_fi[(int)$t] = array_search($c, $colors, true) + 1;   // 0 = keine Farbe
    $fi = $tool_fi ? reset($tool_fi) : 0;
    $flush = function () use (&$line, &$cur, &$cat, &$fi) {
        if ($line !== null && count($line) >= 4 && $cur !== null) $cur[1]["$cat:$fi"][] = ps_simplify($line, 0.08);
        $line = null;
    };
    $fh = fopen($gcode, 'r');
    while (($l = fgets($fh)) !== false) {
        if ($l[0] === ';') {
            // Bambu-Format (Orca mit BBL-Profil, Bambu Studio): „; FEATURE: …“ statt „;TYPE:“, „; Z_HEIGHT: …“ statt „;Z:“ (09.11.2026, Meldung #75)
            if (strncmp($l, ';TYPE:', 6) === 0 || strncmp($l, '; FEATURE:', 10) === 0) { $flush(); $cat = $cat_of(trim(substr($l, strpos($l, ':') + 1))); }
            elseif (strncmp($l, ';Z:', 3) === 0 || strncmp($l, '; Z_HEIGHT:', 11) === 0) {
                $flush();
                $z = (float)trim(substr($l, strpos($l, ':') + 1));
                if ($cur !== null && $cur[1]) $layers[] = $cur;
                $cur = [round($z, 2), []];
            }
            elseif (preg_match('/^; estimated printing time \(normal mode\) = (.+)$/', $l, $m)) $stats['time'] = ps_parse_duration(trim($m[1]));
            elseif ($stats['time'] === null && preg_match('/^; model printing time: .*; total estimated time: (.+)$/', $l, $m)) $stats['time'] = ps_parse_duration(trim($m[1]));
            elseif (preg_match('/^; total filament used \[g\] = ([\d.]+)/', $l, $m)) $stats['grams'] = (float)$m[1];
            elseif (preg_match('/^; filament used \[g\] = ([\d., ]+)/', $l, $m)) $stats['grams_by'] = array_map('floatval', explode(',', $m[1]));
            elseif (preg_match('/^; filament used \[mm\] = ([\d.]+)/', $l, $m)) $stats['mm'] = (float)$m[1];
            elseif (preg_match('/^; total layers count = (\d+)/', $l, $m)) $stats['layers'] = (int)$m[1];
            elseif (preg_match('/^; total layer number: (\d+)/', $l, $m)) $stats['layers'] = (int)$m[1];
            // Cura (eigener G-Code): Schichten heißen „;LAYER:n“ statt „;Z:“, Druckzeit „;TIME:<Sekunden>“
            elseif ($no_z && strncmp($l, ';LAYER:', 7) === 0) { $flush(); if ($cur !== null && $cur[1]) $layers[] = $cur; $cur = [round($gz, 2), []]; $cura_new = true; }
            elseif ($stats['time'] === null && preg_match('/^;TIME:(\d+)/', $l, $m)) $stats['time'] = (int)round((int)$m[1] / 60);
            continue;
        }
        if ($l[0] === 'T' && $tool_fi && preg_match('/^T(\d+)\b/', $l, $m)) { $flush(); $fi = $tool_fi[(int)$m[1]] ?? $fi; continue; }
        if ($l[0] === 'M' && strncmp($l, 'M82', 3) === 0) $abs_e = true;
        elseif ($l[0] === 'M' && strncmp($l, 'M83', 3) === 0) $abs_e = false;
        $gc = $l[1] ?? '';
        if ($l[0] !== 'G' || !($gc === '1' || $gc === '0' || $gc === '2' || $gc === '3') || ($l[2] !== ' ' && $l[2] !== "\n")) continue;
        $nx = $x; $ny = $y; $e = null; $ci = $cj = 0.0;
        if (preg_match_all('/([XYEZIJ])(-?[\d.]+)/', strtok($l, ';'), $mm, PREG_SET_ORDER)) {
            foreach ($mm as [, $k, $val]) {
                if ($k === 'X') $nx = (float)$val; elseif ($k === 'Y') $ny = (float)$val; elseif ($k === 'E') $e = (float)$val;
                elseif ($k === 'I') $ci = (float)$val; elseif ($k === 'J') $cj = (float)$val;
                elseif ($k === 'Z' && $no_z) { $gz = (float)$val; if ($cura_new && $cur !== null) $cur[0] = round($gz, 2); }
            }
        }
        // Bögen G2/G3 (Bambu-Profile haben arc fitting an): als Linienzug, sonst fehlen Rundungen bzw. werden zu Sehnen
        $pts = ($gc === '2' || $gc === '3') && ($ci != 0 || $cj != 0) ? ps_arc_points($x, $y, $nx, $ny, $ci, $cj, $gc === '2') : [[$nx, $ny]];
        $moved = $nx !== $x || $ny !== $y || count($pts) > 1;
        $extr = $e !== null && ($abs_e ? $e > $e_last : $e > 0);
        if ($e !== null && $abs_e) $e_last = $e;
        if ($extr && $no_z) $cura_new = false;   // Höhe der Schicht steht fest, sobald gedruckt wird
        if ($extr && $moved && $cur !== null) {
            if ($line === null) $line = [round($x, 2), round($y, 2)];
            foreach ($pts as [$px, $py]) {
                $n = count($line);
                if (abs($px - $line[$n - 2]) + abs($py - $line[$n - 1]) >= 0.05) { $line[] = round($px, 2); $line[] = round($py, 2); }
            }
        } elseif ($moved) {
            $flush();
        }
        $x = $nx; $y = $ny;
    }
    fclose($fh);
    $flush();
    if ($cur !== null && $cur[1]) $layers[] = $cur;
    $bed = [];
    foreach ((array)($machine['printable_area'] ?? []) as $pt) { [$bx, $by] = array_map('floatval', explode('x', $pt)); $bed[] = [$bx, $by]; }
    file_put_contents($out, gzencode(ps_preview_pack($layers, $bed, (float)($machine['printable_height'] ?? 250), $colors), 6));
    if (!$stats['layers']) $stats['layers'] = count($layers);
    // Bambu-Format hat keine Summenzeile „; total filament used [g]“, nur die Werte je Filament
    if ($stats['grams'] === null && !empty($stats['grams_by'])) $stats['grams'] = round(array_sum($stats['grams_by']), 2);
    return $stats;
}

/**
 * Bogen G2 (im Uhrzeigersinn) / G3 von ($x0,$y0) nach ($x1,$y1), Mittelpunkt um ($i,$j) versetzt → Punkte bis einschließlich
 * Endpunkt, höchstens 0,5 mm Bogenlänge je Stück. Gleicher Start und Ende = Vollkreis (G-Code-Regel); fast gleiche Punkte
 * (Rundung) bleiben eine Gerade, damit kein falscher Vollkreis entsteht.
 */
function ps_arc_points(float $x0, float $y0, float $x1, float $y1, float $i, float $j, bool $cw): array {
    $d = hypot($x1 - $x0, $y1 - $y0);
    if ($d > 0 && $d < 0.05) return [[$x1, $y1]];
    $cx = $x0 + $i; $cy = $y0 + $j; $r = hypot($i, $j);
    $a0 = atan2($y0 - $cy, $x0 - $cx);
    if ($d == 0) $sw = $cw ? -2 * M_PI : 2 * M_PI;
    else {
        $sw = atan2($y1 - $cy, $x1 - $cx) - $a0;
        if ($cw) { if ($sw > 0) $sw -= 2 * M_PI; } elseif ($sw < 0) $sw += 2 * M_PI;
    }
    $n = (int)min(256, max(1, ceil(abs($sw) * $r / 0.5)));
    $pts = [];
    for ($k = 1; $k < $n; $k++) { $a = $a0 + $sw * $k / $n; $pts[] = [$cx + $r * cos($a), $cy + $r * sin($a)]; }
    $pts[] = [$x1, $y1];
    return $pts;
}

/**
 * Douglas-Peucker auf einem flachen Linienzug [x0,y0,x1,y1,…], höchstens $eps mm Abweichung. Ein Kreis mit
 * 10 cm Durchmesser schrumpft so von ~785 auf ~70 Strecken je Schicht — vorher 830.000 Strecken bei der
 * Wespenfalle, zu viel für schwache Handys.
 */
function ps_simplify(array $p, float $eps): array {
    $n = intdiv(count($p), 2);
    if ($n < 3) return $p;
    $keep = array_fill(0, $n, false);
    $keep[0] = $keep[$n - 1] = true;
    $stack = [[0, $n - 1]];
    $e2 = $eps * $eps;
    while ($stack) {
        [$a, $b] = array_pop($stack);
        $ax = $p[2 * $a]; $ay = $p[2 * $a + 1]; $dx = $p[2 * $b] - $ax; $dy = $p[2 * $b + 1] - $ay;
        $len2 = $dx * $dx + $dy * $dy;
        $max = -1; $at = -1;
        for ($i = $a + 1; $i < $b; $i++) {
            $px = $p[2 * $i] - $ax; $py = $p[2 * $i + 1] - $ay;
            if ($len2 > 0) { $c = $px * $dy - $py * $dx; $d = $c * $c / $len2; } else { $d = $px * $px + $py * $py; }
            if ($d > $max) { $max = $d; $at = $i; }
        }
        if ($max > $e2) { $keep[$at] = true; $stack[] = [$a, $at]; $stack[] = [$at, $b]; }
    }
    $out = [];
    for ($i = 0; $i < $n; $i++) if ($keep[$i]) { $out[] = $p[2 * $i]; $out[] = $p[2 * $i + 1]; }
    return $out;
}

/** „1h 2m 3s“ / „39m 46s“ / „1d 2h 3m“ → Minuten */
function ps_parse_duration(string $s): int {
    $min = 0.0;
    if (preg_match_all('/(\d+)\s*([dhms])/', $s, $m, PREG_SET_ORDER)) {
        foreach ($m as [, $n, $u]) $min += (int)$n * ['d' => 1440, 'h' => 60, 'm' => 1, 's' => 1 / 60][$u];
    }
    return (int)round($min);
}

/**
 * Hochgeladenen G-Code prüfen (beim Hochladen, also schnell): sieht nach G-Code aus? Welche Werkzeuge (T-Befehle)?
 * Für welchen Drucker und welches Material laut Kopf/Ende (Orca, Anycubic Slicer, PrusaSlicer: „; printer_model =“,
 * „; filament_type =“)? Rückgabe [printer, types, tools] oder Fehlercode.
 */
function ps_gcode_inspect(string $path): array|string {
    $size = (int)filesize($path);
    $fh = fopen($path, 'rb');
    $head = (string)fread($fh, 262144);
    fseek($fh, -min(262144, $size), SEEK_END);
    $tail = (string)stream_get_contents($fh);
    if (!preg_match('/^G[01] /m', $head . $tail) || str_contains(substr($head, 0, 4096), "\0")) { fclose($fh); return 'gcode_bad'; }
    // Werkzeuge: ganze Datei in Stücken durchsuchen (80 MB ≈ 1 s), Zeilen „T<n>“ am Zeilenanfang
    $tools = []; $carry = '';
    fseek($fh, 0);
    while (!feof($fh)) {
        $buf = $carry . (string)fread($fh, 8 << 20);
        $cut = strrpos($buf, "\n");
        $carry = $cut === false ? $buf : substr($buf, $cut + 1);
        if (preg_match_all('/^T(\d+)\b/m', $cut === false ? '' : substr($buf, 0, $cut + 1), $m)) foreach ($m[1] as $t) $tools[(int)$t] = true;
    }
    if (preg_match('/^T(\d+)\b/', $carry, $m)) $tools[(int)$m[1]] = true;
    fclose($fh);
    if (count($tools) > 1) return 'gcode_multi';
    $get = fn(string $k) => preg_match('/^; ?' . preg_quote($k, '/') . ' = (.+)$/m', $head . "\n" . $tail, $m) ? trim($m[1], " \"\r") : '';
    $printer = $get('printer_model') ?: $get('printer_settings_id') ?: (preg_match('/^;\s*MACHINE[_ ]NAME\s*[:=]\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '');
    $types = array_values(array_filter(array_map('trim', explode(';', $get('filament_type')))));
    return ['printer' => mb_substr($printer, 0, 80), 'types' => array_slice($types, 0, 1), 'tools' => array_keys($tools)];
}

/** Passt der G-Code laut Kopf zum gewählten Drucker? Rückgabe: Druckername aus dem G-Code bei Abweichung, sonst null. */
function ps_gcode_mismatch(array $gi, array $printer): ?string {
    if ($gi['printer'] === '') return null;
    $g = ps_printer_norm($gi['printer']);
    foreach ([$printer['model'] ?? '', $printer['machine'] ?? '', $printer['name'] ?? ''] as $c) {
        $c = ps_printer_norm($c);
        if ($c !== '' && (str_contains($c, $g) || str_contains($g, $c))) return null;
    }
    return $gi['printer'];
}

/** Druckername zum Vergleichen: ohne Düsenangabe, nur Kleinbuchstaben und Ziffern. */
function ps_printer_norm(string $s): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower(preg_replace('/\s*\(?\d\.\d+ ?nozzle\)?/i', '', $s)));
}

/**
 * Worker: eigenen G-Code wie ein Slice-Ergebnis ablegen (out/plate_1.gcode) — danach gilt für Vorschau und Senden alles
 * wie beim Slicen. Einen „ams_info“-Block des Anycubic Slicers entfernen (ps_anycubic_gcode hängt den passenden an),
 * ein einzelnes Werkzeug T<n> auf T0 bringen und dann wie in ps_slice auf das gewählte Fach umstellen.
 */
function ps_gcode_prepare(array $job): array {
    $dir = ps_job_dir($job['id']);
    if (!is_dir("$dir/out")) mkdir("$dir/out", 0770, true);
    $gcode = "$dir/out/plate_1.gcode";
    $in = fopen("$dir/{$job['file']}", 'rb'); $out = fopen($gcode, 'wb'); $skip = false;
    while (($l = fgets($in)) !== false) {
        if (!$skip && strncmp($l, '; ams_info = begin', 18) === 0) { $skip = true; continue; }
        if ($skip) { if (strncmp($l, '; ams_info = end', 16) === 0) $skip = false; continue; }
        fwrite($out, $l);
    }
    fclose($in); fclose($out);
    $t = $job['gcode']['tools'] ?? [];
    if (count($t) === 1 && (int)$t[0] !== 0) ps_remap_tools($gcode, [(int)$t[0] => [0, '']]);
    if (isset($job['tool'])) {
        if (!empty($job['printer']['cfs']) && preg_match('/^T0\b/m', (string)file_get_contents($gcode, false, null, 0, 1048576)))
            ps_remap_tools($gcode, [0 => [(int)$job['tool'], (string)$job['slot']['label']]]);
        else ps_insert_toolchange($gcode, (int)$job['tool'], (string)$job['slot']['label']);
    }
    $v = $job['printer']['vendor']; $machine = $job['printer']['machine'];
    $m = $machine ? ps_flatten($v, 'machine', $machine, ['inherits' => $machine]) : [];   // nur fürs Bett in der Vorschau
    $job['profiles'] = ['machine' => $job['gcode']['printer'] ?: $job['printer']['name'], 'process' => 'G-Code',
                        'filament' => $job['gcode']['types'][0] ?? (string)$job['slot']['material']];
    $job['stats'] = ps_preview_build($gcode, "$dir/preview.bin.gz", $m, []);
    try { ps_thumb_build("$dir/preview.bin.gz", "$dir/thumb.webp", $job['slot']['color'] ?? null); } catch (Throwable $e) {}
    return $job;
}

function ps_file_name(array $job): string {
    return mb_substr('3DSEARCH_' . preg_replace('/[^\w.()+-]/u', '_', $job['name']), 0, 90) . '.gcode';
}

/** Was die Oberfläche für die Zuordnung braucht (Status „mapping“): Projekt-Filamente, Fächer, Vorbelegung. */
function ps_map_view(array $job, ?array $printer): array {
    return ['fils' => $job['info3mf']['fils'] ?? [], 'auto' => (object)($job['map_auto'] ?? []),
            'slots' => array_values(array_filter($printer['slots'] ?? [], fn($s) => $s['material'] && empty($s['locked'])))];
}

/** Verbrauch je Fach für die Ergebnisanzeige (nur mehrfarbig, sonst null). */
function ps_paint_view(array $job): ?array {
    if (empty($job['fils'])) return null;
    $labels = [];
    foreach ($job['fils'] as $f) $labels[(int)$f['slot']['index']] = (string)$f['slot']['label'];
    return array_map(fn($p) => $p + ['label' => $labels[$p['slot']] ?? (string)$p['slot']], ps_job_paint($job));
}

/**
 * Geplanter Verbrauch je Fach [{slot, g, material, color}] — für Abbuchungsplan, Fachzuordnung und Kosten.
 * Mehrfarbig: Gramm je Projekt-Filament aus dem G-Code-Kopf („filament used [g] = a, b“), gleiche Fächer zusammengefasst.
 */
function ps_job_paint(array $job): array {
    // AllMyPrinters-App, Fach im Startbefehl ('seq'/'keep'): ein Eintrag je benutztem Werkzeug mit seiner T-Nummer — der Startbefehl
    // (ams_box_mapping, ams_mapping, slot_map …) braucht jede T-Nummer; zwei Filamente im selben Fach blieben sonst ohne Zuordnung
    if (!empty($job['fils']) && in_array($job['printer']['tools'] ?? '', ['seq', 'keep'], true)) {
        $by = $job['stats']['grams_by'] ?? [];
        $out = [];
        foreach ($job['fils'] as $f) {
            if ($f['tool'] === null) continue;
            $out[] = ['slot' => (int)$f['slot']['index'], 'tool' => (int)$f['tool'], 'g' => round((float)($by[$f['ext'] - 1] ?? 0), 2),
                      'material' => (string)$f['slot']['material'], 'color' => $f['slot']['color'] ?? null];
        }
        if ($out && !array_sum(array_column($out, 'g'))) $out[0]['g'] = (float)($job['stats']['grams'] ?? 0);
        $out = array_values(array_filter($out, fn($e) => $e['g'] > 0));
        if ($out) return $out;
    }
    if (empty($job['fils'])) {
        return [['slot' => (int)$job['slot']['index'], 'g' => (float)($job['stats']['grams'] ?? 0),
                 'material' => (string)$job['slot']['material'], 'color' => $job['slot']['color'] ?? null]];
    }
    $by = $job['stats']['grams_by'] ?? [];
    $out = [];
    foreach ($job['fils'] as $f) {
        $i = (int)$f['slot']['index'];
        $g = (float)($by[$f['ext'] - 1] ?? 0);
        if (!isset($out[$i])) $out[$i] = ['slot' => $i, 'g' => 0.0, 'material' => (string)$f['slot']['material'], 'color' => $f['slot']['color'] ?? null];
        $out[$i]['g'] = round($out[$i]['g'] + $g, 2);
    }
    // Ohne Aufteilung im G-Code-Kopf alles aufs erste Fach (besser als gar nicht abbuchen)
    if (!array_sum(array_column($out, 'g'))) { $k = array_key_first($out); $out[$k]['g'] = (float)($job['stats']['grams'] ?? 0); }
    return array_values(array_filter($out, fn($e) => $e['g'] > 0));
}

