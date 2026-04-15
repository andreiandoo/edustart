<?php
/**
 * Panou — Raport generație (wrapper)
 * Disponibile din template:
 *   - $generation_id (int)
 *   - $GEN (array cu meta generație)
 *
 * Acest partial strânge datele brute (elevi + rezultate),
 * apoi include helper-ele și UI-urile pentru SEL și LIT.
 */

if (!defined('ABSPATH')) exit;
global $wpdb;

// --- Siguranță parametru
$generation_id = isset($generation_id) ? (int)$generation_id : (int)(get_query_var('generation_id') ?: ($_GET['generation_id'] ?? 0));
if (!$generation_id) {
  echo '<div class="p-6"><p class="text-red-600">Generație invalidă.</p></div>';
  return;
}

// --- Tabele
$tbl_students = $wpdb->prefix . 'edu_students';
$tbl_results  = $wpdb->prefix . 'edu_results';

// --- Elevii din generație
$students = $wpdb->get_results($wpdb->prepare(
  "SELECT id, first_name, last_name, gender, age, class_label
   FROM {$tbl_students}
   WHERE generation_id = %d
   ORDER BY last_name, first_name",
  $generation_id
));
$student_ids    = array_map(fn($s) => (int)$s->id, $students);
$total_students = count($students);
$student_name   = [];
foreach ($students as $s) {
  $student_name[(int)$s->id] = trim(($s->last_name ?? '') . ' ' . ($s->first_name ?? ''));
}

// --- Rezultatele pentru toți elevii generației
$rowsRaw = [];
if ($student_ids) {
  $placeholders = implode(',', array_fill(0, count($student_ids), '%d'));
  // luăm coloanele utile; dacă există updated_at/created_at, le includem (compat)
  $cols     = $wpdb->get_results("SHOW COLUMNS FROM {$tbl_results}");
  $colnames = array_map(fn($c) => $c->Field, $cols);
  $select   = ['id','student_id','modul_type','modul','results','score','completion','status','class_id'];
  if (in_array('updated_at', $colnames, true)) $select[] = 'updated_at';
  if (in_array('created_at', $colnames, true)) $select[] = 'created_at';

  $rowsRaw = $wpdb->get_results($wpdb->prepare(
    "SELECT ".implode(', ', $select)." FROM {$tbl_results}
     WHERE student_id IN ({$placeholders})",
    ...$student_ids
  ));
}

// --- Utilitar ordonare cronologică (folosit în helper-ele SEL/LIT)
if (!function_exists('row_order_key')) {
  function row_order_key($r) {
    if (!empty($r->updated_at)) return 't:' . strtotime($r->updated_at);
    if (!empty($r->created_at)) return 'c:' . strtotime($r->created_at);
    return 'i:' . (int)($r->id ?? 0);
  }
}

// --- Utilități comune UI (dacă nu sunt deja definite global)
if (!function_exists('pct_badge')) {
  function pct_badge($pct, $size='sm'){
    if ($pct===null) return '<span class="inline-flex items-center px-2 py-0.5 text-xs rounded bg-slate-200 text-slate-700">—</span>';
    $v = max(0,min(100, round($pct)));
    $cls = $v>=75 ? 'bg-emerald-600 text-white' : ($v>=50 ? 'bg-amber-500 text-white' : 'bg-rose-600 text-white');
    $pad = $size==='xl' ? 'px-3 py-1 text-base' : ($size==='sm' ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-0.5 text-sm');
    return '<span class="inline-flex items-center '.$pad.' rounded '.$cls.'">'.$v.'%</span>';
  }
}
if (!function_exists('delta_chip')) {
  function delta_chip($delta, $size='xs'){
    if ($delta===null) return '<span class="inline-flex items-center px-2 py-0.5 text-xs rounded bg-slate-200 text-slate-600">—</span>';
    $cls = $delta>0 ? 'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200' :
           ($delta<0 ? 'bg-rose-100 text-rose-800 ring-1 ring-rose-200' :
                       'bg-slate-100 text-slate-700 ring-1 ring-slate-200');
    $pad = $size==='sm' ? 'px-2 py-0.5 text-xs' : 'px-1.5 py-0.5 text-[11px]';
    $lab = ($delta>0?'+':'').(is_numeric($delta) ? number_format($delta, 2) : (string)$delta);
    return '<span class="inline-flex items-center '.$pad.' rounded '.$cls.'">'.$lab.'</span>';
  }
}
if (!function_exists('badge_score')) {
  function badge_score($v){
    if ($v===null || $v==='') return '—';
    return '<span class="inline-flex items-center px-2 py-0.5 rounded bg-slate-800 text-white text-xs">'.(is_numeric($v)?(strpos((string)$v,'.')===false?number_format($v,0):number_format($v,2)):$v).'</span>';
  }
}

// ------------------------------------------------------------
// Include HELPER-ele (calcule) – expun variabile pentru UI
// ------------------------------------------------------------
require_once get_template_directory().'/dashboard/raport-generatie-helper-sel.php';
require_once get_template_directory().'/dashboard/raport-generatie-helper-lit.php';

$gen_id   = intval(get_query_var('generation_id') ?: ($generation->id ?? 0));
$gen_name = trim($generation->name ?? "Generație #{$gen_id}");

if($viewer_mode === 'TUTOR') :
  $back_url = home_url('/dashboard/generatii');
elseif($viewer_mode === 'PROF') :
  $back_url = home_url('/lista');
else :
  $back_url = home_url('/dashboard/generatii');
endif;

// ------------------------------------------------------------
// UI — container cu tab-uri + fiecare tab include UI-ul propriu
// ------------------------------------------------------------
?>

<section class="sticky top-0 z-20 border-b inner-submenu bg-slate-800 border-slate-200">
  <div class="relative z-20 flex items-center justify-between px-2 py-2 gap-x-2">
    <div class="flex items-center justify-start">
      <a href="<?= esc_url($back_url) ?>"
          class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
        <span class="transition-transform duration-150 ease-in-out group-hover:-translate-x-1">←</span> Înapoi la elevi
      </a>
      <span class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
        </svg>
        <span class="font-semibold"><?= intval($total_students ?? 0) ?> elevi în generație/clasă</span>
      </span>
    </div>

    <div class="flex items-center justify-end gap-x-2">
      <a href="<?php echo esc_url(edu_generation_pdf_url($generation_id, 'sel')); ?>"
        class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
        Export PDF SEL
      </a>

      <a href="<?php echo esc_url(edu_generation_pdf_url($generation_id, 'lit')); ?>"
        class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
        Export PDF LIT
      </a>

      <button type="button"
        class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
        Export PDF NUM
      </button>
    </div>
  </div>
</section>
  
<!-- Header & acțiuni (placeholder, putem adăuga export mai târziu) -->
<header class="px-6 mt-4 space-y-1">
  <h1 class="flex flex-col text-2xl font-bold leading-tight text-slate-900 gap-x-4">
    <span class="text-sm uppercase text-slate-400">Raport generația/clasa #<?= $gen_id ?: '—' ?></span> 
    <span class="inline-flex items-center gap-x-2"><?= esc_html($gen_name) ?></span>
  </h1>
</header>

<div class="px-6 mt-4 mb-6 space-y-8" x-data="{ tab: 'sel' }">

  <!-- Tabs -->
  <div class="inline-flex p-1 bg-white border rounded-xl">
    <button @click="tab='sel'" :class="tab==='sel' ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
            class="px-4 py-2 text-sm font-medium rounded-lg">SEL</button>
    <button @click="tab='lit'" :class="tab==='lit' ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
            class="px-4 py-2 text-sm font-medium rounded-lg">Literație</button>
    <button @click="tab='num'" :class="tab==='num' ? 'bg-gray-900 text-white' : 'text-gray-700 hover:bg-gray-100'"
            class="px-4 py-2 text-sm font-medium rounded-lg">Numeratie</button>
  </div>

  <!-- TAB: SEL -->
  <div x-show="tab==='sel'" x-cloak class="space-y-8">
    <?php require get_template_directory().'/dashboard/raport-generatie-tab-sel.php'; ?>
  </div>

  <!-- TAB: LIT -->
  <div x-show="tab==='lit'" x-cloak class="space-y-8">
    <?php require get_template_directory().'/dashboard/raport-generatie-tab-lit.php'; ?>
  </div>

  <!-- TAB: NUM -->
  <div x-show="tab==='num'" x-cloak class="space-y-8">
    <?php //require get_template_directory().'/dashboard/raport-generatie-tab-num.php'; ?>
  </div>
</div>
