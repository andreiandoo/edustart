<?php
/* Template Name: Profesor – Lista elevilor (pe generație) */
if (!defined('ABSPATH')) exit;

// ► Lăsăm panou-clasa.js exact cum e
wp_enqueue_script('panou-clasa', get_template_directory_uri() . '/js/panou-clasa.js', ['jquery'], filemtime(get_template_directory() . '/js/panou-clasa.js'), true);
wp_add_inline_script('panou-clasa','window.ajaxurl="'.esc_url(admin_url('admin-ajax.php')).'";','before');

/** ===================== Util & DB ===================== */
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

global $wpdb;
$students_table  = $wpdb->prefix . 'edu_students';
$gens_table      = $wpdb->prefix . 'edu_generations';
$schools_table   = $wpdb->prefix . 'edu_schools';
$results_table   = $wpdb->prefix . 'edu_results';

/** ===================== Cine e logat? (NU schimba asta pe parcurs) ===================== */
$logged_id    = get_current_user_id();
$logged_user  = wp_get_current_user();
$logged_roles = (array)$logged_user->roles;

$is_prof_logged  = $logged_id && in_array('profesor', $logged_roles, true);
$is_tutor_logged = $logged_id && in_array('tutor',    $logged_roles, true);
$is_admin_logged = $logged_id && current_user_can('manage_options');

if (!$logged_id || (!$is_prof_logged && !$is_tutor_logged && !$is_admin_logged)) {
  get_header(); ?>
  <main class="wrap" style="max-width:1100px;margin:40px auto;">
    <div class="p-4 text-red-800 bg-red-100 rounded">Acces refuzat.</div>
  </main>
  <?php get_footer(); exit;
}

/** ===================== generation_id din URL ===================== */
$gen_req = 0;
if (isset($_GET['gen']))               $gen_req = intval($_GET['gen']);
elseif (get_query_var('gen'))          $gen_req = intval(get_query_var('gen'));
elseif (get_query_var('gen_id'))       $gen_req = intval(get_query_var('gen_id'));
elseif (get_query_var('generation_id'))$gen_req = intval(get_query_var('generation_id'));

/** ===================== Încărcăm generația țintă + profesorul proprietar ===================== */
$gen = null;
if ($gen_req) {
  $gen = $wpdb->get_row($wpdb->prepare("
    SELECT id, name, level, class_label, class_labels_json, year, created_at, professor_id,
           sel_t0, sel_ti, sel_t1, lit_t0, lit_t1, num_t0, num_t1
    FROM {$gens_table}
    WHERE id = %d
  ", $gen_req));
}

if (!$gen) {
  // dacă utilizatorul este PROF, cădem pe prima lui generație
  if ($is_prof_logged) {
    $gen = $wpdb->get_row($wpdb->prepare("
      SELECT id, name, level, class_label, class_labels_json, year, created_at, professor_id,
             sel_t0, sel_ti, sel_t1, lit_t0, lit_t1, num_t0, num_t1
      FROM {$gens_table}
      WHERE professor_id = %d
      ORDER BY year DESC, id DESC
      LIMIT 1
    ", $logged_id));
  }
}

if (!$gen) {
  get_header(); ?>
  <main class="wrap" style="max-width:1100px;margin:40px auto;">
    <div class="p-4 text-red-800 bg-red-100 rounded">Nu s-a găsit nicio generație pentru vizualizare.</div>
  </main>
  <?php get_footer(); exit;
}

$owner_prof_id = (int)$gen->professor_id;                     // profesorul proprietar al generației
$owner_user    = $owner_prof_id ? get_user_by('id', $owner_prof_id) : null;

/** ===================== Determină viewer_mode (TUTOR → ADMIN → PROF) ===================== */
$viewer_mode = 'UNKNOWN';

// 1) TUTOR: numai dacă e alocat profesorului proprietar
if ($is_tutor_logged) {
  $assigned_tid = (int) get_user_meta($owner_prof_id, 'assigned_tutor_id', true);
  if ($assigned_tid === $logged_id) {
    $viewer_mode = 'TUTOR';
  }
}
// 2) ADMIN (dacă nu s-a calificat ca TUTOR)
if ($viewer_mode === 'UNKNOWN' && $is_admin_logged) {
  $viewer_mode = 'ADMIN';
}
// 3) PROF: doar dacă e deținătorul generației
if ($viewer_mode === 'UNKNOWN' && $is_prof_logged && $owner_prof_id === $logged_id) {
  $viewer_mode = 'PROF';
}

if ($viewer_mode === 'UNKNOWN') {
  get_header(); ?>
  <main class="wrap" style="max-width:1100px;margin:40px auto;">
    <div class="p-4 text-red-800 bg-red-100 rounded">Nu ai permisiuni pentru această generație.</div>
  </main>
  <?php get_footer(); exit;
}

$can_edit = ($viewer_mode === 'ADMIN' || $viewer_mode === 'PROF'); // Tutor = read-only

/** ===================== Gerațiile profesorului VIZUALIZAT ===================== */
$view_professor_id = $owner_prof_id; // întotdeauna profesorul proprietar
$gens = $wpdb->get_results($wpdb->prepare("
  SELECT id, name, level, class_label, class_labels_json, year, created_at, professor_id,
         sel_t0, sel_ti, sel_t1, lit_t0, lit_t1, num_t0, num_t1
  FROM {$gens_table}
  WHERE professor_id = %d
  ORDER BY year DESC, id DESC
", $view_professor_id));

// Dacă genul din URL nu e în setul owner-ului, setează implicit prima
if ($gen && $gen->professor_id !== $view_professor_id && !empty($gens)) {
  $gen = $gens[0];
}
// — HARD RELOAD: asigură că $gen are toate coloanele de activare
$gen = $wpdb->get_row($wpdb->prepare("
  SELECT id, name, level, class_label, class_labels_json, year, created_at, professor_id,
         sel_t0, sel_ti, sel_t1, lit_t0, lit_t1, num_t0, num_t1
  FROM {$gens_table}
  WHERE id = %d
", (int)$gen->id));

/** ===================== Școli (din meta profesor proprietar) ===================== */
$school_ids = (array) get_user_meta($view_professor_id, 'assigned_school_ids', true);
$schools = [];
if ($school_ids) {
  $placeholders = implode(',', array_fill(0, count($school_ids), '%d'));
  $schools = $wpdb->get_results($wpdb->prepare(
    "SELECT id, name FROM {$schools_table} WHERE id IN ($placeholders)",
    ...$school_ids
  ));
}

/** ===================== Helpers nivel + LIT/SEL/NUM slugs ===================== */
$human_level = function($code){
  $map = ['prescolar'=>'Preșcolar','primar'=>'Primar','gimnazial'=>'Gimnazial','liceu'=>'Liceu'];
  return $map[$code] ?? (string)$code;
};
$legacy_level_for_js = function($code){
  switch ($code) {
    case 'prescolar': return 'Prescolar';
    case 'primar':    return 'Primar';
    case 'gimnazial': return 'Gimnazial';
    default:          return 'Liceu';
  }
};

/* Extrage un „hint” de clasă (clasa reprezentativă) din generație */
if (!function_exists('edus_grade_number_from_classlabel')) {
  function edus_grade_number_from_classlabel($label){
    $s = mb_strtolower(trim((string)$label));
    $s = str_replace(['â','ă','î','ș','ţ','ț'],['a','a','i','s','t','t'],$s);
    if ($s==='' || strpos($s,'prescolar')!==false) return -1;
    if (strpos($s,'pregatitoare')!==false) return 0;
    if (preg_match('/\b([0-9])\b/', $s, $m)) { $n=intval($m[1]); if ($n>=1 && $n<=8) return $n; }
    return null;
  }
}
if (!function_exists('edus_pick_representative_grade')) {
  function edus_pick_representative_grade($gen){
    // 1) class_label (dacă e singular)
    $g = edus_grade_number_from_classlabel($gen->class_label ?? '');
    if ($g !== null) return $g;
    // 2) class_labels_json (dacă există)
    $json = $gen->class_labels_json ?? '';
    if ($json) {
      $arr = json_decode($json, true);
      if (is_array($arr) && !empty($arr)) {
        foreach ($arr as $lbl) {
          $g2 = edus_grade_number_from_classlabel($lbl);
          if ($g2 !== null) return $g2;
        }
      }
    }
    // 3) fallback pe level
    $lev = strtolower(trim($gen->level ?? ''));
    if ($lev==='prescolar') return -1;
    if ($lev==='primar') return 2;
    if ($lev==='gimnazial') return 5;
    return null;
  }
}
if (!function_exists('edus_sel_segment_for_gen')) {
  function edus_sel_segment_for_gen($gen){
    $lev = strtolower(trim($gen->level ?? ''));
    if ($lev==='prescolar') return 'prescolar';
    if ($lev==='gimnazial') return 'gimnaziu';
    if ($lev==='primar') {
      $g = edus_pick_representative_grade($gen);
      if ($g === -1 || $g === 0 || $g === 1 || $g === 2) return 'primar-mic';
      return 'primar-mare';
    }
    // fallback: încearcă prin număr de clasă
    $g = edus_pick_representative_grade($gen);
    if ($g !== null) {
      if ($g <= 2) return 'primar-mic';
      if ($g <= 4) return 'primar-mare';
      return 'gimnaziu';
    }
    return 'primar-mic';
  }
}
if (!function_exists('edus_sel_slug_for')) {
  function edus_sel_slug_for($gen, $stage){ // t0 | ti | t1
    $seg = edus_sel_segment_for_gen($gen);
    return "sel-{$stage}-{$seg}";
  }
}
if (!function_exists('edus_lit_slug_for')) {
  function edus_lit_slug_for($gen, $stage){ // t0 | t1
    $g = edus_pick_representative_grade($gen);
    if ($g === -1) return "literatie-prescolar-{$stage}";
    if ($g === 0)  return "literatie-clasa-pregatitoare-{$stage}";
    if ($g >= 1 && $g <= 4) return "literatie-primar-{$stage}";
    if ($g >= 5 && $g <= 8) return "literatie-gimnaziu-{$stage}";
    $lev = strtolower(trim($gen->level ?? ''));
    if ($lev==='prescolar') return "literatie-prescolar-{$stage}";
    if ($lev==='primar')    return "literatie-primar-{$stage}";
    if ($lev==='gimnazial') return "literatie-gimnaziu-{$stage}";
    return "literatie-primar-{$stage}";
  }
}
if (!function_exists('edus_num_slug_for')) {
  function edus_num_slug_for($gen, $stage){ // t0 | t1
    $g = edus_pick_representative_grade($gen);
    if ($g === -1) return "numeratie-prescolar-{$stage}";
    if ($g === 0)  return "numeratie-clasa-pregatitoare-{$stage}";
    if ($g >= 1 && $g <= 4) return "numeratie-primar-{$stage}";
    if ($g >= 5 && $g <= 8) return "numeratie-gimnaziu-{$stage}";
    $lev = strtolower(trim($gen->level ?? ''));
    if ($lev==='prescolar') return "numeratie-prescolar-{$stage}";
    if ($lev==='primar')    return "numeratie-primar-{$stage}";
    if ($lev==='gimnazial') return "numeratie-gimnaziu-{$stage}";
    return "numeratie-primar-{$stage}";
  }
}

/** ===================== Count elevi (pentru owner) ===================== */
$student_count = 0;
if ($gen) {
  $student_count = (int) $wpdb->get_var($wpdb->prepare("
    SELECT COUNT(*) FROM {$students_table}
    WHERE generation_id = %d AND professor_id = %d
  ", $gen->id, $owner_prof_id));
}

/** ===================== Slug-uri pentru etape (pt. UI/panouri) ===================== */
$sel_t0_slug = edus_sel_slug_for($gen, 't0');
$sel_ti_slug = edus_sel_slug_for($gen, 'ti');
$sel_t1_slug = edus_sel_slug_for($gen, 't1');

$lit_t0_slug = edus_lit_slug_for($gen, 't0');
$lit_t1_slug = edus_lit_slug_for($gen, 't1');

$num_t0_slug = edus_num_slug_for($gen, 't0');
$num_t1_slug = edus_num_slug_for($gen, 't1');

/** ===================== ACTIVE/INACTIVE: strict din wp_edu_generations ===================== */
$sel_active = [
  't0' => ((int)($gen->sel_t0 ?? 0) === 1),
  'ti' => ((int)($gen->sel_ti ?? 0) === 1),
  't1' => ((int)($gen->sel_t1 ?? 0) === 1),
];
$lit_active = [
  't0' => ((int)($gen->lit_t0 ?? 0) === 1),
  't1' => ((int)($gen->lit_t1 ?? 0) === 1),
];
$num_active = [
  't0' => ((int)($gen->num_t0 ?? 0) === 1),
  't1' => ((int)($gen->num_t1 ?? 0) === 1),
];

/** ===================== LIT schema (ca înainte; folosit de panou-clasa.js) ===================== */
function edus_lit_modul_from_level_code($code){
  $code = strtolower(trim($code ?: ''));
  if ($code === 'prescolar') return 'literatie-prescolar';
  if ($code === 'primar')    return 'literatie-primar';
  if ($code === 'gimnazial') return 'literatie-primar';
  if ($code === 'liceu')     return 'literatie-primar';
  return 'literatie-primar';
}
$lit_modul_slug = $gen ? edus_lit_modul_from_level_code($gen->level) : 'literatie-primar';

$lit_schema = ['slug'=>$lit_modul_slug,'source'=>'none','questions'=>[],'__debug'=>[]];
$modul_post = get_page_by_path($lit_modul_slug, OBJECT, 'modul');

function edus_acf_field_to_q($fo){
  if (!$fo || !is_array($fo)) return null;
  $label   = isset($fo['label']) ? $fo['label'] : strtoupper($fo['name']);
  $name    = isset($fo['name']) ? $fo['name'] : '';
  $type    = isset($fo['type']) ? $fo['type'] : 'text';
  $req     = !empty($fo['required']);
  $default = isset($fo['default_value']) ? $fo['default_value'] : null;

  $uiType = 'range';
  if (in_array($type, ['select','radio','button_group'], true)) $uiType = 'select';
  if (in_array($type, ['range','number'], true)) $uiType = 'range';

  $q = ['name'=>$name,'label'=>$label,'type'=>$uiType,'required'=>$req];

  if ($uiType === 'select') {
    $choices = [];
    if (!empty($fo['choices']) && is_array($fo['choices'])) {
      foreach ($fo['choices'] as $val=>$lab) {
        $choices[] = ['value'=>(string)$val, 'label'=>(string)($lab===''?$val:$lab)];
      }
    }
    $q['choices'] = $choices;
    if ($default !== null && $default !== '') $q['default'] = (string)$default;
  } else {
    $min  = isset($fo['min'])  ? (int)$fo['min']  : 0;
    $max  = isset($fo['max'])  ? (int)$fo['max']  : (isset($fo['range']['max']) ? (int)$fo['range']['max'] : 10);
    $step = isset($fo['step']) ? (string)$fo['step'] : '1';
    $init = ($default !== null && $default !== '') ? $default : $min;
    $q += ['min'=>$min,'max'=>$max,'step'=>$step?:'1','initial'=>(string)$init];
  }
  return $q;
}

if ($modul_post && function_exists('get_field_object')) {
  for ($i=1;$i<=12;$i++){
    $name='lit_q'.$i;
    $fo = get_field_object($name, $modul_post->ID, false);
    if ($fo) {
      $q = edus_acf_field_to_q($fo);
      if ($q) {
        if ($lit_modul_slug==='literatie-primar' && $i>=7 && $i<=12) $q['cond']=['field'=>'lit_q2','values'=>['P','PP','p','pp']];
        $lit_schema['questions'][] = $q;
      }
    }
  }
  $lit_schema['__debug']['direct_found'] = count($lit_schema['questions']);
  if (count($lit_schema['questions']) < 12 && function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
    $byName = []; $final = [];
    foreach (acf_get_field_groups(['post_id'=>$modul_post->ID]) as $grp) {
      foreach ((array)acf_get_fields($grp['key']) as $fo) {
        if (empty($fo['name'])) continue;
        if (!preg_match('/^lit_q(\d{1,2})$/', $fo['name'], $m)) continue;
        $idx = intval($m[1]);
        $q = edus_acf_field_to_q($fo);
        if ($q) {
          if ($lit_modul_slug==='literatie-primar' && $idx>=7 && $idx<=12) $q['cond']=['field'=>'lit_q2','values'=>['P','PP','p','pp']];
          $byName[$fo['name']] = $q;
        }
      }
    }
    for ($i=1;$i<=12;$i++){ $n='lit_q'.$i; if (isset($byName[$n])) $final[]=$byName[$n]; }
    if (!empty($final)) $lit_schema['questions'] = $final;
    $lit_schema['__debug']['groups_found'] = count($final);
  }
  if (!empty($lit_schema['questions'])) $lit_schema['source']='acf';
}
if (empty($lit_schema['questions'])) {
  $lit_schema['source']='fallback';
  if ($lit_modul_slug === 'literatie-prescolar') {
    $lit_schema['questions'] = [
      ['name'=>'lit_q1','label'=>'Interes pentru literație','type'=>'range','min'=>0,'max'=>22,'step'=>'1','initial'=>'0','required'=>true],
      ['name'=>'lit_q2','label'=>'Vocabular expresiv','type'=>'range','min'=>0,'max'=>20,'step'=>'1','initial'=>'0','required'=>true],
      ['name'=>'lit_q3','label'=>'Distingerea sunetului inițial','type'=>'range','min'=>0,'max'=>3,'step'=>'1','initial'=>'0','required'=>true],
      ['name'=>'lit_q4','label'=>'Înțelegerea poveștii ascultate','type'=>'range','min'=>0,'max'=>5,'step'=>'1','initial'=>'0','required'=>true],
      ['name'=>'lit_q5','label'=>'Scrierea prenumelui','type'=>'range','min'=>0,'max'=>1,'step'=>'1','initial'=>'0','required'=>true],
    ];
  } else {
    $choices_pp = array_map(fn($v)=>['value'=>(string)$v,'label'=>(string)$v], ['P','PP','1','2','3','4']);
    $choices_p  = array_map(fn($v)=>['value'=>(string)$v,'label'=>(string)$v], ['P','1','2','3','4']);
    $condPP     = ['field'=>'lit_q2','values'=>['P','PP']];
    $lit_schema['questions'] = [
      ['name'=>'lit_q1','label'=>'Lista de cuvinte','type'=>'select','choices'=>$choices_pp,'required'=>false],
      ['name'=>'lit_q2','label'=>'Acuratețe citire','type'=>'select','choices'=>$choices_pp,'required'=>true],
      ['name'=>'lit_q3','label'=>'CCPM','type'=>'range','min'=>0,'max'=>100,'step'=>1,'initial'=>'0','required'=>false],
      ['name'=>'lit_q4','label'=>'Comprehensiune citire','type'=>'select','choices'=>$choices_pp,'required'=>false],
      ['name'=>'lit_q5','label'=>'Comprehensiune audiere','type'=>'select','choices'=>$choices_p,'required'=>false],
      ['name'=>'lit_q6','label'=>'Scris (număr cuvinte pe care le scriu corect)','type'=>'range','min'=>0,'max'=>5,'step'=>1,'initial'=>'0','required'=>false],
      ['name'=>'lit_q7','label'=>'Noțiunea despre textul tipărit (0–6)','type'=>'range','min'=>0,'max'=>6,'step'=>1,'initial'=>'0','required'=>false,'cond'=>$condPP],
      ['name'=>'lit_q8','label'=>'Recunoașterea și reproducerea alfabetului (0–93)','type'=>'range','min'=>0,'max'=>93,'step'=>1,'initial'=>'0','required'=>false,'cond'=>$condPP],
      ['name'=>'lit_q9','label'=>'Noțiunea de cuvânt (0–12)','type'=>'range','min'=>0,'max'=>12,'step'=>1,'initial'=>'0','required'=>true,'cond'=>$condPP],
      ['name'=>'lit_q10','label'=>'Segmentarea fonemică (0–22)','type'=>'range','min'=>0,'max'=>22,'step'=>1,'initial'=>'0','required'=>false,'cond'=>$condPP],
      ['name'=>'lit_q11','label'=>'Recunoașterea cuvintelor (0–12)','type'=>'range','min'=>0,'max'=>12,'step'=>1,'initial'=>'0','required'=>false,'cond'=>$condPP],
      ['name'=>'lit_q12','label'=>'Scrierea cuvintelor (0–5)','type'=>'range','min'=>0,'max'=>5,'step'=>1,'initial'=>'0','required'=>true,'cond'=>$condPP],
    ];
  }
}

/** ===================== Header & UI ===================== */
get_header('blank'); ?>

<?php if ($debug): ?>
  <div class="max-w-4xl p-4 mx-auto mt-6 text-sm bg-white border rounded-xl border-slate-200">
    <div><b>Mode:</b> <?php echo esc_html($viewer_mode); ?></div>
    <div><b>logged_user_id:</b> <?php echo (int)$logged_id; ?> | <b>logged_roles:</b> <?php echo esc_html(implode(',', $logged_roles)); ?></div>
    <div><b>view_professor_id (owner):</b> <?php echo (int)$owner_prof_id; ?> |
      <b>assigned_tutor_id (owner):</b> <?php echo (int) get_user_meta($owner_prof_id, 'assigned_tutor_id', true); ?></div>
    <div><b>generation_id:</b> <?php echo (int)$gen->id; ?> | <b>Gen level:</b> <?php echo esc_html($gen->level); ?></div>

    <div class="mt-2"><b>GEN flags:</b>
      SEL[t0=<?php echo (int)$gen->sel_t0; ?>, ti=<?php echo (int)$gen->sel_ti; ?>, t1=<?php echo (int)$gen->sel_t1; ?>] ·
      LIT[t0=<?php echo (int)$gen->lit_t0; ?>, t1=<?php echo (int)$gen->lit_t1; ?>] ·
      NUM[t0=<?php echo (int)$gen->num_t0; ?>, t1=<?php echo (int)$gen->num_t1; ?>]
    </div>

    <div class="mt-2">
      <b>SEL slugs:</b> <?php echo esc_html("$sel_t0_slug, $sel_ti_slug, $sel_t1_slug"); ?> |
      <b>LIT slugs:</b> <?php echo esc_html("$lit_t0_slug, $lit_t1_slug"); ?> |
      <b>NUM slugs:</b> <?php echo esc_html("$num_t0_slug, $num_t1_slug"); ?>
    </div>
  </div>
<?php endif; ?>

<section class="sticky top-0 z-10 px-6 border-b inner-submenu bg-slate-800 border-slate-200" data-generation-id="<?php echo esc_attr($gen->id ?? 0); ?>">
  <div class="relative z-10 flex items-center justify-between px-2 py-2 gap-x-2">
    <div class="flex items-center justify-start gap-x-4">
      <div class="flex items-center gap-2 font-semibold text-slate-800">
        <?php if (isset($student_count)): ?>
          <span class="inline-flex items-center text-xs uppercase text-slate-400">
            Elevi in generație
          </span>
          <span class="inline-flex items-center px-2 py-1 text-sm font-medium rounded-md bg-white/90 text-slate-800">
            <?php echo (int)$student_count; ?>
          </span>
        <?php endif; ?>
      </div>

      <div class="flex items-center gap-2 text-sm font-semibold text-white">
        <span class="inline-flex items-center text-xs uppercase text-slate-400">
          Profesor
        </span>
        <?php if ($viewer_mode === 'TUTOR' || $viewer_mode === 'ADMIN'): ?>
          <a href="<?php echo esc_url(home_url('/panou/profesor/').$gen->professor_id); ?>" class="px-2 py-1 text-xs font-medium bg-white rounded-md text-slate-800 hover:text-sky-800">
            <?php echo esc_html($owner_user ? $owner_user->display_name : ('#'.$owner_prof_id)); ?>
          </a>
        <?php else: ?>
          <?php echo esc_html($owner_user ? $owner_user->display_name : ('#'.$owner_prof_id)); ?>
        <?php endif; ?>
      </div>

      <!-- Selector generație (ID — Nume (Nivel) · An) -->
      <form method="get" class="flex items-center gap-2">
        <label for="genSelect" class="inline-flex items-center text-xs font-semibold uppercase text-slate-400">Generație</label>
        <select id="genSelect" name="gen"
                class="px-2 py-1 text-sm font-medium rounded-md bg-white/90 text-slate-800 focus:outline-none"
                onchange="this.form.submit()">
            <?php foreach ($gens as $g): ?>
            <option value="<?php echo (int)$g->id; ?>" <?php selected($gen && $gen->id == $g->id); ?>>
                #<?php echo (int)$g->id; ?>
                <?php echo $g->name ? ' — '.esc_html($g->name) : ''; ?>
                (<?php echo esc_html($human_level($g->level)); ?>) · <?php echo esc_html($g->year); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php if ($debug): ?><input type="hidden" name="debug" value="1"><?php endif; ?>
      </form>
    </div>

    <!-- Acțiuni: doar PROF/ADMIN -->
    <div class="flex items-center gap-2">
      <a href="<?php echo $gen ? esc_url(home_url('/panou/raport/generatie/'.$gen->id)) : '#'; ?>"
          class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700 <?php echo $gen?'':'pointer-events-none opacity-50'; ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4">
          <path fill-rule="evenodd" d="M5.625 1.5c-1.036 0-1.875.84-1.875 1.875v17.25c0 1.035.84 1.875 1.875 1.875h12.75c1.035 0 1.875-.84 1.875-1.875V12.75A3.75 3.75 0 0 0 16.5 9h-1.875a1.875 1.875 0  0 1-1.875-1.875V5.25A3.75 3.75 0  0 0 9 1.5H5.625ZM7.5 15a.75.75 0  0 1 .75-.75h7.5a.75.75 0  0 1 0 1.5h-7.5A.75.75 0  0 1 7.5 15Zm.75 2.25a.75.75 0  0 0 0 1.5H12a.75.75 0  0 0 0-1.5H8.25Z" clip-rule="evenodd" />
          <path d="M12.971 1.816A5.23 5.23 0  0 1 14.25 5.25v1.875c0 .207.168.375.375.375H16.5a5.23 5.23 0  0 1 3.434 1.279 9.768 9.768 0  0 0-6.963-6.963Z" />
        </svg>
        Raport general
      </a>
      
      <?php if ($can_edit): ?>
        <button id="toggleAddForm"
                class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
          </svg>
          Adaugă elevi
        </button>

        <button id="renameGenBtn"
                class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700 <?php echo $gen?'':'pointer-events-none opacity-50'; ?>">
          <svg xmlns="http://www.w3.org/2000/svg" class="size-4" viewBox="0 0 24 24" fill="currentColor">
              <path d="M21.731 2.269a2.625 2.625 0  0 0-3.712 0l-1.157 1.157 3.712 3.712 1.157-1.157a2.625 2.625 0  0 0 0-3.712ZM19.513 8.199l-3.712-3.712-12.15 12.15a5.25 5.25 0  0 0-1.32 2.214l-.8 2.685a.75.75 0  0 0 .933.933l2.685-.8a5.25 5.25 0  0 0 2.214-1.32L19.513 8.2Z"/>
          </svg>
          Editează
        </button>

        <button type="button"
                class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
          <svg stroke="currentColor" fill="none" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" class="size-4">
              <path d="M4 17v2a2 2 0  0 0 2 2h12a2 2 0  0 0 2 -2v-2"></path><path d="M7 9l5 -5l5 5"></path><path d="M12 4l0 12"></path>
          </svg>
          Export CSV
        </button>
      <?php endif; ?>

      <?php if ($viewer_mode === 'ADMIN'): ?>
        <a href="../documentatie/#profesori" target="_blank" rel="noopener noreferrer"
        class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-3 h-3">
            <path d="M12 .75a8.25 8.25 0  0 0-4.135 15.39c.686.398 1.115 1.008 1.134 1.623a.75.75 0  0 0 .577.706c.352.083.71.148 1.074.195.323.041.6-.218.6-.544v-4.661a6.714 6.714 0  0 1-.937-.171.75.75 0  0 1 .374-1.453 5.261 5.261 0  0 0 2.626 0 .75.75 0  0 1 .374 1.452 6.712 6.712 0  0 1-.937.172v4.66c0 .327.277.586.6.545.364-.047.722-.112 1.074-.195a.75.75 0  0 0 .577-.706c.02-.615.448-1.225 1.134-1.623A8.25 8.25 0  0 0 12 .75Z" />
            <path fill-rule="evenodd" d="M9.013 19.9a.75.75 0  0 1 .877-.597 11.319 11.319 0  0 0 4.22 0 .75.75 0  0 1 .28 1.473 12.819 12.819 0  0 1-4.78 0 .75.75 0  0 1-.597-.876ZM9.754 22.344a.75.75 0  0 1 .824-.668 13.682 13.682 0  0 0 2.844 0 .75.75 0  0 1 .156 1.492 15.156 15.156 0  0 1-3.156 0 .75.75 0  0 1-.668-.824Z" clip-rule="evenodd" />
          </svg>
          Documentatie
        </a>
      <?php endif; ?>
    </div>
  </div>
</section>

<div class="w-full px-6 pb-8 transition-content" data-generation-id="<?php echo esc_attr($gen->id ?? 0); ?>">
  <div class="flex items-center justify-between mt-4 mb-6 gap-x-6">
    <div class="flex flex-wrap items-center gap-2 text-sm md:gap-3">
      <?php if ($gen): ?>
        <!-- Nivel -->
        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-xl bg-emerald-50 text-emerald-800 ring-1 ring-inset ring-emerald-200">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4 text-emerald-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0  2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0  2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0  2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
          </svg>
          <span class="font-medium">Nivel:</span>
          <span class="font-semibold"><?php echo esc_html($human_level($gen->level)); ?></span>
        </span>
      <?php endif; ?>

      <?php if ($schools): ?>
        <?php
          $schools_names = implode(', ', array_map(fn($s)=>$s->name, $schools));
        ?>
        <!-- Școli -->
        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-xl bg-sky-50 text-sky-800 ring-1 ring-inset ring-sky-200" title="<?php echo esc_attr($schools_names); ?>">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4 text-sky-600">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.75 3l-1.5.545m0 6.205 3 1m1.5.5-1.5-.5M6.75 7.364V3h-3v18m3-13.636 10.5-3.819" />
          </svg>
          <span class="font-medium">Școala:</span>
          <span class="font-semibold"><?php echo $schools_names; ?></span>
        </span>
      <?php endif; ?>
    </div>
    <div class="flex flex-wrap items-center gap-2 text-xs md:gap-3">
      <?php
        $chip = function($active, $label, $title='') {
          $cls = $active
            ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200'
            : 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200';
          return '<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl '.$cls.'" title="'.esc_attr($title).'">'.
                  ($active ? '●' : '○').' '.esc_html($label).
                '</span>';
        };
        echo $chip($sel_active['t0'], "SEL T0", 'Setat din wp_edu_generations');
        echo $chip($sel_active['ti'], "SEL TI", 'Setat din wp_edu_generations');
        echo $chip($sel_active['t1'], "SEL T1", 'Setat din wp_edu_generations');
        echo $chip($lit_active['t0'], "LIT T0", 'Setat din wp_edu_generations');
        echo $chip($lit_active['t1'], "LIT T1", 'Setat din wp_edu_generations');
        echo $chip($num_active['t0'], "NUM T0", 'Setat din wp_edu_generations');
        echo $chip($num_active['t1'], "NUM T1", 'Setat din wp_edu_generations');
      ?>
    </div>
  </div>

  <!-- Add elevi: doar PROF/ADMIN -->
  <?php if ($can_edit): ?>
    <div id="addStudentsWrapper" class="hidden mb-10">
      <div class="overflow-hidden bg-white border shadow-sm rounded-2xl border-slate-200">
        <div class="flex items-center justify-between px-5 py-3 text-white bg-gradient-to-r from-emerald-600 to-emerald-500">
          <div class="flex items-center gap-3">
            <svg class="w-5 h-5 opacity-90" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 3a1 1 0  0 1 1 1v7h7a1 1 0  1 1 0 2h-7v7a1 1 0  1 1-2 0v-7H4a1 1 0  1 1 0-2h7V4a1 1 0  0 1 1-1z"/>
            </svg>
            <h2 class="text-lg font-semibold tracking-wide">Adaugă elevi</h2>
          </div>
          <div class="text-sm/none opacity-95">
            Generația #<?php echo (int)$gen->id; ?> · <span class="font-medium"><?php echo esc_html($human_level($gen->level)); ?></span>
          </div>
        </div>

        <div class="p-5">
          <form id="addStudentsForm" class="">
            <input type="hidden" name="action" value="add_students">
            <input type="hidden" name="class_id" value="">
            <input type="hidden" name="generation_id" value="<?php echo (int)$gen->id; ?>">
            <input type="hidden" id="class_level" value="<?php echo esc_attr($legacy_level_for_js($gen->level)); ?>">
            <input type="hidden" id="level_code" value="<?php echo esc_attr($gen->level); ?>">

            <div class="flex flex-col gap-3 pb-5 sm:flex-row sm:items-center sm:justify-between">
              <label for="studentCount" class="flex items-center gap-3">
                <span class="text-sm font-medium text-slate-700">Câți elevi vrei să adaugi?</span>
                <input type="number" id="studentCount" value="1" min="1"
                  class="w-24 rounded-lg border-slate-300 bg-white px-3 py-1.5 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
              </label>
              <div class="text-xs text-slate-500">
                Completează rândurile și apasă <span class="font-medium text-slate-700">Salvează elevii</span>.
              </div>
            </div>

            <div class="relative -mx-5 overflow-x-auto">
              <table id="studentsTable" class="min-w-[900px] relative w-full text-sm border-separate border-spacing-0">
                <thead class="sticky top-0 bg-sky-800">
                  <tr class="text-white">
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70">Prenume</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70">Nume</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70">Vârstă</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70">Gen</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70 class_label_th">Clasa</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70">Sit. absenteism</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70 frecventa_th">Frecvență grădiniță</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70 bursa_th">Bursă socială</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70">Limba diferită</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70 cauze_abs_th">Cauze absenteism</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70 risc_abandon_th">Risc abandon</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70">Repetă clasa</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70">Observație</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70">Alte observații</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70 demers_familie_th">Demers familie</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70 demers_conducere_th">Demers conducere</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70 demers_consilier_th">Demers consilier</th>
                    <th class="p-3 font-semibold text-left border-b border-slate-700/70">Mențiuni</th>
                    <th class="p-3 font-semibold text-center border-b border-slate-700/70">Ștergere</th>
                  </tr>
                </thead>
                <tbody id="studentsContainer" class="bg-white divide-y divide-slate-200"></tbody>
              </table>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
              <button type="submit"
                      class="inline-flex items-center gap-2 px-4 py-2 text-white shadow-sm rounded-xl bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M17 3H7a2 2 0  0 0-2 2v14l7-3 7 3V5a2 2 0  0 0-2-2z"/></svg>
                Salvează elevii
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Lista elevilor (AJAX) -->
  <div id="studentList"
       class="space-y-4"
       data-class-id="<?php echo $gen ? (int)$gen->id : ''; ?>"
       data-lit-modul="<?php echo esc_attr($lit_modul_slug); ?>"
       data-class-level="<?php echo $gen ? esc_attr($legacy_level_for_js($gen->level)) : ''; ?>"
       data-sel-t0="<?php echo $sel_active['t0'] ? '1' : '0'; ?>"
       data-sel-ti="<?php echo $sel_active['ti'] ? '1' : '0'; ?>"
       data-sel-t1="<?php echo $sel_active['t1'] ? '1' : '0'; ?>"
       data-lit-t0="<?php echo $lit_active['t0'] ? '1' : '0'; ?>"
       data-lit-t1="<?php echo $lit_active['t1'] ? '1' : '0'; ?>"
       data-num-t0="<?php echo $num_active['t0'] ? '1' : '0'; ?>"
       data-num-t1="<?php echo $num_active['t1'] ? '1' : '0'; ?>"
       data-sel-t0-slug="<?php echo esc_attr($sel_t0_slug); ?>"
       data-sel-ti-slug="<?php echo esc_attr($sel_ti_slug); ?>"
       data-sel-t1-slug="<?php echo esc_attr($sel_t1_slug); ?>"
       data-lit-t0-slug="<?php echo esc_attr($lit_t0_slug); ?>"
       data-lit-t1-slug="<?php echo esc_attr($lit_t1_slug); ?>"
       data-num-t0-slug="<?php echo esc_attr($num_t0_slug); ?>"
       data-num-t1-slug="<?php echo esc_attr($num_t1_slug); ?>"
  >
    <div class="text-gray-500"><?php echo $gen ? 'Se încarcă elevii...' : 'Nu ai încă nicio generație.'; ?></div>
  </div>
</div>

<!-- Overlay & panel (folosit de SEL/LIT/NUM) -->
<div id="overlay" class="fixed inset-0 z-40 hidden bg-sky-800/70"></div>
<div id="questionnairePanel" class="fixed top-4 bottom-4 rounded-xl right-0 z-50 flex flex-col w-full h-[calc(100vh - 2rem)] max-w-3xl transition-transform duration-200 ease-out translate-x-full bg-white shadow-xl overflow-hidden">
  <div id="questionnaireContent" class="flex-1 min-h-0 overflow-y-auto scrollbar-none"></div>
</div>

<script>
/* ==== Server → Client flags ==== */
window.__LIT_SCHEMA = <?php echo wp_json_encode($lit_schema, JSON_UNESCAPED_UNICODE); ?>;
window.__LIT_DEBUG  = <?php echo $debug ? 'true' : 'false'; ?>;

window.__EDU_EVAL_ACTIVE = {
  sel: {
    t0: { active: <?php echo $sel_active['t0'] ? 'true' : 'false'; ?>, slug: "<?php echo esc_js($sel_t0_slug); ?>" },
    ti: { active: <?php echo $sel_active['ti'] ? 'true' : 'false'; ?>, slug: "<?php echo esc_js($sel_ti_slug); ?>" },
    t1: { active: <?php echo $sel_active['t1'] ? 'true' : 'false'; ?>, slug: "<?php echo esc_js($sel_t1_slug); ?>" },
  },
  lit: {
    t0: { active: <?php echo $lit_active['t0'] ? 'true' : 'false'; ?>, slug: "<?php echo esc_js($lit_t0_slug); ?>" },
    t1: { active: <?php echo $lit_active['t1'] ? 'true' : 'false'; ?>, slug: "<?php echo esc_js($lit_t1_slug); ?>" },
  },
  num: {
    t0: { active: <?php echo $num_active['t0'] ? 'true' : 'false'; ?>, slug: "<?php echo esc_js($num_t0_slug); ?>" },
    t1: { active: <?php echo $num_active['t1'] ? 'true' : 'false'; ?>, slug: "<?php echo esc_js($num_t1_slug); ?>" },
  },
  generation_id: <?php echo (int)$gen->id; ?>
};

(function($){
  const ACTIVE = window.__EDU_EVAL_ACTIVE || {};
  const CONTAINER = "#studentList";
  const TRIGGER_SEL = ".start-questionnaire, [data-eval-slug], [data-eval-modul], [data-modul], [data-module]";

  /* ---------------- Helpers: detect group/stage/slug ---------------- */
  function detectStageFromModul(modul, type){
    const m = String(modul||"");
    if (!m) return null;
    if ((type||"").toLowerCase()==="sel"){
      // sel-ti-primar-mic → ti
      const ms = m.match(/^sel-(t0|ti|t1)-/i);
      return ms ? ms[1].toLowerCase() : null;
    }
    // ...-t0 / ...-t1 at the end (lit/num)
    const mt = m.match(/-(t0|ti|t1)$/i);
    return mt ? mt[1].toLowerCase() : null;
  }
  function detectGroupFromModul(modul){
    const s = String(modul||"").toLowerCase();
    if (s.startsWith("sel-")) return "sel";
    if (s.startsWith("literatie-")) return "lit";
    if (s.startsWith("num-")) return "num";
    return null;
  }
  function extractInfo(ds){
    const info = { slug: null, group: null, stage: null };

    // Highest priority: explicit slug
    if (ds.evalSlug) info.slug = String(ds.evalSlug);

    // Group (prefer explicit data-type)
    const type = (ds.type || "").toLowerCase();
    if (type) info.group = type;

    // Stage (prefer explicit)
    if (ds.evalStage) info.stage = String(ds.evalStage).toLowerCase();
    else if (ds.stage) info.stage = String(ds.stage).toLowerCase();

    // If not explicit, try infer from modul/module/evalModul
    const modulStr = ds.modul || ds.module || ds.evalModul || "";
    if (!info.group) info.group = detectGroupFromModul(modulStr);
    if (!info.stage) info.stage = detectStageFromModul(modulStr, info.group);

    return info;
  }
  function isActiveBySlug(slug){
    if (!slug) return null; // unknown
    for (const grp of ["sel","lit","num"]) {
      if (!ACTIVE[grp]) continue;
      for (const st of Object.keys(ACTIVE[grp])) {
        const obj = ACTIVE[grp][st];
        if (obj && obj.slug === slug) return !!obj.active;
      }
    }
    return false;
  }
  function isActiveByPair(group, stage){
    if (!group || !stage) return null; // unknown
    group = group.toLowerCase();
    stage = stage.toLowerCase();
    return !!(ACTIVE[group] && ACTIVE[group][stage] && ACTIVE[group][stage].active);
  }

  /* ---------------- Make a trigger fully inert ---------------- */
  function inertize($el){
    $el.addClass("opacity-50 cursor-not-allowed pointer-events-none")
       .attr({"aria-disabled":"true","tabindex":"-1","data-disabled":"1","title":"Evaluarea este dezactivată pe această generație."});
    if ($el.is("a")) {
      if (!$el.data("href")) $el.data("href", $el.attr("href") || "");
      $el.attr("href","#");
    }
    if ($el.is("button") || $el.hasClass("start-questionnaire")) {
      $el.prop("disabled", true);
    }
    // swallow clicks/keyboard on that node
    $el.off(".eduInert")
       .on("click.eduInert", function(e){ e.preventDefault(); e.stopImmediatePropagation(); return false; })
       .on("keydown.eduInert", function(e){
          if (e.key==="Enter" || e.key===" ") { e.preventDefault(); e.stopImmediatePropagation(); return false; }
       });
  }

  /* ---------------- Apply disabling to current DOM ---------------- */
  function applyGuards($root){
    $root.find(TRIGGER_SEL).each(function(){
      const $btn = $(this);
      // don't re-process if already disabled by server/previous pass
      if ($btn.is("[data-disabled='1']") || $btn.is(":disabled")) return;

      const info = extractInfo(this.dataset);
      let allowed;

      if (info.slug) {
        allowed = isActiveBySlug(info.slug);
        if (allowed === null) allowed = true; // unknown → allow
      } else {
        allowed = isActiveByPair(info.group, info.stage);
        if (allowed === null) allowed = true; // unknown → allow
      }

      if (!allowed) inertize($btn);
    });
  }

  /* ---------------- Global capture guard: block any late clicks ---------------- */
  function captureGuard(ev){
    const root = document.querySelector(CONTAINER);
    if (!root) return;
    const path = ev.composedPath ? ev.composedPath() : (function(p,t){ let n=t; while(n){p.push(n); n=n.parentNode;} return p; })([], ev.target);
    if (!path.includes(root)) return;

    // find closest element that looks like a trigger
    let node = null;
    for (const el of path) {
      if (!el || !el.matches) continue;
      if (el.matches(TRIGGER_SEL)) { node = el; break; }
      const near = el.closest && el.closest(TRIGGER_SEL);
      if (near) { node = near; break; }
    }
    if (!node) return;

    const info = extractInfo(node.dataset);
    let allowed;
    if (info.slug) {
      allowed = isActiveBySlug(info.slug);
      if (allowed === null) allowed = true;
    } else {
      allowed = isActiveByPair(info.group, info.stage);
      if (allowed === null) allowed = true;
    }

    if (!allowed) {
      ev.preventDefault();
      ev.stopPropagation();
      try { ev.stopImmediatePropagation(); } catch(_){}
      inertize($(node));
      return false;
    }
  }
  document.addEventListener("click", captureGuard, true);
  document.addEventListener("keydown", function(e){
    if (e.key==="Enter" || e.key===" ") captureGuard(e);
  }, true);

  /* ---------------- Page init + AJAX load of students ---------------- */
  $(function(){
    const genId   = <?php echo $gen ? (int)$gen->id : 0; ?>;
    const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;

    <?php if ($can_edit): ?>
    $("#toggleAddForm").on("click", function(){
      const $form = $("#addStudentsWrapper");
      if (!genId) return;
      $form.toggleClass("hidden");
      if (!$form.hasClass("hidden")){
        $("#studentCount").trigger("change");
        window.scrollTo({ top: $form.offset().top - 100, behavior: 'smooth' });
      }
    });

    $("#renameGenBtn").on("click", function(){
      if (!genId) return;
      const cur  = <?php echo json_encode($gen ? (string)$gen->name : ''); ?>;
      const name = prompt("Noul nume pentru generație:", cur || "");
      if (name === null) return;
      $.post(ajaxurl, { action:'edu_rename_generation', generation_id: genId, name }, function(res){
        if (res && res.success) location.reload();
        else alert(res && res.data ? res.data : "Eroare la redenumire generație.");
      });
    });
    <?php endif; ?>

    const classId = $(CONTAINER).data("class-id");
    if (classId) {
      $.post(ajaxurl, { action:"get_students", class_id: classId, generation_id: classId }, function (response) {
        if (!response || !response.success) {
          $(CONTAINER).html('<div class="text-red-600">Eroare la încărcarea elevilor.</div>');
          return;
        }
        $(CONTAINER).html(response.data);

        // Immediately inactivate disallowed triggers (LIT/NUM inactive etc.)
        applyGuards($(CONTAINER));

        // Re-apply guards on any later DOM mutations (e.g. list re-render)
        const mo = new MutationObserver(() => applyGuards($(CONTAINER)));
        mo.observe($(CONTAINER)[0], { childList:true, subtree:true });
      });
    }
  });
})(jQuery);
</script>


