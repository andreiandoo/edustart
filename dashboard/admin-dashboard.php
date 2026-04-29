<?php
/**
 * Template: Dashboard Admin (Premium)
 * Locație sugerată: page-panou-admin.php
 */

if ( ! defined('ABSPATH') ) exit;

// ——— Access control: doar Admin
$current_user = wp_get_current_user();
$user_fname = get_user_meta($current_user->ID, 'first_name', true);
$is_admin     = current_user_can('manage_options');
if (!$is_admin) {
  wp_safe_redirect( home_url('/panou') );
  exit;
}

$debug = !empty($_GET['debug']);

// ——— DB tables
global $wpdb;
$tbl_users       = $wpdb->users;
$tbl_umeta       = $wpdb->usermeta;
$tbl_generations = $wpdb->prefix . 'edu_generations';
$tbl_students    = $wpdb->prefix . 'edu_students';
$tbl_schools     = $wpdb->prefix . 'edu_schools';

// ——— Helpers
function ad_cap_key(){
  return is_multisite() ? $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities' : $GLOBALS['wpdb']->prefix . 'capabilities';
}
function ad_fmt_num($n){ return number_format_i18n((int)$n); }
function ad_initials($first, $last, $display=''){
  $ini = '';
  if ($first || $last) {
    if ($first) $ini .= mb_strtoupper(mb_substr($first,0,1));
    if ($last)  $ini .= mb_strtoupper(mb_substr($last,0,1));
  } else {
    $parts = preg_split('/\s+/', trim($display ?: '' ));
    if ($parts && $parts[0]) $ini .= mb_strtoupper(mb_substr($parts[0],0,1));
    if ($parts && count($parts)>1) $ini .= mb_strtoupper(mb_substr(end($parts),0,1));
  }
  return mb_substr($ini,0,2);
}
function ad_prof_name($u) {
  $name = trim(($u->first_name ?? '').' '.($u->last_name ?? ''));
  if ($name === '') $name = $u->display_name ?: $u->user_login;
  return $name;
}
function ad_fmt_dt($ts_or_str){
  if (!$ts_or_str) return '—';
  $ts = is_numeric($ts_or_str) ? (int)$ts_or_str : strtotime($ts_or_str);
  if (!$ts) return '—';
  return date_i18n(str_replace('Y', "\'y", get_option('date_format')), $ts) . ' <span class="mobile:block">' . date_i18n(get_option('time_format'), $ts) . '</span>';
}

/** Count users by WP role */
function ad_count_role($role){
  global $wpdb, $tbl_umeta;
  $cap_key = ad_cap_key();
  $like = '%"'.esc_sql($role).'"%';
  $sql  = $wpdb->prepare("SELECT COUNT(*) FROM {$tbl_umeta} WHERE meta_key = %s AND meta_value LIKE %s", $cap_key, $like);
  return (int)$wpdb->get_var($sql);
}

/** Count profesori by status (activ / in asteptare) din meta */
function ad_count_profesori_by_status(array $wanted_statuses){
  global $wpdb, $tbl_umeta;
  $cap_key = ad_cap_key();
  $norm = function($s){
    $s = strtolower(trim((string)$s));
    $map = ['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t'];
    $s = strtr($s, $map);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
  };
  $targets = array_unique(array_map($norm, $wanted_statuses));
  if (empty($targets)) return 0;

  $like_prof = '%"profesor"%';
  $cap_key_q = esc_sql($cap_key);
  $rows = $wpdb->get_results("
    SELECT u1.user_id, u2.meta_value
    FROM {$tbl_umeta} u1
    LEFT JOIN {$tbl_umeta} u2
      ON u2.user_id = u1.user_id
     AND u2.meta_key IN ('user_status_profesor','status')
    WHERE u1.meta_key = '{$cap_key_q}'
      AND u1.meta_value LIKE '{$like_prof}'
  ");

  $count = 0; $seen = [];
  if ($rows) {
    foreach ($rows as $r){
      $uid = (int)$r->user_id;
      if (isset($seen[$uid])) continue;
      $val = $r->meta_value;
      if ($val === null || $val === '') continue;

      $v = $norm($val);
      if ($v === 'in_asteptare' || $v === 'pending') $v = 'in asteptare';
      if ($v === 'activ' || $v === 'active') $v = 'activ';

      if (in_array($v, $targets, true)) { $count++; $seen[$uid]=true; }
    }
  }
  return $count;
}

/** Lista ultimilor N utilizatori activi pentru un rol (profesor/tutor) pe baza last_activity/last_login/last_seen */
function ad_last_active_users_by_role($role, $limit = 8){
  $args = [
    'role'   => $role,
    'number' => -1,
    'orderby'=> 'display_name',
    'order'  => 'ASC',
    'fields' => 'all',
  ];
  $q = new WP_User_Query($args);
  $users = $q->get_results();

  $rows = [];
  foreach ($users as $u){
    $uid = (int)$u->ID;
    $cand = [];
    foreach (['last_activity','last_login','last_seen'] as $k){
      $v = get_user_meta($uid, $k, true);
      if ($v !== '' && $v !== null) $cand[] = is_numeric($v) ? (int)$v : (int)strtotime($v);
    }
    $last_ts = !empty($cand) ? max($cand) : (int)strtotime($u->user_registered);
    $rows[] = [
      'id'    => $uid,
      'name'  => ad_prof_name($u),
      'email' => $u->user_email,
      'first' => $u->first_name,
      'lastn' => $u->last_name,
      'disp'  => $u->display_name,
      'last'  => $last_ts,
      'avatar'=> (function($uid){
        $avatar_id = get_user_meta($uid, 'profile_image', true);
        return $avatar_id ? wp_get_attachment_image_url($avatar_id, 'thumbnail') : null;
      })($uid),
    ];
  }
  usort($rows, fn($a,$b)=>($b['last']<=>$a['last']));
  return array_slice($rows, 0, $limit);
}

// ——— KPIs
$total_users             = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tbl_users}");
$profesori_activi        = ad_count_profesori_by_status(['activ']);
$profesori_in_asteptare  = ad_count_profesori_by_status(['in asteptare']);
$total_tutori            = ad_count_role('tutor');
$total_elevi             = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tbl_students}");
// Numărăm doar școlile alocate efectiv profesorilor (assigned_school_ids).
$cap_key_for_count = ad_cap_key();
$assigned_meta_rows = $wpdb->get_col($wpdb->prepare(
  "SELECT um1.meta_value
   FROM {$tbl_umeta} um1
   JOIN {$tbl_umeta} um2 ON um1.user_id = um2.user_id
   WHERE um1.meta_key = %s
     AND um2.meta_key = %s
     AND um2.meta_value LIKE %s",
  'assigned_school_ids',
  $cap_key_for_count,
  '%"profesor"%'
));
$assigned_school_ids_set = [];
foreach ((array)$assigned_meta_rows as $val) {
  $arr = maybe_unserialize($val);
  if (!is_array($arr)) continue;
  foreach ($arr as $sid) {
    $sid = (int)$sid;
    if ($sid > 0) $assigned_school_ids_set[$sid] = true;
  }
}
$total_scoli = 0;
if (!empty($assigned_school_ids_set)) {
  $ids = array_keys($assigned_school_ids_set);
  $in_ph = implode(',', array_fill(0, count($ids), '%d'));
  // intersectăm cu școlile existente ca să nu numărăm IDuri orfane.
  $total_scoli = (int) $wpdb->get_var(
    $wpdb->prepare("SELECT COUNT(*) FROM {$tbl_schools} WHERE id IN ($in_ph)", ...$ids)
  );
}
$generatii_active        = (int)$wpdb->get_var("SELECT COUNT(DISTINCT s.generation_id) FROM {$tbl_students} s WHERE s.generation_id IS NOT NULL AND s.generation_id <> 0");

// ——— Ultimii activi
$last_profesori = ad_last_active_users_by_role('profesor', 8);
$last_tutori    = ad_last_active_users_by_role('tutor', 8);

?>
<section class="relative">
  <!-- Luxury gradient backdrop -->
  <div aria-hidden="true" class="absolute inset-x-0 h-56 pointer-events-none -top-16 bg-gradient-to-l from-sky-800 via-sky-700 to-sky-600"></div>

  <!-- Header -->
  <div class="relative flex flex-col items-start justify-between gap-4 px-6 py-4 mb-8 md:flex-row md:items-center">
    <div class="flex items-center justify-between w-full gap-x-4">
      <h1 class="text-3xl tracking-tight text-white">Buna <span class="font-bold"><?php echo $user_fname;?></span></h1>
      <!-- FAB + Modal (mobile only) -->
      <button id="fab-actions" type="button" class="items-center justify-center hidden text-white rounded-full shadow-lg mobile:flex md:hidden bottom-6 right-6 w-14 h-14 bg-es-orange hover:bg-orange-600 active:scale-95" aria-label="Meniu acțiuni">
        <svg id="fab-icon-menu" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        <svg id="fab-icon-close" class="hidden w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <!-- Action bar (desktop only) -->
    <div class="flex-wrap items-center flex-none hidden gap-2 md:flex">
      <a href="<?php echo esc_url( home_url('/panou/profesori') ); ?>" class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white transition-all duration-150 ease-in-out border rounded hover:bg-slate-800 border-slate-100/30 hover:border-transparent">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M3 4h18v2H3zM3 9h18v2H3zM3 14h18v2H3zM3 19h18v2H3z"/></svg>
        Lista profesori
      </a>
      <a href="<?php echo esc_url( home_url('/panou/elevi') ); ?>" class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white transition-all duration-150 ease-in-out border rounded hover:bg-slate-800 border-slate-100/30 hover:border-transparent">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5.33 0-8 2.667-8 6a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1c0-3.333-2.67-6-8-6Z"/></svg>
        Lista elevi
      </a>
      <a href="<?php echo esc_url( home_url('/panou/notificari') ); ?>" class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white transition-all duration-150 ease-in-out border rounded hover:bg-slate-800 border-slate-100/30 hover:border-transparent">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5.33 0-8 2.667-8 6a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1c0-3.333-2.67-6-8-6Z"/></svg>
        Notificari
      </a>
      <a href="<?php echo esc_url( home_url('/panou/tutor/'.$current_user->ID) ); ?>" class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white transition-all duration-150 ease-in-out border rounded hover:bg-slate-800 border-slate-100/30 hover:border-transparent">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5.33 0-8 2.667-8 6a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1c0-3.333-2.67-6-8-6Z"/></svg>
        Profilul meu
      </a>
      <a href="<?php echo esc_url( home_url('/panou/setari') ); ?>" class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white transition-all duration-150 ease-in-out border rounded hover:bg-slate-800 border-slate-100/30 hover:border-transparent">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M19.14 12.94a7 7 0 1 1-7.08-7.08 7 7 0 0 1 7.08 7.08Z"/></svg>
        Setări
      </a>
      <a href="<?php echo esc_url( home_url('/panou/scoli') ); ?>" class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white transition-all duration-150 ease-in-out border rounded hover:bg-slate-800 border-slate-100/30 hover:border-transparent">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M3 10 12 5l9 5-9 5-9-5Zm0 4 9 5 9-5"/></svg>
        Lista școli
      </a>
      <a href="<?php echo esc_url( home_url('/panou/rapoarte') ); ?>" class="inline-flex items-center justify-center gap-2 px-3 py-2 text-sm font-semibold text-white transition-all duration-150 ease-in-out border rounded hover:bg-slate-800 border-slate-100/30 hover:border-transparent">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3h14v18H5zM8 7h8v2H8zm0 4h8v2H8zm0 4h5v2H8z"/></svg>
        Rapoarte
      </a>
    </div>

    <div id="fab-modal" class="fixed inset-0 z-40 hidden md:hidden">
      <div id="fab-backdrop" class="absolute inset-0 bg-black/50"></div>
      <div class="absolute bottom-0 left-0 right-0 p-4 pb-24 overflow-y-auto bg-white shadow-2xl rounded-t-2xl max-h-[70vh]">
        <div class="w-10 h-1 mx-auto mb-4 rounded-full bg-slate-300"></div>
        <h3 class="mb-3 text-sm font-semibold tracking-wide uppercase text-slate-500">Acțiuni rapide</h3>
        <div class="grid grid-cols-2 gap-3">
          <a href="<?php echo esc_url( home_url('/panou/profesori') ); ?>" class="flex items-center gap-3 p-3 transition rounded-xl bg-slate-50 hover:bg-slate-100">
            <svg class="flex-shrink-0 w-5 h-5 text-slate-600" viewBox="0 0 24 24" fill="currentColor"><path d="M3 4h18v2H3zM3 9h18v2H3zM3 14h18v2H3zM3 19h18v2H3z"/></svg>
            <span class="text-sm font-medium text-slate-800">Lista profesori</span>
          </a>
          <a href="<?php echo esc_url( home_url('/panou/elevi') ); ?>" class="flex items-center gap-3 p-3 transition rounded-xl bg-slate-50 hover:bg-slate-100">
            <svg class="flex-shrink-0 w-5 h-5 text-slate-600" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5.33 0-8 2.667-8 6a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1c0-3.333-2.67-6-8-6Z"/></svg>
            <span class="text-sm font-medium text-slate-800">Lista elevi</span>
          </a>
          <a href="<?php echo esc_url( home_url('/panou/scoli') ); ?>" class="flex items-center gap-3 p-3 transition rounded-xl bg-slate-50 hover:bg-slate-100">
            <svg class="flex-shrink-0 w-5 h-5 text-slate-600" viewBox="0 0 24 24" fill="currentColor"><path d="M3 10 12 5l9 5-9 5-9-5Zm0 4 9 5 9-5"/></svg>
            <span class="text-sm font-medium text-slate-800">Lista școli</span>
          </a>
          <a href="<?php echo esc_url( home_url('/panou/rapoarte') ); ?>" class="flex items-center gap-3 p-3 transition rounded-xl bg-slate-50 hover:bg-slate-100">
            <svg class="flex-shrink-0 w-5 h-5 text-slate-600" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3h14v18H5zM8 7h8v2H8zm0 4h8v2H8zm0 4h5v2H8z"/></svg>
            <span class="text-sm font-medium text-slate-800">Rapoarte</span>
          </a>
          <a href="<?php echo esc_url( home_url('/panou/notificari') ); ?>" class="flex items-center gap-3 p-3 transition rounded-xl bg-slate-50 hover:bg-slate-100">
            <svg class="flex-shrink-0 w-5 h-5 text-slate-600" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a7 7 0 0 0-7 7v4l-2 3h18l-2-3V9a7 7 0 0 0-7-7Zm0 20a2 2 0 0 1-2-2h4a2 2 0 0 1-2 2Z"/></svg>
            <span class="text-sm font-medium text-slate-800">Notificări</span>
          </a>
          <a href="<?php echo esc_url( home_url('/panou/tutor/'.$current_user->ID) ); ?>" class="flex items-center gap-3 p-3 transition rounded-xl bg-slate-50 hover:bg-slate-100">
            <svg class="flex-shrink-0 w-5 h-5 text-slate-600" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5.33 0-8 2.667-8 6a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1c0-3.333-2.67-6-8-6Z"/></svg>
            <span class="text-sm font-medium text-slate-800">Profilul meu</span>
          </a>
          <a href="<?php echo esc_url( home_url('/panou/setari') ); ?>" class="flex items-center gap-3 p-3 transition rounded-xl bg-slate-50 hover:bg-slate-100">
            <svg class="flex-shrink-0 w-5 h-5 text-slate-600" viewBox="0 0 24 24" fill="currentColor"><path d="M19.14 12.94a7 7 0 1 1-7.08-7.08 7 7 0 0 1 7.08 7.08Z"/></svg>
            <span class="text-sm font-medium text-slate-800">Setări</span>
          </a>
        </div>
      </div>
    </div>

    <script>
    (function(){
      var fab = document.getElementById('fab-actions');
      var modal = document.getElementById('fab-modal');
      var backdrop = document.getElementById('fab-backdrop');
      var iconMenu = document.getElementById('fab-icon-menu');
      var iconClose = document.getElementById('fab-icon-close');
      var open = false;
      function toggle(){
        open = !open;
        modal.classList.toggle('hidden', !open);
        iconMenu.classList.toggle('hidden', open);
        iconClose.classList.toggle('hidden', !open);
        document.body.style.overflow = open ? 'hidden' : '';
      }
      fab.addEventListener('click', toggle);
      backdrop.addEventListener('click', toggle);
    })();
    </script>
  </div>

  <!-- KPI Cards: luxury glass + interactive states -->
  <div class="grid grid-cols-2 gap-4 px-12 mb-8 sm:grid-cols-2 lg:grid-cols-6 mobile:px-4">
    <!-- Utilizatori total -->
    <a href="<?php echo esc_url( home_url('/panou/utilizatori') ); ?>" class="relative block group">
      <div class="p-5 transition-all border shadow-sm bg-white/80 backdrop-blur rounded-2xl border-slate-200 ring-1 ring-white/60 group-hover:shadow-lg">
        <div class="text-[11px] font-semibold tracking-wide uppercase text-slate-500">Utilizatori</div>
        <div class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900"><?php echo ad_fmt_num($total_users); ?></div>
        <div class="mt-2 text-xs text-slate-500">total</div>
      </div>
    </a>

    <!-- Profesori -->
    <a href="<?php echo esc_url( home_url('/panou/profesori') ); ?>" class="relative block group">
      <div class="p-5 transition-all border shadow-sm bg-gradient-to-br from-emerald-50 to-white backdrop-blur rounded-2xl border-emerald-100 ring-1 ring-emerald-100/60 group-hover:shadow-lg">
        <div class="text-[11px] font-semibold tracking-wide uppercase text-emerald-700">Profesori</div>
        <div class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">
          <?php echo ad_fmt_num($profesori_activi + $profesori_in_asteptare); ?>
        </div>
        <div class="flex flex-wrap mt-2 text-xs text-slate-600 gap-x-3 gap-y-1">
          <span class="inline-flex items-center gap-1"><span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>activi: <strong class="ml-1"><?php echo ad_fmt_num($profesori_activi); ?></strong></span>
          <span class="inline-flex items-center gap-1"><span class="inline-block w-2 h-2 rounded-full bg-amber-500"></span>în așteptare: <strong class="ml-1"><?php echo ad_fmt_num($profesori_in_asteptare); ?></strong></span>
        </div>
      </div>
    </a>

    <!-- Tutori -->
    <a href="<?php echo esc_url( home_url('/panou/tutori') ); ?>" class="relative block group">
      <div class="p-5 transition-all border border-indigo-100 shadow-sm bg-gradient-to-br from-indigo-50 to-white backdrop-blur rounded-2xl ring-1 ring-indigo-100/60 group-hover:shadow-lg">
        <div class="text-[11px] font-semibold tracking-wide uppercase text-indigo-700">Tutori</div>
        <div class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900"><?php echo ad_fmt_num($total_tutori); ?></div>
        <div class="mt-2 text-xs text-slate-500">rol „tutor”</div>
      </div>
    </a>

    <!-- Elevi -->
    <a href="<?php echo esc_url( home_url('/panou/elevi') ); ?>" class="relative block group">
      <div class="p-5 transition-all border shadow-sm bg-white/80 backdrop-blur rounded-2xl border-slate-200 ring-1 ring-white/60 group-hover:shadow-lg">
        <div class="text-[11px] font-semibold tracking-wide uppercase text-slate-500">Elevi</div>
        <div class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900"><?php echo ad_fmt_num($total_elevi); ?></div>
        <div class="mt-2 text-xs text-slate-500">total</div>
      </div>
    </a>

    <!-- Școli -->
    <a href="<?php echo esc_url( home_url('/panou/scoli') ); ?>" class="relative block group">
      <div class="p-5 transition-all border shadow-sm bg-gradient-to-br from-amber-50 to-white backdrop-blur rounded-2xl border-amber-100 ring-1 ring-amber-100/60 group-hover:shadow-lg">
        <div class="text-[11px] font-semibold tracking-wide uppercase text-amber-700">Școli</div>
        <div class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900"><?php echo ad_fmt_num($total_scoli); ?></div>
        <div class="mt-2 text-xs text-slate-500">alocate profesorilor</div>
      </div>
    </a>

    <!-- Generații active -->
    <a href="<?php echo esc_url( home_url('/panou/generatii') ); ?>" class="relative block group">
      <div class="p-5 transition-all border shadow-sm bg-gradient-to-br from-rose-50 to-white backdrop-blur rounded-2xl border-rose-100 ring-1 ring-rose-100/60 group-hover:shadow-lg">
        <div class="text-[11px] font-semibold tracking-wide uppercase text-rose-700">Generații active</div>
        <div class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900"><?php echo ad_fmt_num($generatii_active); ?></div>
        <div class="mt-2 text-xs text-slate-500">cu cel puțin 1 elev</div>
      </div>
    </a>
  </div>

  <!-- Două coloane: Ultima activitate profesori & tutori -->
  <div class="grid grid-cols-1 gap-6 px-12 lg:grid-cols-2 mobile:px-4 mobile:pb-12">
    <!-- Ultimii profesori activi -->
    <div class="p-6 border shadow-sm bg-white/80 backdrop-blur rounded-2xl border-slate-200 ring-1 ring-white/60">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold tracking-wide uppercase text-slate-800">Ultimii profesori activi</h2>
        <a href="<?php echo esc_url( home_url('/panou/profesori') ); ?>" class="text-xs font-medium text-emerald-700 hover:underline">Vezi toți</a>
      </div>
      <?php if ($last_profesori): ?>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($last_profesori as $row): ?>
            <?php $ini = ad_initials($row['first'], $row['lastn'], $row['disp']); ?>
            <li class="flex items-center justify-between gap-3 py-3">
              <div class="flex items-center min-w-0 gap-3">
                <?php if ($row['avatar']): ?>
                  <img src="<?php echo esc_url($row['avatar']); ?>" class="object-cover rounded-full shadow w-9 h-9 ring-2 ring-white" alt="">
                <?php else: ?>
                  <span class="inline-flex items-center justify-center text-[11px] font-bold text-white rounded-full shadow-sm w-9 h-9 bg-gradient-to-br from-slate-600 to-slate-800 ring-2 ring-white">
                    <?php echo esc_html($ini); ?>
                  </span>
                <?php endif; ?>
                <div class="min-w-0">
                  <a href="<?php echo esc_url( home_url('/panou/profesor/'.$row['id']) ); ?>" class="block font-medium truncate text-slate-900 hover:text-emerald-700"><?php echo esc_html($row['name']); ?></a>
                  <div class="text-xs truncate text-slate-500"><?php echo esc_html($row['email']); ?></div>
                </div>
              </div>
              <div class="text-xs font-medium text-slate-600 mobile:text-right"><?php echo wp_kses( ad_fmt_dt($row['last']), ['span' => ['class' => []]] ); ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="text-sm text-slate-500">Nicio activitate recentă.</p>
      <?php endif; ?>
    </div>

    <!-- Ultimii tutori activi -->
    <div class="p-6 border shadow-sm bg-white/80 backdrop-blur rounded-2xl border-slate-200 ring-1 ring-white/60">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold tracking-wide uppercase text-slate-800">Ultimii tutori activi</h2>
        <a href="<?php echo esc_url( home_url('/panou/tutori') ); ?>" class="text-xs font-medium text-indigo-700 hover:underline">Vezi toți</a>
      </div>
      <?php if ($last_tutori): ?>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($last_tutori as $row): ?>
            <?php $ini = ad_initials($row['first'], $row['lastn'], $row['disp']); ?>
            <li class="flex items-center justify-between gap-3 py-3">
              <div class="flex items-center min-w-0 gap-3">
                <?php if ($row['avatar']): ?>
                  <img src="<?php echo esc_url($row['avatar']); ?>" class="object-cover rounded-full shadow w-9 h-9 ring-2 ring-white" alt="">
                <?php else: ?>
                  <span class="inline-flex items-center justify-center text-[11px] font-bold text-white rounded-full shadow-sm w-9 h-9 bg-gradient-to-br from-indigo-600 to-indigo-800 ring-2 ring-white">
                    <?php echo esc_html($ini); ?>
                  </span>
                <?php endif; ?>
                <div class="min-w-0">
                  <a href="<?php echo esc_url( home_url('/panou/tutor/'.$row['id']) ); ?>" class="block font-medium truncate text-slate-900 hover:text-indigo-700"><?php echo esc_html($row['name']); ?></a>
                  <div class="text-xs truncate text-slate-500"><?php echo esc_html($row['email']); ?></div>
                </div>
              </div>
              <div class="text-xs font-medium text-slate-600 mobile:text-right"><?php echo wp_kses( ad_fmt_dt($row['last']), ['span' => ['class' => []]] ); ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="text-sm text-slate-500">Nicio activitate recentă.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($debug): ?>
    <div class="p-4 px-12 mx-auto mt-6 text-xs border border-yellow-200 max-w-7xl bg-yellow-50 rounded-2xl mobile:px-4">
      <strong>DEBUG</strong>
      <pre><?php echo esc_html(print_r([
        'total_users'            => $total_users,
        'profesori_activi'       => $profesori_activi,
        'profesori_in_asteptare' => $profesori_in_asteptare,
        'total_tutori'           => $total_tutori,
        'total_elevi'            => $total_elevi,
        'total_scoli'            => $total_scoli,
        'generatii_active'       => $generatii_active,
        'last_profesori_count'   => count($last_profesori),
        'last_tutori_count'      => count($last_tutori),
      ], true)); ?></pre>
    </div>
  <?php endif; ?>
</section>
