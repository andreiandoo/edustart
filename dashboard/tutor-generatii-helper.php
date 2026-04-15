<?php
if (!defined('ABSPATH')) exit;

/** ===================== Utils generale ===================== */
if (!function_exists('es_maybe_unserialize_arr')) {
  function es_maybe_unserialize_arr($s){
    if ($s === null || $s === '') return null;
    if (is_array($s)) return $s;
    $arr = @maybe_unserialize($s);
    return is_array($arr) ? $arr : null;
  }
}
if (!function_exists('es_avg_non_null')) {
  function es_avg_non_null($arr){ $s=0;$n=0; foreach((array)$arr as $v){ if($v!==null && $v!==''){ $s+=floatval($v); $n++; } } return $n?($s/$n):null; }
}
if (!function_exists('es_level_label_norm')) {
  function es_level_label_norm($raw){
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
if (!function_exists('edus_grade_number_from_classlabel')) {
  function edus_grade_number_from_classlabel($label){
    $s = mb_strtolower(trim((string)$label));
    $s = str_replace(['â','ă','î','ș','ţ','ț'],['a','a','i','s','t','t'],$s);
    if ($s==='' || strpos($s,'prescolar')!==false) return -1;
    if (strpos($s,'pregatitoare')!==false) return 0;
    if (preg_match('/\b([0-9])\b/', $s, $m)) { $n=intval($m[1]); if ($n>=1 && $n<=8) return $n; }
    return 1;
  }
}
if (!function_exists('edus_level_string_to_num')) {
  function edus_level_string_to_num($v){
    $v = strtoupper(trim((string)$v));
    if ($v==='') return null;
    if ($v==='PP') return -1;
    if ($v==='P')  return 0;
    if (is_numeric($v)) return intval($v);
    return null;
  }
}
if (!function_exists('gen_lit_level_num_to_label')) {
  function gen_lit_level_num_to_label($num){
    if ($num===null) return '—';
    $r = round($num);
    if ($r <= -1) return 'PP';
    if ($r === 0) return 'P';
    if ($r >= 1 && $r <= 4) return (string)$r;
    return (string)$r;
  }
}

/** ===================== SEL — calc la fel ca în raportul tău ===================== */
if (!function_exists('str_starts_with')) {
  function str_starts_with($h,$n){ return $n===''?true:strpos($h,$n)===0; }
}

$SEL_CHAPTERS = ['Conștientizare de sine','Autoreglare','Conștientizare socială','Relaționare','Luarea deciziilor'];

if (!function_exists('edus_sel_stage_from_modul')) {
  // STRICT: "sel-t0", "sel-ti", "sel-t1" (ca în raport)
  function edus_sel_stage_from_modul($m){
    $m = strtolower(trim($m ?? ''));
    if (str_starts_with($m,'sel-t0')) return 'sel-t0';
    if (str_starts_with($m,'sel-ti')) return 'sel-ti';
    if (str_starts_with($m,'sel-t1')) return 'sel-t1';
    return null;
  }
}
if (!function_exists('is_serialized')) {
  function is_serialized($data){
    if (!is_string($data)) return false;
    $data = trim($data);
    if ($data === 'N;') return true;
    if (!preg_match('/^[adObis]:/', $data)) return false;
    return @unserialize($data) !== false || $data === 'b:0;';
  }
}
if (!function_exists('edus_sel_parse_chapter_map')) {
  // exact ca în helperul tău: citește capitole din score (serialized) sau results JSON
  function edus_sel_parse_chapter_map($row, $chapters){
    $map = [];
    foreach ($chapters as $c) $map[$c] = null;

    $score = $row->score ?? null;
    if (is_serialized($score)) {
      $a = @unserialize($score);
      if (is_array($a)) {
        foreach ($chapters as $c) {
          $map[$c] = (isset($a[$c]) && is_numeric($a[$c])) ? floatval($a[$c]) : null;
        }
        return $map;
      }
    }
    $json = json_decode($row->results ?? '', true);
    if (is_array($json)) {
      if (isset($json['chapters']) && is_array($json['chapters'])) {
        foreach ($chapters as $c) {
          $v = $json['chapters'][$c] ?? null;
          $map[$c] = is_array($v) ? (is_numeric($v['score'] ?? null) ? floatval($v['score']) : null)
                                  : (is_numeric($v) ? floatval($v) : null);
        }
      } elseif (isset($json['score_breakdown']) && is_array($json['score_breakdown'])) {
        foreach ($json['score_breakdown'] as $it) {
          $cap = $it['chapter'] ?? null;
          if ($cap && in_array($cap, $chapters, true)) {
            $map[$cap] = is_numeric($it['score'] ?? null) ? floatval($it['score']) : null;
          }
        }
      }
    }
    return $map;
  }
}
if (!function_exists('edus_array_avg_non_null')) {
  function edus_array_avg_non_null(array $arr){
    $s=0; $n=0; foreach($arr as $v){ if($v!==null && $v!==''){ $s+=floatval($v); $n++; } }
    return $n ? ($s/$n) : null;
  }
}
if (!function_exists('edus_sel_avg_by_chapters')) {
  // medie pe capitole (la nivel de generație), ca în raport
  function edus_sel_avg_by_chapters($rows, $chapters){
    $sums = array_fill_keys($chapters, 0.0);
    $cnts = array_fill_keys($chapters, 0);
    foreach ($rows as $r) {
      $m = edus_sel_parse_chapter_map($r, $chapters);
      foreach ($chapters as $c) {
        if ($m[$c] !== null) { $sums[$c] += $m[$c]; $cnts[$c] += 1; }
      }
    }
    $avg = [];
    foreach ($chapters as $c) $avg[$c] = $cnts[$c] ? ($sums[$c] / $cnts[$c]) : 0.0;
    return $avg;
  }
}
if (!function_exists('row_order_key')) {
  function row_order_key($r){
    if (!empty($r->updated_at)) return strtotime($r->updated_at);
    if (!empty($r->created_at)) return strtotime($r->created_at);
    return intval($r->id ?? 0);
  }
}
/**
 * Ultimul rezultat SEL per elev PE ETAPĂ (indiferent de status), ca în raport:
 *  - alegem după updated_at > created_at > id
 *  - prioritizăm doar etapa (nu status)
 * return: ['sel-t0'=>[sid=>row], 'sel-ti'=>[...], 'sel-t1'=>[...]]
 */
if (!function_exists('sel_latest_any_by_stage')) {
  function sel_latest_any_by_stage($rows){
    global $SEL_CHAPTERS;
    $perStage = []; // per elev
    foreach ($rows as $r){
      $mt = strtolower(trim($r->modul_type ?? ''));
      $mm = strtolower(trim($r->modul ?? ''));
      $isSEL = ($mt === 'sel') || str_starts_with($mm, 'sel-');
      if (!$isSEL) continue;

      $sid = (int)($r->student_id ?? 0);
      if (!$sid) continue;

      $stage = edus_sel_stage_from_modul($r->modul);
      if (!$stage) continue;

      $k = row_order_key($r);
      if (!isset($perStage[$stage][$sid]) || $k > $perStage[$stage][$sid]['_k']) {
        $perStage[$stage][$sid] = ['row'=>$r,'_k'=>$k];
      }
    }
    $out = ['sel-t0'=>[],'sel-ti'=>[],'sel-t1'=>[]];
    foreach ($perStage as $st => $bySid){
      foreach ($bySid as $sid => $wrap){ $out[$st][$sid] = $wrap['row']; }
    }
    return $out;
  }
}

/** ===================== LIT (aceeași logică de la tine — ok) ===================== */
if (!function_exists('es_lit_levels_from_row')) {
  function es_lit_levels_from_row($row){
    $arr = es_maybe_unserialize_arr($row->score ?? null);
    $raw = [];
    if (is_array($arr) && !empty($arr['breakdown']) && is_array($arr['breakdown'])) {
      foreach ($arr['breakdown'] as $it) {
        if (!empty($it['name'])) $raw[$it['name']] = $it['value'] ?? null;
      }
    }
    if (!$raw) {
      $json = json_decode($row->results ?? '', true);
      if (is_array($json) && !empty($json['raw'])) $raw = $json['raw'];
    }
    $acc  = edus_level_string_to_num($raw['lit_q2'] ?? null);
    $comp = edus_level_string_to_num($raw['lit_q4'] ?? null);
    return [$acc, $comp];
  }
}
if (!function_exists('es_lit_is_remedial')) {
  function es_lit_is_remedial($row){
    $arr = es_maybe_unserialize_arr($row->score ?? null);
    if (is_array($arr)) {
      if (!empty($arr['remedial'])) return true;
      if (!empty($arr['breakdown']) && is_array($arr['breakdown'])) {
        foreach ($arr['breakdown'] as $it) {
          if (($it['name'] ?? '') === 'lit_q2') {
            $v = strtoupper(trim((string)($it['value'] ?? '')));
            if ($v==='PP' || $v==='P') return true;
          }
        }
      }
    }
    $json = json_decode($row->results ?? '', true);
    if (is_array($json)) {
      if (!empty($json['remedial'])) return true;
      $v = strtoupper(trim((string)($json['raw']['lit_q2'] ?? '')));
      if ($v==='PP' || $v==='P') return true;
    }
    return false;
  }
}

/** ===================== UI helpers (badge-uri/culori) ===================== */
if (!function_exists('tutor_badge')) {
  function tutor_badge($text, $class){
    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold '.$class.'">'.$text.'</span>';
  }
}
if (!function_exists('tutor_chip_delta')) {
  function tutor_chip_delta($d){
    if ($d===null) return '<span class="inline-flex items-center px-2 py-0.5 text-xs rounded bg-slate-100 text-slate-500">—</span>';
    $cls = ($d>=0) ? 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200'
                   : 'bg-rose-100 text-rose-800 ring-1 ring-rose-200';
    $lab = ($d>0?'+':'').number_format($d,0);
    return '<span class="inline-flex items-center px-2 py-0.5 text-xs rounded '.$cls.'">'.$lab.'</span>';
  }
}
if (!function_exists('tutor_level_label_badge')) {
  function tutor_level_label_badge($num){
    if ($num===null) return '<span class="inline-flex px-2 py-0.5 text-xs rounded bg-slate-100 text-slate-500">—</span>';
    $label = gen_lit_level_num_to_label($num);
    $r = round($num);
    if ($r <= 0)      $cls = 'bg-rose-100 text-rose-800 ring-1 ring-rose-200';
    elseif ($r <= 2)  $cls = 'bg-yellow-100 text-yellow-800 ring-1 ring-yellow-200';
    else              $cls = 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200';
    return '<span class="inline-flex items-center px-2 py-0.5 text-xs rounded '.$cls.'">'.$label.'</span>';
  }
}
if (!function_exists('tutor_pct_badge')) {
  function tutor_pct_badge($pct){
    if ($pct===null) return '<span class="inline-flex px-2 py-0.5 text-xs rounded bg-slate-100 text-slate-500">—</span>';
    $v = max(0,min(100,floatval($pct)));
    if ($v >= 80)      $cls = 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200';
    elseif ($v >= 60)  $cls = 'bg-lime-100 text-lime-800 ring-1 ring-lime-200';
    elseif ($v >= 40)  $cls = 'bg-yellow-100 text-yellow-800 ring-1 ring-yellow-200';
    else               $cls = 'bg-rose-100 text-rose-800 ring-1 ring-rose-200';
    return '<span class="inline-flex px-2 py-0.5 text-xs rounded '.$cls.'">'.intval(round($v)).'%</span>';
  }
}

if (!function_exists('tutor_sel_badge')) {
  // Barem SEL (0–3): <1.5 roșu, <2.0 galben, <2.5 lime, altfel verde
  function tutor_sel_badge($val){
    if ($val===null) return '<span class="inline-flex px-2 py-0.5 text-xs rounded bg-slate-100 text-slate-500">—</span>';
    $v = floatval($val);
    if ($v < 1.5)      $cls = 'bg-rose-100 text-rose-800 ring-1 ring-rose-200';
    elseif ($v < 2.0)  $cls = 'bg-yellow-100 text-yellow-800 ring-1 ring-yellow-200';
    elseif ($v < 2.5)  $cls = 'bg-lime-100 text-lime-800 ring-1 ring-lime-200';
    else               $cls = 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200';
    return '<span class="inline-flex items-center px-2 py-0.5 text-xs rounded '.$cls.'">'.number_format($v,2).'</span>';
  }
}

if (!function_exists('es_lit_row_pct')) {
  // % dintr-un rezultat LIT: score.total / meta.total_max; fallback: breakdown sum(value)/sum(max)
  function es_lit_row_pct($row){
    $arr = es_maybe_unserialize_arr($row->score ?? null);
    if (is_array($arr)) {
      $tot = isset($arr['total']) && is_numeric($arr['total']) ? floatval($arr['total']) : null;
      $max = null;
      if (!empty($arr['meta']) && is_array($arr['meta']) && isset($arr['meta']['total_max']) && is_numeric($arr['meta']['total_max'])) {
        $max = floatval($arr['meta']['total_max']);
      }
      if ($tot !== null && $max && $max > 0) return 100.0 * $tot / $max;
      if (!empty($arr['breakdown']) && is_array($arr['breakdown'])) {
        $sum=0; $sumMax=0; $had=false;
        foreach ($arr['breakdown'] as $it) {
          $v = $it['value'] ?? null; $m = $it['max'] ?? null;
          if (is_numeric($v) && is_numeric($m)) { $sum += floatval($v); $sumMax += floatval($m); $had=true; }
        }
        if ($had && $sumMax>0) return 100.0 * $sum / $sumMax;
      }
    }
    return null;
  }
}

if (!function_exists('tutor_count_pill')) {
  function tutor_count_pill($n){
    $n = (int)$n;
    $cls = $n>0 ? 'bg-rose-100 text-rose-800 ring-1 ring-rose-200'
                : 'bg-slate-100 text-slate-700 ring-1 ring-slate-200';
    return '<span class="inline-flex items-center gap-x-2 px-2 py-0.5 text-xs rounded '.$cls.'">
      <svg class="size-2" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5.33 0-8 2.667-8 6a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1c0-3.333-2.67-6-8-6Z"/></svg><b>'.$n.'</b></span>';
  }
}

if (!function_exists('bg_score_class')) {
  function bg_score_class($v){
    if ($v===null) return 'bg-slate-200 text-slate-700';
    $x = floatval($v);
    if($x<1.5)  return 'bg-red-600 text-white font-bold';
    if($x<2.0)  return 'bg-orange-400 text-white font-bold';
    if($x<2.5)  return 'bg-yellow-300 text-slate-800 font-bold';
    if($x<2.75) return 'bg-lime-500 text-slate-800 font-bold';
    if($x<3.0)  return 'bg-lime-600 text-white font-bold';
    return 'bg-green-500 text-white font-bold';
  }
}

/* pill numeric (medii SEL sau Δ LIT) – folosește exact bg_score_class */
if (!function_exists('tutor_score_pill')) {
  function tutor_score_pill($val, $decimals=2, $prefix_plus=true){
    if ($val===null || $val==='') {
      return '<span class="inline-flex px-2 py-0.5 text-xs rounded bg-slate-200 text-slate-700">—</span>';
    }
    $cls = bg_score_class($val);
    $lab = ($prefix_plus && $val>0?'+':'').number_format((float)$val, $decimals);
    return '<span class="inline-flex items-center px-2 py-0.5 text-base rounded '.$cls.'">'.$lab.'</span>';
  }
}

/* pill procent — aceleași culori, mapate pe praguri % (paleta identică, nuanțele ca la bg_score_class) */
if (!function_exists('tutor_pct_pill')) {
  function tutor_pct_pill($pct){
    if ($pct===null) return '<span class="inline-flex px-2 py-0.5 text-xs rounded bg-slate-200 text-slate-700">—</span>';
    $v = max(0,min(100,floatval($pct)));
    // praguri recomandate pentru completare:  <40, <60, <75, <90, <100, >=100
    if ($v < 40)      $cls = 'bg-red-600 text-white font-bold';
    elseif ($v < 60)  $cls = 'bg-orange-400 text-white font-bold';
    elseif ($v < 75)  $cls = 'bg-yellow-300 text-slate-800 font-bold';
    elseif ($v < 90)  $cls = 'bg-lime-500 text-slate-800 font-bold';
    elseif ($v < 100) $cls = 'bg-lime-600 text-white font-bold';
    else              $cls = 'bg-green-500 text-white font-bold';
    return '<span class="inline-flex items-center px-2 py-0.5 text-xs rounded '.$cls.'">'.intval(round($v)).'%</span>';
  }
}

// Clasa cromatică pentru Δ LIT
if (!function_exists('bg_lit_delta_class')) {
  function bg_lit_delta_class($v){
    if ($v === null || $v === '') return 'bg-slate-200 text-slate-700';
    $x = floatval($v);
    return ($x < 0)
      ? 'bg-red-600 text-white font-bold'
      : 'bg-emerald-600 text-white font-bold';
  }
}

// Pill HTML pentru Δ LIT (opțional, ca să afișezi direct)
if (!function_exists('tutor_lit_delta_pill')) {
  function tutor_lit_delta_pill($val, $decimals=0, $prefix_plus=true){
    if ($val === null || $val === '') {
      return '<span class="inline-flex px-2 py-0.5 text-base rounded bg-slate-200 text-slate-700">—</span>';
    }
    $cls = bg_lit_delta_class($val);
    $lab = ($prefix_plus && $val > 0 ? '+' : '') . number_format((float)$val, $decimals);
    return '<span class="inline-flex items-center px-2 py-0.5 text-base rounded '.$cls.'">'.$lab.'</span>';
  }
}


/* opțional: alias vechi -> nou, ca să nu rupi alte locuri */
if (!function_exists('tutor_pct_badge')) {
  function tutor_pct_badge($pct){ return tutor_pct_pill($pct); }
}

/** ===================== Builder principal ===================== */
function tutor_gen_build_cards($tutor_id){
  global $wpdb, $SEL_CHAPTERS;
  $tbl_umeta       = $wpdb->usermeta;
  $tbl_results     = $wpdb->prefix . 'edu_results';
  $tbl_students    = $wpdb->prefix . 'edu_students';
  $tbl_generations = $wpdb->prefix . 'edu_generations';

  // profesori alocați
  $professor_ids = $wpdb->get_col(
    $wpdb->prepare("SELECT user_id FROM $tbl_umeta WHERE meta_key=%s AND CAST(meta_value AS UNSIGNED)=%d", 'assigned_tutor_id', $tutor_id)
  );
  if (empty($professor_ids)) return ['professor_ids'=>[], 'pairs'=>[], 'cards'=>[]];

  // perechi gen-prof
  $ph = implode(',', array_fill(0, count($professor_ids), '%d'));
  $sql_pairs = "
    SELECT s.generation_id, s.professor_id, COUNT(*) AS students_count
    FROM $tbl_students s
    WHERE s.professor_id IN ($ph) AND s.generation_id IS NOT NULL
    GROUP BY s.generation_id, s.professor_id
    ORDER BY s.generation_id DESC
  ";
  $args_pairs = array_merge([ $sql_pairs ], array_map('intval', $professor_ids));
  $pairs = call_user_func_array([ $wpdb, 'get_results' ], [ call_user_func_array([ $wpdb, 'prepare' ], $args_pairs) ]);

  $cards = [];

  foreach ((array)$pairs as $p){
    $gid = (int)$p->generation_id;
    $pid = (int)$p->professor_id;
    $students_count = (int)$p->students_count;

    // info generație + profesor
    $gen = $wpdb->get_row( $wpdb->prepare("SELECT id, name, year FROM $tbl_generations WHERE id=%d", $gid) );
    $gname = $gen->name ?? '—';
    $gyear_raw = $gen->year ?? '—';
    $gyear = (preg_match('/^\d{4}$/', $gyear_raw)) ? $gyear_raw . '-' . ((int)$gyear_raw + 1) : $gyear_raw;
    $u = get_user_by('id', $pid);
    $prof_name  = $u ? trim(($u->first_name ?: $u->display_name) . ' ' . ($u->last_name ?? '')) : ('Profesor #'.$pid);
    $level_raw  = function_exists('get_field') ? get_field('nivel_predare', 'user_' . $pid) : get_user_meta($pid, 'nivel_predare', true);
    $prof_level = es_level_label_norm($level_raw);

    // rezultate SEL & LIT (pereche)
    $rows_sel = $wpdb->get_results( $wpdb->prepare("
      SELECT r.* FROM $tbl_results r
      JOIN $tbl_students s ON s.id=r.student_id
      WHERE s.generation_id=%d AND s.professor_id=%d AND r.modul_type='sel'
    ", $gid, $pid) );
    $rows_lit = $wpdb->get_results( $wpdb->prepare("
      SELECT r.* FROM $tbl_results r
      JOIN $tbl_students s ON s.id=r.student_id
      WHERE s.generation_id=%d AND s.professor_id=%d AND r.modul_type IN ('lit','literatie')
    ", $gid, $pid) );

    /** ---------- SEL: exact ca în raportul de generație ---------- */
    $sel_by_stage = sel_latest_any_by_stage($rows_sel); // ['sel-t0'=>[sid=>row], ...]
    // medie pe capitole la nivel de generație (pe etapă)
    $sel_t0_avg_ch = edus_sel_avg_by_chapters($sel_by_stage['sel-t0'] ?? [], $SEL_CHAPTERS);
    $sel_ti_avg_ch = edus_sel_avg_by_chapters($sel_by_stage['sel-ti'] ?? [], $SEL_CHAPTERS);
    $sel_t1_avg_ch = edus_sel_avg_by_chapters($sel_by_stage['sel-t1'] ?? [], $SEL_CHAPTERS);
    // OVERALL = media acestor medii pe capitole
    $sel_avg = [
      't0' => edus_array_avg_non_null($sel_t0_avg_ch),
      'ti' => edus_array_avg_non_null($sel_ti_avg_ch),
      't1' => edus_array_avg_non_null($sel_t1_avg_ch),
    ];
    // completare SEL per etapă (nr elevi cu rezultat / total elevi pereche)
    $sel_comp = [
      't0' => $students_count ? (100.0 * count($sel_by_stage['sel-t0']) / $students_count) : null,
      'ti' => $students_count ? (100.0 * count($sel_by_stage['sel-ti']) / $students_count) : null,
      't1' => $students_count ? (100.0 * count($sel_by_stage['sel-t1']) / $students_count) : null,
    ];

    /** ---------- LIT: (secțiunea ta deja corectă) ---------- */
    // ultimul pe etapă (preferința de status nu contează aici pentru tine; ai zis că e ok)
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

    // class_value per elev (pt Δ)
    $sid_need = [];
    foreach (['t0','t1'] as $st){ foreach ($lit_latest[$st] as $sid => $r){ $sid_need[$sid]=true; } }
    $class_values = [];
    if (!empty($sid_need)){
      $ids = array_map('intval', array_keys($sid_need));
      $in  = implode(',', array_fill(0, count($ids), '%d'));
      $student_rows = $wpdb->get_results( $wpdb->prepare("SELECT id, class_label FROM $tbl_students WHERE id IN ($in)", ...$ids) );
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

    /** ---------- Remedial & status evaluări ---------- */
    $rem_cnt = ['t0'=>0,'t1'=>0];
    foreach (['t0','t1'] as $st){
      $cnt=0; foreach ($lit_latest[$st] as $sid => $row){ if (es_lit_is_remedial($row)) $cnt++; }
      $rem_cnt[$st]=$cnt;
    }
    $drafts_count = (int) $wpdb->get_var( $wpdb->prepare("
      SELECT COUNT(*) FROM $tbl_results r
      JOIN $tbl_students s ON s.id=r.student_id
      WHERE s.generation_id=%d AND s.professor_id=%d AND r.status='draft'
    ", $gid, $pid) );
    $finals_count = (int) $wpdb->get_var( $wpdb->prepare("
      SELECT COUNT(*) FROM $tbl_results r
      JOIN $tbl_students s ON s.id=r.student_id
      WHERE s.generation_id=%d AND s.professor_id=%d AND r.status='final'
    ", $gid, $pid) );

    /** ---------- Completare (%) ---------- */
    $comp_rates = [
      'sel'=>[ 't0'=>$sel_comp['t0'], 'ti'=>$sel_comp['ti'], 't1'=>$sel_comp['t1'] ],
      'lit'=>[ 't0'=>$students_count ? (100.0 * $lit_comp['t0'] / $students_count) : null,
               't1'=>$students_count ? (100.0 * $lit_comp['t1'] / $students_count) : null ],
    ];

    // --- LIT: % medie pe etapă (T0/T1), folosind aceleași selecții "ultimul pe etapă" ---
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

    // --- medii pentru completare și remedial, ca să păstrăm structura cu o coloană "Medie" ---
    $rem_avg = es_avg_non_null([ $rem_cnt['t0'], $rem_cnt['t1'] ]); // medie simplă a numărului de elevi
    $lit_comp_avg = es_avg_non_null([ $comp_rates['lit']['t0'], $comp_rates['lit']['t1'] ]);

    $cards[] = [
      'gid'=>$gid, 'pid'=>$pid, 'gname'=>$gname, 'gyear'=>$gyear,
      'prof_name'=>$prof_name, 'prof_level'=>$prof_level, 'students_count'=>$students_count,
      'drafts_count'=>$drafts_count, 'finals_count'=>$finals_count,

      // SEL – OVERALL ca în raport (medie capitole): T0/Ti/T1
      'sel_t0'=>$sel_avg['t0'], 'sel_ti'=>$sel_avg['ti'], 'sel_t1'=>$sel_avg['t1'],

      // LIT – nivele + Δ
      'acc_t0_num'=>$acc_lvl['t0'], 'acc_t1_num'=>$acc_lvl['t1'], 'acc_avg_num'=>$acc_lvl['avg'],
      'acc_t0_delta'=>$acc_dlt['t0'], 'acc_t1_delta'=>$acc_dlt['t1'], 'acc_avg_delta'=>$acc_dlt['avg'],
      'comp_t0_num'=>$comp_lvl['t0'], 'comp_t1_num'=>$comp_lvl['t1'], 'comp_avg_num'=>$comp_lvl['avg'],
      'comp_t0_delta'=>$comp_dlt['t0'], 'comp_t1_delta'=>$comp_dlt['t1'], 'comp_avg_delta'=>$comp_dlt['avg'],

      // Remedial & completare
      'rem_t0'=>$rem_cnt['t0'], 'rem_t1'=>$rem_cnt['t1'],
      'comp_rates'=>$comp_rates,

      'lit_t0_pct' => $lit_pct_avg['t0'],
      'lit_t1_pct' => $lit_pct_avg['t1'],
      'lit_avg_pct'=> $lit_pct_avg['avg'],
      'rem_avg'    => $rem_avg,
      'lit_comp_avg'=> $lit_comp_avg,
    ];
  }

  return ['professor_ids'=>$professor_ids, 'pairs'=>$pairs, 'cards'=>$cards];
}
