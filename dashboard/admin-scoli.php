<?php
/* Template Name: Manager Școli (front) */
if (!defined('ABSPATH')) exit;
if (!is_user_logged_in()) { wp_redirect(wp_login_url()); exit; }

global $wpdb;
$tbl_schools  = $wpdb->prefix . 'edu_schools';
$tbl_cities   = $wpdb->prefix . 'edu_cities';
$tbl_counties = $wpdb->prefix . 'edu_counties';

$can_manage = current_user_can('manage_options') || current_user_can('manage_edu_classes');

/* Nonce + URL AJAX */
$ajax_url   = admin_url('admin-ajax.php');
$nonce_op   = wp_create_nonce('edu_save_school_op');     // save/delete în această pagină
$nonce_ajax = wp_create_nonce('edu_nonce');              // pentru edu_search_schools
$nonce_loc  = wp_create_nonce('edu_location_nonce');     // pentru edu_get_cities / edu_get_villages

/* Save (insert/update) — POST (rămâne server-side) */
$notice = '';
if ($can_manage && !empty($_POST['school_action']) && $_POST['school_action'] === 'save') {
  if (!wp_verify_nonce($_POST['_opnonce'] ?? '', 'edu_save_school_op')) {
    $notice = '<div class="p-3 mb-4 text-sm text-white rounded-lg bg-rose-600">Eroare: sesiune invalidă.</div>';
  } else {
    $school_id  = isset($_POST['school_id']) ? (int)$_POST['school_id'] : 0;
    $county_id  = (int)($_POST['county_id'] ?? 0);
    $city_id    = (int)($_POST['city_id'] ?? 0);
    $village_id = (int)($_POST['village_id'] ?? 0);

    $cod        = ($_POST['cod'] !== '') ? (int)$_POST['cod'] : null;
    // câmpul postat este `school_name` — `name` este rezervat de WordPress (post slug)
    // și provoca 404 la submit. Acceptăm și valoarea veche `name` pentru orice cache de formular.
    $name       = sanitize_text_field($_POST['school_name'] ?? ($_POST['name'] ?? ''));
    $short_name = sanitize_text_field($_POST['short_name'] ?? '');
    $regiune    = in_array(($_POST['regiune_tfr'] ?? 'RMD'), ['RMD','RCV','SUD'], true) ? $_POST['regiune_tfr'] : 'RMD';
    $statut_vals = ['Cu personalitate juridică', 'Arondată', ''];
    $statut     = in_array(($_POST['statut'] ?? ''), $statut_vals, true) ? $_POST['statut'] : '';
    $tip_vals   = ['Public', 'Privat', ''];
    $tip        = in_array(($_POST['tip'] ?? ''), $tip_vals, true) ? $_POST['tip'] : '';
    $first_year_tfr = sanitize_text_field($_POST['first_year_tfr'] ?? '');
    $medie_irse = ($_POST['medie_irse'] !== '' ? (float)$_POST['medie_irse'] : null);
    $scor_irse  = ($_POST['scor_irse']  !== '' ? (float)$_POST['scor_irse']  : null);
    $strategic  = !empty($_POST['strategic']) ? 1 : 0;
    $idx_tfr_vals = ['1','2','3','3NO','4','5',''];
    $idx_tfr    = in_array(($_POST['index_vulnerabilitate_tfr'] ?? ''), $idx_tfr_vals, true) ? ($_POST['index_vulnerabilitate_tfr'] ?: null) : null;
    $nr_siiir   = (isset($_POST['numar_elevi_siiir']) && $_POST['numar_elevi_siiir'] !== '' ? (int)$_POST['numar_elevi_siiir'] : null);

    // mediu: din formular (dropdown) cu fallback auto-calc
    $mediu_vals = ['urban', 'rural'];
    $mediu_post = strtolower($_POST['mediu'] ?? '');
    if (in_array($mediu_post, $mediu_vals, true)) {
      $mediu = $mediu_post;
    } else {
      $parent_id = $city_id ? (int)$wpdb->get_var($wpdb->prepare("SELECT parent_city_id FROM {$tbl_cities} WHERE id=%d", $city_id)) : 0;
      $mediu     = ($village_id > 0 || $parent_id > 0) ? 'rural' : 'urban';
    }

    if ($city_id > 0 && $name !== '') {
      $data = [
        'city_id'                   => $city_id,
        'village_id'                => ($village_id ?: null),
        'cod'                       => $cod,
        'name'                      => $name,
        'short_name'                => $short_name,
        'regiune_tfr'               => $regiune,
        'statut'                    => $statut,
        'tip'                       => ($tip ?: null),
        'first_year_tfr'            => ($first_year_tfr ?: null),
        'medie_irse'                => $medie_irse,
        'scor_irse'                 => $scor_irse,
        'strategic'                 => $strategic,
        'index_vulnerabilitate_tfr' => $idx_tfr,
        'numar_elevi_siiir'         => $nr_siiir,
        'mediu'                     => $mediu,
      ];

      if ($school_id > 0) {
        $ok = (false !== $wpdb->update($tbl_schools, $data, ['id' => $school_id]));
        $notice = $ok
          ? '<div class="p-3 mb-4 text-sm border rounded-lg text-emerald-900 bg-emerald-50 border-emerald-200">Școala a fost actualizată.</div>'
          : '<div class="p-3 mb-4 text-sm text-white rounded-lg bg-rose-600">Actualizarea a eșuat.</div>';
      } else {
        $ok = (false !== $wpdb->insert($tbl_schools, $data));
        $notice = $ok
          ? '<div class="p-3 mb-4 text-sm border rounded-lg text-emerald-900 bg-emerald-50 border-emerald-200">Școală adăugată.</div>'
          : '<div class="p-3 mb-4 text-sm text-white rounded-lg bg-rose-600">Adăugarea a eșuat.</div>';
      }
    } else {
      $notice = '<div class="p-3 mb-4 text-sm text-white rounded-lg bg-rose-600">Completează cel puțin <strong>județ</strong>, <strong>oraș</strong> și <strong>denumire</strong>.</div>';
    }
  }
}

/* Filtre inițiale (NU le mai folosim pentru GET — doar pentru preselectare UI) */
$s_init      = sanitize_text_field($_GET['q'] ?? ($_GET['s'] ?? ''));
$county_init = (int)($_GET['county'] ?? 0);
$city_init   = (int)($_GET['city'] ?? 0);

/* Dropdown județe + (opțional) orașe preîncărcate dacă vrei tu server-side */
$counties = $wpdb->get_results("SELECT id,name FROM {$tbl_counties} ORDER BY name");

/* Paginare server-side */
$per_page = 100;
$paged    = max(1, (int)($_GET['pg'] ?? 1));
$offset   = ($paged - 1) * $per_page;

/* Condiții WHERE pentru filtru server-side */
$where = [];
$prepare_args = [];
if ($s_init !== '') {
  $like = '%' . $wpdb->esc_like($s_init) . '%';
  $where[] = "(s.name LIKE %s OR s.short_name LIKE %s OR s.cod LIKE %s OR c.name LIKE %s OR ct.name LIKE %s)";
  $prepare_args = array_merge($prepare_args, [$like, $like, $like, $like, $like]);
}
if ($county_init > 0) {
  $where[] = "ct.id = %d";
  $prepare_args[] = $county_init;
}
if ($city_init > 0) {
  $where[] = "c.id = %d";
  $prepare_args[] = $city_init;
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* Total pentru paginare */
$count_sql = "
  SELECT COUNT(*)
  FROM {$tbl_schools} s
  JOIN {$tbl_cities} c   ON s.city_id = c.id
  JOIN {$tbl_counties} ct ON c.county_id = ct.id
  {$where_sql}
";
$total_schools = (int)($prepare_args
  ? $wpdb->get_var($wpdb->prepare($count_sql, ...$prepare_args))
  : $wpdb->get_var($count_sql)
);
$total_pages = max(1, (int)ceil($total_schools / $per_page));
$paged = min($paged, $total_pages);
$offset = ($paged - 1) * $per_page;

/* Listă școli pentru tabel */
$sql = "
  SELECT s.*,
         c.name  AS city_name,
         c.id    AS city_id,
         c.parent_city_id,
         ct.id   AS county_id,
         ct.name AS county_name,
         v.name  AS village_name
  FROM {$tbl_schools} s
  JOIN {$tbl_cities} c   ON s.city_id = c.id
  JOIN {$tbl_counties} ct ON c.county_id = ct.id
  LEFT JOIN {$tbl_cities} v ON s.village_id = v.id
  {$where_sql}
  ORDER BY ct.name, c.name, s.name
  LIMIT {$per_page} OFFSET {$offset}
";
$rows = $prepare_args
  ? $wpdb->get_results($wpdb->prepare($sql, ...$prepare_args))
  : $wpdb->get_results($sql);


$export_base = add_query_arg([
  'action' => 'edus_export_schools_csv',
  'nonce'  => wp_create_nonce('edus_export_schools_csv'),
], admin_url('admin-post.php'));
?>

<main class="w-full" x-data="schoolsPage()" x-init="init()" x-cloak
      data-ajax="<?php echo esc_url($ajax_url); ?>"
      data-opnonce="<?php echo esc_attr($nonce_op); ?>"
      data-nonce="<?php echo esc_attr($nonce_ajax); ?>"
      data-locnonce="<?php echo esc_attr($nonce_loc); ?>"
      data-export="<?php echo esc_url($export_base); ?>">

<section class="sticky top-0 z-10 px-2 border-b inner-submenu bg-slate-800 border-slate-200">
  <div class="relative z-10 flex items-center justify-between py-2 gap-x-2">
    <div class="flex items-center justify-start">
      <?php if ($can_manage): ?>
        <button @click="openAdd()"
                class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md rounded-tl-xl bg-emerald-600 hover:bg-emerald-700">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4">
                <path fill-rule="evenodd" d="M12 3.75a.75.75 0 0 1 .75.75v6.75h6.75a.75.75 0 0 1 0 1.5h-6.75v6.75a.75.75 0 0 1-1.5 0v-6.75H4.5a.75.75 0 0 1 0-1.5h6.75V4.5a.75.75 0 0 1 .75-.75Z" clip-rule="evenodd" />
            </svg>
            Adaugă școală
        </button>
      <?php endif; ?>
    </div>

    <div class="flex items-center gap-2">
      <a :href="exportUrl" target="_blank"
        class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-md hover:bg-slate-700">
        <svg class="w-3 h-3 mobile:w-5 mobile:h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
          <path d="M7 10l5 5 5-5"/>
          <path d="M12 15V3"/>
        </svg>
        <span class="mobile:hidden">Export CSV</span>
      </a>
      
      <a href="../documentatie/#profesori" target="_blank" rel="noopener noreferrer"
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
  <div class="px-6 mx-auto mt-4 text-slate-800 mobile:px-2 mobile:pb-12">

    <?php echo $notice; ?>

    <!-- FILTRE -->
    <section class="mb-6">
      <form id="schools-filter-form" method="get" action="<?php echo esc_url(home_url('/panou/scoli/')); ?>" class="grid items-end grid-cols-1 gap-3 md:grid-cols-12">
        <!-- Căutare (AJAX sugestii) -->
        <div class="relative md:col-span-4">
          <label class="block mb-1 text-xs font-medium text-slate-600">Caută școală (nume/cod)</label>
          <input type="text" name="q" x-model.trim="s" @input="onSearchInput()" @keydown.escape="showSuggest=false"
                 autocomplete="off" placeholder="Ex: Școala Gimnazială …"
                 value="<?php echo esc_attr($s_init); ?>"
                 class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-600 focuse:border-transparent focus:outline-none">
          <!-- Panel sugestii -->
          <div x-show="showSuggest" x-transition
               class="absolute z-20 hidden w-full p-1 mt-1 bg-white border rounded-lg shadow-lg border-slate-200 suggest-panel">
            <template x-if="suggestions.length===0">
              <div class="px-3 py-2 text-sm text-slate-500">Nicio sugestie…</div>
            </template>
            <template x-for="it in suggestions" :key="it.id">
              <button type="button"
                      @click="s = it.name || ''; showSuggest = false; $refs.filterForm?.submit()"
                      class="flex items-center justify-between w-full px-3 py-1.5 text-left rounded hover:bg-slate-50">
                <span class="text-sm" x-text="it.text"></span>
              </button>
            </template>
          </div>
        </div>

        <!-- Județ -->
        <div class="md:col-span-2">
          <label class="block mb-1 text-xs font-medium text-slate-600">Județ</label>
          <select name="county" x-model.number="county" @change="onCountyChange()"
                  class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-600 focus:border-transparent focus:outline-none">
            <option value="0">— Toate județele —</option>
            <?php foreach ($counties as $c): ?>
              <option value="<?php echo (int)$c->id; ?>" <?php selected($county_init, (int)$c->id); ?>><?php echo esc_html($c->name); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Oraș (dependent) -->
        <div class="md:col-span-2" x-show="county>0" x-transition>
          <label class="block mb-1 text-xs font-medium text-slate-600">Oraș</label>
          <select name="city" x-model.number="city" x-ref="filterCity"
                  class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-600 focus:border-transparent focus:outline-none">
            <option value="0">— Toate orașele —</option>
          </select>
        </div>

        <!-- Submit -->
        <div class="flex items-center gap-2 md:col-span-4">
          <button type="submit"
                  class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-medium text-white rounded-md shadow-sm bg-emerald-600 hover:bg-emerald-700">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
                    <path fill-rule="evenodd" d="M3.792 2.938A49.069 49.069 0 0 1 12 2.25c2.797 0 5.54.236 8.209.688a1.857 1.857 0 0 1 1.541 1.836v1.044a3 3 0 0 1-.879 2.121l-6.182 6.182a1.5 1.5 0 0 0-.439 1.061v2.927a3 3 0 0 1-1.658 2.684l-1.757.878A.75.75 0 0 1 9.75 21v-5.818a1.5 1.5 0 0 0-.44-1.06L3.13 7.938a3 3 0 0 1-.879-2.121V4.774c0-.897.64-1.683 1.542-1.836Z" clip-rule="evenodd" />
                </svg>
            Aplică filtre
          </button>
          <a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'], '?')); ?>"
             class="inline-flex items-center justify-center gap-2 px-3 py-2 text-xs font-medium text-white rounded-md shadow-sm bg-rose-500 hover:bg-rose-600">
            Reset
          </a>
          <span class="ml-auto text-xs text-slate-600">
            Afișezi <?php echo count($rows); ?> din <?php echo $total_schools; ?> școli
          </span>
        </div>
      </form>
    </section>

    <!-- TABEL -->
    <div class="relative overflow-x-auto bg-white border shadow-sm min-h-auto rounded-2xl border-slate-200">
      <table class="relative w-full text-sm table-auto max-h-[62vh] overflow-y-scroll block" id="schools-table">
        <thead class="sticky top-0 bg-sky-800 backdrop-blur">
          <tr class="text-white">
            <th class="px-3 py-2 font-semibold text-left border-b">ID</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Cod SIIIR</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Denumire</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Scurt</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Județ</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Oraș</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Comună/Sat</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Mediu</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Regiune</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Statut</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Tip</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Primul an TFR</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Idx. Vuln.</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Medie IRSE</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Scor IRSE</th>
            <th class="px-3 py-2 font-semibold text-left border-b">Strategică</th>
            <?php if ($can_manage): ?>
                <th class="px-3 py-2 font-semibold text-left border-b">Acțiuni</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody class="[&>tr:nth-child(odd)]:bg-slate-50/40">
          <?php if ($rows): foreach ($rows as $r):
            $row = [
              'id'        => (int)$r->id,
              'cod'       => (int)$r->cod,
              'name'      => (string)$r->name,
              'short_name'=> (string)$r->short_name,
              'county_id' => (int)$r->county_id,
              'city_id'   => (int)$r->city_id,
              'village_id'=> (int)($r->village_id ?? 0),
              'regiune_tfr'=>(string)$r->regiune_tfr,
              'statut'    => (string)($r->statut ?? ''),
              'tip'       => (string)($r->tip ?? ''),
              'first_year_tfr' => (string)($r->first_year_tfr ?? ''),
              'medie_irse'=> $r->medie_irse,
              'scor_irse' => $r->scor_irse,
              'strategic' => (int)$r->strategic,
              'index_vulnerabilitate_tfr' => (string)($r->index_vulnerabilitate_tfr ?? ''),
              'numar_elevi_siiir' => $r->numar_elevi_siiir,
              'mediu' => (string)($r->mediu ?? ''),
            ];
            $json = wp_json_encode($row);
            $search_blob = strtolower(trim(
              ($r->cod ? $r->cod.' ' : '') .
              ($r->name ?: '') . ' ' .
              ($r->short_name ?: '') . ' ' .
              ($r->city_name ?: '') . ' ' .
              ($r->county_name ?: '') . ' ' .
              (($r->village_name ?: ''))
            ));
            $del_url = esc_url( add_query_arg([
              'delete_school' => (int)$r->id,
              '_delnonce'     => $nonce_op,
            ]) );
          ?>
          <tr class="border-b border-slate-200 odd:bg-white even:bg-slate-50"
              data-row
              data-county="<?php echo (int)$r->county_id; ?>"
              data-city="<?php echo (int)$r->city_id; ?>"
              data-search="<?php echo esc_attr($search_blob); ?>">
            <td class="px-3 py-2 font-mono text-[12px] text-slate-600">#<?php echo (int)$r->id; ?></td>
            <td class="px-3 py-2"><?php echo $r->cod ? (int)$r->cod : '—'; ?></td>
            <td class="px-3 py-2 font-medium"><?php echo esc_html($r->name); ?></td>
            <td class="px-3 py-2"><?php echo $r->short_name ? esc_html($r->short_name) : '—'; ?></td>
            <td class="px-3 py-2"><?php echo esc_html($r->county_name); ?></td>
            <td class="px-3 py-2"><?php echo esc_html($r->city_name); ?></td>
            <td class="px-3 py-2"><?php echo $r->village_name ? esc_html($r->village_name) : '—'; ?></td>
            <td class="px-3 py-2">
              <?php if (($r->mediu ?? '') === 'rural'): ?>
                <span class="px-2 py-0.5 text-[11px] rounded-full bg-amber-50 text-amber-700 ring-1 ring-amber-200">rural</span>
              <?php else: ?>
                <span class="px-2 py-0.5 text-[11px] rounded-full bg-sky-50 text-sky-700 ring-1 ring-sky-200">urban</span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-2"><?php echo $r->regiune_tfr ? esc_html($r->regiune_tfr) : '—'; ?></td>
            <td class="px-3 py-2 text-xs"><?php echo $r->statut ? esc_html($r->statut) : '—'; ?></td>
            <td class="px-3 py-2"><?php echo ($r->tip ?? '') ? esc_html($r->tip) : '—'; ?></td>
            <td class="px-3 py-2"><?php echo ($r->first_year_tfr ?? '') ? esc_html($r->first_year_tfr) : '—'; ?></td>
            <td class="px-3 py-2"><?php echo ($r->index_vulnerabilitate_tfr ?? '') !== '' && $r->index_vulnerabilitate_tfr !== null ? esc_html($r->index_vulnerabilitate_tfr) : '—'; ?></td>
            <td class="px-3 py-2"><?php echo ($r->medie_irse !== null) ? esc_html($r->medie_irse) : '—'; ?></td>
            <td class="px-3 py-2"><?php echo ($r->scor_irse  !== null) ? esc_html($r->scor_irse)  : '—'; ?></td>
            <td class="px-3 py-2"><?php echo $r->strategic ? '✅' : '—'; ?></td>
            <?php if ($can_manage): ?>
            <td class="px-3 py-2">
              <div class="flex items-center gap-2">
                <button @click='openEdit(<?php echo $json; ?>)'
                        class="px-2 py-1 text-xs font-medium rounded bg-slate-100 hover:bg-slate-200">Editează</button>
                <a href="<?php echo $del_url; ?>"
                   onclick="return confirm('Sigur dorești să ștergi această școală?');"
                   class="px-2 py-1 text-xs font-medium rounded text-rose-600 hover:text-rose-700">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4">
                    <path fill-rule="evenodd" d="M16.5 4.478v.227a48.816 48.816 0 0 1 3.878.512.75.75 0 1 1-.256 1.478l-.209-.035-1.005 13.07a3 3 0 0 1-2.991 2.77H8.084a3 3 0 0 1-2.991-2.77L4.087 6.66l-.209.035a.75.75 0 0 1-.256-1.478A48.567 48.567 0 0 1 7.5 4.705v-.227c0-1.564 1.213-2.9 2.816-2.951a52.662 52.662 0 0 1 3.369 0c1.603.051 2.815 1.387 2.815 2.951Zm-6.136-1.452a51.196 51.196 0 0 1 3.273 0C14.39 3.05 15 3.684 15 4.478v.113a49.488 49.488 0 0 0-6 0v-.113c0-.794.609-1.428 1.364-1.452Zm-.355 5.945a.75.75 0 1 0-1.5.058l.347 9a.75.75 0 1 0 1.499-.058l-.346-9Zm5.48.058a.75.75 0 1 0-1.498-.058l-.347 9a.75.75 0 0 0 1.5.058l.345-9Z" clip-rule="evenodd" />
                  </svg>
                </a>
              </div>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="<?php echo $can_manage ? 17 : 16; ?>" class="px-4 py-6 text-center text-slate-500">Nu s-au găsit școli.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <!-- Empty state dinamic -->
      <div id="empty-state" class="<?php echo empty($rows) ? '' : 'hidden'; ?> px-4 py-10 text-sm text-center text-slate-500">Niciun rezultat pentru filtrele curente.</div>
    </div>

    <!-- Paginare -->
    <?php if ($total_pages > 1): ?>
    <div class="sticky flex items-center justify-between px-4 mt-4 mb-6 bottom-4">
      <div class="text-sm text-slate-600">
        Pagina <?php echo $paged; ?> din <?php echo $total_pages; ?>
      </div>
      <div class="flex gap-1">
        <?php
          $base_url = strtok($_SERVER['REQUEST_URI'], '?');
          $filter_params = [];
          if ($s_init !== '') $filter_params['q'] = $s_init;
          if ($county_init > 0) $filter_params['county'] = $county_init;
          if ($city_init > 0) $filter_params['city'] = $city_init;
        ?>
        <?php if ($paged > 1): ?>
          <a href="<?php echo esc_url(add_query_arg(array_merge($filter_params, ['pg' => $paged - 1]), $base_url)); ?>"
             class="px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">
            &laquo; Anterior
          </a>
        <?php endif; ?>

        <?php
          $start = max(1, $paged - 2);
          $end   = min($total_pages, $paged + 2);
          for ($i = $start; $i <= $end; $i++):
        ?>
          <?php if ($i === $paged): ?>
            <span class="px-3 py-1.5 text-sm font-semibold text-white rounded-lg bg-sky-700"><?php echo $i; ?></span>
          <?php else: ?>
            <a href="<?php echo esc_url(add_query_arg(array_merge($filter_params, ['pg' => $i]), $base_url)); ?>"
               class="px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">
              <?php echo $i; ?>
            </a>
          <?php endif; ?>
        <?php endfor; ?>

        <?php if ($paged < $total_pages): ?>
          <a href="<?php echo esc_url(add_query_arg(array_merge($filter_params, ['pg' => $paged + 1]), $base_url)); ?>"
             class="px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">
            Următor &raquo;
          </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- MODAL ADD/EDIT -->
  <?php if ($can_manage): ?>
  <div x-show="modalOpen" x-transition
       class="fixed inset-0 z-[999] flex items-center justify-center p-4 bg-black/40">
    <div @click.away="closeModal()"
         class="w-full max-w-4xl p-4 bg-white rounded-2xl">
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold" x-text="form.id ? 'Editează școală' : 'Adaugă școală'"></h3>
        <button @click="closeModal()" class="p-2 text-slate-500 hover:text-slate-800">&times;</button>
      </div>

      <form method="post" class="grid grid-cols-1 gap-4 mt-4 md:grid-cols-3" @submit="onSubmit">
        <input type="hidden" name="school_action" value="save">
        <input type="hidden" name="_opnonce" :value="$root.dataset.opnonce">
        <input type="hidden" name="school_id" :value="form.id || 0">

        <!-- COL 1 -->
        <div class="flex flex-col gap-3">
          <!-- Județ -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Județ *</label>
            <select name="county_id" x-model.number="form.county_id" @change="loadCitiesForModal(0)"
                    class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800" required>
              <option :value="0">— selectează —</option>
              <?php foreach ($counties as $c): ?>
                <option value="<?php echo (int)$c->id; ?>"><?php echo esc_html($c->name); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Oraș -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Oraș *</label>
            <select x-ref="modalCitySelect"
                    name="city_id"
                    @change="onModalCityChange($event)"
                    class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800" required>
              <option value="0">— selectează —</option>
            </select>
          </div>

          <!-- Comună/Sat (opțional) -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Comună/Sat (opțional)</label>
            <select x-ref="modalVillageSelect"
                    name="village_id"
                    @change="form.village_id = +$event.target.value || 0"
                    class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800">
              <option value="0">— selectează —</option>
            </select>
          </div>

          <!-- Cod SIIIR -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Cod SIIIR</label>
            <input type="number" name="cod" x-model="form.cod"
                   class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800" required>
          </div>
        </div>

        <!-- COL 2 -->
        <div class="flex flex-col gap-3">
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Denumire *</label>
            <input type="text" name="school_name" x-model="form.name"
                   class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800" required>
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Denumire scurtă</label>
            <input type="text" name="short_name" x-model="form.short_name"
                   class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800">
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Regiune TFR</label>
            <select name="regiune_tfr" x-model="form.regiune_tfr"
                    class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800">
              <option value="RMD">RMD</option>
              <option value="RCV">RCV</option>
              <option value="SUD">SUD</option>
            </select>
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Statut</label>
            <select name="statut" x-model="form.statut"
                    class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800">
              <option value="">— Selectează —</option>
              <option value="Cu personalitate juridică">Cu personalitate juridică</option>
              <option value="Arondată">Arondată</option>
            </select>
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Tip</label>
            <select name="tip" x-model="form.tip"
                    class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800">
              <option value="">— Selectează —</option>
              <option value="Public">Public</option>
              <option value="Privat">Privat</option>
            </select>
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Mediu</label>
            <select name="mediu" x-model="form.mediu"
                    class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800">
              <option value="">— Selectează —</option>
              <option value="urban">Urban</option>
              <option value="rural">Rural</option>
            </select>
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Primul an TFR</label>
            <input type="text" name="first_year_tfr" x-model="form.first_year_tfr" placeholder="ex: 2024-2025"
                   class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800">
          </div>
        </div>

        <!-- COL 3 -->
        <div class="flex flex-col gap-3">
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Medie IRSE</label>
            <input type="number" step="0.01" name="medie_irse" x-model="form.medie_irse"
                   class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800">
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Scor IRSE</label>
            <input type="number" step="0.01" name="scor_irse" x-model="form.scor_irse"
                   class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800">
          </div>
          <div class="flex items-center gap-2 min-h-[58px]">
            <input id="strategic" type="checkbox" name="strategic" :checked="form.strategic===1"
                   @change="form.strategic = $event.target.checked ? 1 : 0"
                   class="w-4 h-4 border rounded text-emerald-600 focus:ring-emerald-600">
            <label for="strategic" class="text-sm font-medium text-slate-800">Școală strategică</label>
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Index vulnerabilitate TFR</label>
            <select name="index_vulnerabilitate_tfr" x-model="form.index_vulnerabilitate_tfr"
                    class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800">
              <option value="">— Selectează —</option>
              <option value="1">1</option>
              <option value="2">2</option>
              <option value="3">3</option>
              <option value="3NO">3NO</option>
              <option value="4">4</option>
              <option value="5">5</option>
            </select>
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-700">Număr elevi SIIIR</label>
            <input type="number" step="1" name="numar_elevi_siiir" x-model="form.numar_elevi_siiir"
                   class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300 focus:ring-2 focus:ring-slate-800">
          </div>

          <div class="flex justify-end mt-1">
            <button type="submit"
                    class="inline-flex items-center gap-2 px-3 py-2 text-sm font-semibold text-white rounded-lg bg-emerald-600 hover:bg-emerald-700">
              Salvează
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</main>

<script>
function schoolsPage(){
  return {
    /* state filtre */
    s: <?php echo json_encode($s_init); ?>,
    county: <?php echo (int)$county_init; ?>,
    city: <?php echo (int)$city_init; ?>,

    /* sugestii */
    showSuggest: false,
    suggestions: [],

    /* modal */
    modalOpen: false,
    form: {
      id: 0, cod: '', name: '', short_name: '',
      county_id: 0, city_id: 0, village_id: 0,
      regiune_tfr: 'RMD', statut: '', tip: '', mediu: '', first_year_tfr: '',
      medie_irse: '', scor_irse: '',
      strategic: 0,
      index_vulnerabilitate_tfr: '',
      numar_elevi_siiir: ''
    },

    /* count vizibil (doar pe pagina curentă) */
    visibleCount: 0,
    totalCount: 0,

    get exportUrl(){
      try {
        const base = this.$root.dataset.export; // admin-post.php?...&nonce=...
        const u = new URL(base, window.location.origin);
        const q = (this.s || '').trim();
        if (q) u.searchParams.set('s', q);
        const county = +this.county || 0;
        if (county > 0) u.searchParams.set('county', county);
        const city = +this.city || 0;
        if (city > 0) u.searchParams.set('city', city);
        return u.toString();
      } catch(e){
        return this.$root.dataset.export;
      }
    },

    init(){
      // click în afara sugestiilor le închide
      document.addEventListener('click', (e)=>{
        if (!e.target.closest('.suggest-panel') && !e.target.closest('input')) this.showSuggest = false;
      });

      // total rânduri
      this.totalCount = document.querySelectorAll('#schools-table tbody tr[data-row]').length;
      this.filterRows();

      // dacă avem county preselectat (din cod), populează orașele
      if (this.county > 0) {
        this.loadCitiesForFilter(this.county, this.city).then(()=> this.filterRows());
      }
    },

    /* ========== Căutare AJAX pentru sugestii (nu navighează) ========== */
    async onSearchInput(){
      const q = (this.s || '').trim();
      if (q.length < 2) { this.showSuggest=false; this.suggestions=[]; return; }

      const fd = new FormData();
      fd.append('action','edu_search_schools');
      fd.append('nonce', this.$root.dataset.nonce);
      fd.append('q', q);

      try{
        const r = await fetch(this.$root.dataset.ajax, {method:'POST', body:fd, credentials:'same-origin'});
        const data = await r.json();
        this.suggestions = Array.isArray(data) ? data : [];
        this.showSuggest = true;
      }catch(e){
        this.showSuggest = false;
        this.suggestions = [];
      }
    },

    /* ========== Populare orașe pentru filtrul de sus ========== */
    async onCountyChange(){
      this.city = 0;
      await this.loadCitiesForFilter(this.county, 0);
      this.filterRows();
    },
    async loadCitiesForFilter(countyId, preselectId=0){
      const sel = this.$refs.filterCity;
      if (!sel) return;

      sel.innerHTML = `<option value="0">Încărcare orașe…</option>`;
      if (!countyId){ sel.innerHTML = `<option value="0">— Toate orașele —</option>`; return; }

      const fd = new FormData();
      fd.append('action','edu_get_cities');
      fd.append('nonce', this.$root.dataset.locnonce);
      fd.append('county_id', countyId);

      try{
        const r = await fetch(this.$root.dataset.ajax, {method:'POST', body:fd, credentials:'same-origin'});
        const html = await r.text();
        sel.innerHTML = html || `<option value="0">— Toate orașele —</option>`;
        if (preselectId && [...sel.options].some(o=> +o.value===+preselectId)){
          sel.value = String(preselectId);
          this.city = +preselectId;
        } else {
          sel.value = '0';
          this.city = 0;
        }
      }catch(e){
        sel.innerHTML = `<option value="0">Eroare la încărcare</option>`;
      }
    },

    /* ========== Filtrare tabel (client-side) ========== */
    filterRows(){
      const q = (this.s || '').toLowerCase().trim();
      const county = +this.county || 0;
      const city   = +this.city   || 0;

      const rows = document.querySelectorAll('#schools-table tbody tr[data-row]');
      let visible = 0;

      rows.forEach(tr=>{
        const sblob  = tr.getAttribute('data-search') || '';
        const rc     = +(tr.getAttribute('data-county') || 0);
        const rci    = +(tr.getAttribute('data-city')   || 0);

        let ok = true;
        if (q && !sblob.includes(q)) ok = false;
        if (ok && county>0 && rc !== county) ok = false;
        if (ok && city>0   && rci !== city)   ok = false;

        tr.style.display = ok ? '' : 'none';
        if (ok) visible++;
      });

      this.visibleCount = visible;

      // empty state
      const empty = document.getElementById('empty-state');
      if (empty) empty.classList.toggle('hidden', visible !== 0);
    },

    resetFilters(){
      this.s = '';
      this.county = 0;
      this.city = 0;
      // goliți dropdown orașe
      const sel = this.$refs.filterCity;
      if (sel) sel.innerHTML = `<option value="0">— Toate orașele —</option>`;
      this.filterRows();
    },

    /* ========== MODAL Add/Edit (orașe/sate) ========== */
    openAdd(){
      this.form = {
        id: 0,
        cod: '',
        name: '',
        short_name: '',
        county_id: this.county || 0,
        city_id: 0,
        village_id: 0,
        regiune_tfr: 'RMD',
        statut: '',
        tip: '',
        mediu: '',
        first_year_tfr: '',
        medie_irse: '',
        scor_irse: '',
        strategic: 0,
        index_vulnerabilitate_tfr: '',
        numar_elevi_siiir: ''
      };
      this.modalOpen = true;
      this.$nextTick(()=> this.loadCitiesForModal(0));
    },
    openEdit(row){
      this.form = {
        id: row.id || 0,
        cod: row.cod || '',
        name: row.name || '',
        short_name: row.short_name || '',
        county_id: row.county_id || 0,
        city_id: row.city_id || 0,
        village_id: row.village_id || 0,
        regiune_tfr: row.regiune_tfr || 'RMD',
        statut: row.statut || '',
        tip: row.tip || '',
        mediu: row.mediu || '',
        first_year_tfr: row.first_year_tfr || '',
        medie_irse: (row.medie_irse ?? ''),
        scor_irse: (row.scor_irse ?? ''),
        strategic: row.strategic || 0,
        index_vulnerabilitate_tfr: (row.index_vulnerabilitate_tfr ?? ''),
        numar_elevi_siiir: (row.numar_elevi_siiir ?? '')
      };
      this.modalOpen = true;
      this.$nextTick(()=> this.loadCitiesForModal(this.form.city_id));
    },
    closeModal(){ this.modalOpen = false; },

    onSubmit(e){ /* submit normal spre aceeași pagină (server-side) */ },

    onModalCityChange(evt){
      this.form.city_id = +evt.target.value || 0;
      this.form.village_id = 0;
      this.loadVillagesForModal(this.form.city_id, 0);
    },

    async loadCitiesForModal(preselectCityId=0){
      const sel = this.$refs.modalCitySelect;
      const vs  = this.$refs.modalVillageSelect;
      if (!sel) return;

      sel.innerHTML = `<option value="0">Încărcare orașe…</option>`;
      if (vs) vs.innerHTML = `<option value="0">— selectează —</option>`;

      const countyId = this.form.county_id || 0;
      if (!countyId){ sel.innerHTML = `<option value="0">— selectează —</option>`; return; }

      const fd = new FormData();
      fd.append('action','edu_get_cities');
      fd.append('nonce',  this.$root.dataset.locnonce);
      fd.append('county_id', countyId);

      try{
        const r = await fetch(this.$root.dataset.ajax, {method:'POST', body:fd, credentials:'same-origin'});
        const html = await r.text();
        sel.innerHTML = html || `<option value="0">— selectează —</option>`;

        const targetCity = preselectCityId || this.form.city_id || 0;
        if (targetCity && [...sel.options].some(o=> +o.value===+targetCity)) {
          sel.value = String(targetCity);
          this.form.city_id = +targetCity;
          await this.loadVillagesForModal(this.form.city_id, this.form.village_id || 0);
        } else {
          sel.value = '0';
          this.form.city_id = 0;
          if (vs) vs.innerHTML = `<option value="0">— selectează —</option>`;
        }
      }catch(e){
        sel.innerHTML = `<option value="0">Eroare la încărcare</option>`;
      }
    },

    async loadVillagesForModal(cityId, preselectVillageId=0){
      const vs = this.$refs.modalVillageSelect;
      if (!vs) return;

      vs.innerHTML = `<option value="0">Încărcare sate…</option>`;
      if (!cityId){ vs.innerHTML = `<option value="0">— selectează —</option>`; return; }

      const fd = new FormData();
      fd.append('action','edu_get_villages');
      fd.append('nonce',  this.$root.dataset.locnonce);
      fd.append('city_id', cityId);

      try{
        const r = await fetch(this.$root.dataset.ajax, {method:'POST', body:fd, credentials:'same-origin'});
        const html = await r.text();
        vs.innerHTML = html || `<option value="0">— fără sate —</option>`;

        const target = preselectVillageId || this.form.village_id || 0;
        if (target && [...vs.options].some(o=> +o.value===+target)) {
          vs.value = String(target);
          this.form.village_id = +target;
        } else {
          vs.value = '0';
          this.form.village_id = 0;
        }
      }catch(e){
        vs.innerHTML = `<option value="0">Eroare la încărcare</option>`;
      }
    },
  }
}
</script>