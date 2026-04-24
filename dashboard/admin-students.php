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
$ajax_url_students   = admin_url('admin-ajax.php');
$ajax_nonce_students = wp_create_nonce('edu_nonce');

/* ——— Tabele exacte din schema ——— */
$tbl_students     = $wpdb->prefix . 'edu_students';      // id, generation_id, class_label, professor_id, class_id, first_name, last_name, age, gender, observation, notes, ..., sit_abs, frecventa, bursa, dif_limba
$tbl_results      = $wpdb->prefix . 'edu_results';       // id, student_id, modul_type, modul_id, status, results, created_at, professor_id, class_id, score, completion, modul
$tbl_generations  = $wpdb->prefix . 'edu_generations';   // id, name, professor_id, level, class_label, class_labels_json, year, created_at
$tbl_users        = $wpdb->users;
$tbl_schools      = $wpdb->prefix . 'edu_schools';       // id, city_id, name, ...
$tbl_cities       = $wpdb->prefix . 'edu_cities';        // id, county_id, name, ... 
$tbl_counties     = $wpdb->prefix . 'edu_counties';      // id, name

/* ================= Helpers UI ================= */
function es_link_btn($url, $text, $variant='primary') {
  $pal = [
    'primary' => 'bg-emerald-600 hover:bg-emerald-700 text-white',
    'ghost'   => 'bg-slate-100 hover:bg-slate-200 text-slate-800',
  ][$variant] ?? 'bg-emerald-600 hover:bg-emerald-700 text-white';
  return '<a class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-full shadow-sm '.$pal.'" href="'.esc_url($url).'">'.esc_html($text).'</a>';
}
function es_badge_progress($pct) {
  if ($pct === null || $pct === '') {
    return '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium bg-slate-100 text-slate-700 ring-1 ring-slate-200">—</span>';
  }
  $v = (int)$pct;
  if ($v < 50)       { $cls='bg-rose-50 text-rose-700 ring-rose-200'; }
  elseif ($v < 75)   { $cls='bg-amber-50 text-amber-700 ring-amber-200'; }
  elseif ($v < 90)   { $cls='bg-sky-50 text-sky-700 ring-sky-200'; }
  else               { $cls='bg-emerald-50 text-emerald-700 ring-emerald-200'; }
  return '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium '.$cls.' ring-1 ring-inset">'.$v.'%</span>';
}
function es_gender_label($g) {
  $g = strtoupper(trim((string)$g));
  if ($g === 'M') return 'M';
  if ($g === 'F') return 'F';
  return $g !== '' ? $g : '—';
}

/* ================= Filtre & paginare ================= */
$s            = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
// Profesor filter: accept prof[]=ID array (new) or prof=ID scalar (legacy)
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
$perpage      = max(10, min(200, (int)($_GET['perpage'] ?? 25)));
$paged        = max(1, (int)($_GET['paged'] ?? 1));
$offset       = ($paged - 1) * $perpage;

/* ================= Dropdown profesori (distinct din students) ================= */
$prof_options = [];
$prof_rows = $wpdb->get_results("SELECT DISTINCT professor_id FROM {$tbl_students} WHERE professor_id IS NOT NULL AND professor_id>0 ORDER BY professor_id ASC");
if ($prof_rows) {
  $pids = array_map(fn($o)=> (int)$o->professor_id, $prof_rows);
  if ($pids) {
    $in = implode(',', array_fill(0, count($pids), '%d'));
    $us = $wpdb->get_results($wpdb->prepare("SELECT ID, user_login, display_name FROM {$tbl_users} WHERE ID IN ($in)", ...$pids));
    foreach ($us as $u) {
      $fn = get_user_meta((int)$u->ID, 'first_name', true);
      $ln = get_user_meta((int)$u->ID, 'last_name',  true);
      $name = trim(($fn ?: $u->display_name).' '.($ln ?: ''));
      $prof_options[(int)$u->ID] = $name ?: $u->user_login;
    }
    asort($prof_options, SORT_NATURAL | SORT_FLAG_CASE);
  }
}

/* ================= Query elevi (wp_edu_students) ================= */
$where  = 'WHERE 1=1';
$params = [];

if ($s !== '') {
  $like = '%' . $wpdb->esc_like($s) . '%';
  // doar numele elevului (prenume, nume, sau ambele în combinație)
  $where .= " AND (
    first_name LIKE %s
    OR last_name LIKE %s
    OR CONCAT_WS(' ', first_name, last_name) LIKE %s
  )";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}
if (!empty($prof_arr)) {
  $in_ph = implode(',', array_fill(0, count($prof_arr), '%d'));
  $where .= " AND professor_id IN ($in_ph)";
  foreach ($prof_arr as $pid) $params[] = $pid;
}

/* Total */
$sql_total = "SELECT COUNT(*) FROM {$tbl_students} {$where}";
$total = (int)(
  !empty($params) ? $wpdb->get_var($wpdb->prepare($sql_total, $params))
                  : $wpdb->get_var($sql_total)
);

/* Pagină */
$sql_page = "
  SELECT id, generation_id, class_label, professor_id, class_id,
         first_name, last_name, age, gender,
         observation, notes, sit_abs, frecventa, bursa, dif_limba,
         repeta_clasa, alte_obs, cauze_abs, risc_abandon,
         demers_familie, demers_conducere, demers_consilier
  FROM {$tbl_students}
  {$where}
  ORDER BY id DESC
  LIMIT %d OFFSET %d
";
if (!empty($params)) {
  $args_all = array_merge($params, [ (int)$perpage, (int)$offset ]);
  $rows = $wpdb->get_results( $wpdb->prepare($sql_page, $args_all) );
} else {
  $rows = $wpdb->get_results( $wpdb->prepare($sql_page, (int)$perpage, (int)$offset) );
}

$student_ids = $rows ? array_map(fn($r)=> (int)$r->id, $rows) : [];

/* ================= Profesori & Generații (bulk) ================= */
$prof_map = []; // pid => obj {ID, name, assigned_school_ids[]}
$gen_map  = []; // gid => obj {id, name}

if ($rows) {
  // Profesori
  $pids = array_values(array_unique(array_filter(array_map(fn($r)=> (int)$r->professor_id, $rows))));
  if ($pids) {
    $in = implode(',', array_fill(0, count($pids), '%d'));
    $us = $wpdb->get_results($wpdb->prepare("SELECT ID, user_login, display_name FROM {$tbl_users} WHERE ID IN ($in)", ...$pids));
    foreach ($us as $u) {
      $fn = get_user_meta((int)$u->ID, 'first_name', true);
      $ln = get_user_meta((int)$u->ID, 'last_name',  true);
      $name = trim(($fn ?: $u->display_name).' '.($ln ?: ''));
      $sids = get_user_meta((int)$u->ID, 'assigned_school_ids', true);
      $prof_map[(int)$u->ID] = (object)[
        'ID'   => (int)$u->ID,
        'name' => $name ?: ($u->display_name ?: $u->user_login),
        'schools' => is_array($sids) ? array_values(array_filter(array_map('intval',$sids))) : [],
      ];
    }
  }
  // Generații
  $gids = array_values(array_unique(array_filter(array_map(fn($r)=> (int)$r->generation_id, $rows))));
  if ($gids) {
    $in = implode(',', array_fill(0, count($gids), '%d'));
    $gs = $wpdb->get_results($wpdb->prepare("SELECT id, name FROM {$tbl_generations} WHERE id IN ($in)", ...$gids));
    foreach ($gs as $g) $gen_map[(int)$g->id] = $g;
  }
}

/* ================= Școli / Oraș / Județ (derivat din profesor) ================= */
$school_cache = []; // school_id => ['name'=>..., 'city'=>..., 'county'=>..., 'city_id'=>...]
if (!empty($prof_map)) {
  $school_ids = [];
  foreach ($prof_map as $p) { foreach ($p->schools as $sid) $school_ids[$sid] = true; }
  $school_ids = array_keys($school_ids);
  if ($school_ids) {
    $in = implode(',', array_fill(0, count($school_ids), '%d'));
    $sch = $wpdb->get_results($wpdb->prepare("SELECT id, city_id, name FROM {$tbl_schools} WHERE id IN ($in)", ...$school_ids));
    $by_city = [];
    foreach ($sch as $school_row) {
      $school_cache[(int)$school_row->id] = [
        'name'    => $school_row->name,
        'city_id' => (int)$school_row->city_id,
        'city'    => null,
        'county'  => null
      ];
      if ($school_row->city_id) {
        $by_city[(int)$school_row->city_id] = true;
      }
    }
    $city_ids = array_keys($by_city);
    if ($city_ids) {
      $in2 = implode(',', array_fill(0, count($city_ids), '%d'));
      $cities = $wpdb->get_results($wpdb->prepare("SELECT id, county_id, name FROM {$tbl_cities} WHERE id IN ($in2)", ...$city_ids));
      $counties_to_get = [];
      $city_map = [];
      foreach ($cities as $c) { $city_map[(int)$c->id] = (object)['name'=>$c->name, 'county_id'=>(int)$c->county_id]; $counties_to_get[(int)$c->county_id] = true; }
      foreach ($school_cache as $sid=>$sc) {
        $cid = $sc['city_id'];
        if (isset($city_map[$cid])) {
          $school_cache[$sid]['city'] = $city_map[$cid]->name;
          $school_cache[$sid]['county_id'] = $city_map[$cid]->county_id;
        }
      }
      $county_ids = array_keys($counties_to_get);
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

/* ================= Evaluări (wp_edu_results) — progres pe stagiu =================
   - modul_type: 'sel' | 'lit' | 'num' (pot apărea și 'literatie' / 'numeratie' — normalizăm)
   - modul: ex. 'sel-t0-primar-mare' -> extragem 't0' ca stagiu
   - progres: coloana 'completion' (fallback: 'completition' dacă ar exista)
*/
$results_map = []; // [sid][TYPE][STAGE] = ['pct'=>int, 'id'=>..., 'created_at'=>..., 'modul'=>...]
if ($student_ids) {
  $in = implode(',', array_fill(0, count($student_ids), '%d'));
  $res = $wpdb->get_results($wpdb->prepare("
    SELECT id, student_id, modul_type, modul, status, created_at, completion
    FROM {$tbl_results}
    WHERE student_id IN ($in)
      AND modul_type IN ('sel','lit','num','literatie','numeratie','numeracy')
    ORDER BY student_id ASC, modul_type ASC, created_at DESC, id DESC
  ", ...$student_ids));

  // fallback pentru instalații unde numele coloanei e greșit (completition)
  // încercăm să citim din row ca proprietate dinamică
  foreach ($res as $r) {
    $sid  = (int)$r->student_id;
    $type_raw = strtolower(trim((string)$r->modul_type));
    if ($type_raw === 'numeracy' || $type_raw === 'numeratie') $type = 'num';
    elseif ($type_raw === 'literatie') $type = 'lit';
    else $type = $type_raw; // sel/lit/num

    $mod = strtolower(trim((string)$r->modul));
    // extrag stagiu după schema [*]-[t0|ti|t1]-[*]
    $stage = null;
    $parts = preg_split('/[-_]/', $mod);
    if (isset($parts[1]) && in_array($parts[1], ['t0','ti','t1'], true)) {
      $stage = strtoupper($parts[1]); // T0/Ti/T1
    } else {
      if (preg_match('/(?:^|[-_])(t0|ti|t1)(?:$|[-_])/', $mod, $m)) {
        $stage = strtoupper($m[1]);
      }
    }

    $pct = null;
    if (property_exists($r, 'completion') && $r->completion !== null) {
      $pct = (int)$r->completion;
    } elseif (property_exists($r, 'completition') && $r->completition !== null) {
      $pct = (int)$r->completition;
    }

    $ok = false; $K=null;
    if ($type === 'sel' && in_array($stage, ['T0','Ti','T1'], true)) { $K='SEL'; $ok=true; }
    if ($type === 'lit' && in_array($stage, ['T0','T1'], true))      { $K='LIT'; $ok=true; }
    if ($type === 'num' && in_array($stage, ['T0','T1'], true))      { $K='NUM'; $ok=true; }

    if ($ok && $K && $stage) {
      if (!isset($results_map[$sid][$K][$stage])) {
        $results_map[$sid][$K][$stage] = [
          'pct'        => $pct,
          'id'         => (int)$r->id,
          'created_at' => $r->created_at,
          'modul'      => (string)$r->modul,
        ];
      }
    }
  }
}

/* ================= Build UI ================= */
// base url fără paged (păstrăm prof[] ca array)
$qs = $_GET;
unset($qs['paged']);
foreach ($qs as $kk => $vv) {
  if ($kk === 'prof') continue; // preserve prof[] as array
  if (is_array($vv) || is_object($vv)) {
    unset($qs[$kk]);
  } else {
    $qs[$kk] = (string) $vv;
  }
}
$base_url = esc_url( add_query_arg( $qs, remove_query_arg('paged') ) );
$total_pages = (int) max(1, ceil($total / $perpage));
$paged       = min($paged, $total_pages);

/* ================= Coloane toggle (fixe: id, name, raport) ================= */
$COLS = [
  'id'               => ['label'=>'ID',          'fixed'=>true],
  'name'             => ['label'=>'Nume elev',   'fixed'=>true],
  'age'              => ['label'=>'Vârstă'],
  'gender'           => ['label'=>'Gen'],
  'class'            => ['label'=>'Clasă'],

  'sel_t0'           => ['label'=>'SEL T0'],
  'sel_ti'           => ['label'=>'SEL Ti'],
  'sel_t1'           => ['label'=>'SEL T1'],
  'lit_t0'           => ['label'=>'LIT T0'],
  'lit_t1'           => ['label'=>'LIT T1'],
  'num_t0'           => ['label'=>'NUM T0'],
  'num_t1'           => ['label'=>'NUM T1'],

  'observation'      => ['label'=>'Modificări statut elev'],
  'repeta_clasa'     => ['label'=>'Repetă clasa'],
  'alte_obs'         => ['label'=>'Alte observații'],
  'notes'            => ['label'=>'Mențiuni'],
  'sit_abs'          => ['label'=>'Sit. abs.'],
  'cauze_abs'        => ['label'=>'Cauze absenteism'],
  'risc_abandon'     => ['label'=>'Risc abandon'],
  'frecventa'        => ['label'=>'Frecvență'],
  'bursa'            => ['label'=>'Bursa'],
  'dif_limba'        => ['label'=>'Dific. limbă'],
  'demers_familie'   => ['label'=>'Discuție cu familia'],
  'demers_conducere' => ['label'=>'Discuție cu conducerea școlii'],
  'demers_consilier' => ['label'=>'Consilier / mediator școlar'],

  'school'           => ['label'=>'Școală'],
  'city'             => ['label'=>'Oraș'],
  'county'           => ['label'=>'Județ'],

  'report'           => ['label'=>'Raport',      'fixed'=>true],
  'prof'             => ['label'=>'Profesor'],
  'gen'              => ['label'=>'Generație'],
];

$export_args = [
  'action'   => 'es_export_students',
  '_wpnonce' => wp_create_nonce('es_export_students'),
  's'        => isset($_GET['s']) ? (string)$_GET['s'] : '',
];
if (!empty($prof_arr)) $export_args['prof'] = $prof_arr; // array -> ?prof[]=1&prof[]=2
$export_url = add_query_arg($export_args, admin_url('admin-post.php'));

$s_safe = is_scalar($s) ? $s : '';

?>

<section class="sticky top-0 z-20 border-b inner-submenu bg-slate-800 border-slate-200">
  <div class="relative z-20 flex items-center justify-between px-2 py-2 gap-x-2">
    <div class="flex items-center justify-start">

    </div>

    <div class="flex items-center justify-end gap-x-2">
      <button id="cols-toggle-btn" type="button" 
        class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
        <svg class="w-3 h-3 mobile:w-5 mobile:h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        <span class="mobile:hidden">Arata coloane</span>
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

<section class="px-6 mt-4 mb-6 mobile:px-2">
  <form method="get" class="grid items-end grid-cols-1 gap-3 md:grid-cols-12">
    <!-- Căutare -->
    <div class="relative md:col-span-4">
      <label class="block mb-1 text-xs font-medium text-slate-600">Caută elev</label>
      <input
        id="student-q"
        type="search"
        name="s"
        value="<?php echo esc_attr($s_safe); ?>"
        placeholder="Caută elev (prenume, nume)"
        class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-700 focus:border-transparent"
        inputmode="search" autocomplete="off"
        data-ajax="<?php echo esc_url($ajax_url_students); ?>"
        data-nonce="<?php echo esc_attr($ajax_nonce_students); ?>"
      />
      <!-- panou sugestii -->
      <div id="student-suggest"
          class="absolute z-20 hidden w-full mt-1 overflow-auto bg-white border rounded-lg shadow-lg max-h-72 border-slate-200"></div>
    </div>

    <!-- Profesor (multiselect) -->
    <div class="relative md:col-span-4" id="stud-prof-wrap">
      <label class="block mb-1 text-xs font-medium text-slate-600">Profesor</label>
      <div id="stud-prof-inputs">
        <?php foreach ($prof_arr as $pid): ?>
          <input type="hidden" name="prof[]" value="<?php echo (int)$pid; ?>">
        <?php endforeach; ?>
      </div>
      <div id="stud-prof-trigger" class="flex items-center gap-1 w-full min-h-[38px] px-3 py-1.5 text-sm bg-white border shadow-sm rounded-xl border-slate-300 cursor-pointer hover:border-slate-400 flex-wrap">
        <?php if (empty($prof_arr)): ?>
          <span class="ms-placeholder text-slate-400">— Toți profesorii —</span>
        <?php else: ?>
          <?php foreach ($prof_arr as $pid):
            $lbl = isset($prof_options[$pid]) ? $prof_options[$pid] : ('#' . (int)$pid);
          ?>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 ms-tag" data-val="<?php echo (int)$pid; ?>">
              <?php echo esc_html($lbl); ?>
              <button type="button" class="ms-remove hover:text-red-600">&times;</button>
            </span>
          <?php endforeach; ?>
        <?php endif; ?>
        <svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
      </div>
      <div id="stud-prof-dropdown" class="absolute z-30 hidden w-full py-1 mt-1 overflow-auto bg-white border shadow-lg rounded-xl border-slate-200 max-h-72">
        <div class="sticky top-0 px-2 pt-1 pb-2 bg-white border-b border-slate-100">
          <input type="text" id="stud-prof-search" placeholder="Caută profesor..."
                 class="w-full px-2 py-1 text-sm bg-white border rounded-lg border-slate-200 focus:outline-none focus:ring-1 focus:ring-sky-500">
        </div>
        <?php foreach ($prof_options as $pid => $pname):
          $on = in_array((int)$pid, $prof_arr, true);
        ?>
          <div class="ms-opt flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50"
               data-val="<?php echo (int)$pid; ?>" data-name="<?php echo esc_attr(mb_strtolower(remove_accents((string)$pname))); ?>">
            <span class="inline-flex items-center justify-center w-4 h-4 rounded border ms-check <?php echo $on ? 'bg-sky-600 border-sky-600' : 'bg-white border-slate-300'; ?>">
              <?php if ($on): ?>
                <svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
              <?php endif; ?>
            </span>
            <span><?php echo esc_html($pname); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Per page -->
    <div class="md:col-span-2">
      <label class="block mb-1 text-xs font-medium text-slate-600">Pe pagină</label>
      <select name="perpage" class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-700 focus:border-transparent">
        <?php foreach ([25,50,100,150,200] as $pp): ?>
          <option value="<?php echo (int)$pp; ?>" <?php selected($perpage===$pp); ?>><?php echo (int)$pp; ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex items-end gap-2 md:col-span-2 md:justify-end">
      <button type="submit"
              class="inline-flex items-center justify-center w-full gap-2 px-3 py-2 text-sm font-medium text-white shadow-sm rounded-xl bg-slate-800 hover:bg-slate-900">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
            <path fill-rule="evenodd" d="M3.792 2.938A49.069 49.069 0 0 1 12 2.25c2.797 0 5.54.236 8.209.688a1.857 1.857 0 0 1 1.541 1.836v1.044a3 3 0 0 1-.879 2.121l-6.182 6.182a1.5 1.5 0 0 0-.439 1.061v2.927a3 3 0 0 1-1.658 2.684l-1.757.878A.75.75 0 0 1 9.75 21v-5.818a1.5 1.5 0 0 0-.44-1.06L3.13 7.938a3 3 0 0 1-.879-2.121V4.774c0-.897.64-1.683 1.542-1.836Z" clip-rule="evenodd" />
        </svg>
        Filtrează
      </button>
    </div>
  </form>
</section>

<!-- Toggle coloane -->
<section id="cols-section" class="hidden px-6 mb-4 -mt-2 mobile:px-2">
  <div class="p-3 bg-white border shadow-sm rounded-xl border-slate-200">
    <div class="mb-2 text-xs font-medium text-slate-600">Afișează/ascunde coloane</div>
    <div class="flex flex-wrap gap-3 text-sm" id="cols-toggle">
      <?php foreach ($COLS as $k=>$meta): ?>
        <label class="inline-flex items-center gap-2 select-none">
          <input type="checkbox" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                 data-col="<?php echo esc_attr((string)$k); ?>" <?php echo !(!empty($meta['fixed'])) ? 'checked' : 'checked disabled'; ?>>
          <span class="text-slate-700"><?php echo esc_html($meta['label']); ?><?php echo !empty($meta['fixed'])? ' *' : ''; ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <p class="mt-1 text-[11px] text-slate-500">* Coloane fixe (nu pot fi ascunse): ID, Nume elev, Raport</p>
  </div>
</section>

<section class="px-6 pb-8 mobile:px-2 mobile:pb-12">
  <div class="relative overflow-auto bg-white border shadow-sm rounded-2xl border-slate-200 max-h-[610px]">
    <table class="relative w-full text-sm table-auto" id="stud-table">
      <thead class="sticky top-0 bg-sky-800 backdrop-blur">
        <tr class="text-white">
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="id">ID</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="name">Nume și prenume</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="age">Vârstă</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="gender">Gen</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="class">Clasă</th>

          <!-- SEL -->
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="sel_t0">SEL T0</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="sel_ti">SEL Ti</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="sel_t1">SEL T1</th>

          <!-- LIT -->
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="lit_t0">LIT T0</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="lit_t1">LIT T1</th>

          <!-- NUM -->
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="num_t0">NUM T0</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="num_t1">NUM T1</th>

          <!-- Extra elev -->
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="observation">Modificări statut elev</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="repeta_clasa">Repetă clasa</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="alte_obs">Alte observații</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="notes">Mențiuni</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="sit_abs">Sit. abs.</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="cauze_abs">Cauze absenteism</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="risc_abandon">Risc abandon</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="frecventa">Frecvență</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="bursa">Bursa</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="dif_limba">Dific. limbă</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="demers_familie">Discuție cu familia</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="demers_conducere">Discuție cu conducerea școlii</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="demers_consilier">Consilier / mediator școlar</th>

          <!-- Școala / Oraș / Județ -->
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200" data-col="school">Școală</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="city">Oraș</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="county">Județ</th>

          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="report">Raport</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="prof">Profesor</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200" data-col="gen">Generație</th>
        </tr>
      </thead>
      <tbody class="[&>tr:nth-child(odd)]:bg-slate-50/40">
      <?php if ($rows): ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $sid   = (int)$r->id;
            $name  = trim((string)$r->first_name.' '.(string)$r->last_name);
            if ($name === '') $name = '—';
            $search_blob = strtolower( remove_accents( trim((string)$r->first_name . ' ' . (string)$r->last_name) ) );
            $age   = (string)($r->age ?? '');
            $age   = $age !== '' ? $age : '—';
            $gen   = es_gender_label($r->gender ?? '');
            $class = trim((string)($r->class_label ?? '')) ?: '—';

            $evals = $results_map[$sid] ?? [];

            $sel_t0 = es_badge_progress( $evals['SEL']['T0']['pct'] ?? null );
            $sel_ti = es_badge_progress( $evals['SEL']['Ti']['pct'] ?? null );
            $sel_t1 = es_badge_progress( $evals['SEL']['T1']['pct'] ?? null );

            $lit_t0 = es_badge_progress( $evals['LIT']['T0']['pct'] ?? null );
            $lit_t1 = es_badge_progress( $evals['LIT']['T1']['pct'] ?? null );

            $num_t0 = es_badge_progress( $evals['NUM']['T0']['pct'] ?? null );
            $num_t1 = es_badge_progress( $evals['NUM']['T1']['pct'] ?? null );

            $report_url = home_url('/panou/raport/elev/'.$sid.'/');

            $prof_id   = (int)($r->professor_id ?? 0);
            $prof      = $prof_id && isset($prof_map[$prof_id]) ? $prof_map[$prof_id] : null;
            $prof_name = $prof ? $prof->name : '—';
            $prof_link = $prof ? home_url('/panou/profesor/'.$prof->ID.'/') : '';

            $gen_id    = (int)($r->generation_id ?? 0);
            $gen_obj   = $gen_id && isset($gen_map[$gen_id]) ? $gen_map[$gen_id] : null;
            $gen_name  = $gen_obj ? ($gen_obj->name ?: '#'.$gen_id) : '—';
            $gen_link  = $gen_obj ? home_url('/panou/generatia/'.$gen_id.'/') : '';

            // Școala/Oraș/Județ (din prima școală a profesorului)
            $school_label = '—'; $city_label='—'; $county_label='—';
            if ($prof && !empty($prof->schools)) {
              $primary_sid = (int)$prof->schools[0];
              $more = count($prof->schools) - 1;
              if (isset($school_cache[$primary_sid])) {
                $S = $school_cache[$primary_sid];
                $school_label = $S['name'] . ($more>0 ? ' (+' . $more . ')' : '');
                $city_label   = $S['city']   ?: '—';
                $county_label = $S['county'] ?: '—';
              } else {
                $school_label = 'ID #'.$primary_sid . ($more>0 ? ' (+' . $more . ')' : '');
              }
            }
          ?>
          <tr class="transition-colors border-b border-slate-200 hover:bg-slate-50 odd:bg-white even:bg-slate-200" data-search="<?php echo esc_attr($search_blob); ?>">
            <td class="px-3 py-3 align-top font-mono text-[12px] text-slate-600" data-col="id">#<?php echo (int)$sid; ?></td>
            <td class="px-3 py-3 font-medium align-top text-slate-900" data-col="name"><?php echo esc_html($name); ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="age"><?php echo esc_html($age); ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="gender"><?php echo esc_html($gen); ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="class"><?php echo esc_html($class); ?></td>

            <td class="px-3 py-3 text-center align-top" data-col="sel_t0"><?php echo $sel_t0; ?></td>
            <td class="px-3 py-3 text-center align-top" data-col="sel_ti"><?php echo $sel_ti; ?></td>
            <td class="px-3 py-3 text-center align-top" data-col="sel_t1"><?php echo $sel_t1; ?></td>

            <td class="px-3 py-3 text-center align-top" data-col="lit_t0"><?php echo $lit_t0; ?></td>
            <td class="px-3 py-3 text-center align-top" data-col="lit_t1"><?php echo $lit_t1; ?></td>

            <td class="px-3 py-3 text-center align-top" data-col="num_t0"><?php echo $num_t0; ?></td>
            <td class="px-3 py-3 text-center align-top" data-col="num_t1"><?php echo $num_t1; ?></td>

            <td class="px-3 py-3 align-top text-slate-800" data-col="observation"><?php echo $r->observation ? esc_html($r->observation) : '—'; ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="repeta_clasa"><?php echo $r->repeta_clasa ? esc_html($r->repeta_clasa) : '—'; ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="alte_obs"><?php echo $r->alte_obs ? esc_html($r->alte_obs) : '—'; ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="notes"><?php echo $r->notes ? esc_html($r->notes) : '—'; ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="sit_abs"><?php echo $r->sit_abs ? esc_html($r->sit_abs) : '—'; ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="cauze_abs"><?php echo $r->cauze_abs ? esc_html($r->cauze_abs) : '—'; ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="risc_abandon"><?php echo $r->risc_abandon ? esc_html($r->risc_abandon) : '—'; ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="frecventa"><?php echo $r->frecventa ? esc_html($r->frecventa) : '—'; ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="bursa"><?php echo $r->bursa ? esc_html($r->bursa) : '—'; ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="dif_limba"><?php echo $r->dif_limba ? esc_html($r->dif_limba) : '—'; ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="demers_familie"><?php echo $r->demers_familie ? esc_html($r->demers_familie) : '—'; ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="demers_conducere"><?php echo $r->demers_conducere ? esc_html($r->demers_conducere) : '—'; ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="demers_consilier"><?php echo $r->demers_consilier ? esc_html($r->demers_consilier) : '—'; ?></td>

            <td class="px-3 py-3 align-top text-slate-800" data-col="school"><?php echo esc_html($school_label); ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="city"><?php echo esc_html($city_label); ?></td>
            <td class="px-3 py-3 align-top text-slate-800" data-col="county"><?php echo esc_html($county_label); ?></td>

            <td class="px-3 py-3 align-top" data-col="report">
              <a href="<?php echo esc_url($report_url); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center text-emerald-800">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4">
                  <path fill-rule="evenodd" d="M5.625 1.5c-1.036 0-1.875.84-1.875 1.875v17.25c0 1.035.84 1.875 1.875 1.875h12.75c1.035 0 1.875-.84 1.875-1.875V12.75A3.75 3.75 0 0 0 16.5 9h-1.875a1.875 1.875 0  0 1-1.875-1.875V5.25A3.75 3.75 0  0 0 9 1.5H5.625ZM7.5 15a.75.75 0  0 1 .75-.75h7.5a.75.75 0  0 1 0 1.5h-7.5A.75.75 0  0 1 7.5 15Zm.75 2.25a.75.75 0  0 0 0 1.5H12a.75.75 0  0 0 0-1.5H8.25Z" clip-rule="evenodd" />
                  <path d="M12.971 1.816A5.23 5.23 0  0 1 14.25 5.25v1.875c0 .207.168.375.375.375H16.5a5.23 5.23 0  0 1 3.434 1.279 9.768 9.768 0  0 0-6.963-6.963Z" />
                </svg>
              </a>
            </td>

            <td class="px-3 py-3 align-top" data-col="prof">
              <?php echo $prof ? es_link_btn($prof_link, $prof_name, 'ghost') : '<span class="text-slate-500">—</span>'; ?>
            </td>

            <td class="px-3 py-3 align-top" data-col="gen">
              <?php echo $gen_obj ? es_link_btn($gen_link, $gen_name, 'ghost') : '<span class="text-slate-500">—</span>'; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="<?php echo count($COLS); ?>" class="px-4 py-6 text-center text-slate-500">Nu s-au găsit elevi pentru criteriul curent.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginare -->
  <?php if ($total_pages > 1): ?>
    <div class="flex items-center justify-between mt-4">
      <div class="text-sm text-slate-600">
        Afișezi <strong><?php echo (int)min($total, $offset + $perpage); ?></strong> din <strong><?php echo (int)$total; ?></strong> elevi.
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

<!-- JS: Profesor multiselect dropdown -->
<script>
(function(){
  const prefix = 'stud-prof';
  const inputName = 'prof[]';
  const wrap     = document.getElementById(prefix + '-wrap');
  const trigger  = document.getElementById(prefix + '-trigger');
  const dropdown = document.getElementById(prefix + '-dropdown');
  const inputs   = document.getElementById(prefix + '-inputs');
  const search   = document.getElementById(prefix + '-search');
  if (!wrap || !trigger || !dropdown || !inputs) return;

  const placeholder = '— Toți profesorii —';
  const checkSvg = '<svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
  const arrow    = '<svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';

  // selected = list of option ids currently checked (strings)
  let selected = Array.from(dropdown.querySelectorAll('.ms-opt .ms-check.bg-sky-600'))
    .map(el => el.closest('.ms-opt')?.dataset.val).filter(Boolean);

  // option label map
  const optLabels = {};
  dropdown.querySelectorAll('.ms-opt').forEach(opt => {
    optLabels[opt.dataset.val] = opt.querySelector('span:last-child')?.textContent || ('#' + opt.dataset.val);
  });

  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

  function renderTrigger(){
    inputs.innerHTML = selected.map(v => `<input type="hidden" name="${inputName}" value="${v}">`).join('');
    const tags = selected.map(v => {
      const lab = optLabels[v] || ('#' + v);
      return `<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 ms-tag" data-val="${v}">
        ${escapeHtml(lab)}<button type="button" class="ms-remove hover:text-red-600">&times;</button></span>`;
    }).join('');
    trigger.innerHTML = selected.length ? tags + arrow
      : `<span class="ms-placeholder text-slate-400">${placeholder}</span>` + arrow;
  }

  function renderOpts(){
    dropdown.querySelectorAll('.ms-opt').forEach(opt => {
      const v = opt.dataset.val, isOn = selected.includes(v);
      const box = opt.querySelector('.ms-check');
      box.className = 'inline-flex items-center justify-center w-4 h-4 rounded border ms-check ' + (isOn ? 'bg-sky-600 border-sky-600' : 'bg-white border-slate-300');
      box.innerHTML = isOn ? checkSvg : '';
    });
  }

  function applySearch(q){
    const qn = (q || '').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu, '').trim();
    dropdown.querySelectorAll('.ms-opt').forEach(opt => {
      const hay = (opt.dataset.name || '');
      opt.style.display = (!qn || hay.includes(qn)) ? '' : 'none';
    });
  }

  trigger.addEventListener('click', (e) => {
    if (e.target.closest('.ms-remove')) {
      const tag = e.target.closest('.ms-tag');
      if (tag?.dataset.val) {
        selected = selected.filter(v => v !== tag.dataset.val);
        renderTrigger(); renderOpts();
      }
      return;
    }
    dropdown.classList.toggle('hidden');
    if (!dropdown.classList.contains('hidden') && search) {
      search.value = '';
      applySearch('');
      search.focus();
    }
  });

  dropdown.addEventListener('click', (e) => {
    const opt = e.target.closest('.ms-opt');
    if (!opt) return;
    const val = opt.dataset.val;
    if (selected.includes(val)) selected = selected.filter(v => v !== val);
    else selected.push(val);
    renderTrigger(); renderOpts();
  });

  search?.addEventListener('input', () => applySearch(search.value));
  search?.addEventListener('click', (e) => e.stopPropagation());

  document.addEventListener('click', (e) => {
    if (!e.target.closest('#' + prefix + '-wrap')) dropdown.classList.add('hidden');
  });

  renderTrigger();
})();
</script>

<!-- JS: toggle coloane cu persistenta in localStorage -->
<script>
(function(){
  const toggleBtnBar = document.getElementById('cols-toggle-btn');
  if(!toggleBtnBar) return;
  const toggleBar = document.getElementById('cols-section');
  if(!toggleBar) return;

  const KEY = 'admin_students_cols_v1';
  const toggles = document.querySelectorAll('#cols-toggle input[type="checkbox"][data-col]');
  const table   = document.getElementById('stud-table');
  if(!table) return;

  const fixed = new Set(['id','name','report']); // coloane care nu pot fi ascunse

  function apply(state){
    toggleBtnBar.addEventListener('click', () => {
      if (toggleBar.classList.contains('hidden')) {
        toggleBar.classList.remove('hidden');
      } else {
        toggleBar.classList.add('hidden');
      }
    });
    toggles.forEach(cb=>{
      const k = cb.getAttribute('data-col');
      const allowed = !fixed.has(k);
      const on = state[k] !== false || fixed.has(k); // default ON, dar fixe mereu ON
      cb.checked = on;
      cb.disabled = !allowed;
      table.querySelectorAll('[data-col="'+k+'"]').forEach(el=>{
        el.style.display = on ? '' : 'none';
      });
    });
  }

  // init state
  let state = {};
  try { state = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch(e){ state = {}; }
  apply(state);

  // changes
  toggles.forEach(cb=>{
    cb.addEventListener('change', ()=>{
      const k = cb.getAttribute('data-col');
      if (fixed.has(k)) { cb.checked = true; return; }
      state[k] = cb.checked;
      localStorage.setItem(KEY, JSON.stringify(state));
      apply(state);
    });
  });
})();
</script>

<script>
(function(){
  const $q       = document.getElementById('student-q');
  const $panel   = document.getElementById('student-suggest');
  const $table   = document.getElementById('stud-table');
  if(!$q || !$panel || !$table) return;

  const rows = Array.from($table.querySelectorAll('tbody tr[data-search]'));

  // prevenim submit pe Enter în câmpul de căutare
  $q.addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); } });

  // helper: normalizează diacriticele (pt. potrivire fără diacritice)
  const norm = (s)=> (s||'').toString().toLowerCase()
                   .normalize('NFD').replace(/\p{Diacritic}/gu,'').trim();

  // FILTRARE LOCALĂ (ascunde/arată rânduri)
  function filterRows(){
    const q = norm($q.value);
    let visible = 0;
    rows.forEach(tr=>{
      const hay = norm(tr.getAttribute('data-search') || '');
      const ok = !q || hay.includes(q);
      tr.style.display = ok ? '' : 'none';
      if(ok) visible++;
    });
    // (opțional) poți afișa câte rânduri sunt vizibile, dacă vrei
  }

  // SUGESTII (folosește AJAX dacă există `edu_search_students`, altfel din rândurile tabelului)
  let tipTimer = null;
  $q.addEventListener('input', ()=>{
    clearTimeout(tipTimer);
    filterRows(); // filtrăm imediat
    const q = $q.value.trim();
    if(q.length < 2){ hideSuggest(); return; }
    tipTimer = setTimeout(()=> loadSuggestions(q), 180);
  });

  document.addEventListener('click', (e)=>{
    if(!e.target.closest('#student-suggest') && e.target !== $q) hideSuggest();
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
    // 1) încercăm AJAX (dacă ai un endpoint 'edu_search_students' în plugin)
    try{
      const fd = new FormData();
      fd.append('action','edu_search_students'); // OPTIONAL (dacă îl vei adăuga în plugin)
      fd.append('nonce', $q.dataset.nonce || '');
      fd.append('q', query);

      const r = await fetch($q.dataset.ajax || '', { method:'POST', body:fd, credentials:'same-origin' });
      // Dacă nu există endpoint, o să arunce eroare / răspuns invalid -> trecem pe fallback
      const data = await r.json();
      if(Array.isArray(data) && data.length){
        // așteptăm items de forma { id, first_name, last_name, text }
        showSuggest(data.slice(0, 10).map(d=>({ text: d.text || ((d.first_name||'')+' '+(d.last_name||'')) })));
        return;
      }
    }catch(_) { /* ignoră și cade pe fallback */ }

    // 2) fallback local din rândurile deja încărcate
    const qn = norm(query);
    const seen = new Set();
    const out = [];
    for(const tr of rows){
      const label = (tr.querySelector('[data-col="name"]')?.textContent || '').trim();
      const ln = norm(label);
      if(ln && ln.includes(qn) && !seen.has(ln)){
        out.push({ text: label });
        seen.add(ln);
        if(out.length>=10) break;
      }
    }
    showSuggest(out);
  }

  // rulează o dată la încărcare (dacă aveai valoare din GET)
  filterRows();
})();
</script>

<?php
/* ===== DEBUG minimal (activezi cu ?debug=1) ===== */
if (!empty($_GET['debug'])) {
  echo '<div class="max-w-5xl p-4 mx-auto my-4 text-xs border border-yellow-200 bg-yellow-50 rounded-xl">';
  echo '<strong>DEBUG</strong><br>';
  echo 'students total: <b>'.(int)$total.'</b>; perpage='.$perpage.'; paged='.$paged.'; offset='.$offset.'<br>';
  if (!empty($wpdb->last_error)) {
    echo '<div class="mt-2 text-red-700">wpdb error: '.esc_html($wpdb->last_error).'</div>';
    echo '<div class="mt-1 text-slate-600"><code>'.esc_html($wpdb->last_query).'</code></div>';
  }
  if (!empty($rows)) {
    echo '<details class="mt-2"><summary>Primele 2 rânduri elevi</summary><pre>'.esc_html(print_r(array_slice($rows,0,2), true)).'</pre></details>';
  }
  echo '</div>';
}
