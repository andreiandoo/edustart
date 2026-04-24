<?php
if (!defined('ABSPATH')) exit;

/* Template Name: Admin – Lista Generații (complet, cu SEL/LIT & Tutor) */

$current_user = wp_get_current_user();
$is_admin     = current_user_can('manage_options');
$DEBUG        = !empty($_GET['debug']);

if (!$is_admin) {
  echo '<div class="px-6 py-10">
          <div class="max-w-3xl p-8 mx-auto text-center bg-white border shadow-sm rounded-2xl border-slate-200">
            <h2 class="text-xl font-semibold text-slate-800">Acces restricționat</h2>
            <p class="mt-2 text-slate-600">Această pagină este disponibilă doar pentru <strong>Administrator</strong>.</p>
          </div>
        </div>';
  return;
}

// Helper Admin
require_once get_stylesheet_directory() . '/dashboard/admin-generatii-helper.php';

// === NEW: GEN MODAL & BUTTON (server-side vars) ===
$edu_nonce             = wp_create_nonce('edu_nonce');
$ajax_url_teachers     = admin_url('admin-ajax.php');
$ajax_nonce_teachers   = wp_create_nonce('edu_nonce');

// Filtre
$s         = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
$year_f    = isset($_GET['year']) ? sanitize_text_field(wp_unslash($_GET['year'])) : '';
$level_arr = isset($_GET['level']) ? (array) wp_unslash($_GET['level']) : [];
$level_arr = array_values(array_filter(array_map('sanitize_text_field', $level_arr)));
$tutor_q   = isset($_GET['tutor_q']) ? sanitize_text_field(wp_unslash($_GET['tutor_q'])) : '';
$prof_q    = isset($_GET['prof_q'])  ? sanitize_text_field(wp_unslash($_GET['prof_q']))  : '';
$perpage   = max(5, min(200, (int)($_GET['perpage'] ?? 25)));
$paged     = max(1, (int)($_GET['paged'] ?? 1));

// Colectăm setul
$bundle = admin_gen_build_cards_all([
  's'=>$s, 'year'=>$year_f, 'level_arr'=>$level_arr,
  'tutor_q'=>$tutor_q, 'prof_q'=>$prof_q,
  'perpage'=>$perpage, 'paged'=>$paged,
]);
$cards  = $bundle['cards'];
$years  = $bundle['years'];
$levels = $bundle['levels'];
$total  = (int)$bundle['total'];
$total_pages = max(1, (int)ceil($total / $perpage));
$paged  = min($paged, $total_pages);

$gen_flags = [];
if (!empty($cards)) {
  global $wpdb;
  $gen_ids = array_map(fn($r) => (int)$r['gid'], $cards);
  $place = implode(',', array_fill(0, count($gen_ids), '%d'));
  $rows = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT id, sel_t0, sel_ti, sel_t1, lit_t0, lit_t1, num_t0, num_t1
       FROM {$wpdb->prefix}edu_generations
       WHERE id IN ($place)",
      $gen_ids
    ),
    ARRAY_A
  );
  foreach ($rows as $r) {
    $id = (int)$r['id'];
    unset($r['id']);
    $gen_flags[$id] = array_map('intval', $r);
  }
}

// Base URL fără paged/export
$qs = $_GET; unset($qs['paged'],$qs['export']);
$base_url = esc_url(add_query_arg($qs, remove_query_arg(['paged','export'])));

// HEADER premium
?>

<section class="sticky top-0 z-20 border-b inner-submenu bg-slate-800 border-slate-200">
  <div class="relative z-20 flex items-center justify-between px-2 py-2 gap-x-2 mobile:gap-x-1">
    <div class="flex items-center justify-start">
      <!-- === NEW: GEN MODAL & BUTTON === -->
      <button id="es-open-add-gen" type="button"
        class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md rounded-tl-xl hover:bg-indigo-700">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a1 1 0 0 1 1 1v8h8a1 1 0 1 1 0 2h-8v8a1 1 0 1 1-2 0v-8H3a1 1 0 1 1 0-2h8V3a1 1 0 0 1 1-1Z"/></svg>
        Generație/clasă
      </button>
    </div>

    <div class="flex items-center gap-2">
        <a href="<?php echo esc_url(add_query_arg('export','csv',$base_url)); ?>"
        class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
        <svg class="w-3 h-3 mobile:w-5 mobile:h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
        <span class="mobile:hidden">Export CSV</span>
        </a>

        <a href="../documentatie/#generatii" target="_blank" rel="noopener noreferrer"
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

<script>
window.ajaxurl = window.ajaxurl || '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>';
window.__GENMOD_NONCE = '<?php echo esc_js( wp_create_nonce('genmod_nonce') ); ?>';
// === NEW: expose nonces for gen modal ===
window.__EDU_NONCE = '<?php echo esc_js( $edu_nonce ); ?>';
window.__AJAX_URL_TEACHERS = '<?php echo esc_js( $ajax_url_teachers ); ?>';
window.__AJAX_NONCE_TEACHERS = '<?php echo esc_js( $ajax_nonce_teachers ); ?>';
</script>

<div class="px-6 mobile:px-2">
    <!-- FILTRE -->
    <section class="mt-4 mb-6">
      <form id="gen-filter-form" method="get" class="grid items-end grid-cols-1 gap-3 md:grid-cols-12">
          <div class="md:col-span-3">
          <label class="block mb-1 text-xs font-medium text-slate-600">Căutare (generație / profesor / tutor)</label>
          <input type="text" name="q" id="gen-search-q" value="<?php echo esc_attr($s); ?>"
                  placeholder="Tastează pentru a căuta..."
                  autocomplete="off"
                  class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-700 focus:border-transparent">
          </div>
          <div class="md:col-span-2">
          <label class="block mb-1 text-xs font-medium text-slate-600">An școlar</label>
          <select name="year" id="gen-filter-year" class="w-full px-3 py-2 text-sm bg-white border shadow-sm gen-filter-select rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-700 focus:border-transparent">
              <option value="">— Oricare —</option>
              <?php foreach ($years as $yr): ?>
              <option value="<?php echo esc_attr($yr); ?>" <?php selected($year_f===$yr); ?>><?php echo esc_html($yr); ?></option>
              <?php endforeach; ?>
          </select>
          </div>
          <!-- Nivel (multiselect) -->
          <div class="relative md:col-span-2" id="gen-nivel-wrap">
            <label class="block mb-1 text-xs font-medium text-slate-600">Nivel</label>
            <?php
              $gen_nivel_opts = ['prescolar'=>'Preșcolar','primar'=>'Primar','gimnazial'=>'Gimnazial','liceu'=>'Liceu'];
            ?>
            <div id="gen-nivel-inputs">
              <?php foreach ($level_arr as $v): ?>
                <input type="hidden" name="level[]" value="<?php echo esc_attr($v); ?>">
              <?php endforeach; ?>
            </div>
            <div id="gen-nivel-trigger" class="flex items-center gap-1 w-full min-h-[38px] px-3 py-1.5 text-sm bg-white border shadow-sm rounded-xl border-slate-300 cursor-pointer hover:border-slate-400 flex-wrap">
              <?php if (empty($level_arr)): ?>
                <span class="ms-placeholder text-slate-400">— Oricare —</span>
              <?php else: ?>
                <?php foreach ($level_arr as $v): ?>
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 ms-tag" data-val="<?php echo esc_attr($v); ?>">
                    <?php echo esc_html($gen_nivel_opts[$v] ?? $v); ?>
                    <button type="button" class="ms-remove hover:text-red-600">&times;</button>
                  </span>
                <?php endforeach; ?>
              <?php endif; ?>
              <svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
            </div>
            <div id="gen-nivel-dropdown" class="hidden absolute z-30 w-full mt-1 bg-white border shadow-lg rounded-xl border-slate-200 py-1" style="min-width:200px">
              <?php foreach ($gen_nivel_opts as $k => $lab): ?>
                <div class="ms-opt flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50" data-val="<?php echo esc_attr($k); ?>">
                  <span class="inline-flex items-center justify-center w-4 h-4 rounded border ms-check <?php echo in_array($k, $level_arr, true) ? 'bg-sky-600 border-sky-600' : 'bg-white border-slate-300'; ?>">
                    <?php if (in_array($k, $level_arr, true)): ?>
                      <svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    <?php endif; ?>
                  </span>
                  <span><?php echo esc_html($lab); ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="md:col-span-2">
            <label class="block mb-1 text-xs font-medium text-slate-600">Tutor</label>
            <input type="text" name="tutor_q" id="gen-filter-tutor" value="<?php echo esc_attr($tutor_q); ?>"
                    placeholder="Nume sau email tutor"
                    autocomplete="off"
                    class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-700 focus:border-transparent">
          </div>
          <div class="md:col-span-2">
            <label class="block mb-1 text-xs font-medium text-slate-600">Profesor</label>
            <input type="text" name="prof_q" id="gen-filter-prof" value="<?php echo esc_attr($prof_q); ?>"
                    placeholder="Nume sau email profesor"
                    autocomplete="off"
                    class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-700 focus:border-transparent">
          </div>
          <?php $has_filters = ($s !== '' || $year_f !== '' || !empty($level_arr) || $tutor_q !== '' || $prof_q !== ''); ?>
          <div class="flex items-end gap-2 md:col-span-1">
            <button type="submit" class="inline-flex items-center justify-center w-full gap-2 px-3 py-2 text-sm font-semibold text-white shadow-sm bg-emerald-600 rounded-xl hover:bg-emerald-700">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                    <path fill-rule="evenodd" d="M3.792 2.938A49.069 49.069 0 0 1 12 2.25c2.797 0 5.54.236 8.209.688a1.857 1.857 0 0 1 1.541 1.836v1.044a3 3 0 0 1-.879 2.121l-6.182 6.182a1.5 1.5 0 0 0-.439 1.061v2.927a3 3 0 0 1-1.658 2.684l-1.757.878A.75.75 0 0 1 9.75 21v-5.818a1.5 1.5 0 0 0-.44-1.06L3.13 7.938a3 3 0 0 1-.879-2.121V4.774c0-.897.64-1.683 1.542-1.836Z" clip-rule="evenodd" />
                </svg>
                Filtrează
            </button>
          </div>
          <?php if ($has_filters): ?>
            <div class="flex items-end gap-2 md:col-span-12">
              <a href="<?php echo esc_url(remove_query_arg(['q','year','level','tutor_q','prof_q','paged'])); ?>"
                 class="inline-flex items-center justify-center gap-2 px-3 py-2 text-xs font-medium text-rose-700 bg-rose-50 border border-rose-200 rounded-lg hover:bg-rose-100">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                  <path fill-rule="evenodd" d="M5.47 5.47a.75.75 0 0 1 1.06 0L12 10.94l5.47-5.47a.75.75 0 1 1 1.06 1.06L13.06 12l5.47 5.47a.75.75 0 1 1-1.06 1.06L12 13.06l-5.47 5.47a.75.75 0 0 1-1.06-1.06L10.94 12 5.47 6.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                </svg>
                Șterge filtrele
              </a>
            </div>
          <?php endif; ?>
      </form>
    </section>

    <!-- LISTĂ CARDURI (identic cu tutor, dar cu TUTOR în header) -->
    <section class="pb-10">
    <?php if ($DEBUG): ?>
        <div class="p-4 mb-4 text-xs rounded-xl bg-slate-900 text-slate-100">
        <b>DEBUG</b>
        <pre><?php echo esc_html(print_r([
            'filters'=> compact('s','year_f','level_arr','tutor_q','prof_q','perpage','paged'),
            'total'=> $total,
        ], true)); ?></pre>
        </div>
    <?php endif; ?>

    <?php if (empty($cards)): ?>
        <div class="max-w-3xl p-10 mx-auto mt-4 text-center bg-white border shadow-sm rounded-2xl border-slate-200">
            <h3 class="text-lg font-semibold text-slate-800">Nu există generații pentru criteriile curente</h3>
            <p class="mt-2 text-slate-600">Ajustează filtrele sau caută după nume profesor/tutor/gen.</p>
        </div>
    <?php else: ?>

    <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 mb-3 bg-white border border-slate-200 rounded-2xl">
        <div class="flex items-center gap-3">
            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" id="gen-select-all" class="rounded size-4 border-slate-300">
            Selectează toate
            </label>
            <span class="text-slate-300">|</span>
            <span class="text-sm text-slate-600">Acțiune în masă:</span>
            <select id="bulk-module" class="px-2 py-1 text-sm bg-white border rounded-lg border-slate-300">
            <option value="sel_t0">SEL T0</option>
            <option value="sel_ti">SEL Ti</option>
            <option value="sel_t1">SEL T1</option>
            <option value="lit_t0">LIT T0</option>
            <option value="lit_t1">LIT T1</option>
            <option value="num_t0">NUM T0</option>
            <option value="num_t1">NUM T1</option>
            </select>
            <button type="button" id="bulk-activate" class="px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">Activează</button>
            <button type="button" id="bulk-deactivate" class="px-3 py-1.5 text-xs font-medium rounded-lg bg-slate-200 text-slate-800 hover:bg-slate-300">Dezactivează</button>
        </div>
        <div class="text-xs text-slate-500" id="bulk-count">0 selectate</div>
    </div>


    <div id="gen-cards-container" class="mt-2 space-y-3">
        <?php foreach ($cards as $c): ?>
            <?php
            $gen_url   = home_url('/panou/generatia/' . (int)$c['gid']);
            $prof_url  = home_url('/panou/profesor/' . (int)$c['pid']);
            $tutor_url = $c['tid'] ? home_url('/panou/tutor/' . (int)$c['tid']) : '';

            // SEL pills
            $sel_t0_pill = tutor_score_pill($c['sel_t0'], 2, false);
            $sel_ti_pill = tutor_score_pill($c['sel_ti'], 2, false);
            $sel_t1_pill = tutor_score_pill($c['sel_t1'], 2, false);

            $sel_cmp_t0 = tutor_pct_pill($c['comp_rates']['sel']['t0']);
            $sel_cmp_ti = tutor_pct_pill($c['comp_rates']['sel']['ti']);
            $sel_cmp_t1 = tutor_pct_pill($c['comp_rates']['sel']['t1']);

            // LIT Δ & % & remedial
            $acc_t0 = tutor_lit_delta_pill($c['acc_t0_delta'], 0, true);
            $acc_t1 = tutor_lit_delta_pill($c['acc_t1_delta'], 0, true);
            $acc_av = tutor_lit_delta_pill($c['acc_avg_delta'], 0, true);

            $comp_t0 = tutor_lit_delta_pill($c['comp_t0_delta'], 0, true);
            $comp_t1 = tutor_lit_delta_pill($c['comp_t1_delta'], 0, true);
            $comp_av = tutor_lit_delta_pill($c['comp_avg_delta'], 0, true);

            $lit_cmp_t0 = tutor_pct_pill($c['lit_t0_pct']);
            $lit_cmp_t1 = tutor_pct_pill($c['lit_t1_pct']);
            $lit_cmp_av = tutor_pct_pill($c['lit_avg_pct']);

            $rem_t0 = tutor_count_pill($c['rem_t0'] ?? 0);
            $rem_t1 = tutor_count_pill($c['rem_t1'] ?? 0);
            $rem_av = tutor_count_pill(round($c['rem_avg'] ?? 0));

            $chip_draft = tutor_badge($c['drafts_count'].' draft', 'bg-amber-50 text-amber-900 ring-1 ring-inset ring-amber-200');
            $chip_final = tutor_badge($c['finals_count'].' final', 'bg-emerald-50 text-emerald-900 ring-1 ring-inset ring-emerald-200');
            $eval_total = (int)$c['drafts_count'] + (int)$c['finals_count'];
            $chip_total = tutor_badge($eval_total.' rezultate', 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200');

            // Search blob pentru filtrare locală
            $search_blob = implode(' ', [
                $c['gname'], $c['gyear'], $c['glevel'],
                $c['prof_name'], $c['tutor_name'],
                (int)$c['pid'], (int)$c['tid']
            ]);
            ?>

            <div class="w-full overflow-hidden bg-white border gen-card rounded-2xl ring-1 ring-black/5 border-slate-200"
                 data-search="<?php echo esc_attr($search_blob); ?>"
                 data-year="<?php echo esc_attr($c['gyear']); ?>"
                 data-level="<?php echo esc_attr($c['glevel']); ?>"
                 data-tutor="<?php echo (int)$c['tid']; ?>"
                 data-prof="<?php echo (int)$c['pid']; ?>">
            <div class="flex flex-col p-4 gap-y-2">
                <!-- HEADER: generație/profesor/tutor + status -->
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center min-w-0 gap-x-4">
                      <div class="shrink-0">
                        <input type="checkbox" class="rounded gen-select size-4 border-slate-300" data-gid="<?php echo (int)$c['gid']; ?>">
                      </div>
                      <div class="flex flex-wrap items-center gap-2">
                        <a href="<?php echo esc_url($gen_url); ?>" class="flex items-center text-base font-semibold tracking-tight gap-x-2 text-slate-900 hover:text-emerald-700">
                            <?php echo esc_html($c['gname']); ?>
                        </a>
                        <div class="">
                          <span class="px-3 py-1 text-sm rounded-full bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200"><?php echo esc_html($c['gyear']); ?> - Ciclu <?php echo esc_html($c['glevel']); ?> - <b><?php echo (int)$c['students_count']; ?> elevi</b></span>
                        </div>
                        <a href="<?php echo esc_url( home_url('/panou/raport/generatie/'.(int)$c['gid']) ); ?>"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-emerald-700 rounded-full shadow-sm bg-emerald-50 hover:bg-emerald-100 ring-1 ring-emerald-200">
                          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4">
                            <path fill-rule="evenodd" d="M5.625 1.5c-1.036 0-1.875.84-1.875 1.875v17.25c0 1.035.84 1.875 1.875 1.875h12.75c1.035 0 1.875-.84 1.875-1.875V12.75A3.75 3.75 0 0 0 16.5 9h-1.875a1.875 1.875 0  0 1-1.875-1.875V5.25A3.75 3.75 0  0 0 9 1.5H5.625ZM7.5 15a.75.75 0  0 1 .75-.75h7.5a.75.75 0  0 1 0 1.5h-7.5A.75.75 0  0 1 7.5 15Zm.75 2.25a.75.75 0  0 0 0 1.5H12a.75.75 0  0 0 0-1.5H8.25Z" clip-rule="evenodd" />
                            <path d="M12.971 1.816A5.23 5.23 0  0 1 14.25 5.25v1.875c0 .207.168.375.375.375H16.5a5.23 5.23 0  0 1 3.434 1.279 9.768 9.768 0  0 0-6.963-6.963Z" />
                          </svg>
                          Raport
                      </a>
                      </div>
                      <div class="flex flex-wrap items-center text-sm gap-x-4 gap-y-1 text-slate-600">
                        <span class="inline-flex items-center gap-1.5">
                            Profesor: 
                            <a href="<?php echo esc_url($prof_url); ?>" class="font-medium text-slate-800 hover:text-emerald-700"><?php echo esc_html($c['prof_name']); ?></a>
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            Tutor: 
                            <?php if ($tutor_url): ?>
                              <a href="<?php echo esc_url($tutor_url); ?>" class="font-medium text-slate-800 hover:text-emerald-700"><?php echo esc_html($c['tutor_name']); ?></a>
                            <?php else: ?>
                              <span class="text-slate-500">—</span>
                            <?php endif; ?>
                        </span>
                      </div>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <?php echo $chip_total.$chip_draft.$chip_final; ?>
                    </div>
                </div>

                <!-- BODY: SEL | LIT (identic cu pagina de tutor) -->
                <div class="flex flex-row items-center justify-start gap-x-4">

                <!-- SEL -->
                <div class="flex items-center gap-x-4">
                    <div class="inline-flex items-center gap-2">
                    <span class="inline-flex items-center justify-center px-2 py-1 text-lg font-semibold rounded-lg bg-sky-100 text-sky-700 ring-1 ring-sky-200">SEL</span>
                    </div>
                    <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="flex items-center p-2 border rounded-lg gap-x-2 border-slate-200/80 bg-white/60">
                        <div class="text-xl font-bold text-slate-800">T0</div>
                        <div><?php echo $sel_t0_pill; ?></div>
                        <div><?php echo $sel_cmp_t0; ?></div>
                    </div>
                    <div class="flex items-center p-2 border rounded-lg gap-x-2 border-slate-200/80 bg-white/60">
                        <div class="text-xl font-bold text-slate-800">Ti</div>
                        <div><?php echo $sel_ti_pill; ?></div>
                        <div><?php echo $sel_cmp_ti; ?></div>
                    </div>
                    <div class="flex items-center p-2 border rounded-lg gap-x-2 border-slate-200/80 bg-white/60">
                        <div class="text-xl font-bold text-slate-800">T1</div>
                        <div><?php echo $sel_t1_pill; ?></div>
                        <div><?php echo $sel_cmp_t1; ?></div>
                    </div>
                    </div>
                </div>

                <!-- LIT -->
                <div class="flex items-center gap-x-4">
                    <div class="inline-flex items-center gap-2">
                    <span class="inline-flex items-center justify-center px-2 py-1 text-lg font-semibold rounded-lg bg-violet-100 text-violet-700 ring-1 ring-violet-200">LIT</span>
                    </div>
                    <div class="grid grid-cols-3 gap-2 text-center">
                    <!-- T0 -->
                    <div class="flex items-start p-2 border rounded-lg gap-x-2 border-slate-200/80">
                        <div class="text-xl font-bold text-slate-800">T0</div>
                        <div class="flex flex-col justify-center"><?php echo $acc_t0; ?><small class="text-xxs">ACU</small></div>
                        <div class="flex flex-col justify-center"><?php echo $comp_t0; ?><small class="text-xxs">COMP</small></div>
                        <div><?php echo $lit_cmp_t0; ?></div>
                        <div><?php echo $rem_t0; ?></div>
                    </div>
                    <!-- T1 -->
                    <div class="flex items-start p-2 border rounded-lg gap-x-2 border-slate-200/80">
                        <div class="text-xl font-bold text-slate-800">T1</div>
                        <div class="flex flex-col justify-center"><?php echo $acc_t1; ?><small class="text-xxs">ACU</small></div>
                        <div class="flex flex-col justify-center"><?php echo $comp_t1; ?><small class="text-xxs">COMP</small></div>
                        <div><?php echo $lit_cmp_t1; ?></div>
                        <div><?php echo $rem_t1; ?></div>
                    </div>
                    <!-- AVG -->
                    <div class="flex items-start p-2 border rounded-lg gap-x-2 border-slate-200/80">
                        <div class="text-xl font-bold text-slate-800">AVG</div>
                        <div class="flex flex-col justify-center"><?php echo $acc_av; ?><small class="text-xxs">ACU</small></div>
                        <div class="flex flex-col justify-center"><?php echo $comp_av; ?><small class="text-xxs">COMP</small></div>
                        <div><?php echo $lit_cmp_av; ?></div>
                        <div><?php echo $rem_av; ?></div>
                    </div>
                    </div>
                </div>

                </div>

                <?php
                    $gid = (int)$c['gid'];
                    // ia flags din fallback sau din $c, dacă le ai acolo
                    $flags = $gen_flags[$gid] ?? [
                        'sel_t0'=>0,'sel_ti'=>0,'sel_t1'=>0,
                        'lit_t0'=>0,'lit_t1'=>0,
                        'num_t0'=>0,'num_t1'=>0,
                    ];
                    $modules = [
                        'sel_t0' => 'SEL T0',
                        'sel_ti' => 'SEL Ti',
                        'sel_t1' => 'SEL T1',
                        'lit_t0' => 'LIT T0',
                        'lit_t1' => 'LIT T1',
                        'num_t0' => 'NUM T0',
                        'num_t1' => 'NUM T1',
                    ];
                ?>
                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                    <span class="mr-1 text-xs text-slate-500">Module:</span>
                    <?php foreach ($modules as $key => $label):
                        $on  = !empty($flags[$key]);
                        $cls = $on
                        ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                        : 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-200';
                    ?>
                        <button type="button"
                        class="genmod-toggle inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-full <?php echo $cls; ?>"
                        data-gid="<?php echo $gid; ?>"
                        data-mod="<?php echo esc_attr($key); ?>"
                        data-val="<?php echo $on ? '1' : '0'; ?>">
                        <span class="w-1.5 h-1.5 rounded-full <?php echo $on ? 'bg-white' : 'bg-slate-400'; ?>"></span>
                        <?php echo esc_html($label); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Paginare -->
    <?php if ($total_pages > 1): ?>
        <div class="flex items-center justify-between mt-4">
            <div class="text-sm text-slate-600">
            Afișezi <strong><?php echo (int)min($total, ($paged-1)*$perpage + $perpage); ?></strong> din <strong><?php echo (int)$total; ?></strong> generații.
            </div>
            <div class="flex items-center gap-2">
            <?php $mk = function($p) use ($base_url){ return esc_url(add_query_arg('paged', max(1,(int)$p), $base_url)); }; ?>
            <a href="<?php echo $mk($paged-1); ?>" class="px-3 py-1 text-sm border rounded-lg <?php echo $paged<=1?'pointer-events-none opacity-40':''; ?>">« Înapoi</a>
            <span class="px-2 text-sm text-slate-700">Pagina <?php echo (int)$paged; ?> / <?php echo (int)$total_pages; ?></span>
            <a href="<?php echo $mk($paged+1); ?>" class="px-3 py-1 text-sm border rounded-lg <?php echo $paged>=$total_pages?'pointer-events-none opacity-40':''; ?>">Înainte »</a>
            </div>
        </div>
    <?php endif; ?>

    <?php endif; ?>
    </section>
</div>

<!-- === NEW: MODAL Adaugă / Alocă generație === -->
<div id="es-gen-modal" class="fixed inset-0 z-[100] hidden">
  <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>
  <div class="relative max-w-2xl mx-auto my-10">
    <div class="mx-4 overflow-hidden bg-white border shadow-xl rounded-2xl border-slate-200">
      <div class="flex items-center justify-between px-5 py-4 border-b bg-slate-50 border-slate-200">
        <h3 id="es-gen-modal-title" class="text-base font-semibold text-slate-900">Adaugă generație/clasă</h3>
        <button type="button" id="es-close-gen" class="p-2 text-slate-500 hover:text-slate-700">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M6 6l12 12M6 18 18 6"/></svg>
        </button>
      </div>

      <form id="es-gen-form" class="px-5 py-4">
        <input type="hidden" name="nonce" value="<?php echo esc_attr($edu_nonce); ?>">
        <input type="hidden" id="es-gen-id" name="gen_id" value="">
        <input type="hidden" id="es-gen-professor-id" name="professor_id" value="">
        <input type="hidden" id="es-gen-year" name="year" value="">

        <!-- Inline error (duplicate an școlar etc.) -->
        <div id="es-gen-error" class="hidden mb-3 px-3 py-2 text-sm text-rose-800 bg-rose-50 border border-rose-200 rounded-xl" role="alert"></div>

        <div class="grid grid-cols-1 gap-4">
          <!-- Profesor -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Profesor</label>
            <div class="flex gap-2" id="es-gen-prof-search-wrap">
              <input id="es-gen-prof-search" type="text" placeholder="Caută profesor după nume/email..."
                    class="flex-1 px-3 py-2 text-sm bg-white border rounded-xl border-slate-300"
                    data-ajax="<?php echo esc_url($ajax_url_teachers); ?>" data-nonce="<?php echo esc_attr($ajax_nonce_teachers); ?>">
              <button id="es-gen-prof-clear" type="button"
                      class="px-3 py-2 text-sm bg-white border rounded-xl hover:bg-slate-50 border-slate-300">Golește</button>
            </div>
            <div id="es-gen-prof-selected" class="mt-2 text-sm text-slate-700"></div>
            <div id="es-gen-prof-suggest" class="hidden mt-2 overflow-hidden border divide-y rounded-xl border-slate-200 divide-slate-200"></div>
          </div>

          <!-- Nivel (derivat) -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Nivel (derivat din profesor)</label>
            <div class="px-3 py-2 text-sm border bg-slate-50 rounded-xl border-slate-200 text-slate-800" id="es-gen-level-ro">—</div>
          </div>

          <!-- Clase disponibile -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Clase disponibile (informativ)</label>
            <div id="es-gen-level-classes" class="flex flex-wrap gap-2"></div>
          </div>

          <!-- Nume generație -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Nume generație/clasă</label>
            <input name="name" id="es-gen-name" type="text" placeholder="ex: G12 — Clasa a V-a A"
                   required class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
          </div>

          <!-- An școlar (dropdown: anul curent + următorii 2) -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">An școlar</label>
            <select id="es-gen-year-display" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
            </select>
          </div>
        </div>

        <div class="flex items-center justify-end gap-2 pt-5 mt-5 border-t border-slate-200">
          <button type="button" id="es-cancel-gen" class="px-3 py-2 text-sm bg-white border rounded-xl hover:bg-slate-50 border-slate-300">Anulează</button>
          <button id="es-submit-gen" type="submit" class="px-3 py-2 text-sm text-white bg-indigo-600 rounded-xl hover:bg-indigo-700">
            Salvează generația/clasa
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  // fallback toast dacă nu ai deja edusToast în pagina asta
  if (typeof window.edusToast !== 'function') {
    window.edusToast = function(msg, type='ok', ms=2000){
      const wrap = document.createElement('div');
      wrap.className = 'fixed inset-0 z-[9999] flex items-center justify-center';
      wrap.innerHTML = `
        <div class="absolute inset-0 bg-black/40"></div>
        <div class="relative px-5 py-3 rounded-2xl shadow-2xl text-base ${
          type === 'ok' ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white'
        } transform opacity-0 scale-95 transition-all duration-150">${msg}</div>`;
      document.body.appendChild(wrap);
      requestAnimationFrame(() => wrap.children[1].classList.remove('opacity-0','scale-95'));
      setTimeout(() => {
        wrap.children[1].classList.add('opacity-0','scale-95');
        setTimeout(() => wrap.remove(), 160);
      }, ms);
    };
  }

  const $ = (sel, ctx=document) => ctx.querySelector(sel);
  const $$ = (sel, ctx=document) => Array.from(ctx.querySelectorAll(sel));

  function pillClasses(on){
    return on
      ? 'genmod-toggle inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-full bg-emerald-600 text-white hover:bg-emerald-700'
      : 'genmod-toggle inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded-full bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-200';
  }
  function setPillState(btn, on){
    btn.setAttribute('data-val', on ? '1':'0');
    btn.className = pillClasses(on);
    const dot = btn.querySelector('span.w-1\\.5');
    if (dot) dot.className = 'w-1.5 h-1.5 rounded-full ' + (on ? 'bg-white' : 'bg-slate-400');
  }

  // === FIX: robust JSON parse (elimină "Eroare de rețea" falsă)
  function postJSON(data){
    return fetch(window.ajaxurl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body: new URLSearchParams(data).toString()
    })
    .then(r => r.text())
    .then(txt => {
      try { return JSON.parse(txt); }
      catch(e) {
        // WP poate returna "0" sau HTML; întoarcem un obiect coerent
        return { success: false, data: (txt && txt.trim()!=='') ? txt : 'Răspuns invalid de la server.' };
      }
    });
  }

  // — Toggle pe butonul din card
  document.addEventListener('click', function(e){
    const btn = e.target.closest('.genmod-toggle');
    if (!btn) return;
    const gid = btn.dataset.gid;
    const mod = btn.dataset.mod;
    const cur = btn.dataset.val === '1';
    const newVal = cur ? 0 : 1;

    btn.disabled = true;
    postJSON({
      action: 'edu_toggle_generation_module',
      gid: gid,
      module: mod,
      value: String(newVal),
      nonce: window.__GENMOD_NONCE
    }).then(res => {
      if (res && res.success) setPillState(btn, !!newVal);
      else edusToast((res && res.data) ? res.data : 'Eroare la actualizare.', 'err', 2000);
    }).catch(() => edusToast('Eroare la rețea.', 'err', 2000))
      .finally(() => { btn.disabled = false; });
  });

  // — Selecție în masă
  const selectAll = $('#gen-select-all');
  const visibleGenChecks = () => $$('.gen-select').filter(cb => cb.closest('.gen-card')?.style.display !== 'none');
  const updateBulkCount = () => {
    const n = $$('.gen-select:checked').filter(cb => cb.closest('.gen-card')?.style.display !== 'none').length;
    const bc = $('#bulk-count');
    if (bc) bc.textContent = n + ' selectate';
  };
  if (selectAll) {
    selectAll.addEventListener('change', function(){
      visibleGenChecks().forEach(cb => { cb.checked = selectAll.checked; });
      updateBulkCount();
    });
  }
  document.addEventListener('change', function(e){
    if (e.target.matches('.gen-select')) updateBulkCount();
  });

  // — Bulk activate/deactivate
  function bulkSet(val){
    const ids = $$('.gen-select:checked').filter(cb => cb.closest('.gen-card')?.style.display !== 'none').map(cb => cb.dataset.gid);
    if (!ids.length) { edusToast('Selectează cel puțin o generație.', 'err', 1800); return; }
    const module = $('#bulk-module')?.value || 'sel_t0';

    postJSON({
      action: 'edu_bulk_toggle_generation_modules',
      gids: JSON.stringify(ids),
      module: module,
      value: String(val),
      nonce: window.__GENMOD_NONCE
    }).then(res => {
      if (res && res.success) {
        ids.forEach(id => {
          $$('.genmod-toggle[data-gid="'+id+'"][data-mod="'+module+'"]').forEach(btn => setPillState(btn, !!val));
        });
        edusToast('Actualizat pentru ' + (res.data?.updated || ids.length) + ' generații.', 'ok', 1800);
      } else {
        edusToast(res?.data || 'Eroare la actualizare.', 'err', 2000);
      }
    }).catch(() => edusToast('Eroare la rețea.', 'err', 2000));
  }
  $('#bulk-activate')?.addEventListener('click', () => bulkSet(1));
  $('#bulk-deactivate')?.addEventListener('click', () => bulkSet(0));

  // =========================
  // === GEN MODAL (NEW)  ====
  // =========================
  const genModal    = document.getElementById('es-gen-modal');
  const genForm     = document.getElementById('es-gen-form');
  const genTitleEl  = document.getElementById('es-gen-modal-title');
  const genIdInput  = document.getElementById('es-gen-id');
  const genProfId   = document.getElementById('es-gen-professor-id');
  const genYearHid  = document.getElementById('es-gen-year');
  const genYearDisp = document.getElementById('es-gen-year-display');
  const genName     = document.getElementById('es-gen-name');

  const genOpenBtn  = document.getElementById('es-open-add-gen');
  const genCloseBtn = document.getElementById('es-close-gen');
  const genCancel   = document.getElementById('es-cancel-gen');
  const genSubmit   = document.getElementById('es-submit-gen');

  const genProfSearchWrap = document.getElementById('es-gen-prof-search-wrap');
  const genProfSearch = document.getElementById('es-gen-prof-search');
  const genProfSuggest= document.getElementById('es-gen-prof-suggest');
  const genProfSelected = document.getElementById('es-gen-prof-selected');

  const genLevelRO   = document.getElementById('es-gen-level-ro');
  const genLevelClasses = document.getElementById('es-gen-level-classes');

  const GEN_CREATE_ACTION = 'edu_create_generation';
  const LEVEL_LABEL = { prescolar:'Preșcolar', primar:'Primar', gimnazial:'Gimnazial', liceu:'Liceu' };
  const CLASSES_MAP = {
    prescolar: ['Grupa mică','Grupa mijlocie','Grupa mare'],
    primar:    ['Clasa pregătitoare','Clasa I','Clasa II','Clasa III','Clasa IV'],
    gimnazial: ['Clasa V','Clasa VI','Clasa VII','Clasa VIII'],
    liceu:     ['Clasa IX','Clasa X','Clasa XI','Clasa XII']
  };

  function computeAcademicYearStr(){
    const d = new Date();
    const y = d.getFullYear(), m = d.getMonth() + 1; // 1..12
    return (m >= 8) ? `${y}-${y+1}` : `${y-1}-${y}`;
  }
  function getAcademicYearOptions(){
    const d = new Date();
    const y = d.getFullYear(), m = d.getMonth() + 1;
    const baseYear = (m >= 8) ? y : y - 1;
    return [
      `${baseYear}-${baseYear+1}`,
      `${baseYear+1}-${baseYear+2}`,
      `${baseYear+2}-${baseYear+3}`,
    ];
  }
  function populateYearDropdown(selectedValue){
    if (!genYearDisp) return;
    const opts = getAcademicYearOptions();
    const sel = selectedValue || opts[0];
    genYearDisp.innerHTML = opts.map(o =>
      `<option value="${o}" ${o === sel ? 'selected' : ''}>${o}</option>`
    ).join('');
    if (genYearHid) genYearHid.value = genYearDisp.value;
  }
  function setYearAuto(){
    populateYearDropdown();
  }

  // Inline error panel (duplicate year, etc.)
  const genErrEl = document.getElementById('es-gen-error');
  function showGenError(msg){
    if (!genErrEl) return;
    genErrEl.textContent = msg || '';
    genErrEl.classList.toggle('hidden', !msg);
  }
  function showGenErrorWithLink(msg, url, linkLabel){
    if (!genErrEl) return;
    genErrEl.textContent = '';
    genErrEl.appendChild(document.createTextNode(msg + ' '));
    if (url && linkLabel) {
      genErrEl.appendChild(document.createTextNode('Vezi: '));
      const a = document.createElement('a');
      a.href = url;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.className = 'font-medium underline hover:text-rose-900';
      a.textContent = linkLabel;
      genErrEl.appendChild(a);
    }
    genErrEl.classList.remove('hidden');
  }
  function clearGenError(){ showGenError(''); }

  if (genYearDisp) genYearDisp.addEventListener('change', () => {
    if (genYearHid) genYearHid.value = genYearDisp.value;
    clearGenError();
  });
  function renderLevelAndClasses(code){
    const label = LEVEL_LABEL[code] || '—';
    genLevelRO.textContent = label;
    genLevelClasses.innerHTML = '';
    const arr = CLASSES_MAP[code] || [];
    arr.forEach(txt => {
      const b = document.createElement('span');
      b.className = 'inline-flex items-center px-2 py-0.5 text-xs rounded-full bg-sky-50 text-sky-800 ring-1 ring-sky-200';
      b.textContent = txt;
      genLevelClasses.appendChild(b);
    });
  }
  async function fetchProfessorLevel(pid){
    if (!pid) { renderLevelAndClasses(''); return; }
    try{
      const fd = new FormData();
      fd.append('action','edu_get_prof_level');
      fd.append('nonce', window.__EDU_NONCE || '');
      fd.append('professor_id', String(pid));
      const r = await fetch(window.ajaxurl, { method:'POST', body:fd, credentials:'same-origin' });
      const txt = await r.text(); let data={};
      try{ data = JSON.parse(txt); }catch(_){ data = {success:false}; }
      if (data && data.success && data.data) {
        renderLevelAndClasses(data.data.level_code || '');
        const sy = (data.data.year || computeAcademicYearStr());
        populateYearDropdown(sy);
      } else {
        renderLevelAndClasses('');
      }
    }catch(_){
      renderLevelAndClasses('');
    }
  }
  function lockProfessorUI(locked, labelHtml){
    if (locked) {
      genProfSearchWrap.classList.add('hidden');
      genProfSuggest.classList.add('hidden'); genProfSuggest.innerHTML='';
      genProfSelected.innerHTML = labelHtml || '';
    } else {
      genProfSearchWrap.classList.remove('hidden');
      genProfSelected.innerHTML = '';
    }
  }
  function showGenModal(){
    genForm.reset();
    genIdInput.value = '';
    genProfId.value = '';
    setYearAuto();
    genLevelRO.textContent = '—';
    genLevelClasses.innerHTML = '';
    genTitleEl.textContent = 'Adaugă generație';
    lockProfessorUI(false, '');
    clearGenError();
    genModal.classList.remove('hidden');
    genName.focus();
  }
  function hideGenModal(){ genModal.classList.add('hidden'); }

  // deschidere liberă
  genOpenBtn?.addEventListener('click', ()=> showGenModal());
  genCloseBtn?.addEventListener('click', hideGenModal);
  genCancel?.addEventListener('click', hideGenModal);
  genModal?.querySelector('.absolute.inset-0')?.addEventListener('click', (e)=>{ if(e.target===e.currentTarget) hideGenModal(); });

  // deschidere din card (preselect profesorul)
  document.querySelectorAll('.es-open-assign-gen').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const pid = btn.getAttribute('data-prof-id') || '';
      const pname = btn.getAttribute('data-prof-name') || '';
      genForm.reset();
      genIdInput.value = '';
      genProfId.value = pid;
      setYearAuto();
      fetchProfessorLevel(pid);
      lockProfessorUI(true,
        pname
          ? `<span class="px-2 py-1 text-indigo-800 rounded bg-indigo-50 ring-1 ring-indigo-200">Profesor: ${escapeHtml(pname)} (#${pid})</span>`
          : `<span class="px-2 py-1 text-indigo-800 rounded bg-indigo-50 ring-1 ring-indigo-200">Profesor selectat: #${pid}</span>`
      );
      genTitleEl.textContent = 'Alocă generație';
      genModal.classList.remove('hidden');
      genName.focus();
    });
  });

  // căutare profesor (admin)
  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
  let genTipTimer=null;
  genProfSearch?.addEventListener('input', ()=>{
    clearTimeout(genTipTimer);
    const q = (genProfSearch.value||'').trim();
    if(q.length<2){ genProfSuggest.classList.add('hidden'); genProfSuggest.innerHTML=''; return; }
    genTipTimer = setTimeout(()=> loadProfSuggest(q), 220);
  });
  document.getElementById('es-gen-prof-clear')?.addEventListener('click', ()=>{
    genProfId.value = '';
    genProfSelected.innerHTML = '';
    genProfSuggest.classList.add('hidden'); genProfSuggest.innerHTML='';
    genProfSearch.value=''; genProfSearch.focus();
    renderLevelAndClasses('');
    setYearAuto();
  });

  async function loadProfSuggest(q){
    try{
      const fd = new FormData();
      fd.append('action','edu_search_teachers');
      fd.append('nonce', window.__AJAX_NONCE_TEACHERS || '');
      fd.append('q', q);
      const r = await fetch(window.__AJAX_URL_TEACHERS || window.ajaxurl, { method:'POST', body:fd, credentials:'same-origin' });
      const txt = await r.text(); let data=[];
      try{ data = JSON.parse(txt); }catch(_){ data = []; }
      if(!Array.isArray(data) || !data.length){ genProfSuggest.classList.add('hidden'); genProfSuggest.innerHTML=''; return; }
      genProfSuggest.innerHTML = data.slice(0,15).map(it=>{
        const label = escapeHtml(it.text || (`#${it.id}`));
        return `<button type="button" data-id="${it.id}" class="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-50">${label}</button>`;
      }).join('');
      genProfSuggest.classList.remove('hidden');
      Array.from(genProfSuggest.querySelectorAll('button[data-id]')).forEach(b=>{
        b.addEventListener('click', ()=>{
          const pid = b.getAttribute('data-id') || '';
          genProfId.value = pid;
          genProfSelected.innerHTML = `<span class="px-2 py-1 text-indigo-800 rounded bg-indigo-50 ring-1 ring-indigo-200">Profesor selectat: ${escapeHtml(b.textContent)} (#${pid})</span>`;
          genProfSuggest.classList.add('hidden'); genProfSuggest.innerHTML='';
          clearGenError();
          fetchProfessorLevel(pid);
        });
      });
    }catch(_){
      genProfSuggest.classList.add('hidden'); genProfSuggest.innerHTML='';
    }
  }

  // submit creare generație
  genForm?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    clearGenError();
    const pid = (genProfId.value||'').trim();
    if (!pid) { showGenError('Selectează profesorul.'); return; }
    if (!genName.value.trim()) { showGenError('Scrie numele generației.'); return; }

    const fd = new FormData(genForm);
    fd.append('action', GEN_CREATE_ACTION);

    const old = genSubmit.innerHTML;
    genSubmit.disabled = true; genSubmit.textContent = 'Se salvează...';
    try{
      const r = await fetch(window.ajaxurl, { method:'POST', body: fd, credentials:'same-origin' });
      const txt = await r.text(); let resp={};
      try{ resp = JSON.parse(txt); } catch(e){ resp = {success:false, data:{message:txt}}; }
      if(resp && resp.success){
        edusToast('Generație salvată.', 'ok');
        hideGenModal();
        setTimeout(()=>location.reload(), 500);
      } else {
        const d   = (resp && resp.data) ? resp.data : null;
        const msg = d ? (d.message || (typeof d === 'string' ? d : '')) : '';
        if (d && d.code === 'duplicate_year' && d.existing_url) {
          showGenErrorWithLink(msg || 'Generație existentă.', d.existing_url, d.existing_name || ('#' + (d.existing_id || '')));
        } else {
          showGenError(msg || 'Eroare la salvarea generației.');
        }
      }
    }catch(_){
      showGenError('Eroare de rețea.');
    }finally{
      genSubmit.disabled=false; genSubmit.innerHTML = old;
    }
  });
})();
</script>

<!-- Nivel multiselect dropdown -->
<script>
(function(){
  const prefix = 'gen-nivel';
  const inputName = 'level[]';
  const options = <?php echo wp_json_encode($gen_nivel_opts, JSON_UNESCAPED_UNICODE); ?>;
  let selected = <?php echo wp_json_encode(array_values($level_arr)); ?>;
  const placeholder = '— Oricare —';

  const wrap     = document.getElementById(prefix + '-wrap');
  const trigger  = document.getElementById(prefix + '-trigger');
  const dropdown = document.getElementById(prefix + '-dropdown');
  const inputs   = document.getElementById(prefix + '-inputs');
  if (!wrap || !trigger || !dropdown || !inputs) return;

  const checkSvg = '<svg class="w-3 h-3 text-white" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
  const arrow    = '<svg class="w-4 h-4 ml-auto shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';

  function render(){
    inputs.innerHTML = selected.map(v => `<input type="hidden" name="${inputName}" value="${v}">`).join('');
    const tags = selected.map(v => {
      const lab = options[v] || v;
      return `<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200 ms-tag" data-val="${v}">
        ${lab}<button type="button" class="ms-remove hover:text-red-600">&times;</button></span>`;
    }).join('');
    trigger.innerHTML = selected.length ? tags + arrow : `<span class="ms-placeholder text-slate-400">${placeholder}</span>` + arrow;
    dropdown.querySelectorAll('.ms-opt').forEach(opt => {
      const v = opt.dataset.val, isOn = selected.includes(v);
      const box = opt.querySelector('.ms-check');
      box.className = 'inline-flex items-center justify-center w-4 h-4 rounded border ms-check ' + (isOn ? 'bg-sky-600 border-sky-600' : 'bg-white border-slate-300');
      box.innerHTML = isOn ? checkSvg : '';
    });
  }
  trigger.addEventListener('click', (e) => {
    if (e.target.closest('.ms-remove')) {
      const tag = e.target.closest('.ms-tag');
      if (tag?.dataset.val) { selected = selected.filter(v => v !== tag.dataset.val); render(); }
      return;
    }
    dropdown.classList.toggle('hidden');
  });
  dropdown.addEventListener('click', (e) => {
    const opt = e.target.closest('.ms-opt');
    if (!opt) return;
    const val = opt.dataset.val;
    if (selected.includes(val)) selected = selected.filter(v => v !== val);
    else selected.push(val);
    render();
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#' + prefix + '-wrap')) dropdown.classList.add('hidden');
  });
})();
</script>

<!-- Filtrare locală live (doar pentru q — an, nivel, tutor, profesor = server-side) -->
<script>
(function(){
  const form = document.getElementById('gen-filter-form');
  const searchInput = document.getElementById('gen-search-q');
  const container = document.getElementById('gen-cards-container');

  if (!form || !container) return;

  const cards = Array.from(container.querySelectorAll('.gen-card'));
  const bulkBar = container.previousElementSibling;

  const norm = (s) => (s || '').toString().toLowerCase()
    .normalize('NFD').replace(/\p{Diacritic}/gu, '').trim();

  // Local search only filters on `q` — other filters reload the page via form submit.
  let debounceTimer = null;
  function debounceFilter() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(filterCards, 150);
  }
  searchInput?.addEventListener('input', debounceFilter);

  function filterCards() {
    const q = norm(searchInput?.value || '');
    let visibleCount = 0;
    cards.forEach(card => {
      const searchBlob = norm(card.getAttribute('data-search') || '');
      const show = !q || searchBlob.includes(q);
      card.style.display = show ? '' : 'none';
      if (show) visibleCount++;
    });
    updateNoResultsMessage(visibleCount);

    const selectAll = document.getElementById('gen-select-all');
    if (selectAll) selectAll.checked = false;
    document.querySelectorAll('.gen-select').forEach(cb => cb.checked = false);
    const bulkCount = document.getElementById('bulk-count');
    if (bulkCount) bulkCount.textContent = '0 selectate';
  }

  let noResultsEl = null;
  function updateNoResultsMessage(count) {
    if (count === 0 && cards.length > 0) {
      if (!noResultsEl) {
        noResultsEl = document.createElement('div');
        noResultsEl.id = 'gen-no-results';
        noResultsEl.className = 'max-w-3xl p-6 mx-auto mt-4 text-center bg-white border shadow-sm rounded-2xl border-slate-200';
        noResultsEl.innerHTML = `
          <h3 class="text-lg font-semibold text-slate-800">Nu există generații pentru criteriile curente</h3>
          <p class="mt-2 text-slate-600">Ajustează filtrele sau caută după nume profesor/tutor/gen.</p>
        `;
      }
      if (!container.contains(noResultsEl)) container.appendChild(noResultsEl);
      if (bulkBar) bulkBar.style.display = 'none';
    } else {
      if (noResultsEl && container.contains(noResultsEl)) container.removeChild(noResultsEl);
      if (bulkBar) bulkBar.style.display = '';
    }
  }

  filterCards();
})();
</script>
