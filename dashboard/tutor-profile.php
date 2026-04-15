<?php
/* Template Name: Profil Tutor (vizibil tutor/profesor/admin) */
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) {
  wp_redirect(home_url('/login'));
  exit;
}

global $wpdb;

/* ——— Helpers ——— */
function edus_resolve_tutor_id(): int {
  foreach (['tutor_id','user_id','id'] as $k) {
    $v = get_query_var($k);
    if ($v && (int)$v > 0) return (int)$v;
  }
  foreach (['tutor_id','user_id','id'] as $k) {
    if (!empty($_GET[$k]) && (int)$_GET[$k] > 0) return (int)$_GET[$k];
  }
  $uri = $_SERVER['REQUEST_URI'] ?? '';
  if ($uri && preg_match('~(?:^|/)(\d+)(?:/)?(?:\?.*)?$~', $uri, $m)) {
    $n = (int)$m[1]; if ($n>0) return $n;
  }
  return 0;
}

/* ——— Context ——— */
$logged_user   = wp_get_current_user();
$logged_id     = (int) ($logged_user->ID ?? 0);
$logged_roles  = (array) ($logged_user->roles ?? []);
$is_adminlike  = current_user_can('manage_options');
$is_tutor      = in_array('tutor', $logged_roles, true);
$is_prof       = in_array('profesor', $logged_roles, true);
$debug         = !empty($_GET['debug']);

/* ——— Țintă ——— */
$target_tutor_id = edus_resolve_tutor_id();
if ($target_tutor_id <= 0 && $is_tutor) $target_tutor_id = $logged_id;

if ($target_tutor_id <= 0) {
  status_header(400);
  echo '<div class="max-w-4xl p-6 mx-auto text-red-600">Lipsește ID-ul tutorelui în URL.</div>';
  return get_footer();
}

$profile_user = get_userdata($target_tutor_id);
if (!$profile_user || !in_array('tutor', (array)$profile_user->roles, true)) {
  status_header(404);
  echo '<div class="max-w-4xl p-6 mx-auto text-red-600">Utilizatorul #'.(int)$target_tutor_id.' nu este tutor sau nu există.</div>';
  return get_footer();
}

/* ——— Permisiuni ———
   Admin: ok
   Tutor: doar propriul profil
   Profesor: doar dacă assigned_tutor_id(profesor) == target_tutor_id
*/
$allowed = false; $mode = 'UNKNOWN';
if ($is_adminlike) { $allowed = true; $mode = 'ADMIN'; }
elseif ($is_tutor && $logged_id === $target_tutor_id) { $allowed = true; $mode = 'TUTOR'; }
elseif ($is_prof) {
  $my_tutor_id = (int) get_user_meta($logged_id, 'assigned_tutor_id', true);
  if ($my_tutor_id === $target_tutor_id) { $allowed = true; $mode = 'PROF'; }
}

if (!$allowed) {
  status_header(403);
  echo '<div class="max-w-4xl p-6 mx-auto text-red-600">Nu ai permisiuni pentru acest profil de tutor.</div>';
  if ($debug) {
    echo '<pre class="max-w-4xl p-3 mx-auto mt-2 text-xs rounded bg-slate-900 text-slate-100">';
    echo 'logged_id='.$logged_id.' roles='.esc_html(implode(',', $logged_roles))."\n";
    echo 'target_tutor_id='.$target_tutor_id."\n";
    if ($is_prof) echo 'my_tutor_id(prof)='.(int) get_user_meta($logged_id, 'assigned_tutor_id', true)."\n";
    echo '</pre>';
  }
  return get_footer();
}

/* ——— Date profil tutor ——— */
$view_user    = $profile_user;
$view_user_id = (int) $view_user->ID;
$profile_image_id = get_user_meta($view_user_id, 'profile_image', true);
$member_since = $view_user->user_registered ? date_i18n( get_option('date_format'), strtotime($view_user->user_registered) ) : '';

/* ——— Lista profesorilor administrați de acest tutor ——— */
$tbl_users = $wpdb->users;
$tbl_umeta = $wpdb->usermeta;
// profesori care au usermeta (assigned_tutor_id == $view_user_id)
$prof_ids = $wpdb->get_col( $wpdb->prepare("
  SELECT u.ID
  FROM {$tbl_users} u
  INNER JOIN {$tbl_umeta} m1 ON m1.user_id = u.ID AND m1.meta_key = 'wp_capabilities' AND m1.meta_value LIKE %s
  LEFT JOIN {$tbl_umeta} m2 ON m2.user_id = u.ID AND m2.meta_key = 'assigned_tutor_id'
  WHERE m2.meta_value = %s
  ORDER BY u.display_name ASC
", '%profesor%', (string)$view_user_id) );

get_header('blank');
?>
<?php if ($debug): ?>
  <div class="max-w-5xl p-3 mx-auto my-4 text-xs rounded bg-slate-900 text-slate-100">
    <div><strong>Mode:</strong> <?= esc_html($mode) ?></div>
    <div><strong>logged:</strong> <?= (int)$logged_id ?> | <?= esc_html(implode(',', $logged_roles)) ?></div>
    <div><strong>target_tutor_id:</strong> <?= (int)$view_user_id ?></div>
  </div>
<?php endif; ?>

<section class="w-full px-6 pb-8 mt-6 mb-8">
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
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-emerald-100 text-emerald-800 ring-1 ring-inset ring-emerald-200">Tutor</span>
          </div>

          <div class="flex mt-2 text-sm text-white gap-x-6 gap-y-2">
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

        <div class="grid grid-cols-1 overflow-hidden rounded-xl bg-slate-50 ring-1 ring-slate-200">
          <div class="px-4 py-3 text-center">
            <div class="text-xs text-slate-500">Profesori administrați</div>
            <div class="text-sm font-semibold"><?= (int)count($prof_ids); ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="w-full px-6 pb-10">
  <div class="p-5 bg-white border shadow-sm rounded-2xl border-slate-200">
    <h2 class="mb-4 text-sm font-semibold tracking-wide uppercase text-slate-800">Profesorii alocați</h2>
    <?php if ($prof_ids): ?>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($prof_ids as $pid):
          $u = get_userdata($pid);
          if (!$u) continue;
          $name = trim(($u->first_name ?: $u->display_name).' '.$u->last_name);
          $email = $u->user_email;
          $link = home_url('/panou/profesor/'.$pid);
        ?>
          <li class="flex items-center justify-between py-2">
            <div class="min-w-0">
              <div class="font-medium truncate text-slate-900"><?= esc_html($name ?: ('Profesor #'.$pid)); ?></div>
              <div class="text-xs truncate text-slate-500"><?= esc_html($email); ?></div>
            </div>
            <a class="inline-flex items-center text-xs font-medium px-3 py-1.5 rounded-full bg-sky-600 text-white hover:bg-sky-700" href="<?= esc_url($link); ?>">Vezi profil</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="text-slate-500">Nu există profesori alocați acestui tutor.</p>
    <?php endif; ?>
  </div>
</section>

<?php get_footer('blank'); ?>
