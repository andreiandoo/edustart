<?php
if (!defined('ABSPATH')) exit;

$current_user = wp_get_current_user();
$user_roles   = (array) $current_user->roles;
$tutor_id     = (int) $current_user->ID;
$DEBUG        = !empty($_GET['debug']);

if (!in_array('tutor', $user_roles, true)) {
  echo '<div class="px-6 py-10">
          <div class="max-w-3xl p-8 mx-auto text-center bg-white border shadow-sm rounded-2xl border-slate-200">
            <h2 class="text-xl font-semibold text-slate-800">Acces restricționat</h2>
            <p class="mt-2 text-slate-600">Această secțiune este disponibilă doar pentru utilizatorii cu rolul <strong>Tutor</strong>.</p>
          </div>
        </div>';
  return;
}

/** Include helper logic */
require_once get_stylesheet_directory() . '/dashboard/tutor-generatii-helper.php';

$bundle = tutor_gen_build_cards($tutor_id);
$professor_ids = $bundle['professor_ids'];
$pairs = $bundle['pairs'];
$cards = $bundle['cards'];

/** Header premium */
echo '
  <section class="sticky top-0 z-20 border-b inner-submenu bg-slate-800 border-slate-200">
    <div class="relative z-20 flex items-center justify-between px-4 py-3 gap-x-2">
      <div class="flex items-center justify-start">
        <h1 class="text-sm font-semibold text-white">Generațiile profesorilor tăi</h1>
      </div>

      <div class="flex items-center gap-2">
        
      </div>
    </div>
  </section>
';

echo '<div class="px-6 mt-4 mb-6">';

if ($DEBUG) {
  echo '<div class="p-4 mt-4 space-y-2 text-sm rounded-xl bg-slate-900 text-slate-100">';
  echo '<div><b>DEBUG</b>: tutor_id = '.(int)$tutor_id.'</div>';
  echo '<div><b>DEBUG</b>: found professors ('.count($professor_ids).')</div>';
  echo '<div><b>DEBUG</b>: pairs = '.count($pairs).'</div>';
  echo '</div>';
}

if (empty($professor_ids)) {
  echo '
    <div class="max-w-3xl p-10 mx-auto mt-8 text-center bg-white border border-dashed rounded-2xl border-slate-300">
      <h3 class="text-lg font-semibold text-slate-800">N-ai încă profesori alocați</h3>
      <p class="mt-2 text-slate-600">Când un administrator te alocă unor profesori (metakey <code>assigned_tutor_id</code>), generațiile lor vor apărea aici.</p>
    </div>
  ';
  echo '</div>';
  return;
}
if (empty($cards)) {
  echo '
    <div class="max-w-3xl p-10 mx-auto mt-8 text-center bg-white border shadow-sm rounded-2xl border-slate-200">
      <h3 class="text-lg font-semibold text-slate-800">Nu există încă generații</h3>
      <p class="mt-2 text-slate-600">Asigură-te că există elevi în <code>wp_edu_students</code> cu <code>professor_id</code> din setul alocat și <code>generation_id</code> setat.</p>
    </div>
  ';
  echo '</div>';
  return;
}

/** ---------- UI: listă carduri (varianta compactă & premium) ---------- */
echo '<div class="space-y-3">';

foreach ($cards as $c) {
  $gen_url   = home_url('/panou/generatia/' . (int)$c['gid']);
  $prof_url  = home_url('/panou/profesor/' . (int)$c['pid']);

  // SEL (medii) + completare
  $sel_t0_pill = tutor_score_pill($c['sel_t0'], 2, false);
  $sel_ti_pill = tutor_score_pill($c['sel_ti'], 2, false);
  $sel_t1_pill = tutor_score_pill($c['sel_t1'], 2, false);

  $sel_cmp_t0 = tutor_pct_pill($c['comp_rates']['sel']['t0']);
  $sel_cmp_ti = tutor_pct_pill($c['comp_rates']['sel']['ti']);
  $sel_cmp_t1 = tutor_pct_pill($c['comp_rates']['sel']['t1']);

  // LIT – Δ Acuratețe / Δ Comprehensiune (fără PP/P/1–4), Completare, Remedial
  $acc_t0 = tutor_lit_delta_pill($c['acc_t0_delta'], 0, true);
  $acc_t1 = tutor_lit_delta_pill($c['acc_t1_delta'], 0, true);
  $acc_av = tutor_lit_delta_pill($c['acc_avg_delta'], 0, true);

  $comp_t0 = tutor_lit_delta_pill($c['comp_t0_delta'], 0, true);
  $comp_t1 = tutor_lit_delta_pill($c['comp_t1_delta'], 0, true);
  $comp_av = tutor_lit_delta_pill($c['comp_avg_delta'], 0, true);

  $lit_cmp_t0 = tutor_pct_pill($c['comp_rates']['lit']['t0']);
  $lit_cmp_t1 = tutor_pct_pill($c['comp_rates']['lit']['t1']);
  $lit_cmp_av = tutor_pct_pill($c['lit_comp_avg'] ?? null);

  $rem_t0 = tutor_count_pill($c['rem_t0'] ?? 0);
  $rem_t1 = tutor_count_pill($c['rem_t1'] ?? 0);
  $rem_av = tutor_count_pill(round($c['rem_avg'] ?? 0));

  // Status evaluări
  $chip_draft = tutor_badge($c['drafts_count'].' draft', 'bg-amber-50 text-amber-900 ring-1 ring-inset ring-amber-200');
  $chip_final = tutor_badge($c['finals_count'].' final', 'bg-emerald-50 text-emerald-900 ring-1 ring-inset ring-emerald-200');
  $eval_total = (int)$c['drafts_count'] + (int)$c['finals_count'];
  $chip_total = tutor_badge($eval_total.' rezultate', 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200');

  echo '
  <div class="w-full overflow-hidden bg-white border rounded-2xl ring-1 ring-black/5 border-slate-200">
    <div class="flex flex-col p-4 gap-y-2 md:p-5">

      <!-- HEADER: generație/profesor + status + număr evaluări -->
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center min-w-0 gap-x-4">
          <div class="flex flex-wrap items-center gap-2">
            <a href="'.esc_url($prof_url).'" class="font-medium text-slate-800 hover:text-blue-600">'.esc_html($c['prof_name']).'</a>
            
            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-slate-700 ring-1 ring-inset ring-slate-200">'.esc_html($c['gyear']).'- Ciclul <b>'.esc_html($c['prof_level']).'</b> - <b>'.(int)$c['students_count'].' elevi</b></span>
          </div>
          <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-slate-600">
            <span class="inline-flex items-center gap-1.5 gap-x-2">
              <a href="'.esc_url($gen_url).'" class="flex items-center text-base font-semibold tracking-tight gap-x-2 text-slate-900 hover:text-blue-600">
                '.esc_html($c['gname']).'
              </a>
            </span>
          </div>
        </div>
        <div class="flex items-center gap-1.5">
          '.$chip_total.$chip_draft.$chip_final.'
        </div>
      </div>

      <!-- BODY: două casete (SEL | LIT) -->
      <div class="flex flex-row items-center justify-between gap-x-4">

        <!-- SEL -->
        <div class="flex items-center gap-x-4">
          <div class="flex items-center justify-between">
            <div class="inline-flex items-center gap-2">
              <span class="inline-flex items-center justify-center px-2 py-1 text-lg font-semibold rounded-lg bg-sky-100 text-sky-700 ring-1 ring-sky-200">SEL</span>
            </div>
          </div>
          <div class="grid grid-cols-3 gap-2 text-center">
            <div class="flex items-center p-2 border rounded-lg gap-x-2 border-slate-200/80 bg-white/60">
              <div class="text-xl font-bold text-slate-800">T0</div>
              <div>'.$sel_t0_pill.'</div>
              <div>'.$sel_cmp_t0.'</div>
            </div>
            <div class="flex items-center p-2 border rounded-lg gap-x-2 border-slate-200/80 bg-white/60">
              <div class="text-xl font-bold text-slate-800">Ti</div>
              <div>'.$sel_ti_pill.'</div>
              <div>'.$sel_cmp_ti.'</div>
            </div>
            <div class="flex items-center p-2 border rounded-lg gap-x-2 border-slate-200/80 bg-white/60">
              <div class="text-xl font-bold text-slate-800">T1</div>
              <div>'.$sel_t1_pill.'</div>
              <div>'.$sel_cmp_t1.'</div>
            </div>
          </div>
        </div>

        <!-- LIT -->
        <div class="flex items-center gap-x-4">
          <div class="flex items-center justify-between">
            <div class="inline-flex items-center gap-2">
              <span class="inline-flex items-center justify-center px-2 py-1 text-lg font-semibold rounded-lg bg-violet-100 text-violet-700 ring-1 ring-violet-200">LIT</span>
            </div>
          </div>

          <div class="grid grid-cols-3 gap-2 text-center">
            <!-- T0 -->
            <div class="flex items-start p-2 border rounded-lg gap-x-2 border-slate-200/80">
              <div class="text-xl font-bold text-slate-800">T0</div>
              <div class="flex flex-col justify-center">'.$acc_t0.'<small class="text-xxs">ACU</small></div>
              <div class="flex flex-col justify-center">'.$comp_t0.'<small class="text-xxs">COMP</small></div>
              <div>'. $lit_cmp_t0 .'</div>
              <div>'. $rem_t0 .'</div>
            </div>

            <!-- T1 -->
            <div class="flex items-start p-2 border rounded-lg gap-x-2 border-slate-200/80">
              <div class="text-xl font-bold text-slate-800">T1</div>
              <div class="flex flex-col justify-center">'.$acc_t1.'<small class="text-xxs">ACU</small></div>
              <div class="flex flex-col justify-center">'.$comp_t1.'<small class="text-xxs">COMP</small></div>
              <div>'. $lit_cmp_t1 .'</div>
              <div>'. $rem_t1 .'</div>
            </div>

            <!-- Medie etape -->
            <div class="flex items-start p-2 border rounded-lg gap-x-2 border-slate-200/80">
              <div class="text-xl font-bold text-slate-800">AVG</div>
              <div class="flex flex-col justify-center">'.$acc_av.'<small class="text-xxs">ACU</small></div>
              <div class="flex flex-col justify-center">'.$comp_av.'<small class="text-xxs">COMP</small></div>
              <div>'. $lit_cmp_av .'</div>
              <div>'. $rem_av .'</div>
            </div>
          </div>
        </div>

      </div>

    </div>
  </div>
  ';
}

echo '</div>'; // listă carduri
echo '</div>'; // wrapper
