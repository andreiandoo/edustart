<?php
/** Raport generație — LIT (UI)
 *  Necesită din helper:
 *   - $gen_lit_stage, $gen_lit_total_pct, $gen_lit_delta_total, $gen_lit_items_order
 *   - $gen_lit_total_pct_remedial, $gen_lit_overall_pct_remedial, $gen_lit_delta_total_remedial
 *   - $gen_lit_levels, $gen_lit_levels_delta_avg, $gen_lit_levels_delta_avg_color
 *   - $gen_lit_completion_avg_overall
 *
 *  Notă: Secțiunea de elevi își calculează singură datele din $rowsRaw și edu_students. 
 */

if (!defined('ABSPATH')) exit;

/* ================= UI helpers ================ */
if (!function_exists('gen_pct_badge')) {
  function gen_pct_badge($pct, $size='sm'){
    if ($pct===null) return '<span class="inline-flex items-center px-2 py-0.5 text-xs rounded bg-slate-100 text-slate-500">—</span>';
    $v = max(0, min(100, floatval($pct)));
    $cls = 'bg-slate-200 text-slate-800';
    if ($v >= 80) $cls = 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200';
    elseif ($v >= 60) $cls = 'bg-lime-100 text-lime-800 ring-1 ring-lime-200';
    elseif ($v >= 40) $cls = 'bg-yellow-100 text-yellow-800 ring-1 ring-yellow-200';
    else $cls = 'bg-rose-100 text-rose-800 ring-1 ring-rose-200';
    $pad = $size==='xl' ? 'px-3 py-1.5 text-base font-bold' : ($size==='sm' ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-1 text-sm');
    return '<span class="inline-flex items-center '.$pad.' rounded '.$cls.'">'.number_format($v,2).'%</span>';
  }
}
if (!function_exists('gen_delta_chip')) {
  function gen_delta_chip($d, $size='sm'){
    if ($d===null) return '<span class="inline-flex items-center px-2 py-0.5 text-xs rounded bg-slate-100 text-slate-500">—</span>';
    $cls = ($d>0) ? 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200'
         : (($d<0) ? 'bg-rose-100 text-rose-800 ring-1 ring-rose-200' : 'bg-slate-100 text-slate-700 ring-1 ring-slate-200');
    $lab = ($d>0?'+':'').(is_float($d) ? number_format($d,2) : (string)$d);
    $pad = $size==='lg' ? 'px-2.5 py-1 text-sm' : 'px-2 py-0.5 text-xs';
    return '<span class="inline-flex items-center '.$pad.' rounded '.$cls.'">'.$lab.'</span>';
  }
}
if (!function_exists('gen_lit_color_badge')) {
  function gen_lit_color_badge($val, $colorKey=null){
    if ($val===null) return '—';
    $cls = 'bg-slate-100 text-slate-700 ring-1 ring-slate-200';
    if     ($colorKey==='green')       $cls = 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200';
    elseif ($colorKey==='red')         $cls = 'bg-rose-100 text-rose-800 ring-1 ring-rose-200';
    elseif ($colorKey==='red-strong')  $cls = 'bg-rose-600 text-white';
    $s  = ($val>0?'+':'').number_format($val,2);
    return '<span class="inline-flex items-center px-2 py-0.5 text-base font-semibold rounded '.$cls.'">'.$s.'</span>';
  }
}
$P = function($pct,$size='sm'){ return gen_pct_badge($pct,$size); };

/* afisare nivel mediu (−1..4) ca etichetă PP/P/1..4 (pt carduri) */
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
/* etichetare pentru scara PP=-2, P=-1, 0..5 (pt medii pe itemi RAW) */
if (!function_exists('gen_lit_level_num_to_label_pp2')) {
  function gen_lit_level_num_to_label_pp2($num){
    if ($num===null) return '—';
    $r = round($num);
    if ($r <= -2) return 'PP';
    if ($r === -1) return 'P';
    return (string)$r;
  }
}

/* ============== calc dif T1−T0 pentru cardul 3 (NU medii) ============== */
$acc0 = $gen_lit_stage['t0']['levels']['acc']['delta_avg'] ?? null;
$accColor0 = ($acc0===null? null : ($acc0<0?'red':'green'));
$comp0 = $gen_lit_stage['t0']['levels']['comp']['delta_avg'] ?? null;
$compColor0 = ($comp0===null? null : ($comp0<0?'red':'green'));

$acc1 = $gen_lit_stage['t1']['levels']['acc']['delta_avg'] ?? null;
$accColor1 = ($acc1===null? null : ($acc1<0?'red':'green'));
$comp1 = $gen_lit_stage['t1']['levels']['comp']['delta_avg'] ?? null;
$compColor1 = ($comp1===null? null : ($comp1<0?'red':'green'));

$dCompl = (isset($gen_lit_stage['t0']['completion_avg'],$gen_lit_stage['t1']['completion_avg'])
          && $gen_lit_stage['t0']['completion_avg']!==null && $gen_lit_stage['t1']['completion_avg']!==null)
          ? (float)$gen_lit_stage['t1']['completion_avg'] - (float)$gen_lit_stage['t0']['completion_avg'] : null;

/* DIFERENȚE T1−T0 pentru NIVELURI */
$dAcc  = (isset($acc0,$acc1)
          && $acc0!==null && $acc1!==null)
          ? (float)$acc1 - (float)$acc0 : null;

$dComp = (isset($comp0,$comp1)
          && $comp0!==null && $comp1!==null)
          ? (float)$comp1 - (float)$comp0 : null;

$pillacc = ($dAcc===null? null : ($dAcc<0?'red':'green'));
$pillcompp = ($dComp===null? null : ($dComp<0?'red':'green'));
?>

<!-- LIT — KPIs 3 carduri -->
<section class="">
  <div class="grid items-stretch grid-cols-1 gap-4 lg:grid-cols-3">
    <!-- Card T0 -->
    <div class="p-4 bg-white border rounded-xl">
      <div class="flex items-center justify-between">
        <div class="text-sm font-bold text-slate-800">T0</div>
        <div class="text-xs text-slate-600">
          draft <strong><?= (int)($gen_lit_stage['t0']['status_counts']['draft'] ?? 0) ?></strong> /
          final <strong><?= (int)($gen_lit_stage['t0']['status_counts']['final'] ?? 0) ?></strong>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-2 mt-2 text-sm">
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Elevi cu rezultate</span>
          <span class="font-semibold"><?= intval($gen_lit_stage['t0']['students'] ?? 0) ?> / <?= intval($total_students ?? 0) ?></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Grad completare</span>
          <span class="font-semibold">
            <?php
              $t0_students = intval($gen_lit_stage['t0']['students'] ?? 0);
              $t0_total = intval($total_students ?? 0);
              echo $t0_total > 0 ? number_format(($t0_students / $t0_total) * 100, 2) . '%' : '—';
            ?>
          </span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Nivel Acuratețe</span>
          <span class="font-semibold"><?= gen_lit_color_badge($acc0, $accColor0) ?></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Nivel Comprehensiune</span>
          <span class="font-semibold"><?= gen_lit_color_badge($comp0, $compColor0) ?></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Elevi remedial</span>
          <span class="font-semibold">
            <?php
              $t0_rem = intval($gen_lit_stage['t0']['remedial_count'] ?? 0);
              $t0_eval = intval($gen_lit_stage['t0']['students'] ?? 0);
              echo $t0_rem . ' / ' . $t0_eval;
              if ($t0_eval > 0) echo ' · ' . number_format(($t0_rem / $t0_eval) * 100, 2) . '%';
            ?>
          </span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Remedial (puncte)</span>
          <span class="font-semibold">
            <?php
              $rp = $gen_lit_stage['t0']['remedial_points_sum'] ?? null;
              $rm = $gen_lit_stage['t0']['remedial_max_sum'] ?? null;
              $ravg = $gen_lit_stage['t0']['total_pct_avg_remedial'] ?? null;
              if ($rp!==null && $rm!==null) {
                echo intval(round($rp)).' / '.intval(round($rm)).' · ';
              }
              echo $P($ravg);
            ?>
          </span>
        </div>
      </div>
    </div>

    <!-- Card T1 -->
    <div class="p-4 bg-white border rounded-xl">
      <div class="flex items-center justify-between">
        <div class="text-sm font-bold text-slate-800">T1</div>
        <div class="text-xs text-slate-600">
          draft <strong><?= (int)($gen_lit_stage['t1']['status_counts']['draft'] ?? 0) ?></strong> /
          final <strong><?= (int)($gen_lit_stage['t1']['status_counts']['final'] ?? 0) ?></strong>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-2 mt-2 text-sm">
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Elevi cu rezultate</span>
          <span class="font-semibold"><?= intval($gen_lit_stage['t1']['students'] ?? 0) ?> / <?= intval($total_students ?? 0) ?></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Grad completare</span>
          <span class="font-semibold">
            <?php
              $t1_students = intval($gen_lit_stage['t1']['students'] ?? 0);
              $t1_total = intval($total_students ?? 0);
              echo $t1_total > 0 ? number_format(($t1_students / $t1_total) * 100, 2) . '%' : '—';
            ?>
          </span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Nivel Acuratețe</span>
          <span class="font-semibold"><?= gen_lit_color_badge($acc1, $accColor1) ?></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Nivel Comprehensiune</span>
          <span class="font-semibold"><?= gen_lit_color_badge($comp1, $compColor1) ?></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Elevi remedial</span>
          <span class="font-semibold">
            <?php
              $t1_rem = intval($gen_lit_stage['t1']['remedial_count'] ?? 0);
              $t1_eval = intval($gen_lit_stage['t1']['students'] ?? 0);
              echo $t1_rem . ' / ' . $t1_eval;
              if ($t1_eval > 0) echo ' · ' . number_format(($t1_rem / $t1_eval) * 100, 2) . '%';
            ?>
          </span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Remedial (puncte)</span>
          <span class="font-semibold">
            <?php
              $rp1 = $gen_lit_stage['t1']['remedial_points_sum'] ?? null;
              $rm1 = $gen_lit_stage['t1']['remedial_max_sum'] ?? null;
              $r1avg = $gen_lit_stage['t1']['total_pct_avg_remedial'] ?? null;
              if ($rp1!==null && $rm1!==null) {
                echo intval(round($rp1)).' / '.intval(round($rm1)).' · ';
              }
              echo $P($r1avg);
            ?>
          </span>
        </div>
      </div>
    </div>

    <!-- Card Diferențe / Medii -->
    <div class="flex flex-col items-stretch justify-between p-4 bg-white border rounded-xl">
      <div class="text-sm font-bold text-slate-800">Δ T1 − T0 & Medii</div>
      <div class="grid grid-cols-1 gap-2 mt-3 text-sm">
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Medie Completare</span>
          <div class="flex items-center font-semibold gap-x-2">
            <?php
              $total_st = intval($total_students ?? 0);
              $compl_t0_pct = ($total_st > 0 && isset($gen_lit_stage['t0']['students'])) ? (intval($gen_lit_stage['t0']['students']) / $total_st * 100) : null;
              $compl_t1_pct = ($total_st > 0 && isset($gen_lit_stage['t1']['students'])) ? (intval($gen_lit_stage['t1']['students']) / $total_st * 100) : null;
              $dComplNew = ($compl_t0_pct !== null && $compl_t1_pct !== null) ? ($compl_t1_pct - $compl_t0_pct) : null;
              $avgComplNew = ($compl_t0_pct !== null && $compl_t1_pct !== null) ? (($compl_t0_pct + $compl_t1_pct) / 2) : ($compl_t0_pct ?? $compl_t1_pct);
            ?>
            <span><?= gen_delta_chip($dComplNew) ?></span>
            <?= $avgComplNew !== null ? number_format($avgComplNew, 2).'%' : '—' ?>
          </div>
        </div>

        <!-- Aici sunt DIFERENȚELE (T1−T0), nu medii -->
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Nivel Acuratețe</span>
          <?= gen_lit_color_badge($dAcc, $pillacc) ?>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-slate-500">Nivel Comprehensiune</span>
          <?= gen_lit_color_badge($dComp, $pillcompp) ?>
        </div>

        <div class="flex items-center justify-between">
          <span class="text-slate-500">Remedial (medie %)</span>
          <div class="flex items-center gap-x-2">
            <span><?= gen_delta_chip($gen_lit_delta_total_remedial ?? null) ?></span>
            <?= $P($gen_lit_overall_pct_remedial,'md') ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- LIT — Rezultate pe itemi (medii raw, ca la raport elev) -->
<section class="p-6 mt-4 bg-white border rounded-2xl">
  <h3 class="mb-4 text-lg font-semibold">Rezultate pe itemi (medii)</h3>
  <div class="overflow-x-auto">
    <table class="min-w-[700px] w-full text-sm">
      <thead>
        <tr class="text-slate-700">
          <th class="py-2 text-left align-bottom">Întrebare</th>
          <th class="px-3 py-2 text-center align-bottom">T0</th>
          <th class="px-3 py-2 text-center align-bottom">T1</th>
          <th class="px-3 py-2 text-center align-bottom">Δ T1−T0</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php
          $order = ['lit_q1','lit_q2','lit_q3','lit_q4','lit_q5','lit_q6'];
          foreach($order as $k):
            $lab = $gen_lit_stage['t0']['items_core_raw'][$k]['label']
                ?? ($gen_lit_stage['t1']['items_core_raw'][$k]['label'] ?? strtoupper($k));

            $row0 = $gen_lit_stage['t0']['items_core_raw'][$k] ?? null;
            $row1 = $gen_lit_stage['t1']['items_core_raw'][$k] ?? null;

            $is_level = ($row0['is_level'] ?? $row1['is_level'] ?? false);

            if ($is_level) {
              $v0 = $row0['avg_num'] ?? null;
              $v1 = $row1['avg_num'] ?? null;
              $d  = ($v0!==null && $v1!==null) ? ($v1 - $v0) : null;

              // etichete: preferă avg_label; fallback din numeric (PP=-2, P=-1)
              $t0 = ($row0 && $row0['avg_label']!==null) ? $row0['avg_label']
                   : ($v0!==null ? gen_lit_level_num_to_label_pp2($v0) : '—');
              $t1 = ($row1 && $row1['avg_label']!==null) ? $row1['avg_label']
                   : ($v1!==null ? gen_lit_level_num_to_label_pp2($v1) : '—');
            } else {
              $v0 = $row0['avg_raw'] ?? null;
              $v1 = $row1['avg_raw'] ?? null;
              $d  = ($v0!==null && $v1!==null) ? ($v1 - $v0) : null;
              $t0 = ($v0!==null ? esc_html(number_format($v0, 2)) : '—');
              $t1 = ($v1!==null ? esc_html(number_format($v1, 2)) : '—');
            }
        ?>
        <tr>
          <td class="py-2 font-medium text-slate-900"><?= esc_html($lab) ?></td>
          <td class="px-3 py-2 text-center"><?= $t0 ?></td>
          <td class="px-3 py-2 text-center"><?= $t1 ?></td>
          <td class="px-3 py-2 text-center"><?= gen_delta_chip($d,'sm') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="mt-2 text-xs text-slate-500">
    Pentru itemii pe scală (PP, P, 0…5) am folosit: PP=<strong>−2</strong>, P=<strong>−1</strong>. Diferența (Δ) este calculată numeric.
  </p>
</section>

<?php
  // Întrebări condiționale — păstrăm doar % și Δ (fără “N T0 / N T1”)
  $cond = ['lit_q7','lit_q8','lit_q9','lit_q10','lit_q11','lit_q12'];
  $has_cond = false;
  foreach ($cond as $k) {
    $n0 = intval($gen_lit_stage['t0']['items'][$k]['n'] ?? 0);
    $n1 = intval($gen_lit_stage['t1']['items'][$k]['n'] ?? 0);
    if ($n0>0 || $n1>0) { $has_cond = true; break; }
  }
?>

<?php if ($has_cond): ?>
<section class="p-6 mt-4 mb-8 bg-white border rounded-2xl">
  <h3 class="mb-2 text-lg font-semibold">LIT — Rezultate evaluare emergenta / remedial</h3>
  <div class="overflow-x-auto">
    <table class="min-w-[820px] w-full text-sm">
      <thead>
        <tr class="text-slate-700">
          <th class="py-2 text-left">Întrebare</th>
          <th class="py-2 text-center">T0 (medie %)</th>
          <th class="py-2 text-center">T1 (medie %)</th>
          <th class="py-2 text-center">Δ T1−T0</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($cond as $k):
            $lab = $gen_lit_stage['t0']['items'][$k]['label'] ?? ($gen_lit_stage['t1']['items'][$k]['label'] ?? strtoupper($k));
            $p0  = $gen_lit_stage['t0']['items'][$k]['avg_pct'] ?? null;
            $p1  = $gen_lit_stage['t1']['items'][$k]['avg_pct'] ?? null;
            $d   = ($p0!==null && $p1!==null) ? ($p1 - $p0) : null;
        ?>
        <tr>
          <td class="py-2 font-medium text-slate-900"><?= esc_html($lab) ?></td>
          <td class="py-2 text-center"><?= $P($p0) ?></td>
          <td class="py-2 text-center"><?= $P($p1) ?></td>
          <td class="py-2 text-center"><?= gen_delta_chip($d) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="mt-2 text-xs text-slate-500">
    Aceste întrebări apar doar pentru elevii în <strong>Remedial</strong> și sunt mediate la nivelul generației.
  </p>
</section>
<?php endif; ?>

<?php
/* ===================== SECȚIUNE: Tabel elevi din generație ===================== *
 * Listează elevii care au evaluări LIT, cu Acuratețe & Comprehensiune la T0/T1 și Δ.
 * Se calculează din $rowsRaw + edu_students (nume/clasă).
 */
if (!function_exists('rg_maybe_unserialize')) {
  function rg_maybe_unserialize($v){
    if (is_array($v) || is_object($v)) return $v;
    if (!is_string($v)) return $v;
    $v2 = trim($v); if ($v2==='') return $v;
    if (preg_match('/^[adObis]:/', $v2) || $v2==='N;') { $u=@unserialize($v2); if ($u!==false || $v2==='b:0;') return $u; }
    return $v;
  }
}
if (!function_exists('rg_level_to_num_pp2')) {
  function rg_level_to_num_pp2($v){
    $v = strtoupper(trim((string)$v));
    if ($v==='') return null;
    if ($v==='PP') return -2;
    if ($v==='P')  return -1;
    if (is_numeric($v)) return intval($v);
    return null;
  }
}
if (!function_exists('rg_num_to_label_pp2')) {
  function rg_num_to_label_pp2($num){
    if ($num===null) return '—';
    $r = round($num);
    if ($r <= -2) return 'PP';
    if ($r === -1) return 'P';
    return (string)$r;
  }
}

/* preluare rezultate LIT din $rowsRaw (cele mai noi T0/T1 per elev) */
$rowsRaw = isset($rowsRaw) ? (array)$rowsRaw : [];
$rowsLIT = array_values(array_filter($rowsRaw, function($r){
  $mt = strtolower(trim($r->modul_type ?? ''));
  $m  = strtolower(trim($r->modul ?? ''));
  return ($mt==='literatie' || $mt==='lit' || strpos($m,'literatie-')===0 || strpos($m,'lit-')===0);
}));

$latest_by_student = ['t0'=>[],'t1'=>[]];
$student_ids_set = [];
foreach ($rowsLIT as $r){
  $sid = intval($r->student_id ?? 0); if (!$sid) continue;
  $m = strtolower(trim($r->modul ?? ''));
  $st = (strpos($m,'-t0')!==false) ? 't0' : ((strpos($m,'-t1')!==false) ? 't1' : null);
  if (!$st) continue;
  $k = (!empty($r->updated_at) ? strtotime($r->updated_at)
       : (!empty($r->created_at) ? strtotime($r->created_at)
       : intval($r->id ?? 0)));
  if (!isset($latest_by_student[$st][$sid]) || $k > $latest_by_student[$st][$sid]['key']){
    $latest_by_student[$st][$sid] = ['key'=>$k,'row'=>$r];
  }
  $student_ids_set[$sid] = true;
}
$student_ids = array_keys($student_ids_set);

/* nume + clasă din DB */
global $wpdb;
$tbl_students = $wpdb->prefix.'edu_students';
$student_map = [];
if (!empty($student_ids)) {
  $in = implode(',', array_fill(0, count($student_ids), '%d'));
  $rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, first_name, last_name, class_label FROM {$tbl_students} WHERE id IN ($in)",
    ...$student_ids
  ));
  foreach ($rows as $s) {
    $nm = trim(($s->first_name ?? '').' '.($s->last_name ?? ''));
    if ($nm==='') $nm = 'Elev #'.intval($s->id);
    $student_map[intval($s->id)] = [
      'name' => $nm,
      'class_label' => (string)($s->class_label ?? '—')
    ];
  }
}

/* agregăm pe elev */
$rows_students = [];
foreach ($student_ids as $sid) {
  $t0 = $latest_by_student['t0'][$sid]['row'] ?? null;
  $t1 = $latest_by_student['t1'][$sid]['row'] ?? null;

  $acc0=$comp0=$acc1=$comp1=null;

  if ($t0 && !empty($t0->score)) {
    $arr = rg_maybe_unserialize($t0->score);
    if (is_array($arr) && !empty($arr['breakdown'])) {
      foreach ($arr['breakdown'] as $it) {
        if (($it['name'] ?? '')==='lit_q2') $acc0  = $it['value'] ?? null;
        if (($it['name'] ?? '')==='lit_q4') $comp0 = $it['value'] ?? null;
      }
    }
  }
  if ($t1 && !empty($t1->score)) {
    $arr = rg_maybe_unserialize($t1->score);
    if (is_array($arr) && !empty($arr['breakdown'])) {
      foreach ($arr['breakdown'] as $it) {
        if (($it['name'] ?? '')==='lit_q2') $acc1  = $it['value'] ?? null;
        if (($it['name'] ?? '')==='lit_q4') $comp1 = $it['value'] ?? null;
      }
    }
  }

  // scala PP=−1, P=0 (ca în carduri)
  $n_acc0  = edus_level_string_to_num($acc0);
  $n_comp0 = edus_level_string_to_num($comp0);
  $n_acc1  = edus_level_string_to_num($acc1);
  $n_comp1 = edus_level_string_to_num($comp1);

  // diferența nivel evaluat − nivel clasă
  $cl = $student_map[$sid]['class_label'] ?? '';
  $grade_num = edus_grade_number_from_classlabel($cl);
  $class_value = max(0, min(8, ($grade_num >= 0 ? $grade_num : 0)));

  $d_acc0  = ($n_acc0  !== null) ? ($n_acc0  - $class_value) : null;
  $d_comp0 = ($n_comp0 !== null) ? ($n_comp0 - $class_value) : null;
  $d_acc1  = ($n_acc1  !== null) ? ($n_acc1  - $class_value) : null;
  $d_comp1 = ($n_comp1 !== null) ? ($n_comp1 - $class_value) : null;

  $rows_students[] = [
    'id'   => $sid,
    'name' => $student_map[$sid]['name'] ?? ('Elev #'.$sid),
    'class_label' => $student_map[$sid]['class_label'] ?? '—',
    'acc0' => $d_acc0,  'comp0'=>$d_comp0,
    'acc1' => $d_acc1,  'comp1'=>$d_comp1,
    'd_acc'  => ($d_acc0!==null  && $d_acc1!==null)  ? ($d_acc1  - $d_acc0)  : null,
    'd_comp' => ($d_comp0!==null && $d_comp1!==null) ? ($d_comp1 - $d_comp0) : null,
  ];
}
usort($rows_students, function($a,$b){ return strcasecmp($a['name'],$b['name']); });

/* celulă colorată: diferență nivel evaluat − nivel clasă (negativ=roșu, 0+=verde) */
$cell_level_label = function($val){
  if ($val===null) return '<span class="text-slate-400">—</span>';
  $cls = ($val < 0) ? 'bg-rose-100 text-rose-700 px-2 py-0.5 rounded'
                    : 'bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded';
  $lab = ($val > 0 ? '+' : '') . (string)$val;
  return '<span class="'.$cls.'">'.$lab.'</span>';
};
?>

<section class="p-6 mt-4 bg-white border rounded-2xl">
  <h3 class="mb-4 text-lg font-semibold">Elevi — Nivel Acuratețe & Comprehensiune (T0, T1, Δ)</h3>
  <div class="overflow-x-auto">
    <table class="min-w-[920px] w-full text-sm">
      <thead>
        <tr class="text-slate-700">
          <th class="py-2 text-left">Nume și prenume elev</th>
          <th class="px-3 py-2 text-left">Clasa elevului</th>
          <th colspan="2" class="px-3 py-2 text-center border-l border-slate-200">T0</th>
          <th colspan="2" class="px-3 py-2 text-center border-l border-slate-200">T1</th>
          <th colspan="2" class="px-3 py-2 text-center border-l border-r border-slate-200">Δ T1−T0</th>
        </tr>
        <tr class="text-xs text-slate-500">
          <th></th><th></th>
          <th class="px-3 py-1 text-center border-l border-slate-200">Acuratețe</th>
          <th class="px-3 py-1 text-center border-l border-slate-200">Comprehensiune</th>
          <th class="px-3 py-1 text-center border-l border-slate-200">Acuratețe</th>
          <th class="px-3 py-1 text-center border-l border-slate-200">Comprehensiune</th>
          <th class="px-3 py-1 text-center border-l border-slate-200">Acuratețe</th>
          <th class="px-3 py-1 text-center border-l border-r border-slate-200">Comprehensiune</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php if (!empty($rows_students)): ?>
          <?php foreach ($rows_students as $row): ?>
            <?php
              $sid   = (int)$row['id'];
              $url   = esc_url( home_url('/panou/raport/elev/'.$sid) ); // link la raport individual
              $t0acc = $cell_level_label($row['acc0']);
              $t0cmp = $cell_level_label($row['comp0']);
              $t1acc = $cell_level_label($row['acc1']);
              $t1cmp = $cell_level_label($row['comp1']);
            ?>
            <tr>
              <td class="py-2">
                <a href="<?= $url; ?>" class="font-semibold text-slate-900 hover:text-emerald-700"><?= esc_html($row['name']); ?></a>
              </td>
              <td class="px-3 py-2 text-slate-700"><?= esc_html($row['class_label']); ?></td>

              <td class="px-3 py-2 text-center border-l border-slate-200"><?= $t0acc; ?></td>
              <td class="px-3 py-2 text-center border-l border-slate-200"><?= $t0cmp; ?></td>

              <td class="px-3 py-2 text-center border-l border-slate-200"><?= $t1acc; ?></td>
              <td class="px-3 py-2 text-center border-l border-slate-200"><?= $t1cmp; ?></td>

              <td class="px-3 py-2 text-center border-l border-slate-200"><?= gen_delta_chip($row['d_acc'],'sm'); ?></td>
              <td class="px-3 py-2 text-center border-l border-r border-slate-200"><?= gen_delta_chip($row['d_comp'],'sm'); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="8" class="px-3 py-4 text-center text-slate-500">Nu există elevi cu rezultate LIT în această generație.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <p class="mt-2 text-xs text-slate-500">
    Valorile reprezintă diferența între nivelul evaluat (PP=−1, P=0, apoi 1…4) și nivelul clasei. Negativ = <span class="text-rose-600 font-semibold">roșu</span>, zero sau pozitiv = <span class="text-emerald-600 font-semibold">verde</span>.
  </p>
</section>

<section class=""></section>
<section class=""></section>
