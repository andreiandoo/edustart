<?php
/** Raport generație — LIT (helpers/agregări robuste)
 *  Depinde de: $wpdb, $rowsRaw (toate rezultatele elevilor din generație)
 *  Nu mai depinde obligatoriu de $students; își ia class_label din DB la nevoie.
 *
 *  Expune pentru UI:
 *   - $gen_lit_stage: [
 *        't0'=>[
 *           'students','completion_avg','total_pct_avg',
 *           'remedial_rate',
 *           'remedial_points_sum','remedial_max_sum','total_pct_avg_remedial',
 *           'items'=>[key=>['label','avg_pct','n']],
 *           'levels'=>['acc'=>['delta_avg','avg_num'],'comp'=>['delta_avg','avg_num']],
 *           'status_counts'=>['draft'=>X,'final'=>Y],
 *           'items_core_raw'=>[
 *              key=>[
 *                'label',
 *                'is_level'=>bool,
 *                'avg_num'=>float|null,   // pentru categorice (PP=-2,P=-1,0..5)
 *                'avg_label'=>string|null,// PP/P/0..5 (rotunjit)
 *                'avg_raw'=>float|null    // pentru numerice (ex: q3,q6)
 *              ]
 *           ]
 *        ],
 *        't1'=>[...]
 *     ]
 *   - $gen_lit_total_pct, $gen_lit_delta_total
 *   - $gen_lit_total_pct_remedial, $gen_lit_overall_pct_remedial, $gen_lit_delta_total_remedial
 *   - $gen_lit_levels, $gen_lit_levels_delta_avg, $gen_lit_levels_delta_avg_color
 *   - $gen_lit_items_order
 *   - $gen_lit_completion_avg_overall   // medie T0/T1 pentru completare
 */

if (!defined('ABSPATH')) exit;

/* ---------- Utilitare minime (gărzi) ---------- */
if (!function_exists('maybe_unserialize')) {
  function maybe_unserialize($v){
    if (is_array($v) || is_object($v)) return $v;
    if (!is_string($v)) return $v;
    $v2 = trim($v);
    if ($v2 === '') return $v;
    if (preg_match('/^[adObis]:/', $v2) || $v2==='N;') {
      $u = @unserialize($v2);
      if ($u !== false || $v2==='b:0;') return $u;
    }
    return $v;
  }
}
if (!function_exists('avg_non_null')) {
  function avg_non_null($arr){ $s=0;$n=0; foreach((array)$arr as $v){ if($v!==null && $v!==''){ $s+=floatval($v); $n++; } } return $n?($s/$n):null; }
}
if (!function_exists('row_order_key')) {
  function row_order_key($r){
    if (!empty($r->updated_at)) return 't:'.strtotime($r->updated_at);
    if (!empty($r->created_at)) return 'c:'.strtotime($r->created_at);
    return 'i:'.intval($r->id ?? 0);
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
/* atenție: funcția existentă rămâne pentru normalizări 0..4 în alte calcule */
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
/* nou: mapare strictă pentru mediile pe itemi categorici (PP=-2, P=-1, 0..5) */
if (!function_exists('genlit_level_to_num_pp2')) {
  function genlit_level_to_num_pp2($v){
    $v = strtoupper(trim((string)$v));
    if ($v==='') return null;
    if ($v==='PP') return -2;
    if ($v==='P')  return -1;
    if (is_numeric($v)) return intval($v);
    return null;
  }
}
if (!function_exists('genlit_num_to_level_label_pp2')) {
  function genlit_num_to_level_label_pp2($num){
    if ($num===null) return '—';
    $r = round($num);
    if ($r <= -2) return 'PP';
    if ($r === -1) return 'P';
    return (string)$r; // 0..5
  }
}

/* ---------- Scheme ---------- */
$GEN_LIT_SCHEMES = [
  'prescolar' => [
    'total_max' => 51,
    'per_max'   => [ 'lit_q1'=>22, 'lit_q2'=>20, 'lit_q3'=>3, 'lit_q4'=>5, 'lit_q5'=>1 ],
    'categoric' => []
  ],
  'pregatitoare' => [
    'total_max' => 150,
    'per_max'   => [ 'lit_q7'=>6, 'lit_q8'=>93, 'lit_q9'=>12, 'lit_q10'=>22, 'lit_q11'=>12, 'lit_q12'=>5 ],
    'categoric' => []
  ],
  'primar_gimnaziu' => [
    'total_max' => 150,
    'per_max'   => [ 'lit_q3'=>100, 'lit_q6'=>5, 'lit_q7'=>6, 'lit_q8'=>93, 'lit_q9'=>12, 'lit_q10'=>22, 'lit_q11'=>12, 'lit_q12'=>5 ],
    'categoric' => [ 'lit_q1'=>['min'=>-1,'max'=>4], 'lit_q2'=>['min'=>-1,'max'=>4], 'lit_q4'=>['min'=>-1,'max'=>4], 'lit_q5'=>['min'=>-1,'max'=>4] ],
  ],
];
function genlit_pick_scheme_key($class_label){
  $g = edus_grade_number_from_classlabel($class_label);
  if ($g === -1) return 'prescolar';
  if ($g === 0)  return 'pregatitoare';
  return 'primar_gimnaziu';
}
function genlit_parse_score($row){
  $out = ['total'=>null,'breakdown'=>[],'raw'=>[],'_arr'=>[]];
  $arr = @maybe_unserialize($row->score ?? null);
  if (is_array($arr)) {
    if (isset($arr['total']) && is_numeric($arr['total'])) $out['total'] = (float)$arr['total'];
    if (!empty($arr['breakdown']) && is_array($arr['breakdown'])) {
      foreach($arr['breakdown'] as $it){
        if (empty($it['name'])) continue;
        $name  = $it['name'];
        $label = $it['label'] ?? $name;
        $value = $it['value'] ?? null;
        $max   = (isset($it['max']) && is_numeric($it['max'])) ? (float)$it['max'] : null;
        $out['breakdown'][] = compact('name','label','value','max');
        $out['raw'][$name]  = $value;
      }
    }
    $out['_arr'] = $arr;
  }
  return $out;
}
function genlit_item_percent($item, $scheme){
  $name = $item['name']; $val = $item['value']; $max = $item['max'];
  if (is_numeric($val)){
    $v = (float)$val;
    $M = $max ?: ($scheme['per_max'][$name] ?? null);
    if ($M && $M>0) return 100.0 * ($v / $M);
    return null;
  }
  $num = edus_level_string_to_num($val);
  if ($num !== null && isset($scheme['categoric'][$name])){
    $min = $scheme['categoric'][$name]['min']; $M = $scheme['categoric'][$name]['max'];
    $span = ($M - $min);
    if ($span > 0) return 100.0 * (($num - $min) / $span);
  }
  return null;
}

/* ---------- Selectare cele mai noi LIT T0/T1 per elev ---------- */
$rowsLIT = array_values(array_filter((array)$rowsRaw, function($r){
  $mt = strtolower(trim($r->modul_type ?? ''));
  $m  = strtolower(trim($r->modul ?? ''));
  return ($mt==='literatie' || $mt==='lit' || strpos($m,'literatie-')===0 || strpos($m,'lit-')===0);
}));

$latest_by_student = ['t0'=>[],'t1'=>[]];
foreach ($rowsLIT as $r){
  $sid = intval($r->student_id ?? 0);
  if (!$sid) continue;
  $m = strtolower(trim($r->modul ?? ''));
  $st = (strpos($m,'-t0')!==false) ? 't0' : ((strpos($m,'-t1')!==false) ? 't1' : null);
  if (!$st) continue;
  $k = row_order_key($r);
  if (!isset($latest_by_student[$st][$sid]) || $k > $latest_by_student[$st][$sid]['key']){
    $latest_by_student[$st][$sid] = ['key'=>$k,'row'=>$r];
  }
}

/* ---------- Asigură-te că avem class_label per elev ---------- */
$student_class_label = [];
if (isset($students) && is_iterable($students)) {
  foreach ($students as $s) {
    $student_class_label[intval($s->id)] = $s->class_label ?? '';
  }
}
$need_ids = [];
foreach (['t0','t1'] as $st){
  foreach ($latest_by_student[$st] as $sid => $wrap) {
    if (!array_key_exists($sid, $student_class_label)) $need_ids[$sid] = true;
  }
}
if (!empty($need_ids)) {
  global $wpdb;
  $tbl_students = $wpdb->prefix.'edu_students';
  $ids = array_map('intval', array_keys($need_ids));
  $in  = implode(',', array_fill(0, count($ids), '%d'));
  $rows = $wpdb->get_results($wpdb->prepare("SELECT id, class_label FROM {$tbl_students} WHERE id IN ($in)", ...$ids));
  foreach ((array)$rows as $row) {
    $student_class_label[intval($row->id)] = $row->class_label ?? '';
  }
}

/* ---------- Agregare pe generație ---------- */
$lit_focus_keys = [
  'lit_q1'=>'Lista de cuvinte',
  'lit_q2'=>'Acuratețe citire',
  'lit_q3'=>'CCPM (0–100)',
  'lit_q4'=>'Comprehensiune citire',
  'lit_q5'=>'Comprehensiune audiere',
  'lit_q6'=>'Scris (nr. cuvinte corecte)',
];
$all_keys = array_merge(array_keys($lit_focus_keys), ['lit_q7','lit_q8','lit_q9','lit_q10','lit_q11','lit_q12']);
$core_keys = ['lit_q1','lit_q2','lit_q3','lit_q4','lit_q5','lit_q6'];

$gen_lit_stage = [
  't0'=>[
    'students'=>0,'completion_avg'=>null,'total_pct_avg'=>null,'remedial_rate'=>null,
    'remedial_points_sum'=>null,'remedial_max_sum'=>null,'total_pct_avg_remedial'=>null,
    'items'=>[],'levels'=>[],'status_counts'=>['draft'=>0,'final'=>0],'items_core_raw'=>[]
  ],
  't1'=>[
    'students'=>0,'completion_avg'=>null,'total_pct_avg'=>null,'remedial_rate'=>null,
    'remedial_points_sum'=>null,'remedial_max_sum'=>null,'total_pct_avg_remedial'=>null,
    'items'=>[],'levels'=>[],'status_counts'=>['draft'=>0,'final'=>0],'items_core_raw'=>[]
  ],
];

foreach (['t0','t1'] as $st) {
  $sum_total_pct = 0.0; $cnt_total = 0;
  $sum_cmpl = 0.0; $cnt_cmpl = 0;
  $rem_yes = 0; $rem_all = 0;
  $rem_points_sum = 0.0; $rem_max_sum = 0.0; $rem_cnt = 0;

  $acc_delta_sum = 0.0; $acc_delta_cnt = 0; $acc_level_sum = 0.0; $acc_level_cnt = 0;
  $comp_delta_sum = 0.0; $comp_delta_cnt = 0; $comp_level_sum = 0.0; $comp_level_cnt = 0;

  $item_sum = []; $item_cnt = []; $item_label = [];
  foreach ($all_keys as $k) { $item_sum[$k]=0.0; $item_cnt[$k]=0; }

  // pregătim colector pentru medii raw pe itemii core (categorici vs numerici)
  $core_raw = [];
  foreach ($core_keys as $ck) {
    $core_raw[$ck] = ['label'=>$lit_focus_keys[$ck] ?? strtoupper($ck), 'is_level'=>in_array($ck, ['lit_q1','lit_q2','lit_q4','lit_q5'], true), 'sum'=>0.0, 'cnt'=>0];
  }

  foreach ($latest_by_student[$st] as $sid => $wrap) {
    $row = $wrap['row'];
    $class_label = $student_class_label[$sid] ?? '';
    $sch_key = genlit_pick_scheme_key($class_label);
    $scheme  = $GEN_LIT_SCHEMES[$sch_key];

    // status counts (draft/final)
    $status = strtolower(trim((string)($row->status ?? '')));
    if ($status === 'final') $gen_lit_stage[$st]['status_counts']['final']++;
    elseif ($status === 'draft') $gen_lit_stage[$st]['status_counts']['draft']++;

    $parsed = genlit_parse_score($row);

    // total % (pe elev)
    $tp = (isset($parsed['total']) && is_numeric($parsed['total'])) ? (float)$parsed['total'] : null;
    if ($tp === null && !empty($parsed['breakdown'])) {
      $sum=0; $had=false;
      foreach($parsed['breakdown'] as $it){ if(is_numeric($it['value'])){ $sum += (float)$it['value']; $had=true; } }
      if ($had) $tp = $sum;
    }
    if ($tp !== null && $scheme['total_max'] > 0) { $sum_total_pct += (100.0 * $tp / $scheme['total_max']); $cnt_total++; }

    // completion
    if (isset($row->completion) && $row->completion !== '') { $sum_cmpl += (float)$row->completion; $cnt_cmpl++; }

    // class value
    $grade_num   = edus_grade_number_from_classlabel($class_label);
    $class_value = max(0, min(8, ($grade_num>=0 ? $grade_num : 0)));

    // remedial?
    $isRem = false;
    $arr_full = $parsed['_arr'] ?? [];
    if (!empty($arr_full['remedial'])) $isRem = true;
    else {
      $acc = strtoupper(trim((string)($parsed['raw']['lit_q2'] ?? '')));
      if ($acc === 'PP' || $acc === 'P') $isRem = true;
    }
    $rem_all++; if ($isRem) $rem_yes++;
    if ($isRem && $tp !== null && $scheme['total_max']>0) {
      $rem_points_sum += $tp;
      $rem_max_sum    += $scheme['total_max'];
      $rem_cnt++;
    }

    // levels (exclude preșcolar) — Δ vs clasă + nivel numeric mediu (pentru colorări & etichete)
    if ($sch_key !== 'prescolar') {
      $acc_raw  = $parsed['raw']['lit_q2'] ?? null;
      $comp_raw = $parsed['raw']['lit_q4'] ?? null;
      $acc_lvl  = edus_level_string_to_num($acc_raw);
      $comp_lvl = edus_level_string_to_num($comp_raw);

      if ($acc_lvl !== null) { $acc_level_sum += $acc_lvl; $acc_level_cnt++; $acc_delta_sum += ($acc_lvl - $class_value); $acc_delta_cnt++; }
      if ($comp_lvl !== null){ $comp_level_sum += $comp_lvl; $comp_level_cnt++; $comp_delta_sum += ($comp_lvl - $class_value); $comp_delta_cnt++; }
    }

    // items avg % (0–100) pentru tabele standard/condiționale
    foreach ($parsed['breakdown'] as $it) {
      $name = $it['name'];
      if (!in_array($name, $all_keys, true)) continue;
      $p = genlit_item_percent($it, $scheme);
      if ($p === null) continue;
      $item_sum[$name] += $p; $item_cnt[$name] += 1;
      if (empty($item_label[$name])) $item_label[$name] = $it['label'] ?: $name;
    }

    // medii RAW pe itemii core (categorici cu PP=-2,P=-1,0..5; numerici simplu)
    foreach ($core_keys as $ck) {
      $val = $parsed['raw'][$ck] ?? null;
      if ($core_raw[$ck]['is_level']) {
        $num = genlit_level_to_num_pp2($val);
        if ($num !== null) { $core_raw[$ck]['sum'] += $num; $core_raw[$ck]['cnt']++; }
      } else {
        if (is_numeric($val)) { $core_raw[$ck]['sum'] += (float)$val; $core_raw[$ck]['cnt']++; }
      }
    }
  }

  $gen_lit_stage[$st]['students']       = count($latest_by_student[$st]);
  $gen_lit_stage[$st]['completion_avg'] = $cnt_cmpl ? ($sum_cmpl / $cnt_cmpl) : null;
  $gen_lit_stage[$st]['total_pct_avg']  = $cnt_total ? ($sum_total_pct / $cnt_total) : null;
  $gen_lit_stage[$st]['remedial_rate']  = $rem_all ? (100.0 * $rem_yes / $rem_all) : null;
  $gen_lit_stage[$st]['remedial_count'] = $rem_yes;

  // remedial agregat: puncte & % ponderat (sum(points)/sum(max))
  $gen_lit_stage[$st]['remedial_points_sum'] = $rem_cnt ? $rem_points_sum : null;
  $gen_lit_stage[$st]['remedial_max_sum']    = $rem_cnt ? $rem_max_sum    : null;
  $gen_lit_stage[$st]['total_pct_avg_remedial'] = ($rem_cnt && $rem_max_sum>0) ? (100.0 * $rem_points_sum / $rem_max_sum) : null;

  // averages for levels (Δ vs clasă + nivel numeric)
  $gen_lit_stage[$st]['levels'] = [
    'acc' => [
      'delta_avg' => ($acc_delta_cnt ? ($acc_delta_sum / $acc_delta_cnt) : null),
      'avg_num'   => ($acc_level_cnt ? ($acc_level_sum / $acc_level_cnt) : null),
    ],
    'comp' => [
      'delta_avg' => ($comp_delta_cnt ? ($comp_delta_sum / $comp_delta_cnt) : null),
      'avg_num'   => ($comp_level_cnt ? ($comp_level_sum / $comp_level_cnt) : null),
    ],
  ];

  // finalize items % pentru tabele standard/condiționale
  $items = [];
  foreach ($all_keys as $k) {
    $label = $item_label[$k] ?? ($lit_focus_keys[$k] ?? strtoupper($k));
    $avg   = $item_cnt[$k] ? ($item_sum[$k] / $item_cnt[$k]) : null;
    $items[$k] = ['label'=>$label, 'avg_pct'=>$avg, 'n'=>$item_cnt[$k]];
  }
  $gen_lit_stage[$st]['items'] = $items;

  // finalize medii RAW pe itemii core
  $core_out = [];
  foreach ($core_keys as $ck) {
    $c = $core_raw[$ck];
    if ($c['is_level']) {
      $avg_num = $c['cnt'] ? ($c['sum'] / $c['cnt']) : null;
      $core_out[$ck] = [
        'label'     => $c['label'],
        'is_level'  => true,
        'avg_num'   => $avg_num,
        'avg_label' => ($avg_num===null ? null : genlit_num_to_level_label_pp2($avg_num)),
        'avg_raw'   => null,
      ];
    } else {
      $avg_raw = $c['cnt'] ? ($c['sum'] / $c['cnt']) : null;
      $core_out[$ck] = [
        'label'     => $c['label'],
        'is_level'  => false,
        'avg_num'   => null,
        'avg_label' => null,
        'avg_raw'   => $avg_raw,
      ];
    }
  }
  $gen_lit_stage[$st]['items_core_raw'] = $core_out;
}

/* ---------- Derivate pentru UI ---------- */
$gen_lit_total_pct = [
  't0' => $gen_lit_stage['t0']['total_pct_avg'],
  't1' => $gen_lit_stage['t1']['total_pct_avg'],
];
$gen_lit_delta_total = ( $gen_lit_total_pct['t0']!==null && $gen_lit_total_pct['t1']!==null )
  ? ($gen_lit_total_pct['t1'] - $gen_lit_total_pct['t0'])
  : null;

$gen_lit_total_pct_remedial = [
  't0' => $gen_lit_stage['t0']['total_pct_avg_remedial'],
  't1' => $gen_lit_stage['t1']['total_pct_avg_remedial'],
];
$gen_lit_overall_pct_remedial = avg_non_null([$gen_lit_total_pct_remedial['t0'],$gen_lit_total_pct_remedial['t1']]);
$gen_lit_delta_total_remedial = ( $gen_lit_total_pct_remedial['t0']!==null && $gen_lit_total_pct_remedial['t1']!==null )
  ? ($gen_lit_total_pct_remedial['t1'] - $gen_lit_total_pct_remedial['t0'])
  : null;

$gen_lit_levels = [
  'acc' => [
    't0_num_avg' => $gen_lit_stage['t0']['levels']['acc']['avg_num'],
    't1_num_avg' => $gen_lit_stage['t1']['levels']['acc']['avg_num'],
    'avg_num'    => avg_non_null([$gen_lit_stage['t0']['levels']['acc']['avg_num'], $gen_lit_stage['t1']['levels']['acc']['avg_num']]),
  ],
  'comp' => [
    't0_num_avg' => $gen_lit_stage['t0']['levels']['comp']['avg_num'],
    't1_num_avg' => $gen_lit_stage['t1']['levels']['comp']['avg_num'],
    'avg_num'    => avg_non_null([$gen_lit_stage['t0']['levels']['comp']['avg_num'], $gen_lit_stage['t1']['levels']['comp']['avg_num']]),
  ],
];
$gen_lit_levels_delta_avg = [
  'acc'  => avg_non_null([ $gen_lit_stage['t0']['levels']['acc']['delta_avg'],  $gen_lit_stage['t1']['levels']['acc']['delta_avg'] ]),
  'comp' => avg_non_null([ $gen_lit_stage['t0']['levels']['comp']['delta_avg'], $gen_lit_stage['t1']['levels']['comp']['delta_avg'] ]),
];
$gen_lit_levels_delta_avg_color = [
  'acc'  => ($gen_lit_levels_delta_avg['acc']  === null ? null : ($gen_lit_levels_delta_avg['acc']  < 0 ? 'red' : 'green')),
  'comp' => ($gen_lit_levels_delta_avg['comp'] === null ? null : ($gen_lit_levels_delta_avg['comp'] < 0 ? 'red' : 'green')),
];

$gen_lit_items_order = [
  'lit_q1','lit_q2','lit_q3','lit_q4','lit_q5','lit_q6',
  'lit_q7','lit_q8','lit_q9','lit_q10','lit_q11','lit_q12'
];

/* medie între etape pentru completare (pt. cardul 3) */
$gen_lit_completion_avg_overall = avg_non_null([$gen_lit_stage['t0']['completion_avg'],$gen_lit_stage['t1']['completion_avg']]);
$gen_lit_completion_avg_overall_decimal = $gen_lit_completion_avg_overall;
