<?php
if (!defined('ABSPATH')) exit;

$current_user = wp_get_current_user();
$uid          = (int) ($current_user->ID ?? 0);
$roles        = (array) ($current_user->roles ?? []);
$is_admin     = current_user_can('manage_options');
$is_tutor     = in_array('tutor', $roles, true);

global $wpdb;
$tbl_schools  = $wpdb->prefix . 'edu_schools';
$tbl_cities   = $wpdb->prefix . 'edu_cities';
$tbl_counties = $wpdb->prefix . 'edu_counties';

/* ================= Helpers ================= */

function es_badge_u($label, $tone='slate'){
  $palette = [
    'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    'rose'    => 'bg-rose-50 text-rose-700 ring-rose-200',
    'amber'   => 'bg-amber-50 text-amber-800 ring-amber-200',
    'sky'     => 'bg-sky-50 text-sky-800 ring-sky-200',
    'slate'   => 'bg-slate-100 text-slate-700 ring-slate-200',
  ];
  $cls = $palette[$tone] ?? $palette['slate'];
  return '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset '.$cls.'">'.esc_html($label).'</span>';
}
function es_format_dt_u($ts_or_str){
  if (!$ts_or_str) return '—';
  $ts = is_numeric($ts_or_str) ? (int)$ts_or_str : strtotime($ts_or_str);
  if (!$ts) return '—';
  return date_i18n(get_option('date_format').' '.get_option('time_format'), $ts);
}
function es_user_fullname_u($u){
  $name = trim(($u->first_name ?? '').' '.($u->last_name ?? ''));
  if ($name === '') $name = $u->display_name ?: $u->user_login;
  return $name;
}
function es_status_badge_u($val){
  $v = strtolower(trim((string)$val));
  if ($v==='activ') return es_badge_u('Activ','emerald');
  if (in_array($v,['in_asteptare','suspendat','concediu_maternitate','concediu_studii'],true)) return es_badge_u(ucfirst(str_replace('_',' ',$v)),'amber');
  if (in_array($v,['drop-out','eliminat','inactiv','respins'],true)) return es_badge_u(ucfirst(str_replace('_',' ',$v)),'rose');
  return es_badge_u($v!==''?$v:'—','slate');
}

/* ================= Opțiuni roluri & status ================= */

$ROLE_LABELS = [
  'administrator' => 'Administrator',
  'editor'        => 'Editor',
  'tutor'         => 'Tutor',
  'profesor'      => 'Profesor',
  'alumni'        => 'Alumni',
  'non-teach'     => 'Non-Teach',
  'subscriber'    => 'Subscriber',
];

$PROF_STATUS = apply_filters('edu_professor_status_options', [
  'in_asteptare'         => 'În așteptare',
  'activ'                => 'Activ',
  'drop-out'             => 'Drop-out',
  'eliminat'             => 'Eliminat',
  'concediu_maternitate' => 'Concediu maternitate',
  'concediu_studii'      => 'Concediu studii',
]);
$TUTOR_STATUS = apply_filters('edu_tutor_status_options', [
  'in_asteptare' => 'În așteptare',
  'activ'        => 'Activ',
  'suspendat'    => 'Suspendat',
  'inactiv'      => 'Inactiv',
]);

$nivel_opts = ['prescolar'=>'Preșcolar','primar'=>'Primar','gimnazial'=>'Gimnazial','liceu'=>'Liceu'];
$statut_prof_opts = [
  'Suplinitor necalificat','Suplinitor calificat','Titular',
  'Titular pe viabilitatea postului','Director','Inspector'
];
$calificare_opts = ['Calificat','Necalificat'];
$experienta_opts = ['2 ani sau mai putin','3-5 ani','mai mult de 5 ani'];
$materii_default = [
  'Educator','Învățător','Română','Engleză','Franceză','Germană','Latină',
  'Geografie','Istorie','Biologie','Fizică','Chimie','Matematică',
  'Educație tehnologică','TIC','Cultură civică & Educație civică',
  'Educație socială','Religie','Arte','Educație plastică','Educație muzicală',
  'Educație fizică și sport','Învățământ special','Consilier școlar','Turcă','Alta'
];

/* ================= Filtre ================= */

// `s` este rezervat de WordPress pentru căutarea globală — folosim `q` ca să nu provoace 404 pe /panou/utilizatori/.
$s       = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q']))
         : (isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '');
$role_f  = isset($_GET['role']) ? sanitize_text_field(wp_unslash($_GET['role'])) : '';
$perpage = max(5, min(200, (int)($_GET['perpage'] ?? 25)));
$paged   = max(1, (int)($_GET['paged'] ?? 1));

/* ================= Query users ================= */

// Tutor non-admin: vede profesorii alocați + pe sine
$include_ids = [];
if ($is_tutor && !$is_admin) {
  $q = new WP_User_Query([
    'role'       => 'profesor',
    'number'     => -1,
    'meta_query' => [[
      'key'     => 'assigned_tutor_id',
      'value'   => $uid,
      'compare' => '=',
      'type'    => 'NUMERIC',
    ]],
  ]);
  $include_ids = array_map(fn($u)=>(int)$u->ID, $q->get_results());
  $include_ids[] = $uid;
}

$args = [
  'number'  => -1,
  'orderby' => 'display_name',
  'order'   => 'ASC',
];
if ($s !== '') {
  $args['search']         = '*'.esc_attr($s).'*';
  $args['search_columns'] = ['user_login','user_email','user_nicename','display_name'];
}
if ($role_f !== '') $args['role'] = $role_f;
if (!empty($include_ids)) $args['include'] = array_unique($include_ids);

$user_query = new WP_User_Query($args);
$all_users  = $user_query->get_results();

// listă roluri prezente (pt. dropdown Role)
$all_roles_present = [];
foreach ($all_users as $u) foreach ((array)$u->roles as $r) $all_roles_present[$r] = true;
$filter_role_list = array_values(array_unique(array_keys($all_roles_present)));
sort($filter_role_list, SORT_NATURAL);

// pagina curentă
usort($all_users, fn($a,$b)=> strcasecmp($a->display_name, $b->display_name));
$total       = count($all_users);
$total_pages = (int) max(1, ceil($total / $perpage));
$paged       = min($paged, $total_pages);
$offset      = ($paged - 1) * $perpage;
$users_page  = array_slice($all_users, $offset, $perpage);

// Nonce / endpoints / tutors
$edu_nonce   = wp_create_nonce('edu_nonce');
$ajax_url    = admin_url('admin-ajax.php');

$ROLE_OPTIONS = [
  'administrator' => 'Administrator',
    'editor'      => 'Editor',
  'profesor'   => 'Profesor',
  'tutor'      => 'Tutor',
  'alumni'     => 'Alumni',
  'non-teach'  => 'Non-Teach',
];
$all_tutors = get_users(['role'=>'tutor','orderby'=>'display_name','order'=>'ASC','number'=>-1]);

// base url pentru pagination
$qs = $_GET; unset($qs['paged']); $base_url = esc_url(add_query_arg($qs, remove_query_arg(['paged'])));

?>

<section class="sticky top-0 z-20 border-b inner-submenu bg-slate-800 border-slate-200">
  <div class="relative z-20 flex items-center justify-between px-2 py-2 gap-x-2">
    <div class="flex items-center justify-start">
      <button id="eu-open-add" type="button"
          class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-white rounded-md rounded-tl-xl bg-emerald-600 hover:bg-emerald-700">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M11 11V5a1 1 0 1 1 2 0v6h6a1 1 0 1 1 0 2h-6v6a1 1 0 1 1-2 0v-6H5a1 1 0 1 1 0-2h6Z"/></svg>
          Adaugă utilizator
      </button>
    </div>

    <div class="flex items-center justify-end gap-x-2">
      <a href="../documentatie/#adaugareutilizatori" target="_blank" rel="noopener noreferrer"
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

<!-- Filtre -->
<section class="px-6 my-6 mobile:px-2">
  <form method="get" class="grid items-end grid-cols-1 gap-3 md:grid-cols-12">
    <div class="md:col-span-5">
      <label class="block mb-1 text-xs font-medium text-slate-600">Căutare (nume/email)</label>
      <input type="text" name="q" value="<?php echo esc_attr($s); ?>"
             class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:outline-none focus:ring-1 focus:ring-sky-600 focus:border-transparent">
    </div>

    <div class="md:col-span-3">
      <label class="block mb-1 text-xs font-medium text-slate-600">Rol</label>
      <select name="role" class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:ring-1 focus:ring-sky-600 focus:border-transparent">
        <option value="">— Oricare —</option>
        <?php foreach ($filter_role_list as $rr): $lab = $ROLE_LABELS[$rr] ?? ucfirst($rr); ?>
          <option value="<?php echo esc_attr($rr); ?>" <?php selected($role_f===$rr); ?>><?php echo esc_html($lab); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="md:col-span-2">
      <label class="block mb-1 text-xs font-medium text-slate-600">Pe pagină</label>
      <input type="number" min="5" max="200" name="perpage" value="<?php echo (int)$perpage; ?>"
             class="w-full px-3 py-2 text-sm bg-white border shadow-sm rounded-xl border-slate-300 focus:outline-none focus:ring-1 focus:ring-sky-600 focus:border-transparent">
    </div>

    <div class="md:col-span-2">
      <button type="submit"
              class="inline-flex items-center justify-center w-full gap-2 px-3 py-2 text-sm font-medium text-white shadow-sm rounded-xl bg-emerald-600 hover:bg-emerald-700">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4">
          <path fill-rule="evenodd" d="M3.792 2.938A49.069 49.069 0 0 1 12 2.25c2.797 0 5.54.236 8.209.688a1.857 1.857 0 0 1 1.541 1.836v1.044a3 3 0 0 1-.879 2.121l-6.182 6.182a1.5 1.5 0 0 0-.439 1.061v2.927a3 3 0 0 1-1.658 2.684l-1.757.878A.75.75 0 0 1 9.75 21v-5.818a1.5 1.5 0 0 0-.44-1.06L3.13 7.938a3 3 0 0 1-.879-2.121V4.774c0-.897.64-1.683 1.542-1.836Z" clip-rule="evenodd" />
        </svg>
        <span>Filtrează</span>
      </button>
    </div>
  </form>
</section>

<!-- Tabel -->
<section class="px-6 pb-8 mobile:px-2 mobile:pb-12">
  <div class="relative overflow-x-auto bg-white border shadow-sm rounded-2xl border-slate-200">
    <table class="relative w-full text-sm table-fixed" id="eu-table">
      <thead class="sticky top-0 bg-sky-800 backdrop-blur">
        <tr class="text-white">
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200">[#id] Utilizator</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200">Email</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200">Rol</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200">Status</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200">Înregistrare</th>
          <th class="px-3 py-3 font-semibold text-left border-b border-slate-200">Ultima activitate</th>
          <th class="px-3 py-3 font-semibold text-center border-b border-slate-200">Acțiuni</th>
        </tr>
      </thead>
      <tbody class="[&>tr:nth-child(odd)]:bg-slate-50/40">
        <?php if (!empty($users_page)): ?>
          <?php foreach ($users_page as $u):
            $pid   = (int)$u->ID;
            $name  = es_user_fullname_u($u);
            $email = (string)$u->user_email;
            $roles_u = (array)$u->roles;
            $primary = $roles_u ? $roles_u[0] : '';

            // status per rol
            $status_val = '';
            if ($primary === 'profesor') {
              $status_val = (string)get_user_meta($pid,'user_status_profesor',true);
            } elseif ($primary === 'tutor') {
              $status_val = (string)(get_user_meta($pid,'user_status_tutor',true) ?: get_user_meta($pid,'tutor_status',true));
            } else {
              $status_val = (string)get_user_meta($pid,'user_status',true);
            }

            $last = get_user_meta($pid,'last_login',true);
            if(!$last) $last = get_user_meta($pid,'last_activity',true);
            if(!$last) $last = get_user_meta($pid,'last_seen',true);
            $registered_ts = $u->user_registered ? strtotime($u->user_registered) : 0;

            // permisiuni rând
            $row_sensitive = in_array('administrator',$roles_u,true) || in_array('editor',$roles_u,true);
            $can_edit   = $is_admin || (!$row_sensitive && $pid === $uid) || (!$row_sensitive && $primary!=='administrator');
            $can_delete = $is_admin && !$row_sensitive && ($pid !== $uid);

            // ===== payload EDIT =====
            $phone           = get_user_meta($pid,'phone',true) ?: get_user_meta($pid,'billing_phone',true);
            $nivel_val       = get_user_meta($pid,'nivel_predare',true);
            $mat             = get_user_meta($pid,'materia_predata',true);
            $materia_alta    = get_user_meta($pid,'materia_alta',true);
            $cod_slf         = get_user_meta($pid,'cod_slf',true);
            $statut_prof_v   = get_user_meta($pid,'statut_prof',true);
            $calificare_v    = get_user_meta($pid,'calificare',true);
            $experienta_v    = get_user_meta($pid,'experienta',true);
            $rsoi_v          = get_user_meta($pid,'segment_rsoi',true);
            $teach_v         = get_user_meta($pid,'generatie',true);
            $an_prog         = get_user_meta($pid,'an_program',true);
            $mentor_sel      = get_user_meta($pid,'mentor_sel',true);
            $mentor_lit      = get_user_meta($pid,'mentor_literatie',true);
            $mentor_num      = get_user_meta($pid,'mentor_numeratie',true);
            $assigned_tutor_id = (int)get_user_meta($pid,'assigned_tutor_id',true);

            // școli atribuite
            $sids = get_user_meta($pid, 'assigned_school_ids', true);
            $sids = is_array($sids) ? array_filter(array_map('intval',$sids)) : [];
            $schools_detailed = [];
            if (!empty($sids)) {
              $in = implode(',', array_fill(0, count($sids), '%d'));
              $rows = $wpdb->get_results($wpdb->prepare("
                SELECT s.id, s.name, s.code AS cod, c.name AS city, j.name AS county
                FROM {$tbl_schools} s
                LEFT JOIN {$tbl_cities} c  ON s.city_id = c.id
                LEFT JOIN {$tbl_counties} j ON c.county_id = j.id
                WHERE s.id IN ($in)
              ", ...$sids));
              foreach ($rows as $r) {
                $schools_detailed[] = [
                  'id'     => (int)$r->id,
                  'name'   => (string)$r->name,
                  'cod'    => (string)($r->cod ?: ''),
                  'city'   => (string)($r->city ?: ''),
                  'county' => (string)($r->county ?: ''),
                ];
              }
            }

            $edit_payload = [
              'id'                   => $pid,
              'first_name'           => (string)$u->first_name,
              'last_name'            => (string)$u->last_name,
              'email'                => (string)$email,
              'phone'                => (string)($phone ?: ''),
              'role'                 => (string)$primary,

              // ---- PROFESOR: TOT SETUL ----
              'user_status_profesor' => (string)$status_val,
              'nivel_predare'        => is_array($nivel_val) ? (string)($nivel_val[0] ?? '') : (string)$nivel_val,
              'materia_predata'      => (string)$mat,
              'materia_alta'         => (string)($materia_alta ?: ''),
              'cod_slf'              => (string)($cod_slf ?: ''),
              'statut_prof'          => (string)($statut_prof_v ?: ''),
              'calificare'           => (string)($calificare_v ?: ''),
              'experienta'           => (string)($experienta_v ?: ''),
              'segment_rsoi'         => (string)($rsoi_v ?: ''),
              'generatie'            => (string)($teach_v ?: ''),
              'an_program'           => (string)($an_prog ?: ''),
              'assigned_tutor_id'    => (int)$assigned_tutor_id,
              'mentor_sel'           => (string)($mentor_sel ?: ''),
              'mentor_literatie'     => (string)($mentor_lit ?: ''),
              'mentor_numeratie'     => (string)($mentor_num ?: ''),
              'assigned_school_ids'  => $sids,
              'schools_detailed'     => $schools_detailed,

              // ---- TUTOR ----
              'user_status_tutor'    => (string)(get_user_meta($pid,'user_status_tutor',true) ?: get_user_meta($pid,'tutor_status',true)),

              // ---- GENERIC ----
              'user_status'          => (string)get_user_meta($pid,'user_status',true),
            ];
          ?>
          <tr class="transition-colors border-b border-slate-200 hover:bg-slate-50 odd:bg-white even:bg-slate-200">
            <td class="px-3 py-3 align-center">
              <div class="min-w-0">
                <div class="font-semibold truncate text-slate-900"><span class="p-1 text-xs border bg-slate-100 text-slate-600 border-slate-300">#<?php echo (int)$pid; ?></span> <?php echo esc_html($name); ?></div>
              </div>
            </td>
            <td class="px-3 py-3 text-xs font-semibold align-center text-slate-600">
              <a class="hover:underline" href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a>
            </td>
            <td class="px-3 py-3 align-center text-slate-800">
              <?php
                $labels = array_map(fn($r)=>($ROLE_LABELS[$r]??ucfirst($r)), $roles_u);
                echo esc_html(implode(', ', $labels));
              ?>
            </td>
            <td class="px-3 py-3 text-center align-center"><?php echo es_status_badge_u($status_val); ?></td>
            <td class="px-3 py-3 text-xs align-center text-slate-700"><?php echo esc_html(es_format_dt_u($registered_ts)); ?></td>
            <td class="px-3 py-3 text-xs align-center text-slate-700"><?php echo esc_html(es_format_dt_u($last)); ?></td>
            <td class="px-3 py-3 text-center align-center">
              <div class="flex flex-wrap items-center justify-center gap-2">
                <?php if ($can_edit): ?>
                <button type="button"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-slate-800 rounded-full shadow-sm bg-slate-100 hover:bg-slate-200 eu-edit-btn"
                        data-user='<?php echo esc_attr( wp_json_encode($edit_payload, JSON_UNESCAPED_UNICODE) ); ?>'>
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                  </svg>
                  Edit
                </button>
                <?php endif; ?>
                <?php if ($can_delete): ?>
                <button type="button"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium text-white rounded-full shadow-sm bg-rose-600 hover:bg-rose-700 eu-delete-btn"
                        data-user-id="<?php echo (int)$pid; ?>" data-name="<?php echo esc_attr($name); ?>">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-3">
                    <path fill-rule="evenodd" d="M16.5 4.478v.227a48.816 48.816 0 0 1 3.878.512.75.75 0 1 1-.256 1.478l-.209-.035-1.005 13.07a3 3 0 0 1-2.991 2.77H8.084a3 3 0 0 1-2.991-2.77L4.087 6.66l-.209.035a.75.75 0 0 1-.256-1.478A48.567 48.567 0 0 1 7.5 4.705v-.227c0-1.564 1.213-2.9 2.816-2.951a52.662 52.662 0 0 1 3.369 0c1.603.051 2.815 1.387 2.815 2.951Zm-6.136-1.452a51.196 51.196 0 0 1 3.273 0C14.39 3.05 15 3.684 15 4.478v.113a49.488 49.488 0 0 0-6 0v-.113c0-.794.609-1.428 1.364-1.452Zm-.355 5.945a.75.75 0 1 0-1.5.058l.347 9a.75.75 0 1 0 1.499-.058l-.346-9Zm5.48.058a.75.75 0 1 0-1.498-.058l-.347 9a.75.75 0 0 0 1.5.058l.345-9Z" clip-rule="evenodd" />
                  </svg>
                  Șterge
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="7" class="px-4 py-6 text-center text-slate-500">Nu s-au găsit utilizatori.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
    <div class="flex items-center justify-between mt-4">
      <div class="text-sm text-slate-600">Afișezi <strong><?php echo (int)min($total, $offset + $perpage); ?></strong> din <strong><?php echo (int)$total; ?></strong> utilizatori.</div>
      <div class="flex items-center gap-2">
        <?php $mk = fn($p)=> esc_url(add_query_arg('paged', max(1,(int)$p), $base_url)); ?>
        <a href="<?php echo $mk($paged-1); ?>" class="px-3 py-1 text-sm border rounded-lg <?php echo $paged<=1?'pointer-events-none opacity-40':''; ?>">« Înapoi</a>
        <span class="px-2 text-sm text-slate-700">Pagina <?php echo (int)$paged; ?> / <?php echo (int)$total_pages; ?></span>
        <a href="<?php echo $mk($paged+1); ?>" class="px-3 py-1 text-sm border rounded-lg <?php echo $paged>=$total_pages?'pointer-events-none opacity-40':''; ?>">Înainte »</a>
      </div>
    </div>
  <?php endif; ?>
</section>

<!-- MODAL: Add/Edit user -->
<div id="eu-modal" class="fixed inset-0 z-[100] hidden">
  <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>
  <div class="relative max-w-5xl mx-auto my-8">
    <div class="mx-4 overflow-hidden bg-white border shadow-xl rounded-2xl border-slate-200">
      <div class="flex items-center justify-between px-5 py-4 border-b bg-slate-50 border-slate-200">
        <h3 id="eu-modal-title" class="text-base font-semibold text-slate-900">Adaugă utilizator</h3>
        <button type="button" id="eu-close" class="p-2 text-slate-500 hover:text-slate-700">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M6 6l12 12M6 18 18 6"/></svg>
        </button>
      </div>

      <form id="eu-form" class="px-5 py-4" enctype="multipart/form-data">
        <input type="hidden" name="nonce" value="<?php echo esc_attr($edu_nonce); ?>">
        <input type="hidden" name="user_id" id="eu-user-id" value="">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
          <!-- Nume / Prenume / Email -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Prenume</label>
            <input name="first_name" type="text" required class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Nume</label>
            <input name="last_name" type="text" required class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Email (va fi și user_login)</label>
            <input name="email" type="email" required class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
          </div>

          <!-- Telefon / Rol / Parolă -->
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Telefon</label>
            <input name="phone" type="text" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Rol</label>
            <select name="user_role" id="eu-role" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
              <?php foreach ($ROLE_OPTIONS as $rk=>$lab): ?>
                <option value="<?php echo esc_attr($rk); ?>"><?php echo esc_html($lab); ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!$is_admin): ?>
              <p class="mt-1 text-[11px] text-slate-500">Notă: doar Administratorii pot crea Editor/Administrator.</p>
            <?php endif; ?>
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Parolă <span class="text-slate-400">(opțional)</span></label>
            <input name="user_pass" type="password" autocomplete="new-password" placeholder="Setează parolă" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
            <input name="password" type="hidden"> <!-- compat handler plugin -->
          </div>
          <div>
            <label class="block mb-1 text-xs font-medium text-slate-600">Confirmă parola</label>
            <input id="eu-pass2" type="password" autocomplete="new-password" placeholder="Confirmă parolă" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
          </div>

          <!-- ===== PROFESOR: set complet ===== -->
          <div class="hidden md:col-span-3 eu-role-prof">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">

              <!-- Status / Nivel / Materie (+ Alta) -->
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Status profesor</label>
                <select name="user_status_profesor" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                  <?php foreach ($PROF_STATUS as $k=>$lab): ?>
                    <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($lab); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Nivel predare</label>
                <select name="nivel_predare" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                  <?php foreach ($nivel_opts as $k=>$lab): ?>
                    <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($lab); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Materia predată</label>
                <select id="eu-materia" name="materia_predata" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                  <?php foreach ($materii_default as $m): ?>
                    <option value="<?php echo esc_attr($m); ?>"><?php echo esc_html($m); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div id="eu-materia-alta-wrap" class="hidden">
                <label class="block mb-1 text-xs font-medium text-slate-600">Materia (altă)</label>
                <input name="materia_alta" type="text" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
              </div>

              <!-- Statut/Calificare/Experiență -->
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Statut</label>
                <select name="statut_prof" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                  <?php foreach ($statut_prof_opts as $st): ?>
                    <option value="<?php echo esc_attr($st); ?>"><?php echo esc_html($st); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Calificare</label>
                <select name="calificare" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                  <?php foreach ($calificare_opts as $st): ?>
                    <option value="<?php echo esc_attr($st); ?>"><?php echo esc_html($st); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Experiență</label>
                <select name="experienta" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                  <?php foreach ($experienta_opts as $st): ?>
                    <option value="<?php echo esc_attr($st); ?>"><?php echo esc_html($st); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- RSOI / Teach / An program / Cod SLF -->
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Segment RSOI</label>
                <input name="segment_rsoi" type="text" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
              </div>
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Generație (Teach)</label>
                <input name="generatie" type="text" placeholder="ex: G12" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
              </div>
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">An program</label>
                <input name="an_program" type="text" placeholder="ex: 2024-2025" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
              </div>
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Cod SLF</label>
                <input name="cod_slf" type="text" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
              </div>

              <!-- Tutor & Mentori -->
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Tutor coordonator</label>
                <select name="assigned_tutor_id" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300" <?php echo ($is_tutor && !$is_admin) ? 'disabled' : '';?>>
                  <option value="">—</option>
                  <?php foreach ($all_tutors as $t): ?>
                    <option value="<?php echo (int)$t->ID; ?>" <?php echo ($is_tutor && !$is_admin && (int)$t->ID === $uid) ? 'selected' : ''; ?>>
                      <?php echo esc_html($t->display_name ?: ($t->first_name.' '.$t->last_name)); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Mentor SEL</label>
                <select name="mentor_sel" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                  <option value="">—</option>
                  <?php foreach ($all_tutors as $t): ?>
                    <option value="<?php echo (int)$t->ID; ?>"><?php echo esc_html($t->display_name); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Mentor LIT</label>
                <select name="mentor_literatie" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                  <option value="">—</option>
                  <?php foreach ($all_tutors as $t): ?>
                    <option value="<?php echo (int)$t->ID; ?>"><?php echo esc_html($t->display_name); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Mentor NUM</label>
                <select name="mentor_numeratie" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                  <option value="">—</option>
                  <?php foreach ($all_tutors as $t): ?>
                    <option value="<?php echo (int)$t->ID; ?>"><?php echo esc_html($t->display_name); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Școli (căutare AJAX + selecție) -->
              <div class="md:col-span-3">
                <label class="block mb-1 text-xs font-medium text-slate-600">Școli atribuite</label>
                <div class="flex items-center gap-2">
                  <input id="eu-school-search" type="text" placeholder="Caută după nume/cod SIIIR/oras/județ..."
                          class="flex-1 px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                  <button id="eu-school-search-btn" type="button"
                          class="px-3 py-2 text-sm font-medium text-white rounded-xl bg-slate-700 hover:bg-slate-800">Caută</button>
                </div>
                <div id="eu-school-results" class="hidden mt-2 overflow-hidden border divide-y rounded-xl border-slate-200 divide-slate-200"></div>
                <div id="eu-school-selected" class="flex flex-wrap gap-2 mt-3"></div>
              </div>
            </div>
          </div>

          <!-- ===== TUTOR: status ===== -->
          <div class="hidden md:col-span-3 eu-role-tutor">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
              <div>
                <label class="block mb-1 text-xs font-medium text-slate-600">Status tutor</label>
                <select name="user_status_tutor" class="w-full px-3 py-2 text-sm bg-white border rounded-xl border-slate-300">
                  <?php foreach ($TUTOR_STATUS as $k=>$lab): ?>
                    <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($lab); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <!-- Alumni / Non-Teach / Editor / Admin => fără câmpuri extra aici -->
        </div>

        <div class="flex items-center justify-end gap-2 pt-5 mt-5 border-t border-slate-200">
          <button type="button" id="eu-cancel" class="px-3 py-2 text-sm bg-white border rounded-xl hover:bg-slate-50 border-slate-300">Anulează</button>
          <button id="eu-submit" type="submit" class="px-3 py-2 text-sm text-white rounded-xl bg-emerald-600 hover:bg-emerald-700">
            Salvează
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL: Confirm Delete -->
<div id="eu-del-modal" class="fixed inset-0 z-[110] hidden">
  <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>
  <div class="relative max-w-md mx-auto my-8">
    <div class="mx-4 overflow-hidden bg-white border shadow-xl rounded-2xl border-slate-200">
      <div class="px-5 py-4 border-b bg-slate-50 border-slate-200">
        <h3 class="text-base font-semibold text-slate-900">Ștergere utilizator</h3>
      </div>
      <div class="px-5 py-4 text-sm text-slate-700">
        Ești sigur(ă) că vrei să ștergi utilizatorul <strong id="eu-del-name"></strong>? Acțiunea nu poate fi anulată.
      </div>
      <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-slate-200">
        <button type="button" id="eu-cancel-del" class="px-3 py-2 text-sm bg-white border rounded-xl hover:bg-slate-50 border-slate-300">Anulează</button>
        <button type="button" id="eu-confirm-del" class="px-3 py-2 text-sm text-white rounded-xl bg-rose-600 hover:bg-rose-700">Șterge</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const ajaxUrl = "<?php echo esc_js($ajax_url); ?>";
  const nonce   = "<?php echo esc_js($edu_nonce); ?>";
  const isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
  const isTutorOnly = <?php echo json_encode($is_tutor && !$is_admin); ?>;
  const myTutorId   = <?php echo (int)$uid; ?>;

  const SAVE_ACTION   = 'edu_save_user_form';
  const DELETE_ACTION = 'edu_delete_user';
  const SEARCH_SCHOOL = 'edu_search_schools';

  const $  = (s, r=document)=> r.querySelector(s);
  const $$ = (s, r=document)=> Array.from(r.querySelectorAll(s));
  const show = el => el && el.classList.remove('hidden');
  const hide = el => el && el.classList.add('hidden');

  // ====== Modal Add/Edit ======
  const modal = $('#eu-modal');
  const form  = $('#eu-form');
  const titleEl = $('#eu-modal-title');
  const submitBtn = $('#eu-submit');
  const roleSel = $('#eu-role');
  const userId  = $('#eu-user-id');
  const pass2   = $('#eu-pass2');

  const blkProf  = $('.eu-role-prof');
  const blkTutor = $('.eu-role-tutor');
  const materiaSel = $('#eu-materia');
  const materiaAltaWrap = $('#eu-materia-alta-wrap');

  // Școli UI (în blocul profesor)
  const selectedWrap = $('#eu-school-selected');
  const resultsWrap  = $('#eu-school-results');
  const searchInput  = $('#eu-school-search');

  function toggleRoleBlocks(){
    const role = roleSel.value;
    [blkProf, blkTutor].forEach(hide);
    if (role === 'profesor') show(blkProf);
    if (role === 'tutor')    show(blkTutor);
    toggleMateriaAlta();
  }
  roleSel?.addEventListener('change', toggleRoleBlocks);
  materiaSel?.addEventListener('change', toggleMateriaAlta);
  function toggleMateriaAlta(){ if (!materiaSel) return; (materiaSel.value === 'Alta') ? show(materiaAltaWrap) : hide(materiaAltaWrap); }

  $('#eu-open-add')?.addEventListener('click', () => openAdd());
  $('#eu-close')?.addEventListener('click', closeModal);
  $('#eu-cancel')?.addEventListener('click', closeModal);

  function openAdd(){
    resetForm();
    titleEl.textContent = 'Adaugă utilizator';
    userId.value = '';
    if (!isAdmin && (roleSel.value === 'administrator' || roleSel.value === 'editor')) roleSel.value = 'profesor';
    toggleRoleBlocks();
    show(modal);
  }

  function openEdit(data){
    resetForm();
    titleEl.textContent = 'Editează utilizator';
    userId.value = String(data.id||'');
    setVal('first_name', data.first_name);
    setVal('last_name',  data.last_name);
    setVal('email',      data.email);
    setVal('phone',      data.phone);
    setVal('user_role',  data.role);

    if (data.role === 'profesor') {
      setVal('user_status_profesor', data.user_status_profesor);
      setVal('nivel_predare', data.nivel_predare);
      setVal('materia_predata', data.materia_predata);
      setVal('materia_alta', data.materia_alta);
      setVal('cod_slf', data.cod_slf);
      setVal('statut_prof', data.statut_prof);
      setVal('calificare', data.calificare);
      setVal('experienta', data.experienta);
      setVal('segment_rsoi', data.segment_rsoi);
      setVal('generatie', data.generatie);
      setVal('an_program', data.an_program);
      if(!isTutorOnly) setVal('assigned_tutor_id', data.assigned_tutor_id);
      setVal('mentor_sel', data.mentor_sel);
      setVal('mentor_literatie', data.mentor_literatie);
      setVal('mentor_numeratie', data.mentor_numeratie);

      // Școli preselectate
      if (selectedWrap) selectedWrap.innerHTML = '';
      if (Array.isArray(data.schools_detailed)) {
        data.schools_detailed.forEach(addSelectedSchool);
      } else if (Array.isArray(data.assigned_school_ids)) {
        data.assigned_school_ids.forEach(id => addSelectedSchool({id, name: 'Școală #'+id, city:'', county:'', cod:''}));
      }
    } else if (data.role === 'tutor') {
      setVal('user_status_tutor', data.user_status_tutor || data.tutor_status || '');
    } else {
      setVal('user_status', data.user_status || '');
    }

    toggleRoleBlocks();
    show(modal);
  }

  function closeModal(){ hide(modal); resetForm(); }
  function setVal(name, val){
    const el = form.querySelector(`[name="${name}"]`);
    if (!el) return;
    if (el.tagName === 'SELECT') {
      el.value = (val ?? '').toString();
      el.dispatchEvent(new Event('change', {bubbles:true}));
    } else {
      el.value = (val ?? '').toString();
    }
  }
  function resetForm(){
    form.reset();
    if (selectedWrap) selectedWrap.innerHTML = '';
    hide(resultsWrap);
    if (!isAdmin && (roleSel.value === 'administrator' || roleSel.value === 'editor')) roleSel.value = 'profesor';
    toggleRoleBlocks();
  }

  // ====== School search & selection (AJAX) ======
  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
  function addSelectedSchool(item){
    if (!item || !item.id || !selectedWrap) return;
    if (selectedWrap.querySelector(`[data-school-id="${item.id}"]`)) return;
    const tag = document.createElement('span');
    tag.className = 'inline-flex items-center gap-1 px-2 py-1 text-xs rounded-full bg-sky-50 text-sky-800 ring-1 ring-sky-200';
    tag.setAttribute('data-school-id', item.id);
    tag.innerHTML = `
      <input type="hidden" name="assigned_school_ids[]" value="${item.id}">
      <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 1.5 9 12 15 22.5 9 12 3Z"/><path d="M3 10.5v5.25L12 21l9-5.25V10.5" opacity=".4"/></svg>
      <span class="font-medium">${escapeHtml(item.name||('Școală #'+item.id))}</span>
      <span class="text-slate-500">•</span>
      <span class="text-slate-500">${escapeHtml(item.city||'')}${item.city&&item.county?' / ':''}${escapeHtml(item.county||'')}</span>
      <button type="button" class="ml-1 rounded hover:bg-sky-100 p-0.5" aria-label="Remove">
        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor"><path d="M6 6l12 12M6 18 18 6"/></svg>
      </button>
    `;
    tag.querySelector('button').addEventListener('click', () => tag.remove());
    selectedWrap.appendChild(tag);
  }
  function renderResults(list){
    if(!resultsWrap) return;
    resultsWrap.innerHTML = '';
    if(!list || !list.length){ hide(resultsWrap); return; }
    show(resultsWrap);
    list.forEach(item => {
      const row = document.createElement('button');
      row.type = 'button';
      row.className = 'w-full text-left px-3 py-2 text-sm hover:bg-slate-50';
      row.innerHTML = `<div class="font-medium">${escapeHtml(item.name)}</div>
                      <div class="text-xs text-slate-500">${escapeHtml(item.city||'')}${item.city&&item.county?' / ':''}${escapeHtml(item.county||'')} • cod: ${escapeHtml(item.cod||'—')}</div>`;
      row.addEventListener('click', () => { addSelectedSchool(item); hide(resultsWrap); });
      resultsWrap.appendChild(row);
    });
  }
  function searchSchools(){
    if(!searchInput) return;
    const q = searchInput.value.trim();
    if(q.length < 2){ hide(resultsWrap); return; }
    const fd = new FormData();
    fd.append('action', SEARCH_SCHOOL);
    fd.append('nonce', nonce);
    fd.append('q', q);
    fetch(ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' })
      .then(async r => {
        const txt = await r.text();
        let resp = {};
        try { resp = JSON.parse(txt); } catch(e){ resp = {success:false, data:[]}; }
        const arr = Array.isArray(resp?.data) ? resp.data : (Array.isArray(resp) ? resp : (Array.isArray(resp?.items) ? resp.items : []));
        renderResults(arr);
      })
      .catch(()=> hide(resultsWrap));
  }
  let tmr=null;
  searchInput?.addEventListener('input', () => { clearTimeout(tmr); tmr = setTimeout(searchSchools, 250); });
  $('#eu-school-search-btn')?.addEventListener('click', searchSchools);
  searchInput?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); searchSchools(); } });
  document.addEventListener('click', (e)=>{ if(resultsWrap && !resultsWrap.contains(e.target) && e.target!==resultsWrap && e.target!==searchInput){ hide(resultsWrap); } });

  // ====== Submit Save (add/edit) ======
  form?.addEventListener('submit', function(e){
    e.preventDefault();

    if (!isAdmin && (roleSel.value === 'administrator' || roleSel.value === 'editor')) {
      toast('Doar administratorii pot crea sau edita Admin/Editor.', 'err'); return;
    }

    // validare parolă
    const pass = form.querySelector('[name="user_pass"]').value.trim();
    const passCompat = form.querySelector('[name="password"]');
    if (pass !== '' || (pass2 && pass2.value.trim() !== '')) {
      if (pass.length < 8) { toast('Parola trebuie să aibă minim 8 caractere.', 'err'); return; }
      if (pass2 && pass !== pass2.value.trim()) { toast('Parolele nu coincid.', 'err'); return; }
      passCompat.value = pass;
    }

    // user_login = email
    const email = form.querySelector('[name="email"]').value.trim();
    let loginHidden = form.querySelector('[name="user_login"]');
    if (!loginHidden) { loginHidden = document.createElement('input'); loginHidden.type='hidden'; loginHidden.name='user_login'; form.appendChild(loginHidden); }
    loginHidden.value = email;

    // tutor-only: asigurăm assigned_tutor_id pentru profesor
    if (isTutorOnly && roleSel.value === 'profesor' && !form.querySelector('input[name="assigned_tutor_id"]')) {
      const h = document.createElement('input');
      h.type='hidden'; h.name='assigned_tutor_id'; h.value = String(myTutorId);
      form.appendChild(h);
    }

    const fd = new FormData(this);
    fd.append('action', SAVE_ACTION);
    if (!isAdmin && (fd.get('user_role') === 'administrator' || fd.get('user_role') === 'editor')) {
      fd.set('user_role', 'profesor');
    }

    submitBtn.disabled = true; const oldTxt = submitBtn.textContent; submitBtn.textContent = 'Se salvează...';

    fetch(ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' })
      .then(async r => {
        const txt = await r.text();
        let resp = {};
        try { resp = JSON.parse(txt); } catch(e){ resp = {success:false, data:{message:txt}}; }
        if (resp?.success) {
          toast('Salvat cu succes.', 'ok');
          closeModal();
          setTimeout(()=>location.reload(), 600);
        } else {
          const msg = (resp && resp.data && (resp.data.message||resp.data)) ? (resp.data.message||resp.data) : 'Eroare la salvare.';
          toast(msg, 'err');
        }
      })
      .catch(()=> toast('Eroare de rețea.', 'err'))
      .finally(()=> { submitBtn.disabled=false; submitBtn.textContent = oldTxt; });
  });

  // ====== Edit/Delete butoane ======
  $$('.eu-edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      try {
        const data = JSON.parse(btn.getAttribute('data-user')||'{}');
        const sensitive = data.role === 'administrator' || data.role === 'editor';
        if (!isAdmin && sensitive) { toast('Doar administratorii pot edita Admin/Editor.', 'err'); return; }
        openEdit(data);
      } catch(e){ console.error('Bad data-user JSON', e); }
    });
  });

  const delModal   = $('#eu-del-modal');
  const delNameEl  = $('#eu-del-name');
  const delCancel  = $('#eu-cancel-del');
  const delConfirm = $('#eu-confirm-del');
  let delState = { id:null, row:null };

  $$('.eu-delete-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const id   = btn.getAttribute('data-user-id');
      const name = btn.getAttribute('data-name') || ('#'+id);
      delState.id = id;
      delState.row = btn.closest('tr');
      delNameEl.textContent = name;
      show(delModal);
    });
  });
  delCancel?.addEventListener('click', () => { hide(delModal); delState = {id:null,row:null}; });
  $('#eu-del-modal .absolute.inset-0')?.addEventListener('click', (e) => {
    if (e.target === e.currentTarget) { hide(delModal); delState = {id:null,row:null}; }
  });
  delConfirm?.addEventListener('click', () => {
    if(!delState.id) return;
    const fd = new FormData();
    fd.append('action', DELETE_ACTION);
    fd.append('nonce', nonce);
    fd.append('user_id', delState.id);

    delConfirm.disabled = true; delConfirm.textContent = 'Se șterge...';
    fetch(ajaxUrl, { method:'POST', body: fd, credentials:'same-origin' })
      .then(async r => {
        const txt = await r.text();
        let resp = {};
        try { resp = JSON.parse(txt); } catch(e){ resp = {success:false, data:{message:txt}}; }
        if(resp?.success){
          toast('Utilizator șters.', 'ok');
          hide(delModal);
          delState.row?.remove();
          delState = { id:null, row:null };
        } else {
          const msg = (resp && resp.data && (resp.data.message||resp.data)) ? (resp.data.message||resp.data) : 'Nu am putut șterge utilizatorul.';
          toast(msg, 'err');
        }
      })
      .catch(()=> toast('Eroare de rețea la ștergere.', 'err'))
      .finally(()=> { delConfirm.disabled=false; delConfirm.textContent='Șterge'; });
  });

  // ====== Toast helper ======
  function toast(msg, type){
    const n = document.createElement('div');
    n.className = 'fixed bottom-4 right-4 px-4 py-2 text-sm text-white rounded-lg shadow-lg ' + (type==='ok'?'bg-emerald-600':'bg-rose-600');
    n.textContent = msg;
    document.body.appendChild(n);
    setTimeout(()=> n.remove(), 1800);
  }
})();
</script>
