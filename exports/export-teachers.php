<?php
// Export profesori CSV (full, fără "Profil")

if (!function_exists('es_send_csv')) {
  status_header(500);
  echo 'Lipsește funcția es_send_csv(). Verifică functions.php.';
  exit;
}

global $wpdb;

$tbl_users       = $wpdb->users;
$tbl_generations = $wpdb->prefix . 'edu_generations';
$tbl_students    = $wpdb->prefix . 'edu_students';
$tbl_schools     = $wpdb->prefix . 'edu_schools';
$tbl_cities      = $wpdb->prefix . 'edu_cities';
$tbl_counties    = $wpdb->prefix . 'edu_counties';

// ------- helpers locale (cu guard) -------
if (!function_exists('es_normalize_level_code')) {
  function es_normalize_level_code($raw){
    $c = strtolower(trim((string)$raw));
    if ($c === 'primar-mic' || $c === 'primar mare' || $c === 'primar-mare') $c = 'primar';
    if ($c === 'gimnaziu') $c = 'gimnazial';
    if ($c === 'preșcolar' || $c === 'prescolari' || $c === 'preșcolari') $c = 'prescolar';
    return in_array($c, ['prescolar','primar','gimnazial','liceu'], true) ? $c : ($c ?: '');
  }
}
if (!function_exists('es_level_label')) {
  function es_level_label($code){
    $map = ['prescolar'=>'Preșcolar','primar'=>'Primar','gimnazial'=>'Gimnazial','liceu'=>'Liceu'];
    $code = es_normalize_level_code($code);
    return $map[$code] ?? '—';
  }
}
if (!function_exists('es_format_dt')) {
  function es_format_dt($ts_or_str){
    if (!$ts_or_str) return '—';
    $ts = is_numeric($ts_or_str) ? (int)$ts_or_str : strtotime((string)$ts_or_str);
    if (!$ts) return '—';
    return date_i18n(get_option('date_format').' '.get_option('time_format'), $ts);
  }
}
if (!function_exists('es_user_fullname')) {
  function es_user_fullname($u){
    $name = trim(($u->first_name ?? '').' '.($u->last_name ?? ''));
    if ($name === '') $name = $u->display_name ?: $u->user_login;
    return $name;
  }
}

// ------- filtre din GET (identice cu pagina) -------
$s          = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
$nivel_arr  = isset($_GET['nivel']) ? array_filter(array_map('sanitize_text_field', (array)wp_unslash($_GET['nivel']))) : [];
$statut     = isset($_GET['statut']) ? sanitize_text_field(wp_unslash($_GET['statut'])) : '';
$gen_year   = isset($_GET['gen_year']) ? sanitize_text_field(wp_unslash($_GET['gen_year'])) : '';
$county_arr = isset($_GET['county']) ? array_filter(array_map('sanitize_text_field', (array)wp_unslash($_GET['county']))) : [];
$an_program = isset($_GET['an_program']) ? sanitize_text_field(wp_unslash($_GET['an_program'])) : '';
$rsoi_arr   = isset($_GET['rsoi']) ? array_filter(array_map('sanitize_text_field', (array)wp_unslash($_GET['rsoi']))) : [];
$tutor_arr  = isset($_GET['tutor']) ? array_filter(array_map('intval', (array)$_GET['tutor'])) : [];

// ------- permisiuni tutor (opțional) -------
$user     = wp_get_current_user();
$is_admin = current_user_can('manage_options');
$is_tutor = in_array('tutor', (array)($user->roles ?? []), true);

// ------- WP_User_Query: rol profesor, number=-1 -------
$meta_query = ['relation' => 'AND'];

if ($is_tutor && !$is_admin) {
  $meta_query[] = ['key'=>'assigned_tutor_id','value'=>(int)$user->ID,'compare'=>'=','type'=>'NUMERIC'];
}
if ($statut !== '') {
  $meta_query[] = [
    'relation'=>'OR',
    ['key'=>'user_status_profesor','value'=>$statut,'compare'=>'='],
    ['key'=>'statut_prof','value'=>$statut,'compare'=>'='],
    ['key'=>'statut','value'=>$statut,'compare'=>'='],
  ];
}
if ($an_program !== '') {
  $meta_query[] = ['key'=>'an_program','value'=>$an_program,'compare'=>'='];
}
if (!empty($rsoi_arr)) {
  $meta_query[] = ['key'=>'segment_rsoi','value'=>$rsoi_arr,'compare'=>'IN'];
}
if (!empty($tutor_arr)) {
  $meta_query[] = ['key'=>'assigned_tutor_id','value'=>$tutor_arr,'compare'=>'IN','type'=>'NUMERIC'];
}

$args = [
  'role'       => 'profesor',
  'number'     => -1,
  'orderby'    => 'display_name',
  'order'      => 'ASC',
  'meta_query' => $meta_query,
];

if ($s !== '') {
  $args['search']         = '*'.esc_attr($s).'*';
  $args['search_columns'] = ['user_login','user_nicename','user_email','display_name'];
}

$user_query = new WP_User_Query($args);
$all_prof   = $user_query->get_results(); // array<WP_User>

// ------- preluări asociate (generații, elevi, județe) -------
$prof_ids = array_map(fn($u)=>(int)$u->ID, $all_prof);

$gens_by_prof   = [];
$students_count = [];
$counties_by_prof = [];

// generații
if ($prof_ids) {
  $in = implode(',', array_fill(0, count($prof_ids), '%d'));
  $gens = $wpdb->get_results($wpdb->prepare("
    SELECT id, professor_id, name, level, year
    FROM {$tbl_generations}
    WHERE professor_id IN ($in)
    ORDER BY year DESC, id DESC
  ", ...$prof_ids));
  foreach ($gens as $g) {
    $pid = (int)$g->professor_id;
    $gens_by_prof[$pid] ??= [];
    $gens_by_prof[$pid][] = $g;
  }

  // elevi per profesor
  $sc = $wpdb->get_results($wpdb->prepare("
    SELECT professor_id, COUNT(*) AS total
    FROM {$tbl_students}
    WHERE professor_id IN ($in)
    GROUP BY professor_id
  ", ...$prof_ids));
  foreach ($sc as $row) {
    $students_count[(int)$row->professor_id] = (int)$row->total;
  }

  // school meta (name, cod SIRUTA, city, county) din școlile asignate
  $school_ids_all = [];
  foreach ($prof_ids as $pid) {
    $sids = get_user_meta($pid, 'assigned_school_ids', true);
    if (is_array($sids)) {
      foreach ($sids as $sid) { $sid=(int)$sid; if ($sid>0) $school_ids_all[$sid]=true; }
    }
  }
  $school_ids_all = array_keys($school_ids_all);

  $school_info = []; // school_id => ['name','cod','city','county']
  if ($school_ids_all) {
    $in2 = implode(',', array_fill(0, count($school_ids_all), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare("
      SELECT s.id AS school_id, s.name AS school_name, s.cod AS school_cod,
             c.name AS city_name, j.name AS county_name
      FROM {$tbl_schools} s
      LEFT JOIN {$tbl_cities}   c ON s.city_id = c.id
      LEFT JOIN {$tbl_counties} j ON c.county_id = j.id
      WHERE s.id IN ($in2)
    ", ...$school_ids_all));
    foreach ($rows as $r) {
      $school_info[(int)$r->school_id] = [
        'name'   => (string)$r->school_name,
        'cod'    => (string)$r->school_cod,
        'city'   => (string)$r->city_name,
        'county' => (string)$r->county_name,
      ];
    }
  }

  foreach ($prof_ids as $pid) {
    $sids = get_user_meta($pid, 'assigned_school_ids', true);
    $set = [];
    if (is_array($sids)) {
      foreach ($sids as $sid) {
        $sid = (int)$sid;
        if ($sid>0 && isset($school_info[$sid])) {
          $nm = trim($school_info[$sid]['county']);
          if ($nm !== '') $set[$nm] = true;
        }
      }
    }
    $counties_by_prof[$pid] = array_keys($set);
  }
}

// ------- filtre manuale suplimentare (nivel, an generație, județ) -------
$filtered = $all_prof;

// nivel (multiselect)
if (!empty($nivel_arr)) {
  $nivel_codes = array_filter(array_map('es_normalize_level_code', $nivel_arr));
  if (!empty($nivel_codes)) {
    $filtered = array_values(array_filter($filtered, function($u) use ($nivel_codes){
      $raw = get_user_meta((int)$u->ID, 'nivel_predare', true);
      if (is_array($raw)) {
        foreach ($raw as $rv) if (in_array(es_normalize_level_code($rv), $nivel_codes, true)) return true;
        return false;
      }
      return in_array(es_normalize_level_code($raw), $nivel_codes, true);
    }));
  }
}

// an generație
if ($gen_year !== '') {
  $filtered = array_values(array_filter($filtered, function($u) use ($gens_by_prof, $gen_year){
    $pid = (int)$u->ID;
    if (empty($gens_by_prof[$pid])) return false;
    foreach ($gens_by_prof[$pid] as $g) {
      if ((string)$g->year === (string)$gen_year) return true;
    }
    return false;
  }));
}

// județ (multiselect)
if (!empty($county_arr)) {
  $filtered = array_values(array_filter($filtered, function($u) use ($counties_by_prof, $county_arr){
    $pid = (int)$u->ID;
    $ctys = $counties_by_prof[$pid] ?? [];
    return !empty(array_intersect($county_arr, $ctys));
  }));
}

// sortare finală
usort($filtered, fn($a,$b)=> strcasecmp($a->display_name, $b->display_name));

// Status profesor labels (pentru a exporta "În așteptare" etc., nu slug-ul)
$status_prof_labels = [
  'in_asteptare'         => 'În așteptare',
  'activ'                => 'Activ',
  'drop-out'             => 'Drop-out',
  'eliminat'             => 'Eliminat',
  'concediu_maternitate' => 'Concediu maternitate',
  'concediu_studii'      => 'Concediu studii',
];

// ------- pregătim CSV în ordinea cerută -------
$headers = [
  'ID',
  'Nume complet',
  'Prenume',
  'Nume',
  'Email',
  'Telefon',
  'Cod SLF',
  'Segment RSOI',
  'Generație Teach',
  'Status',
  'Statut',
  'Calificare',
  'Experiență',
  'Nivel predare',
  'Materia predată',
  'Altă materie',
  'An program',
  'Cohorte',
  'Tutor coordonator',
  'Mentor SEL',
  'Mentor Literație',
  'Mentor Numerație',
  'Școli asignate',
  'Județ',
  'Oraș',
  'Număr elevi',
  'Generații (id·nivel·an)',
  'Data înregistrare',
  'Ultima activitate',
];

$rows = [];
foreach ($filtered as $u) {
  $pid  = (int)$u->ID;
  $fn   = (string)($u->first_name ?? '');
  $ln   = (string)($u->last_name ?? '');
  $full = trim($fn.' '.$ln);
  if ($full === '') $full = $u->display_name ?: $u->user_login;

  $phone   = (string) get_user_meta($pid, 'phone', true);
  $cod     = (string) get_user_meta($pid, 'cod_slf', true);
  $rsoi_v  = (string) get_user_meta($pid, 'segment_rsoi', true);
  $teach_v = (string) get_user_meta($pid, 'generatie', true);

  // Status profesor (user_status_profesor) — etichetăm cu label uman
  $status_raw = (string) get_user_meta($pid, 'user_status_profesor', true);
  $status_lbl = $status_raw !== '' ? ($status_prof_labels[$status_raw] ?? $status_raw) : '';

  // Statut (statut_prof) — e text free form (Titular, Suplinitor etc.)
  $statut_v = (string) get_user_meta($pid, 'statut_prof', true);

  $calif   = (string) get_user_meta($pid, 'calificare', true);
  $exper   = (string) get_user_meta($pid, 'experienta', true);
  $nivel_v = get_user_meta($pid, 'nivel_predare', true);
  $mat     = (string) get_user_meta($pid, 'materia_predata', true);
  $mat_alt = (string) get_user_meta($pid, 'materia_alta', true);
  $an_prog = (string) get_user_meta($pid, 'an_program', true);
  $cohorte = (string) get_user_meta($pid, 'cohorte', true);

  // Tutor coordonator
  $tid   = (int) get_user_meta($pid, 'assigned_tutor_id', true);
  $tname = '';
  if ($tid > 0) {
    $tu = get_userdata($tid);
    if ($tu) $tname = es_user_fullname($tu);
  }

  // Mentori
  $mentor_id_sel = (int) get_user_meta($pid, 'mentor_sel', true);
  $mentor_id_lit = (int) get_user_meta($pid, 'mentor_literatie', true);
  $mentor_id_num = (int) get_user_meta($pid, 'mentor_numeratie', true);
  $mentor_name_sel = $mentor_id_sel ? ((($mu = get_userdata($mentor_id_sel)) ? es_user_fullname($mu) : '')) : '';
  $mentor_name_lit = $mentor_id_lit ? ((($mu = get_userdata($mentor_id_lit)) ? es_user_fullname($mu) : '')) : '';
  $mentor_name_num = $mentor_id_num ? ((($mu = get_userdata($mentor_id_num)) ? es_user_fullname($mu) : '')) : '';

  // Școli asignate — nume (cod SIRUTA), separate prin "|"
  $sids = get_user_meta($pid, 'assigned_school_ids', true);
  $school_parts = [];
  $school_cities = [];
  $school_counties = [];
  if (is_array($sids)) {
    foreach ($sids as $sid) {
      $sid = (int)$sid;
      if ($sid > 0 && isset($school_info[$sid])) {
        $inf  = $school_info[$sid];
        $part = $inf['name'];
        if ($inf['cod'] !== '') $part .= ' ('.$inf['cod'].')';
        $school_parts[] = $part;
        if ($inf['city']   !== '') $school_cities[$inf['city']] = true;
        if ($inf['county'] !== '') $school_counties[$inf['county']] = true;
      }
    }
  }
  $schools_str = implode(' | ', $school_parts);
  $judet_str   = implode('; ', array_keys($school_counties));
  $oras_str    = implode('; ', array_keys($school_cities));

  // Elevi
  $elevi = (int)($students_count[$pid] ?? 0);

  // Generații
  $gen_bits = [];
  if (!empty($gens_by_prof[$pid])) {
    foreach ($gens_by_prof[$pid] as $g) {
      $gen_bits[] = '#'.$g->id.'·'.es_level_label($g->level).'·'.$g->year;
    }
  }

  // Data înregistrare / ultima activitate
  $reg_ts = $u->user_registered ? strtotime($u->user_registered) : 0;
  $last   = get_user_meta($pid,'last_activity',true);
  if (!$last) $last = get_user_meta($pid,'last_login',true);
  if (!$last) $last = get_user_meta($pid,'last_seen',true);

  $rows[] = [
    $pid,                       // ID
    $full,                      // Nume complet
    $fn,                        // Prenume
    $ln,                        // Nume
    (string)$u->user_email,     // Email
    $phone,                     // Telefon
    $cod,                       // Cod SLF
    $rsoi_v,                    // Segment RSOI
    $teach_v,                   // Generație Teach
    $status_lbl,                // Status
    $statut_v,                  // Statut
    $calif,                     // Calificare
    $exper,                     // Experiență
    es_level_label($nivel_v),   // Nivel predare
    $mat,                       // Materia predată
    $mat_alt,                   // Altă materie
    $an_prog,                   // An program
    $cohorte,                   // Cohorte
    $tname,                     // Tutor coordonator
    $mentor_name_sel,           // Mentor SEL
    $mentor_name_lit,           // Mentor Literație
    $mentor_name_num,           // Mentor Numerație
    $schools_str,               // Școli asignate
    $judet_str,                 // Județ
    $oras_str,                  // Oraș
    $elevi,                     // Număr elevi
    implode(' | ', $gen_bits),  // Generații (id·nivel·an)
    $reg_ts ? es_format_dt($reg_ts) : '',  // Data înregistrare
    $last   ? es_format_dt($last)   : '',  // Ultima activitate
  ];
}

$filename = 'profesori_' . date('Y-m-d_His') . '.csv';
es_send_csv($filename, $headers, $rows);
