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
function es_user_fullname($u){
  $name = trim(($u->first_name ?? '').' '.($u->last_name ?? ''));
  if ($name === '') $name = $u->display_name ?: $u->user_login;
  return $name;
}

/* =============== Filtre (UI & logic) =============== */

$s          = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
$nivel_arr  = isset($_GET['nivel']) ? array_filter(array_map('sanitize_text_field', (array)wp_unslash($_GET['nivel']))) : [];
$statut     = isset($_GET['statut']) ? sanitize_text_field(wp_unslash($_GET['statut'])) : '';
$gen_year   = isset($_GET['gen_year']) ? sanitize_text_field(wp_unslash($_GET['gen_year'])) : '';
$county_f   = isset($_GET['county']) ? sanitize_text_field(wp_unslash($_GET['county'])) : '';
$an_program = isset($_GET['an_program']) ? sanitize_text_field(wp_unslash($_GET['an_program'])) : '';
$rsoi       = isset($_GET['rsoi']) ? sanitize_text_field(wp_unslash($_GET['rsoi'])) : '';
$tutor_f    = isset($_GET['tutor']) ? (int)$_GET['tutor'] : 0;

$perpage    = max(5, min(200, (int)($_GET['perpage'] ?? 25)));
$paged      = max(1, (int)($_GET['paged'] ?? 1));

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
if ($tutor_f > 0) {
  $meta_query[] = ['key' => 'assigned_tutor_id', 'value' => $tutor_f, 'compare' => '=', 'type' => 'NUMERIC'];
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
    $yr = trim((string)$g->year);
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
  $nivel_codes = array_filter(array_map('es_normalize_level_code', $nivel_arr));
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
        if ((string)$g->year === (string)$gen_year) { $has = true; break; }
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

// Pentru export CSV
$export_url = add_query_arg([
  'action' => 'edus_export_teachers_csv',
  'nonce'  => wp_create_nonce('edus_export_teachers_csv'),
  // păstrăm filtrele active
  's'          => $s,
  'nivel'      => $nivel_arr,
  'statut'     => $statut,
  'gen_year'   => $gen_year,
  'county'     => $county_f,
  'an_program' => $an_program,
  'rsoi'       => $rsoi,
  'tutor'      => $tutor_f,
], admin_url('admin-post.php'));

// Pregătim date pentru dropdown-uri
$edu_nonce = wp_create_nonce('edu_nonce');
$tutor_args = ['role'=>'tutor','orderby'=>'display_name','order'=>'ASC','number'=>-1];
$all_tutors = get_users($tutor_args);

// Counties & cities for school cascade in modal
$all_counties_modal = $wpdb->get_results("SELECT id, name FROM {$tbl_counties} ORDER BY name ASC");
$all_cities_modal   = $wpdb->get_results("SELECT id, name, county_id FROM {$tbl_cities} ORDER BY name ASC");

$prof_status = [
  'in_asteptare'         => 'În așteptare',
  'activ'                => 'Activ',
  'drop-out'             => 'Drop-out',
  'eliminat'             => 'Eliminat',
  'concediu_maternitate' => 'Concediu maternitate',
  'concediu_studii'      => 'Concediu studii',
];
$nivel_opts = ['prescolar'=>'Preșcolar','primar'=>'Primar','gimnazial'=>'Gimnazial','liceu'=>'Liceu'];
$statut_prof_opts = [
  'Suplinitor necalificat','Suplinitor calificat','Titular',
  'Titular pe viabilitatea postului','Director','Inspector'
];
$calificare_opts = ['Calificat','Necalificat'];
$experienta_opts = ['2 ani sau mai putin','3-5 ani','mai mult de 5 ani'];
$materii_default = [
  'Educator','Învățător','Română','Engleză','Franceză','Germană','Latină',
  'Geografie','Istorie','Biologie','Fizică','Chimie','Matematică',
  'Educație tehnologică','TIC','Cultură civică & Educație civică',
  'Educație socială','Religie','Arte','Educație plastică','Educație muzicală',
  'Educație fizică și sport','Învățământ special','Consilier școlar','Turcă','Alta'
];

/* =============== UI: Filtre pe un rând + Export =============== */

// build query-string base (fără paged)
$qs = $_GET; unset($qs['paged'], $qs['export']);
$base_url = esc_url(add_query_arg($qs, remove_query_arg(['paged','export'])));

// ——— Definim coloanele (chei pentru toggle). Notă: „profesor” e întotdeauna vizibil.
$COLS = [
  'tutor'       => 'Tutor',
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
  'act1'        => 'Profil',
];


?>

<section class="sticky top-0 z-20 border-b inner-submenu bg-slate-800 border-slate-200">
  <div class="relative z-20 flex items-center justify-between px-2 py-2 gap-x-2 mobile:gap-x-1">
    <div class="flex items-center justify-start">
      <button id="es-open-add-prof" type="button"
      class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-white rounded-md rounded-tl-xl bg-emerald-600 hover:bg-emerald-700">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M11 11V5a1 1 0 1 1 2 0v6h6a1 1 0 1 1 0 2h-6v6a1 1 0 1 1-2 0v-6H5a1 1 0 1 1 0-2h6Z"/></svg>
        Profesor
      </button>

      <button id="es-open-add-gen" type="button"
      class="inline-flex items-center gap-2 px-3 py-2 ml-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a1 1 0 0 1 1 1v8h8a1 1 0 1 1 0 2h-8v8a1 1 0 1 1-2 0v-8H3a1 1 0 1 1 0-2h8V3a1 1 0 0 1 1-1Z"/></svg>
        Generație
      </button>
    </div>

    <div class="flex items-center justify-end gap-x-2">
      <button id="cols-toggle-btn" type="button" 
        class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
        <svg class="w-3 h-3 mobile:w-5 mobile:h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        <span class="mobile:hidden">Arata coloane </span>
      </button>

      <a href="<?php echo esc_url($export_url); ?>" target="_blank" rel="noopener noreferrer"
        class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
        <svg class="w-3 h-3 mobile:w-5 mobile:h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
        <span class="mobile:hidden">Export CSV</span>
      </a>

      <a href="../documentatie/#profesori" target="_blank" rel="noopener noreferrer"
        class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-3 h-3 mobile:w-5 mobile:h-5">
          <path d="M12 .75a8.25 8.25 0 0 0-4.135 15.39c.686.398 1.115 1.008 1.134 1.623a.75.75 0 0 0 .577.706c.352.083.71.148 1.074.195.323.041.6-.218.6-.544v-4.661a6.714 6.714 0 0 1-.937-.171.75.75 0 1 1 .374-1.453 5.261 5.261 0 0 0 2.626 0 .75.75 0 1 1 .374 1.452 6.712 6.712 0 0 1-.937.172v4.66c0 .327.277.586.6.545.364-.047.722-.112 1.074-.195a.75.75 0 0 0 .577-.706c.02-.615.448-1.225 1.134-1.623A8.25 8.25 0 0 0 12 .75Z" />
          <path fill-rule="evenodd" d="M9.013 19.9a.75.75 0 0 1 .877-.597 11.319 11.319 0 0 0 4.22 0 .75.75 0 1 1 .28 1.473 12.819 12.819 0 0 1-4.78 0 .75.75 0 0 1-.597-.876ZM9.754 22.344a.75.75 0 0 1 .824-.668 13.682 13.682 0 0 0 2.844 0 .75.75 0 1 1 .156 1.492 15.156 15.156 0 0 1-3.156 0 .75.75 0 0 1-.668-.824Z" clip-rule="evenodd" />
        </svg>
        <span class="mobile:hidden">Documentatie</span>
      </a>
    </div>
  </div>
</section>

<!-- Modal Add/Edit profesor -->
<div id="es-prof-modal" class="fixed inset-0 z-[100] hidden">
  <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>
  <div class="relative flex items-start justify-center mt-[2.5vh]" style="height:95vh">
    <div class="flex flex-col w-full max-w-5xl mx-4 bg-white border shadow-xl rounded-2xl border-slate-200" style="max-height:95vh">
      <div class="flex items-center justify-between px-5 py-4 border-b shrink-0 bg-slate-50 border-slate-200 rounded-t-2xl">
        <h3 id="es-prof-modal-title" class="text-base font-semibold text-slate-900">Adaugă profesor</h3>
        <button type="button" id="es-close-prof" class="p-2 text-red-700 hover:text-slate-700">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <form id="es-prof-form" class="flex flex-col flex-1 min-h-0" enctype="multipart/form-data">
        <input type="hidden" name="nonce" value="<?php echo esc_attr($edu_nonce); ?>">
        <input type="hidden" name="user_role" value="profesor">
        <input type="hidden" id="es-user-id" name="user_id" value="">
        <?php if ($is_tutor && !$is_admin): ?>
          <input type="hidden" name="assigned_tutor_id" value="<?php echo (int)$uid; ?>">
        <?php endif; ?>

        <div class="flex-1 px-5 py-4 overflow-y-auto">
          <!-- Row 1: Cod SLF, Segment RSOI, Generatie (Teach), An program — 4 cols -->
          <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Cod SLF</label>
              <input name="cod_slf" type="text" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
            </div>
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Segment RSOI</label>
              <select name="segment_rsoi" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                <option value="">— Selectează —</option>
                <option value="ED">ED</option>
                <option value="YP">YP</option>
                <option value="CC">CC</option>
              </select>
            </div>
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Generație (Teach)</label>
              <input name="generatie" type="text" placeholder="ex: G12" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
            </div>
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">An program</label>
              <select name="an_program" id="es-an-program" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                <option value="">— Selectează —</option>
                <option value="An 1">An 1</option>
                <option value="An 2">An 2</option>
                <option value="ALU">ALU</option>
                <option value="Extern">Extern</option>
                <option value="Other">Other</option>
              </select>
            </div>
          </div>

          <!-- Remaining rows: 3 cols -->
          <div class="grid grid-cols-1 gap-4 mt-4 md:grid-cols-3">
            <!-- Prenume / Nume / Email -->
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Prenume</label>
              <input name="first_name" type="text" required class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
            </div>
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Nume</label>
              <input name="last_name" type="text" required class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
            </div>
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Email (va fi și user_login)</label>
              <div class="relative">
                <input name="email" id="es-prof-email" type="email" required class="w-full px-3 py-2 pr-9 text-sm bg-white border rounded-xl border-slate-300">
                <span id="es-email-ok" class="absolute hidden -translate-y-1/2 right-3 top-1/2 text-emerald-500">
                  <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4-4a.75.75 0 011.06-1.06l3.366 3.365 7.506-9.879a.75.75 0 011.052-.143z" clip-rule="evenodd"/></svg>
                </span>
              </div>
              <p id="es-email-err" class="hidden mt-1 text-xs text-rose-600"></p>
            </div>

            <!-- Telefon / Status / Nivel -->
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Telefon</label>
              <input name="phone" type="text" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
            </div>
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Status profesor</label>
              <select name="user_status_profesor" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                <option value="">— Selectează —</option>
                <?php foreach ($prof_status as $k=>$lab): ?>
                  <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($lab); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Nivel predare</label>
              <select name="nivel_predare" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                <option value="">— Selectează —</option>
                <?php foreach ($nivel_opts as $k=>$lab): ?>
                  <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($lab); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Materie / Materie altă / Statut -->
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Materia predată</label>
              <select id="es-materia" name="materia_predata" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                <option value="">— Selectează —</option>
                <?php foreach ($materii_default as $m): ?>
                  <option value="<?php echo esc_attr($m); ?>"><?php echo esc_html($m); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div id="es-materia-alta-wrap" class="hidden">
              <label class="block mb-1 text-xs font-medium text-slate-600">Materia (altă)</label>
              <input id="es-materia-alta" name="materia_alta" type="text" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
            </div>
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Statut</label>
              <select name="statut_prof" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                <option value="">— Selectează —</option>
                <?php foreach ($statut_prof_opts as $st): ?>
                  <option value="<?php echo esc_attr($st); ?>"><?php echo esc_html($st); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Calificare / Experienta / Cohorte -->
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Calificare</label>
              <select name="calificare" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                <option value="">— Selectează —</option>
                <?php foreach ($calificare_opts as $st): ?>
                  <option value="<?php echo esc_attr($st); ?>"><?php echo esc_html($st); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Experiență</label>
              <select name="experienta" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                <option value="">— Selectează —</option>
                <?php foreach ($experienta_opts as $st): ?>
                  <option value="<?php echo esc_attr($st); ?>"><?php echo esc_html($st); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Cohorte</label>
              <select name="cohorte" id="es-cohorte" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                <option value="">— Selectează —</option>
                <option value="CORE">CORE</option>
                <option value="Scoli Strategice">Școli Strategice</option>
              </select>
            </div>

            <!-- Tutor / Mentori -->
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Tutor coordonator</label>
              <select name="assigned_tutor_id" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300" <?php echo ($is_tutor && !$is_admin) ? 'disabled' : '';?>>
                <option value="">— Selectează —</option>
                <?php foreach ($all_tutors as $t): ?>
                  <option value="<?php echo (int)$t->ID; ?>" <?php echo ($is_tutor && !$is_admin && (int)$t->ID === $uid) ? 'selected' : ''; ?>>
                    <?php echo esc_html($t->display_name ?: ($t->first_name.' '.$t->last_name)); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Mentor SEL</label>
              <select name="mentor_sel" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                <option value="">— Selectează —</option>
                <?php foreach ($all_tutors as $t): ?>
                  <option value="<?php echo (int)$t->ID; ?>"><?php echo esc_html($t->display_name); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Mentor LIT</label>
              <select name="mentor_literatie" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                <option value="">— Selectează —</option>
                <?php foreach ($all_tutors as $t): ?>
                  <option value="<?php echo (int)$t->ID; ?>"><?php echo esc_html($t->display_name); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block mb-1 text-xs font-medium text-slate-600">Mentor NUM</label>
              <select name="mentor_numeratie" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                <option value="">— Selectează —</option>
                <?php foreach ($all_tutors as $t): ?>
                  <option value="<?php echo (int)$t->ID; ?>"><?php echo esc_html($t->display_name); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Școli atribuite: county -> city -> school cascade -->
            <div class="md:col-span-3">
              <label class="block mb-2 text-xs font-medium text-slate-600">Școli atribuite</label>
              <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <div>
                  <label class="block mb-1 text-xs text-slate-500">Județ</label>
                  <select id="es-school-county" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                    <option value="">— Toate județele —</option>
                    <?php foreach ($all_counties_modal as $c): ?>
                      <option value="<?php echo (int)$c->id; ?>"><?php echo esc_html($c->name); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="relative" id="es-city-dd-wrap">
                  <label class="block mb-1 text-xs text-slate-500">Oraș</label>
                  <div id="es-city-dd-trigger" class="flex items-center justify-between w-full px-3 py-2 text-sm bg-white border cursor-pointer rounded-xl border-slate-300 hover:border-slate-400">
                    <span id="es-city-dd-label" class="text-slate-500">— Selectează județ —</span>
                    <svg class="w-4 h-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                  </div>
                  <input type="hidden" id="es-school-city" name="_city_filter" value="">
                  <div id="es-city-dropdown" class="hidden absolute z-30 w-full mt-1 bg-white border shadow-lg rounded-xl border-slate-200" style="min-width:220px">
                    <div class="p-2 border-b border-slate-100">
                      <input id="es-city-dd-search" type="text" placeholder="Caută oraș..."
                             class="w-full px-3 py-1.5 text-sm bg-white border rounded-lg border-slate-300 focus:outline-none focus:ring-1 focus:ring-sky-500">
                    </div>
                    <div id="es-city-dd-list" class="overflow-y-auto max-h-48"></div>
                    <div id="es-city-dd-empty" class="hidden px-3 py-3 text-xs text-center text-slate-500">Niciun rezultat.</div>
                  </div>
                </div>
                <div class="relative" id="es-school-dd-wrap">
                  <label class="block mb-1 text-xs text-slate-500">Școală</label>
                  <div id="es-school-dd-trigger" class="flex items-center justify-between w-full px-3 py-2 text-sm bg-white border cursor-pointer rounded-xl border-slate-300 hover:border-slate-400">
                    <span class="text-slate-500">— Selectează școli —</span>
                    <svg class="w-4 h-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                  </div>
                  <div id="es-school-dropdown" class="hidden absolute z-30 w-full mt-1 bg-white border shadow-lg rounded-xl border-slate-200" style="min-width:320px">
                    <div class="p-2 border-b border-slate-100">
                      <input id="es-school-dd-search" type="text" placeholder="Caută școală..."
                             class="w-full px-3 py-1.5 text-sm bg-white border rounded-lg border-slate-300 focus:outline-none focus:ring-1 focus:ring-sky-500">
                    </div>
                    <div id="es-school-dd-list" class="overflow-y-auto max-h-48"></div>
                    <div id="es-school-dd-empty" class="hidden px-3 py-3 text-xs text-center text-slate-500">Niciun rezultat. Selectează un județ pentru a vedea școlile.</div>
                  </div>
                </div>
              </div>
              <div id="es-school-selected" class="mt-3 space-y-1"></div>
            </div>
          </div>

          <!-- Hidden fields (Imagine profil + reset) -->
          <input name="profile_image" type="file" accept="image/*" class="hidden" id="es-profile-image-hidden">
          <p id="es-current-avatar" class="hidden"></p>
        </div>

        <div class="flex items-center justify-end gap-2 px-5 py-4 border-t shrink-0 bg-white border-slate-200 rounded-b-2xl">
          <button type="button" id="es-cancel-prof" class="px-3 py-2 text-sm bg-white border rounded-xl hover:bg-slate-50 border-slate-300">Anulează</button>
          <button id="es-submit-prof" type="submit" class="px-3 py-2 text-sm text-white rounded-xl bg-emerald-600 hover:bg-emerald-700">
            Salvează
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL: Adaugă / Alocă generație (ADMIN ONLY UI) -->
<div id="es-gen-modal" class="fixed inset-0 z-[95] hidden">
  <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>
  <div class="relative max-w-2xl mx-auto my-10">
    <div class="mx-4 overflow-hidden bg-white border shadow-xl rounded-2xl border-slate-200">
      <div class="flex items-center justify-between px-5 py-4 border-b bg-slate-50 border-slate-200">
        <h3 id="es-gen-modal-title" class="text-base font-semibold text-slate-900">Adaugă generație</h3>
        <button type="button" id="es-close-gen" class="p-2 text-slate-500 hover:text-slate-700">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <form id="es-gen-form" class="px-5 py-4">
        <input type="hidden" name="nonce" value="<?php echo esc_attr($edu_nonce); ?>">
        <input type="hidden" id="es-gen-id" name="gen_id" value="">
        <input type="hidden" id="es-gen-professor-id" name="professor_id" value="">
        <!-- we send 'year' too, but server oricum îl recalculă/validatează -->
        <input type="hidden" id="es-gen-year" name="year" value="">

        <div class="grid grid-cols-1 gap-4">
          <!-- 1) Profesor (obligatoriu) -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Profesor</label>

            <!-- Când vii din „Alocă gen”, câmpul e BLOCAȚI -->
            <div class="flex gap-2" id="es-gen-prof-search-wrap">
              <input id="es-gen-prof-search" type="text" placeholder="Caută profesor după nume/email..."
                    class="flex-1 px-3 py-2 text-sm bg-white border rounded-xl border-slate-300"
                    data-ajax="<?php echo esc_url($ajax_url_teachers); ?>" data-nonce="<?php echo esc_attr($ajax_nonce_teachers); ?>">
              <button id="es-gen-prof-clear" type="button"
                      class="px-3 py-2 text-sm bg-white border rounded-xl hover:bg-slate-50 border-slate-300">Golește</button>
            </div>
            <div id="es-gen-prof-selected" class="mt-2 text-sm text-slate-700"></div>
            <div id="es-gen-prof-suggest" class="hidden mt-2 overflow-hidden border divide-y rounded-xl border-slate-200 divide-slate-200"></div>
          </div>

          <!-- 2) Nivel (derivat din profesor) -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Nivel (derivat din profesor)</label>
            <div class="px-3 py-2 text-sm border bg-slate-50 rounded-xl border-slate-200 text-slate-800" id="es-gen-level-ro">—</div>
          </div>

          <!-- 3) Clase disponibile pentru nivel (informativ) -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Clase disponibile (informativ)</label>
            <div id="es-gen-level-classes" class="flex flex-wrap gap-2">
              <!-- badges generate din JS -->
            </div>
          </div>

          <!-- 4) Nume generație -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Nume generație</label>
            <input name="name" id="es-gen-name" type="text" placeholder="ex: G12 — Clasa a V-a A"
                   required class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
          </div>

          <!-- 5) An generație (dropdown: anul curent + următorii 2) -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">An școlar</label>
            <select id="es-gen-year-display" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
            </select>
          </div>
        </div>

        <div class="flex items-center justify-end gap-2 pt-5 mt-5 border-t border-slate-200">
          <button type="button" id="es-cancel-gen" class="px-3 py-2 text-sm bg-white border rounded-xl hover:bg-slate-50 border-slate-300">Anulează</button>
          <button id="es-submit-gen" type="submit" class="px-3 py-2 text-sm text-white bg-indigo-600 rounded-xl hover:bg-indigo-700">
            Salvează generația
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Confirm Delete Modal -->
<div id="es-del-modal" class="fixed inset-0 z-[110] hidden">
  <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>
  <div class="relative max-w-md mx-auto my-8">
    <div class="mx-4 overflow-hidden bg-white border shadow-xl rounded-2xl border-slate-200">
      <div class="px-5 py-4 border-b bg-slate-50 border-slate-200">
        <h3 class="text-base font-semibold text-slate-900">Ștergere profesor</h3>
      </div>
      <div class="px-5 py-4 text-sm text-slate-700">
        Ești sigur(ă) că vrei să ștergi profesorul <strong id="es-del-name"></strong>? Acțiunea nu poate fi anulată.
      </div>
      <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-slate-200">
        <button type="button" id="es-cancel-del" class="px-3 py-2 text-sm bg-white border rounded-xl hover:bg-slate-50 border-slate-300">Anulează</button>
        <button type="button" id="es-confirm-del" class="px-3 py-2 text-sm text-white rounded-xl bg-rose-600 hover:bg-rose-700">Șterge</button>
      </div>
    </div>
  </div>
</div>

<section class="px-6 mt-4 mb-6 mobile:px-2">
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

    <!-- Nivel (multiselect) -->
    <div class="relative md:col-span-2" id="prof-nivel-wrap">
      <label class="block mb-1 text-xs font-medium text-slate-600">Nivel predare</label>
      <?php $prof_nivel_opts = ['prescolar'=>'Preșcolar','primar'=>'Primar','gimnazial'=>'Gimnazial','liceu'=>'Liceu']; ?>
      <div id="prof-nivel-inputs">
        <?php foreach ($nivel_arr as $v): ?>
          <input type="hidden" name="nivel[]" value="<?php echo esc_attr($v); ?>">
        <?php endforeach; ?>
      </div>
      <div id="prof-nivel-trigger" class="flex items-center gap-1 w-full min-h-[38px] px-3 py-1.5 text-sm bg-white border shadow-sm rounded-xl border-slate-300 cursor-pointer hover:border-slate-400 flex-wrap">
        <?php if (empty($nivel_arr)): ?>
          <span class="ms-placeholder text-slate-400">— Oricare —</span>
        <?php else: ?>
          <?php foreach ($nivel_arr as $v): ?>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 ms-tag" data-val="<?php echo esc_attr($v); ?>">
              <?php echo esc_html($prof_nivel_opts[$v] ?? $v); ?>
              <button type="button" class="ms-remove hover:text-red-600">&times;</button>
            </span>
          <?php endforeach; ?>
        <?php endif; ?>
        <svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
      </div>
      <div id="prof-nivel-dropdown" class="hidden absolute z-30 w-full mt-1 bg-white border shadow-lg rounded-xl border-slate-200 py-1" style="min-width:200px">
        <?php foreach ($prof_nivel_opts as $k => $lab): ?>
          <div class="ms-opt flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50" data-val="<?php echo esc_attr($k); ?>">
            <span class="inline-flex items-center justify-center w-4 h-4 rounded border ms-check <?php echo in_array($k, $nivel_arr, true) ? 'bg-sky-600 border-sky-600' : 'bg-white border-slate-300'; ?>">
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
        <?php foreach (['in_asteptare'=>'În așteptare','aprobat'=>'Aprobat','respins'=>'Respins'] as $k=>$lab): ?>
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
    <div class="md:col-span-1">
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

    <!-- Tutor -->
    <div class="md:col-span-1">
      <label class="block mb-1 text-xs font-medium text-slate-600">Tutor</label>
      <select name="tutor" class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-700 focus:border-transparent">
        <option value="" class="text-xs">— Toți —</option>
        <?php foreach ($all_tutors as $t): ?>
          <option value="<?php echo (int)$t->ID; ?>" <?php selected($tutor_f===(int)$t->ID); ?>><?php echo esc_html(es_user_fullname($t)); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Export + Perpage -->
    <div class="flex items-end gap-2 md:col-span-2 md:justify-end">
      <button type="submit"
              class="inline-flex items-center justify-center w-full gap-2 px-3 py-2 text-sm font-medium text-white shadow-sm rounded-xl bg-emerald-600 hover:bg-emerald-700">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
            <path fill-rule="evenodd" d="M3.792 2.938A49.069 49.069 0 0 1 12 2.25c2.797 0 5.54.236 8.209.688a1.857 1.857 0 0 1 1.541 1.836v1.044a3 3 0 0 1-.879 2.121l-6.182 6.182a1.5 1.5 0 0 0-.439 1.061v2.927a3 3 0 0 1-1.658 2.684l-1.757.878A.75.75 0 0 1 9.75 21v-5.818a1.5 1.5 0 0 0-.44-1.06L3.13 7.938a3 3 0 0 1-.879-2.121V4.774c0-.897.64-1.683 1.542-1.836Z" clip-rule="evenodd" />
        </svg>
        Filtrează
      </button>
    </div>
  </form>
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

<section class="px-6 pb-8">
  <div class="relative overflow-x-auto bg-white border shadow-sm rounded-2xl border-slate-200">
    <table class="relative w-full text-sm table-auto" id="prof-table">
      <thead class="sticky top-0 bg-sky-800 backdrop-blur">
        <tr class="text-white">
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200 cursor-pointer select-none hover:bg-sky-900" data-sort="profesor">
            <span class="inline-flex items-center gap-1">Profesor <svg class="w-3 h-3 opacity-40 es-sort-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/></svg></span>
          </th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="tutor">Tutor</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="cod">Cod SLF</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="statut">Statut</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200 cursor-pointer select-none hover:bg-sky-900" data-col="nivel" data-sort="nivel">
            <span class="inline-flex items-center gap-1">Nivel <svg class="w-3 h-3 opacity-40 es-sort-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/></svg></span>
          </th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200 cursor-pointer select-none hover:bg-sky-900" data-col="anprog" data-sort="anprog">
            <span class="inline-flex items-center gap-1">An program <svg class="w-3 h-3 opacity-40 es-sort-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"/></svg></span>
          </th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="rsoi">RSOI</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="teach">Teach</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="materie">Materie</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="elevi">Elevi</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="scoli">Școli</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="judet">Județ</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="last">Ultima activitate</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="reg">Înregistrare</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="generatii">Generații</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="act1">Profil</th>
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

              // nume scoli
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

              // Tutor (nume + link)
              $tid   = (int) get_user_meta($pid, 'assigned_tutor_id', true);
              $tutor_name = '—';
              $tutor_link = '';
              if ($tid > 0) {
                $tu = get_userdata($tid);
                if ($tu) {
                  $tutor_name = es_user_fullname($tu);
                  $tutor_link = home_url('/panou/tutor/'.$tid);
                }
              }

              // Generația „primară” pentru buton (cea mai recentă după year, apoi id)
              $primary_gen_id = 0;
              if (!empty($gens)) {
                usort($gens, function($a,$b){
                  $ay = (int)$a->year; $by = (int)$b->year;
                  if ($ay === $by) return $b->id <=> $a->id;
                  return $by <=> $ay;
                });
                $primary_gen_id = (int)$gens[0]->id;
              }
            ?>
            <tr class="transition-colors border-b border-slate-200 hover:bg-slate-50 odd:bg-white even:bg-slate-200" data-search="<?php echo esc_attr($search_blob); ?>" data-sort-profesor="<?php echo esc_attr(mb_strtolower($name)); ?>" data-sort-anprog="<?php echo esc_attr(mb_strtolower($an_prog ?: 'zzz')); ?>" data-sort-nivel="<?php echo esc_attr(mb_strtolower(es_level_label($nivel_val))); ?>">
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

              <!-- Tutor -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="tutor">
                <?php if ($tutor_link): ?>
                  <a href="<?php echo esc_url($tutor_link); ?>" class="font-medium hover:text-indigo-700"><?php echo esc_html($tutor_name); ?></a>
                <?php else: ?>
                  <span class="text-slate-500">—</span>
                <?php endif; ?>
              </td>

              <!-- Cod SLF -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="cod"><?php echo $cod!=='' ? esc_html($cod) : '—'; ?></td>

              <!-- Statut -->
              <td class="px-3 py-3 align-center" data-col="statut"><?php echo es_statut_badge($stat); ?></td>

              <!-- Nivel -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="nivel"><?php echo esc_html(es_level_label($nivel_val)); ?></td>

              <!-- An program (nou) -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="anprog"><?php echo $an_prog!=='' ? esc_html($an_prog) : '—'; ?></td>

              <!-- RSOI (nou) -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="rsoi"><?php echo $rsoi_v!=='' ? esc_html($rsoi_v) : '—'; ?></td>

              <!-- Teach (nou) -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="teach"><?php echo $teach_v!=='' ? esc_html($teach_v) : '—'; ?></td>

              <!-- Materie -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="materie"><?php echo $mat!=='' ? esc_html($mat) : '—'; ?></td>

              <!-- Elevi -->
              <td class="px-3 py-3 text-center align-center text-slate-900" data-col="elevi"><?php echo (int)$elevi; ?></td>

              <!-- Școli -->
              <td class="px-3 py-3 align-center text-slate-800" data-col="scoli">
                <?php if ($school_count): ?>
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded-full bg-sky-50 text-sky-800 ring-1 ring-sky-200" title="<?php echo $school_title; ?>">
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 1.5 9 12 15 22.5 9 12 3Z"/><path d="M3 10.5v5.25L12 21l9-5.25V10.5" opacity=".4"/></svg>
                    <?php echo (int)$school_count; ?>
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

              <!-- Ultima activitate (last_login/last_activity/last_seen) -->
              <td class="px-3 py-3 text-xs align-center text-slate-700 max-w-[80px]" data-col="last">
                <?php echo esc_html(es_format_dt($last_login_ts)); ?>
              </td>

              <!-- Înregistrare (user_registered) -->
              <td class="px-3 py-3 text-xs align-center text-slate-700 max-w-[80px]" data-col="reg">
                <?php echo esc_html(es_format_dt($registered_ts)); ?>
              </td>

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
                        <span><?php echo esc_html($g->year); ?></span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <span class="text-slate-500">—</span>
                <?php endif; ?>
              </td>

              <!-- Acțiuni: Profil + Edit + Reset + (Șterge pentru Admin) -->
              <td class="px-3 py-3 text-center align-center" data-col="act1">
                <?php
                  // === payload JS pentru modalul de editare ===
                  $phone  = get_user_meta($pid,'phone',true) ?: get_user_meta($pid,'billing_phone',true);
                  $statut_prof_v = get_user_meta($pid,'statut_prof',true);
                  $calificare_v  = get_user_meta($pid,'calificare',true);
                  $experienta_v  = get_user_meta($pid,'experienta',true);
                  $materia_alta  = get_user_meta($pid,'materia_alta',true);
                  $mentor_sel    = get_user_meta($pid,'mentor_sel',true);
                  $mentor_lit    = get_user_meta($pid,'mentor_literatie',true);
                  $mentor_num    = get_user_meta($pid,'mentor_numeratie',true);
                  $profile_image_id  = get_user_meta($pid,'profile_image',true);
                  $profile_image_url = $profile_image_id ? wp_get_attachment_image_url($profile_image_id,'thumbnail') : '';
                  $cohorte_v     = get_user_meta($pid,'cohorte',true);

                  // școli detaliate pt. precompletare
                  $schools_detailed = [];
                  if ($school_count) {
                    $in3 = implode(',', array_fill(0, $school_count, '%d'));
                    $rows_det = $wpdb->get_results($wpdb->prepare("
                      SELECT s.id, s.name, s.cod, c.name AS city, j.name AS county
                      FROM {$tbl_schools} s
                      LEFT JOIN {$tbl_cities} c  ON s.city_id = c.id
                      LEFT JOIN {$tbl_counties} j ON c.county_id = j.id
                      WHERE s.id IN ($in3)
                    ", ...$sids));
                    foreach ($rows_det as $r) {
                      $schools_detailed[] = [
                        'id'     => (int)$r->id,
                        'name'   => (string)$r->name,
                        'cod'    => (string)($r->cod ?: ''),
                        'city'   => (string)($r->city ?: ''),
                        'county' => (string)($r->county ?: ''),
                      ];
                    }
                  }

                  $prof_payload = [
                    'id'                   => $pid,
                    'first_name'           => (string)$u->first_name,
                    'last_name'            => (string)$u->last_name,
                    'email'                => (string)$email,
                    'phone'                => (string)($phone ?: ''),
                    'user_status_profesor' => (string)$stat,
                    'nivel_predare'        => is_array($nivel_val) ? (string)($nivel_val[0] ?? '') : (string)$nivel_val,
                    'materia_predata'      => (string)$mat,
                    'materia_alta'         => (string)($materia_alta ?: ''),
                    'cod_slf'              => (string)($cod ?: ''),
                    'statut_prof'          => (string)($statut_prof_v ?: ''),
                    'calificare'           => (string)($calificare_v ?: ''),
                    'experienta'           => (string)($experienta_v ?: ''),
                    'segment_rsoi'         => (string)($rsoi_v ?: ''),
                    'generatie'            => (string)($teach_v ?: ''),
                    'an_program'           => (string)($an_prog ?: ''),
                    'cohorte'              => (string)($cohorte_v ?: ''),
                    'assigned_tutor_id'    => (int)$tid,
                    'mentor_sel'           => (string)($mentor_sel ?: ''),
                    'mentor_literatie'     => (string)($mentor_lit ?: ''),
                    'mentor_numeratie'     => (string)($mentor_num ?: ''),
                    'profile_image_id'     => (int)($profile_image_id ?: 0),
                    'profile_image_url'    => (string)$profile_image_url,
                    'assigned_school_ids'  => array_values($sids),
                    'schools_detailed'     => $schools_detailed,
                  ];
                ?>
                <div class="flex flex-wrap items-center justify-center gap-2">
                  <button type="button"
                          class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-indigo-800 rounded-full shadow-sm bg-indigo-100 hover:bg-indigo-200 es-open-assign-gen"
                          data-prof-id="<?php echo (int)$pid; ?>" data-prof-name="<?php echo esc_attr($name); ?>">
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a1 1 0 0 1 1 1v6h6a1 1 0 1 1 0 2h-6v6a1 1 0 1 1-2 0v-6H5a1 1 0 1 1 0-2h6V3a1 1 0 0 1 1-1Z"/></svg>
                    Alocă gen
                  </button>
                  <button type="button"
                          class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-slate-800 rounded-full shadow-sm bg-slate-100 hover:bg-slate-200 es-edit-prof-btn"
                          data-prof='<?php echo esc_attr( wp_json_encode($prof_payload, JSON_UNESCAPED_UNICODE) ); ?>'>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-3 h-3">
                      <path fill-rule="evenodd" d="M11.078 2.25c-.917 0-1.699.663-1.85 1.567L9.05 4.889c-.02.12-.115.26-.297.348a7.493 7.493 0 0 0-.986.57c-.166.115-.334.126-.45.083L6.3 5.508a1.875 1.875 0 0 0-2.282.819l-.922 1.597a1.875 1.875 0 0 0 .432 2.385l.84.692c.095.078.17.229.154.43a7.598 7.598 0 0 0 0 1.139c.015.2-.059.352-.153.43l-.841.692a1.875 1.875 0 0 0-.432 2.385l.922 1.597a1.875 1.875 0 0 0 2.282.818l1.019-.382c.115-.043.283-.031.45.082.312.214.641.405.985.57.182.088.277.228.297.35l.178 1.071c.151.904.933 1.567 1.85 1.567h1.844c.916 0 1.699-.663 1.85-1.567l.178-1.072c.02-.12.114-.26.297-.349.344-.165.673-.356.985-.57.167-.114.335-.125.45-.082l1.02.382a1.875 1.875 0 0 0 2.28-.819l.923-1.597a1.875 1.875 0 0 0-.432-2.385l-.84-.692c-.095-.078-.17-.229-.154-.43a7.614 7.614 0 0 0 0-1.139c-.016-.2.059-.352.153-.43l.84-.692c.708-.582.891-1.59.433-2.385l-.922-1.597a1.875 1.875 0 0 0-2.282-.818l-1.02.382c-.114.043-.282.031-.449-.083a7.49 7.49 0 0 0-.985-.57c-.183-.087-.277-.227-.297-.348l-.179-1.072a1.875 1.875 0 0 0-1.85-1.567h-1.843ZM12 15.75a3.75 3.75 0 1 0 0-7.5 3.75 3.75 0 0 0 0 7.5Z" clip-rule="evenodd" />
                    </svg>
                    Edit
                  </button>
                  <button type="button"
                          class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-slate-800 rounded-full shadow-sm bg-slate-100 hover:bg-slate-200 es-reset-pass-btn"
                          data-user-id="<?php echo (int)$pid; ?>" data-email="<?php echo esc_attr($email); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-3 h-3">
                      <path fill-rule="evenodd" d="M15.75 1.5a6.75 6.75 0 0 0-6.651 7.906c.067.39-.032.717-.221.906l-6.5 6.499a3 3 0 0 0-.878 2.121v2.818c0 .414.336.75.75.75H6a.75.75 0 0 0 .75-.75v-1.5h1.5A.75.75 0 0 0 9 19.5V18h1.5a.75.75 0 0 0 .53-.22l2.658-2.658c.19-.189.517-.288.906-.22A6.75 6.75 0 1 0 15.75 1.5Zm0 3a.75.75 0 0 0 0 1.5A2.25 2.25 0 0 1 18 8.25a.75.75 0 0 0 1.5 0 3.75 3.75 0 0 0-3.75-3.75Z" clip-rule="evenodd" />
                    </svg>

                    Reset parolă
                  </button>
                  <?php if ($is_admin): ?>
                    <button type="button"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-white rounded-full shadow-sm bg-rose-600 hover:bg-rose-700 es-delete-prof-btn"
                            data-user-id="<?php echo (int)$pid; ?>" data-name="<?php echo esc_attr($name); ?>">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-3 h-3">
                        <path fill-rule="evenodd" d="M16.5 4.478v.227a48.816 48.816 0 0 1 3.878.512.75.75 0 1 1-.256 1.478l-.209-.035-1.005 13.07a3 3 0 0 1-2.991 2.77H8.084a3 3 0 0 1-2.991-2.77L4.087 6.66l-.209.035a.75.75 0 0 1-.256-1.478A48.567 48.567 0 0 1 7.5 4.705v-.227c0-1.564 1.213-2.9 2.816-2.951a52.662 52.662 0 0 1 3.369 0c1.603.051 2.815 1.387 2.815 2.951Zm-6.136-1.452a51.196 51.196 0 0 1 3.273 0C14.39 3.05 15 3.684 15 4.478v.113a49.488 49.488 0 0 0-6 0v-.113c0-.794.609-1.428 1.364-1.452Zm-.355 5.945a.75.75 0 1 0-1.5.058l.347 9a.75.75 0 1 0 1.499-.058l-.346-9Zm5.48.058a.75.75 0 1 0-1.498-.058l-.347 9a.75.75 0 0 0 1.5.058l.345-9Z" clip-rule="evenodd" />
                      </svg>
                      Șterge
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="17" class="px-4 py-6 text-center text-slate-500">Nu s-au găsit profesori pentru criteriile selectate.</td></tr>
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

<!-- JS: Nivel multiselect dropdown -->
<script>
(function(){
  const prefix = 'prof-nivel';
  const inputName = 'nivel[]';
  const options = <?php echo wp_json_encode($prof_nivel_opts, JSON_UNESCAPED_UNICODE); ?>;
  let selected = <?php echo wp_json_encode(array_values($nivel_arr)); ?>;
  const placeholder = '— Oricare —';

  const wrap     = document.getElementById(prefix + '-wrap');
  const trigger  = document.getElementById(prefix + '-trigger');
  const dropdown = document.getElementById(prefix + '-dropdown');
  const inputs   = document.getElementById(prefix + '-inputs');
  if (!wrap || !trigger || !dropdown || !inputs) return;

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
})();
</script>

<!-- JS: toggle coloane (persistă în localStorage) -->
<script>
(function(){
  const toggleBtnBar = document.getElementById('cols-toggle-btn');
  if(!toggleBtnBar) return;
  const toggleBar = document.getElementById('cols-section');
  if(!toggleBar) return;
  const KEY = 'professors_table_cols_v1';
  const toggles = document.querySelectorAll('#cols-toggle input[type="checkbox"][data-col]');
  const table   = document.getElementById('prof-table');
  if(!table) return;

  const apply = (state) => {
    toggleBtnBar.addEventListener('click', () => {
      if (toggleBar.classList.contains('hidden')) {
        toggleBar.classList.remove('hidden');
      } else {
        toggleBar.classList.add('hidden');
      }
    });
    // state: {colKey: true/false}
    toggles.forEach(cb => {
      const k = cb.getAttribute('data-col');
      const on = state[k] !== false; // default true
      cb.checked = on;
      // hide/show TH & TD with data-col=k
      table.querySelectorAll('th[data-col="'+k+'"], td[data-col="'+k+'"]').forEach(el => {
        el.style.display = on ? '' : 'none';
      });
    });
  };

  // read
  let state = {};
  try { state = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch(e){ state = {}; }
  apply(state);

  // change
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

<!-- JS: Table sorting (Profesor, An program, Nivel) -->
<script>
(function(){
  const table = document.getElementById('prof-table');
  if (!table) return;
  const tbody = table.querySelector('tbody');
  if (!tbody) return;

  const sortHeaders = table.querySelectorAll('th[data-sort]');
  let currentSort = { key: null, asc: true };

  sortHeaders.forEach(th => {
    th.addEventListener('click', () => {
      const key = th.getAttribute('data-sort');
      if (currentSort.key === key) {
        currentSort.asc = !currentSort.asc;
      } else {
        currentSort.key = key;
        currentSort.asc = true;
      }

      // Update icons
      sortHeaders.forEach(h => {
        const icon = h.querySelector('.es-sort-icon');
        if (!icon) return;
        if (h.getAttribute('data-sort') === key) {
          icon.style.opacity = '1';
          icon.style.transform = currentSort.asc ? 'rotate(0deg)' : 'rotate(180deg)';
        } else {
          icon.style.opacity = '0.4';
          icon.style.transform = 'rotate(0deg)';
        }
      });

      // Sort rows
      const rows = Array.from(tbody.querySelectorAll('tr[data-search]'));
      const attr = 'data-sort-' + key;
      rows.sort((a, b) => {
        const va = (a.getAttribute(attr) || '').toLowerCase();
        const vb = (b.getAttribute(attr) || '').toLowerCase();
        return currentSort.asc ? va.localeCompare(vb, 'ro') : vb.localeCompare(va, 'ro');
      });
      rows.forEach(r => tbody.appendChild(r));
    });
  });
})();
</script>

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
</script>



<script>
  (function(){
    const ajaxUrl = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
    const nonce   = "<?php echo esc_js(wp_create_nonce('edu_nonce')); ?>";
    const isTutorOnly = <?php echo json_encode($is_tutor && !$is_admin); ?>;
    const myTutorId   = <?php echo (int)$uid; ?>;

    const SAVE_ACTION   = 'edu_save_user_form';   // create/update + (opțional) send_reset_link
    const DELETE_ACTION = 'edu_delete_user';      // șterge utilizatorul (admin only)
    const SEARCH_SCHOOL = 'edu_search_schools';   // returnează listă școli [{id,name,city,county,cod}]

    const $  = (sel, root=document) => root.querySelector(sel);
    const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));
    const show = el => el && el.classList.remove('hidden');
    const hide = el => el && el.classList.add('hidden');

    // ====== Modal Add/Edit profesor ======
    const modal   = $('#es-prof-modal');
    const form    = $('#es-prof-form');
    const titleEl = $('#es-prof-modal-title');
    const userId  = $('#es-user-id');
    const submitBtn = $('#es-submit-prof');

    const selectedWrap = $('#es-school-selected');

    // School cascade elements
    const schoolCountySel = $('#es-school-county');
    const schoolCityHid   = $('#es-school-city');
    const cityDdTrigger   = $('#es-city-dd-trigger');
    const cityDdLabel     = $('#es-city-dd-label');
    const cityDropdown    = $('#es-city-dropdown');
    const cityDdSearch    = $('#es-city-dd-search');
    const cityDdList      = $('#es-city-dd-list');
    const cityDdEmpty     = $('#es-city-dd-empty');
    const schoolDdTrigger = $('#es-school-dd-trigger');
    const schoolDropdown  = $('#es-school-dropdown');
    const schoolDdSearch  = $('#es-school-dd-search');
    const schoolDdList    = $('#es-school-dd-list');
    const schoolDdEmpty   = $('#es-school-dd-empty');

    // Cities data embedded from PHP
    const allCitiesData = <?php echo wp_json_encode(array_map(function($c){ return ['id'=>(int)$c->id,'name'=>$c->name,'county_id'=>(int)$c->county_id]; }, $all_cities_modal)); ?>;

    $('#es-open-add-prof')?.addEventListener('click', () => openAddModal());
    $('#es-close-prof')?.addEventListener('click', closeModal);
    $('#es-cancel-prof')?.addEventListener('click', closeModal);

    // === Mapare Nivel -> Materie (preselectare automată)
    const nivelSel   = form.querySelector('select[name="nivel_predare"]');
    const materiaSel = form.querySelector('select[name="materia_predata"]');
    function preselectMateriaForNivel(){
      const nv = (nivelSel?.value || '').toLowerCase();
      if(!materiaSel) return;
      if(nv === 'prescolar') {
        materiaSel.value = 'Educator';
      } else if(nv === 'primar') {
        // atenție la diacritice: în lista ta există „Învățător”
        // dacă în opțiuni este "Invățător" fără diacritice, ajustează aici exact textul
        materiaSel.value = 'Învățător';
      }
      materiaSel.dispatchEvent(new Event('change',{bubbles:true}));
    }
    nivelSel?.addEventListener('change', preselectMateriaForNivel);

    // La deschiderea modalului de Adăugare profesor: setează default „An 1”
    const anProgramSel = document.querySelector('#es-an-program');
    function setDefaultsOnAddProfessor(){
      if(anProgramSel) anProgramSel.value = 'An 1';
      // reset RSOI/cohorte la blank
      const rsoiSel = document.querySelector('select[name="segment_rsoi"]');
      if(rsoiSel) rsoiSel.value = '';
      const cohSel = document.querySelector('#es-cohorte');
      if(cohSel) cohSel.value = '';
    }

    function openAddModal(){
      resetForm();
      titleEl.textContent = 'Adaugă profesor';
      userId.value = '';
      setDefaultsOnAddProfessor();
      show(modal);
    }

    function openEditModal(data){
      resetForm();
      titleEl.textContent = 'Editează profesor';
      userId.value = String(data.id || '');
      setVal('first_name', data.first_name);
      setVal('last_name',  data.last_name);
      setVal('email',      data.email);
      setVal('phone',      data.phone);
      setVal('user_status_profesor', data.user_status_profesor);
      setVal('nivel_predare',        data.nivel_predare);
      setVal('materia_predata',      data.materia_predata);
      setVal('materia_alta',         data.materia_alta);
      setVal('cod_slf',              data.cod_slf);
      setVal('statut_prof',          data.statut_prof);
      setVal('calificare',           data.calificare);
      setVal('experienta',           data.experienta);
      setVal('segment_rsoi',         data.segment_rsoi);
      setVal('generatie',            data.generatie);
      setVal('an_program',           data.an_program);
      setVal('cohorte',             data.cohorte);
      if(!isTutorOnly) setVal('assigned_tutor_id', data.assigned_tutor_id);
      setVal('mentor_sel',       data.mentor_sel);
      setVal('mentor_literatie', data.mentor_literatie);
      setVal('mentor_numeratie', data.mentor_numeratie);
      toggleAlta();

      const avatar = $('#es-current-avatar');
      avatar.textContent = data.profile_image_url ? 'Imagine curentă setată.' : 'Fără imagine de profil.';

      if (Array.isArray(data.schools_detailed) && data.schools_detailed.length) {
        data.schools_detailed.forEach(addSelectedSchool);
      } else if (Array.isArray(data.assigned_school_ids) && data.assigned_school_ids.length) {
        data.assigned_school_ids.forEach(id => addSelectedSchool({id, name: 'Școală #'+id, city:'', county:'', cod:''}));
      }
      show(modal);
    }

    function closeModal(){ hide(modal); resetForm(); }

    function setVal(name, val){
      const el = form.querySelector(`[name="${name}"]`);
      if(!el) return;
      if (el.tagName === 'SELECT') {
        el.value = (val ?? '').toString();
        el.dispatchEvent(new Event('change', {bubbles:true}));
      } else if (el.type === 'checkbox') {
        el.checked = !!val;
      } else {
        el.value = (val ?? '').toString();
      }
    }

    function resetForm(){
      form.reset();
      selectedWrap.innerHTML = '';
      // Reset cascade
      if (schoolCountySel) schoolCountySel.value = '';
      resetCityDropdown();
      if (schoolDdList) schoolDdList.innerHTML = '';
      hide(schoolDropdown);
      schoolDdOpen = false;
      toggleAlta();
      resetEmailValidation();
    }

    // Materia „Alta”
    const materiaAltaWrap = $('#es-materia-alta-wrap');
    function toggleAlta(){ (materiaSel?.value === 'Alta') ? show(materiaAltaWrap) : hide(materiaAltaWrap); }
    materiaSel?.addEventListener('change', toggleAlta);

    // ====== Școli (cascade: județ -> oraș -> dropdown multiselect cu search) ======
    function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

    function addSelectedSchool(item){
      if (!item || !item.id) return;
      if ($(`[data-school-id="${item.id}"]`, selectedWrap)) return;
      const row = document.createElement('div');
      row.className = 'flex items-center justify-between px-3 py-2 text-sm bg-sky-50 rounded-lg ring-1 ring-sky-200';
      row.setAttribute('data-school-id', item.id);
      const loc = [item.city, item.county].filter(Boolean).join(' / ');
      row.innerHTML = `
        <input type="hidden" name="assigned_school_ids[]" value="${item.id}">
        <div class="flex items-center gap-2 min-w-0">
          <svg class="w-4 h-4 shrink-0 text-sky-600" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 1.5 9 12 15 22.5 9 12 3Z"/><path d="M3 10.5v5.25L12 21l9-5.25V10.5" opacity=".4"/></svg>
          <span class="font-medium truncate text-sky-900">${escapeHtml(item.name||('Școală #'+item.id))}</span>
          ${loc ? `<span class="text-xs truncate text-sky-600">${escapeHtml(loc)}</span>` : ''}
          ${item.cod ? `<span class="text-xs text-sky-500">cod: ${escapeHtml(item.cod)}</span>` : ''}
        </div>
        <button type="button" class="p-1 ml-2 rounded-md shrink-0 text-rose-500 hover:bg-rose-100 hover:text-rose-700" aria-label="Elimină școala" title="Elimină școala">
          <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
        </button>
      `;
      row.querySelector('button').addEventListener('click', () => row.remove());
      selectedWrap.appendChild(row);
    }

    // ====== City searchable dropdown ======
    let cityDdOpen = false;
    let currentCities = []; // cities for current county

    function resetCityDropdown(){
      schoolCityHid.value = '';
      cityDdLabel.textContent = '— Selectează județ —';
      cityDdLabel.classList.add('text-slate-500');
      cityDdLabel.classList.remove('text-slate-900');
      currentCities = [];
      cityDdList.innerHTML = '';
      hide(cityDropdown);
      cityDdOpen = false;
    }

    function selectCity(id, name){
      schoolCityHid.value = id;
      cityDdLabel.textContent = name || '— Toate orașele —';
      cityDdLabel.classList.toggle('text-slate-500', !id);
      cityDdLabel.classList.toggle('text-slate-900', !!id);
      hide(cityDropdown);
      cityDdOpen = false;
      loadSchoolsForDropdown();
    }

    function renderCityList(filter){
      cityDdList.innerHTML = '';
      const norm = (s) => (s||'').toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,'');
      const q = norm(filter);

      // "All cities" option
      const allBtn = document.createElement('button');
      allBtn.type = 'button';
      allBtn.className = 'w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50 text-slate-500 border-b border-slate-100';
      allBtn.textContent = '— Toate orașele —';
      allBtn.addEventListener('click', () => selectCity('', ''));
      cityDdList.appendChild(allBtn);

      let count = 0;
      currentCities.forEach(c => {
        if (q && !norm(c.name).includes(q)) return;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50';
        btn.textContent = c.name;
        btn.addEventListener('click', () => selectCity(c.id, c.name));
        cityDdList.appendChild(btn);
        count++;
      });

      if (!count) { cityDdEmpty.textContent = 'Niciun oraș găsit.'; show(cityDdEmpty); }
      else { hide(cityDdEmpty); }
    }

    cityDdTrigger?.addEventListener('click', () => {
      if (!currentCities.length) return;
      cityDdOpen = !cityDdOpen;
      cityDdOpen ? show(cityDropdown) : hide(cityDropdown);
      if (cityDdOpen) { cityDdSearch.value = ''; cityDdSearch.focus(); renderCityList(''); }
    });
    cityDdSearch?.addEventListener('input', () => renderCityList(cityDdSearch.value));
    cityDdSearch?.addEventListener('keydown', (e) => { if (e.key === 'Escape') { hide(cityDropdown); cityDdOpen = false; } });
    document.addEventListener('click', (e) => {
      const wrap = $('#es-city-dd-wrap');
      if (wrap && !wrap.contains(e.target)) { hide(cityDropdown); cityDdOpen = false; }
    });

    // County change → populate cities
    schoolCountySel?.addEventListener('change', () => {
      const countyId = parseInt(schoolCountySel.value) || 0;
      if (!countyId) {
        resetCityDropdown();
      } else {
        currentCities = allCitiesData.filter(c => c.county_id === countyId);
        schoolCityHid.value = '';
        cityDdLabel.textContent = '— Toate orașele —';
        cityDdLabel.classList.add('text-slate-500');
        cityDdLabel.classList.remove('text-slate-900');
      }
      loadSchoolsForDropdown();
    });

    // ====== School dropdown ======
    let schoolDdOpen = false;
    schoolDdTrigger?.addEventListener('click', () => {
      schoolDdOpen = !schoolDdOpen;
      schoolDdOpen ? show(schoolDropdown) : hide(schoolDropdown);
      if (schoolDdOpen) { schoolDdSearch.value = ''; schoolDdSearch.focus(); loadSchoolsForDropdown(); }
    });
    document.addEventListener('click', (e) => {
      const wrap = $('#es-school-dd-wrap');
      if (wrap && !wrap.contains(e.target)) { hide(schoolDropdown); schoolDdOpen = false; }
    });
    schoolDdSearch?.addEventListener('keydown', (e) => { if (e.key === 'Escape') { hide(schoolDropdown); schoolDdOpen = false; } });

    // Search within dropdown
    let schoolSearchTmr = null;
    schoolDdSearch?.addEventListener('input', () => {
      clearTimeout(schoolSearchTmr);
      schoolSearchTmr = setTimeout(() => loadSchoolsForDropdown(), 250);
    });

    let schoolCache = [];
    async function loadSchoolsForDropdown(){
      const countyId = parseInt(schoolCountySel?.value) || 0;
      const cityId   = parseInt(schoolCityHid?.value) || 0;
      const q        = (schoolDdSearch?.value || '').trim();

      if (!countyId && q.length < 2) {
        schoolDdList.innerHTML = '';
        schoolDdEmpty.textContent = 'Selectează un județ pentru a vedea școlile.';
        show(schoolDdEmpty);
        return;
      }

      const fd = new FormData();
      fd.append('action', SEARCH_SCHOOL);
      fd.append('nonce', nonce);
      fd.append('q', q);
      if (countyId) fd.append('county_id', countyId);
      if (cityId)   fd.append('city_id', cityId);

      try {
        const r = await fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' });
        const txt = await r.text();
        let data = [];
        try {
          const resp = JSON.parse(txt);
          data = Array.isArray(resp) ? resp : (Array.isArray(resp?.data) ? resp.data : []);
        } catch(e){}

        schoolCache = data;
        renderSchoolDropdownList(data);
      } catch(_){
        schoolDdList.innerHTML = '';
        schoolDdEmpty.textContent = 'Eroare la încărcarea școlilor.';
        show(schoolDdEmpty);
      }
    }

    function renderSchoolDropdownList(list){
      schoolDdList.innerHTML = '';
      if (!list || !list.length) {
        schoolDdEmpty.textContent = 'Niciun rezultat.';
        show(schoolDdEmpty);
        return;
      }
      hide(schoolDdEmpty);
      list.forEach(item => {
        // Skip already selected
        if ($(`[data-school-id="${item.id}"]`, selectedWrap)) return;
        const row = document.createElement('button');
        row.type = 'button';
        row.className = 'w-full text-left px-3 py-2 text-sm hover:bg-slate-50 border-b border-slate-100 last:border-0';
        row.innerHTML = `<div class="font-medium">${escapeHtml(item.name)}</div>
                        <div class="text-xs text-slate-500">${escapeHtml(item.city||'')}${item.city&&item.county?' / ':''}${escapeHtml(item.county||'')} • cod: ${escapeHtml(item.cod||'—')}</div>`;
        row.addEventListener('click', () => {
          addSelectedSchool(item);
          row.remove();
          // If no more items visible
          if (!schoolDdList.children.length) {
            schoolDdEmpty.textContent = 'Toate școlile au fost selectate.';
            show(schoolDdEmpty);
          }
        });
        schoolDdList.appendChild(row);
      });
    }

    // ====== Email live validation ======
    const emailInput = $('#es-prof-email');
    const emailOk    = $('#es-email-ok');
    const emailErr   = $('#es-email-err');
    let emailCheckTmr = null;
    let emailIsValid  = true; // tracks current state

    function resetEmailValidation(){
      hide(emailOk); hide(emailErr);
      emailInput?.classList.remove('border-rose-400', 'border-emerald-400');
      emailIsValid = true;
    }

    emailInput?.addEventListener('input', () => {
      clearTimeout(emailCheckTmr);
      resetEmailValidation();
      const val = (emailInput.value || '').trim();
      if (!val || val.length < 5 || !val.includes('@')) return;
      emailCheckTmr = setTimeout(() => checkEmailExists(val), 400);
    });

    async function checkEmailExists(email){
      // Skip validation when editing (user_id is set) and email hasn't changed
      const editingId = userId.value || '';

      const fd = new FormData();
      fd.append('action', 'edu_check_email');
      fd.append('nonce', nonce);
      fd.append('email', email);
      if (editingId) fd.append('user_id', editingId);

      try {
        const r = await fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' });
        const resp = await r.json();

        // Only apply if the input still has the same value
        if ((emailInput.value || '').trim() !== email) return;

        if (resp && resp.success) {
          // Email is available
          show(emailOk); hide(emailErr);
          emailInput.classList.remove('border-rose-400');
          emailInput.classList.add('border-emerald-400');
          emailIsValid = true;
        } else {
          // Email exists
          const msg = resp?.data?.message || 'Acest email există deja în sistem.';
          emailErr.textContent = msg;
          show(emailErr); hide(emailOk);
          emailInput.classList.remove('border-emerald-400');
          emailInput.classList.add('border-rose-400');
          emailIsValid = false;
        }
      } catch(_){
        // Network error, don't block
        resetEmailValidation();
      }
    }

    // ====== Submit Add/Edit (SAVE_ACTION) ======
    form?.addEventListener('submit', function(e){
      e.preventDefault();
      if (!emailIsValid) {
        toast('Emailul este deja folosit. Corectează adresa.', 'err');
        emailInput?.focus();
        return;
      }
      if(isTutorOnly && !this.querySelector('input[name="assigned_tutor_id"]')) {
        const h = document.createElement('input');
        h.type='hidden'; h.name='assigned_tutor_id'; h.value = String(myTutorId);
        this.appendChild(h);
      }
      const fd = new FormData(this);
      fd.append('action', SAVE_ACTION);

      const oldHtml = submitBtn.innerHTML;
      submitBtn.disabled = true; submitBtn.textContent = 'Se salvează...';

      fetch(ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' })
        .then(async r => {
          const txt = await r.text();
          let resp = {};
          try { resp = JSON.parse(txt); } catch(e){ resp = {success:false, data:{message:txt}}; }
          if(resp && resp.success){
            toast('Salvat cu succes.', 'ok');
            closeModal();
            setTimeout(()=>location.reload(), 600);
          } else {
            const msg = (resp && resp.data && (resp.data.message||resp.data)) ? (resp.data.message||resp.data) : 'Eroare la salvare.';
            toast(msg, 'err');
          }
        })
        .catch(()=> toast('Eroare de rețea.', 'err'))
        .finally(()=> { submitBtn.disabled=false; submitBtn.innerHTML = oldHtml; });
    });

    // ====== Butoane Edit / Reset / Delete din tabel ======
    $$('.es-edit-prof-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        try {
          const data = JSON.parse(btn.getAttribute('data-prof')||'{}');
          openEditModal(data);
        } catch(e){ console.error('Bad data-prof JSON', e); }
      });
    });

    $$('.es-reset-pass-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-user-id');
        const email = btn.getAttribute('data-email') || '';
        if(!id) return;
        if(!confirm(`Trimit link de resetare parolă către ${email || ('utilizator #' + id)}?`)) return;
        sendResetLink(id);
      });
    });

    // === Delete (Admin only) ===
    const delModal   = $('#es-del-modal');
    const delNameEl  = $('#es-del-name');
    const delCancel  = $('#es-cancel-del');
    const delConfirm = $('#es-confirm-del');
    let delState = { id:null, row:null };

    $$('.es-delete-prof-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const id   = btn.getAttribute('data-user-id');
        const name = btn.getAttribute('data-name') || ('#'+id);
        delState.id = id;
        delState.row = btn.closest('tr');
        delNameEl.textContent = name;
        show(delModal);
      });
    });
    delCancel?.addEventListener('click', () => { hide(delModal); delState = {id:null,row:null}; });
    $('#es-del-modal .absolute.inset-0')?.addEventListener('click', (e) => {
      if (e.target === e.currentTarget) { hide(delModal); delState = {id:null,row:null}; }
    });
    delConfirm?.addEventListener('click', () => {
      if(!delState.id) return;
      deleteProfessor(delState.id, delState.row);
    });

    // ====== AJAX helpers: reset & delete ======
    function sendResetLink(userId){
      const fd = new FormData();
      fd.append('action', SAVE_ACTION);
      fd.append('nonce', nonce);
      fd.append('user_id', userId);
      fd.append('send_reset_link', '1');

      fetch(ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' })
        .then(async r => {
          const txt = await r.text();
          let resp = {};
          try { resp = JSON.parse(txt); } catch(e){ resp = {success:false, data:{message:txt}}; }
          if(resp && resp.success){
            toast('Email de resetare trimis.', 'ok');
          } else {
            const msg = (resp && resp.data && (resp.data.message||resp.data)) ? (resp.data.message||resp.data) : 'Nu am putut trimite resetarea.';
            toast(msg, 'err');
          }
        })
        .catch(()=> toast('Eroare de rețea la reset.', 'err'));
    }

    function deleteProfessor(userId, rowEl){
      const fd = new FormData();
      fd.append('action', DELETE_ACTION);
      fd.append('nonce', nonce);
      fd.append('user_id', userId);

      delConfirm.disabled = true; delConfirm.textContent = 'Se șterge...';

      fetch(ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' })
        .then(async r => {
          const txt = await r.text();
          let resp = {};
          try { resp = JSON.parse(txt); } catch(e){ resp = {success:false, data:{message:txt}}; }
          if(resp && resp.success){
            toast('Profesor șters.', 'ok');
            hide(delModal);
            if (rowEl) { rowEl.remove(); }
          } else {
            const msg = (resp && resp.data && (resp.data.message||resp.data)) ? (resp.data.message||resp.data) : 'Nu am putut șterge profesorul.';
            toast(msg, 'err');
          }
        })
        .catch(()=> toast('Eroare de rețea la ștergere.', 'err'))
        .finally(()=> { delConfirm.disabled=false; delConfirm.textContent='Șterge'; });
    }

    // ====== Toast helper ======
    function toast(msg, type){
      const n = document.createElement('div');
      n.className = 'fixed bottom-4 right-4 px-4 py-2 text-sm text-white rounded-lg shadow-lg ' + (type==='ok'?'bg-emerald-600':'bg-rose-600');
      n.textContent = msg;
      document.body.appendChild(n);
      setTimeout(()=> n.remove(), 1500);
    }


    // ====== Modal Adaugă/Alocă generație (nou) ======
    const genModal    = document.getElementById('es-gen-modal');
    const genForm     = document.getElementById('es-gen-form');
    const genTitleEl  = document.getElementById('es-gen-modal-title');
    const genIdInput  = document.getElementById('es-gen-id');
    const genProfId   = document.getElementById('es-gen-professor-id');
    const genYearHid  = document.getElementById('es-gen-year');
    const genYearDisp = document.getElementById('es-gen-year-display');
    const genName     = document.getElementById('es-gen-name');

    const genOpenBtn  = document.getElementById('es-open-add-gen');
    const genCloseBtn = document.getElementById('es-close-gen');
    const genCancel   = document.getElementById('es-cancel-gen');
    const genSubmit   = document.getElementById('es-submit-gen');

    const genProfSearchWrap = document.getElementById('es-gen-prof-search-wrap');
    const genProfSearch = document.getElementById('es-gen-prof-search');
    const genProfSuggest= document.getElementById('es-gen-prof-suggest');
    const genProfSelected = document.getElementById('es-gen-prof-selected');
    const genProfClear  = document.getElementById('es-gen-prof-clear');

    const genLevelRO   = document.getElementById('es-gen-level-ro');
    const genLevelClasses = document.getElementById('es-gen-level-classes');

    const GEN_CREATE_ACTION = 'edu_create_generation';

    // Map pentru afișat clase per nivel (informativ)
    const LEVEL_LABEL = { prescolar:'Preșcolar', primar:'Primar', gimnazial:'Gimnazial', liceu:'Liceu' };
    const CLASSES_MAP = {
      prescolar: ['Grupa mică','Grupa mijlocie','Grupa mare'],
      primar:    ['Clasa pregătitoare','Clasa I','Clasa II','Clasa III','Clasa IV'],
      gimnazial: ['Clasa V','Clasa VI','Clasa VII','Clasa VIII'],
      liceu:     ['Clasa IX','Clasa X','Clasa XI','Clasa XII']
    };

    function computeAcademicYearStr(){
      const d = new Date();
      const y = d.getFullYear(), m = d.getMonth() + 1; // 1..12
      return (m >= 8) ? `${y}-${y+1}` : `${y-1}-${y}`;
    }

    function getAcademicYearOptions(){
      const d = new Date();
      const y = d.getFullYear(), m = d.getMonth() + 1;
      const baseYear = (m >= 8) ? y : y - 1;
      return [
        `${baseYear}-${baseYear+1}`,
        `${baseYear+1}-${baseYear+2}`,
        `${baseYear+2}-${baseYear+3}`,
      ];
    }

    function populateYearDropdown(selectedValue){
      if (!genYearDisp) return;
      const opts = getAcademicYearOptions();
      const sel = selectedValue || opts[0];
      genYearDisp.innerHTML = opts.map(o =>
        `<option value="${o}" ${o === sel ? 'selected' : ''}>${o}</option>`
      ).join('');
      if (genYearHid) genYearHid.value = genYearDisp.value;
    }

    // Sync hidden field when dropdown changes
    genYearDisp?.addEventListener('change', () => {
      if (genYearHid) genYearHid.value = genYearDisp.value;
    });

    function setYearAuto(){
      populateYearDropdown();
    }

    function renderLevelAndClasses(code){
      const label = LEVEL_LABEL[code] || '—';
      genLevelRO.textContent = label;

      genLevelClasses.innerHTML = '';
      const arr = CLASSES_MAP[code] || [];
      arr.forEach(txt => {
        const b = document.createElement('span');
        b.className = 'inline-flex items-center px-2 py-0.5 text-xs rounded-full bg-sky-50 text-sky-800 ring-1 ring-sky-200';
        b.textContent = txt;
        genLevelClasses.appendChild(b);
      });
    }

    async function fetchProfessorLevel(pid){
      if (!pid) { renderLevelAndClasses(''); return; }
      try{
        const fd = new FormData();
        fd.append('action','edu_get_prof_level');
        fd.append('nonce','<?php echo esc_js($edu_nonce); ?>');
        fd.append('professor_id', String(pid));
        const r = await fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method:'POST', body:fd, credentials:'same-origin' });
        const data = await r.json();
        if (data && data.success && data.data) {
          renderLevelAndClasses(data.data.level_code || '');
          // preferăm anul de pe server, dacă vine
          const sy = (data.data.year || computeAcademicYearStr());
          populateYearDropdown(sy);
        } else {
          renderLevelAndClasses('');
        }
      }catch(_){
        renderLevelAndClasses('');
      }
    }

    function lockProfessorUI(locked, labelHtml){
      if (locked) {
        genProfSearchWrap.classList.add('hidden');
        genProfSelected.innerHTML = labelHtml || '';
      } else {
        genProfSearchWrap.classList.remove('hidden');
        genProfSelected.innerHTML = '';
      }
    }

    function showGenModal(){
      genForm.reset();
      genIdInput.value = '';
      setYearAuto();
      genLevelRO.textContent = '—';
      genLevelClasses.innerHTML = '';
      genTitleEl.textContent = 'Adaugă generație';
      lockProfessorUI(false, '');
      genProfSuggest.classList.add('hidden'); genProfSuggest.innerHTML = '';
      genModal.classList.remove('hidden');
      genName.focus();
    }
    function hideGenModal(){ genModal.classList.add('hidden'); }

    // Deschidere „Adaugă generație” (liber) — doar admin ai butonul
    genOpenBtn?.addEventListener('click', ()=>{
      genProfId.value = '';
      showGenModal();
    });
    genCloseBtn?.addEventListener('click', hideGenModal);
    genCancel?.addEventListener('click', hideGenModal);
    genModal?.querySelector('.absolute.inset-0')?.addEventListener('click', (e)=>{ if(e.target===e.currentTarget) hideGenModal(); });

    // Deschidere din fiecare rând: „Alocă gen” — știm deja profesorul
    document.querySelectorAll('.es-open-assign-gen').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const pid = btn.getAttribute('data-prof-id') || '';
        const pname = btn.getAttribute('data-prof-name') || '';
        genProfId.value = pid;
        setYearAuto();
        fetchProfessorLevel(pid);
        lockProfessorUI(true, pname
          ? `<span class="px-2 py-1 text-indigo-800 rounded bg-indigo-50 ring-1 ring-indigo-200">Profesor: ${escapeHtml(pname)} (#${pid})</span>`
          : `<span class="px-2 py-1 text-indigo-800 rounded bg-indigo-50 ring-1 ring-indigo-200">Profesor selectat: #${pid}</span>`
        );
        genTitleEl.textContent = 'Alocă generație';
        genModal.classList.remove('hidden');
        genName.focus();
      });
    });

    // Căutare profesor (admin-only)
    function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
    let genTipTimer=null;
    genProfSearch?.addEventListener('input', ()=>{
      clearTimeout(genTipTimer);
      const q = (genProfSearch.value||'').trim();
      if(q.length<2){ genProfSuggest.classList.add('hidden'); genProfSuggest.innerHTML=''; return; }
      genTipTimer = setTimeout(()=> loadProfSuggest(q), 220);
    });
    genProfClear?.addEventListener('click', ()=>{
      genProfId.value = '';
      genProfSelected.innerHTML = '';
      genProfSuggest.classList.add('hidden'); genProfSuggest.innerHTML='';
      genProfSearch.value=''; genProfSearch.focus();
      renderLevelAndClasses('');
      setYearAuto();
    });

    async function loadProfSuggest(q){
      try{
        const fd = new FormData();
        fd.append('action','edu_search_teachers'); // endpoint-ul tău existent
        fd.append('nonce', genProfSearch.dataset.nonce || '');
        fd.append('q', q);
        const r = await fetch(genProfSearch.dataset.ajax || '<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method:'POST', body:fd, credentials:'same-origin' });
        const data = await r.json();
        if(!Array.isArray(data) || !data.length){ genProfSuggest.classList.add('hidden'); genProfSuggest.innerHTML=''; return; }
        genProfSuggest.innerHTML = data.slice(0,15).map(it=>{
          const label = escapeHtml(it.text || (`#${it.id}`));
          return `<button type="button" data-id="${it.id}" class="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50">${label}</button>`;
        }).join('');
        genProfSuggest.classList.remove('hidden');
        Array.from(genProfSuggest.querySelectorAll('button[data-id]')).forEach(b=>{
          b.addEventListener('click', ()=>{
            const pid = b.getAttribute('data-id') || '';
            genProfId.value = pid;
            genProfSelected.innerHTML = `<span class="px-2 py-1 text-indigo-800 rounded bg-indigo-50 ring-1 ring-indigo-200">Profesor selectat: ${escapeHtml(b.textContent)} (#${pid})</span>`;
            genProfSuggest.classList.add('hidden'); genProfSuggest.innerHTML='';
            fetchProfessorLevel(pid);
          });
        });
      }catch(_){
        genProfSuggest.classList.add('hidden'); genProfSuggest.innerHTML='';
      }
    }

    // Submit
    genForm?.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const pid = (genProfId.value||'').trim();
      if (!pid) { toast('Selectează profesorul.', 'err'); return; }
      if (!genName.value.trim()) { toast('Scrie numele generației.', 'err'); return; }

      const fd = new FormData(genForm);
      fd.append('action', GEN_CREATE_ACTION);

      const old = genSubmit.innerHTML;
      genSubmit.disabled = true; genSubmit.textContent = 'Se salvează...';
      try{
        const r = await fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method:'POST', body: fd, credentials:'same-origin' });
        const txt = await r.text();
        let resp={};
        try{ resp = JSON.parse(txt); } catch(e){ resp = {success:false, data:{message:txt}}; }
        if(resp && resp.success){
          toast('Generație salvată.', 'ok');
          hideGenModal();
          setTimeout(()=>location.reload(), 500);
        } else {
          const msg = (resp && resp.data && (resp.data.message||resp.data)) ? (resp.data.message||resp.data) : 'Eroare la salvarea generației.';
          toast(msg, 'err');
        }
      }catch(_){
        toast('Eroare de rețea.', 'err');
      }finally{
        genSubmit.disabled=false; genSubmit.innerHTML = old;
      }
    });
  })();
</script>
