<?php
if (!is_user_logged_in()) {
  wp_redirect(home_url('/login'));
  exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

// doar profesor
if (!in_array('profesor', (array)$current_user->roles, true)) {
  echo '<div class="p-4 text-red-600">Acces restricționat. Această pagină este doar pentru utilizatori inregistrati.</div>';
  exit;
}

?>

<?php
// ============= Helpers =============
function edu_badge($text, $type = 'neutral'){
  $map = [
    'success' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
    'warn'    => 'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200',
    'danger'  => 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200',
    'neutral' => 'bg-slate-50 text-slate-700 ring-1 ring-inset ring-slate-200',
    'info'    => 'bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-200',
  ];
  $cls = $map[$type] ?? $map['neutral'];
  return '<span class="px-2 py-1 text-xs font-medium rounded-md '.$cls.'">'.esc_html($text).'</span>';
}

// Alege primul câmp de timp disponibil
function edu_pick_datetime($row) {
  foreach (['created_at','created','date_created','added_at','date','createdOn','updated_at'] as $k) {
    if (isset($row->$k) && $row->$k) return $row->$k;
  }
  return null;
}

// Alege un nume de student robust (în caz că ai altă schemă)
function edu_student_name($row) {
  $first = '';
  $last  = '';
  foreach (['first_name','firstname','prenume','first'] as $k) {
    if (isset($row->$k) && $row->$k) { $first = $row->$k; break; }
  }
  foreach (['last_name','lastname','nume','last','surname'] as $k) {
    if (isset($row->$k) && $row->$k) { $last = $row->$k; break; }
  }
  $full = trim(trim((string)$first).' '.trim((string)$last));
  if ($full !== '') return $full;
  foreach (['name','full_name'] as $k) {
    if (isset($row->$k) && $row->$k) return (string)$row->$k;
  }
  return '—';
}

// ============= DB Handles =============
global $wpdb, $current_user;
$user_id          = (int) $current_user->ID;
$tbl_generations  = $wpdb->prefix . 'edu_generations';
$tbl_students     = $wpdb->prefix . 'edu_students';
$tbl_results      = $wpdb->prefix . 'edu_results';

// ============= Filtru generație =============
$selected_gen = isset($_GET['generation_id']) ? (int) $_GET['generation_id'] : 0;

// Sursă 1: generațiile unde ești profesor
$gens_direct = $wpdb->get_results(
  $wpdb->prepare("
    SELECT id, name, year, created_at
    FROM {$tbl_generations}
    WHERE professor_id = %d
    ORDER BY id DESC
  ", $user_id)
) ?: [];

// Sursă 2: generații regăsite în rezultate (via students)
$gens_from_results = $wpdb->get_results(
  $wpdb->prepare("
    SELECT g.id, g.name, g.year, g.created_at
    FROM {$tbl_results} r
    INNER JOIN {$tbl_students} s ON s.id = r.student_id
    INNER JOIN {$tbl_generations} g ON g.id = s.generation_id
    WHERE r.professor_id = %d
    GROUP BY g.id, g.name, g.year, g.created_at
    ORDER BY g.id DESC
  ", $user_id)
) ?: [];

// Unire + deduplicare
$gen_map = [];
foreach (array_merge($gens_direct, $gens_from_results) as $g) {
  $gen_map[(int)$g->id] = $g;
}
$generations = array_values($gen_map);

// Validează selecția (dacă vine din URL alt ID, îl ignor)
$allowed_ids = array_map(fn($g) => (int)$g->id, $generations);
if ($selected_gen && !in_array($selected_gen, $allowed_ids, true)) {
  $selected_gen = 0;
}

// ============= Statistici =============

// Total generații (doar ale tale direct)
$total_generations = (int) $wpdb->get_var(
  $wpdb->prepare("SELECT COUNT(*) FROM {$tbl_generations} WHERE professor_id = %d", $user_id)
);

// Total studenți
if ($selected_gen) {
  $total_students = (int) $wpdb->get_var(
    $wpdb->prepare("SELECT COUNT(*) FROM {$tbl_students} WHERE generation_id = %d", $selected_gen)
  );
} else {
  $total_students = (int) $wpdb->get_var(
    $wpdb->prepare("
      SELECT COUNT(s.id)
      FROM {$tbl_students} s
      INNER JOIN {$tbl_generations} g ON g.id = s.generation_id
      WHERE g.professor_id = %d
    ", $user_id)
  );
}

// Evaluări în lucru / final – filtrare prin students->generation_id
if ($selected_gen) {
  $eval_in_progress = (int) $wpdb->get_var(
    $wpdb->prepare("
      SELECT COUNT(r.id)
      FROM {$tbl_results} r
      INNER JOIN {$tbl_students} s ON s.id = r.student_id
      WHERE r.professor_id = %d AND r.status = %s AND s.generation_id = %d
    ", $user_id, 'draft', $selected_gen)
  );
  $eval_final = (int) $wpdb->get_var(
    $wpdb->prepare("
      SELECT COUNT(r.id)
      FROM {$tbl_results} r
      INNER JOIN {$tbl_students} s ON s.id = r.student_id
      WHERE r.professor_id = %d AND r.status = %s AND s.generation_id = %d
    ", $user_id, 'final', $selected_gen)
  );
} else {
  $eval_in_progress = (int) $wpdb->get_var(
    $wpdb->prepare("
      SELECT COUNT(r.id)
      FROM {$tbl_results} r
      WHERE r.professor_id = %d AND r.status = %s
    ", $user_id, 'draft')
  );
  $eval_final = (int) $wpdb->get_var(
    $wpdb->prepare("
      SELECT COUNT(r.id)
      FROM {$tbl_results} r
      WHERE r.professor_id = %d AND r.status = %s
    ", $user_id, 'final')
  );
}

// ============= Colecții recente (ORDER BY id DESC) =============

// Activitate recentă (evaluări recente)
if ($selected_gen) {
  $recent_results = $wpdb->get_results(
    $wpdb->prepare("
      SELECT r.*, s.first_name, s.last_name, g.name AS generation_name, s.created_at AS student_created_at
      FROM {$tbl_results} r
      INNER JOIN {$tbl_students} s ON s.id = r.student_id
      INNER JOIN {$tbl_generations} g ON g.id = s.generation_id
      WHERE r.professor_id = %d AND s.generation_id = %d
      ORDER BY r.id DESC
      LIMIT 10
    ", $user_id, $selected_gen)
  );
} else {
  $recent_results = $wpdb->get_results(
    $wpdb->prepare("
      SELECT r.*, s.first_name, s.last_name, g.name AS generation_name, s.created_at AS student_created_at
      FROM {$tbl_results} r
      INNER JOIN {$tbl_students} s ON s.id = r.student_id
      INNER JOIN {$tbl_generations} g ON g.id = s.generation_id
      WHERE r.professor_id = %d
      ORDER BY r.id DESC
      LIMIT 10
    ", $user_id)
  );
}

// Generațiile mele (doar din tabela directă; nu afișez status pentru că nu există în schemă)
$recent_generations = $wpdb->get_results(
  $wpdb->prepare("
    SELECT id, name, year, created_at
    FROM {$tbl_generations}
    WHERE professor_id = %d
    ORDER BY id DESC
    LIMIT 6
  ", $user_id)
);

// Studenți adăugați recent
if ($selected_gen) {
  $recent_students = $wpdb->get_results(
    $wpdb->prepare("
        SELECT s.*, g.name AS generation_name, s.class_label AS class_label
        FROM {$tbl_students} s
        INNER JOIN {$tbl_generations} g ON g.id = s.generation_id
        WHERE s.generation_id = %d
        ORDER BY s.id DESC
        LIMIT 10
    ", $selected_gen)
  );
} else {
  $recent_students = $wpdb->get_results(
    $wpdb->prepare("
        SELECT s.*, g.name AS generation_name, s.class_label AS class_label
        FROM {$tbl_students} s
        INNER JOIN {$tbl_generations} g ON g.id = s.generation_id
        WHERE g.professor_id = %d
        ORDER BY s.id DESC
        LIMIT 10
    ", $user_id)
  );
}

// Etapa evaluării din slug-ul modulului (ex: sel-t0, sel-ti, literatie-primar-t1)
function edu_stage($modul, $modul_type) {
  $m = strtolower((string)$modul);
  if (strpos($m, '-t0') !== false) return 'T0';
  if (strpos($m, '-ti') !== false) return 'Ti';
  if (strpos($m, '-t1') !== false) return 'T1';
  // fallback: pentru SEL mai vechi sau valori atipice
  if ($modul_type === 'sel') {
    if (preg_match('/\b(t0|ti|t1)\b/i', $m, $mm)) return strtoupper($mm[1]);
  }
  return '—';
}

// Clasa progres bar (culoare după prag)
function edu_progress_bar_class($pct) {
  $p = (int)$pct;
  if ($p >= 75) return 'bg-emerald-500';
  if ($p >= 50) return 'bg-orange-500';
  if ($p >= 25) return 'bg-amber-500';
  return 'bg-rose-500';
}

// Remedial: contează DISTINCT studenți cu cel puțin un rezultat LIT ce are remedial=1 în câmpul serializat `score`
if ($selected_gen) {
  $remedial_count = (int) $wpdb->get_var(
    $wpdb->prepare("
      SELECT COUNT(DISTINCT r.student_id)
      FROM {$tbl_results} r
      INNER JOIN {$tbl_students} s ON s.id = r.student_id
      WHERE r.professor_id = %d
        AND r.modul_type = %s
        AND s.generation_id = %d
        AND r.score LIKE %s
    ", $user_id, 'lit', $selected_gen, '%s:8:"remedial";i:1%')
  );
} else {
  $remedial_count = (int) $wpdb->get_var(
    $wpdb->prepare("
      SELECT COUNT(DISTINCT r.student_id)
      FROM {$tbl_results} r
      INNER JOIN {$tbl_students} s ON s.id = r.student_id
      INNER JOIN {$tbl_generations} g ON g.id = s.generation_id
      WHERE r.professor_id = %d
        AND r.modul_type = %s
        AND g.professor_id = %d
        AND r.score LIKE %s
    ", $user_id, 'lit', $user_id, '%s:8:"remedial";i:1%')
  );
}

// ============= Header (nume + toast) =============
$full_name = trim(($current_user->first_name ?? '') . ' ' . ($current_user->last_name ?? ''));
if ($full_name === '') { $full_name = $current_user->display_name ?: $current_user->user_login; }
$toast_msg = isset($_GET['msg']) ? sanitize_text_field(wp_unslash($_GET['msg'])) : '';
?>

<main x-data="{ showToast: <?php echo $toast_msg ? 'true' : 'false'; ?> }" class="flex-1 overflow-y-auto">
  <!-- Header -->
  <div class="px-6 pt-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">Bun venit, <?php echo esc_html($full_name); ?> 👋</h1>
        <p class="mt-1 text-slate-500">
          Panorama activității tale <?php echo $selected_gen ? 'pentru generația/clasa selectată' : 'pe toate generațiile/clasele'; ?>.
        </p>
      </div>

      <!-- Filtru generație -->
      <form method="get" class="flex items-center gap-3">
        <select id="generation_id" name="generation_id"
                class="px-3 py-2 text-sm bg-white shadow-sm rounded-xl border-slate-300 focus:outline-none focus:ring-2 focus:ring-slate-200">
          <option value="0"<?php selected($selected_gen, 0); ?>>Toate generațiile/clasele</option>
          <?php if (!empty($generations)): ?>
            <?php foreach ($generations as $gen): ?>
              <option value="<?php echo (int)$gen->id; ?>" <?php selected($selected_gen, (int)$gen->id); ?>>
                <?php
                  $label = $gen->name ?? ('Gen. #' . (int)$gen->id);
                  if (!empty($gen->year)) {
                    $yr = function_exists('es_normalize_year_str') ? es_normalize_year_str($gen->year) : (string)$gen->year;
                    $label .= ' · ' . $yr;
                  }
                  echo esc_html($label);
                ?>
              </option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
        <button type="submit"
                class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm hover:bg-slate-50 active:scale-[.98]">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <path d="M3 12a9 9 0 0 1 15.5-6"/><path d="M3 3v6h6"/><path d="M21 12a9 9 0 0 1-15.5 6"/><path d="M21 21v-6h-6"/>
          </svg>
          Actualizează
        </button>
      </form>
    </div>
  </div> 

  <!-- Carduri statistici -->
  <section class="grid grid-cols-1 gap-4 px-6 mt-6 sm:grid-cols-2 xl:grid-cols-4">
    <div class="p-5 bg-white border shadow-sm rounded-2xl border-slate-200">
      <div class="flex items-center justify-between">
        <p class="text-sm text-slate-500">Generații/clase</p>
        <span class="px-2 py-1 text-xs rounded-lg bg-slate-100">total</span>
      </div>
      <div class="mt-2 text-3xl font-semibold tracking-tight"><?php echo number_format_i18n($total_generations); ?></div>
      <p class="mt-1 text-xs text-slate-500">Gestionate de tine</p>
    </div>

    <div class="p-5 bg-white border shadow-sm rounded-2xl border-slate-200">
      <div class="flex items-center justify-between">
        <p class="text-sm text-slate-500">Studenți</p>
        <span class="px-2 py-1 text-xs rounded-lg bg-slate-100"><?php echo $selected_gen ? 'gen. curentă' : 'toate'; ?></span>
      </div>
      <div class="mt-2 text-3xl font-semibold tracking-tight"><?php echo number_format_i18n($total_students); ?></div>
      <p class="mt-1 text-xs text-slate-500">
        Înregistrați
      </p>
    </div>

    <div class="p-5 bg-white border shadow-sm rounded-2xl border-slate-200">
      <div class="flex items-center justify-between">
        <p class="text-sm text-slate-500">Evaluări în lucru</p>
        <span class="px-2 py-1 text-xs rounded-lg bg-amber-50 text-amber-700 ring-1 ring-amber-200">temp</span>
      </div>
      <div class="mt-2 text-3xl font-semibold tracking-tight"><?php echo number_format_i18n($eval_in_progress); ?></div>
      <p class="mt-1 text-xs text-slate-500">Necesită finalizare</p>
    </div>

    <div class="p-5 bg-white border shadow-sm rounded-2xl border-slate-200">
      <div class="flex items-center justify-between">
        <p class="text-sm text-slate-500">Evaluări finalizate</p>
        <span class="px-2 py-1 text-xs rounded-lg bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">final</span>
      </div>
      <div class="mt-2 text-3xl font-semibold tracking-tight"><?php echo number_format_i18n($eval_final); ?></div>
      <p class="mt-1 text-xs text-slate-500">Gata de raport</p>
    </div>
  </section>

  <!-- Activitate recentă -->
  <section class="px-6 mt-8">
    <div class="bg-white border shadow-sm rounded-2xl border-slate-200">
      <div class="flex items-center justify-between p-5 border-b border-slate-100">
        <h2 class="text-base font-semibold tracking-tight">Evaluări recente</h2>
        <a href="<?php echo esc_url(home_url('/panou/evaluari')); ?>"
           class="text-sm text-slate-600 hover:text-slate-900 inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 hover:bg-slate-50 active:scale-[.98]">
           Vezi toate evaluările
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
            </svg>
          </a>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-slate-600">
            <tr>
              <th class="px-5 py-3 font-medium text-left">Student</th>
              <th class="px-5 py-3 font-medium text-left">Generație/clasă</th>
              <th class="px-5 py-3 font-medium text-left">Modul</th>
              <th class="px-5 py-3 font-medium text-left">Etapă</th>
              <th class="px-5 py-3 font-medium text-left">Stare</th>
              <th class="w-48 px-5 py-3 font-medium text-left">Completare</th>
              <th class="px-5 py-3 font-medium text-left">Creat la</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if (!empty($recent_results)): foreach ($recent_results as $r):
              $name = edu_student_name($r);
              $badge = ($r->status === 'final') ? edu_badge('Final','success') : edu_badge('Temp','warn');
              $mod   = strtoupper($r->modul_type ?: '—');
              $completion = is_numeric($r->completion) ? max(0, min(100, (int)$r->completion)) : 0;
              $dt = edu_pick_datetime($r);
            ?>
              <tr class="hover:bg-slate-50/60">
                <td class="px-5 py-3">
                    <a href="<?php echo esc_url(home_url('/panou/raport/elev/'.$r->student_id)); ?>"
                        class="font-medium text-slate-800 hover:underline underline-offset-4">
                        <?php echo esc_html($name); ?>
                    </a>
                </td>
                <td class="px-5 py-3 text-slate-600"><?php echo esc_html($r->generation_name ?? '—'); ?></td>
                <td class="px-5 py-3"><?php echo edu_badge($mod, 'info'); ?></td>
                <td class="px-5 py-3">
                    <?php echo edu_badge( edu_stage($r->modul ?? '', $r->modul_type ?? ''), 'neutral'); ?>
                </td>
                <td class="px-5 py-3"><?php echo $badge; ?></td>
                <td class="px-5 py-3">
                    <div class="w-44 h-2.5 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full <?php echo edu_progress_bar_class($completion); ?>"
                            style="width: <?php echo (int)$completion; ?>%"></div>
                    </div>
                    <span class="ml-2 text-xs align-middle text-slate-500"><?php echo (int)$completion; ?>%</span>
                </td>

                <td class="px-5 py-3 text-slate-600">
                  <?php echo $dt ? esc_html(mysql2date(get_option('date_format').' '.get_option('time_format'), $dt)) : '—'; ?>
                </td>
                <td class="px-5 py-3 text-right">
                  <a href="<?php echo esc_url(home_url('/panou/raport/elev/'.$r->student_id)); ?>"
                     class="inline-flex items-center gap-1.5 text-slate-700 hover:text-slate-900 underline-offset-4 hover:underline">
                    Deschide
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                      <path d="M7 17L17 7"/><path d="M8 7h9v9"/>
                    </svg>
                  </a>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="7" class="px-5 py-6 text-center text-slate-500">Nu există evaluări recente.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- Două coloane: Generațiile mele & Studenți recenți -->
  <section class="grid grid-cols-1 gap-6 px-6 mt-8 xl:grid-cols-2">
    <!-- Generațiile mele -->
    <div class="bg-white border shadow-sm rounded-2xl border-slate-200">
      <div class="flex items-center justify-between p-5 border-b border-slate-100">
        <h2 class="text-base font-semibold tracking-tight">Generațiile mele</h2>
        <a href="<?php echo esc_url(home_url('/panou/generatii')); ?>"
           class="text-sm text-slate-600 hover:text-slate-900 underline-offset-4 hover:underline">Administrează</a>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-slate-600">
            <tr>
              <th class="px-5 py-3 font-medium text-left">Nume</th>
              <th class="px-5 py-3 font-medium text-left">An</th>
              <th class="px-5 py-3 font-medium text-left">Creată la</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if (!empty($recent_generations)): foreach ($recent_generations as $g):
              $dt = edu_pick_datetime($g);
            ?>
              <tr class="hover:bg-slate-50/60">
                <td class="px-5 py-3 font-medium"><?php echo esc_html($g->name ?? ('Gen. #'.(int)$g->id)); ?></td>
                <td class="px-5 py-3 text-slate-600"><?php echo esc_html($g->year ?? '—'); ?></td>
                <td class="px-5 py-3 text-slate-600">
                  <?php echo $dt ? esc_html(mysql2date(get_option('date_format'), $dt)) : '—'; ?>
                </td>
                <td class="px-5 py-3 text-right">
                  <a href="<?php echo esc_url(home_url('/panou/generatii/'.$g->id)); ?>"
                     class="inline-flex items-center gap-1.5 text-slate-700 hover:text-slate-900 underline-offset-4 hover:underline">
                    Deschide
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                      <path d="M7 17L17 7"/><path d="M8 7h9v9"/>
                    </svg>
                  </a>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="4" class="px-5 py-6 text-center text-slate-500">Nu ai încă nicio generație/clasă.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Studenți recenți -->
    <div class="bg-white border shadow-sm rounded-2xl border-slate-200">
      <div class="flex items-center justify-between p-5 border-b border-slate-100">
        <h2 class="text-base font-semibold tracking-tight">Studenți adăugați recent</h2>
        <a href="<?php echo esc_url(home_url('/panou/lista')); ?>"
           class="text-sm text-slate-600 hover:text-slate-900 underline-offset-4 hover:underline">Gestionează</a>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-slate-600">
            <tr>
              <th class="px-5 py-3 font-medium text-left">Student</th>
              <th class="px-5 py-3 font-medium text-left">Generație</th>
              <th class="px-5 py-3 font-medium text-left">Clasă</th>
              <th class="px-5 py-3 font-medium text-left">Creat la</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if (!empty($recent_students)): foreach ($recent_students as $s):
              $name = edu_student_name($s);
              $dt = edu_pick_datetime($s);
            ?>
              <tr class="hover:bg-slate-50/60">
                <td class="px-5 py-3 font-medium"><?php echo esc_html($name); ?></td>
                <td class="px-5 py-3 text-slate-600"><?php echo esc_html($s->generation_name ?? '—'); ?></td>
                <td class="px-5 py-3 text-slate-600">
                    <?php echo esc_html($s->class_label ?? '—'); ?>
                </td>
                <td class="px-5 py-3 text-slate-600">
                  <?php echo $dt ? esc_html(mysql2date(get_option('date_format'), $dt)) : '—'; ?>
                </td>
                <td class="px-5 py-3 text-right">
                  <a href="<?php echo esc_url(home_url('/panou/raport/elev/'.$s->id)); ?>"
                     class="inline-flex items-center gap-1.5 text-slate-700 hover:text-slate-900 underline-offset-4 hover:underline">
                    Deschide raport
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                      <path d="M7 17L17 7"/><path d="M8 7h9v9"/>
                    </svg>
                  </a>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="4" class="px-5 py-6 text-center text-slate-500">Nu există înregistrări recente.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- Toast -->
  <div x-show="showToast" x-transition
       class="fixed px-4 py-3 text-sm text-white shadow-lg bottom-6 right-6 rounded-xl bg-slate-900">
    <?php echo $toast_msg ? esc_html($toast_msg) : 'Actualizare reușită.'; ?>
  </div>

  <div class="h-10"></div>
</main>

