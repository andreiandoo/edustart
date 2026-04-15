<?php
/* Template Name: Profil Profesor (vizibil prof/tutor/admin) */
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) {
  wp_redirect(home_url('/login'));
  exit;
}

global $wpdb, $wp_query;

/* ——— Helpers ——— */
function edus_resolve_prof_id(): int {
  foreach (['profesor_id','prof_id','user_id','id'] as $k) {
    $v = get_query_var($k);
    if ($v && (int)$v > 0) return (int)$v;
  }
  foreach (['profesor_id','prof_id','user_id','id'] as $k) {
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

/* ——— Pe cine afișăm? ———
   /panou/profesor/{id} → {id} obligatoriu
   fallback: dacă nu e ID în URL și utilizatorul e „profesor”, afișăm propriul profil.
*/
$target_prof_id = edus_resolve_prof_id();
if ($target_prof_id <= 0 && $is_prof) $target_prof_id = $logged_id;

if ($target_prof_id <= 0) {
  status_header(400);
  echo '<div class="max-w-4xl p-6 mx-auto text-red-600">Lipsește ID-ul profesorului în URL.</div>';
  return get_footer();
}

/* ——— Validare țintă ——— */
$profile_user = get_userdata($target_prof_id);
if (!$profile_user || !in_array('profesor', (array)$profile_user->roles, true)) {
  status_header(404);
  echo '<div class="max-w-4xl p-6 mx-auto text-red-600">Utilizatorul #'.(int)$target_prof_id.' nu este profesor sau nu există.</div>';
  return get_footer();
}

/* ——— Permisiuni ———
   Admin: ok
   Profesor: doar propriul profil
   Tutor: doar profesorii care au meta assigned_tutor_id == tutorul logat
*/
$assigned_tutor_id = (int) get_user_meta($target_prof_id, 'assigned_tutor_id', true);

$allowed = false; $mode = 'UNKNOWN';
if ($is_adminlike) { $allowed = true;  $mode = 'ADMIN'; }
elseif ($is_prof && $logged_id === $target_prof_id) { $allowed = true; $mode = 'PROF'; }
elseif ($is_tutor && $assigned_tutor_id === $logged_id) { $allowed = true; $mode = 'TUTOR'; }

if (!$allowed) {
  status_header(403);
  echo '<div class="max-w-4xl p-6 mx-auto text-red-600">Nu ai permisiuni pentru acest profil de profesor.</div>';
  if ($debug) {
    echo '<pre class="max-w-4xl p-3 mx-auto mt-2 text-xs rounded bg-slate-900 text-slate-100">';
    echo 'logged_id='.$logged_id.' roles='.esc_html(implode(',', $logged_roles))."\n";
    echo 'target_prof_id='.$target_prof_id.' | assigned_tutor_id(target)='.$assigned_tutor_id."\n";
    echo '</pre>';
  }
  return get_footer();
}

/* ——— Date pentru afișare (ACF/meta) ——— */
$view_user      = $profile_user;
$view_user_id   = (int) $view_user->ID;
$profile_image_id    = get_user_meta($view_user_id, 'profile_image', true);
$assigned_school_ids = get_user_meta($view_user_id, 'assigned_school_ids', true);
$session_tokens      = get_user_meta($view_user_id, 'session_tokens', true);
$user_status_profesor= get_user_meta($view_user_id, 'user_status_profesor', true);
$user_phone= get_user_meta($view_user_id, 'phone', true);

$generation          = get_user_meta($view_user_id, 'generatie', true);
$program_year        = get_user_meta($view_user_id, 'an_program', true);
$status              = get_user_meta($view_user_id, 'statut', true);
$statut              = get_user_meta($view_user_id, 'statut_prof', true);
$materie             = get_user_meta($view_user_id, 'materia_predata', true);
$qualification       = get_user_meta($view_user_id, 'calificare', true);
$experience          = get_user_meta($view_user_id, 'experienta', true);
$teaching_level      = get_user_meta($view_user_id, 'nivel_predare', true);
if (is_array($teaching_level)) $teaching_level = $teaching_level[0] ?? '';
$mentor_sel          = get_user_meta($view_user_id, 'mentor_sel', true);
$mentor_lit          = get_user_meta($view_user_id, 'mentor_literatie', true);
$mentor_num          = get_user_meta($view_user_id, 'mentor_numeratie', true);
$segment_rsoi        = get_user_meta($view_user_id, 'segment_rsoi', true);
$cod_slf             = get_user_meta($view_user_id, 'cod_slf', true);
$cohorte             = get_user_meta($view_user_id, 'cohorte', true);

$member_since  = $view_user->user_registered ? date_i18n( get_option('date_format'), strtotime($view_user->user_registered) ) : '';
$badge = [
  'label' => $user_status_profesor === 'in_asteptare' ? 'În așteptare' : ($user_status_profesor ?: 'Nesetat'),
  'class' => $user_status_profesor === 'in_asteptare'
    ? 'bg-amber-100 text-amber-800 ring-amber-200'
    : 'bg-emerald-100 text-emerald-800 ring-emerald-200'
];

function es_full_name($uid) {
  if (!$uid) return null;
  $u = get_userdata($uid);
  return $u ? trim(($u->first_name ?: '') . ' ' . ($u->last_name ?: '')) : null;
}

get_header('blank');
?>
<?php if ($debug): ?>
  <div class="max-w-5xl p-3 mx-auto my-4 text-xs rounded bg-slate-900 text-slate-100">
    <div><strong>Mode:</strong> <?= esc_html($mode) ?></div>
    <div><strong>logged:</strong> <?= (int)$logged_id ?> | <?= esc_html(implode(',', $logged_roles)) ?></div>
    <div><strong>target_prof_id:</strong> <?= (int)$view_user_id ?> | <strong>assigned_tutor_id(target):</strong> <?= (int)$assigned_tutor_id ?></div>
  </div>
<?php endif; ?>

<!-- ===== Header: profil profesor ===== -->
<section class="w-full px-6 pb-8 mt-6 mb-8">
  <div class="relative overflow-hidden shadow-sm bg-gradient-to-r from-sky-600 to-sky-800 rounded-2xl"> 
    <div class="relative p-4">
      <div class="flex flex-col gap-6 lg:flex-row lg:items-center">
        <!-- Avatar -->
        <div class="shrink-0">
          <div class="overflow-hidden rounded-2xl">
            <?php if ($profile_image_id): ?>
              <img src="<?= esc_url(wp_get_attachment_image_url($profile_image_id, 'medium')); ?>" class="object-cover size-24" alt="Profil">
            <?php else: ?>
              <img src="<?= esc_url(get_template_directory_uri().'/assets/images/default-teach.svg'); ?>" class="object-cover size-24" alt="Profil">
            <?php endif; ?>
          </div>
        </div>

        <!-- Nume & badge-uri -->
        <div class="flex-1 min-w-0">
          <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold text-white truncate lg:text-2xl">
              <?= esc_html(trim(($view_user->first_name ?: $view_user->display_name).' '.$view_user->last_name)); ?>
            </h1>
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-sky-100 text-sky-800 ring-1 ring-inset ring-sky-200">Profesor</span>
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?= esc_attr($badge['class']); ?>">
              <?= esc_html($badge['label']); ?>
            </span>
            <?php if ($cod_slf): ?>
              <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-slate-100 text-slate-800 ring-1 ring-inset ring-slate-200">Cod SLF: <strong class="ml-1"><?= esc_html($cod_slf); ?></strong></span>
            <?php endif; ?>
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
            <div class="flex items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="text-white size-4">
                <path d="M10.5 18.75a.75.75 0 0 0 0 1.5h3a.75.75 0 0 0 0-1.5h-3Z" />
                <path fill-rule="evenodd" d="M8.625.75A3.375 3.375 0 0 0 5.25 4.125v15.75a3.375 3.375 0 0 0 3.375 3.375h6.75a3.375 3.375 0 0 0 3.375-3.375V4.125A3.375 3.375 0 0 0 15.375.75h-6.75ZM7.5 4.125C7.5 3.504 8.004 3 8.625 3H9.75v.375c0 .621.504 1.125 1.125 1.125h2.25c.621 0 1.125-.504 1.125-1.125V3h1.125c.621 0 1.125.504 1.125 1.125v15.75c0 .621-.504 1.125-1.125 1.125h-6.75A1.125 1.125 0 0 1 7.5 19.875V4.125Z" clip-rule="evenodd" />
              </svg>
              <span>Telefon: <strong><?= $user_phone; ?></strong></span>
            </div>
          </div>
        </div>

        <!-- Mic overview -->
        <div class="grid grid-cols-3 overflow-hidden divide-x divide-slate-200 rounded-xl bg-slate-50 ring-1 ring-slate-200">
          <div class="px-4 py-3 text-center">
            <div class="text-xs text-slate-500">Generație</div>
            <div class="text-sm font-semibold"><?= esc_html($generation ?: '—'); ?></div>
          </div>
          <div class="px-4 py-3 text-center">
            <div class="text-xs text-slate-500">An program</div>
            <div class="text-sm font-semibold"><?= esc_html($program_year ?: '—'); ?></div>
          </div>
          <div class="px-4 py-3 text-center">
            <div class="text-xs text-slate-500">Sesiuni active</div>
            <div class="text-sm font-semibold"><?= is_array($session_tokens) ? count($session_tokens) : (int)!!$session_tokens; ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== Conținut: 3 coloane (identic cu varianta ta, doar referințe la $view_user_id) ===== -->
<section class="grid w-full gap-6 px-6 pb-8 lg:grid-cols-3">

  <div class="p-5 bg-white border shadow-sm rounded-2xl border-slate-200">
    <h2 class="mb-4 text-sm font-semibold tracking-wide uppercase text-slate-800">Date educaționale & administrative</h2>
    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
      <div class="flex justify-between gap-3"><dt class="text-slate-500">Generație</dt><dd class="font-medium text-slate-800"><?= esc_html($generation ?: '—'); ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-slate-500">An program</dt><dd class="font-medium text-slate-800"><?= esc_html($program_year ?: '—'); ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-slate-500">Statut</dt><dd class="font-medium text-slate-800"><?= esc_html($statut ?: ($status ?: '—')); ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-slate-500">Calificare</dt><dd class="font-medium text-slate-800"><?= esc_html($qualification ?: '—'); ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-slate-500">Experiență</dt><dd class="font-medium text-slate-800"><?= esc_html($experience ?: '—'); ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-slate-500">Nivel predare</dt><dd class="font-medium text-slate-800"><?= esc_html($teaching_level ?: '—'); ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-slate-500">Materie</dt><dd class="font-medium text-slate-800"><?= esc_html($materie ?: '—'); ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-slate-500">Segment RSOI</dt><dd class="font-medium text-slate-800"><?= esc_html($segment_rsoi ?: '—'); ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-slate-500">Cod SLF</dt><dd class="font-medium text-slate-800"><?= esc_html($cod_slf ?: '—'); ?></dd></div>
      <div class="flex justify-between gap-3"><dt class="text-slate-500">Cohorte</dt><dd class="font-medium text-slate-800"><?= esc_html($cohorte ?: '—'); ?></dd></div>
    </dl>
  </div>

  <div class="p-5 bg-white border shadow-sm rounded-2xl border-slate-200">
    <h2 class="mb-4 text-sm font-semibold tracking-wide uppercase text-slate-800">Mentori alocați</h2>
    <?php
      $mentor_sel_name = es_full_name($mentor_sel) ?: 'Nedefinit';
      $mentor_lit_name = es_full_name($mentor_lit) ?: 'Nedefinit';
      $mentor_num_name = es_full_name($mentor_num) ?: 'Nedefinit';
    ?>
    <ul class="space-y-3">
      <li class="flex items-center justify-between px-3 py-2 border rounded-lg border-slate-200"><span class="text-slate-600">Mentor SEL</span><strong class="text-slate-900"><?= esc_html($mentor_sel_name); ?></strong></li>
      <li class="flex items-center justify-between px-3 py-2 border rounded-lg border-slate-200"><span class="text-slate-600">Mentor Literație</span><strong class="text-slate-900"><?= esc_html($mentor_lit_name); ?></strong></li>
      <li class="flex items-center justify-between px-3 py-2 border rounded-lg border-slate-200"><span class="text-slate-600">Mentor Numerație</span><strong class="text-slate-900"><?= esc_html($mentor_num_name); ?></strong></li>
    </ul>
  </div>
  
  <div class="space-y-6">
    <div class="p-5 bg-white border shadow-sm rounded-2xl border-slate-200">
      <h2 class="mb-3 text-sm font-semibold tracking-wide uppercase text-slate-800">Tutor alocat</h2>
      <?php if ($assigned_tutor_id && ($tutor_name = es_full_name($assigned_tutor_id))): ?>
        <p class="text-slate-700"><span class="font-medium">Tutor:</span> <?= esc_html($tutor_name); ?></p>
      <?php else: ?>
        <p class="text-slate-500">Nu are tutor alocat.</p>
      <?php endif; ?>
    </div>

    <div class="p-5 bg-white border shadow-sm rounded-2xl border-slate-200">
      <h3 class="mb-3 inline-flex items-center gap-2 rounded-lg bg-es-blue px-2.5 py-1.5 text-[11px] font-bold uppercase tracking-wide text-white">
        <svg class="size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 1.5 9 12 15 22.5 9 12 3Z"/><path opacity=".3" d="M3 10.5v5.25L12 21l9-5.25V10.5L12 16.5 3 10.5Z" fill="currentColor"/></svg>
        Școli asociate
      </h3>
      <?php
      if (!empty($assigned_school_ids) && is_array($assigned_school_ids)) {
        echo '<ul class="mt-2 space-y-2">';
        foreach ($assigned_school_ids as $sid) {
          $school = $wpdb->get_row( $wpdb->prepare("
              SELECT s.name AS school_name, c.name AS city_name, j.name AS county_name
              FROM {$wpdb->prefix}edu_schools s
              LEFT JOIN {$wpdb->prefix}edu_cities c  ON s.city_id = c.id
              LEFT JOIN {$wpdb->prefix}edu_counties j ON c.county_id = j.id
              WHERE s.id = %d
          ", $sid) );
          if ($school) {
            echo '<li class="flex items-center justify-between px-3 py-2 border rounded-lg border-slate-200">';
            echo '<span class="font-medium text-slate-800">'.esc_html($school->school_name).'</span>';
            echo '<span class="text-xs text-slate-500">'.esc_html($school->city_name).', '.esc_html($school->county_name).'</span>';
            echo '</li>';
          }
        }
        echo '</ul>';
      } else {
        echo '<p class="text-slate-500">Nu sunt școli asociate.</p>';
      }
      ?>
    </div>
  </div>
</section>

<?php
// meta debug (opțional — îl poți păstra/șterge)
$user_meta = get_user_meta($view_user_id, '', false);
?>
<div class="hidden mx-6 mt-8 bg-white border shadow-sm rounded-2xl border-slate-200">
  <details class="group">
    <summary class="flex items-center justify-between px-5 py-4 list-none cursor-pointer">
      <div>
        <h3 class="text-sm font-semibold text-slate-800">Toate meta-urile utilizatorului</h3>
        <p class="text-xs text-slate-500 mt-0.5">Pentru depanare și audit.</p>
      </div>
      <svg class="transition-transform size-5 text-slate-400 group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 9l6 6 6-6"/></svg>
    </summary>
    <div class="px-5 pb-5">
      <?php if (!empty($user_meta)): ?>
        <div class="overflow-auto rounded-xl ring-1 ring-slate-200">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50"><tr><th class="px-3 py-2 font-semibold text-left text-slate-600">Meta key</th><th class="px-3 py-2 font-semibold text-left text-slate-600">Valoare</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($user_meta as $key => $values): foreach ($values as $value): ?>
              <tr>
                <td class="px-3 py-2 font-mono text-[12px] text-purple-700"><?= esc_html($key); ?></td>
                <td class="px-3 py-2 text-slate-800">
                  <?php if (is_serialized($value)): $un = maybe_unserialize($value); ?>
                    <pre class="max-h-64 overflow-auto rounded bg-slate-50 p-2 text-[12px]"><?= esc_html(print_r($un, true)); ?></pre>
                  <?php else: ?>
                    <?= esc_html($value); ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-slate-500">Nu s-au găsit meta-uri.</p>
      <?php endif; ?>
    </div>
  </details>
</div>

<?php get_footer('blank'); ?>
