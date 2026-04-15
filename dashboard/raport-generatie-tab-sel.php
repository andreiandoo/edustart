<?php
/** Raport generație — SEL (UI) */
if (!defined('ABSPATH')) exit;

/* --- utilitare vizuale --- */
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
if (!function_exists('delta_badge_class')) {
  function delta_badge_class($d){
    if ($d===null) return 'bg-slate-100 text-slate-700';
    $x = floatval($d);
    if ($x>0) return 'bg-green-100 text-green-700';
    if ($x<0) return 'bg-red-100 text-red-700';
    return 'bg-gray-100 text-gray-700';
  }
}
if (!function_exists('fmt_delta')) {
  function fmt_delta($d){
    if ($d===null) return '—';
    $x=floatval($d);
    return ($x>0?'+':'').number_format($x,2);
  }
}
?>

<!-- ============================ SEL — REZUMAT ============================ -->
<section class="p-6 bg-white border rounded-2xl">
    <div class="flex items-center mb-4 gap-x-4">
        <h2 class="text-xl font-semibold">SEL — Rezumat pe generație</h2>
        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-xl bg-sky-50 text-sky-800 ring-1 ring-inset ring-sky-200">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4 text-sky-600">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
            </svg>
            <span class="font-medium">Grad completare:</span>
            <span class="font-semibold"><?= isset($sel_completion_allStages) ? number_format($sel_completion_allStages, 0) : '—' ?>%</span>
        </span>
    </div>

  <div class="grid gap-4 sm:grid-cols-5">
    <!-- Media generală (toate etapele) -->
    <div class="hidden p-4 rounded-xl bg-gray-50">
      <div class="text-sm text-gray-500">Media generală</div>
      <div class="text-2xl font-bold">
        <?= $sel_overall_allStages_avg!==null ? number_format($sel_overall_allStages_avg,2) : '—' ?>
      </div>
    </div>

    <!-- Capitole: medie pe toate etapele -->
    <?php foreach ($SEL_CHAPTERS as $cap):
      $v = $sel_gen_chapter_avg_allStages[$cap] ?? null; ?>
      <div class="p-4 rounded-xl <?= esc_attr(bg_score_class($v)) ?>">
        <div class="text-sm opacity-90"><?= esc_html($cap) ?></div>
        <div class="text-2xl font-bold"><?= $v!==null?number_format($v, 2):'—' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>


<!-- ============== SEL — Medii pe capitole pe etape + Δ între etape ========= -->
<section class="p-6 mt-4 bg-white border rounded-2xl">
  <h2 class="mb-4 text-xl font-semibold">SEL — Medii pe capitole pe etape</h2>
  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-left text-gray-500 border-b">
          <th class="py-2 pr-4">Etapă</th>
          <th class="py-2 pr-4">Grad completare</th>
          <?php foreach($SEL_CHAPTERS as $cap): ?>
            <th class="py-2 pr-4"><?= esc_html($cap) ?></th>
          <?php endforeach; ?>
          <th class="py-2 pr-4 font-semibold">Scor SEL</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <!-- T0 -->
        <tr>
          <td class="py-2 pr-4 font-medium">T0</td>
          <td class="py-2 pr-4">
            <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-200">
              <?= isset($sel_completion_t0) ? number_format($sel_completion_t0, 0) : '—' ?>%
            </span>
          </td>
          <?php foreach($SEL_CHAPTERS as $cap):
            $v = $sel_t0_avg_chapters[$cap] ?? null; ?>
            <td class="py-2 pr-4">
              <span class="px-2 py-1 rounded <?= esc_attr(bg_score_class($v)) ?>"><?= $v!==null?number_format($v,2):'—' ?></span>
            </td>
          <?php endforeach; ?>
          <td class="py-2 pr-4">
            <span class="px-2 py-1 rounded bg-slate-100 text-slate-800">
              <?= $sel_stage_overall_avg['t0']!==null?number_format($sel_stage_overall_avg['t0'],2):'—' ?>
            </span>
          </td>
        </tr>

        <!-- Ti -->
        <tr>
          <td class="py-2 pr-4 font-medium">Ti</td>
          <td class="py-2 pr-4">
            <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-200">
              <?= isset($sel_completion_ti) ? number_format($sel_completion_ti, 0) : '—' ?>%
            </span>
          </td>
          <?php foreach($SEL_CHAPTERS as $cap):
            $v = $sel_ti_avg_chapters[$cap] ?? null;
            $d = $sel_delta['ti_t0'][$cap] ?? null; ?>
            <td class="py-2 pr-4">
              <div class="flex items-center gap-1">
                <span class="px-2 py-1 rounded <?= esc_attr(bg_score_class($v)) ?>"><?= $v!==null?number_format($v,2):'—' ?></span>
                <span class="px-1.5 py-0.5 rounded text-[11px] <?= esc_attr(delta_badge_class($d)) ?>">Δ T0 <?= fmt_delta($d) ?></span>
              </div>
            </td>
          <?php endforeach; ?>
          <td class="py-2 pr-4">
            <?php $dg = ($sel_stage_overall_avg['ti']??null)!==null && ($sel_stage_overall_avg['t0']??null)!==null
                ? ($sel_stage_overall_avg['ti'] - $sel_stage_overall_avg['t0']) : null; ?>
            <div class="flex items-center gap-1">
              <span class="px-2 py-1 rounded bg-slate-100 text-slate-800">
                <?= $sel_stage_overall_avg['ti']!==null?number_format($sel_stage_overall_avg['ti'],2):'—' ?>
              </span>
              <span class="px-1.5 py-0.5 rounded text-[11px] <?= esc_attr(delta_badge_class($dg)) ?>">Δ T0 <?= fmt_delta($dg) ?></span>
            </div>
          </td>
        </tr>

        <!-- T1 -->
        <tr>
          <td class="py-2 pr-4 font-medium">T1</td>
          <td class="py-2 pr-4">
            <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-200">
              <?= isset($sel_completion_t1) ? number_format($sel_completion_t1, 0) : '—' ?>%
            </span>
          </td>
          <?php foreach($SEL_CHAPTERS as $cap):
            $v  = $sel_t1_avg_chapters[$cap] ?? null;
            $d1 = $sel_delta['t1_ti'][$cap] ?? null;
            $d0 = $sel_delta['t1_t0'][$cap] ?? null; ?>
            <td class="py-2 pr-4">
              <div class="flex flex-wrap items-center gap-1">
                <span class="px-2 py-1 rounded <?= esc_attr(bg_score_class($v)) ?>"><?= $v!==null?number_format($v,2):'—' ?></span>
                <span class="px-1.5 py-0.5 rounded text-[11px] <?= esc_attr(delta_badge_class($d1)) ?>">Δ Ti <?= fmt_delta($d1) ?></span>
                <span class="px-1.5 py-0.5 rounded text-[11px] <?= esc_attr(delta_badge_class($d0)) ?>">Δ T0 <?= fmt_delta($d0) ?></span>
              </div>
            </td>
          <?php endforeach; ?>
          <td class="py-2 pr-4">
            <?php
              $dg1 = ($sel_stage_overall_avg['t1']??null)!==null && ($sel_stage_overall_avg['ti']??null)!==null
                   ? ($sel_stage_overall_avg['t1'] - $sel_stage_overall_avg['ti']) : null;
              $dg0 = ($sel_stage_overall_avg['t1']??null)!==null && ($sel_stage_overall_avg['t0']??null)!==null
                   ? ($sel_stage_overall_avg['t1'] - $sel_stage_overall_avg['t0']) : null;
            ?>
            <div class="flex flex-wrap items-center gap-1">
              <span class="px-2 py-1 rounded bg-slate-100 text-slate-800">
                <?= $sel_stage_overall_avg['t1']!==null?number_format($sel_stage_overall_avg['t1'],2):'—' ?>
              </span>
              <span class="px-1.5 py-0.5 rounded text-[11px] <?= esc_attr(delta_badge_class($dg1)) ?>">Δ Ti <?= fmt_delta($dg1) ?></span>
              <span class="px-1.5 py-0.5 rounded text-[11px] <?= esc_attr(delta_badge_class($dg0)) ?>">Δ T0 <?= fmt_delta($dg0) ?></span>
            </div>
          </td>
        </tr>
      </tbody>
      <tfoot>
        <tr class="border-t">
            <td class="py-2 pr-4 font-semibold">Medie (toate etapele)</td>
            <td class="py-2 pr-4">
              <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-bold rounded bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-300">
                <?= isset($sel_completion_allStages) ? number_format($sel_completion_allStages, 0) : '—' ?>%
              </span>
            </td>
            <?php foreach ($SEL_CHAPTERS as $cap):
            $v = $sel_gen_chapter_avg_allStages[$cap] ?? null; ?>
            <td class="py-2 pr-4">
                <span class="px-2 py-1 text-lg font-bold rounded bg-slate-100 text-slate-800"><?= $v!==null?number_format($v,2):'—' ?></span>
            </td>
            <?php endforeach; ?>
            <td class="py-2 pr-4">
            <span class="px-2 py-1 text-lg font-bold rounded bg-slate-100 text-slate-800">
                <?= $sel_overall_allStages_avg!==null?number_format($sel_overall_allStages_avg,2):'—' ?>
            </span>
            </td>
        </tr>
       </tfoot>
    </table>
  </div>
</section>

<!-- ======== SEL — Rezultate individuale (pe capitole) ======= -->
<?php $caps_js = wp_json_encode(array_values($SEL_CHAPTERS), JSON_UNESCAPED_UNICODE); ?>

<section
  x-data='selCapFilter(<?= $caps_js ?>)'
  class="p-6 mt-4 bg-white border rounded-2xl"
>
  <div class="flex flex-col gap-3 mb-4 sm:flex-row sm:items-center sm:justify-between">
    <h2 class="text-xl font-semibold">SEL — Rezultate individuale (pe capitole)</h2>

    <!-- Filtru pe capitole (checkbox-uri + butoane) -->
    <div class="flex flex-wrap items-center gap-3">
      <template x-for="c in caps" :key="c">
        <label class="inline-flex items-center gap-2 text-sm">
          <input type="checkbox" class="w-4 h-4 border rounded border-slate-300 bg-slate-300 text-emerald-600 focus:ring-0 focus:outline-none focus:border-transparent"
                 :checked="selected[c]" @change="toggle(c)">
          <span x-text="c" class="text-sm text-slate-600" :class="{'text-slate-800 font-semibold': selected[c]}"></span>
        </label>
      </template>
      <div class="w-px h-5 mx-1 bg-slate-300"></div>
      <button type="button" @click="selectAll()" class="px-2.5 py-1 text-xs border rounded-lg bg-white hover:bg-slate-50">
        Selectează toate
      </button>
      <button type="button" @click="clearAll()" class="px-2.5 py-1 text-xs border rounded-lg bg-white hover:bg-slate-50">
        Golește
      </button>
    </div>
  </div>

    <table class="min-w-full text-xs align-top md:text-sm">
      <thead class="sticky top-[52px] z-10 bg-white shadow-[0_1px_0_0_theme(colors.slate.200)]">
        <tr class="text-left text-gray-500">
          <th class="py-2 pr-4">Elev</th>
          <th class="py-2 pr-4">Capitol</th>
          <th class="py-2 pr-4">T0</th>
          <th class="py-2 pr-4">Ti</th>
          <th class="py-2 pr-4">T1</th>
          <th class="py-2 pr-4">Medie</th>
          <th class="py-2 pr-4">Δ (Ti−T0)</th>
          <th class="py-2 pr-4">Δ (T1−Ti)</th>
          <th class="py-2 pr-4">Δ (T1−T0)</th>
          <th class="py-2 pr-4">vs. Generație</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php
        if (!function_exists('edus_avg3_non_null')) {
        function edus_avg3_non_null($a,$b,$c){
            $vals=[]; if($a!==null && $a!=='') $vals[]=(float)$a;
            if($b!==null && $b!=='') $vals[]=(float)$b;
            if($c!==null && $c!=='') $vals[]=(float)$c;
            return count($vals)? array_sum($vals)/count($vals) : null;
        }
        }

        if (!empty($students)):
            foreach ($students as $s):
                $sid   = intval($s->id);
                $name  = $student_name[$sid] ?? trim(($s->last_name ?? '').' '.($s->first_name ?? '')) ?: ('Elev #'.$sid);

                // Hărți T0/Ti/T1 pe capitole
                $t0 = $ti = $t1 = array_fill_keys($SEL_CHAPTERS, null);
                if (!empty($SEL_perStage[$sid]['sel-t0']['row'])) $t0 = edus_sel_parse_chapter_map($SEL_perStage[$sid]['sel-t0']['row'], $SEL_CHAPTERS);
                if (!empty($SEL_perStage[$sid]['sel-ti']['row'])) $ti = edus_sel_parse_chapter_map($SEL_perStage[$sid]['sel-ti']['row'], $SEL_CHAPTERS);
                if (!empty($SEL_perStage[$sid]['sel-t1']['row'])) $t1 = edus_sel_parse_chapter_map($SEL_perStage[$sid]['sel-t1']['row'], $SEL_CHAPTERS);

                // Rând “header” elev – rămâne mereu vizibil
                ?>
                <tr class="bg-slate-50/60">
                    <td class="py-2 pr-4 font-medium" colspan="10">
                        <a class="flex items-center group gap-x-2 hover:text-blue-600" href="<?= esc_url(home_url('/panou/raport/elev/'.$sid)) ?>">
                            <?= esc_html($name) ?>
                            <span class="text-xs text-sky-600"> - vezi raportul elevului</span>
                        </a>
                    </td>
                </tr>
                <?php
                // Apoi rândurile pe capitole – filtrabile
                foreach ($SEL_CHAPTERS as $cap):
                $a = $t0[$cap]; $b = $ti[$cap]; $c = $t1[$cap];

                // Medie elev pe capitol (din T0/Ti/T1 disponibile)
                $stud_cap_avg = edus_avg3_non_null($a,$b,$c);

                // Medie generație pe capitol (toate etapele)
                $gen_cap_avg  = $sel_gen_chapter_avg_allStages[$cap] ?? null;

                // Δ elev − gen (medie pe capitol)
                $delta_vs_gen_cap = ($stud_cap_avg!==null && $gen_cap_avg!==null) ? ($stud_cap_avg - $gen_cap_avg) : null;
                ?>
                <tr x-show="show('<?= esc_attr($cap) ?>')" x-cloak>
                    <td class="py-2 pr-4"></td>
                    <td class="py-2 pr-4"><?= esc_html($cap) ?></td>
                    <td class="py-2 pr-4"><?= $a!==null?'<span class="px-2 py-1 rounded '.esc_attr(bg_score_class($a)).'">'.number_format($a,2).'</span>':'—' ?></td>
                    <td class="py-2 pr-4"><?= $b!==null?'<span class="px-2 py-1 rounded '.esc_attr(bg_score_class($b)).'">'.number_format($b,2).'</span>':'—' ?></td>
                    <td class="py-2 pr-4"><?= $c!==null?'<span class="px-2 py-1 rounded '.esc_attr(bg_score_class($c)).'">'.number_format($c,2).'</span>':'—' ?></td>

                    <td class="py-2 pr-4"><?= $stud_cap_avg!==null?number_format($stud_cap_avg,2):'—' ?></td>

                    <td class="py-2 pr-4"><?= ($b!==null&&$a!==null)?'<span class="px-2 py-1 rounded '.esc_attr(delta_badge_class($b-$a)).'">'.number_format($b-$a,2).'</span>':'—' ?></td>
                    <td class="py-2 pr-4"><?= ($c!==null&&$b!==null)?'<span class="px-2 py-1 rounded '.esc_attr(delta_badge_class($c-$b)).'">'.number_format($c-$b,2).'</span>':'—' ?></td>
                    <td class="py-2 pr-4"><?= ($c!==null&&$a!==null)?'<span class="px-2 py-1 rounded '.esc_attr(delta_badge_class($c-$a)).'">'.number_format($c-$a,2).'</span>':'—' ?></td>

                    <td class="py-2 pr-4">
                      <div class="flex items-center gap-1">
                        <span class="text-slate-500"><?= $gen_cap_avg!==null?number_format($gen_cap_avg,2):'—' ?></span>
                        <span class="px-1.5 py-0.5 rounded text-[11px] <?= esc_attr(delta_badge_class($delta_vs_gen_cap)) ?>"><?= $delta_vs_gen_cap!==null ? (($delta_vs_gen_cap>0?'+':'').number_format($delta_vs_gen_cap,2)) : '—' ?></span>
                      </div>
                    </td>
                </tr>
                <?php endforeach; // capitole

                // Scor SEL = media pe capitole, per etapă și overall
                $s_t0_vals = array_filter(array_map(fn($c) => $t0[$c], $SEL_CHAPTERS), fn($v) => $v !== null);
                $s_ti_vals = array_filter(array_map(fn($c) => $ti[$c], $SEL_CHAPTERS), fn($v) => $v !== null);
                $s_t1_vals = array_filter(array_map(fn($c) => $t1[$c], $SEL_CHAPTERS), fn($v) => $v !== null);
                $s_t0_avg = count($s_t0_vals) ? array_sum($s_t0_vals) / count($s_t0_vals) : null;
                $s_ti_avg = count($s_ti_vals) ? array_sum($s_ti_vals) / count($s_ti_vals) : null;
                $s_t1_avg = count($s_t1_vals) ? array_sum($s_t1_vals) / count($s_t1_vals) : null;
                $s_all_vals = array_filter([$s_t0_avg, $s_ti_avg, $s_t1_avg], fn($v) => $v !== null);
                $s_overall  = count($s_all_vals) ? array_sum($s_all_vals) / count($s_all_vals) : null;
                $gen_overall = $sel_overall_allStages_avg ?? null;
                $s_delta_gen = ($s_overall !== null && $gen_overall !== null) ? ($s_overall - $gen_overall) : null;
                ?>
                <tr class="bg-slate-100/80 border-t border-slate-300">
                    <td class="py-2 pr-4"></td>
                    <td class="py-2 pr-4 font-semibold text-slate-700">Scor SEL</td>
                    <td class="py-2 pr-4"><?= $s_t0_avg !== null ? '<span class="px-2 py-1 rounded font-semibold '.esc_attr(bg_score_class($s_t0_avg)).'">'.number_format($s_t0_avg,2).'</span>' : '—' ?></td>
                    <td class="py-2 pr-4"><?= $s_ti_avg !== null ? '<span class="px-2 py-1 rounded font-semibold '.esc_attr(bg_score_class($s_ti_avg)).'">'.number_format($s_ti_avg,2).'</span>' : '—' ?></td>
                    <td class="py-2 pr-4"><?= $s_t1_avg !== null ? '<span class="px-2 py-1 rounded font-semibold '.esc_attr(bg_score_class($s_t1_avg)).'">'.number_format($s_t1_avg,2).'</span>' : '—' ?></td>
                    <td class="py-2 pr-4"><span class="px-2 py-1 font-bold rounded bg-slate-200 text-slate-800"><?= $s_overall !== null ? number_format($s_overall,2) : '—' ?></span></td>
                    <?php
                      $sd_ti_t0 = ($s_ti_avg !== null && $s_t0_avg !== null) ? ($s_ti_avg - $s_t0_avg) : null;
                      $sd_t1_ti = ($s_t1_avg !== null && $s_ti_avg !== null) ? ($s_t1_avg - $s_ti_avg) : null;
                      $sd_t1_t0 = ($s_t1_avg !== null && $s_t0_avg !== null) ? ($s_t1_avg - $s_t0_avg) : null;
                    ?>
                    <td class="py-2 pr-4"><span class="px-2 py-1 rounded text-xs <?= esc_attr(delta_badge_class($sd_ti_t0)) ?>"><?= fmt_delta($sd_ti_t0) ?></span></td>
                    <td class="py-2 pr-4"><span class="px-2 py-1 rounded text-xs <?= esc_attr(delta_badge_class($sd_t1_ti)) ?>"><?= fmt_delta($sd_t1_ti) ?></span></td>
                    <td class="py-2 pr-4"><span class="px-2 py-1 rounded text-xs <?= esc_attr(delta_badge_class($sd_t1_t0)) ?>"><?= fmt_delta($sd_t1_t0) ?></span></td>
                    <td class="py-2 pr-4">
                      <div class="flex items-center gap-1">
                        <span class="text-slate-500"><?= $gen_overall !== null ? number_format($gen_overall,2) : '—' ?></span>
                        <span class="px-1.5 py-0.5 rounded text-[11px] <?= esc_attr(delta_badge_class($s_delta_gen)) ?>"><?= $s_delta_gen !== null ? (($s_delta_gen>0?'+':'').number_format($s_delta_gen,2)) : '—' ?></span>
                      </div>
                    </td>
                </tr>
            <?php endforeach; // elevi
        else: ?>
            <tr><td colspan="10" class="py-4 text-gray-500">Nu există elevi în această generație.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
</section>

<script>
document.addEventListener('alpine:init', () => {
  Alpine.data('selCapFilter', (caps) => ({
    caps,
    selected: {},
    init(){ this.caps.forEach(c => this.selected[c] = true); },
    show(c){ return !!this.selected[c]; },
    toggle(c){ this.selected[c] = !this.selected[c]; },
    selectAll(){ Object.keys(this.selected).forEach(k => this.selected[k] = true); },
    clearAll(){ Object.keys(this.selected).forEach(k => this.selected[k] = false); },
  }));
});

</script>



