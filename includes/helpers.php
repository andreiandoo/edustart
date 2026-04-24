<?php
// includes/helpers.php

if (!defined('ABSPATH')) exit;

function edu_levels_allowed(): array {
    return ['prescolar','primar','gimnazial','liceu'];
}

function edu_level_human(string $level): string {
    $map = [
        'prescolar' => 'Preșcolar',
        'primar'    => 'Primar',
        'gimnazial' => 'Gimnazial',
        'liceu'     => 'Liceu',
    ];
    return $map[$level] ?? $level;
}

function edu_class_labels_by_level(string $level): array {
    switch ($level) {
        case 'prescolar':
            return ['Grupa mica','Grupa mijlocie','Grupa mare'];
        case 'primar':
            return ['Clasa 0','Clasa 1','Clasa 2','Clasa 3','Clasa 4'];
        case 'gimnazial':
            return ['Clasa 5','Clasa 6','Clasa 7','Clasa 8'];
        case 'liceu':
            return ['Clasa 9','Clasa 10','Clasa 11','Clasa 12','Clasa 13'];
        default:
            return [];
    }
}

// Normalizăm meta-ul din user_meta('nivel_predare') la: prescolar | primar | gimnazial | liceu
function edu_get_professor_level(int $user_id): string {
    // citește meta-ul tău actual
    $raw = get_user_meta($user_id, 'nivel_predare', true);

    if (!is_string($raw) || $raw === '') return '';

    // mapăm valorile vechi -> coduri noi (lowercase)
    $map = [
        'prescolar'            => 'prescolar',
        'preșcolar'            => 'prescolar',
        'prescolar '           => 'prescolar',
        'primar mic'           => 'primar',
        'primar mare'          => 'primar',
        'primar'               => 'primar',
        'gimnaziu'             => 'gimnazial',
        'gimnazial'            => 'gimnazial',
        'primar & gimnaziu'    => 'gimnazial', // decizie: împingem înspre gimnazial (ajustează dacă vrei altfel)
        'liceu'                => 'liceu',
    ];

    $k = mb_strtolower(trim($raw));
    return $map[$k] ?? '';
}

// --- PATCH: determină urban/rural în mod robust ---
if (!function_exists('es_guess_mediu')) {
  function es_guess_mediu($city_id, $village_id = 0){
    global $wpdb;
    $city_id    = (int)$city_id;
    $village_id = (int)$village_id;

    // dacă avem village_id explicit => SIGUR rural
    if ($village_id > 0) return 'rural';

    if ($city_id > 0) {
      $tbl_cities = $wpdb->prefix . 'edu_cities';
      $parent = $wpdb->get_var($wpdb->prepare(
        "SELECT parent_city_id FROM {$tbl_cities} WHERE id=%d", $city_id
      ));
      // dacă (din greșeală) cineva a selectat un „sat” la city_id (rareori) => parent_city_id != NULL ⇒ rural
      if (!is_null($parent) && (int)$parent > 0) return 'rural';
    }

    return 'urban';
  }
}


?>