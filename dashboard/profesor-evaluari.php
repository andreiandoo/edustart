<?php
/** Profesor – Lista Evaluări pe Generații (SEL/LIT grupat + Δ comp/acc LIT + coloane dinamice) */
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) { wp_redirect(home_url('/login')); exit; }

$current_user = wp_get_current_user();
$user_id      = (int) $current_user->ID;

if (!in_array('profesor', (array)$current_user->roles, true)) {
  echo '<div class="p-4 text-red-600">Acces restricționat. Această pagină este doar pentru profesori.</div>';
  exit;
}

global $wpdb;

/** Tabele */
$tbl_results     = $wpdb->prefix . 'edu_results';
$tbl_students    = $wpdb->prefix . 'edu_students';
$tbl_generations = $wpdb->prefix . 'edu_generations';

/* ---------- Helpers common ---------- */
if (!function_exists('str_starts_with')) {
  function str_starts_with($h, $n){ return $n===''?true:strpos($h,$n)===0; }
}
if (!function_exists('is_serialized')) {
  function is_serialized($d){ if(!is_string($d))return false; $d=trim($d); if($d==='N;')return true; if(!preg_match('/^[adObis]:/',$d))return false; return @unserialize($d)!==false||$d==='b:0;'; }
}
if (!function_exists('avg_non_null')) {
  function avg_non_null($arr){ $s=0;$n=0; foreach($arr as $v){ if($v!==null && $v!==''){ $s+=floatval($v); $n++; } } return $n?($s/$n):null; }
}
if (!function_exists('row_order_key')) {
  function row_order_key($r){
    if(!empty($r->created_at)) return 'c:'.strtotime($r->created_at);
    return 'i:'.intval($r->res_id ?? 0);
  }
}

/* ---------- UI helpers ---------- */
function edu_modul_label($t){
  $map=['sel'=>'SEL','literatie'=>'Literație','lit'=>'Literație'];
  return $map[strtolower((string)$t)] ?? strtoupper((string)$t);
}
function edu_safe_name($f,$l){ $f=trim((string)$f); $l=trim((string)$l); return ($f===''&&$l==='')?'—':esc_html($f.' '.$l); }
function edu_badge_status_classes($s){
  return $s==='final' ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200'
       : ($s==='draft' ? 'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200'
                       : 'bg-slate-50 text-slate-700 ring-1 ring-inset ring-slate-200');
}
function edu_progress_bar_colored($p){
  $p=max(0,min(100,(int)round($p)));
  $cls = $p>75 ? 'bg-emerald-600' : ($p>50 ? 'bg-orange-500' : ($p>25 ? 'bg-amber-500' : 'bg-rose-500'));
  return '<div class="flex items-center w-full overflow-hidden rounded-full gap-x-2"><div class="h-2 rounded-full '.$cls.'" style="width:'.$p.'%"></div><div class="text-[12px] text-slate-700 font-semibold">'.$p.'%</div></div>';
}
function edu_pick_status($arr){ return in_array('final',$arr,true)?'final':(in_array('draft',$arr,true)?'temp':'—'); }

/* ---------- BADGE-uri pentru scoruri (după codul tău) ---------- */
if (!function_exists('badge_score')) {
  function badge_score($v,$size='sm'){
    if($v===null||$v==='') return '<span class="inline-flex items-center text-gray-700 bg-gray-200 rounded '.($size==='xl'?'px-5 py-1.5 text-2xl font-extrabold leading-8':($size==='md'?'px-2.5 py-1 text-base':'px-2 py-0.5 text-sm')).'">—</span>';
    $v=floatval($v);
    $cls='bg-emerald-600 text-white';
    if($v<3)$cls='bg-lime-600 text-white';
    if($v<2.75)$cls='bg-lime-500 text-gray-900';
    if($v<2.5)$cls='bg-yellow-300 text-gray-900';
    if($v<2)$cls='bg-orange-400 text-white';
    if($v<1.5)$cls='bg-red-600 text-white';
    $pad = $size==='xl' ? 'px-5 py-1.5 text-2xl font-extrabold leading-8' : ($size==='md'?'px-2.5 py-1 text-base':'px-2 py-0.5 text-sm');
    return '<span class="inline-flex items-center rounded '.$cls.' '.$pad.'">'.number_format($v,2).'</span>';
  }
}
if (!function_exists('pct_badge')) {
  function pct_badge($p,$size='sm'){
    if($p===null) return '<span class="inline-flex items-center px-2 py-0.5 text-sm rounded bg-gray-200 text-gray-700">—</span>';
    $p = max(0,min(100, floatval($p)));
    $cls = 'bg-emerald-600 text-white';
    if($p<75) $cls='bg-lime-600 text-white';
    if($p<50) $cls='bg-yellow-300 text-slate-900';
    if($p<25) $cls='bg-orange-400 text-white';
    if($p<10) $cls='bg-red-600 text-white';
    $pad = $size==='xl' ? 'px-5 py-1.5 text-2xl font-extrabold leading-8' : ($size==='md'?'px-2.5 py-1 text-base':'px-2 py-0.5 text-sm');
    return '<span class="inline-flex items-center rounded '.$cls.' '.$pad.'">'.number_format($p,0).'%</span>';
  }
}
/* Delta badge pentru LIT (Δ față de valoarea clasei) */
if (!function_exists('delta_badge')) {
  function delta_badge($d,$size='sm'){
    if($d===null || $d==='') return '<span class="inline-flex items-center px-2 py-0.5 text-sm rounded bg-gray-200 text-gray-700">—</span>';
    $d = floatval($d);
    $cls = $d>=0 ? 'bg-emerald-700 text-white'
                 : 'bg-rose-700 text-white';
    $pad = $size==='md' ? 'px-2.5 py-1 text-base' : 'px-2 py-0.5 text-sm';
    $sign = $d>0 ? '+' : '';
    return '<span class="inline-flex items-center font-semibold rounded '.$cls.' '.$pad.'">'.$sign.number_format($d,0).'</span>';
  }
}

/* ---------- SEL helpers ---------- */
$SEL_CHAPTERS = ['Conștientizare de sine','Autoreglare','Conștientizare socială','Relaționare','Luarea deciziilor'];
function sel_stage_from_modul($m){ $m=strtolower(trim($m??'')); if(strpos($m,'-t0')!==false)return't0'; if(strpos($m,'-ti')!==false)return'ti'; if(strpos($m,'-t1')!==false)return't1'; return null; }
function parse_sel_score_map_any($raw){
  if (is_array($raw)) return $raw;
  if (is_serialized($raw)) { $a=@unserialize($raw); if(is_array($a))return $a; }
  return [];
}

/* ---------- LIT helpers ---------- */
function edus_grade_number_from_classlabel($name){
  $s=mb_strtolower(trim($name??'')); $s=str_replace(['â','ă','î','ș','ţ','ț'],['a','a','i','s','t','t'],$s);
  if(strpos($s,'prescolar')!==false || strpos($s,'preșcolar')!==false || strpos($s,'gradinita')!==false) return -1;
  if(strpos($s,'pregatitoare')!==false || strpos($s,'preparator')!==false) return 0;
  if(preg_match('/\b([0-9])\b/',$s,$m)){ $n=intval($m[1]); if($n>=1 && $n<=8) return $n; }
  return 0;
}
function edus_level_string_to_num($v){
  $v=trim((string)$v); if($v==='') return null; $u=strtoupper($v);
  if($u==='PP') return -1; if($u==='P') return 0; if(is_numeric($v)) return intval($v); return null;
}
$LIT_SCHEMES = [
  'prescolar' => [ 'total_max'=>51, 'per_max'=>['lit_q1'=>22,'lit_q2'=>20,'lit_q3'=>3,'lit_q4'=>5,'lit_q5'=>1], 'categoric'=>[] ],
  'pregatitoare' => [ 'total_max'=>150, 'per_max'=>['lit_q7'=>6,'lit_q8'=>93,'lit_q9'=>12,'lit_q10'=>22,'lit_q11'=>12,'lit_q12'=>5], 'categoric'=>[] ],
  'primar_gimnaziu' => [
    'total_max'=>150,
    'per_max'=>['lit_q3'=>100,'lit_q6'=>5,'lit_q7'=>6,'lit_q8'=>93,'lit_q9'=>12,'lit_q10'=>22,'lit_q11'=>12,'lit_q12'=>5],
    'categoric'=>['lit_q1'=>['min'=>-1,'max'=>4],'lit_q2'=>['min'=>-1,'max'=>4],'lit_q4'=>['min'=>-1,'max'=>4],'lit_q5'=>['min'=>-1,'max'=>4]],
  ],
];
function lit_pick_scheme_key($class_label){ $g=edus_grade_number_from_classlabel($class_label); if($g===-1)return'prescolar'; if($g===0)return'pregatitoare'; return 'primar_gimnaziu'; }
function lit_parse_score($row){
  $out=['total'=>null,'breakdown'=>[]];
  $arr = @maybe_unserialize($row->score ?? null);
  if (is_array($arr)){
    if (isset($arr['total'])) $out['total'] = is_numeric($arr['total']) ? floatval($arr['total']) : null;
    if (!empty($arr['breakdown']) && is_array($arr['breakdown'])){
      foreach($arr['breakdown'] as $it){
        $out['breakdown'][] = [
          'name'=>$it['name']??null,
          'label'=>$it['label']??($it['name']??''),
          'value'=>$it['value']??null,
          'max'=>(isset($it['max'])&&is_numeric($it['max']))?floatval($it['max']):null,
        ];
      }
    }
  }
  return $out;
}

/* ---------- Filtre (liste) – pe elevii profesorului ---------- */
$generations = $wpdb->get_results($wpdb->prepare("
  SELECT g.id, g.name, g.year
  FROM {$tbl_generations} g
  WHERE g.professor_id=%d
  ORDER BY COALESCE(g.year,0) DESC, g.name ASC
", $user_id));

$module_types = $wpdb->get_col($wpdb->prepare("
  SELECT DISTINCT r.modul_type
  FROM {$tbl_results} r
  JOIN {$tbl_students} s ON s.id=r.student_id
  WHERE s.professor_id=%d
  ORDER BY r.modul_type ASC
", $user_id));

/* ---------- Query brut (TOT) – pe elevii profesorului ---------- */
$rowsRaw = $wpdb->get_results($wpdb->prepare("
  SELECT
    r.id as res_id, r.student_id, r.modul_type, r.modul_id, r.modul, r.status, r.results, r.score, r.completion, r.created_at,
    s.first_name, s.last_name, s.class_label, s.generation_id, s.professor_id,
    g.name AS generation_name, g.year AS generation_year
  FROM {$tbl_results} r
  JOIN {$tbl_students} s ON s.id = r.student_id
  LEFT JOIN {$tbl_generations} g ON g.id = s.generation_id
  WHERE s.professor_id = %d
  ORDER BY g.year DESC, g.name ASC, s.last_name ASC, s.first_name ASC, r.modul_type ASC, r.created_at DESC
", $user_id));

/* ---------- KPI ---------- */
$total_evals = 0; $final_evals = 0; $draft_evals = 0; $sum_completion = 0.0;
foreach ($rowsRaw as $r){ $total_evals++; if($r->status==='final') $final_evals++; if($r->status==='draft') $draft_evals++; $sum_completion += (float)($r->completion ?? 0); }
$avg_completion_all = $total_evals ? round($sum_completion / $total_evals, 1) : 0.0;

/* ---------- Agregare (student | modul_type | generation) ---------- */
$agg = [];
foreach ($rowsRaw as $r) {
  $mt = strtolower(trim($r->modul_type ?? ''));
  if ($mt!=='sel' && $mt!=='lit' && $mt!=='literatie') continue;

  $key = $r->student_id.'|'.($mt==='literatie'?'lit':$mt).'|'.(string)$r->generation_id;
  if (!isset($agg[$key])) {
    $agg[$key] = (object)[
      'student_id'     => (int)$r->student_id,
      'first_name'     => $r->first_name,
      'last_name'      => $r->last_name,
      'class_label'    => $r->class_label,
      'generation_id'  => $r->generation_id,
      'generation_name'=> $r->generation_name,
      'modul_type'     => ($mt==='literatie'?'lit':$mt),

      // latest rows pe etape
      'sel_latest'     => ['t0'=>null,'ti'=>null,'t1'=>null],
      'lit_latest'     => ['t0'=>null,'t1'=>null],

      // scoruri SEL
      'score_t0'       => null,
      'score_ti'       => null,
      'score_t1'       => null,

      // LIT: remedial + Δ comp/acc pe etape
      'lit_remedial'   => false,
      'lit_comp_d_t0'  => null,
      'lit_acc_d_t0'   => null,
      'lit_comp_d_t1'  => null,
      'lit_acc_d_t1'   => null,

      'completion_max' => 0.0,
      'statuses'       => [],
    ];
  }

  // statusuri + completion
  $s = $r->status ?: 'draft';
  if (!in_array($s, $agg[$key]->statuses, true)) $agg[$key]->statuses[] = $s;
  $agg[$key]->completion_max = max($agg[$key]->completion_max, (float)($r->completion ?? 0));

  // împărțire pe etape
  $mod = strtolower(trim($r->modul ?? ''));
  if ($agg[$key]->modul_type === 'sel' || str_starts_with($mod,'sel-')) {
    $st = sel_stage_from_modul($mod);
    if ($st) {
      $k = row_order_key($r);
      $cur = $agg[$key]->sel_latest[$st];
      if ($cur === null || $k > row_order_key($cur)) $agg[$key]->sel_latest[$st] = $r;
    }
  } elseif ($agg[$key]->modul_type === 'lit' || str_starts_with($mod,'literatie-') || str_starts_with($mod,'lit-')) {
    $st = (strpos($mod,'-t0')!==false) ? 't0' : ((strpos($mod,'-t1')!==false) ? 't1' : null);
    if ($st) {
      $k = row_order_key($r);
      $cur = $agg[$key]->lit_latest[$st];
      if ($cur === null || $k > row_order_key($cur)) $agg[$key]->lit_latest[$st] = $r;
    }
  }
}

/* ---------- Calcule scoruri ---------- */
foreach ($agg as $key => $row) {
  if ($row->modul_type === 'sel') {
    // medii pe capitole din SCORE pentru T0/Ti/T1
    foreach (['t0','ti','t1'] as $st) {
      $latest = $row->sel_latest[$st];
      if ($latest && !empty($latest->score)) {
        $map = parse_sel_score_map_any(@maybe_unserialize($latest->score));
        $vals=[]; foreach($SEL_CHAPTERS as $c){ if(isset($map[$c]) && is_numeric($map[$c])) $vals[]=(float)$map[$c]; }
        $avg = $vals ? array_sum($vals)/count($vals) : null;
        if     ($st==='t0') $row->score_t0 = $avg;
        elseif ($st==='ti') $row->score_ti = $avg;
        elseif ($st==='t1') $row->score_t1 = $avg;
      }
    }
  } elseif ($row->modul_type === 'lit') {
    // Δ față de valoarea clasei pentru lit_q4 (comprehensiune) și lit_q2 (acuratețe), T0/T1
    $scheme_key = lit_pick_scheme_key($row->class_label ?? '');
    $grade_num  = edus_grade_number_from_classlabel($row->class_label ?? '');
    $class_value = max(0, min(8, ($grade_num>=0 ? $grade_num : 0)));
    $apply_diff = ($scheme_key !== 'prescolar');

    foreach (['t0','t1'] as $st) {
      $latest = $row->lit_latest[$st];
      if (!$latest) continue;

      $parsed = lit_parse_score($latest);

      // remedial (din DB sau fallback pe lit_q2)
      if (!$row->lit_remedial) {
        $raw = @maybe_unserialize($latest->score);
        if (is_array($raw) && !empty($raw['remedial'])) {
          $row->lit_remedial = true;
        } else {
          foreach ($parsed['breakdown'] as $it) {
            if (($it['name']??'')==='lit_q2') {
              $acc = strtoupper(trim((string)($it['value'] ?? '')));
              if ($acc==='PP' || $acc==='P') $row->lit_remedial = true;
            }
          }
        }
      }

      if ($apply_diff) {
        $comp_raw = null; $acc_raw = null;
        foreach ($parsed['breakdown'] as $it) {
          $n = $it['name'] ?? '';
          if ($n==='lit_q4') $comp_raw = $it['value'] ?? null; // Comprehensiune citire
          if ($n==='lit_q2') $acc_raw  = $it['value'] ?? null; // Acuratețe citire
        }
        $comp_lvl = edus_level_string_to_num($comp_raw);
        $acc_lvl  = edus_level_string_to_num($acc_raw);

        $comp_d = ($comp_lvl!==null) ? ($comp_lvl - $class_value) : null;
        $acc_d  = ($acc_lvl !==null) ? ($acc_lvl  - $class_value) : null;

        if ($st==='t0'){ $row->lit_comp_d_t0=$comp_d; $row->lit_acc_d_t0=$acc_d; }
        if ($st==='t1'){ $row->lit_comp_d_t1=$comp_d; $row->lit_acc_d_t1=$acc_d; }
      }
    }
  }
}

/* ---------- Sortare ---------- */
$rows = array_values($agg);
usort($rows, function($a,$b){
  $ga=strtolower((string)$a->generation_name); $gb=strtolower((string)$b->generation_name);
  if ($ga!==$gb) return $ga<=>$gb;
  $na=strtolower(trim(($a->last_name??'').' '.($a->first_name??'')));
  $nb=strtolower(trim(($b->last_name??'').' '.($b->first_name??'')));
  if ($na!==$nb) return $na<=>$nb;
  return strtolower($a->modul_type) <=> strtolower($b->modul_type);
});
?>
<section x-data="evaluariPage()" class="">
  <!-- Header premium + filtre -->
  <div class="">
    <div class="px-6 mt-4 mb-6 ">
      <div class="flex flex-wrap items-start justify-between gap-6">
        <div class="text-sky-800">
          <h1 class="text-2xl font-semibold md:text-3xl">Evaluările mele</h1>
          <p class="mt-1 text-sm text-slate-800">Toate evaluările pe generații/clase și module (T0 / Ti / T1), cu filtrare.</p>
        </div>
        <div class="flex items-center gap-2">
          <div class="">
            <select x-model="genFilter" class="px-3 py-2 text-sm bg-white rounded-lg text-slate-800 focus:outline-none">
              <option value="">Toate generațiile/clasele</option>
              <?php foreach($generations as $g){ echo '<option value="'.esc_attr($g->id).'">'.esc_html($g->name).'</option>'; } ?>
            </select>
          </div>
          <div class="">
            <select x-model="modFilter" class="px-3 py-2 text-sm bg-white rounded-lg text-slate-800 focus:outline-none">
              <option value="">Toate modulele</option>
              <?php foreach($module_types as $mt){ $norm=$mt==='literatie'?'lit':$mt; echo '<option value="'.esc_attr($norm).'">'.esc_html(edu_modul_label($norm)).'</option>'; } ?>
            </select>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-3 mt-5 sm:grid-cols-4">
        <div class="p-4 bg-white rounded-2xl text-slate-900">
          <div class="text-xs text-slate-500">Total evaluări</div>
          <div class="mt-1 text-2xl font-semibold"><?php echo esc_html($total_evals); ?></div>
        </div>
        <div class="p-4 bg-white rounded-2xl text-slate-900">
          <div class="text-xs text-slate-500">Finalizate</div>
          <div class="mt-1 text-2xl font-semibold"><?php echo esc_html($final_evals); ?></div>
        </div>
        <div class="p-4 bg-white rounded-2xl text-slate-900">
          <div class="text-xs text-slate-500">Temporare</div>
          <div class="mt-1 text-2xl font-semibold"><?php echo esc_html($draft_evals); ?></div>
        </div>
        <div class="p-4 bg-white rounded-2xl text-slate-900">
          <div class="text-xs text-slate-500">Medie completare</div>
          <div class="mt-1 text-2xl font-semibold"><?php echo esc_html($avg_completion_all); ?>%</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabel -->
  <div class="mx-6 overflow-hidden bg-white border rounded-2xl">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <!-- Row 1: grupări -->
          <tr class="text-white bg-sky-800">
            <th class="px-4 py-3 font-medium text-left align-bottom" rowspan="2">#</th>
            <th class="px-4 py-3 font-medium text-left align-bottom" rowspan="2">Nume Elev</th>
            <th class="px-4 py-3 font-medium text-left align-bottom" rowspan="2">Clasa</th>
            <th class="px-4 py-3 font-medium text-left align-bottom" rowspan="2">Modul</th>
            <th class="px-4 py-3 font-medium text-left align-bottom" rowspan="2">Etape</th>
            <th class="px-4 py-3 font-medium text-left align-bottom" rowspan="2">Stare</th>

            <!-- SEL group -->
            <th class="px-4 py-2 font-semibold text-center border-l-2 border-slate-200" x-show="showSELCols()" colspan="3">Evaluare SEL</th>

            <!-- LIT group -->
            <th class="px-4 py-2 font-semibold text-center border-l-2 border-slate-200" x-show="showLITCols()" colspan="4">Evaluare LIT</th>

            <th class="px-4 py-3 font-medium text-center align-bottom border-l-2 border-slate-200" rowspan="2">Grad Completare</th>
          </tr>
          <!-- Row 2: capete detaliate -->
          <tr class="text-white bg-sky-800">
            <!-- SEL sub-capete -->
            <th class="px-4 py-2 text-center border-l-2 border-slate-200" x-show="showSELCols()">Scor T0</th>
            <th class="px-4 py-2 text-center" x-show="showSELCols()">Scor Ti</th>
            <th class="px-4 py-2 text-center" x-show="showSELCols()">Scor T1</th>

            <!-- LIT sub-capete -->
            <th class="px-4 py-2 text-center border-l-2 border-slate-200" x-show="showLITCols()">Comp T0</th>
            <th class="px-4 py-2 text-center" x-show="showLITCols()">Acc T0</th>
            <th class="px-4 py-2 text-center" x-show="showLITCols()">Comp T1</th>
            <th class="px-4 py-2 text-center" x-show="showLITCols()">Acc T1</th>
          </tr>
        </thead>

        <tbody class="divide-y">
          <?php if (empty($rows)): ?>
            <tr><td colspan="20" class="px-4 py-10 text-center text-slate-500">Nu există încă evaluări pentru elevii tăi.</td></tr>
          <?php else:
            $i=0; foreach($rows as $r): $i++;
              $student_name = edu_safe_name($r->first_name,$r->last_name);
              $class_label  = esc_html($r->class_label ?? '—');
              $gen_id       = (string)($r->generation_id ?? '');
              $modul_type   = (string)$r->modul_type; // 'sel' / 'lit'
              $modul_label  = edu_modul_label($modul_type);
              $status       = edu_pick_status($r->statuses);
              $badge_cls    = edu_badge_status_classes($status);

              // SEL badges
              $sel_t0_badge = badge_score($r->score_t0);
              $sel_ti_badge = badge_score($r->score_ti);
              $sel_t1_badge = badge_score($r->score_t1);

              // LIT delta badges
              $lit_comp_t0 = delta_badge($r->lit_comp_d_t0);
              $lit_acc_t0  = delta_badge($r->lit_acc_d_t0);
              $lit_comp_t1 = delta_badge($r->lit_comp_d_t1);
              $lit_acc_t1  = delta_badge($r->lit_acc_d_t1);

              // Grad completare = media între etapele așteptate (T0/Ti/T1 pt SEL, T0/T1 pt LIT).
              // Etapele lipsă contează ca 0%, ca să nu mai apară 100% când o singură etapă e completă.
              if ($modul_type === 'sel') {
                $stages_expected = ['t0','ti','t1'];
                $stage_rows = $r->sel_latest ?? [];
              } else {
                $stages_expected = ['t0','t1'];
                $stage_rows = $r->lit_latest ?? [];
              }
              $sum_comp = 0.0;
              foreach ($stages_expected as $st) {
                $row_st = $stage_rows[$st] ?? null;
                $sum_comp += $row_st ? (float)($row_st->completion ?? 0) : 0.0;
              }
              $completion = $sum_comp / max(1, count($stages_expected));
              $report_url = home_url('/panou/raport/elev/' . (int)$r->student_id);
              $rowTintCls = ($modul_type==='lit' && $r->lit_remedial) ? '' : '';
            ?>
            <tr
              x-show="rowVisible('<?php echo esc_js($gen_id); ?>','<?php echo esc_js(strtolower($modul_type)); ?>')"
              x-cloak
              class="<?php echo esc_attr($rowTintCls); ?>">
              <td class="px-4 py-3 text-slate-500"><?php echo esc_html($i); ?></td>
              <td class="px-4 py-3">
                <div class="flex items-center gap-x-2">
                  <a href="<?php echo esc_url($report_url); ?>" class="font-medium text-slate-900 hover:underline"><?php echo $student_name; ?></a>
                  <?php if ($modul_type==='lit' && $r->lit_remedial): ?>
                    <span class="mt-1 inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold bg-rose-700 text-white">R</span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="px-4 py-3 text-slate-700"><?php echo $class_label; ?></td>
              <td class="px-4 py-3"><span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-lg bg-slate-100 text-slate-700"><?php echo esc_html($modul_label); ?></span></td>
              <td class="px-4 py-3 text-slate-500">T0 / <?php echo ($modul_type==='lit')?'—':'Ti'; ?> / T1</td>
              <td class="px-4 py-3"><span class="inline-flex items-center px-2 py-1 rounded-xl text-xs font-semibold <?php echo esc_attr($badge_cls); ?>"><?php echo esc_html($status); ?></span></td>

              <!-- SEL group -->
              <td class="px-4 py-3 border-l-2 border-slate-200" x-show="showSELCols()"><?php echo $sel_t0_badge; ?></td>
              <td class="px-4 py-3 text-center" x-show="showSELCols()"><?php echo $sel_ti_badge; ?></td>
              <td class="px-4 py-3 text-center" x-show="showSELCols()"><?php echo $sel_t1_badge; ?></td>

              <!-- LIT group -->
              <td class="px-4 py-3 text-center border-l-2 border-slate-200" x-show="showLITCols()"><?php echo $lit_comp_t0; ?></td>
              <td class="px-4 py-3 text-center" x-show="showLITCols()"><?php echo $lit_acc_t0; ?></td>
              <td class="px-4 py-3 text-center" x-show="showLITCols()"><?php echo $lit_comp_t1; ?></td>
              <td class="px-4 py-3 text-center" x-show="showLITCols()"><?php echo $lit_acc_t1; ?></td>

              <td class="px-4 py-3 border-l-2 border-slate-200"><?php echo edu_progress_bar_colored($completion); ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<script>
function evaluariPage(){
  return {
    genFilter: '',
    modFilter: '',
    showSELCols(){ return !this.modFilter || this.modFilter.toLowerCase()==='sel'; },
    showLITCols(){ return !this.modFilter || this.modFilter.toLowerCase()==='lit'; },
    rowVisible(genId, modul){
      const g=(this.genFilter||'').toString();
      const m=(this.modFilter||'').toString().toLowerCase();
      const genHit=!g || genId.toString()===g;
      const modulHit=!m || modul===m;
      return genHit && modulHit;
    }
  }
}
</script>
