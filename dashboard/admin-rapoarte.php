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

/* ==================== FILTRE ==================== */

// An școlar: calculăm anul curent ca default
$now_m = (int) date('n');
$now_y = (int) date('Y');
$default_year = ($now_m >= 8) ? "$now_y-" . ($now_y + 1) : ($now_y - 1) . "-$now_y";

$year_filter   = isset($_GET['an_scolar']) ? sanitize_text_field($_GET['an_scolar']) : $default_year;
$tutor_filter  = isset($_GET['tutor']) ? array_filter(array_map('intval', (array)$_GET['tutor'])) : [];
$nivel_filter  = isset($_GET['nivel']) ? array_filter(array_map('sanitize_text_field', (array)$_GET['nivel'])) : [];
$judet_filter  = isset($_GET['judet']) ? array_filter(array_map('sanitize_text_field', (array)$_GET['judet'])) : [];
$report_type   = isset($_GET['tip']) ? sanitize_text_field($_GET['tip']) : 'sel';
if (!in_array($report_type, ['sel', 'lit'], true)) $report_type = 'sel';

// Anii școlari disponibili (din generații)
$years_available = $wpdb->get_col("SELECT DISTINCT year FROM {$tbl_generations} WHERE year != '' ORDER BY year DESC");

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

// 1) Generații din anul selectat
$gen_where = $wpdb->prepare("WHERE year = %s", $year_filter);
$generations = $wpdb->get_results("SELECT * FROM {$tbl_generations} {$gen_where} ORDER BY professor_id, id");

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
    $tutor_agg[$tid] = ['name' => $tname, 'profs' => 0, 'students' => 0, 't0_vals' => [], 'ti_vals' => [], 't1_vals' => []];
  }
  $tutor_agg[$tid]['profs']++;
  $tutor_agg[$tid]['students'] += $d['students'];
  if ($d['t0'] !== null) $tutor_agg[$tid]['t0_vals'][] = $d['t0'];
  if ($d['ti'] !== null) $tutor_agg[$tid]['ti_vals'][] = $d['ti'];
  if ($d['t1'] !== null) $tutor_agg[$tid]['t1_vals'][] = $d['t1'];
}
foreach ($tutor_agg as &$ta) {
  $ta['t0'] = count($ta['t0_vals']) ? array_sum($ta['t0_vals']) / count($ta['t0_vals']) : null;
  $ta['ti'] = count($ta['ti_vals']) ? array_sum($ta['ti_vals']) / count($ta['ti_vals']) : null;
  $ta['t1'] = count($ta['t1_vals']) ? array_sum($ta['t1_vals']) / count($ta['t1_vals']) : null;
  $ta['delta'] = ($ta['t1'] !== null && $ta['t0'] !== null) ? ($ta['t1'] - $ta['t0']) : null;
}
unset($ta);
uasort($tutor_agg, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// Prepare chart data — per profesor
$chart_labels = array_map(fn($d) => $d['name'], $report_data);
$chart_t0 = array_map(fn($d) => $d['t0'] !== null ? round($d['t0'], 2) : null, $report_data);
$chart_ti = array_map(fn($d) => $d['ti'] !== null ? round($d['ti'], 2) : null, $report_data);
$chart_t1 = array_map(fn($d) => $d['t1'] !== null ? round($d['t1'], 2) : null, $report_data);

// Per-professor data for JS (tutor-filtered charts)
$prof_chart_data = array_values(array_map(fn($d) => [
  'name'     => $d['name'],
  'tutor_id' => (string)($d['tutor_id'] ?? 0),
  't0'       => $d['t0'] !== null ? round($d['t0'], 2) : null,
  'ti'       => $d['ti'] !== null ? round($d['ti'], 2) : null,
  't1'       => $d['t1'] !== null ? round($d['t1'], 2) : null,
], $report_data));

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
  <h2 class="mb-4 text-lg font-semibold text-slate-800">Scoruri <?php echo esc_html($report_label); ?> per profesor</h2>
  <div style="max-height:400px;">
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
          <th class="px-3 py-3 font-semibold text-center cursor-pointer select-none hover:bg-sky-900" data-sort="t0">
            <span class="inline-flex items-center gap-1"><?php echo esc_html($report_label); ?> T0 <svg class="w-3 h-3 opacity-40" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/></svg></span>
          </th>
          <?php if ($has_ti): ?>
          <th class="px-3 py-3 font-semibold text-center cursor-pointer select-none hover:bg-sky-900" data-sort="ti">
            <span class="inline-flex items-center gap-1"><?php echo esc_html($report_label); ?> Ti <svg class="w-3 h-3 opacity-40" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/></svg></span>
          </th>
          <?php endif; ?>
          <th class="px-3 py-3 font-semibold text-center cursor-pointer select-none hover:bg-sky-900" data-sort="t1">
            <span class="inline-flex items-center gap-1"><?php echo esc_html($report_label); ?> T1 <svg class="w-3 h-3 opacity-40" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/></svg></span>
          </th>
          <th class="px-3 py-3 font-semibold text-center">Δ (T1−T0)</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <?php foreach ($report_data as $d): ?>
          <tr class="transition-colors hover:bg-slate-50"
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
                <span class="inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_bg_score_class($d['t0'])); ?>"><?php echo number_format($d['t0'], 2); ?></span>
              <?php else: ?>
                <span class="text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <?php if ($has_ti): ?>
            <td class="px-3 py-3 text-center">
              <?php if ($d['ti'] !== null): ?>
                <span class="inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_bg_score_class($d['ti'])); ?>"><?php echo number_format($d['ti'], 2); ?></span>
              <?php else: ?>
                <span class="text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="px-3 py-3 text-center">
              <?php if ($d['t1'] !== null): ?>
                <span class="inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_bg_score_class($d['t1'])); ?>"><?php echo number_format($d['t1'], 2); ?></span>
              <?php else: ?>
                <span class="text-slate-400">—</span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-3 text-center">
              <?php if ($d['delta'] !== null): ?>
                <span class="inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_delta_badge_class($d['delta'])); ?>">
                  <?php echo ($d['delta'] > 0 ? '+' : '') . number_format($d['delta'], 2); ?>
                </span>
              <?php else: ?>
                <span class="text-slate-400">—</span>
              <?php endif; ?>
            </td>
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
    <!-- Inline tutor filter for charts -->
    <div class="relative" id="chart-tutor-wrap">
      <div id="chart-tutor-trigger" class="flex items-center gap-1 min-w-[200px] min-h-[36px] px-3 py-1.5 text-sm bg-white border shadow-sm rounded-xl border-slate-300 cursor-pointer hover:border-slate-400 flex-wrap">
        <span class="ms-placeholder text-slate-400">Toți tutorii</span>
        <svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
      </div>
      <div id="chart-tutor-dropdown" class="hidden absolute right-0 z-30 mt-1 bg-white border shadow-lg rounded-xl border-slate-200 py-1 max-h-60 overflow-y-auto" style="min-width:260px">
        <?php foreach ($chart_tutor_list as $tid => $tlab): ?>
          <div class="ct-opt flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50" data-val="<?php echo esc_attr($tid); ?>">
            <span class="inline-flex items-center justify-center w-4 h-4 rounded border ct-check bg-sky-600 border-sky-600">
              <svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </span>
            <span><?php echo esc_html($tlab); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="space-y-6">
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
    <table class="w-full text-sm">
      <thead class="sticky top-[52px] z-10 bg-indigo-800 text-white">
        <tr>
          <th class="px-3 py-3 font-semibold text-left">Tutor</th>
          <th class="px-3 py-3 font-semibold text-center">Profesori</th>
          <th class="px-3 py-3 font-semibold text-center">Elevi</th>
          <th class="px-3 py-3 font-semibold text-center"><?php echo esc_html($report_label); ?> T0</th>
          <?php if ($has_ti): ?>
          <th class="px-3 py-3 font-semibold text-center"><?php echo esc_html($report_label); ?> Ti</th>
          <?php endif; ?>
          <th class="px-3 py-3 font-semibold text-center"><?php echo esc_html($report_label); ?> T1</th>
          <th class="px-3 py-3 font-semibold text-center">Δ (T1−T0)</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <?php foreach ($tutor_agg as $tid => $ta): ?>
          <tr class="transition-colors hover:bg-slate-50">
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
                <span class="inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_bg_score_class($ta['t0'])); ?>"><?php echo number_format($ta['t0'], 2); ?></span>
              <?php else: ?><span class="text-slate-400">—</span><?php endif; ?>
            </td>
            <?php if ($has_ti): ?>
            <td class="px-3 py-3 text-center">
              <?php if ($ta['ti'] !== null): ?>
                <span class="inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_bg_score_class($ta['ti'])); ?>"><?php echo number_format($ta['ti'], 2); ?></span>
              <?php else: ?><span class="text-slate-400">—</span><?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="px-3 py-3 text-center">
              <?php if ($ta['t1'] !== null): ?>
                <span class="inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_bg_score_class($ta['t1'])); ?>"><?php echo number_format($ta['t1'], 2); ?></span>
              <?php else: ?><span class="text-slate-400">—</span><?php endif; ?>
            </td>
            <td class="px-3 py-3 text-center">
              <?php if ($ta['delta'] !== null): ?>
                <span class="inline-block px-2 py-1 text-xs rounded <?php echo esc_attr(rap_delta_badge_class($ta['delta'])); ?>">
                  <?php echo ($ta['delta'] > 0 ? '+' : '') . number_format($ta['delta'], 2); ?>
                </span>
              <?php else: ?><span class="text-slate-400">—</span><?php endif; ?>
            </td>
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

// Prof chart datasets
const profDs = [
  { label: '<?php echo $lbl; ?> T0', data: <?php echo wp_json_encode($chart_t0); ?>, backgroundColor: '#34aada', borderRadius: 3 },
];
if (hasTi) profDs.push({ label: '<?php echo $lbl; ?> Ti', data: <?php echo wp_json_encode($chart_ti); ?>, backgroundColor: '#fd431c', borderRadius: 3 });
profDs.push({ label: '<?php echo $lbl; ?> T1', data: <?php echo wp_json_encode($chart_t1); ?>, backgroundColor: '#057a55', borderRadius: 3 });

buildBarChart('sel-chart', <?php echo wp_json_encode($chart_labels, JSON_UNESCAPED_UNICODE); ?>, profDs, yMax);

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

// Initial render
renderTutorCharts();

// Inline tutor filter for charts (client-side only)
(function(){
  const wrap = document.getElementById('chart-tutor-wrap');
  const trigger = document.getElementById('chart-tutor-trigger');
  const dropdown = document.getElementById('chart-tutor-dropdown');
  if (!wrap || !trigger || !dropdown) return;

  const checkSvg = '<svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
  const arrow = '<svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';

  function render() {
    // Update checkboxes
    dropdown.querySelectorAll('.ct-opt').forEach(opt => {
      const v = opt.dataset.val, on = selectedTutors.includes(v);
      const box = opt.querySelector('.ct-check');
      box.className = 'inline-flex items-center justify-center w-4 h-4 rounded border ct-check ' + (on ? 'bg-sky-600 border-sky-600' : 'bg-white border-slate-300');
      box.innerHTML = on ? checkSvg : '';
    });
    // Update trigger text
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
    renderTutorCharts();
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
    dropdown.classList.toggle('hidden');
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
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#chart-tutor-wrap')) dropdown.classList.add('hidden');
  });
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
</script>
<?php endif; ?>

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
