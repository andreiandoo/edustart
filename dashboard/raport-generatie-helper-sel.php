<?php
/**
 * Helper — Raport generație: SEL
 * Input (din wrapper):
 *   - $wpdb, $generation_id
 *   - $students (id, first_name, last_name, gender, age, class_label)
 *   - $student_name (map id => "Nume Prenume")
 *   - $rowsRaw (toate rezultatele elevilor din generație)
 *
 * Expune pentru UI (nume *snake_case* folosite în tab):
 *   - $SEL_CHAPTERS
 *   - $perStageSEL              (ultimul T0/Ti/T1 per elev)
 *   - $latestAnySEL             (ultimul SEL disponibil per elev)
 *   - $sel_any_completion_avg   (completare medie % pt. ultimul SEL per elev)
 *   - $sel_any_avg_chapters     (medii pe capitole — ultimul SEL per elev)
 *   - $sel_t0_avg_chapters, $sel_ti_avg_chapters, $sel_t1_avg_chapters
 *   - $sel_stage_overall_avg    (['t0'=>..., 'ti'=>..., 't1'=>...])
 *   - $sel_any_overall_avg
 *   - $sel_student_overall_avg  (map sid => media pe capitole pt. ultimul rezultat)
 *   - $sel_gen_overall_avg_for_delta (alias la $sel_any_overall_avg)
 *
 * (plus compat alias în stil vechi: $SEL_perStage, $SEL_latest_any, $SEL_avg_by_stage, $SEL_avg_any, $SEL_avg_completion)
 */

if (!defined('ABSPATH')) exit;

// ---------- Compat & utilitare minime ----------
if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle){ return $needle === '' ? true : strpos($haystack, $needle) === 0; }
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

// Etichetele capitolelor SEL (păstrează ordinea clasică)
$SEL_CHAPTERS = ['Conștientizare de sine','Autoreglare','Conștientizare socială','Relaționare','Luarea deciziilor'];

// Extrage “etapa” din modul (sel-t0 / sel-ti / sel-t1), sau null
if (!function_exists('edus_sel_stage_from_modul')) {
  function edus_sel_stage_from_modul($m){
    $m = strtolower(trim($m ?? ''));
    if (str_starts_with($m,'sel-t0')) return 'sel-t0';
    if (str_starts_with($m,'sel-ti')) return 'sel-ti';
    if (str_starts_with($m,'sel-t1')) return 'sel-t1';
    return null;
  }
}

// Parsează valorile pe capitole din row (score serialized sau results json)
if (!function_exists('edus_sel_parse_chapter_map')) {
  function edus_sel_parse_chapter_map($row, $chapters){
    $map = [];
    foreach ($chapters as $c) $map[$c] = null;

    // 1) din score (serialized array indexat pe capitole)
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

    // 2) din results (json): chapters[] sau score_breakdown[]
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

// Media aritmetică pe capitole, ignorând null-urile
if (!function_exists('edus_sel_avg_by_chapters')) {
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

// Completare medie (din “completion”) – simplu, media valorilor disponibile
if (!function_exists('edus_completion_avg')) {
  function edus_completion_avg($rows){
    $s=0; $n=0;
    foreach ($rows as $r) { $s += intval($r->completion ?? 0); $n++; }
    return $n ? ($s / $n) : 0.0;
  }
}

// Ordonare cronologică e oferită de row_order_key() din wrapper

// ------------------------------------------------------------------
// 1) Ultimul rezultat SEL per elev, pe fiecare etapă: T0 / Ti / T1
// ------------------------------------------------------------------
$SEL_perStage  = [];   // compat
$perStageSEL   = [];   // numele folosit în UI
$SEL_latest_any = [];  // compat
$latestAnySEL   = [];  // numele folosit în UI

foreach ($rowsRaw as $r) {
  $mt = strtolower(trim($r->modul_type ?? ''));
  $mm = strtolower(trim($r->modul ?? ''));
  $isSEL = ($mt === 'sel') || str_starts_with($mm, 'sel-');
  if (!$isSEL) continue;

  $sid = (int)($r->student_id ?? 0);
  if (!$sid) continue;

  $stage = edus_sel_stage_from_modul($r->modul);
  if (!$stage) continue;

  $k = row_order_key($r);
  if (!isset($perStageSEL[$sid][$stage]) || $k > $perStageSEL[$sid][$stage]['_k']) {
    $perStageSEL[$sid][$stage] = ['row' => $r, '_k' => $k];
  }
}
// compat alias
$SEL_perStage = $perStageSEL;

// ultimul SEL disponibil per elev, cu prioritate T1 > Ti > T0
foreach ($perStageSEL as $sid => $byStage) {
  if (!empty($byStage['sel-t1']['row']))       { $latestAnySEL[$sid] = $byStage['sel-t1']['row']; continue; }
  if (!empty($byStage['sel-ti']['row']))       { $latestAnySEL[$sid] = $byStage['sel-ti']['row']; continue; }
  if (!empty($byStage['sel-t0']['row']))       { $latestAnySEL[$sid] = $byStage['sel-t0']['row']; continue; }
}
// compat alias
$SEL_latest_any = $latestAnySEL;

// ------------------------------------------------------------------
// 2) Medii pe capitole (ultimul SEL per elev) + completare medie
// ------------------------------------------------------------------
$sel_any_avg_chapters   = edus_sel_avg_by_chapters($latestAnySEL, $SEL_CHAPTERS);
$sel_any_completion_avg = edus_completion_avg($latestAnySEL);

// compat alias
$SEL_avg_any        = $sel_any_avg_chapters;
$SEL_avg_completion = $sel_any_completion_avg;

// ------------------------------------------------------------------
// 3) Medii pe capitole pe etape (T0 / Ti / T1)
// ------------------------------------------------------------------
$rows_t0 = $rows_ti = $rows_t1 = [];
foreach ($perStageSEL as $sid => $byStage) {
  if (!empty($byStage['sel-t0']['row'])) $rows_t0[$sid] = $byStage['sel-t0']['row'];
  if (!empty($byStage['sel-ti']['row'])) $rows_ti[$sid] = $byStage['sel-ti']['row'];
  if (!empty($byStage['sel-t1']['row'])) $rows_t1[$sid] = $byStage['sel-t1']['row'];
}

$sel_t0_avg_chapters = edus_sel_avg_by_chapters($rows_t0, $SEL_CHAPTERS);
$sel_ti_avg_chapters = edus_sel_avg_by_chapters($rows_ti, $SEL_CHAPTERS);
$sel_t1_avg_chapters = edus_sel_avg_by_chapters($rows_t1, $SEL_CHAPTERS);

// compat alias
$SEL_avg_by_stage = [
  't0' => $sel_t0_avg_chapters,
  'ti' => $sel_ti_avg_chapters,
  't1' => $sel_t1_avg_chapters,
];

// --- Delta-uri pe capitole între etape (pentru tabelul "Medii pe etape")
$sel_delta = [
  'ti_t0' => [],
  't1_ti' => [],
  't1_t0' => [],
];
foreach ($SEL_CHAPTERS as $cap) {
  $a = $sel_t0_avg_chapters[$cap] ?? 0;
  $b = $sel_ti_avg_chapters[$cap] ?? 0;
  $c = $sel_t1_avg_chapters[$cap] ?? 0;
  $sel_delta['ti_t0'][$cap] = $b - $a;
  $sel_delta['t1_ti'][$cap] = $c - $b;
  $sel_delta['t1_t0'][$cap] = $c - $a;
}

// --- Aliasuri explicite pt "medie generație pe capitol"
$sel_gen_chapter_avg_any = $sel_any_avg_chapters;
$sel_gen_chapter_avg_by_stage = [
  't0' => $sel_t0_avg_chapters,
  'ti' => $sel_ti_avg_chapters,
  't1' => $sel_t1_avg_chapters,
];

// ------------------------------------------------------------------
// 4) Medii generale (pe capitole) — overall per etapă și overall “ultimul”
// ------------------------------------------------------------------
if (!function_exists('edus_array_avg_non_null')) {
  function edus_array_avg_non_null(array $arr){
    $s=0; $n=0;
    foreach($arr as $v){ if($v!==null && $v!==''){ $s+=floatval($v); $n++; } }
    return $n ? ($s/$n) : null;
  }
}

$sel_stage_overall_avg = [
  't0' => edus_array_avg_non_null($sel_t0_avg_chapters),
  'ti' => edus_array_avg_non_null($sel_ti_avg_chapters),
  't1' => edus_array_avg_non_null($sel_t1_avg_chapters),
];
$sel_any_overall_avg = edus_array_avg_non_null($sel_any_avg_chapters);
$sel_gen_overall_avg_for_delta = $sel_any_overall_avg; // alias pentru UI

// ------------------------------------------------------------------
// 5) Media elevului (ultimul rezultat per elev), pentru Δ vs gen
// ------------------------------------------------------------------
$sel_student_overall_avg = [];
if (!empty($latestAnySEL)) {
  foreach ($latestAnySEL as $sid => $row) {
    $map = edus_sel_parse_chapter_map($row, $SEL_CHAPTERS);
    $sel_student_overall_avg[(int)$sid] = edus_array_avg_non_null($map);
  }
}

// === AVERAGE PE TOATE ETAPELE (gen) ===
$rows_all = array_merge(array_values($rows_t0), array_values($rows_ti), array_values($rows_t1));

// medii pe capitole, luând în calcul T0, Ti, T1 (toate valorile disponibile)
$SEL_avg_allStages_chapters = edus_sel_avg_by_chapters($rows_all, $SEL_CHAPTERS);

// media generală (pe toate capitolele, pe toate etapele) – pentru rezumat
$SEL_overall_allStages_avg = edus_array_avg_non_null($SEL_avg_allStages_chapters);

// completare medie pe toate etapele (dacă vrei s-o afișezi în rezumat)
$SEL_completion_allStages = edus_completion_avg($rows_all);

// completare medie per etapă
$sel_completion_t0 = edus_completion_avg(array_values($rows_t0));
$sel_completion_ti = edus_completion_avg(array_values($rows_ti));
$sel_completion_t1 = edus_completion_avg(array_values($rows_t1));

// aliasuri pentru UI (ca să fie clar ce folosim unde)
$sel_gen_chapter_avg_allStages = $SEL_avg_allStages_chapters;
$sel_overall_allStages_avg     = $SEL_overall_allStages_avg;
$sel_completion_allStages      = $SEL_completion_allStages;
// ------------------------------------------