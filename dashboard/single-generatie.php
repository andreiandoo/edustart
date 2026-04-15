<?php
if (!defined('ABSPATH')) exit;

/**
 * /panou/generatia/{id} → redă profesor-lista.php pentru generația cerută,
 * fără să schimbe utilizatorul curent (fără impersonare).
 * Acces: Tutor (dacă e alocat), Admin (toate), Profesor (dacă e owner).
 */

global $wpdb;

$debug = !empty($_GET['debug']);

// Utilizatorul REAL, cel logat
$uid   = get_current_user_id();
$user  = wp_get_current_user();
$roles = (array) ($user->roles ?? []);

$is_admin  = $uid && current_user_can('manage_options');
$is_tutor  = $uid && in_array('tutor', $roles, true);
$is_prof   = $uid && in_array('profesor', $roles, true);

if (!$uid || (!$is_admin && !$is_tutor && !$is_prof)) {
  get_header(); ?>
  <main class="wrap" style="max-width:1100px;margin:40px auto;">
    <div class="p-4 text-red-800 bg-red-100 rounded">
      Acces interzis. (necesar: tutor / profesor / administrator)
    </div>
  </main>
  <?php get_footer(); return;
}

// gen id din pretty permalink (/panou/generatia/7) sau din ?gen=7
$gen_id = (int)(
    get_query_var('gen')
 ?: ($_GET['gen'] ?? 0)
 ?: get_query_var('gen_id')
 ?: get_query_var('generation_id')
);
if ($gen_id <= 0) {
  get_header(); ?>
  <main class="wrap" style="max-width:1100px;margin:40px auto;">
    <div class="p-4 text-red-800 bg-red-100 rounded">Lipsește ID-ul generației/clasei în URL.</div>
  </main>
  <?php get_footer(); return;
}

// Gen + owner
$tbl_gens = $wpdb->prefix . 'edu_generations';
$gen = $wpdb->get_row($wpdb->prepare("
  SELECT id, professor_id
  FROM {$tbl_gens}
  WHERE id = %d
  LIMIT 1
", $gen_id));

if (!$gen) {
  status_header(404);
  get_header(); ?>
  <main class="wrap" style="max-width:1100px;margin:40px auto;">
    <div class="p-4 text-red-800 bg-red-100 rounded">Generația #<?php echo (int)$gen_id; ?> nu există.</div>
  </main>
  <?php get_footer(); return;
}

$owner_prof_id      = (int) $gen->professor_id;
$assigned_tutor_id  = (int) get_user_meta($owner_prof_id, 'assigned_tutor_id', true);

// Verificare acces (fără impersonare)
$can_view = false;
if ($is_admin) {
  $can_view = true;
} elseif ($is_tutor && $assigned_tutor_id === $uid) {
  $can_view = true;
} elseif ($is_prof && $uid === $owner_prof_id) {
  $can_view = true;
}

if (!$can_view) {
  status_header(403);
  get_header(); ?>
  <main class="wrap" style="max-width:1100px;margin:40px auto;">
    <div class="p-4 text-red-800 bg-red-100 rounded">
      Acces interzis: generația/clasa #<?php echo (int)$gen_id; ?> nu aparține unui profesor alocat ție.
    </div>
  </main>
  <?php get_footer(); return;
}

// Pasăm generația către profesor-lista.php; NU schimbăm userul curent
$_GET['gen'] = $gen_id;

if ($debug) {
  add_action('wp_head', function() use ($uid, $roles, $gen_id, $owner_prof_id, $assigned_tutor_id) {
    echo "<!-- SINGLE-GENERATIE passthrough | uid={$uid} | roles=".esc_attr(implode(',', $roles))." | gen={$gen_id} | owner={$owner_prof_id} | assigned_tutor_id={$assigned_tutor_id} -->\n";
  });
}

// Rulăm template-ul profesorului (care își face singur logicile de rol/mode)
$tpl = locate_template('dashboard/profesor-lista.php', false, false);
if ($tpl) {
  include $tpl; // profesor-lista.php emite propriul header/footer
} else {
  get_header(); ?>
  <main class="wrap" style="max-width:1100px;margin:40px auto;">
    <div class="p-4 text-red-800 bg-red-100 rounded">
      Nu găsesc <code>dashboard/profesor-lista.php</code> în tema curentă.
    </div>
  </main>
  <?php get_footer(); return;
}
