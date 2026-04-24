<?php
$current_user = wp_get_current_user();
$user_roles = (array) $current_user->roles;
$dashboard_path = get_template_directory() . '/dashboard/';

switch ($user_roles[0]) {
  case 'tutor':
  case 'admin':
  case 'administrator':
    include_once $dashboard_path . 'admin-analiza.php';
    break;
  default:
    echo '<div class="max-w-5xl p-6 mx-auto"><div class="p-4 text-red-700 bg-red-100 rounded">Acces restricționat.</div></div>';
    break;
}
?>
