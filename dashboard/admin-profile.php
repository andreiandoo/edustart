<?php
/* Template Name: Profil Admin (vizibil doar admin) */
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) {
  wp_redirect(home_url('/login'));
  exit;
}

global $wpdb;

/* ——— Helpers ——— */
function edus_resolve_admin_id(): int {
  foreach (['admin_id','user_id','id'] as $k) {
    $v = get_query_var($k);
    if ($v && (int)$v > 0) return (int)$v;
  }
  foreach (['admin_id','user_id','id'] as $k) {
    if (!empty($_GET[$k]) && (int)$_GET[$k] > 0) return (int)$_GET[$k];
  }
  $uri = $_SERVER['REQUEST_URI'] ?? '';
  if ($uri && preg_match('~(?:^|/)(\d+)(?:/)?(?:\?.*)?$~', $uri, $m)) {
    $n = (int)$m[1]; if ($n>0) return $n;
  }
  return 0;
}
function edus_cap_key(){
  return is_multisite() ? $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities' : $GLOBALS['wpdb']->prefix . 'capabilities';
}
function edus_fmt_dt($ts_or_str){
  if (!$ts_or_str) return '—';
  $ts = is_numeric($ts_or_str) ? (int)$ts_or_str : strtotime($ts_or_str);
  if (!$ts) return '—';
  return date_i18n(get_option('date_format').' '.get_option('time_format'), $ts);
}
function edus_fmt_date($ts_or_str){
  if (!$ts_or_str) return '—';
  $ts = is_numeric($ts_or_str) ? (int)$ts_or_str : strtotime($ts_or_str);
  if (!$ts) return '—';
  return date_i18n(get_option('date_format'), $ts);
}
function edus_num($n){ return number_format_i18n((int)$n); }
function edus_initials($first, $last, $display=''){
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
function edus_prof_name($u) {
  $name = trim(($u->first_name ?? '').' '.($u->last_name ?? ''));
  if ($name === '') $name = $u->display_name ?: $u->user_login;
  return $name;
}
function edus_last_active_users_by_role($role, $limit = 8){
  $args = ['role'=>$role, 'number'=>-1, 'orderby'=>'display_name', 'order'=>'ASC', 'fields'=>'all'];
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
    $avatar_id = get_user_meta($uid, 'profile_image', true);
    $rows[] = [
      'id'    => $uid,
      'name'  => edus_prof_name($u),
      'email' => $u->user_email,
      'first' => $u->first_name,
      'lastn' => $u->last_name,
      'disp'  => $u->display_name,
      'last'  => $last_ts,
      'avatar'=> $avatar_id ? wp_get_attachment_image_url($avatar_id, 'thumbnail') : null,
    ];
  }
  usort($rows, fn($a,$b)=>($b['last']<=>$a['last']));
  return array_slice($rows, 0, $limit);
}

/* ——— Context ——— */
$logged_user   = wp_get_current_user();
$logged_id     = (int) ($logged_user->ID ?? 0);
$is_adminlike  = current_user_can('manage_options');
$debug         = !empty($_GET['debug']);

if (!$is_adminlike) {
  status_header(403);
  wp_redirect(home_url('/panou'));
  exit;
}

/* ——— Țintă ——— */
$target_admin_id = edus_resolve_admin_id();
if ($target_admin_id <= 0) $target_admin_id = $logged_id;

$view_user = get_userdata($target_admin_id);
if (!$view_user) {
  status_header(404);
  get_header('blank');
  echo '<div class="max-w-4xl p-6 mx-auto text-red-600">Utilizatorul #'.(int)$target_admin_id.' nu există.</div>';
  return get_footer('blank');
}

/* ——— Verifică rol admin (capabilities conține "administrator") ——— */
$cap_key = edus_cap_key();
$is_admin_target = false;
$capabilities = get_user_meta($target_admin_id, $cap_key, true);
if (is_array($capabilities) && !empty($capabilities['administrator'])) $is_admin_target = true;
if (!$is_admin_target) {
  status_header(404);
  get_header('blank');
  echo '<div class="max-w-4xl p-6 mx-auto text-red-600">Utilizatorul #'.(int)$target_admin_id.' nu este administrator.</div>';
  return get_footer('blank');
}

/* ——— Date profil admin ——— */
$view_user_id      = (int) $view_user->ID;
$profile_image_id  = get_user_meta($view_user_id, 'profile_image', true);
$member_since      = $view_user->user_registered ? edus_fmt_date($view_user->user_registered) : '—';
$last_login        = get_user_meta($view_user_id, 'last_login', true);
$last_activity     = get_user_meta($view_user_id, 'last_activity', true);
$last_seen         = get_user_meta($view_user_id, 'last_seen', true);
$last_any          = edus_fmt_dt(max(array_filter([
  is_numeric($last_activity)?(int)$last_activity:(int)strtotime($last_activity ?: 0),
  is_numeric($last_login)?(int)$last_login:(int)strtotime($last_login ?: 0),
  is_numeric($last_seen)?(int)$last_seen:(int)strtotime($last_seen ?: 0),
])));

/* ——— KPI-uri utile (pentru context administrativ) ——— */
$tbl_users       = $wpdb->users;
$tbl_umeta       = $wpdb->usermeta;
$tbl_generations = $wpdb->prefix . 'edu_generations';
$tbl_students    = $wpdb->prefix . 'edu_students';
$tbl_schools     = $wpdb->prefix . 'edu_schools';

$total_users      = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tbl_users}");
$total_tutori     = (int)$wpdb->get_var($wpdb->prepare(
  "SELECT COUNT(DISTINCT user_id) FROM {$tbl_umeta} WHERE meta_key=%s AND meta_value LIKE %s",
  $cap_key, '%"tutor"%'
));
$total_profesori  = (int)$wpdb->get_var($wpdb->prepare(
  "SELECT COUNT(DISTINCT user_id) FROM {$tbl_umeta} WHERE meta_key=%s AND meta_value LIKE %s",
  $cap_key, '%"profesor"%'
));
$total_elevi      = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tbl_students}");
$total_scoli      = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tbl_schools}");
$generatii_active = (int)$wpdb->get_var("
  SELECT COUNT(DISTINCT s.generation_id) FROM {$tbl_students} s
  WHERE s.generation_id IS NOT NULL AND s.generation_id <> 0
");

/* ——— Ultima activitate: profesori / tutori ——— */
$last_profesori = edus_last_active_users_by_role('profesor', 8);
$last_tutori    = edus_last_active_users_by_role('tutor', 8);

get_header('blank');
?>

<?php if ($debug): ?>
  <div class="max-w-5xl p-3 mx-auto my-4 text-xs rounded bg-slate-900 text-slate-100">
    <div><strong>logged:</strong> <?= (int)$logged_id ?> (admin=<?= $is_adminlike?'yes':'no' ?>)</div>
    <div><strong>target_admin_id:</strong> <?= (int)$view_user_id ?></div>
    <div><strong>cap_key:</strong> <?= esc_html($cap_key) ?></div>
  </div>
<?php endif; ?>

<section class="relative w-full px-6 pb-8 mt-6 mb-8">
  <!-- Luxury glow -->
  <div aria-hidden="true" class="absolute inset-x-0 h-56 pointer-events-none -top-16 bg-gradient-to-r from-amber-200/50 via-orange-200/50 to-rose-200/50 blur-2xl"></div>

  <div class="relative overflow-hidden shadow-sm bg-gradient-to-r from-emerald-600 to-emerald-800 rounded-2xl">
    <div class="relative p-4">
      <div class="flex flex-col gap-6 lg:flex-row lg:items-center">
        <div class="shrink-0">
          <div class="overflow-hidden rounded-2xl">
            <?php if ($profile_image_id): ?>
              <img src="<?= esc_url(wp_get_attachment_image_url($profile_image_id, 'medium')); ?>" class="object-cover size-24" alt="Profil">
            <?php else: ?>
              <img src="<?= esc_url(get_template_directory_uri().'/assets/images/default-teach.svg'); ?>" class="object-cover size-24" alt="Profil">
            <?php endif; ?>
          </div>
        </div>

        <div class="flex-1 min-w-0">
          <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold text-white truncate lg:text-2xl">
              <?= esc_html(trim(($view_user->first_name ?: $view_user->display_name).' '.$view_user->last_name)); ?>
            </h1>
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-emerald-100 text-emerald-800 ring-1 ring-inset ring-emerald-200">Administrator</span>
          </div>

          <div class="flex mt-2 text-sm text-white gap-x-6 gap-y-2 mobile:flex-col">
            <div class="flex items-center gap-2">
              <svg class="text-white size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M2 6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v.511L12 12 2 6.511V6Z"/><path d="M2 8.489V18a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8.489l-9.445 5.523a2 2 0 0 1-2.11 0L2 8.489Z"/></svg>
              <a class="hover:underline" href="mailto:<?= esc_attr($view_user->user_email); ?>"><?= esc_html($view_user->user_email); ?></a>
            </div>
            <div class="flex items-center gap-2">
              <svg class="text-white size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M7 4h10a2 2 0 0 1 2 2v11l-3-2-3 2-3-2-3 2V6a2 2 0 0 1 2-2Z"/></svg>
              <span>Membru din: <strong><?= esc_html($member_since); ?></strong></span>
            </div>
            <div class="flex items-center gap-2">
              <svg class="text-white size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5.33 0-8 2.667-8 6a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1c0-3.333-2.67-6-8-6Z"/></svg>
              <span>ID utilizator: <strong>#<?= (int)$view_user_id; ?></strong></span>
            </div>
          </div>
        </div>

        <!-- Quick Stats small -->
        <div class="grid grid-cols-2 overflow-hidden bg-white rounded-xl text-slate-900 ring-1 ring-slate-200">
          <div class="px-4 py-3 text-center">
            <div class="text-xs text-slate-500">Utilizatori</div>
            <div class="text-sm font-semibold"><?= edus_num($total_users); ?></div>
          </div>
          <div class="px-4 py-3 text-center border-l border-slate-200/70">
            <div class="text-xs text-slate-500">Școli</div>
            <div class="text-sm font-semibold"><?= edus_num($total_scoli); ?></div>
          </div>
        </div>
      </div>

      <!-- Action bar -->
      <div class="flex flex-wrap items-center gap-2 mt-5">
        <a href="<?= esc_url( home_url('/panou/profesori') ); ?>" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-white shadow-sm rounded-xl bg-slate-900 hover:bg-black">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M3 4h18v2H3zM3 9h18v2H3zM3 14h18v2H3zM3 19h18v2H3z"/></svg>
          Lista profesori
        </a>
        <a href="<?= esc_url( home_url('/panou/elevi') ); ?>" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium bg-white border shadow-sm rounded-xl border-slate-300 hover:bg-slate-50">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5.33 0-8 2.667-8 6a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1c0-3.333-2.67-6-8-6Z"/></svg>
          Lista elevi
        </a>
        <a href="<?= esc_url( home_url('/panou/scoli') ); ?>" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium bg-white border shadow-sm rounded-xl border-slate-300 hover:bg-slate-50">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M3 10 12 5l9 5-9 5-9-5Zm0 4 9 5 9-5"/></svg>
          Lista școli
        </a>
        <a href="<?= esc_url( home_url('/panou/rapoarte') ); ?>" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-white shadow-sm rounded-xl bg-emerald-600 hover:bg-emerald-700">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3h14v18H5zM8 7h8v2H8zm0 4h8v2H8zm0 4h5v2H8z"/></svg>
          Rapoarte
        </a>
        <a href="<?= esc_url( home_url('/panou/setari') ); ?>" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium bg-white border shadow-sm rounded-xl border-slate-300 hover:bg-slate-50">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M19.14 12.94a7 7 0 1 1-7.08-7.08 7 7 0 0 1 7.08 7.08Z"/></svg>
          Setări
        </a>
        <!-- Profilul meu (self) -->
        <a href="<?= esc_url( home_url('/panou/admin/'.$logged_id) ); ?>" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium bg-white border shadow-sm rounded-xl border-slate-300 hover:bg-slate-50">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5.33 0-8 2.667-8 6a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1c0-3.333-2.67-6-8-6Z"/></svg>
          Profilul meu
        </a>
      </div>
    </div>
  </div>
</section>

<?php get_footer('blank'); ?>
