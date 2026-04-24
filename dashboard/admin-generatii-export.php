<?php
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in() || !current_user_can('manage_options')) {
  status_header(403);
  echo 'Acces restricționat.';
  exit;
}

require_once get_stylesheet_directory() . '/dashboard/admin-generatii-helper.php';

$s         = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
$year_f    = isset($_GET['year']) ? sanitize_text_field(wp_unslash($_GET['year'])) : '';
$level_arr = isset($_GET['level']) ? (array) wp_unslash($_GET['level']) : [];
$level_arr = array_values(array_filter(array_map('sanitize_text_field', $level_arr)));
$tutor_q   = isset($_GET['tutor_q']) ? sanitize_text_field(wp_unslash($_GET['tutor_q'])) : '';
$prof_q    = isset($_GET['prof_q'])  ? sanitize_text_field(wp_unslash($_GET['prof_q']))  : '';

$all_export = admin_gen_build_cards_all([
  's'         => $s,
  'year'      => $year_f,
  'level_arr' => $level_arr,
  'tutor_q'   => $tutor_q,
  'prof_q'    => $prof_q,
  'perpage'   => 999999,
  'paged'     => 1,
])['cards'];

while (ob_get_level() > 0) { ob_end_clean(); }

$filename = 'generatii_admin_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM

fputcsv($out, [
  'GenID','Nume generație','An','Nivel',
  'ProfesorID','Profesor','TutorID','Tutor',
  '#Elevi','Draft','Final',
  'SEL T0','SEL Ti','SEL T1',
  'ΔACU T0','ΔACU T1','ΔACU AVG',
  'ΔCOMP T0','ΔCOMP T1','ΔCOMP AVG',
  'LIT% T0','LIT% T1','LIT% AVG',
  'Rem T0','Rem T1','Rem AVG',
  'Completare SEL T0','Completare SEL Ti','Completare SEL T1',
  'Creat la'
]);

foreach ($all_export as $r) {
  fputcsv($out, [
    (int)$r['gid'], $r['gname'], $r['gyear'], $r['glevel'],
    (int)$r['pid'], $r['prof_name'], (int)$r['tid'], $r['tutor_name'],
    (int)$r['students_count'], (int)$r['drafts_count'], (int)$r['finals_count'],
    round((float)$r['sel_t0'], 2), round((float)$r['sel_ti'], 2), round((float)$r['sel_t1'], 2),
    round((float)$r['acc_t0_delta'], 0), round((float)$r['acc_t1_delta'], 0), round((float)$r['acc_avg_delta'], 0),
    round((float)$r['comp_t0_delta'], 0), round((float)$r['comp_t1_delta'], 0), round((float)$r['comp_avg_delta'], 0),
    round((float)$r['lit_t0_pct'], 0), round((float)$r['lit_t1_pct'], 0), round((float)$r['lit_avg_pct'], 0),
    (int)$r['rem_t0'], (int)$r['rem_t1'], round((float)$r['rem_avg'], 0),
    round((float)$r['comp_rates']['sel']['t0'], 0), round((float)$r['comp_rates']['sel']['ti'], 0), round((float)$r['comp_rates']['sel']['t1'], 0),
    adg_dt($r['created_at']),
  ]);
}
fclose($out);
exit;
