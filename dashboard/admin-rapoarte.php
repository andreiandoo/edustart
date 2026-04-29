<?php
if (!defined('ABSPATH')) exit;

$current_user = wp_get_current_user();
$uid   = (int) $current_user->ID;
$roles = (array) ($current_user->roles ?? []);
$is_admin = current_user_can('manage_options');
$is_tutor = in_array('tutor', $roles, true);

if (!$is_admin && !$is_tutor) {
  echo '<div class="p-6"><div class="p-4 text-red-700 bg-red-100 rounded-xl">Acces restricționat.</div></div>';
  return;
}

global $wpdb;
$tbl_generations = $wpdb->prefix . 'edu_generations';
$tbl_students    = $wpdb->prefix . 'edu_students';
$tbl_results     = $wpdb->prefix . 'edu_results';
$tbl_schools     = $wpdb->prefix . 'edu_schools';
$tbl_cities      = $wpdb->prefix . 'edu_cities';
$tbl_counties    = $wpdb->prefix . 'edu_counties';

// Include helper functions (guarded with function_exists)
require_once get_template_directory() . '/dashboard/raport-generatie-helper-sel.php';

// Utility functions for display
if (!function_exists('rap_bg_score_class')) {
  function rap_bg_score_class($v){
    if ($v === null) return 'bg-slate-100 text-slate-500';
    $x = floatval($v);
    if ($x < 1.5)  return 'bg-red-600 text-white font-bold';
    if ($x < 2.0)  return 'bg-orange-400 text-white font-bold';
    if ($x < 2.5)  return 'bg-yellow-300 text-slate-800 font-bold';
    if ($x < 2.75) return 'bg-lime-500 text-slate-800 font-bold';
    if ($x < 3.0)  return 'bg-lime-600 text-white font-bold';
    return 'bg-green-500 text-white font-bold';
  }
}
if (!function_exists('rap_delta_badge_class')) {
  function rap_delta_badge_class($d){
    if ($d === null) return 'bg-slate-100 text-slate-500';
    $x = floatval($d);
    if ($x > 0) return 'bg-green-100 text-green-700';
    if ($x < 0) return 'bg-red-100 text-red-700';
    return 'bg-gray-100 text-gray-700';
  }
}
if (!function_exists('rap_pct_pill')) {
  // Completion pill — aceeași cromatică ca pe /panou/generatii/
  // Marker class "rap-cell-pct" este folosită de CSV exporter ca să poată extrage valoarea separat.
  function rap_pct_pill($pct){
    if ($pct === null) return '<span class="rap-cell-pct inline-flex px-2 py-0.5 text-xs rounded bg-slate-200 text-slate-700">—</span>';
    $v = max(0, min(100, floatval($pct)));
    if     ($v < 40)  $cls = 'bg-red-600 text-white font-bold';
    elseif ($v < 60)  $cls = 'bg-orange-400 text-white font-bold';
    elseif ($v < 75)  $cls = 'bg-yellow-300 text-slate-800 font-bold';
    elseif ($v < 90)  $cls = 'bg-lime-500 text-slate-800 font-bold';
    elseif ($v < 100) $cls = 'bg-lime-600 text-white font-bold';
    else              $cls = 'bg-green-500 text-white font-bold';
    return '<span class="rap-cell-pct inline-flex items-center px-2 py-0.5 text-xs rounded '.$cls.'">'.intval(round($v)).'%</span>';
  }
}

/* ==================== FILTRE ==================== */

// An școlar: calculăm anul curent ca default
$now_m = (int) date('n');
$now_y = (int) date('Y');
$default_year = ($now_m >= 8) ? "$now_y-" . ($now_y + 1) : ($now_y - 1) . "-$now_y";

$year_filter   = isset($_GET['an_scolar']) ? es_normalize_year_str(sanitize_text_field($_GET['an_scolar'])) : $default_year;
$tutor_filter  = isset($_GET['tutor']) ? array_filter(array_map('intval', (array)$_GET['tutor'])) : [];
$nivel_filter  = isset($_GET['nivel']) ? array_filter(array_map('sanitize_text_field', (array)$_GET['nivel'])) : [];
$judet_filter  = isset($_GET['judet']) ? array_filter(array_map('sanitize_text_field', (array)$_GET['judet'])) : [];
$report_type   = isset($_GET['tip']) ? sanitize_text_field($_GET['tip']) : 'sel';
if (!in_array($report_type, ['sel', 'lit'], true)) $report_type = 'sel';

// Anii școlari disponibili (din generații) — normalizăm ca să nu apară "2025" separat de "2025-2026"
$years_raw = $wpdb->get_col("SELECT DISTINCT year FROM {$tbl_generations} WHERE year != '' ORDER BY year DESC");
$years_set = [];
foreach ((array)$years_raw as $yr) {
  $n = es_normalize_year_str($yr);
  if ($n !== '') $years_set[$n] = true;
}
krsort($years_set, SORT_NATURAL);
$years_available = array_keys($years_set);

// Lista tutorilor
$all_tutors = get_users([
  'role'    => 'tutor',
  'orderby' => 'display_name',
  'order'   => 'ASC',
  'number'  => -1,
]);

// Dacă e tutor → forțăm filtrul doar pe el
if ($is_tutor && !$is_admin) {
  $tutor_filter = [$uid];
}

// Collect counties from schools assigned to professors
$nivel_options = ['prescolar'=>'Preșcolar','primar'=>'Primar','gimnazial'=>'Gimnazial','liceu'=>'Liceu'];

// All counties (for dropdown)
$all_counties_list = $wpdb->get_col("SELECT DISTINCT j.name FROM {$tbl_counties} j ORDER BY j.name");

/* ==================== DATA LOADING ==================== */

// 1) Generații din anul selectat (acceptăm ambele forme stocate: "YYYY" și "YYYY-YYYY")
$year_variants = es_year_variants($year_filter);
if (!empty($year_variants)) {
  $in_ph = implode(',', array_fill(0, count($year_variants), '%s'));
  $gen_where = $wpdb->prepare("WHERE year IN ($in_ph)", ...$year_variants);
  $generations = $wpdb->get_results("SELECT * FROM {$tbl_generations} {$gen_where} ORDER BY professor_id, id");
} else {
  $generations = [];
}

// 2) Colectăm profesori unici
$prof_ids_raw = array_unique(array_filter(array_map(fn($g) => (int)$g->professor_id, $generations)));

// 3) Filtrare pe tutor
if (!empty($tutor_filter)) {
  $prof_ids = [];
  foreach ($prof_ids_raw as $pid) {
    $tid = (int) get_user_meta($pid, 'assigned_tutor_id', true);
    if (in_array($tid, $tutor_filter, true)) {
      $prof_ids[] = $pid;
    }
  }
} else {
  $prof_ids = $prof_ids_raw;
}

// 3b) Filtru nivel predare
if (!empty($nivel_filter)) {
  $prof_ids = array_filter($prof_ids, function($pid) use ($nivel_filter) {
    $raw = get_user_meta($pid, 'nivel_predare', true);
    $vals = is_array($raw) ? $raw : [$raw];
    foreach ($vals as $v) {
      $norm = strtolower(trim((string)$v));
      if (in_array($norm, $nivel_filter, true)) return true;
    }
    return false;
  });
}

// 3c) Filtru județ (prin școli atribuite)
if (!empty($judet_filter)) {
  $prof_ids = array_filter($prof_ids, function($pid) use ($wpdb, $tbl_schools, $tbl_cities, $tbl_counties, $judet_filter) {
    $sids = get_user_meta($pid, 'assigned_school_ids', true);
    if (!is_array($sids) || empty($sids)) return false;
    $sids = array_filter(array_map('intval', $sids));
    if (empty($sids)) return false;
    $in = implode(',', array_fill(0, count($sids), '%d'));
    $counties = $wpdb->get_col($wpdb->prepare(
      "SELECT DISTINCT j.name FROM {$tbl_schools} s
       JOIN {$tbl_cities} c ON s.city_id = c.id
       JOIN {$tbl_counties} j ON c.county_id = j.id
       WHERE s.id IN ($in)", ...$sids
    ));
    return !empty(array_intersect($counties, $judet_filter));
  });
  $prof_ids = array_values($prof_ids);
}

// 4) Calculăm scoruri per profesor
$report_data = [];

if (!empty($prof_ids)) {
  // Map generații per profesor
  $gens_by_prof = [];
  foreach ($generations as $g) {
    $pid = (int)$g->professor_id;
    if (in_array($pid, $prof_ids, true)) {
      $gens_by_prof[$pid][] = $g;
    }
  }

  // Colectăm toți studenții din aceste generații
  $gen_ids = array_map(fn($g) => (int)$g->id, $generations);
  $gen_ids = array_filter($gen_ids);

  $students_by_gen = [];
  $all_student_ids = [];
  if (!empty($gen_ids)) {
    $in_gens = implode(',', array_fill(0, count($gen_ids), '%d'));
    $all_students = $wpdb->get_results($wpdb->prepare(
      "SELECT id, generation_id, professor_id FROM {$tbl_students} WHERE generation_id IN ($in_gens)",
      ...$gen_ids
    ));
    foreach ($all_students as $st) {
      $students_by_gen[(int)$st->generation_id][] = $st;
      $all_student_ids[] = (int)$st->id;
    }
  }

  // Colectăm toate rezultatele (SEL sau LIT)
  $all_results_by_student = [];
  if (!empty($all_student_ids)) {
    $all_student_ids = array_unique($all_student_ids);
    $in_st = implode(',', array_fill(0, count($all_student_ids), '%d'));
    $query_modul_type = ($report_type === 'lit') ? 'lit' : 'sel';
    $all_raw_results = $wpdb->get_results($wpdb->prepare(
      "SELECT id, student_id, modul_type, modul, results, score, completion, status
       FROM {$tbl_results}
       WHERE modul_type = %s AND student_id IN ($in_st)
       ORDER BY created_at DESC",
      $query_modul_type, ...$all_student_ids
    ));
    foreach ($all_raw_results as $r) {
      $all_results_by_student[(int)$r->student_id][] = $r;
    }
  }

  // Helper: parse LIT total_pct from a result row
  if (!function_exists('rap_lit_parse_total_pct')) {
    function rap_lit_parse_total_pct($row) {
      $score = $row->score ?? null;
      if (is_serialized($score)) {
        $a = @unserialize($score);
        if (is_array($a) && isset($a['total']) && is_numeric($a['total'])) {
          return (float)$a['total'];
        }
      }
      $json = json_decode($row->results ?? '', true);
      if (is_array($json)) {
        if (isset($json['total']) && is_numeric($json['total'])) return (float)$json['total'];
        if (isset($json['total_pct']) && is_numeric($json['total_pct'])) return (float)$json['total_pct'];
        // Sum breakdown items
        if (!empty($json['breakdown'])) {
          $sum = 0; $had = false;
          foreach ($json['breakdown'] as $it) {
            if (is_numeric($it['value'] ?? null)) { $sum += (float)$it['value']; $had = true; }
          }
          if ($had) return $sum;
        }
      }
      return null;
    }
  }

  // Per profesor: calculăm medii
  foreach ($prof_ids as $pid) {
    $prof_user = get_user_by('id', $pid);
    if (!$prof_user) continue;

    $prof_name = trim(($prof_user->first_name ?? '') . ' ' . ($prof_user->last_name ?? ''));
    if ($prof_name === '') $prof_name = $prof_user->display_name ?: $prof_user->user_login;

    $tid = (int) get_user_meta($pid, 'assigned_tutor_id', true);
    $tutor_user = $tid ? get_user_by('id', $tid) : null;
    $tutor_name = $tutor_user ? trim(($tutor_user->first_name ?? '') . ' ' . ($tutor_user->last_name ?? '')) : '—';
    if ($tutor_name === '') $tutor_name = $tutor_user ? ($tutor_user->display_name ?: '—') : '—';

    $nivel = get_user_meta($pid, 'nivel_predare', true);
    if (is_array($nivel)) $nivel = implode(', ', $nivel);

    $prof_gens = $gens_by_prof[$pid] ?? [];
    $gen_names = array_map(fn($g) => $g->name, $prof_gens);

    $prof_student_ids = [];
    foreach ($prof_gens as $g) {
      $gid = (int)$g->id;
      if (!empty($students_by_gen[$gid])) {
        foreach ($students_by_gen[$gid] as $st) {
          $prof_student_ids[] = (int)$st->id;
        }
      }
    }
    $prof_student_ids = array_unique($prof_student_ids);
    $student_count = count($prof_student_ids);

    $t0_avg = null; $ti_avg = null; $t1_avg = null;
    $t0_pct = null; $ti_pct = null; $t1_pct = null;
    $t0_done = 0;   $ti_done = 0;   $t1_done = 0;

    if ($report_type === 'sel') {
      // SEL: medii pe capitole
      $by_stage = ['sel-t0' => [], 'sel-ti' => [], 'sel-t1' => []];
      foreach ($prof_student_ids as $sid) {
        $seen = [];
        foreach ($all_results_by_student[$sid] ?? [] as $r) {
          $stage = edus_sel_stage_from_modul($r->modul);
          if ($stage && !isset($seen[$stage])) { $seen[$stage] = true; $by_stage[$stage][] = $r; }
        }
      }
      $t0_avg = edus_array_avg_non_null(edus_sel_avg_by_chapters($by_stage['sel-t0'], $SEL_CHAPTERS));
      $ti_avg = edus_array_avg_non_null(edus_sel_avg_by_chapters($by_stage['sel-ti'], $SEL_CHAPTERS));
      $t1_avg = edus_array_avg_non_null(edus_sel_avg_by_chapters($by_stage['sel-t1'], $SEL_CHAPTERS));
      // Rate de completare (câți elevi din generațiile profesorului au completat fiecare stadiu)
      $t0_done = count($by_stage['sel-t0']);
      $ti_done = count($by_stage['sel-ti']);
      $t1_done = count($by_stage['sel-t1']);
      if ($student_count > 0) {
        $t0_pct = 100.0 * $t0_done / $student_count;
        $ti_pct = 100.0 * $ti_done / $student_count;
        $t1_pct = 100.0 * $t1_done / $student_count;
      }
    } else {
      // LIT: total_pct per student, medie pe T0 și T1 (nu are Ti)
      $lit_t0_pcts = []; $lit_t1_pcts = [];
      foreach ($prof_student_ids as $sid) {
        $seen = [];
        foreach ($all_results_by_student[$sid] ?? [] as $r) {
          $modul = strtolower(trim($r->modul ?? ''));
          if (strpos($modul, 'lit-t0') === 0 && !isset($seen['t0'])) {
            $seen['t0'] = true;
            $pct = rap_lit_parse_total_pct($r);
            if ($pct !== null) $lit_t0_pcts[] = $pct;
          } elseif (strpos($modul, 'lit-t1') === 0 && !isset($seen['t1'])) {
            $seen['t1'] = true;
            $pct = rap_lit_parse_total_pct($r);
            if ($pct !== null) $lit_t1_pcts[] = $pct;
          }
        }
      }
      $t0_avg = count($lit_t0_pcts) ? array_sum($lit_t0_pcts) / count($lit_t0_pcts) : null;
      $t1_avg = count($lit_t1_pcts) ? array_sum($lit_t1_pcts) / count($lit_t1_pcts) : null;
      // LIT nu are Ti
    }

    $delta = ($t1_avg !== null && $t0_avg !== null) ? ($t1_avg - $t0_avg) : null;

    $report_data[] = [
      'pid'          => $pid,
      'name'         => $prof_name,
      'tutor'        => $tutor_name,
      'tutor_id'     => $tid,
      'nivel'        => $nivel ?: '—',
      'gen_names'    => $gen_names,
      'students'     => $student_count,
      't0'           => $t0_avg,
      'ti'           => $ti_avg,
      't1'           => $t1_avg,
      't0_pct'       => $t0_pct,
      'ti_pct'       => $ti_pct,
      't1_pct'       => $t1_pct,
      't0_done'      => $t0_done,
      'ti_done'      => $ti_done,
      't1_done'      => $t1_done,
      'delta'        => $delta,
    ];
  }
}

// Sort by name
usort($report_data, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// Aggregate per tutor
$tutor_agg = [];
foreach ($report_data as $d) {
  $tid = $d['tutor_id'] ?? 0;
  $tname = $d['tutor'];
  if (!isset($tutor_agg[$tid])) {
    $tutor_agg[$tid] = [
      'name' => $tname, 'profs' => 0, 'students' => 0,
      't0_vals' => [], 'ti_vals' => [], 't1_vals' => [],
      't0_done' => 0, 'ti_done' => 0, 't1_done' => 0,
    ];
  }
  $tutor_agg[$tid]['profs']++;
  $tutor_agg[$tid]['students'] += $d['students'];
  if ($d['t0'] !== null) $tutor_agg[$tid]['t0_vals'][] = $d['t0'];
  if ($d['ti'] !== null) $tutor_agg[$tid]['ti_vals'][] = $d['ti'];
  if ($d['t1'] !== null) $tutor_agg[$tid]['t1_vals'][] = $d['t1'];
  $tutor_agg[$tid]['t0_done'] += (int)($d['t0_done'] ?? 0);
  $tutor_agg[$tid]['ti_done'] += (int)($d['ti_done'] ?? 0);
  $tutor_agg[$tid]['t1_done'] += (int)($d['t1_done'] ?? 0);
}
foreach ($tutor_agg as &$ta) {
  $ta['t0'] = count($ta['t0_vals']) ? array_sum($ta['t0_vals']) / count($ta['t0_vals']) : null;
  $ta['ti'] = count($ta['ti_vals']) ? array_sum($ta['ti_vals']) / count($ta['ti_vals']) : null;
  $ta['t1'] = count($ta['t1_vals']) ? array_sum($ta['t1_vals']) / count($ta['t1_vals']) : null;
  $ta['delta'] = ($ta['t1'] !== null && $ta['t0'] !== null) ? ($ta['t1'] - $ta['t0']) : null;
  // Rate de completare pe tutor (total elevi completați / total elevi)
  $ta['t0_pct'] = $ta['students'] > 0 ? 100.0 * $ta['t0_done'] / $ta['students'] : null;
  $ta['ti_pct'] = $ta['students'] > 0 ? 100.0 * $ta['ti_done'] / $ta['students'] : null;
  $ta['t1_pct'] = $ta['students'] > 0 ? 100.0 * $ta['t1_done'] / $ta['students'] : null;
}
unset($ta);
uasort($tutor_agg, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// Prepare chart data — per profesor
$chart_labels = array_map(fn($d) => $d['name'], $report_data);
$chart_t0 = array_map(fn($d) => $d['t0'] !== null ? round($d['t0'], 2) : null, $report_data);
$chart_ti = array_map(fn($d) => $d['ti'] !== null ? round($d['ti'], 2) : null, $report_data);
$chart_t1 = array_map(fn($d) => $d['t1'] !== null ? round($d['t1'], 2) : null, $report_data);

// Per-professor data for JS (tutor-filtered charts / prof multiselect)
$prof_chart_data = array_values(array_map(fn($d) => [
  'pid'      => (string)($d['pid'] ?? 0),
  'name'     => $d['name'],
  'tutor_id' => (string)($d['tutor_id'] ?? 0),
  't0'       => $d['t0'] !== null ? round($d['t0'], 2) : null,
  'ti'       => $d['ti'] !== null ? round($d['ti'], 2) : null,
  't1'       => $d['t1'] !== null ? round($d['t1'], 2) : null,
], $report_data));

// Lista profesori pentru dropdown multiselect (chart + tabel per profesor)
$chart_prof_list = [];
foreach ($report_data as $d) {
  $pid = (string)($d['pid'] ?? 0);
  if ($pid && !isset($chart_prof_list[$pid])) {
    $chart_prof_list[$pid] = $d['name'];
  }
}
uksort($chart_prof_list, fn($a, $b) => strcasecmp($chart_prof_list[$a], $chart_prof_list[$b]));

// Tutor list for chart dropdown (id => name, from actual data)
$chart_tutor_list = [];
foreach ($report_data as $d) {
  $tid = (string)($d['tutor_id'] ?? 0);
  if ($tid && !isset($chart_tutor_list[$tid])) {
    $chart_tutor_list[$tid] = $d['tutor'];
  }
}
uksort($chart_tutor_list, fn($a, $b) => strcasecmp($chart_tutor_list[$a], $chart_tutor_list[$b]));

$report_label = ($report_type === 'lit') ? 'LIT' : 'SEL';
$has_ti = ($report_type === 'sel'); // LIT nu are Ti

// Tutor options for dropdown
$tutor_options = [];
foreach ($all_tutors as $tu) {
  $tname = trim(($tu->first_name ?? '') . ' ' . ($tu->last_name ?? ''));
  if ($tname === '') $tname = $tu->display_name ?: $tu->user_login;
  $tutor_options[(int)$tu->ID] = $tname;
}
?>

<!-- Filters -->
<section class="sticky top-0 z-20 border-b bg-slate-800 border-slate-700">
  <form method="get" action="<?php echo esc_url(home_url('/panou/rapoarte/')); ?>" class="flex flex-wrap items-end gap-3 px-4 py-3">
    <!-- An școlar -->
    <div>
      <label class="block mb-1 text-xs font-medium text-slate-300">An școlar</label>
      <select name="an_scolar" class="px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-500">
        <?php foreach ($years_available as $yr): ?>
          <option value="<?php echo esc_attr($yr); ?>" <?php selected($year_filter === $yr); ?>><?php echo esc_html($yr); ?></option>
        <?php endforeach; ?>
        <?php if (!in_array($default_year, $years_available, true)): ?>
          <option value="<?php echo esc_attr($default_year); ?>" <?php selected($year_filter === $default_year); ?>><?php echo esc_html($default_year); ?></option>
        <?php endif; ?>
      </select>
    </div>

    <!-- Tip raport -->
    <div>
      <label class="block mb-1 text-xs font-medium text-slate-300">Tip raport</label>
      <select name="tip" class="px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-500">
        <option value="sel" <?php selected($report_type, 'sel'); ?>>SEL</option>
        <option value="lit" <?php selected($report_type, 'lit'); ?>>Literație (LIT)</option>
      </select>
    </div>

    <!-- Tutor multiselect -->
    <?php if ($is_admin): ?>
    <div class="relative" id="rap-tutor-wrap">
      <label class="block mb-1 text-xs font-medium text-slate-300">Tutor</label>
      <div id="rap-tutor-inputs">
        <?php foreach ($tutor_filter as $tv): ?>
          <input type="hidden" name="tutor[]" value="<?php echo (int)$tv; ?>">
        <?php endforeach; ?>
      </div>
      <div id="rap-tutor-trigger" class="flex items-center gap-1 min-w-[200px] min-h-[38px] px-3 py-1.5 text-sm bg-white border shadow-sm rounded-xl border-slate-300 cursor-pointer hover:border-slate-400 flex-wrap">
        <?php if (empty($tutor_filter)): ?>
          <span class="ms-placeholder text-slate-400">— Toți tutorii —</span>
        <?php else: ?>
          <?php foreach ($tutor_filter as $tv):
            $tlab = $tutor_options[$tv] ?? "Tutor #$tv";
          ?>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 ms-tag" data-val="<?php echo (int)$tv; ?>">
              <?php echo esc_html($tlab); ?>
              <button type="button" class="ms-remove hover:text-red-600">&times;</button>
            </span>
          <?php endforeach; ?>
        <?php endif; ?>
        <svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
      </div>
      <div id="rap-tutor-dropdown" class="hidden absolute z-30 w-full mt-1 bg-white border shadow-lg rounded-xl border-slate-200 py-1 max-h-60 overflow-y-auto" style="min-width:280px">
        <?php foreach ($tutor_options as $tid => $tlab): ?>
          <div class="ms-opt flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50" data-val="<?php echo (int)$tid; ?>">
            <span class="inline-flex items-center justify-center w-4 h-4 rounded border ms-check <?php echo in_array($tid, $tutor_filter, true) ? 'bg-sky-600 border-sky-600' : 'bg-white border-slate-300'; ?>">
              <?php if (in_array($tid, $tutor_filter, true)): ?>
                <svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
              <?php endif; ?>
            </span>
            <span><?php echo esc_html($tlab); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php else: ?>
      <input type="hidden" name="tutor[]" value="<?php echo (int)$uid; ?>">
      <div>
        <label class="block mb-1 text-xs font-medium text-slate-300">Tutor</label>
        <div class="px-3 py-2 text-sm bg-slate-100 border rounded-xl border-slate-300 text-slate-700">
          <?php echo esc_html($tutor_options[$uid] ?? $current_user->display_name); ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Nivel predare multiselect -->
    <div class="relative" id="rap-nivel-wrap">
      <label class="block mb-1 text-xs font-medium text-slate-300">Nivel predare</label>
      <div id="rap-nivel-inputs">
        <?php foreach ($nivel_filter as $v): ?>
          <input type="hidden" name="nivel[]" value="<?php echo esc_attr($v); ?>">
        <?php endforeach; ?>
      </div>
      <div id="rap-nivel-trigger" class="flex items-center gap-1 min-w-[160px] min-h-[38px] px-3 py-1.5 text-sm bg-white border shadow-sm rounded-xl border-slate-300 cursor-pointer hover:border-slate-400 flex-wrap">
        <?php if (empty($nivel_filter)): ?>
          <span class="ms-placeholder text-slate-400">— Oricare —</span>
        <?php else: ?>
          <?php foreach ($nivel_filter as $v): ?>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 ms-tag" data-val="<?php echo esc_attr($v); ?>">
              <?php echo esc_html($nivel_options[$v] ?? $v); ?>
              <button type="button" class="ms-remove hover:text-red-600">&times;</button>
            </span>
          <?php endforeach; ?>
        <?php endif; ?>
        <svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
      </div>
      <div id="rap-nivel-dropdown" class="hidden absolute z-30 w-full mt-1 bg-white border shadow-lg rounded-xl border-slate-200 py-1" style="min-width:200px">
        <?php foreach ($nivel_options as $k => $lab): ?>
          <div class="ms-opt flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50" data-val="<?php echo esc_attr($k); ?>">
            <span class="inline-flex items-center justify-center w-4 h-4 rounded border ms-check <?php echo in_array($k, $nivel_filter, true) ? 'bg-sky-600 border-sky-600' : 'bg-white border-slate-300'; ?>">
              <?php if (in_array($k, $nivel_filter, true)): ?>
                <svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
              <?php endif; ?>
            </span>
            <span><?php echo esc_html($lab); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Județ multiselect -->
    <div class="relative" id="rap-judet-wrap">
      <label class="block mb-1 text-xs font-medium text-slate-300">Județ</label>
      <div id="rap-judet-inputs">
        <?php foreach ($judet_filter as $v): ?>
          <input type="hidden" name="judet[]" value="<?php echo esc_attr($v); ?>">
        <?php endforeach; ?>
      </div>
      <div id="rap-judet-trigger" class="flex items-center gap-1 min-w-[160px] min-h-[38px] px-3 py-1.5 text-sm bg-white border shadow-sm rounded-xl border-slate-300 cursor-pointer hover:border-slate-400 flex-wrap">
        <?php if (empty($judet_filter)): ?>
          <span class="ms-placeholder text-slate-400">— Toate —</span>
        <?php else: ?>
          <?php foreach ($judet_filter as $v): ?>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 ms-tag" data-val="<?php echo esc_attr($v); ?>">
              <?php echo esc_html($v); ?>
              <button type="button" class="ms-remove hover:text-red-600">&times;</button>
            </span>
          <?php endforeach; ?>
        <?php endif; ?>
        <svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
      </div>
      <div id="rap-judet-dropdown" class="hidden absolute z-30 w-full mt-1 bg-white border shadow-lg rounded-xl border-slate-200 py-1 max-h-60 overflow-y-auto" style="min-width:220px">
        <div class="px-2 py-1 border-b border-slate-100">
          <input id="rap-judet-search" type="text" placeholder="Caută județ..." class="w-full px-2 py-1 text-sm bg-white border rounded-lg border-slate-300 focus:outline-none focus:ring-1 focus:ring-sky-500">
        </div>
        <?php foreach ($all_counties_list as $cn): ?>
          <div class="ms-opt flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50" data-val="<?php echo esc_attr($cn); ?>">
            <span class="inline-flex items-center justify-center w-4 h-4 rounded border ms-check <?php echo in_array($cn, $judet_filter, true) ? 'bg-sky-600 border-sky-600' : 'bg-white border-slate-300'; ?>">
              <?php if (in_array($cn, $judet_filter, true)): ?>
                <svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
              <?php endif; ?>
            </span>
            <span><?php echo esc_html($cn); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="flex items-end gap-2">
      <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white shadow-sm rounded-xl bg-emerald-600 hover:bg-emerald-700">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M21 21 15 15m2-5a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"/></svg>
        Filtrează
      </button>
      <button type="button" id="rap-open-export-elevi"
              class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white shadow-sm rounded-xl bg-sky-600 hover:bg-sky-700">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
        Exportă rezultate elevi
      </button>
      <?php
        $has_filters = !empty($tutor_filter) || !empty($nivel_filter) || !empty($judet_filter) || $year_filter !== $default_year || $report_type !== 'sel';
        if ($has_filters):
      ?>
        <a href="<?php echo esc_url(home_url('/panou/rapoarte/')); ?>"
           class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-slate-300 rounded-xl hover:bg-slate-700 hover:text-white border border-slate-600">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
          Șterge filtre
        </a>
      <?php endif; ?>
    </div>

    <div class="ml-auto text-xs text-slate-400">
      <?php echo count($report_data); ?> profesori &middot; An școlar: <?php echo esc_html($year_filter); ?>
    </div>
  </form>
</section>

<?php if (empty($report_data)): ?>
  <section class="p-6 m-6 bg-white border rounded-2xl border-slate-200">
    <p class="text-slate-500">Nu s-au găsit date SEL pentru anul școlar <strong><?php echo esc_html($year_filter); ?></strong> și filtrele selectate.</p>
  </section>
<?php else: ?>

<!-- Chart per profesor -->
<section class="p-6 mx-6 mt-6 bg-white border rounded-2xl border-slate-200">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <h2 class="text-lg font-semibold text-slate-800">Scoruri <?php echo esc_html($report_label); ?> per profesor</h2>
    <div class="flex flex-wrap items-center gap-2">
      <!-- Prof multiselect (filter pentru grafic, tabel & export) -->
      <div class="relative" id="chart-prof-wrap">
        <div id="chart-prof-trigger" class="flex items-center gap-1 min-w-[240px] min-h-[36px] px-3 py-1.5 text-sm bg-white border shadow-sm rounded-xl border-slate-300 cursor-pointer hover:border-slate-400 flex-wrap">
          <span class="ms-placeholder text-slate-400">Toți profesorii</span>
          <svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
        </div>
        <div id="chart-prof-dropdown" class="hidden absolute right-0 z-30 mt-1 bg-white border shadow-lg rounded-xl border-slate-200 py-1 max-h-72 overflow-y-auto" style="min-width:280px">
          <div class="px-2 pb-2 pt-1 sticky top-0 bg-white border-b border-slate-100">
            <input type="text" id="chart-prof-search" placeholder="Caută profesor..."
                   class="w-full px-2 py-1 text-sm bg-white border border-slate-200 rounded-lg focus:outline-none focus:ring-1 focus:ring-sky-500">
          </div>
          <?php foreach ($chart_prof_list as $ppid => $pname): ?>
            <div class="cp-opt flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50"
                 data-val="<?php echo esc_attr($ppid); ?>"
                 data-name="<?php echo esc_attr(mb_strtolower(remove_accents((string)$pname))); ?>">
              <span class="inline-flex items-center justify-center w-4 h-4 rounded border cp-check bg-sky-600 border-sky-600">
                <svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
              </span>
              <span><?php echo esc_html($pname); ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <button type="button" id="rap-toggle-prof-chart" data-target="sel-chart-wrap"
              class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-slate-700 bg-slate-100 rounded-lg hover:bg-slate-200"
              aria-expanded="false">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
        <span class="rap-toggle-label">Arată grafic</span>
      </button>
      <button type="button" id="rap-export-prof"
              data-table="rap-table" data-filename="scoruri-<?php echo esc_attr(strtolower($report_label)); ?>-per-profesor"
              class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
        Export Scoruri <?php echo esc_html($report_label); ?> per profesor
      </button>
    </div>
  </div>
  <div id="sel-chart-wrap" class="hidden" style="height:300px;">
    <canvas id="sel-chart"></canvas>
  </div>
</section>

<!-- Table -->
<section class="px-6 pb-8 mt-4">
  <div class="bg-white border shadow-sm rounded-2xl border-slate-200">
    <table class="w-full text-sm" id="rap-table">
      <thead class="sticky top-[52px] z-10 bg-sky-800 text-white">
        <tr>
          <th class="px-3 py-3 font-semibold text-left cursor-pointer select-none hover:bg-sky-900" data-sort="name">
            <span class="inline-flex items-center gap-1">Profesor <svg class="w-3 h-3 opacity-40" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/></svg></span>
          </th>
          <th class="px-3 py-3 font-semibold text-left">Tutor</th>
          <th class="px-3 py-3 font-semibold text-left">Nivel</th>
          <th class="px-3 py-3 font-semibold text-left">Generație</th>
          <th class="px-3 py-3 font-semibold text-center cursor-pointer select-none hover:bg-sky-900" data-sort="students">
            <span class="inline-flex items-center gap-1">Elevi <svg class="w-3 h-3 opacity-40" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/></svg></span>
          </th>
          <th class="px-3 py-3 font-semibold text-center cursor-pointer select-none hover:bg-sky-900" data-sort="t0"<?php if ($report_type === 'sel'): ?> data-csv-split="1"<?php endif; ?>>
            <span class="inline-flex items-center gap-1"><?php echo esc_html($report_label); ?> T0 <svg class="w-3 h-3 opacity-40" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/></svg></span>
          </th>
          <?php if ($has_ti): ?>
          <th class="px-3 py-3 font-semibold text-center cursor-pointer select-none hover:bg-sky-900" data-sort="ti"<?php if ($report_type === 'sel'): ?> data-csv-split="1"<?php endif; ?>>
            <span class="inline-flex items-center gap-1"><?php echo esc_html($report_label); ?> Ti <svg class="w-3 h-3 opacity-40" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/></svg></span>
          </th>
          <?php endif; ?>
          <th class="px-3 py-3 font-semibold text-center cursor-pointer select-none hover:bg-sky-900" data-sort="t1"<?php if ($report_type === 'sel'): ?> data-csv-split="1"<?php endif; ?>>
            <span class="inline-flex items-center gap-1"><?php echo esc_html($report_label); ?> T1 <svg class="w-3 h-3 opacity-40" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/></svg></span>
          </th>
          <?php if ($report_type !== 'sel'): ?>
          <th class="px-3 py-3 font-semibold text-center">Δ (T1−T0)</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <?php foreach ($report_data as $d): ?>
          <tr class="transition-colors hover:bg-slate-50"
              data-pid="<?php echo (int)$d['pid']; ?>"
              data-tid="<?php echo (int)($d['tutor_id'] ?? 0); ?>"
              data-name="<?php echo esc_attr($d['name']); ?>"
              data-students="<?php echo (int)$d['students']; ?>"
              data-t0="<?php echo $d['t0'] !== null ? round($d['t0'], 4) : ''; ?>"
              data-ti="<?php echo $d['ti'] !== null ? round($d['ti'], 4) : ''; ?>"
              data-t1="<?php echo $d['t1'] !== null ? round($d['t1'], 4) : ''; ?>">
            <td class="px-3 py-3 font-medium text-slate-900">
              <a href="<?php echo esc_url(home_url('/panou/profesor/' . $d['pid'])); ?>" class="hover:text-emerald-700">
                <?php echo esc_html($d['name']); ?>
              </a>
            </td>
            <td class="px-3 py-3 text-slate-700"><?php echo esc_html($d['tutor']); ?></td>
            <td class="px-3 py-3 text-slate-700"><?php echo esc_html($d['nivel']); ?></td>
            <td class="px-3 py-3 text-slate-700">
              <?php if ($d['gen_names']): ?>
                <div class="flex flex-wrap gap-1">
                  <?php foreach ($d['gen_names'] as $gn): ?>
                    <span class="inline-block px-2 py-0.5 text-xs rounded-full bg-slate-100 text-slate-700"><?php echo esc_html($gn); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td class="px-3 py-3 text-center text-slate-800"><?php echo (int)$d['students']; ?></td>
            <td class="px-3 py-3 text-center">
              <?php if ($d['t0'] !== null): ?>
                <div class="inline-flex items-center gap-1.5">
                  <span class="rap-cell-score inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_bg_score_class($d['t0'])); ?>"><?php echo number_format($d['t0'], 2); ?></span>
                  <?php if ($report_type === 'sel'): ?><?php echo rap_pct_pill($d['t0_pct'] ?? null); ?><?php endif; ?>
                </div>
              <?php else: ?>
                <span class="rap-cell-score text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <?php if ($has_ti): ?>
            <td class="px-3 py-3 text-center">
              <?php if ($d['ti'] !== null): ?>
                <div class="inline-flex items-center gap-1.5">
                  <span class="rap-cell-score inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_bg_score_class($d['ti'])); ?>"><?php echo number_format($d['ti'], 2); ?></span>
                  <?php if ($report_type === 'sel'): ?><?php echo rap_pct_pill($d['ti_pct'] ?? null); ?><?php endif; ?>
                </div>
              <?php else: ?>
                <span class="rap-cell-score text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="px-3 py-3 text-center">
              <?php if ($d['t1'] !== null): ?>
                <div class="inline-flex items-center gap-1.5">
                  <span class="rap-cell-score inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_bg_score_class($d['t1'])); ?>"><?php echo number_format($d['t1'], 2); ?></span>
                  <?php if ($report_type === 'sel'): ?><?php echo rap_pct_pill($d['t1_pct'] ?? null); ?><?php endif; ?>
                </div>
              <?php else: ?>
                <span class="rap-cell-score text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <?php if ($report_type !== 'sel'): ?>
            <td class="px-3 py-3 text-center">
              <?php if ($d['delta'] !== null): ?>
                <span class="inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_delta_badge_class($d['delta'])); ?>">
                  <?php echo ($d['delta'] > 0 ? '+' : '') . number_format($d['delta'], 2); ?>
                </span>
              <?php else: ?>
                <span class="text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- Scoruri per tutor — charts with inline tutor filter -->
<section class="p-6 mx-6 mt-4 bg-white border rounded-2xl border-slate-200">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <h2 class="text-lg font-semibold text-slate-800">Scoruri <?php echo esc_html($report_label); ?> per tutor</h2>
    <div class="flex flex-wrap items-center gap-2">
      <!-- Inline tutor filter -->
      <div class="relative" id="chart-tutor-wrap">
        <div id="chart-tutor-trigger" class="flex items-center gap-1 min-w-[240px] min-h-[36px] px-3 py-1.5 text-sm bg-white border shadow-sm rounded-xl border-slate-300 cursor-pointer hover:border-slate-400 flex-wrap">
          <span class="ms-placeholder text-slate-400">Toți tutorii</span>
          <svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
        </div>
        <div id="chart-tutor-dropdown" class="hidden absolute right-0 z-30 mt-1 bg-white border shadow-lg rounded-xl border-slate-200 py-1 max-h-72 overflow-y-auto" style="min-width:280px">
          <div class="px-2 pb-2 pt-1 sticky top-0 bg-white border-b border-slate-100">
            <input type="text" id="chart-tutor-search" placeholder="Caută tutor..."
                   class="w-full px-2 py-1 text-sm bg-white border border-slate-200 rounded-lg focus:outline-none focus:ring-1 focus:ring-sky-500">
          </div>
          <?php foreach ($chart_tutor_list as $tid => $tlab): ?>
            <div class="ct-opt flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50"
                 data-val="<?php echo esc_attr($tid); ?>"
                 data-name="<?php echo esc_attr(mb_strtolower(remove_accents((string)$tlab))); ?>">
              <span class="inline-flex items-center justify-center w-4 h-4 rounded border ct-check bg-sky-600 border-sky-600">
                <svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
              </span>
              <span><?php echo esc_html($tlab); ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <button type="button" id="rap-toggle-tutor-chart" data-target="tutor-chart-wrap"
              class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-slate-700 bg-slate-100 rounded-lg hover:bg-slate-200"
              aria-expanded="false">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
        <span class="rap-toggle-label">Arată grafic</span>
      </button>
      <button type="button" id="rap-export-tutor"
              data-table="rap-tutor-table" data-filename="scoruri-<?php echo esc_attr(strtolower($report_label)); ?>-per-tutor"
              class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
        Export Scoruri <?php echo esc_html($report_label); ?> per tutor
      </button>
    </div>
  </div>

  <div id="tutor-chart-wrap" class="hidden space-y-6">
    <div>
      <h3 class="mb-2 text-sm font-medium text-slate-600">Avg. T0</h3>
      <div style="height:300px;"><canvas id="tutor-chart-t0"></canvas></div>
    </div>
    <?php if ($has_ti): ?>
    <div>
      <h3 class="mb-2 text-sm font-medium text-slate-600">Avg. Ti</h3>
      <div style="height:300px;"><canvas id="tutor-chart-ti"></canvas></div>
    </div>
    <?php endif; ?>
    <div>
      <h3 class="mb-2 text-sm font-medium text-slate-600">Avg. T1</h3>
      <div style="height:300px;"><canvas id="tutor-chart-t1"></canvas></div>
    </div>
  </div>
</section>

<!-- Tutor aggregation table -->
<section class="px-6 pb-4 mt-4">
  <div class="bg-white border shadow-sm rounded-2xl border-slate-200">
    <table class="w-full text-sm" id="rap-tutor-table">
      <thead class="sticky top-[52px] z-10 bg-indigo-800 text-white">
        <tr>
          <th class="px-3 py-3 font-semibold text-left">Tutor</th>
          <th class="px-3 py-3 font-semibold text-center">Profesori</th>
          <th class="px-3 py-3 font-semibold text-center">Elevi</th>
          <th class="px-3 py-3 font-semibold text-center"<?php if ($report_type === 'sel'): ?> data-csv-split="1"<?php endif; ?>><?php echo esc_html($report_label); ?> T0</th>
          <?php if ($has_ti): ?>
          <th class="px-3 py-3 font-semibold text-center"<?php if ($report_type === 'sel'): ?> data-csv-split="1"<?php endif; ?>><?php echo esc_html($report_label); ?> Ti</th>
          <?php endif; ?>
          <th class="px-3 py-3 font-semibold text-center"<?php if ($report_type === 'sel'): ?> data-csv-split="1"<?php endif; ?>><?php echo esc_html($report_label); ?> T1</th>
          <?php if ($report_type !== 'sel'): ?>
          <th class="px-3 py-3 font-semibold text-center">Δ (T1−T0)</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <?php foreach ($tutor_agg as $tid => $ta): ?>
          <tr class="transition-colors hover:bg-slate-50"
              data-tid="<?php echo (int)$tid; ?>"
              data-name="<?php echo esc_attr($ta['name']); ?>"
              data-profs="<?php echo (int)$ta['profs']; ?>"
              data-students="<?php echo (int)$ta['students']; ?>"
              data-t0="<?php echo $ta['t0'] !== null ? round($ta['t0'], 4) : ''; ?>"
              data-ti="<?php echo $ta['ti'] !== null ? round($ta['ti'], 4) : ''; ?>"
              data-t1="<?php echo $ta['t1'] !== null ? round($ta['t1'], 4) : ''; ?>">
            <td class="px-3 py-3 font-medium text-slate-900">
              <?php if ($tid > 0): ?>
                <a href="<?php echo esc_url(home_url('/panou/tutor/' . $tid)); ?>" class="hover:text-indigo-700"><?php echo esc_html($ta['name']); ?></a>
              <?php else: ?>
                <span class="text-slate-500"><?php echo esc_html($ta['name']); ?></span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-3 text-center text-slate-800"><?php echo (int)$ta['profs']; ?></td>
            <td class="px-3 py-3 text-center text-slate-800"><?php echo (int)$ta['students']; ?></td>
            <td class="px-3 py-3 text-center">
              <?php if ($ta['t0'] !== null): ?>
                <div class="inline-flex items-center gap-1.5">
                  <span class="rap-cell-score inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_bg_score_class($ta['t0'])); ?>"><?php echo number_format($ta['t0'], 2); ?></span>
                  <?php if ($report_type === 'sel'): ?><?php echo rap_pct_pill($ta['t0_pct'] ?? null); ?><?php endif; ?>
                </div>
              <?php else: ?><span class="rap-cell-score text-slate-400">—</span><?php endif; ?>
            </td>
            <?php if ($has_ti): ?>
            <td class="px-3 py-3 text-center">
              <?php if ($ta['ti'] !== null): ?>
                <div class="inline-flex items-center gap-1.5">
                  <span class="rap-cell-score inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_bg_score_class($ta['ti'])); ?>"><?php echo number_format($ta['ti'], 2); ?></span>
                  <?php if ($report_type === 'sel'): ?><?php echo rap_pct_pill($ta['ti_pct'] ?? null); ?><?php endif; ?>
                </div>
              <?php else: ?><span class="rap-cell-score text-slate-400">—</span><?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="px-3 py-3 text-center">
              <?php if ($ta['t1'] !== null): ?>
                <div class="inline-flex items-center gap-1.5">
                  <span class="rap-cell-score inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_bg_score_class($ta['t1'])); ?>"><?php echo number_format($ta['t1'], 2); ?></span>
                  <?php if ($report_type === 'sel'): ?><?php echo rap_pct_pill($ta['t1_pct'] ?? null); ?><?php endif; ?>
                </div>
              <?php else: ?><span class="rap-cell-score text-slate-400">—</span><?php endif; ?>
            </td>
            <?php if ($report_type !== 'sel'): ?>
            <td class="px-3 py-3 text-center">
              <?php if ($ta['delta'] !== null): ?>
                <span class="inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_delta_badge_class($ta['delta'])); ?>">
                  <?php echo ($ta['delta'] > 0 ? '+' : '') . number_format($ta['delta'], 2); ?>
                </span>
              <?php else: ?><span class="text-slate-400">—</span><?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

<script>
// Chart helper
function buildBarChart(canvasId, labels, datasets, yMax) {
  const ctx = document.getElementById(canvasId);
  if (!ctx) return;
  new Chart(ctx, {
    type: 'bar',
    data: { labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'top' },
        tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + (c.parsed.y !== null ? c.parsed.y.toFixed(2) : '—') } }
      },
      scales: {
        x: { ticks: { maxRotation: 45, minRotation: 30, font: { size: 11 } } },
        y: { beginAtZero: true, max: yMax, ticks: { stepSize: yMax <= 5 ? 0.5 : 10 } }
      }
    }
  });
}

<?php
  $lbl = $report_label;
  $yMax = ($report_type === 'lit') ? 100 : 3;
?>
const hasTi = <?php echo $has_ti ? 'true' : 'false'; ?>;
const yMax = <?php echo (int)$yMax; ?>;

// Prof chart data (full set); we re-build datasets dynamically based on selectedProfs.
const profChartRows = <?php echo wp_json_encode($prof_chart_data, JSON_UNESCAPED_UNICODE); ?>;
const chartProfList = <?php echo wp_json_encode($chart_prof_list, JSON_UNESCAPED_UNICODE); ?>;
let selectedProfs = Object.keys(chartProfList); // default: all selected

let profChartInstance = null;
function renderProfChart(){
  const rows = profChartRows.filter(r => selectedProfs.includes(String(r.pid)));
  const labels = rows.map(r => r.name);
  const ds = [
    { label: '<?php echo $lbl; ?> T0', data: rows.map(r => r.t0), backgroundColor: '#34aada', borderRadius: 3 },
  ];
  if (hasTi) ds.push({ label: '<?php echo $lbl; ?> Ti', data: rows.map(r => r.ti), backgroundColor: '#fd431c', borderRadius: 3 });
  ds.push({ label: '<?php echo $lbl; ?> T1', data: rows.map(r => r.t1), backgroundColor: '#057a55', borderRadius: 3 });

  if (profChartInstance) profChartInstance.destroy();
  const ctx = document.getElementById('sel-chart');
  if (!ctx) return;
  profChartInstance = new Chart(ctx, {
    type: 'bar',
    data: { labels, datasets: ds },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'top' },
        tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + (c.parsed.y !== null ? c.parsed.y.toFixed(2) : '—') } }
      },
      scales: {
        x: { ticks: { maxRotation: 45, minRotation: 30, font: { size: 11 } } },
        y: { beginAtZero: true, max: yMax, ticks: { stepSize: yMax <= 5 ? 0.5 : 10 } }
      }
    }
  });
}

// Lazy: don't render per-prof chart until user clicks "Arată grafic" (canvas starts hidden)
let profChartBuilt = false;
function buildProfChartIfNeeded(){
  if (profChartBuilt) return;
  renderProfChart();
  profChartBuilt = true;
}

// Tutor-filtered charts — per professor, 3 stages
const allProfs = <?php echo wp_json_encode($prof_chart_data, JSON_UNESCAPED_UNICODE); ?>;
const chartTutorList = <?php echo wp_json_encode($chart_tutor_list, JSON_UNESCAPED_UNICODE); ?>;
let selectedTutors = Object.keys(chartTutorList); // all selected by default

const chartInstances = {};
const datalabelsPlugin = {
  id: 'datalabels',
  afterDatasetsDraw(chart) {
    const { ctx: c } = chart;
    chart.data.datasets.forEach((ds, i) => {
      chart.getDatasetMeta(i).data.forEach((bar, idx) => {
        const val = ds.data[idx];
        if (val === null || val === undefined) return;
        c.save(); c.fillStyle = '#334155'; c.font = 'bold 11px sans-serif'; c.textAlign = 'center';
        c.fillText(val.toFixed(2), bar.x, bar.y - 6);
        c.restore();
      });
    });
  }
};

function renderTutorCharts() {
  const filtered = allProfs.filter(p => selectedTutors.includes(p.tutor_id));
  const labels = filtered.map(p => p.name);
  const stages = [
    { id: 'tutor-chart-t0', key: 't0', label: '<?php echo $lbl; ?> T0', color: '#34aada' },
    <?php if ($has_ti): ?>
    { id: 'tutor-chart-ti', key: 'ti', label: '<?php echo $lbl; ?> Ti', color: '#fd431c' },
    <?php endif; ?>
    { id: 'tutor-chart-t1', key: 't1', label: '<?php echo $lbl; ?> T1', color: '#057a55' },
  ];
  stages.forEach(s => {
    if (chartInstances[s.id]) chartInstances[s.id].destroy();
    const ctx = document.getElementById(s.id);
    if (!ctx) return;
    chartInstances[s.id] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{ label: s.label, data: filtered.map(p => p[s.key]), backgroundColor: s.color, borderRadius: 4 }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => s.label + ': ' + (c.parsed.y !== null ? c.parsed.y.toFixed(2) : '—') } } },
        scales: {
          x: { ticks: { maxRotation: 45, minRotation: 30, font: { size: 11 } } },
          y: { beginAtZero: true, max: yMax, ticks: { stepSize: yMax <= 5 ? 0.5 : 10 } }
        }
      },
      plugins: [datalabelsPlugin]
    });
  });
}

// Lazy: tutor charts are only built once the wrapper becomes visible.
let tutorChartsBuilt = false;
function buildTutorChartsIfNeeded(){
  if (tutorChartsBuilt) return;
  renderTutorCharts();
  tutorChartsBuilt = true;
}

// Helper: filter rows of a tbody by dataset attr against a Set of allowed values.
// If selected is the full list (or empty), show all rows.
function filterTableRows(tableId, attr, selectedIds, allIds){
  const table = document.getElementById(tableId);
  if (!table) return;
  const rows = table.querySelectorAll('tbody tr');
  const showAll = !selectedIds.length || selectedIds.length === allIds.length;
  const allow = new Set(selectedIds.map(String));
  rows.forEach(tr => {
    const v = String(tr.dataset[attr] || '');
    tr.classList.toggle('hidden', !(showAll || allow.has(v)));
  });
}

// Inline tutor filter — drives tutor chart + tutor table
(function(){
  const wrap = document.getElementById('chart-tutor-wrap');
  const trigger = document.getElementById('chart-tutor-trigger');
  const dropdown = document.getElementById('chart-tutor-dropdown');
  const search = document.getElementById('chart-tutor-search');
  if (!wrap || !trigger || !dropdown) return;

  const checkSvg = '<svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
  const arrow = '<svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';

  function render() {
    // Update checkbox icons
    dropdown.querySelectorAll('.ct-opt').forEach(opt => {
      const v = opt.dataset.val, on = selectedTutors.includes(v);
      const box = opt.querySelector('.ct-check');
      box.className = 'inline-flex items-center justify-center w-4 h-4 rounded border ct-check ' + (on ? 'bg-sky-600 border-sky-600' : 'bg-white border-slate-300');
      box.innerHTML = on ? checkSvg : '';
    });
    // Trigger label
    const allKeys = Object.keys(chartTutorList);
    if (selectedTutors.length === allKeys.length || selectedTutors.length === 0) {
      trigger.innerHTML = '<span class="ms-placeholder text-slate-400">Toți tutorii</span>' + arrow;
    } else {
      const tags = selectedTutors.map(v => {
        const lab = chartTutorList[v] || v;
        return `<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 ct-tag" data-val="${v}">
          ${lab}<button type="button" class="ct-remove hover:text-red-600">&times;</button></span>`;
      }).join('');
      trigger.innerHTML = tags + arrow;
    }
    // Re-render chart only if the user has opened it at least once.
    if (tutorChartsBuilt) renderTutorCharts();
    // Filter the per-tutor table rows.
    filterTableRows('rap-tutor-table', 'tid', selectedTutors, allKeys);
  }

  function applySearch(q){
    const qn = (q || '').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu, '').trim();
    dropdown.querySelectorAll('.ct-opt').forEach(opt => {
      const hay = opt.dataset.name || '';
      opt.style.display = (!qn || hay.includes(qn)) ? '' : 'none';
    });
  }

  trigger.addEventListener('click', (e) => {
    if (e.target.closest('.ct-remove')) {
      const tag = e.target.closest('.ct-tag');
      if (tag?.dataset.val) {
        selectedTutors = selectedTutors.filter(v => v !== tag.dataset.val);
        if (selectedTutors.length === 0) selectedTutors = Object.keys(chartTutorList);
        render();
      }
      return;
    }
    const wasHidden = dropdown.classList.contains('hidden');
    dropdown.classList.toggle('hidden');
    if (wasHidden && search) { search.value = ''; applySearch(''); search.focus(); }
  });
  dropdown.addEventListener('click', (e) => {
    const opt = e.target.closest('.ct-opt');
    if (!opt) return;
    const val = opt.dataset.val;
    if (selectedTutors.includes(val)) {
      selectedTutors = selectedTutors.filter(v => v !== val);
      if (selectedTutors.length === 0) selectedTutors = Object.keys(chartTutorList);
    } else {
      selectedTutors.push(val);
    }
    render();
  });
  search?.addEventListener('input', () => applySearch(search.value));
  search?.addEventListener('click', e => e.stopPropagation());
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#chart-tutor-wrap')) dropdown.classList.add('hidden');
  });

  // Initial table sync (no-op when all tutors selected, but safe)
  render();
})();

// Inline prof filter — drives per-prof chart + per-prof table
(function(){
  const wrap = document.getElementById('chart-prof-wrap');
  const trigger = document.getElementById('chart-prof-trigger');
  const dropdown = document.getElementById('chart-prof-dropdown');
  const search = document.getElementById('chart-prof-search');
  if (!wrap || !trigger || !dropdown) return;

  const checkSvg = '<svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
  const arrow = '<svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';

  function render(){
    dropdown.querySelectorAll('.cp-opt').forEach(opt => {
      const v = opt.dataset.val, on = selectedProfs.includes(v);
      const box = opt.querySelector('.cp-check');
      box.className = 'inline-flex items-center justify-center w-4 h-4 rounded border cp-check ' + (on ? 'bg-sky-600 border-sky-600' : 'bg-white border-slate-300');
      box.innerHTML = on ? checkSvg : '';
    });
    const allKeys = Object.keys(chartProfList);
    if (selectedProfs.length === allKeys.length || selectedProfs.length === 0) {
      trigger.innerHTML = '<span class="ms-placeholder text-slate-400">Toți profesorii</span>' + arrow;
    } else {
      const tags = selectedProfs.map(v => {
        const lab = chartProfList[v] || v;
        return `<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 cp-tag" data-val="${v}">
          ${lab}<button type="button" class="cp-remove hover:text-red-600">&times;</button></span>`;
      }).join('');
      trigger.innerHTML = tags + arrow;
    }
    if (profChartBuilt) renderProfChart();
    filterTableRows('rap-table', 'pid', selectedProfs, allKeys);
  }

  function applySearch(q){
    const qn = (q || '').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu, '').trim();
    dropdown.querySelectorAll('.cp-opt').forEach(opt => {
      const hay = opt.dataset.name || '';
      opt.style.display = (!qn || hay.includes(qn)) ? '' : 'none';
    });
  }

  trigger.addEventListener('click', (e) => {
    if (e.target.closest('.cp-remove')) {
      const tag = e.target.closest('.cp-tag');
      if (tag?.dataset.val) {
        selectedProfs = selectedProfs.filter(v => v !== tag.dataset.val);
        if (selectedProfs.length === 0) selectedProfs = Object.keys(chartProfList);
        render();
      }
      return;
    }
    const wasHidden = dropdown.classList.contains('hidden');
    dropdown.classList.toggle('hidden');
    if (wasHidden && search) { search.value = ''; applySearch(''); search.focus(); }
  });
  dropdown.addEventListener('click', (e) => {
    const opt = e.target.closest('.cp-opt');
    if (!opt) return;
    const val = opt.dataset.val;
    if (selectedProfs.includes(val)) {
      selectedProfs = selectedProfs.filter(v => v !== val);
      if (selectedProfs.length === 0) selectedProfs = Object.keys(chartProfList);
    } else {
      selectedProfs.push(val);
    }
    render();
  });
  search?.addEventListener('input', () => applySearch(search.value));
  search?.addEventListener('click', e => e.stopPropagation());
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#chart-prof-wrap')) dropdown.classList.add('hidden');
  });

  render();
})();

// Table sorting
(function(){
  const table = document.getElementById('rap-table');
  if (!table) return;
  const tbody = table.querySelector('tbody');
  const rows = () => Array.from(tbody.querySelectorAll('tr'));
  let sortCol = '', sortAsc = true;

  table.querySelectorAll('th[data-sort]').forEach(th => {
    th.addEventListener('click', () => {
      const col = th.dataset.sort;
      if (sortCol === col) { sortAsc = !sortAsc; } else { sortCol = col; sortAsc = true; }

      const sorted = rows().sort((a, b) => {
        let va, vb;
        if (col === 'name') {
          va = (a.dataset.name || '').toLowerCase();
          vb = (b.dataset.name || '').toLowerCase();
          return sortAsc ? va.localeCompare(vb) : vb.localeCompare(va);
        }
        va = parseFloat(a.dataset[col]) || 0;
        vb = parseFloat(b.dataset[col]) || 0;
        return sortAsc ? va - vb : vb - va;
      });
      sorted.forEach(r => tbody.appendChild(r));
    });
  });
})();

// Chart toggles (per-profesor + per-tutor) + CSV export for the two tables
(function(){
  function toggleChart(btn){
    const target = document.getElementById(btn.dataset.target);
    if (!target) return;
    const nowHidden = target.classList.toggle('hidden');
    const lbl = btn.querySelector('.rap-toggle-label');
    if (lbl) lbl.textContent = nowHidden ? 'Arată grafic' : 'Ascunde grafic';
    btn.setAttribute('aria-expanded', nowHidden ? 'false' : 'true');
    if (!nowHidden) {
      if (btn.id === 'rap-toggle-prof-chart')  buildProfChartIfNeeded();
      if (btn.id === 'rap-toggle-tutor-chart') buildTutorChartsIfNeeded();
    }
  }
  document.getElementById('rap-toggle-prof-chart')?.addEventListener('click', e => toggleChart(e.currentTarget));
  document.getElementById('rap-toggle-tutor-chart')?.addEventListener('click', e => toggleChart(e.currentTarget));

  // CSV export — serializes a table's thead/tbody into CSV (what the user sees).
  function cellText(td){
    return (td.textContent || '').replace(/\s+/g, ' ').trim();
  }
  function csvEscape(v){
    const s = String(v == null ? '' : v);
    return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }
  function tableToCsv(table){
    if (!table) return '';
    const lines = [];
    const heads = Array.from(table.querySelectorAll('thead tr')).pop();
    if (!heads) return '';

    // Determine per-column whether it should be split into <score> and <score %> CSV columns.
    const headerCells = Array.from(heads.querySelectorAll('th'));
    const splitFlags  = headerCells.map(th => th.hasAttribute('data-csv-split'));

    // Header row
    const headerOut = [];
    headerCells.forEach((th, i) => {
      const lab = cellText(th);
      if (splitFlags[i]) {
        headerOut.push(csvEscape(lab));
        headerOut.push(csvEscape(lab + ' %'));
      } else {
        headerOut.push(csvEscape(lab));
      }
    });
    lines.push(headerOut.join(','));

    // Body rows
    table.querySelectorAll('tbody tr').forEach(tr => {
      if (tr.classList.contains('hidden')) return; // respect current filter
      const tds = Array.from(tr.querySelectorAll('td'));
      if (!tds.length) return;
      const row = [];
      tds.forEach((td, i) => {
        if (splitFlags[i]) {
          const scoreEl = td.querySelector('.rap-cell-score');
          const pctEl   = td.querySelector('.rap-cell-pct');
          row.push(csvEscape(scoreEl ? cellText(scoreEl) : cellText(td)));
          row.push(csvEscape(pctEl   ? cellText(pctEl)   : ''));
        } else {
          row.push(csvEscape(cellText(td)));
        }
      });
      lines.push(row.join(','));
    });
    return lines.join('\r\n');
  }
  function downloadCsv(filename, csv){
    const blob = new Blob(["﻿" + csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename + '_' + new Date().toISOString().slice(0,19).replace(/[:T]/g,'-') + '.csv';
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 0);
  }
  function wireExport(btnId){
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn.addEventListener('click', () => {
      const table = document.getElementById(btn.dataset.table);
      if (!table) return;
      downloadCsv(btn.dataset.filename || 'export', tableToCsv(table));
    });
  }
  wireExport('rap-export-prof');
  wireExport('rap-export-tutor');
})();
</script>
<?php endif; ?>

<!-- MODAL: Exportă rezultate elevi (SEL) -->
<div id="rap-elevi-modal" class="fixed inset-0 z-[100] hidden">
  <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>
  <div class="relative max-w-xl mx-auto my-10">
    <div class="mx-4 overflow-visible bg-white border shadow-xl rounded-2xl border-slate-200">
      <div class="flex items-center justify-between px-5 py-4 border-b bg-slate-50 border-slate-200">
        <h3 class="text-base font-semibold text-slate-900">Exportă rezultate elevi (SEL)</h3>
        <button type="button" id="rap-elevi-close" class="p-2 text-slate-500 hover:text-slate-700">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M6 6l12 12M6 18 18 6"/></svg>
        </button>
      </div>

      <form id="rap-elevi-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="px-5 py-4">
        <input type="hidden" name="action" value="es_export_sel_elevi">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('es_export_sel_elevi')); ?>">
        <div id="rap-elevi-prof-inputs"></div>

        <div id="rap-elevi-error" class="hidden mb-3 px-3 py-2 text-sm text-rose-800 bg-rose-50 border border-rose-200 rounded-xl" role="alert"></div>

        <div class="grid grid-cols-1 gap-4">
          <!-- An școlar (required) -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">An școlar <span class="text-rose-600">*</span></label>
            <select name="an_scolar" id="rap-elevi-year" required class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
              <?php
                $modal_years = $years_available;
                if (!in_array($default_year, $modal_years, true)) $modal_years[] = $default_year;
                sort($modal_years, SORT_NATURAL);
                $modal_years = array_reverse($modal_years);
                foreach ($modal_years as $yr):
              ?>
                <option value="<?php echo esc_attr($yr); ?>" <?php selected($year_filter === $yr); ?>><?php echo esc_html($yr); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Profesori (multiselect) -->
          <div class="relative" id="rap-elevi-prof-wrap">
            <label class="block mb-1 text-xs font-medium text-slate-600">Profesori <span class="text-slate-400">(implicit toți)</span></label>
            <div id="rap-elevi-prof-trigger" class="flex items-center gap-1 min-h-[38px] w-full px-3 py-1.5 text-sm bg-white border rounded-xl border-slate-300 cursor-pointer hover:border-slate-400 flex-wrap">
              <span class="ms-placeholder text-slate-400">Toți profesorii</span>
              <svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
            </div>
            <div id="rap-elevi-prof-dropdown" class="hidden absolute z-[110] left-0 right-0 mt-1 bg-white border shadow-lg rounded-xl border-slate-200 py-1 max-h-72 overflow-y-auto">
              <div class="px-2 pb-2 pt-1 sticky top-0 bg-white border-b border-slate-100">
                <input type="text" id="rap-elevi-prof-search" placeholder="Caută profesor..." class="w-full px-2 py-1 text-sm bg-white border border-slate-200 rounded-lg focus:outline-none focus:ring-1 focus:ring-sky-500">
              </div>
              <div id="rap-elevi-prof-list" class="text-sm text-slate-500 px-3 py-2">Se încarcă profesorii...</div>
            </div>
          </div>

          <!-- Evaluare (stages) -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Evaluare</label>
            <div class="flex flex-wrap gap-3">
              <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="stages[]" value="sel-t0" checked class="w-4 h-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                SEL T0
              </label>
              <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="stages[]" value="sel-ti" checked class="w-4 h-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                SEL Ti
              </label>
              <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="stages[]" value="sel-t1" checked class="w-4 h-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                SEL T1
              </label>
            </div>
          </div>
        </div>

        <div class="flex items-center justify-end gap-2 pt-5 mt-5 border-t border-slate-200">
          <button type="button" id="rap-elevi-cancel" class="px-3 py-2 text-sm bg-white border rounded-xl hover:bg-slate-50 border-slate-300">Anulează</button>
          <button type="submit" id="rap-elevi-submit" class="inline-flex items-center gap-2 px-3 py-2 text-sm text-white bg-sky-600 rounded-xl hover:bg-sky-700">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
            Descarcă CSV
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const modal    = document.getElementById('rap-elevi-modal');
  const openBtn  = document.getElementById('rap-open-export-elevi');
  const closeBtn = document.getElementById('rap-elevi-close');
  const cancel   = document.getElementById('rap-elevi-cancel');
  const form     = document.getElementById('rap-elevi-form');
  const yearSel  = document.getElementById('rap-elevi-year');
  const errEl    = document.getElementById('rap-elevi-error');
  const submit   = document.getElementById('rap-elevi-submit');
  if (!modal || !openBtn) return;

  const profWrap     = document.getElementById('rap-elevi-prof-wrap');
  const profTrigger  = document.getElementById('rap-elevi-prof-trigger');
  const profDropdown = document.getElementById('rap-elevi-prof-dropdown');
  const profList     = document.getElementById('rap-elevi-prof-list');
  const profSearch   = document.getElementById('rap-elevi-prof-search');
  const profInputs   = document.getElementById('rap-elevi-prof-inputs');

  const AJAX_URL = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
  const NONCE    = <?php echo wp_json_encode(wp_create_nonce('edu_nonce')); ?>;

  const ARROW = '<svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';
  const CHECK = '<svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';

  let profOptions = {};       // pid => name
  let selectedProfs = [];     // array of pid strings

  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
  function norm(s){ return (s||'').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,'').trim(); }
  function showErr(msg){ if (!errEl) return; errEl.textContent = msg || ''; errEl.classList.toggle('hidden', !msg); }
  function clearErr(){ showErr(''); }

  function syncHiddenInputs(){
    profInputs.innerHTML = selectedProfs.map(v => `<input type="hidden" name="prof_ids[]" value="${escapeHtml(v)}">`).join('');
  }
  function renderTrigger(){
    const allKeys = Object.keys(profOptions);
    if (!allKeys.length) {
      profTrigger.innerHTML = '<span class="ms-placeholder text-slate-400">Niciun profesor pentru acest an</span>' + ARROW;
      return;
    }
    if (!selectedProfs.length || selectedProfs.length === allKeys.length) {
      profTrigger.innerHTML = '<span class="ms-placeholder text-slate-400">Toți profesorii</span>' + ARROW;
      return;
    }
    const tags = selectedProfs.map(v => {
      const lab = profOptions[v] || ('#' + v);
      return `<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 re-tag" data-val="${v}">
        ${escapeHtml(lab)}<button type="button" class="re-remove hover:text-red-600">&times;</button></span>`;
    }).join('');
    profTrigger.innerHTML = tags + ARROW;
  }
  function renderOptions(filter){
    const qn = norm(filter);
    const entries = Object.entries(profOptions)
      .filter(([pid, name]) => !qn || norm(name).includes(qn))
      .sort((a,b) => a[1].localeCompare(b[1], 'ro'));
    if (!entries.length) {
      profList.innerHTML = '<div class="px-3 py-2 text-sm text-slate-500">Nu există profesori pentru anul școlar selectat.</div>';
      return;
    }
    profList.innerHTML = entries.map(([pid, name]) => {
      const on = selectedProfs.includes(pid);
      const boxCls = 'inline-flex items-center justify-center w-4 h-4 rounded border re-check ' + (on ? 'bg-sky-600 border-sky-600' : 'bg-white border-slate-300');
      return `<div class="re-opt flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50" data-val="${pid}">
        <span class="${boxCls}">${on ? CHECK : ''}</span><span>${escapeHtml(name)}</span></div>`;
    }).join('');
  }

  async function loadProfesori(year){
    profList.innerHTML = '<div class="px-3 py-2 text-sm text-slate-500">Se încarcă profesorii...</div>';
    profOptions = {};
    selectedProfs = [];
    syncHiddenInputs();
    renderTrigger();
    try{
      const fd = new FormData();
      fd.append('action', 'edu_rap_profesori_by_year');
      fd.append('nonce', NONCE);
      fd.append('year', year);
      const r = await fetch(AJAX_URL, { method:'POST', body: fd, credentials:'same-origin' });
      const data = await r.json();
      if (!data || !data.success) throw new Error(data && data.data && data.data.message ? data.data.message : 'Eroare la încărcare.');
      (data.data || []).forEach(p => { profOptions[String(p.pid)] = p.name; });
      renderTrigger();
      renderOptions(profSearch.value);
    } catch(e){
      profList.innerHTML = '<div class="px-3 py-2 text-sm text-rose-600">Eroare la încărcarea profesorilor.</div>';
    }
  }

  function openModal(){
    clearErr();
    modal.classList.remove('hidden');
    loadProfesori(yearSel.value);
  }
  function closeModal(){
    modal.classList.add('hidden');
    profDropdown.classList.add('hidden');
  }

  openBtn.addEventListener('click', openModal);
  closeBtn?.addEventListener('click', closeModal);
  cancel?.addEventListener('click', closeModal);
  modal.querySelector('.absolute.inset-0')?.addEventListener('click', (e) => { if (e.target === e.currentTarget) closeModal(); });

  yearSel.addEventListener('change', () => loadProfesori(yearSel.value));

  profTrigger.addEventListener('click', (e) => {
    if (e.target.closest('.re-remove')) {
      const tag = e.target.closest('.re-tag');
      if (tag?.dataset.val) {
        selectedProfs = selectedProfs.filter(v => v !== tag.dataset.val);
        syncHiddenInputs(); renderTrigger(); renderOptions(profSearch.value);
      }
      return;
    }
    const wasHidden = profDropdown.classList.contains('hidden');
    profDropdown.classList.toggle('hidden');
    if (wasHidden) { profSearch.value = ''; renderOptions(''); profSearch.focus(); }
  });
  profDropdown.addEventListener('click', (e) => {
    const opt = e.target.closest('.re-opt');
    if (!opt) return;
    const val = opt.dataset.val;
    if (selectedProfs.includes(val)) selectedProfs = selectedProfs.filter(v => v !== val);
    else selectedProfs.push(val);
    syncHiddenInputs(); renderTrigger(); renderOptions(profSearch.value);
  });
  profSearch.addEventListener('input', () => renderOptions(profSearch.value));
  profSearch.addEventListener('click', e => e.stopPropagation());
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#rap-elevi-prof-wrap')) profDropdown.classList.add('hidden');
  });

  form.addEventListener('submit', (e) => {
    clearErr();
    if (!yearSel.value) { e.preventDefault(); showErr('Selectează anul școlar.'); return; }
    const stagesChecked = form.querySelectorAll('input[name="stages[]"]:checked').length;
    if (!stagesChecked) { e.preventDefault(); showErr('Selectează cel puțin o evaluare (SEL T0 / Ti / T1).'); return; }
    // Submit normal → admin-post returnează CSV, modalul rămâne deschis (iframe-less download via Content-Disposition).
    // Îl închidem după o mică întârziere ca să nu ascundem eventuale erori de browser.
    setTimeout(() => closeModal(), 400);
  });
})();
</script>

<!-- Filter dropdowns JS (always loaded, independent of results) -->
<script>
// Generic multiselect dropdown factory
function initMultiselect(prefix, inputName, options, initialSelected, placeholder) {
  const wrap     = document.getElementById(prefix + '-wrap');
  const trigger  = document.getElementById(prefix + '-trigger');
  const dropdown = document.getElementById(prefix + '-dropdown');
  const inputs   = document.getElementById(prefix + '-inputs');
  if (!wrap || !trigger || !dropdown || !inputs) return;

  let selected = [...initialSelected];
  const checkSvg = '<svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
  const arrow = '<svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';

  function render(){
    inputs.innerHTML = selected.map(v => `<input type="hidden" name="${inputName}" value="${v}">`).join('');
    const tags = selected.map(v => {
      const lab = options[v] || v;
      return `<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 ms-tag" data-val="${v}">
        ${lab}<button type="button" class="ms-remove hover:text-red-600">&times;</button></span>`;
    }).join('');
    trigger.innerHTML = selected.length ? tags + arrow : `<span class="ms-placeholder text-slate-400">${placeholder}</span>` + arrow;
    dropdown.querySelectorAll('.ms-opt').forEach(opt => {
      const v = opt.dataset.val;
      const isOn = selected.includes(v);
      const box = opt.querySelector('.ms-check');
      box.className = 'inline-flex items-center justify-center w-4 h-4 rounded border ms-check ' + (isOn ? 'bg-sky-600 border-sky-600' : 'bg-white border-slate-300');
      box.innerHTML = isOn ? checkSvg : '';
    });
  }
  trigger.addEventListener('click', (e) => {
    if (e.target.closest('.ms-remove')) {
      const tag = e.target.closest('.ms-tag');
      if (tag?.dataset.val) { selected = selected.filter(v => v !== tag.dataset.val); render(); }
      return;
    }
    dropdown.classList.toggle('hidden');
  });
  dropdown.addEventListener('click', (e) => {
    const opt = e.target.closest('.ms-opt');
    if (!opt) return;
    const val = opt.dataset.val;
    if (selected.includes(val)) selected = selected.filter(v => v !== val);
    else selected.push(val);
    render();
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#' + prefix + '-wrap')) dropdown.classList.add('hidden');
  });
}

// Tutor multiselect
initMultiselect('rap-tutor', 'tutor[]',
  <?php
    $tutor_opts_str = [];
    foreach ($tutor_options as $tid => $tname) $tutor_opts_str[(string)$tid] = $tname;
    echo wp_json_encode($tutor_opts_str, JSON_UNESCAPED_UNICODE);
  ?>,
  <?php echo wp_json_encode(array_map('strval', $tutor_filter)); ?>,
  '— Toți tutorii —'
);

// Nivel predare
initMultiselect('rap-nivel', 'nivel[]',
  <?php echo wp_json_encode($nivel_options, JSON_UNESCAPED_UNICODE); ?>,
  <?php echo wp_json_encode(array_values($nivel_filter)); ?>,
  '— Oricare —'
);

// Județ
initMultiselect('rap-judet', 'judet[]',
  <?php
    $judet_map = [];
    foreach ($all_counties_list as $cn) $judet_map[$cn] = $cn;
    echo wp_json_encode($judet_map, JSON_UNESCAPED_UNICODE);
  ?>,
  <?php echo wp_json_encode(array_values($judet_filter), JSON_UNESCAPED_UNICODE); ?>,
  '— Toate —'
);

// Județ search filter
(function(){
  const searchInput = document.getElementById('rap-judet-search');
  if (!searchInput) return;
  searchInput.addEventListener('input', function(){
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('#rap-judet-dropdown .ms-opt').forEach(opt => {
      const text = (opt.dataset.val || '').toLowerCase();
      opt.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
  });
  searchInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
})();
</script>
