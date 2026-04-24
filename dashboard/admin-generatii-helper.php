<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin – Generații (helper)
 * Păstrează TOT fluxul din tutor-generatii-helper (SEL/LIT, remedial, % completare, draft/final)
 * dar pe întregul sistem (toți profesorii/generațiile), cu tutorul fiecărui profesor.
 *
 * Expune:
 * admin_gen_build_cards_all($filters) => [
 *   'cards'  => [ ... // structura cardurilor identică cu tutor + tutor_name/tid ],
 *   'years'  => [years],
 *   'levels' => [normalized labels],
 *   'total'  => int
 * ]
 *
 * Filtre:
 * - s            : string (căutare: generație/profesor/tutor/an/nivel)
 * - year         : string
 * - level        : string (ex. Preșcolar/Primar/Gimnazial/Liceu/Clasa pregătitoare)
 * - tutor_id     : int
 * - professor_id : int
 * - perpage, paged
 */

// 1) Refolosim helper-ul tău (toate utilitarele SEL/LIT, badge-uri etc.)
$TUTOR_HELPER = get_stylesheet_directory() . '/dashboard/tutor-generatii-helper.php';
if (file_exists($TUTOR_HELPER)) {
  require_once $TUTOR_HELPER;
} else {
  // Fallback micro-utils (dacă cineva rulează fără fișierul de tutor)
  if (!function_exists('es_maybe_unserialize_arr')) {
    function es_maybe_unserialize_arr($s){ if ($s === null || $s === '') return null; if (is_array($s)) return $s; $arr = @maybe_unserialize($s); return is_array($arr) ? $arr : null; }
  }
  if (!function_exists('es_avg_non_null')) {
    function es_avg_non_null($arr){ $s=0;$n=0; foreach((array)$arr as $v){ if($v!==null && $v!==''){ $s+=floatval($v); $n++; } } return $n?($s/$n):null; }
  }
  // … (în practică, ai deja helperul tău, deci nu mai duplicăm restul)
}

if (!function_exists('adg_user_fullname')) {
  function adg_user_fullname($u){
    if (!$u) return '—';
    $name = trim(($u->first_name ?? '').' '.($u->last_name ?? ''));
    if ($name === '') $name = $u->display_name ?: $u->user_login;
    return $name;
  }
}
if (!function_exists('adg_level_label_norm')) {
  function adg_level_label_norm($raw){
    // folosim aceeași normalizare ca în tutor helper
    if (function_exists('es_level_label_norm')) return es_level_label_norm($raw);
    $s = mb_strtolower(trim((string)$raw));
    if ($s==='') return '—';
    $s = str_replace(['â','ă','î','ș','ţ','ț'], ['a','a','i','s','t','t'], $s);
    if (strpos($s,'prescolar')!==false) return 'Preșcolar';
    if (strpos($s,'pregatitoare')!==false) return 'Clasa pregătitoare';
    if (strpos($s,'primar')!==false) return 'Primar';
    if (strpos($s,'gimnaz')!==false) return 'Gimnazial';
    if (strpos($s,'lice')!==false) return 'Liceu';
    return ucfirst($raw);
  }
}
if (!function_exists('adg_resolve_user_ids_by_q')) {
  /**
   * Given a free-text query, return the list of user IDs (in $role)
   * whose display_name / login / email / first_name / last_name match.
   * Returns [] if nothing matches (caller should treat as "no results").
   */
  function adg_resolve_user_ids_by_q($q, $role = 'profesor'){
    global $wpdb;
    $q = trim((string)$q);
    if ($q === '') return [];

    // 1) WP_User_Query with wildcard search on common columns
    $args = [
      'role'           => $role,
      'number'         => 500,
      'search'         => '*' . esc_attr($q) . '*',
      'search_columns' => ['user_login','user_email','display_name'],
      'fields'         => 'ID',
    ];
    $uq  = new WP_User_Query($args);
    $ids = array_map('intval', (array)$uq->get_results());

    // 2) Also match on first_name / last_name meta
    $like = '%' . $wpdb->esc_like($q) . '%';
    $meta_ids = $wpdb->get_col($wpdb->prepare("
      SELECT DISTINCT user_id
      FROM {$wpdb->usermeta}
      WHERE ( (meta_key='first_name' AND meta_value LIKE %s)
           OR (meta_key='last_name'  AND meta_value LIKE %s) )
      LIMIT 2000
    ", $like, $like));
    if (!empty($meta_ids)) {
      $extra_ids = array_map('intval', (array)$meta_ids);
      // restrict to role
      if (!empty($extra_ids)) {
        $in = implode(',', array_fill(0, count($extra_ids), '%d'));
        $valid = $wpdb->get_col($wpdb->prepare("
          SELECT user_id FROM {$wpdb->usermeta}
          WHERE meta_key='{$wpdb->prefix}capabilities' AND user_id IN ($in)
        ", ...$extra_ids));
        $role_hits = [];
        foreach ($valid as $uid) {
          $caps = get_user_meta((int)$uid, $wpdb->prefix.'capabilities', true);
          if (is_array($caps) && array_key_exists($role, $caps)) {
            $role_hits[] = (int)$uid;
          }
        }
        $ids = array_unique(array_merge($ids, $role_hits));
      }
    }

    return array_values(array_unique(array_filter(array_map('intval', $ids))));
  }
}
if (!function_exists('adg_dt')) {
  function adg_dt($ts_or_str){
    if (!$ts_or_str) return '—';
    $ts = is_numeric($ts_or_str) ? (int)$ts_or_str : strtotime($ts_or_str);
    if (!$ts) return '—';
    return date_i18n(get_option('date_format').' '.get_option('time_format'), $ts);
  }
}

/**
 * Construiește toate cardurile generație–profesor din sistem (cu tutor).
 * Structura cardurilor = identică cu tutor_gen_build_cards + chei suplimentare: tutor_name, tid, glevel.
 */
if (!function_exists('admin_gen_build_cards_all')) {
  function admin_gen_build_cards_all(array $filters = []){
    global $wpdb, $SEL_CHAPTERS;

    $tbl_results     = $wpdb->prefix . 'edu_results';
    $tbl_students    = $wpdb->prefix . 'edu_students';
    $tbl_generations = $wpdb->prefix . 'edu_generations';
    $tbl_umeta       = $wpdb->usermeta;

    $s            = trim((string)($filters['s'] ?? ''));
    $year_f       = trim((string)($filters['year'] ?? ''));
    $level_f      = trim((string)($filters['level'] ?? '')); // legacy single value
    $level_arr_in = (array)($filters['level_arr'] ?? []);
    $level_arr_in = array_values(array_filter(array_map(fn($v)=>trim((string)$v), $level_arr_in)));
    if (empty($level_arr_in) && $level_f !== '') $level_arr_in = [$level_f];
    $level_labels_norm = [];
    foreach ($level_arr_in as $lv) {
      $lbl = adg_level_label_norm($lv);
      if ($lbl !== '' && $lbl !== '—') $level_labels_norm[mb_strtolower($lbl)] = true;
    }

    $tutor_id_f   = (int)($filters['tutor_id'] ?? 0);
    $prof_id_f    = (int)($filters['professor_id'] ?? 0);
    $tutor_q      = trim((string)($filters['tutor_q'] ?? ''));
    $prof_q       = trim((string)($filters['prof_q']  ?? ''));

    // Resolve tutor_q / prof_q to sets of user IDs (search by name + email)
    $tutor_id_allow = null; // null = no filter
    $prof_id_allow  = null;
    if ($tutor_q !== '') {
      $tutor_id_allow = adg_resolve_user_ids_by_q($tutor_q, 'tutor');
      if (empty($tutor_id_allow)) {
        return ['cards'=>[], 'years'=>[], 'levels'=>[], 'total'=>0];
      }
    }
    if ($prof_q !== '') {
      $prof_id_allow = adg_resolve_user_ids_by_q($prof_q, 'profesor');
      if (empty($prof_id_allow)) {
        return ['cards'=>[], 'years'=>[], 'levels'=>[], 'total'=>0];
      }
    }

    $perpage      = max(5, min(200, (int)($filters['perpage'] ?? 25)));
    $paged        = max(1, (int)($filters['paged'] ?? 1));
    $offset       = ($paged - 1) * $perpage;

    // 1) Perechi (gen_id, prof_id) + #elevi (pe tot sistemul)
    $pairs = $wpdb->get_results("
      SELECT s.generation_id, s.professor_id, COUNT(*) AS students_count
      FROM {$tbl_students} s
      WHERE s.generation_id IS NOT NULL AND s.professor_id IS NOT NULL
      GROUP BY s.generation_id, s.professor_id
      ORDER BY s.generation_id DESC
    ");

    // 2) Map info generații (toate, inclusiv cele fără elevi)
    $all_gens = $wpdb->get_results("
      SELECT id, name, year, level, professor_id, created_at
      FROM {$tbl_generations}
      ORDER BY id DESC
    ");
    $gen_map = [];
    foreach ($all_gens as $r) $gen_map[(int)$r->id] = $r;

    // Adaugăm generațiile fără elevi ca perechi cu students_count=0
    $pairs_set = []; // "gid-pid" => true
    foreach ($pairs as $p) {
      $pairs_set[(int)$p->generation_id . '-' . (int)$p->professor_id] = true;
    }
    foreach ($all_gens as $g) {
      $gid = (int)$g->id;
      $pid = (int)($g->professor_id ?? 0);
      if ($pid > 0 && !isset($pairs_set[$gid . '-' . $pid])) {
        $obj = new \stdClass();
        $obj->generation_id = $gid;
        $obj->professor_id  = $pid;
        $obj->students_count = 0;
        $pairs[] = $obj;
      }
    }

    if (empty($pairs)) {
      return ['cards'=>[], 'years'=>[], 'levels'=>[], 'total'=>0];
    }

    // 3) Map tutor pentru profesori
    $prof_ids = array_values(array_unique(array_map(fn($p)=>(int)$p->professor_id, $pairs)));
    $tutor_by_prof = []; // pid => tid
    if (!empty($prof_ids)) {
      $in = implode(',', array_fill(0, count($prof_ids), '%d'));
      $rows = $wpdb->get_results($wpdb->prepare("
        SELECT user_id, meta_value
        FROM {$tbl_umeta}
        WHERE meta_key='assigned_tutor_id' AND user_id IN ($in)
      ", ...$prof_ids));
      foreach ($rows as $r) $tutor_by_prof[(int)$r->user_id] = (int)$r->meta_value;
    }

    $cards_all = [];
    $years_set = [];
    $levels_set= [];

    foreach ($pairs as $p) {
      $gid = (int)$p->generation_id;
      $pid = (int)$p->professor_id;
      $students_count = (int)$p->students_count;

      if ($prof_id_f && $pid !== $prof_id_f) continue;
      if (is_array($prof_id_allow) && !in_array($pid, $prof_id_allow, true)) continue;

      $gen  = $gen_map[$gid] ?? null;
      $gname= $gen ? (string)$gen->name : '—';
      $gyear_raw = $gen ? (string)$gen->year : '';
      // Normalize: "2025" → "2025-2026"; leave "2025-2026" as-is
      if ($gyear_raw !== '' && preg_match('/^\d{4}$/', $gyear_raw)) {
        $gyear = $gyear_raw . '-' . ((int)$gyear_raw + 1);
      } else {
        $gyear = $gyear_raw;
      }
      $glevel = $gen ? adg_level_label_norm($gen->level ?? '') : '—';

      if ($year_f !== '' && (string)$gyear !== (string)$year_f) continue;
      if (!empty($level_labels_norm) && !isset($level_labels_norm[mb_strtolower($glevel)])) continue;

      $uprof = get_user_by('id', $pid);
      $prof_name = adg_user_fullname($uprof);

      $tid = (int)($tutor_by_prof[$pid] ?? 0);
      if ($tutor_id_f && $tid !== $tutor_id_f) continue;
      if (is_array($tutor_id_allow) && !in_array($tid, $tutor_id_allow, true)) continue;
      $utut = $tid ? get_user_by('id', $tid) : null;
      $tutor_name = $tid ? adg_user_fullname($utut) : '—';

      // search în nume gen/prof/tutor + an + nivel
      if ($s !== '') {
        $needle = mb_strtolower($s);
        $hay = mb_strtolower($gname.' '.$prof_name.' '.$tutor_name.' '.$gyear.' '.$glevel);
        if (mb_strpos($hay, $needle) === false) continue;
      }

      // === Refolosim calculele SEL/LIT identice cu tutor_gen_build_cards ===
      // SEL
      $rows_sel = $wpdb->get_results( $wpdb->prepare("
        SELECT r.* FROM {$tbl_results} r
        JOIN {$tbl_students} s ON s.id=r.student_id
        WHERE s.generation_id=%d AND s.professor_id=%d AND r.modul_type='sel'
      ", $gid, $pid) );
      $sel_by_stage = sel_latest_any_by_stage($rows_sel); // ['sel-t0'=>[sid=>row], 'sel-ti'=>..., 'sel-t1'=>...]

      $sel_t0_avg_ch = edus_sel_avg_by_chapters($sel_by_stage['sel-t0'] ?? [], $SEL_CHAPTERS);
      $sel_ti_avg_ch = edus_sel_avg_by_chapters($sel_by_stage['sel-ti'] ?? [], $SEL_CHAPTERS);
      $sel_t1_avg_ch = edus_sel_avg_by_chapters($sel_by_stage['sel-t1'] ?? [], $SEL_CHAPTERS);

      $sel_avg = [
        't0' => edus_array_avg_non_null($sel_t0_avg_ch),
        'ti' => edus_array_avg_non_null($sel_ti_avg_ch),
        't1' => edus_array_avg_non_null($sel_t1_avg_ch),
      ];
      $sel_comp = [
        't0' => $students_count ? (100.0 * count($sel_by_stage['sel-t0']) / $students_count) : null,
        'ti' => $students_count ? (100.0 * count($sel_by_stage['sel-ti']) / $students_count) : null,
        't1' => $students_count ? (100.0 * count($sel_by_stage['sel-t1']) / $students_count) : null,
      ];

      // LIT
      $rows_lit = $wpdb->get_results( $wpdb->prepare("
        SELECT r.* FROM {$tbl_results} r
        JOIN {$tbl_students} s ON s.id=r.student_id
        WHERE s.generation_id=%d AND s.professor_id=%d AND r.modul_type IN ('lit','literatie')
      ", $gid, $pid) );
      $lit_latest = (function($rows){
        $stages = ['t0','t1']; $out = ['t0'=>[],'t1'=>[]];
        foreach ($rows as $r){
          $m = strtolower(trim($r->modul ?? ''));
          $st = (strpos($m,'-t0')!==false) ? 't0' : ((strpos($m,'-t1')!==false) ? 't1' : null);
          if (!$st) continue;
          $sid = (int)($r->student_id ?? 0); if (!$sid) continue;
          $k = row_order_key($r);
          if (!isset($out[$st][$sid]) || $k > $out[$st][$sid]['_k']) $out[$st][$sid] = ['row'=>$r,'_k'=>$k];
        }
        foreach ($stages as $st){ foreach ($out[$st] as $sid => $wrap){ $out[$st][$sid] = $wrap['row']; } }
        return $out;
      })($rows_lit);

      // class values pentru Δ
      $sid_need = [];
      foreach (['t0','t1'] as $st){ foreach ($lit_latest[$st] as $sid => $r){ $sid_need[$sid]=true; } }
      $class_values = [];
      if (!empty($sid_need)){
        $ids = array_map('intval', array_keys($sid_need));
        $in  = implode(',', array_fill(0, count($ids), '%d'));
        $student_rows = $wpdb->get_results( $wpdb->prepare("SELECT id, class_label FROM {$tbl_students} WHERE id IN ($in)", ...$ids) );
        foreach ((array)$student_rows as $sr){
          $grade = edus_grade_number_from_classlabel($sr->class_label ?? '');
          $class_values[(int)$sr->id] = max(0, min(8, ($grade>=0 ? $grade : 0)));
        }
      }

      $acc_lvl = ['t0'=>null,'t1'=>null,'avg'=>null];
      $comp_lvl= ['t0'=>null,'t1'=>null,'avg'=>null];
      $acc_dlt = ['t0'=>null,'t1'=>null,'avg'=>null];
      $comp_dlt=['t0'=>null,'t1'=>null,'avg'=>null];
      $lit_comp = ['t0'=>count($lit_latest['t0']), 't1'=>count($lit_latest['t1'])];

      foreach (['t0','t1'] as $st){
        $accs=[]; $comps=[]; $dacc=[]; $dcomp=[];
        foreach ($lit_latest[$st] as $sid => $row){
          [$a,$c] = es_lit_levels_from_row($row);
          $cv = $class_values[$sid] ?? null;
          if ($a !== null){ $accs[]=$a; if ($cv!==null) $dacc[] = ($a - $cv); }
          if ($c !== null){ $comps[]=$c; if ($cv!==null) $dcomp[]= ($c - $cv); }
        }
        $acc_lvl[$st] = es_avg_non_null($accs);  $comp_lvl[$st] = es_avg_non_null($comps);
        $acc_dlt[$st] = es_avg_non_null($dacc);  $comp_dlt[$st] = es_avg_non_null($dcomp);
      }
      $acc_lvl['avg']  = es_avg_non_null([$acc_lvl['t0'],$acc_lvl['t1']]);
      $comp_lvl['avg'] = es_avg_non_null([$comp_lvl['t0'],$comp_lvl['t1']]);
      $acc_dlt['avg']  = es_avg_non_null([$acc_dlt['t0'],$acc_dlt['t1']]);
      $comp_dlt['avg'] = es_avg_non_null([$comp_dlt['t0'],$comp_dlt['t1']]);

      // remedial & status
      $rem_cnt = ['t0'=>0,'t1'=>0];
      foreach (['t0','t1'] as $st){
        $cnt=0; foreach ($lit_latest[$st] as $sid => $row){ if (es_lit_is_remedial($row)) $cnt++; }
        $rem_cnt[$st]=$cnt;
      }
      $drafts_count = (int) $wpdb->get_var( $wpdb->prepare("
        SELECT COUNT(*) FROM {$tbl_results} r
        JOIN {$tbl_students} s ON s.id=r.student_id
        WHERE s.generation_id=%d AND s.professor_id=%d AND r.status='draft'
      ", $gid, $pid) );
      $finals_count = (int) $wpdb->get_var( $wpdb->prepare("
        SELECT COUNT(*) FROM {$tbl_results} r
        JOIN {$tbl_students} s ON s.id=r.student_id
        WHERE s.generation_id=%d AND s.professor_id=%d AND r.status='final'
      ", $gid, $pid) );

      // completări % + LIT % medii
      $comp_rates = [
        'sel'=>[ 't0'=>$sel_comp['t0'], 'ti'=>$sel_comp['ti'], 't1'=>$sel_comp['t1'] ],
        'lit'=>[ 't0'=>$students_count ? (100.0 * $lit_comp['t0'] / $students_count) : null,
                 't1'=>$students_count ? (100.0 * $lit_comp['t1'] / $students_count) : null ],
      ];
      $lit_pct_avg = ['t0'=>null,'t1'=>null,'avg'=>null];
      foreach (['t0','t1'] as $st){
        $vals = [];
        foreach ($lit_latest[$st] as $sid => $row){
          $p = es_lit_row_pct($row);
          if ($p !== null) $vals[] = $p;
        }
        $lit_pct_avg[$st] = es_avg_non_null($vals);
      }
      $lit_pct_avg['avg'] = es_avg_non_null([$lit_pct_avg['t0'],$lit_pct_avg['t1']]);
      $rem_avg = es_avg_non_null([ $rem_cnt['t0'], $rem_cnt['t1'] ]);
      $lit_comp_avg = es_avg_non_null([ $comp_rates['lit']['t0'], $comp_rates['lit']['t1'] ]);

      // add row
      $cards_all[] = [
        'gid'=>$gid, 'pid'=>$pid, 'gname'=>$gname, 'gyear'=>$gyear,
        'glevel'=>$glevel,
        'prof_name'=>$prof_name,
        'tid'=>$tid, 'tutor_name'=>$tutor_name,
        'students_count'=>$students_count,
        'drafts_count'=>$drafts_count, 'finals_count'=>$finals_count,

        'sel_t0'=>$sel_avg['t0'], 'sel_ti'=>$sel_avg['ti'], 'sel_t1'=>$sel_avg['t1'],

        'acc_t0_num'=>$acc_lvl['t0'], 'acc_t1_num'=>$acc_lvl['t1'], 'acc_avg_num'=>$acc_lvl['avg'],
        'acc_t0_delta'=>$acc_dlt['t0'], 'acc_t1_delta'=>$acc_dlt['t1'], 'acc_avg_delta'=>$acc_dlt['avg'],
        'comp_t0_num'=>$comp_lvl['t0'], 'comp_t1_num'=>$comp_lvl['t1'], 'comp_avg_num'=>$comp_lvl['avg'],
        'comp_t0_delta'=>$comp_dlt['t0'], 'comp_t1_delta'=>$comp_dlt['t1'], 'comp_avg_delta'=>$comp_dlt['avg'],

        'rem_t0'=>$rem_cnt['t0'], 'rem_t1'=>$rem_cnt['t1'],
        'comp_rates'=>$comp_rates,

        'lit_t0_pct' => $lit_pct_avg['t0'],
        'lit_t1_pct' => $lit_pct_avg['t1'],
        'lit_avg_pct'=> $lit_pct_avg['avg'],
        'rem_avg'    => $rem_avg,
        'lit_comp_avg'=> $lit_comp_avg,

        'created_at' => $gen->created_at ?? null,
      ];

      // colecții pentru filtre
      if ($gyear !== '') $years_set[$gyear]=true;
      if ($glevel !== '') $levels_set[$glevel]=true;
    }

    // sortare (an DESC, apoi gid DESC)
    usort($cards_all, function($a,$b){
      if ($a['gyear'] === $b['gyear']) return $b['gid'] <=> $a['gid'];
      return strnatcasecmp($b['gyear'], $a['gyear']);
    });

    $total = count($cards_all);

    // paginare
    $cards = array_slice($cards_all, $offset, $perpage);

    $years  = array_keys($years_set);  sort($years, SORT_NATURAL);
    $levels = array_keys($levels_set); sort($levels, SORT_NATURAL);

    return [
      'cards'  => $cards,
      'years'  => $years,
      'levels' => $levels,
      'total'  => $total,
    ];
  }
}
