<?php

if (!function_exists('es_send_csv')) {
  status_header(500);
  echo 'Lipsește funcția es_send_csv(). Verifică functions.php.';
  exit;
}

global $wpdb;

/* === Tabele === */
$tbl_students     = $wpdb->prefix . 'edu_students';
$tbl_results      = $wpdb->prefix . 'edu_results';
$tbl_generations  = $wpdb->prefix . 'edu_generations';
$tbl_users        = $wpdb->users;
$tbl_schools      = $wpdb->prefix . 'edu_schools';
$tbl_cities       = $wpdb->prefix . 'edu_cities';
$tbl_counties     = $wpdb->prefix . 'edu_counties';
$tbl_classes      = $wpdb->prefix . 'edu_classes';

/* === Filtre din GET (aceleași ca în UI) === */
$s = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

// Profesor filter: acceptă prof[]=ID (nou) sau prof=ID (legacy)
$prof_arr = [];
if (isset($_GET['prof'])) {
  $raw = wp_unslash($_GET['prof']);
  if (is_array($raw)) {
    $prof_arr = array_values(array_filter(array_map('intval', $raw)));
  } else {
    $pid = (int)$raw;
    if ($pid > 0) $prof_arr = [$pid];
  }
}

// Filtru generație (folosit de pagina Profesor → Lista elevi)
$gen_filter = isset($_GET['gen']) ? (int) $_GET['gen'] : 0;

/* === Query elevi (fără paginare: exportă toate potrivirile) === */
$where  = 'WHERE 1=1';
$params = [];

if ($s !== '') {
  $like = '%' . $wpdb->esc_like($s) . '%';
  $where .= " AND (
    first_name LIKE %s
    OR last_name LIKE %s
    OR CONCAT_WS(' ', first_name, last_name) LIKE %s
  )";
  $params[] = $like; $params[] = $like; $params[] = $like;
}
if (!empty($prof_arr)) {
  $in_ph = implode(',', array_fill(0, count($prof_arr), '%d'));
  $where .= " AND professor_id IN ($in_ph)";
  foreach ($prof_arr as $pid) $params[] = $pid;
}
if ($gen_filter > 0) {
  $where .= " AND generation_id = %d";
  $params[] = $gen_filter;
}

$sql_all = "
  SELECT id, generation_id, class_label, professor_id, class_id,
         first_name, last_name, age, gender,
         observation, notes, sit_abs, frecventa, bursa, dif_limba,
         repeta_clasa, alte_obs, cauze_abs, risc_abandon,
         demers_familie, demers_conducere, demers_consilier,
         created_at, updated_at
  FROM {$tbl_students}
  {$where}
  ORDER BY id DESC
";
$students = !empty($params) ? $wpdb->get_results($wpdb->prepare($sql_all, $params))
                            : $wpdb->get_results($sql_all);

/* === Headere CSV (toate coloanele din tabel + derivate) === */
$headers = [
  'ID',
  'Prenume','Nume',
  'Nume complet',
  'Vârstă','Gen',
  'Clasă (label)','Class ID','Clasă (nume)',
  'SEL T0','SEL Ti','SEL T1',
  'LIT T0','LIT T1',
  'NUM T0','NUM T1',
  'Modificări statut elev','Repetă clasa','Alte observații','Mențiuni',
  'Sit. abs.','Cauze absenteism','Risc abandon',
  'Frecvență','Bursa','Dific. limbă',
  'Discuție cu familia','Discuție cu conducerea școlii','Consilier / mediator școlar',
  'Școală','Oraș','Județ',
  'Professor ID','Profesor','Email profesor',
  'Generation ID','Generație','An generație','Nivel generație',
  'Creat la','Actualizat la',
];

if (!$students) {
  es_send_csv('elevi_'.date('Y-m-d_His').'.csv', $headers, []);
}

/* === Bulk maps: profesori / generații === */
$prof_map = []; // pid => (object)[ID,name,schools[]]
$gen_map  = []; // gid => (object)[id,name]

$pids = array_values(array_unique(array_filter(array_map(fn($r)=> (int)$r->professor_id, $students))));
$gids = array_values(array_unique(array_filter(array_map(fn($r)=> (int)$r->generation_id, $students))));

if ($pids) {
  $in = implode(',', array_fill(0, count($pids), '%d'));
  $us = $wpdb->get_results($wpdb->prepare("SELECT ID, user_login, user_email, display_name FROM {$tbl_users} WHERE ID IN ($in)", ...$pids));
  foreach ($us as $u) {
    $fn = get_user_meta((int)$u->ID, 'first_name', true);
    $ln = get_user_meta((int)$u->ID, 'last_name',  true);
    $name = trim(($fn ?: $u->display_name).' '.($ln ?: ''));
    $sids = get_user_meta((int)$u->ID, 'assigned_school_ids', true);
    $prof_map[(int)$u->ID] = (object)[
      'ID'      => (int)$u->ID,
      'name'    => $name ?: ($u->display_name ?: $u->user_login),
      'email'   => (string)$u->user_email,
      'schools' => is_array($sids) ? array_values(array_filter(array_map('intval',$sids))) : [],
    ];
  }
}
if ($gids) {
  $in = implode(',', array_fill(0, count($gids), '%d'));
  $gs = $wpdb->get_results($wpdb->prepare("SELECT id, name, year, level FROM {$tbl_generations} WHERE id IN ($in)", ...$gids));
  foreach ($gs as $g) $gen_map[(int)$g->id] = $g;
}

/* === Class map === */
$class_map = []; // class_id => name
$cids = array_values(array_unique(array_filter(array_map(fn($r)=> (int)$r->class_id, $students))));
if ($cids) {
  $in = implode(',', array_fill(0, count($cids), '%d'));
  $cs = $wpdb->get_results($wpdb->prepare("SELECT id, name FROM {$tbl_classes} WHERE id IN ($in)", ...$cids));
  foreach ($cs as $c) $class_map[(int)$c->id] = (string)$c->name;
}

/* === Școli / Oraș / Județ (din profesor) === */
$school_cache = []; // school_id => ['name'=>..., 'city'=>..., 'county'=>..., 'city_id'=>..., 'county_id'=>...]
if ($prof_map) {
  $school_ids = [];
  foreach ($prof_map as $p) { foreach ($p->schools as $sid) $school_ids[$sid] = true; }
  $school_ids = array_keys($school_ids);

  if ($school_ids) {
    $in = implode(',', array_fill(0, count($school_ids), '%d'));
    $sch = $wpdb->get_results($wpdb->prepare("SELECT id, city_id, name FROM {$tbl_schools} WHERE id IN ($in)", ...$school_ids));
    $by_city = [];
    foreach ($sch as $s) {
      $school_cache[(int)$s->id] = ['name'=>$s->name, 'city_id'=>(int)$s->city_id, 'city'=>null, 'county'=>null, 'county_id'=>0];
      if ($s->city_id) $by_city[(int)$s->city_id] = true;
    }
    $city_ids = array_keys($by_city);
    if ($city_ids) {
      $in2 = implode(',', array_fill(0, count($city_ids), '%d'));
      $cities = $wpdb->get_results($wpdb->prepare("SELECT id, county_id, name FROM {$tbl_cities} WHERE id IN ($in2)", ...$city_ids));
      $county_to_get = [];
      $city_map = [];
      foreach ($cities as $c) { $city_map[(int)$c->id] = (object)['name'=>$c->name, 'county_id'=>(int)$c->county_id]; $county_to_get[(int)$c->county_id]=true; }
      foreach ($school_cache as $sid=>$sc) {
        $cid = $sc['city_id'];
        if (isset($city_map[$cid])) {
          $school_cache[$sid]['city'] = $city_map[$cid]->name;
          $school_cache[$sid]['county_id'] = $city_map[$cid]->county_id;
        }
      }
      $county_ids = array_keys($county_to_get);
      if ($county_ids) {
        $in3 = implode(',', array_fill(0, count($county_ids), '%d'));
        $cty = $wpdb->get_results($wpdb->prepare("SELECT id, name FROM {$tbl_counties} WHERE id IN ($in3)", ...$county_ids));
        $county_map = [];
        foreach ($cty as $r) $county_map[(int)$r->id] = $r->name;
        foreach ($school_cache as $sid=>$sc) {
          $cid = $school_cache[$sid]['county_id'] ?? 0;
          if ($cid && isset($county_map[$cid])) $school_cache[$sid]['county'] = $county_map[$cid];
        }
      }
    }
  }
}

/* === Rezultate (progres pe stagii) === */
$student_ids = array_map(fn($r)=> (int)$r->id, $students);
$results_map = []; // [sid][SEL/LIT/NUM][T0/Ti/T1] = ['pct'=>int]
if ($student_ids) {
  $in = implode(',', array_fill(0, count($student_ids), '%d'));
  $res = $wpdb->get_results($wpdb->prepare("
    SELECT id, student_id, modul_type, modul, status, created_at, completion
    FROM {$tbl_results}
    WHERE student_id IN ($in)
      AND modul_type IN ('sel','lit','num','literatie','numeratie','numeracy')
    ORDER BY student_id ASC, modul_type ASC, created_at DESC, id DESC
  ", ...$student_ids));

  foreach ($res as $r) {
    $sid = (int)$r->student_id;
    $raw = strtolower((string)$r->modul_type);
    $type = ($raw === 'numeracy' || $raw === 'numeratie') ? 'num' : (($raw === 'literatie') ? 'lit' : $raw);

    $mod = strtolower((string)$r->modul);
    $stage = null;
    $parts = preg_split('/[-_]/', $mod);
    if (isset($parts[1]) && in_array($parts[1], ['t0','ti','t1'], true)) {
      $stage = strtoupper($parts[1]);
    } elseif (preg_match('/(?:^|[-_])(t0|ti|t1)(?:$|[-_])/', $mod, $m)) {
      $stage = strtoupper($m[1]);
    }

    $pct = null;
    if (property_exists($r,'completion') && $r->completion !== null) {
      $pct = (int)$r->completion;
    } elseif (property_exists($r,'completition') && $r->completition !== null) { // fallback
      $pct = (int)$r->completition;
    }

    $K = null;
    if ($type === 'sel' && in_array($stage, ['T0','Ti','T1'], true)) $K='SEL';
    if ($type === 'lit' && in_array($stage, ['T0','T1'], true))      $K='LIT';
    if ($type === 'num' && in_array($stage, ['T0','T1'], true))      $K='NUM';

    if ($K && $stage && !isset($results_map[$sid][$K][$stage])) {
      $results_map[$sid][$K][$stage] = ['pct' => $pct];
    }
  }
}

/* === Helper gen === */
$gender_label = static function($g) {
  $g = strtoupper(trim((string)$g));
  if ($g === 'M') return 'M';
  if ($g === 'F') return 'F';
  return $g !== '' ? $g : '—';
};

/* === Rows CSV === */
$csv_rows = [];
foreach ($students as $r) {
  $sid   = (int)$r->id;
  $fname = (string)($r->first_name ?? '');
  $lname = (string)($r->last_name ?? '');
  $full  = trim($fname.' '.$lname);
  if ($full === '') $full = '—';

  $age   = ($r->age !== null && $r->age !== '') ? (string)$r->age : '';
  $gen   = $gender_label($r->gender ?? '');

  $class_label = (string)($r->class_label ?? '');
  $class_id    = (int)($r->class_id ?? 0);
  $class_name  = $class_id && isset($class_map[$class_id]) ? $class_map[$class_id] : '';

  $evals = $results_map[$sid] ?? [];
  $sel_t0 = isset($evals['SEL']['T0']['pct']) ? (int)$evals['SEL']['T0']['pct'] : '';
  $sel_ti = isset($evals['SEL']['Ti']['pct']) ? (int)$evals['SEL']['Ti']['pct'] : '';
  $sel_t1 = isset($evals['SEL']['T1']['pct']) ? (int)$evals['SEL']['T1']['pct'] : '';
  $lit_t0 = isset($evals['LIT']['T0']['pct']) ? (int)$evals['LIT']['T0']['pct'] : '';
  $lit_t1 = isset($evals['LIT']['T1']['pct']) ? (int)$evals['LIT']['T1']['pct'] : '';
  $num_t0 = isset($evals['NUM']['T0']['pct']) ? (int)$evals['NUM']['T0']['pct'] : '';
  $num_t1 = isset($evals['NUM']['T1']['pct']) ? (int)$evals['NUM']['T1']['pct'] : '';

  // Profesor
  $prof_id_raw = (int)($r->professor_id ?? 0);
  $prof        = $prof_id_raw && isset($prof_map[$prof_id_raw]) ? $prof_map[$prof_id_raw] : null;
  $prof_name   = $prof ? ($prof->name ?? '') : '';
  $prof_email  = $prof ? ($prof->email ?? '') : '';

  // Școala / Oraș / Județ (derivat din profesor)
  $school_label=''; $city_label=''; $county_label='';
  if ($prof && !empty($prof->schools)) {
    $primary_sid = (int)$prof->schools[0];
    $more = count($prof->schools) - 1;
    if (isset($school_cache[$primary_sid])) {
      $S = $school_cache[$primary_sid];
      $school_label = (string)$S['name'] . ($more>0 ? ' (+' . $more . ')' : '');
      $city_label   = (string)($S['city']   ?? '');
      $county_label = (string)($S['county'] ?? '');
    } else {
      $school_label = 'ID #'.$primary_sid . ($more>0 ? ' (+' . $more . ')' : '');
    }
  }

  // Generație
  $gen_id    = (int)($r->generation_id ?? 0);
  $gen_obj   = $gen_id && isset($gen_map[$gen_id]) ? $gen_map[$gen_id] : null;
  $gen_name  = $gen_obj ? ($gen_obj->name  ?: '#'.$gen_id) : '';
  $gen_year  = $gen_obj ? (string)($gen_obj->year  ?? '') : '';
  $gen_level = $gen_obj ? (string)($gen_obj->level ?? '') : '';

  $csv_rows[] = [
    $sid,
    $fname,
    $lname,
    $full,
    $age,
    $gen,
    $class_label,
    $class_id ?: '',
    $class_name,

    $sel_t0, $sel_ti, $sel_t1,
    $lit_t0, $lit_t1,
    $num_t0, $num_t1,

    (string)($r->observation ?? ''),
    (string)($r->repeta_clasa ?? ''),
    (string)($r->alte_obs ?? ''),
    (string)($r->notes ?? ''),
    (string)($r->sit_abs ?? ''),
    (string)($r->cauze_abs ?? ''),
    (string)($r->risc_abandon ?? ''),
    (string)($r->frecventa ?? ''),
    (string)($r->bursa ?? ''),
    (string)($r->dif_limba ?? ''),
    (string)($r->demers_familie ?? ''),
    (string)($r->demers_conducere ?? ''),
    (string)($r->demers_consilier ?? ''),

    $school_label,
    $city_label,
    $county_label,

    $prof_id_raw ?: '',
    $prof_name,
    $prof_email,

    $gen_id ?: '',
    $gen_name,
    $gen_year,
    $gen_level,

    (string)($r->created_at ?? ''),
    (string)($r->updated_at ?? ''),
  ];
}

/* === Trimite CSV === */
$filename = 'elevi_' . date('Y-m-d_His') . '.csv';
es_send_csv($filename, $headers, $csv_rows);
