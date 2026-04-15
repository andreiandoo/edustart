<?php
/** UI pentru tab-ul SEL. Depinde de variabilele din helper-ul SEL + funcțiile badge/delta/status. */
?>
<!-- Rezumat compact -->
<section>
  <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
    <!-- T0 -->
    <div class="p-4 bg-white border rounded-xl">
      <div class="flex items-center justify-between">
        <span class="text-sm font-bold text-slate-800">T0</span>
        <?= status_chip($status['t0']) ?>
      </div>
      <div class="flex items-center justify-between mt-2 text-sm">
        <span class="text-gray-500">Completare</span>
        <span class="font-semibold"><?= $completion['t0']!==null?intval($completion['t0']).'%':'—' ?></span>
      </div>
      <div class="flex items-center justify-between mt-2">
        <span class="text-sm text-gray-500">Media etapă</span>
        <span class="ml-2"><?= badge_score($sel_total_t0) ?></span>
      </div>
      <div class="flex flex-wrap gap-2 mt-2 text-xs">
        <span class="text-gray-500">Δ către:</span>
        <span><?= delta_chip(($sel_total_ti!==null&&$sel_total_t0!==null)?$sel_total_ti-$sel_total_t0:null) ?> <span class="text-gray-400">Ti</span></span>
        <span><?= delta_chip(($sel_total_t1!==null&&$sel_total_t0!==null)?$sel_total_t1-$sel_total_t0:null) ?> <span class="text-gray-400">T1</span></span>
      </div>
    </div>

    <!-- Ti -->
    <div class="p-4 bg-white border rounded-xl">
      <div class="flex items-center justify-between">
        <span class="text-sm font-bold text-slate-800">Ti</span>
        <?= status_chip($status['ti']) ?>
      </div>
      <div class="flex items-center justify-between mt-2 text-sm">
        <span class="text-gray-500">Completare</span>
        <span class="font-semibold"><?= $completion['ti']!==null?intval($completion['ti']).'%':'—' ?></span>
      </div>
      <div class="flex items-center justify-between mt-2">
        <span class="text-sm text-gray-500">Media etapă</span>
        <span class="ml-2"><?= badge_score($sel_total_ti) ?></span>
      </div>
      <div class="flex flex-wrap gap-2 mt-2 text-xs">
        <span class="text-gray-500">Δ:</span>
        <span><?= delta_chip($sel_total_t0!==null&&$sel_total_ti!==null?$sel_total_ti-$sel_total_t0:null) ?> <span class="text-gray-400">vs T0</span></span>
        <span><?= delta_chip($sel_total_t1!==null&&$sel_total_ti!==null?$sel_total_t1-$sel_total_ti:null) ?> <span class="text-gray-400">→ T1</span></span>
      </div>
    </div>

    <!-- T1 -->
    <div class="p-4 bg-white border rounded-xl">
      <div class="flex items-center justify-between">
        <span class="text-sm font-bold text-slate-800">T1</span>
        <?= status_chip($status['t1']) ?>
      </div>
      <div class="flex items-center justify-between mt-2 text-sm">
        <span class="text-gray-500">Completare</span>
        <span class="font-semibold"><?= $completion['t1']!==null?intval($completion['t1']).'%':'—' ?></span>
      </div>
      <div class="flex items-center justify-between mt-2">
        <span class="text-sm text-gray-500">Media etapă</span>
        <span class="ml-2"><?= badge_score($sel_total_t1) ?></span>
      </div>
      <div class="flex flex-wrap gap-2 mt-2 text-xs">
        <span class="text-gray-500">Δ:</span>
        <span><?= delta_chip($delta_total_t1_ti) ?> <span class="text-gray-400">vs Ti</span></span>
        <span><?= delta_chip($delta_total_t1_t0) ?> <span class="text-gray-400">vs T0</span></span>
      </div>
    </div>

    <!-- Media generală elev -->
    <div class="p-4 text-right bg-white border rounded-xl">
      <div class="flex flex-col items-end text-lg font-bold text-slate-800">Media generală <small class="font-normal text-slate-500">(toate etapele)</small></div>
      <div class="mt-2"><?= badge_score($sel_total_overall,'xl') ?></div>
    </div>
  </div>
</section>

<!-- Tabel capitole -->
<section class="bg-white border">
  <div class="overflow-x-auto">
    <table class="min-w-[1250px] w-full text-sm">
      <thead>
        <tr class="text-slate-800">
          <th rowspan="2" class="px-3 py-2 text-left align-bottom">Capitol</th>

          <th colspan="3" class="px-3 py-2 text-center align-bottom border-l-2 border-r-2 border-slate-200">Etapa T0</th>
          <th colspan="3" class="px-3 py-2 text-center align-bottom border-r-2 border-slate-200">Etapa Ti</th>
          <th colspan="3" class="px-3 py-2 text-center align-bottom border-r-2 border-slate-200">Etapa T1</th>
          <th colspan="3" class="px-3 py-2 text-center align-bottom border-r border-slate-400">Δ între etape</th>
          <th colspan="2" class="px-3 py-2 text-center text-white align-bottom border-t border-r border-slate-400 bg-slate-800">Medie / capitol</th>
        </tr>
        <tr class="text-gray-500 border-b">
          <!-- T0 -->
          <th class="px-3 py-2 text-center border-l-2 border-slate-200">Elev</th>
          <th class="px-3 py-2 text-center">Δ</th>
          <th class="px-3 py-2 text-center border-r-2 border-slate-200">Generație</th>
          <!-- Ti -->
          <th class="px-3 py-2 text-center">Elev</th>
          <th class="px-3 py-2 text-center">Δ</th>
          <th class="px-3 py-2 text-center border-r-2 border-slate-200">Generație</th>
          <!-- T1 -->
          <th class="px-3 py-2 text-center">Elev</th>
          <th class="px-3 py-2 text-center">Δ</th>
          <th class="px-3 py-2 text-center border-r-2 border-slate-200">Generație</th>
          <!-- Δ între etape -->
          <th class="px-3 py-2 text-center">Ti−T0</th>
          <th class="px-3 py-2 text-center">T1−Ti</th>
          <th class="px-3 py-2 text-center border-r border-slate-400">T1−T0</th>
          <!-- Medie -->
          <th class="px-3 py-2 text-center text-slate-800 bg-slate-100">Elev</th>
          <th class="px-3 py-2 text-center border-r border-slate-400 text-slate-800 bg-slate-100">Generație</th>
        </tr>
      </thead>

      <tbody class="divide-y">
        <?php foreach($SEL_CHAPTERS as $cap):
          $v0s=$map_t0[$cap]; $vis=$map_ti[$cap]; $v1s=$map_t1[$cap];
          $v0g=$gen_t0_avg[$cap] ?? null; $vig=$gen_ti_avg[$cap] ?? null; $v1g=$gen_t1_avg[$cap] ?? null;
          $d01=$delta_ti_t0[$cap]; $di1=$delta_t1_ti[$cap]; $d0_1=$delta_t1_t0[$cap];
          $stud_avg = avg_non_null([$v0s,$vis,$v1s]);
          $gen_avg  = $gen_all_avg[$cap] ?? null;
          $d_t0_sg = ($v0s!==null && $v0g!==null) ? $v0s-$v0g : null;
          $d_ti_sg = ($vis!==null && $vig!==null) ? $vis-$vig : null;
          $d_t1_sg = ($v1s!==null && $v1g!==null) ? $v1s-$v1g : null;
        ?>
        <tr>
          <td class="px-3 py-2 font-medium text-gray-900"><?= esc_html($cap) ?></td>

          <!-- T0 -->
          <td class="px-3 py-2 text-center border-l-2 border-slate-200"><?= badge_score($v0s) ?></td>
          <td class="px-3 py-2 text-center"><?= delta_chip($d_t0_sg) ?></td>
          <td class="px-3 py-2 text-center border-r-2 border-slate-200"><?= badge_score($v0g) ?></td>

          <!-- Ti -->
          <td class="px-3 py-2 text-center"><?= badge_score($vis) ?></td>
          <td class="px-3 py-2 text-center"><?= delta_chip($d_ti_sg) ?></td>
          <td class="px-3 py-2 text-center border-r-2 border-slate-200"><?= badge_score($vig) ?></td>

          <!-- T1 -->
          <td class="px-3 py-2 text-center"><?= badge_score($v1s) ?></td>
          <td class="px-3 py-2 text-center"><?= delta_chip($d_t1_sg) ?></td>
          <td class="px-3 py-2 text-center border-r-2 border-slate-200"><?= badge_score($v1g) ?></td>

          <!-- Δ între etape -->
          <td class="px-3 py-2 text-center"><?= delta_chip($d01) ?></td>
          <td class="px-3 py-2 text-center"><?= delta_chip($di1) ?></td>
          <td class="px-3 py-2 text-center border-r border-slate-400"><?= delta_chip($d0_1) ?></td>

          <!-- Medie / capitol -->
          <td class="px-3 py-2 font-semibold text-center bg-slate-100"><?= badge_score($stud_avg) ?></td>
          <td class="px-3 py-2 font-semibold text-center border-r border-slate-400 bg-slate-100"><?= badge_score($gen_avg) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>

      <tfoot class="border border-solid border-slate-400">
        <tr class="font-semibold bg-slate-100">
          <td class="px-3 py-2 text-white bg-slate-800">Medie</td>

          <!-- T0 -->
          <td class="px-3 py-2 text-center border-l border-slate-400"><?= badge_score($footer_stud_t0) ?></td>
          <td class="px-3 py-2 text-center"><?= delta_chip($footer_delta_t0) ?></td>
          <td class="px-3 py-2 text-center border-r border-slate-400"><?= badge_score($footer_gen_t0) ?></td>

          <!-- Ti -->
          <td class="px-3 py-2 text-center"><?= badge_score($footer_stud_ti) ?></td>
          <td class="px-3 py-2 text-center"><?= delta_chip($footer_delta_ti) ?></td>
          <td class="px-3 py-2 text-center border-r border-slate-400"><?= badge_score($footer_gen_ti) ?></td>

          <!-- T1 -->
          <td class="px-3 py-2 text-center"><?= badge_score($footer_stud_t1) ?></td>
          <td class="px-3 py-2 text-center"><?= delta_chip($footer_delta_t1) ?></td>
          <td class="px-3 py-2 text-center border-r border-slate-400"><?= badge_score($footer_gen_t1) ?></td>

          <!-- Δ între etape -->
          <td class="px-3 py-2 text-center"><?= delta_chip($delta_total_ti_t0) ?></td>
          <td class="px-3 py-2 text-center"><?= delta_chip($delta_total_t1_ti) ?></td>
          <td class="px-3 py-2 text-center border-r border-slate-400"><?= delta_chip($delta_total_t1_t0) ?></td>

          <!-- Medie / capitol -->
          <td class="px-3 py-2 text-center"><?= badge_score($footer_stud_avg_cap) ?></td>
          <td class="px-3 py-2 text-center border-r border-slate-400"><?= badge_score($footer_gen_avg_cap) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <p class="px-4 py-2 text-xs text-gray-500">
    Mediile generației/clasei sunt calculate dinamic pe fiecare etapă (T0/Ti/T1) și pe ansamblu, pentru toți elevii din aceeași generație/clasă.
  </p>
</section>
