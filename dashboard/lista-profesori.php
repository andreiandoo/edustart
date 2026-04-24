<?php
if (!defined('ABSPATH')) exit;

$current_user = wp_get_current_user();
$uid          = (int) ($current_user->ID ?? 0);
$roles        = (array) ($current_user->roles ?? []);
$is_admin     = current_user_can('manage_options');
$is_tutor     = in_array('tutor', $roles, true);

if (!$is_admin && !$is_tutor) {
  echo '<div class="max-w-5xl p-6 mx-auto"><div class="p-4 text-red-700 bg-red-100 rounded">Acces restricționat.</div></div>';
  return;
}

global $wpdb;
$ajax_url_teachers   = admin_url('admin-ajax.php');
$ajax_nonce_teachers = wp_create_nonce('edu_nonce');
$tbl_users       = $wpdb->users;
$tbl_umeta       = $wpdb->usermeta;
$tbl_generations = $wpdb->prefix . 'edu_generations';
$tbl_students    = $wpdb->prefix . 'edu_students';
$tbl_schools     = $wpdb->prefix . 'edu_schools';
$tbl_cities      = $wpdb->prefix . 'edu_cities';
$tbl_counties    = $wpdb->prefix . 'edu_counties';

/* ================= Helpers ================= */

function es_normalize_level_code($raw) {
  $c = strtolower(trim((string)$raw));
  // normalize variante
  if ($c === 'primar-mic' || $c === 'primar mare' || $c === 'primar-mare') $c = 'primar';
  if ($c === 'gimnaziu') $c = 'gimnazial';
  if ($c === 'preșcolar' || $c === 'prescolari' || $c === 'preșcolari') $c = 'prescolar';
  return in_array($c, ['prescolar','primar','gimnazial','liceu'], true) ? $c : ($c ?: '');
}
function es_level_label($code) {
  $map = ['prescolar'=>'Preșcolar','primar'=>'Primar','gimnazial'=>'Gimnazial','liceu'=>'Liceu'];
  $code = es_normalize_level_code($code);
  return $map[$code] ?? '—';
}
function es_badge($label, $class) {
  return '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset '.$class.'">'.$label.'</span>';
}
function es_statut_badge($val) {
  $v = strtolower(trim((string)$val));
  if ($v === 'aprobat' || $v === 'approved')     return es_badge('Aprobat', 'bg-emerald-50 text-emerald-700 ring-emerald-200');
  if ($v === 'respins' || $v === 'rejected')     return es_badge('Respins', 'bg-rose-50 text-rose-700 ring-rose-200');
  if ($v === 'in_asteptare' || $v === 'pending') return es_badge('În așteptare', 'bg-amber-50 text-amber-800 ring-amber-200');
  if ($v === 'activ' || $v === 'active') return es_badge('Activ', 'bg-emerald-50 text-emerald-700 ring-emerald-200');
  if ($v === 'titular') return es_badge('Titular', 'bg-sky-50 text-sky-700 ring-sky-200');
  return es_badge($val !== '' ? $val : 'Nesetat', 'bg-slate-100 text-slate-700 ring-slate-200');
}
function es_initials($user){
  $fn = trim((string)($user->first_name ?? ''));
  $ln = trim((string)($user->last_name ?? ''));
  $display = trim((string)($user->display_name ?? $user->user_login));
  $ini = '';
  if ($fn !== '' || $ln !== '') {
    if ($fn !== '') $ini .= mb_strtoupper(mb_substr($fn,0,1));
    if ($ln !== '') $ini .= mb_strtoupper(mb_substr($ln,0,1));
  } else {
    $parts = preg_split('/\s+/', $display);
    if (!empty($parts)) {
      $ini .= mb_strtoupper(mb_substr($parts[0],0,1));
      if (count($parts) > 1) $ini .= mb_strtoupper(mb_substr(end($parts),0,1));
    }
  }
  return mb_substr($ini,0,2);
}
function es_format_dt($ts_or_str){
  if (!$ts_or_str) return '—';
  $ts = is_numeric($ts_or_str) ? (int)$ts_or_str : strtotime($ts_or_str);
  if (!$ts) return '—';
  return date_i18n(get_option('date_format').' '.get_option('time_format'), $ts);
}

/* =============== Filtre (UI & logic) =============== */

$s          = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
$nivel_arr  = isset($_GET['nivel']) ? array_filter(array_map('sanitize_text_field', (array)$_GET['nivel'])) : [];
$statut     = isset($_GET['statut']) ? sanitize_text_field(wp_unslash($_GET['statut'])) : '';
$gen_year   = isset($_GET['gen_year']) ? es_normalize_year_str(sanitize_text_field(wp_unslash($_GET['gen_year']))) : '';
$county_f   = isset($_GET['county']) ? sanitize_text_field(wp_unslash($_GET['county'])) : '';
$an_program = isset($_GET['an_program']) ? sanitize_text_field(wp_unslash($_GET['an_program'])) : '';
$rsoi       = isset($_GET['rsoi']) ? sanitize_text_field(wp_unslash($_GET['rsoi'])) : '';

$perpage    = max(5, min(200, (int)($_GET['perpage'] ?? 25)));
$paged      = max(1, (int)($_GET['paged'] ?? 1));
$export_csv = isset($_GET['export']) && $_GET['export'] === 'csv';

/* =============== Query profesori (fără pagination; filtrăm manual) =============== */

$meta_query = ['relation' => 'AND'];

// Tutor: doar profesorii lui
if ($is_tutor && !$is_admin) {
  $meta_query[] = [
    'key'     => 'assigned_tutor_id',
    'value'   => $uid,
    'compare' => '=',
    'type'    => 'NUMERIC',
  ];
}

// Statut (mai multe chei posibile)
if ($statut !== '') {
  $meta_query[] = [
    'relation' => 'OR',
    ['key' => 'user_status_profesor', 'value' => $statut, 'compare' => '='],
    ['key' => 'statut_prof',          'value' => $statut, 'compare' => '='],
    ['key' => 'statut',               'value' => $statut, 'compare' => '='],
  ];
}

// Filtre noi: An program & RSOI (pe usermeta)
if ($an_program !== '') {
  $meta_query[] = ['key' => 'an_program', 'value' => $an_program, 'compare' => '='];
}
if ($rsoi !== '') {
  $meta_query[] = ['key' => 'segment_rsoi', 'value' => $rsoi, 'compare' => '='];
}

$args = [
  'role'         => 'profesor',
  'number'       => -1,             // luăm toți -> filtrare manuală adițională
  'orderby'      => 'display_name',
  'order'        => 'ASC',
  'meta_query'   => $meta_query,
];

if ($s !== '') {
  $args['search']         = '*'.esc_attr($s).'*';
  $args['search_columns'] = ['user_login','user_nicename','user_email','display_name'];
}

$user_query = new WP_User_Query($args);
$all_prof   = $user_query->get_results(); // array WP_User

// Preluăm ID-urile
$prof_ids = array_map(fn($u)=>(int)$u->ID, $all_prof);

/* =============== Preluăm date asociate (generații, elevi, școli+judete) =============== */

$gens_by_prof    = [];
$years_available = []; // pt. dropdown An generație (din setul curent)
$students_count  = [];
$school_ids_all  = [];  // set global de school ids
$county_by_school= [];  // map school_id => county_name
$counties_by_prof= [];  // map prof_id   => set county names

// Colectăm valori distincte pentru filtrele „An program” și „RSOI”
$an_program_values = [];
$rsoi_values       = [];

if (!empty($prof_ids)) {
  // Generații
  $in = implode(',', array_fill(0, count($prof_ids), '%d'));
  $gens = $wpdb->get_results($wpdb->prepare("
    SELECT id, professor_id, name, level, year
    FROM {$tbl_generations}
    WHERE professor_id IN ($in)
    ORDER BY year DESC, id DESC
  ", ...$prof_ids));
  foreach ($gens as $g) {
    $pid = (int)$g->professor_id;
    if (!isset($gens_by_prof[$pid])) $gens_by_prof[$pid] = [];
    $gens_by_prof[$pid][] = $g;
    $yr = es_normalize_year_str($g->year ?? '');
    if ($yr !== '') $years_available[$yr] = true;
  }

  // Elevi per profesor (direct din edu_students)
  $sc = $wpdb->get_results($wpdb->prepare("
    SELECT professor_id, COUNT(*) AS total
    FROM {$tbl_students}
    WHERE professor_id IN ($in)
    GROUP BY professor_id
  ", ...$prof_ids));
  foreach ($sc as $row) {
    $students_count[(int)$row->professor_id] = (int)$row->total;
  }

  // Adunăm toate school ids de pe toți profii
  foreach ($prof_ids as $pid) {
    // colectăm și meta pentru an_program & segment_rsoi
    $ap   = get_user_meta($pid, 'an_program', true);
    $rseg = get_user_meta($pid, 'segment_rsoi', true);
    if ($ap !== '' && $ap !== null)   $an_program_values[ (string)$ap ] = true;
    if ($rseg !== '' && $rseg !== null) $rsoi_values[ (string)$rseg ] = true;

    $sids = get_user_meta($pid, 'assigned_school_ids', true);
    if (is_array($sids)) {
      foreach ($sids as $sid) {
        $sid = (int)$sid;
        if ($sid > 0) $school_ids_all[$sid] = true;
      }
    }
  }
  $school_ids_all = array_keys($school_ids_all);

  // Mapăm school_id => county_name
  if (!empty($school_ids_all)) {
    $in2 = implode(',', array_fill(0, count($school_ids_all), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare("
      SELECT s.id AS school_id, j.name AS county_name
      FROM {$tbl_schools} s
      LEFT JOIN {$tbl_cities} c  ON s.city_id = c.id
      LEFT JOIN {$tbl_counties} j ON c.county_id = j.id
      WHERE s.id IN ($in2)
    ", ...$school_ids_all));
    foreach ($rows as $r) {
      $county_by_school[(int)$r->school_id] = (string)$r->county_name;
    }
  }

  // Construim counties_by_prof
  foreach ($prof_ids as $pid) {
    $sids = get_user_meta($pid, 'assigned_school_ids', true);
    $set = [];
    if (is_array($sids)) {
      foreach ($sids as $sid) {
        $sid = (int)$sid;
        if ($sid > 0 && isset($county_by_school[$sid])) {
          $name = trim((string)$county_by_school[$sid]);
          if ($name !== '') $set[$name] = true;
        }
      }
    }
    $counties_by_prof[$pid] = array_keys($set);
  }
}

// Opțiuni pentru dropdown An generație și Județ (din dataset-ul curent)
$years_list   = array_keys($years_available);
sort($years_list, SORT_NATURAL);

$all_counties = [];
foreach ($counties_by_prof as $pid=>$arr) {
  foreach ($arr as $nm) $all_counties[$nm] = true;
}
$county_list = array_keys($all_counties);
sort($county_list, SORT_NATURAL);

// Opțiuni pentru filtre noi
$an_program_list = array_keys($an_program_values);
sort($an_program_list, SORT_NATURAL);

$rsoi_list = array_keys($rsoi_values);
sort($rsoi_list, SORT_NATURAL);

/* =============== Aplicăm filtrele pe generație & județ (manual) =============== */

$filtered = $all_prof;

// Filtru: Nivel predare (normalizat, multiselect)
if (!empty($nivel_arr)) {
  $nivel_codes = array_map('es_normalize_level_code', $nivel_arr);
  $nivel_codes = array_filter($nivel_codes);
  if (!empty($nivel_codes)) {
    $by_level = [];
    foreach ($filtered as $u) {
      $raw = get_user_meta((int)$u->ID, 'nivel_predare', true);
      $match = false;
      if (is_array($raw)) {
        foreach ($raw as $rv) {
          if (in_array(es_normalize_level_code($rv), $nivel_codes, true)) { $match = true; break; }
        }
      } else {
        if (in_array(es_normalize_level_code($raw), $nivel_codes, true)) $match = true;
      }
      if ($match) $by_level[] = $u;
    }
    $filtered = $by_level;
  }
}

// Filtru: An generație
if ($gen_year !== '') {
  $by_year = [];
  foreach ($filtered as $u) {
    $pid = (int)$u->ID;
    $has = false;
    if (!empty($gens_by_prof[$pid])) {
      foreach ($gens_by_prof[$pid] as $g) {
        if (es_normalize_year_str($g->year ?? '') === (string)$gen_year) { $has = true; break; }
      }
    }
    if ($has) $by_year[] = $u;
  }
  $filtered = $by_year;
}

// Filtru: Județ
if ($county_f !== '') {
  $by_cty = [];
  foreach ($filtered as $u) {
    $pid = (int)$u->ID;
    $ctys = $counties_by_prof[$pid] ?? [];
    if (in_array($county_f, $ctys, true)) $by_cty[] = $u;
  }
  $filtered = $by_cty;
}

// Sort by display_name anyway (User_Query deja ordonează)
usort($filtered, function($a,$b){
  return strcasecmp($a->display_name, $b->display_name);
});

// Paginare manuală
$total       = count($filtered);
$total_pages = (int) max(1, ceil($total / $perpage));
$paged       = min($paged, $total_pages);
$offset      = ($paged - 1) * $perpage;
$profesori   = array_slice($filtered, $offset, $perpage);

/* =============== Export CSV (setul filtrat, nu doar pagina curentă) =============== */
if ($export_csv) {
  $filename = 'profesori_'.date('Y-m-d_His').'.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  $out = fopen('php://output', 'w');
  // BOM pt. Excel
  fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

  // Head (cu noile câmpuri)
  fputcsv($out, ['ID','Nume','Email','Cod SLF','Statut','Nivel predare','An program','RSOI','Teach','Materie','Județ(e)','#Elevi','Generații (id·nivel·an)','Ultima activitate','Înregistrare']);

  foreach ($filtered as $u) {
    $pid   = (int)$u->ID;
    $name  = trim(($u->first_name ?: $u->display_name).' '.($u->last_name ?: ''));
    if ($name === '') $name = $u->display_name ?: $u->user_login;

    $cod   = get_user_meta($pid, 'cod_slf', true);
    $nivel_val = get_user_meta($pid, 'nivel_predare', true);
    $mat   = get_user_meta($pid, 'materia_predata', true);

    $an_prog = get_user_meta($pid, 'an_program', true);
    $rsoi_v  = get_user_meta($pid, 'segment_rsoi', true);
    $teach_v = get_user_meta($pid, 'generatie', true);

    $stat  = get_user_meta($pid, 'user_status_profesor', true);
    if ($stat==='') $stat = get_user_meta($pid, 'statut_prof', true);
    if ($stat==='') $stat = get_user_meta($pid, 'statut', true);

    $ctys  = $counties_by_prof[$pid] ?? [];
    $cty_str = $ctys ? implode('; ', $ctys) : '';

    $elevi = (int)($students_count[$pid] ?? 0);

    $gen_bits = [];
    if (!empty($gens_by_prof[$pid])) {
      foreach ($gens_by_prof[$pid] as $g) {
        $gen_bits[] = '#'.$g->id.'·'.es_level_label($g->level).'·'.es_normalize_year_str($g->year ?? '');
      }
    }

    $last = get_user_meta($pid,'last_activity',true);
    if (!$last) $last = get_user_meta($pid,'last_login',true);
    if (!$last) $last = get_user_meta($pid,'last_seen',true);
    if (!$last) $last = strtotime($u->user_registered);

    $registered_ts = $u->user_registered ? strtotime($u->user_registered) : 0;

    fputcsv($out, [
      $pid,
      $name,
      $u->user_email,
      $cod,
      $stat ?: '',
      es_level_label($nivel_val),
      $an_prog ?: '',
      $rsoi_v ?: '',
      $teach_v ?: '',
      $mat ?: '',
      $cty_str,
      $elevi,
      implode(' | ', $gen_bits),
      es_format_dt($last),
      es_format_dt($registered_ts),
    ]);
  }
  fclose($out);
  exit;
}

/* =============== UI: Filtre pe un rând + Export =============== */

// build query-string base (fără paged)
$qs = $_GET; unset($qs['paged'], $qs['export']);
$base_url = esc_url(add_query_arg($qs, remove_query_arg(['paged','export'])));

// Column definitions for toggle
$COLS = [
  'cod'         => 'Cod SLF',
  'statut'      => 'Statut',
  'nivel'       => 'Nivel',
  'anprog'      => 'An program',
  'rsoi'        => 'RSOI',
  'teach'       => 'Teach',
  'materie'     => 'Materie',
  'generatii'   => 'Generații',
  'elevi'       => 'Elevi',
  'scoli'       => 'Școli',
  'judet'       => 'Județ',
  'last'        => 'Ultima activitate',
  'reg'         => 'Înregistrare',
];

$export_url = add_query_arg([
  'action' => 'edus_export_teachers_csv',
  'nonce'  => wp_create_nonce('edus_export_teachers_csv'),
  // păstrăm filtrele active (exemplu; ajustează în handler dacă folosești alți parametri)
  's'          => $s,
  'nivel'      => $nivel_arr,
  'statut'     => $statut,
  'gen_year'   => $gen_year,
  'county'     => $county_f,
  'an_program' => $an_program,
  'rsoi'       => $rsoi,
], admin_url('admin-post.php'));


?>

<section class="sticky top-0 z-20 border-b inner-submenu bg-slate-800 border-slate-200">
  <div class="relative z-20 flex items-center justify-between px-2 py-2 gap-x-2">
    <div class="flex items-center justify-start">

    </div>

    <div class="flex items-center justify-end gap-x-2">
      <button id="cols-toggle-btn" type="button" 
        class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        Arata coloane
      </button>

      <a href="<?php echo esc_url($export_url); ?>"
        class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
        Export CSV
      </a>
    </div>
  </div>
</section>

<!-- Toggle coloane -->
<section id="cols-section" class="hidden px-6 mb-4 -mt-2">
  <div class="p-3 bg-white border shadow-sm rounded-xl border-slate-200">
    <div class="mb-2 text-xs font-medium text-slate-600">Afișează/ascunde coloane</div>
    <div class="flex flex-wrap gap-3 text-sm" id="cols-toggle">
      <?php foreach ($COLS as $k=>$label): ?>
        <label class="inline-flex items-center gap-2 select-none">
          <input type="checkbox" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" data-col="<?php echo esc_attr($k); ?>" checked>
          <span class="text-slate-700"><?php echo esc_html($label); ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="px-6 mt-4 mb-6">
  <form method="get" action="<?php echo esc_url(home_url('/profesori/')); ?>" class="grid items-end grid-cols-1 gap-3 md:grid-cols-12">
    <!-- Căutare -->
    <div class="relative md:col-span-3">
      <label class="block mb-1 text-xs font-medium text-slate-600">Căutare (nume/email)</label>
      <input
        id="prof-q"
        type="search"
        name="s"
        value="<?php echo esc_attr($s); ?>"
        placeholder="Tastează nume sau adresa email"
        class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:outline-none focus:ring-1 focus:ring-sky-700 focus:border-transparent"
        inputmode="search" autocomplete="off"
        data-ajax="<?php echo esc_url($ajax_url_teachers); ?>"
        data-nonce="<?php echo esc_attr($ajax_nonce_teachers); ?>"
      />
      <!-- panou sugestii -->
      <div id="prof-suggest"
          class="absolute z-20 hidden w-full mt-1 overflow-auto bg-white border rounded-lg shadow-lg max-h-72 border-slate-200"></div>
    </div>

    <!-- Nivel (multiselect custom dropdown) -->
    <div class="md:col-span-2 relative" id="nivel-ms-wrap">
      <label class="block mb-1 text-xs font-medium text-slate-600">Nivel predare</label>
      <?php
        $nivel_options = ['prescolar'=>'Preșcolar','primar'=>'Primar','gimnazial'=>'Gimnazial','liceu'=>'Liceu'];
      ?>
      <!-- Hidden inputs for form submission -->
      <div id="nivel-ms-inputs">
        <?php foreach ($nivel_arr as $v): ?>
          <input type="hidden" name="nivel[]" value="<?php echo esc_attr($v); ?>">
        <?php endforeach; ?>
      </div>
      <!-- Trigger -->
      <div id="nivel-ms-trigger" class="flex items-center gap-1 w-full min-h-[38px] px-3 py-1.5 text-sm bg-white border shadow-sm rounded-xl border-slate-300 cursor-pointer hover:border-slate-400 flex-wrap">
        <?php if (empty($nivel_arr)): ?>
          <span class="nivel-ms-placeholder text-slate-400">— Oricare —</span>
        <?php else: ?>
          <?php foreach ($nivel_arr as $v):
            $lab = $nivel_options[$v] ?? $v;
          ?>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 nivel-ms-tag" data-val="<?php echo esc_attr($v); ?>">
              <?php echo esc_html($lab); ?>
              <button type="button" class="nivel-ms-remove hover:text-red-600">&times;</button>
            </span>
          <?php endforeach; ?>
        <?php endif; ?>
        <svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
      </div>
      <!-- Dropdown -->
      <div id="nivel-ms-dropdown" class="hidden absolute z-30 w-full mt-1 bg-white border shadow-lg rounded-xl border-slate-200 py-1">
        <?php foreach ($nivel_options as $k=>$lab): ?>
          <div class="nivel-ms-opt flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50" data-val="<?php echo esc_attr($k); ?>">
            <span class="inline-flex items-center justify-center w-4 h-4 rounded border border-slate-300 nivel-ms-check <?php echo in_array($k, $nivel_arr, true) ? 'bg-sky-600 border-sky-600' : 'bg-white'; ?>">
              <?php if (in_array($k, $nivel_arr, true)): ?>
                <svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
              <?php endif; ?>
            </span>
            <span><?php echo esc_html($lab); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Statut -->
    <div class="md:col-span-1">
      <label class="block mb-1 text-xs font-medium text-slate-600">Statut</label>
      <select name="statut" class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-700 focus:border-transparent">
        <option value="" class="text-xs">— Oricare —</option>
        <?php foreach (['activ'=>'Activ','in_asteptare'=>'În așteptare','aprobat'=>'Aprobat','respins'=>'Respins'] as $k=>$lab): ?>
          <option value="<?php echo esc_attr($k); ?>" <?php selected($statut===$k); ?>><?php echo esc_html($lab); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- An generație -->
    <div class="md:col-span-1">
      <label class="block mb-1 text-xs font-medium text-slate-600">An generație</label>
      <select name="gen_year" class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-700 focus:border-transparent">
        <option value="" class="text-xs">— Oricare —</option>
        <?php foreach ($years_list as $yr): ?>
          <option value="<?php echo esc_attr($yr); ?>" <?php selected($gen_year===$yr); ?>><?php echo esc_html($yr); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Județ -->
    <div class="md:col-span-2">
      <label class="block mb-1 text-xs font-medium text-slate-600">Județ</label>
      <select name="county" class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-700 focus:border-transparent">
        <option value="" class="text-xs">— Orice județ —</option>
        <?php foreach ($county_list as $nm): ?>
          <option value="<?php echo esc_attr($nm); ?>" <?php selected($county_f===$nm); ?>><?php echo esc_html($nm); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- An program (nou) -->
    <div class="md:col-span-1">
      <label class="block mb-1 text-xs font-medium text-slate-600">An program</label>
      <select name="an_program" class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-700 focus:border-transparent">
        <option value="" class="text-xs">— Toate —</option>
        <?php foreach ($an_program_list as $ap): ?>
          <option value="<?php echo esc_attr($ap); ?>" <?php selected($an_program===$ap); ?>><?php echo esc_html($ap); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- RSOI (nou) -->
    <div class="md:col-span-1">
      <label class="block mb-1 text-xs font-medium text-slate-600">RSOI</label>
      <select name="rsoi" class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-700 focus:border-transparent">
        <option value="" class="text-xs">— Toate —</option>
        <?php foreach ($rsoi_list as $rv): ?>
          <option value="<?php echo esc_attr($rv); ?>" <?php selected($rsoi===$rv); ?>><?php echo esc_html($rv); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Export + Perpage -->
    <div class="flex items-end gap-2 md:col-span-2 md:justify-end">
      <button type="submit"
              class="inline-flex items-center justify-center w-full gap-2 px-3 py-2 text-sm font-medium text-white shadow-sm rounded-xl bg-emerald-600 hover:bg-emerald-700">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M21 21 15 15m2-5a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"/></svg>
        Filtrează
      </button>
    </div>
  </form>
</section>

<section class="px-6 pb-8">
  <div class="relative overflow-x-auto bg-white border shadow-sm rounded-2xl border-slate-200">
    <table id="prof-table" class="relative w-full text-sm table-auto">
      <thead class="sticky top-0 bg-sky-800 backdrop-blur">
        <tr class="text-white">
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200">Profesor</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="cod">Cod SLF</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="statut">Statut</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="nivel">Nivel</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="anprog">An program</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="rsoi">RSOI</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="teach">Teach</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="materie">Materie</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="generatii">Generații</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="elevi">Elevi</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="scoli">Școli</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="judet">Județ</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="last">Ultima activitate</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="reg">Înregistrare</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200">Acțiuni</th>
        </tr>
      </thead>
      <tbody class="[&>tr:nth-child(odd)]:bg-slate-50/40">
        <?php if (!empty($profesori)): ?>
          <?php foreach ($profesori as $u): ?>
            <?php
              $pid   = (int)$u->ID;
              $email = $u->user_email;

              $name  = trim(($u->first_name ?: $u->display_name).' '.($u->last_name ?: ''));
              if ($name === '') $name = $u->display_name ?: $u->user_login;

              $search_blob = strtolower( remove_accents( trim($name.' '.$email) ) );

              // avatar din usermeta 'profile_image'
              $profile_image_id = get_user_meta($pid, 'profile_image', true);
              $avatar_url = $profile_image_id ? wp_get_attachment_image_url($profile_image_id, 'thumbnail') : null;

              $cod   = get_user_meta($pid, 'cod_slf', true);
              $nivel_val = get_user_meta($pid, 'nivel_predare', true);
              $mat   = get_user_meta($pid, 'materia_predata', true);

              $an_prog = get_user_meta($pid, 'an_program', true);
              $rsoi_v  = get_user_meta($pid, 'segment_rsoi', true);
              $teach_v = get_user_meta($pid, 'generatie', true);

              $stat  = get_user_meta($pid, 'user_status_profesor', true);
              if ($stat==='') $stat = get_user_meta($pid, 'statut_prof', true);
              if ($stat==='') $stat = get_user_meta($pid, 'statut', true);

              $gens  = $gens_by_prof[$pid] ?? [];
              $elevi = (int)($students_count[$pid] ?? 0);

              // scoli
              $sids  = get_user_meta($pid, 'assigned_school_ids', true);
              $sids  = is_array($sids) ? array_filter(array_map('intval',$sids)) : [];
              $school_count = count($sids);

              // nume scoli (doar dacă avem nevoie de tooltip)
              $school_names = [];
              if ($school_count) {
                $in3 = implode(',', array_fill(0, $school_count, '%d'));
                $rows = $wpdb->get_results($wpdb->prepare("
                  SELECT id, name FROM {$tbl_schools}
                  WHERE id IN ($in3)
                ", ...$sids));
                foreach ($rows as $r) $school_names[] = $r->name;
              }
              $school_title = $school_names ? esc_attr(implode(', ', $school_names)) : '';

              // judete
              $ctys  = $counties_by_prof[$pid] ?? [];
              $cty_main = $ctys ? $ctys[0] : '—';
              $cty_title = $ctys && count($ctys)>1 ? esc_attr(implode(', ', $ctys)) : '';

              // ultima activitate
              $last_login_ts = get_user_meta($pid, 'last_login', true);
              if (!$last_login_ts) $last_login_ts = get_user_meta($pid, 'last_activity', true);
              if (!$last_login_ts) $last_login_ts = get_user_meta($pid, 'last_seen', true);
              $last_login_ts = $last_login_ts ? (int)$last_login_ts : 0;

              $registered_ts = $u->user_registered ? strtotime($u->user_registered) : 0;
            ?>
            <tr class="transition-colors border-b border-slate-200 hover:bg-slate-50 odd:bg-white even:bg-slate-200" data-search="<?php echo esc_attr($search_blob); ?>">
              <!-- Profesor -->
              <td class="px-3 py-3 align-center">
                <div class="flex items-center gap-3">
                  <?php if ($avatar_url): ?>
                    <img src="<?php echo esc_url($avatar_url); ?>" alt="" class="object-cover rounded-full shadow-sm w-9 h-9">
                  <?php else: ?>
                    <span class="inline-flex items-center justify-center text-[11px] font-bold text-white rounded-full shadow-sm w-9 h-9 bg-gradient-to-br from-slate-600 to-slate-800">
                      <?php echo esc_html(es_initials($u)); ?>
                    </span>
                  <?php endif; ?>
                  <div class="min-w-0">
                    <a href="<?php echo esc_url( home_url('/panou/profesor/'.$pid) ); ?>"
                       class="font-semibold truncate text-slate-900 hover:text-emerald-700">
                      <?php echo esc_html($name); ?>
                    </a>
                    <div class="text-xs text-slate-600">
                      <a class="hover:underline" href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a>
                      <span class="ml-2 text-slate-400">•</span>
                      <span class="ml-2 font-mono text-[11px] text-slate-500">#<?php echo (int)$pid; ?></span>
                    </div>
                  </div>
                </div>
              </td>

              <!-- Cod SLF -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="cod"><?php echo $cod!=='' ? esc_html($cod) : '—'; ?></td>

              <!-- Statut -->
              <td class="px-3 py-3 text-center align-center" data-col="statut"><?php echo es_statut_badge($stat); ?></td>

              <!-- Nivel -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="nivel"><?php echo esc_html(es_level_label($nivel_val)); ?></td>

              <!-- An program -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="anprog"><?php echo $an_prog!=='' ? esc_html($an_prog) : '—'; ?></td>

              <!-- RSOI -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="rsoi"><?php echo $rsoi_v!=='' ? esc_html($rsoi_v) : '—'; ?></td>

              <!-- Teach -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="teach"><?php echo $teach_v!=='' ? esc_html($teach_v) : '—'; ?></td>

              <!-- Materie -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="materie"><?php echo $mat!=='' ? esc_html($mat) : '—'; ?></td>

              <!-- Generații -->
              <td class="px-3 py-3 align-center" data-col="generatii">
                <?php if ($gens): ?>
                  <div class="flex flex-wrap gap-2">
                    <?php foreach ($gens as $g): ?>
                      <a href="<?php echo esc_url( home_url('/panou/generatia/'.$g->id) ); ?>"
                         class="inline-flex items-center gap-1 px-2 py-1 text-xs rounded-full bg-slate-100 text-slate-800 ring-1 ring-slate-200 hover:bg-slate-200">
                        <span>#<?php echo (int)$g->id; ?></span>
                        <span class="text-slate-500">·</span>
                        <span><?php echo esc_html(es_level_label($g->level)); ?></span>
                        <span class="text-slate-500">·</span>
                        <span><?php echo esc_html(es_normalize_year_str($g->year ?? '')); ?></span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <span class="text-slate-500">—</span>
                <?php endif; ?>
              </td>

              <!-- Elevi -->
              <td class="px-3 py-3 text-center align-center text-slate-900" data-col="elevi"><?php echo (int)$elevi; ?></td>

              <!-- Școli -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="scoli">
                <?php if ($school_count): ?>
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded-full bg-sky-50 text-sky-800 ring-1 ring-sky-200" title="<?php echo $school_title; ?>">
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 1.5 9 12 15 22.5 9 12 3Z"/><path d="M3 10.5v5.25L12 21l9-5.25V10.5" opacity=".4"/></svg>
                    <?php echo (int)$school_count; ?> școli
                  </span>
                <?php else: ?>
                  <span class="text-slate-500">—</span>
                <?php endif; ?>
              </td>

              <!-- Județ -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="judet" title="<?php echo $cty_title; ?>">
                <?php echo esc_html($cty_main); ?>
                <?php if ($ctys && count($ctys)>1): ?>
                  <span class="text-xs text-slate-500">(+<?php echo count($ctys)-1; ?>)</span>
                <?php endif; ?>
              </td>

              <!-- Ultima activitate -->
              <td class="px-3 py-3 text-xs max-w-[80px] align-center text-slate-700" data-col="last">
                <?php echo esc_html(es_format_dt($last_login_ts)); ?>
              </td>

              <!-- Înregistrare -->
              <td class="px-3 py-3 text-xs max-w-[80px] align-center text-slate-700" data-col="reg">
                <?php echo esc_html(es_format_dt($registered_ts)); ?>
              </td>

              <!-- Acțiuni -->
              <td class="px-3 py-3 text-center align-center">
                <div class="flex items-center justify-center gap-2">
                  <a href="<?php echo esc_url( home_url('/panou/profesor/'.$pid) ); ?>"
                    class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-white rounded-full shadow-sm bg-emerald-600 hover:bg-emerald-700">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5.33 0-8 2.667-8 6a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1c0-3.333-2.67-6-8-6Z"/></svg>
                    Profil
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="15" class="px-4 py-6 text-center text-slate-500">Nu s-au găsit profesori pentru criteriile selectate.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginare -->
  <?php if ($total_pages > 1): ?>
    <div class="flex items-center justify-between mt-4">
      <div class="text-sm text-slate-600">
        Afișezi <strong><?php echo (int)min($total, $offset + $perpage); ?></strong> din <strong><?php echo (int)$total; ?></strong> profesori.
      </div>
      <div class="flex items-center gap-2">
        <?php
          $mk = function($p) use ($base_url){ return esc_url(add_query_arg('paged', max(1,(int)$p), $base_url)); };
        ?>
        <a href="<?php echo $mk($paged-1); ?>" class="px-3 py-1 text-sm border rounded-lg <?php echo $paged<=1?'pointer-events-none opacity-40':''; ?>">« Înapoi</a>
        <span class="px-2 text-sm text-slate-700">Pagina <?php echo (int)$paged; ?> / <?php echo (int)$total_pages; ?></span>
        <a href="<?php echo $mk($paged+1); ?>" class="px-3 py-1 text-sm border rounded-lg <?php echo $paged>=$total_pages?'pointer-events-none opacity-40':''; ?>">Înainte »</a>
      </div>
    </div>
  <?php endif; ?>
</section>

<?php if (!empty($_GET['debug'])): ?>
  <div class="max-w-5xl p-6 mx-auto my-6 text-xs border border-yellow-200 bg-yellow-50 rounded-xl">
    <strong>DEBUG</strong>
    <pre><?php echo esc_html(print_r([
      'role'=> $is_admin? 'ADMIN' : ($is_tutor?'TUTOR':'OTHER'),
      'uid' => $uid,
      'filters'=> compact('s','nivel','statut','gen_year','county_f','an_program','rsoi','perpage','paged'),
      'total_all'=> count($all_prof),
      'total_filtered'=> $total
    ], true)); ?></pre>
  </div>
<?php endif; ?>


<script>
(function(){
  const $q     = document.getElementById('prof-q');
  const $panel = document.getElementById('prof-suggest');
  const $table = document.getElementById('prof-table');
  if(!$q || !$panel || !$table) return;

  const rows = Array.from($table.querySelectorAll('tbody tr[data-search]'));

  // Previi submit la Enter în inputul de căutare (că filtrăm live)
  $q.addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); } });

  // Normalizare pentru match fără diacritice
  const norm = (s)=> (s||'').toString().toLowerCase()
                   .normalize('NFD').replace(/\p{Diacritic}/gu,'').trim();

  // FILTRARE LOCALĂ (ascunde/arată rânduri în tabel)
  function filterRows(){
    const q = norm($q.value);
    rows.forEach(tr=>{
      const hay = norm(tr.getAttribute('data-search') || '');
      tr.style.display = (!q || hay.includes(q)) ? '' : 'none';
    });
  }

  // SUGESTII (AJAX -> 'edu_search_teachers', apoi fallback local)
  let tipTimer = null;
  $q.addEventListener('input', ()=>{
    clearTimeout(tipTimer);
    filterRows(); // filtrăm imediat ce tastezi
    const q = $q.value.trim();
    if(q.length < 2){ hideSuggest(); return; }
    tipTimer = setTimeout(()=> loadSuggestions(q), 180);
  });

  document.addEventListener('click', (e)=>{
    if(!e.target.closest('#prof-suggest') && e.target !== $q) hideSuggest();
  });
  $q.addEventListener('keydown', (e)=>{ if(e.key==='Escape') hideSuggest(); });

  function hideSuggest(){ $panel.innerHTML=''; $panel.classList.add('hidden'); }
  function showSuggest(items){
    if(!items || !items.length){ hideSuggest(); return; }
    $panel.innerHTML = items.map(it => (
      `<button type="button" data-text="${escapeHtml(it.text)}"
               class="flex items-center justify-between w-full px-3 py-1.5 text-left rounded hover:bg-slate-50">
         <span class="text-sm">${escapeHtml(it.text)}</span>
       </button>`
    )).join('');
    $panel.classList.remove('hidden');
    Array.from($panel.querySelectorAll('button[data-text]')).forEach(btn=>{
      btn.addEventListener('click', ()=>{
        $q.value = btn.getAttribute('data-text') || '';
        hideSuggest();
        filterRows();
      });
    });
  }

  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

  async function loadSuggestions(query){
    // 1) AJAX către backend dacă există acțiunea 'edu_search_teachers'
    try{
      const fd = new FormData();
      fd.append('action','edu_search_teachers');   // endpoint opțional (vezi mai jos)
      fd.append('nonce', $q.dataset.nonce || '');
      fd.append('q', query);

      const r = await fetch($q.dataset.ajax || '', { method:'POST', body:fd, credentials:'same-origin' });
      const data = await r.json();
      if(Array.isArray(data) && data.length){
        // așteptăm { id, text } — text = "Nume — email"
        showSuggest(data.slice(0, 10));
        return;
      }
    }catch(_){/* cade pe fallback*/}

    // 2) fallback local: sugerează din rândurile vizibile
    const qn = norm(query);
    const seen = new Set(); const out = [];
    for(const tr of rows){
      const nameCell = tr.querySelector('td:nth-child(1)'); // prima col e „Profesor”
      const label = (nameCell?.innerText || '').trim();
      const email = (nameCell?.querySelector('a[href^="mailto:"]')?.textContent || '').trim();
      const blob  = norm(label+' '+email);
      if(blob.includes(qn) && !seen.has(blob)){
        out.push({ text: (email ? `${label} — ${email}` : label) });
        seen.add(blob);
        if(out.length>=10) break;
      }
    }
    showSuggest(out);
  }

  // rulează o dată la încărcare (dacă aveai s din GET)
  filterRows();
})();

// — Nivel multiselect dropdown
(function(){
  const wrap     = document.getElementById('nivel-ms-wrap');
  const trigger  = document.getElementById('nivel-ms-trigger');
  const dropdown = document.getElementById('nivel-ms-dropdown');
  const inputs   = document.getElementById('nivel-ms-inputs');
  if (!wrap || !trigger || !dropdown || !inputs) return;

  const OPTIONS = <?php echo wp_json_encode($nivel_options); ?>;
  let selected = <?php echo wp_json_encode(array_values($nivel_arr)); ?>;

  function render(){
    // Hidden inputs
    inputs.innerHTML = selected.map(v => `<input type="hidden" name="nivel[]" value="${v}">`).join('');

    // Tags in trigger
    const tags = selected.map(v => {
      const lab = OPTIONS[v] || v;
      return `<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 nivel-ms-tag" data-val="${v}">
        ${lab}<button type="button" class="nivel-ms-remove hover:text-red-600">&times;</button>
      </span>`;
    }).join('');
    const arrow = '<svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';
    trigger.innerHTML = selected.length
      ? tags + arrow
      : '<span class="nivel-ms-placeholder text-slate-400">— Oricare —</span>' + arrow;

    // Checkboxes in dropdown
    dropdown.querySelectorAll('.nivel-ms-opt').forEach(opt => {
      const v = opt.dataset.val;
      const isOn = selected.includes(v);
      const box = opt.querySelector('.nivel-ms-check');
      if (isOn) {
        box.className = 'inline-flex items-center justify-center w-4 h-4 rounded border border-sky-600 bg-sky-600 nivel-ms-check';
        box.innerHTML = '<svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
      } else {
        box.className = 'inline-flex items-center justify-center w-4 h-4 rounded border border-slate-300 bg-white nivel-ms-check';
        box.innerHTML = '';
      }
    });
  }

  // Toggle dropdown
  trigger.addEventListener('click', (e) => {
    if (e.target.closest('.nivel-ms-remove')) return;
    dropdown.classList.toggle('hidden');
  });

  // Remove tag
  trigger.addEventListener('click', (e) => {
    const btn = e.target.closest('.nivel-ms-remove');
    if (!btn) return;
    const tag = btn.closest('.nivel-ms-tag');
    const val = tag?.dataset.val;
    if (val) {
      selected = selected.filter(v => v !== val);
      render();
    }
  });

  // Toggle option in dropdown
  dropdown.addEventListener('click', (e) => {
    const opt = e.target.closest('.nivel-ms-opt');
    if (!opt) return;
    const val = opt.dataset.val;
    if (selected.includes(val)) {
      selected = selected.filter(v => v !== val);
    } else {
      selected.push(val);
    }
    render();
  });

  // Close on outside click
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#nivel-ms-wrap')) dropdown.classList.add('hidden');
  });
})();

// — Column toggle (persistă în localStorage)
(function(){
  const toggleBtnBar = document.getElementById('cols-toggle-btn');
  if(!toggleBtnBar) return;
  const toggleBar = document.getElementById('cols-section');
  if(!toggleBar) return;
  const KEY = 'lista_prof_cols_v1';
  const toggles = document.querySelectorAll('#cols-toggle input[type="checkbox"][data-col]');
  const table   = document.getElementById('prof-table');
  if(!table) return;

  toggleBtnBar.addEventListener('click', () => {
    toggleBar.classList.toggle('hidden');
  });

  const apply = (state) => {
    toggles.forEach(cb => {
      const k = cb.getAttribute('data-col');
      const on = state[k] !== false;
      cb.checked = on;
      table.querySelectorAll('th[data-col="'+k+'"], td[data-col="'+k+'"]').forEach(el => {
        el.style.display = on ? '' : 'none';
      });
    });
  };

  let state = {};
  try { state = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch(e){ state = {}; }
  apply(state);

  toggles.forEach(cb => {
    cb.addEventListener('change', () => {
      const k = cb.getAttribute('data-col');
      state[k] = cb.checked;
      localStorage.setItem(KEY, JSON.stringify(state));
      apply(state);
    });
  });
})();
</script>