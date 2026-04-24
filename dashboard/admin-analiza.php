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

/* ==================== FILTRE ==================== */
$now_m = (int) date('n');
$now_y = (int) date('Y');
$default_year = ($now_m >= 8) ? "$now_y-" . ($now_y + 1) : ($now_y - 1) . "-$now_y";

$tutor_filter = isset($_GET['tutor']) ? array_filter(array_map('intval', (array)$_GET['tutor'])) : [];
$nivel_filter = isset($_GET['nivel']) ? array_filter(array_map('sanitize_text_field', (array)$_GET['nivel'])) : [];

$years_raw = $wpdb->get_col("SELECT DISTINCT year FROM {$tbl_generations} WHERE year != '' ORDER BY year DESC");
$years_set = [];
foreach ((array)$years_raw as $yr) {
  $n = es_normalize_year_str($yr);
  if ($n !== '') $years_set[$n] = true;
}
krsort($years_set, SORT_NATURAL);
$years_available = array_keys($years_set);

// Smart default: if computed default doesn't exist in DB, use most recent year from DB
if (isset($_GET['an_scolar']) && $_GET['an_scolar'] !== '') {
  $year_filter = es_normalize_year_str(sanitize_text_field($_GET['an_scolar']));
} elseif (in_array($default_year, $years_available, true)) {
  $year_filter = $default_year;
} elseif (!empty($years_available)) {
  $year_filter = $years_available[0];
} else {
  $year_filter = $default_year;
}

$all_tutors = get_users(['role' => 'tutor', 'orderby' => 'display_name', 'order' => 'ASC', 'number' => -1]);
$tutor_options = [];
foreach ($all_tutors as $tu) {
  $tname = trim(($tu->first_name ?? '') . ' ' . ($tu->last_name ?? ''));
  if ($tname === '') $tname = $tu->display_name ?: $tu->user_login;
  $tutor_options[(int)$tu->ID] = $tname;
}

if ($is_tutor && !$is_admin) $tutor_filter = [$uid];

$nivel_options = ['prescolar'=>'Preșcolar','primar'=>'Primar','gimnazial'=>'Gimnazial','liceu'=>'Liceu'];

/* ==================== DATA ==================== */

// 1) Generații din anul selectat (acceptăm ambele forme stocate în DB: "YYYY" și "YYYY-YYYY")
$year_variants = es_year_variants($year_filter);
if (!empty($year_variants)) {
  $in_ph = implode(',', array_fill(0, count($year_variants), '%s'));
  $generations = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$tbl_generations} WHERE year IN ($in_ph) ORDER BY professor_id, name",
    ...$year_variants
  ));
} else {
  $generations = [];
}

// 2) Profesori unici
$prof_ids = array_unique(array_filter(array_map(fn($g) => (int)$g->professor_id, $generations)));

// Filtre pe tutor
if (!empty($tutor_filter)) {
  $prof_ids = array_filter($prof_ids, fn($pid) =>
    in_array((int)get_user_meta($pid, 'assigned_tutor_id', true), $tutor_filter, true)
  );
}
// Filtre pe nivel
if (!empty($nivel_filter)) {
  $prof_ids = array_filter($prof_ids, function($pid) use ($nivel_filter) {
    $raw = get_user_meta($pid, 'nivel_predare', true);
    $vals = is_array($raw) ? $raw : [$raw];
    foreach ($vals as $v) {
      if (in_array(strtolower(trim((string)$v)), $nivel_filter, true)) return true;
    }
    return false;
  });
}
$prof_ids = array_values($prof_ids);

// 3) Map generații per profesor
$gens_by_prof = [];
foreach ($generations as $g) {
  $pid = (int)$g->professor_id;
  if (in_array($pid, $prof_ids, true)) $gens_by_prof[$pid][] = $g;
}

// 4) Colectăm studenți
$gen_ids = array_filter(array_map(fn($g) => (int)$g->id, $generations));
$students_by_gen = [];
if (!empty($gen_ids)) {
  $in = implode(',', array_fill(0, count($gen_ids), '%d'));
  $all_st = $wpdb->get_results($wpdb->prepare(
    "SELECT id, generation_id, professor_id, class_label FROM {$tbl_students} WHERE generation_id IN ($in)", ...$gen_ids
  ));
  foreach ($all_st as $st) $students_by_gen[(int)$st->generation_id][] = $st;
}

// 5) Colectăm rezultate (SEL + LIT) — câte un rezultat per student per modul (ultimul)
$all_student_ids = [];
foreach ($students_by_gen as $arr) foreach ($arr as $st) $all_student_ids[] = (int)$st->id;
$all_student_ids = array_unique($all_student_ids);

$results_map = []; // [student_id][modul_slug] = row (ultimul)
if (!empty($all_student_ids)) {
  $in = implode(',', array_fill(0, count($all_student_ids), '%d'));
  $rows = $wpdb->get_results($wpdb->prepare(
    "SELECT student_id, modul_type, modul, completion, status
     FROM {$tbl_results}
     WHERE student_id IN ($in) AND modul_type IN ('sel','lit')
     ORDER BY created_at DESC",
    ...$all_student_ids
  ));
  foreach ($rows as $r) {
    $sid = (int)$r->student_id;
    // Normalizăm stage-ul
    $modul = strtolower(trim($r->modul));
    $stage = null;
    // SEL stages
    if (strpos($modul, 'sel-t0') === 0) $stage = 'sel_t0';
    elseif (strpos($modul, 'sel-ti') === 0) $stage = 'sel_ti';
    elseif (strpos($modul, 'sel-t1') === 0) $stage = 'sel_t1';
    // LIT stages — match lit-t0-xxx, lit-t1-xxx, literatie-*-t0, literatie-*-t1
    elseif (strpos($modul, 'lit-t0') === 0 || preg_match('/-t0$/', $modul)) $stage = 'lit_t0';
    elseif (strpos($modul, 'lit-t1') === 0 || preg_match('/-t1$/', $modul)) $stage = 'lit_t1';
    if ($stage && !isset($results_map[$sid][$stage])) {
      $results_map[$sid][$stage] = $r;
    }
  }
}

// 6) Construim datele per generație
$eval_stages = ['sel_t0','sel_ti','sel_t1','lit_t0','lit_t1'];
$stage_labels = [
  'sel_t0' => 'SEL T0', 'sel_ti' => 'SEL Ti', 'sel_t1' => 'SEL T1',
  'lit_t0' => 'LIT T0', 'lit_t1' => 'LIT T1',
];

$analysis_data = []; // per professor, per generation

foreach ($prof_ids as $pid) {
  $prof_user = get_user_by('id', $pid);
  if (!$prof_user) continue;
  $prof_name = trim(($prof_user->first_name ?? '') . ' ' . ($prof_user->last_name ?? ''));
  if ($prof_name === '') $prof_name = $prof_user->display_name ?: $prof_user->user_login;

  $tid = (int) get_user_meta($pid, 'assigned_tutor_id', true);
  $tutor_name = '—';
  if ($tid) {
    $tu = get_user_by('id', $tid);
    if ($tu) {
      $tutor_name = trim(($tu->first_name ?? '') . ' ' . ($tu->last_name ?? ''));
      if ($tutor_name === '') $tutor_name = $tu->display_name ?: '—';
    }
  }

  foreach ($gens_by_prof[$pid] ?? [] as $gen) {
    $gid = (int)$gen->id;
    $students = $students_by_gen[$gid] ?? [];
    $total_students = count($students);
    if ($total_students === 0) continue;

    $stage_data = [];
    foreach ($eval_stages as $stage) {
      // Câți studenți au completat această evaluare
      $completed = 0;
      $draft = 0;
      $final = 0;
      $completion_sum = 0;

      foreach ($students as $st) {
        $sid = (int)$st->id;
        if (isset($results_map[$sid][$stage])) {
          $completed++;
          $r = $results_map[$sid][$stage];
          $completion_sum += intval($r->completion ?? 0);
          $status = strtolower(trim($r->status ?? ''));
          if ($status === 'final') $final++;
          else $draft++;
        }
      }

      $stage_data[$stage] = [
        'total'      => $total_students,
        'completed'  => $completed,
        'draft'      => $draft,
        'final'      => $final,
        'pct'        => $total_students > 0 ? round(100.0 * $completed / $total_students, 1) : 0,
        'avg_compl'  => $completed > 0 ? round($completion_sum / $completed, 0) : null,
        'activated'  => (int)($gen->{str_replace('_', '_', $stage)} ?? 0),
      ];
    }

    $analysis_data[] = [
      'pid'        => $pid,
      'tid'        => $tid,
      'prof_name'  => $prof_name,
      'tutor_name' => $tutor_name,
      'gen_id'     => $gid,
      'gen_name'   => $gen->name,
      'gen_year'   => $gen->year,
      'level'      => $gen->level,
      'students'   => $total_students,
      'stages'     => $stage_data,
    ];
  }
}

// Aggregare per profesor
$prof_agg = [];
foreach ($analysis_data as $d) {
  $pid = $d['pid'];
  if (!isset($prof_agg[$pid])) {
    $prof_agg[$pid] = [
      'prof_name'  => $d['prof_name'],
      'tutor_name' => $d['tutor_name'],
      'tid'        => $d['tid'],
      'levels'     => [],
      'years'      => [],
      'students'   => 0,
      'gen_count'  => 0,
      'stages'     => array_fill_keys($eval_stages, ['total' => 0, 'completed' => 0, 'draft' => 0, 'final' => 0]),
    ];
  }
  if (!empty($d['level']))    $prof_agg[$pid]['levels'][$d['level']] = true;
  if (!empty($d['gen_year'])) $prof_agg[$pid]['years'][$d['gen_year']] = true;
  $prof_agg[$pid]['students'] += $d['students'];
  $prof_agg[$pid]['gen_count']++;
  foreach ($eval_stages as $st) {
    $prof_agg[$pid]['stages'][$st]['total'] += $d['stages'][$st]['total'];
    $prof_agg[$pid]['stages'][$st]['completed'] += $d['stages'][$st]['completed'];
    $prof_agg[$pid]['stages'][$st]['draft'] += $d['stages'][$st]['draft'];
    $prof_agg[$pid]['stages'][$st]['final'] += $d['stages'][$st]['final'];
  }
}
uasort($prof_agg, fn($a, $b) => strcasecmp($a['prof_name'], $b['prof_name']));

// Aggregare per tutor
$tutor_agg = [];
foreach ($analysis_data as $d) {
  $tid = $d['tid'] ?? 0;
  if (!isset($tutor_agg[$tid])) {
    $tutor_agg[$tid] = [
      'tutor_name' => $d['tutor_name'],
      'profs'      => [],
      'students'   => 0,
      'gen_count'  => 0,
      'stages'     => array_fill_keys($eval_stages, ['total' => 0, 'completed' => 0, 'draft' => 0, 'final' => 0]),
    ];
  }
  $tutor_agg[$tid]['profs'][$d['pid']] = true;
  $tutor_agg[$tid]['students'] += $d['students'];
  $tutor_agg[$tid]['gen_count']++;
  foreach ($eval_stages as $st) {
    $tutor_agg[$tid]['stages'][$st]['total'] += $d['stages'][$st]['total'];
    $tutor_agg[$tid]['stages'][$st]['completed'] += $d['stages'][$st]['completed'];
    $tutor_agg[$tid]['stages'][$st]['draft'] += $d['stages'][$st]['draft'];
    $tutor_agg[$tid]['stages'][$st]['final'] += $d['stages'][$st]['final'];
  }
}
uasort($tutor_agg, fn($a, $b) => strcasecmp($a['tutor_name'], $b['tutor_name']));

// Totals
$grand_total = ['students' => 0, 'stages' => array_fill_keys($eval_stages, ['total' => 0, 'completed' => 0])];
foreach ($prof_agg as $pa) {
  $grand_total['students'] += $pa['students'];
  foreach ($eval_stages as $st) {
    $grand_total['stages'][$st]['total'] += $pa['stages'][$st]['total'];
    $grand_total['stages'][$st]['completed'] += $pa['stages'][$st]['completed'];
  }
}

$has_filters = !empty($tutor_filter) || !empty($nivel_filter) || $year_filter !== $default_year;

// Helper: color class for completion percentage
function ana_pct_class($pct) {
  if ($pct >= 90) return 'bg-green-100 text-green-800';
  if ($pct >= 70) return 'bg-lime-100 text-lime-800';
  if ($pct >= 50) return 'bg-amber-100 text-amber-800';
  if ($pct >= 25) return 'bg-orange-100 text-orange-800';
  return 'bg-red-100 text-red-800';
}
function ana_pct_bar_color($pct) {
  if ($pct >= 90) return 'bg-green-500';
  if ($pct >= 70) return 'bg-lime-500';
  if ($pct >= 50) return 'bg-amber-500';
  if ($pct >= 25) return 'bg-orange-500';
  return 'bg-red-500';
}
?>

<!-- Filters -->
<section class="sticky top-0 z-20 border-b bg-slate-800 border-slate-700">
  <form method="get" action="<?php echo esc_url(home_url('/panou/analiza/')); ?>" class="flex flex-wrap items-end gap-3 px-4 py-3">
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

    <?php if ($is_admin): ?>
    <div class="relative" id="ana-tutor-wrap">
      <label class="block mb-1 text-xs font-medium text-slate-300">Tutor</label>
      <div id="ana-tutor-inputs">
        <?php foreach ($tutor_filter as $tv): ?>
          <input type="hidden" name="tutor[]" value="<?php echo (int)$tv; ?>">
        <?php endforeach; ?>
      </div>
      <div id="ana-tutor-trigger" class="flex items-center gap-1 min-w-[200px] min-h-[38px] px-3 py-1.5 text-sm bg-white border shadow-sm rounded-xl border-slate-300 cursor-pointer hover:border-slate-400 flex-wrap">
        <?php if (empty($tutor_filter)): ?>
          <span class="ms-placeholder text-slate-400">— Toți tutorii —</span>
        <?php else: ?>
          <?php foreach ($tutor_filter as $tv): ?>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 ms-tag" data-val="<?php echo (int)$tv; ?>">
              <?php echo esc_html($tutor_options[$tv] ?? "Tutor #$tv"); ?>
              <button type="button" class="ms-remove hover:text-red-600">&times;</button>
            </span>
          <?php endforeach; ?>
        <?php endif; ?>
        <svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
      </div>
      <div id="ana-tutor-dropdown" class="hidden absolute z-30 w-full mt-1 bg-white border shadow-lg rounded-xl border-slate-200 py-1 max-h-60 overflow-y-auto" style="min-width:260px">
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
    <?php endif; ?>

    <div class="relative" id="ana-nivel-wrap">
      <label class="block mb-1 text-xs font-medium text-slate-300">Nivel predare</label>
      <div id="ana-nivel-inputs">
        <?php foreach ($nivel_filter as $v): ?>
          <input type="hidden" name="nivel[]" value="<?php echo esc_attr($v); ?>">
        <?php endforeach; ?>
      </div>
      <div id="ana-nivel-trigger" class="flex items-center gap-1 min-w-[160px] min-h-[38px] px-3 py-1.5 text-sm bg-white border shadow-sm rounded-xl border-slate-300 cursor-pointer hover:border-slate-400 flex-wrap">
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
      <div id="ana-nivel-dropdown" class="hidden absolute z-30 w-full mt-1 bg-white border shadow-lg rounded-xl border-slate-200 py-1" style="min-width:200px">
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

    <div class="flex items-end gap-2">
      <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white shadow-sm rounded-xl bg-emerald-600 hover:bg-emerald-700">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M21 21 15 15m2-5a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"/></svg>
        Filtrează
      </button>
      <?php if ($has_filters): ?>
        <a href="<?php echo esc_url(home_url('/panou/analiza/')); ?>" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-xl text-slate-300 hover:bg-slate-700 hover:text-white border border-slate-600">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
          Șterge filtre
        </a>
      <?php endif; ?>
    </div>

    <div class="ml-auto text-xs text-slate-400">
      <?php echo count($prof_agg); ?> profesori &middot; <?php echo count($analysis_data); ?> generații &middot; An: <?php echo esc_html($year_filter); ?>
    </div>
  </form>
</section>

<?php if (empty($analysis_data)): ?>
  <section class="p-6 m-6 bg-white border rounded-2xl border-slate-200">
    <p class="text-slate-500">Nu s-au găsit generații cu studenți pentru anul școlar <strong><?php echo esc_html($year_filter); ?></strong>.</p>
  </section>
<?php else: ?>

<!-- Grand totals summary cards -->
<section class="px-6 mt-6">
  <div class="grid gap-3 sm:grid-cols-5">
    <?php foreach ($eval_stages as $st):
      $gt = $grand_total['stages'][$st];
      $pct = $gt['total'] > 0 ? round(100.0 * $gt['completed'] / $gt['total'], 1) : 0;
    ?>
      <div class="p-4 bg-white border rounded-xl border-slate-200">
        <div class="text-xs font-medium text-slate-500"><?php echo esc_html($stage_labels[$st]); ?></div>
        <div class="flex items-end gap-2 mt-1">
          <span class="text-2xl font-bold text-slate-800"><?php echo $pct; ?>%</span>
          <span class="text-xs text-slate-500 mb-0.5"><?php echo $gt['completed']; ?>/<?php echo $gt['total']; ?> elevi</span>
        </div>
        <div class="w-full h-2 mt-2 overflow-hidden rounded-full bg-slate-100">
          <div class="h-full rounded-full <?php echo ana_pct_bar_color($pct); ?>" style="width:<?php echo min(100, $pct); ?>%"></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- Tabs switch -->
<section class="px-6 mt-4">
  <div class="inline-flex items-center p-1 bg-white border rounded-xl border-slate-200 shadow-sm">
    <button type="button" data-tab="prof"
            class="ana-tab-btn px-4 py-2 text-sm font-medium rounded-lg bg-sky-800 text-white">
      Per profesor
    </button>
    <button type="button" data-tab="tutor"
            class="ana-tab-btn px-4 py-2 text-sm font-medium rounded-lg text-slate-700 hover:bg-slate-100">
      Per tutor
    </button>
  </div>
</section>

<!-- Per professor table -->
<section class="px-6 pb-8 mt-4 ana-tab-content" data-tab="prof">
  <div class="bg-white border shadow-sm rounded-2xl border-slate-200">
    <div class="px-4 py-3 border-b border-slate-200">
      <h2 class="text-lg font-semibold text-slate-800">Grad completare per profesor</h2>
    </div>
    <table class="w-full text-sm">
      <thead class="sticky top-[52px] z-10 bg-sky-800 text-white">
        <tr>
          <th class="px-3 py-3 font-semibold text-left">Profesor</th>
          <th class="px-3 py-3 font-semibold text-left">Tutor</th>
          <th class="px-3 py-3 font-semibold text-left">Nivel</th>
          <th class="px-3 py-3 font-semibold text-left">An școlar</th>
          <th class="px-3 py-3 font-semibold text-center">Generații</th>
          <th class="px-3 py-3 font-semibold text-center">Elevi</th>
          <?php foreach ($eval_stages as $st): ?>
            <th class="px-3 py-3 font-semibold text-center"><?php echo esc_html($stage_labels[$st]); ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <?php foreach ($prof_agg as $pid => $pa):
          $levels = array_map(fn($lv) => ucfirst($lv), array_keys($pa['levels']));
          $years  = array_keys($pa['years']);
        ?>
          <tr class="transition-colors hover:bg-slate-50">
            <td class="px-3 py-3 font-medium text-slate-900">
              <a href="<?php echo esc_url(home_url('/panou/profesor/' . $pid)); ?>" class="hover:text-emerald-700"><?php echo esc_html($pa['prof_name']); ?></a>
            </td>
            <td class="px-3 py-3 text-slate-700"><?php echo esc_html($pa['tutor_name']); ?></td>
            <td class="px-3 py-3 text-slate-700"><?php echo $levels ? esc_html(implode(', ', $levels)) : '—'; ?></td>
            <td class="px-3 py-3 text-slate-700"><?php echo $years ? esc_html(implode(', ', $years)) : '—'; ?></td>
            <td class="px-3 py-3 text-center text-slate-800"><?php echo (int)$pa['gen_count']; ?></td>
            <td class="px-3 py-3 text-center text-slate-800"><?php echo (int)$pa['students']; ?></td>
            <?php foreach ($eval_stages as $st):
              $s = $pa['stages'][$st];
              $pct = $s['total'] > 0 ? round(100.0 * $s['completed'] / $s['total'], 1) : 0;
            ?>
              <td class="px-3 py-2 text-center">
                <div class="inline-flex flex-col items-center gap-1">
                  <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?php echo ana_pct_class($pct); ?>"><?php echo $pct; ?>%</span>
                  <span class="text-[10px] text-slate-400">
                    <?php echo $s['completed']; ?>/<?php echo $s['total']; ?>
                    <?php if ($s['draft'] > 0): ?>
                      <span class="text-amber-500">(<?php echo $s['draft']; ?> temp)</span>
                    <?php endif; ?>
                  </span>
                </div>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- Per tutor table -->
<section class="px-6 pb-8 mt-4 ana-tab-content hidden" data-tab="tutor">
  <div class="bg-white border shadow-sm rounded-2xl border-slate-200">
    <div class="px-4 py-3 border-b border-slate-200">
      <h2 class="text-lg font-semibold text-slate-800">Grad completare per tutor</h2>
    </div>
    <table class="w-full text-sm">
      <thead class="sticky top-[52px] z-10 bg-indigo-800 text-white">
        <tr>
          <th class="px-3 py-3 font-semibold text-left">Tutor</th>
          <th class="px-3 py-3 font-semibold text-center">Profesori</th>
          <th class="px-3 py-3 font-semibold text-center">Generații</th>
          <th class="px-3 py-3 font-semibold text-center">Elevi</th>
          <?php foreach ($eval_stages as $st): ?>
            <th class="px-3 py-3 font-semibold text-center"><?php echo esc_html($stage_labels[$st]); ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <?php foreach ($tutor_agg as $tid => $ta):
          $prof_count = count($ta['profs']);
        ?>
          <tr class="transition-colors hover:bg-slate-50">
            <td class="px-3 py-3 font-medium text-slate-900">
              <?php if ($tid > 0): ?>
                <a href="<?php echo esc_url(home_url('/panou/tutor/' . $tid)); ?>" class="hover:text-indigo-700"><?php echo esc_html($ta['tutor_name']); ?></a>
              <?php else: ?>
                <span class="text-slate-500"><?php echo esc_html($ta['tutor_name']); ?></span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-3 text-center text-slate-800"><?php echo (int)$prof_count; ?></td>
            <td class="px-3 py-3 text-center text-slate-800"><?php echo (int)$ta['gen_count']; ?></td>
            <td class="px-3 py-3 text-center text-slate-800"><?php echo (int)$ta['students']; ?></td>
            <?php foreach ($eval_stages as $st):
              $s = $ta['stages'][$st];
              $pct = $s['total'] > 0 ? round(100.0 * $s['completed'] / $s['total'], 1) : 0;
            ?>
              <td class="px-3 py-2 text-center">
                <div class="inline-flex flex-col items-center gap-1">
                  <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?php echo ana_pct_class($pct); ?>"><?php echo $pct; ?>%</span>
                  <span class="text-[10px] text-slate-400">
                    <?php echo $s['completed']; ?>/<?php echo $s['total']; ?>
                    <?php if ($s['draft'] > 0): ?>
                      <span class="text-amber-500">(<?php echo $s['draft']; ?> temp)</span>
                    <?php endif; ?>
                  </span>
                </div>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php endif; ?>

<!-- Multiselect JS -->
<script>
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
      const v = opt.dataset.val, isOn = selected.includes(v);
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

initMultiselect('ana-tutor', 'tutor[]',
  <?php $to = []; foreach ($tutor_options as $k=>$v) $to[(string)$k] = $v; echo wp_json_encode($to, JSON_UNESCAPED_UNICODE); ?>,
  <?php echo wp_json_encode(array_map('strval', $tutor_filter)); ?>,
  '— Toți tutorii —'
);
initMultiselect('ana-nivel', 'nivel[]',
  <?php echo wp_json_encode($nivel_options, JSON_UNESCAPED_UNICODE); ?>,
  <?php echo wp_json_encode(array_values($nivel_filter)); ?>,
  '— Oricare —'
);

// Tab switch
(function(){
  const btns = document.querySelectorAll('.ana-tab-btn');
  const contents = document.querySelectorAll('.ana-tab-content');
  btns.forEach(b => b.addEventListener('click', () => {
    const tab = b.dataset.tab;
    btns.forEach(x => {
      if (x.dataset.tab === tab) {
        x.classList.add('bg-sky-800', 'text-white');
        x.classList.remove('text-slate-700', 'hover:bg-slate-100');
      } else {
        x.classList.remove('bg-sky-800', 'text-white');
        x.classList.add('text-slate-700', 'hover:bg-slate-100');
      }
    });
    contents.forEach(c => {
      if (c.dataset.tab === tab) c.classList.remove('hidden');
      else c.classList.add('hidden');
    });
  }));
})();
</script>
