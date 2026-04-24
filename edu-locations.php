<?php
/**
 * Plugin Name: Edu Locations Manager
 * Description: Administrează județe, orașe, școli (cu școli strategice) și importă date din CSV.
 * Version: 1.0
 * Author: Andrei Nastase
 */

// Core includes (existente)
require_once __DIR__ . '/includes/db-schema.php';
// ➕ helpers (nou) — folosit de Generații & AJAX
require_once __DIR__ . '/includes/helpers.php';

// Admin pages (existente)
require_once __DIR__ . '/admin/menu.php';
require_once __DIR__ . '/admin/counties.php';
require_once __DIR__ . '/admin/cities.php';
require_once __DIR__ . '/admin/schools.php';
require_once __DIR__ . '/admin/import.php';
require_once __DIR__ . '/ajax/load-cities.php';
require_once __DIR__ . '/admin/roles.php';
require_once __DIR__ . '/admin/class-manager.php';
require_once __DIR__ . '/admin/student-manager.php';

// Admin pages (noi – Generații)
require_once __DIR__ . '/admin/generation-manager.php';
require_once __DIR__ . '/admin/student-manager-generations.php';

// AJAX (existent + extensii noi)
require_once __DIR__ . '/admin/ajax-handlers.php';

require_once __DIR__ . '/admin/tools-schema.php';

/**
 * Hooks de activare (existente)
 */
register_activation_hook( __FILE__, 'edu_create_location_tables' );
register_activation_hook( __FILE__, 'edu_create_classes_table' );
register_activation_hook( __FILE__, 'edu_create_students_table' );
register_activation_hook( __FILE__, 'edu_create_results_table' );

/**
 * Tabel rezultate (existent)
 */
function edu_create_results_table() {
  global $wpdb;
  $table_name = $wpdb->prefix . 'edu_results';
  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE IF NOT EXISTS $table_name (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    modul_type VARCHAR(50) NOT NULL,
    modul_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) DEFAULT 'draft', -- 'draft' sau 'final'
    results LONGTEXT NOT NULL, -- JSON cu răspunsurile
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    professor_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL
  ) $charset_collate;";

  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
}

/**
 * ➕ Hooks de activare pentru noua arhitectură (Generații)
 * - creează tabelul wp_edu_generations
 * - adaugă coloanele generation_id și professor_id în wp_edu_students (fără să șteargă class_id)
 * Aceste funcții sunt definite în includes/db-schema.php (patch-ul adăugat).
 */
register_activation_hook( __FILE__, 'edu_create_generations_table' );
register_activation_hook( __FILE__, 'edu_alter_students_add_generation_professor' );
register_activation_hook( __FILE__, 'edu_alter_students_add_class_label' );
register_activation_hook( __FILE__, 'edu_alter_students_add_extended_fields' );

/**
 * Auto-migrate extended student fields on admin load (idempotent).
 * Uses a one-shot option so the ALTERs aren't re-checked on every request.
 */
add_action('admin_init', function () {
    if (get_option('edu_students_extended_v1') === 'done') return;
    if (function_exists('edu_alter_students_add_class_label'))     edu_alter_students_add_class_label();
    if (function_exists('edu_alter_students_add_extended_fields')) edu_alter_students_add_extended_fields();
    update_option('edu_students_extended_v1', 'done', false);
});

/**
 * Admin assets loader — extins să includă și noile pagini
 */
add_action('admin_enqueue_scripts', function ($hook) {
    // pagini existente + paginile noi
    $allowed_hooks = [
        'toplevel_page_edu-users',
        'toplevel_page_edu-location-manager',
        'toplevel_page_edu-classes',
        'edu-classes_page_edu-students',
        // ➕ pagini noi:
        'toplevel_page_edu-generations',
        'edu-generations_page_edu-students-generations',
    ];

    if ( in_array( $hook, $allowed_hooks, true ) ) {

        // Tailwind (din tema ta, păstrat exact cum ai)
        wp_enqueue_style(
            'edu-admin-tailwind',
            get_theme_file_uri( 'css/app.css' ),
            [],
            filemtime( get_theme_file_path( 'css/app.css' ) )
        );

        // Select2
        wp_enqueue_style(
            'select2-css',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'
        );
        wp_enqueue_script(
            'select2-js',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            ['jquery'],
            null,
            true
        );

        // Users JS (existent)
        wp_enqueue_script(
            'edu-users-js',
            plugin_dir_url(__FILE__) . 'admin/js/edu-users.js',
            ['jquery', 'select2-js'],
            null,
            true
        );
        wp_localize_script('edu-users-js', 'edu_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('edu_nonce'),
        ]);

        // Classes JS (existent)
        wp_enqueue_script(
            'edu-classes-js',
            plugin_dir_url(__FILE__) . 'admin/js/edu-classes.js',
            ['jquery', 'select2-js'],
            null,
            true
        );
        wp_localize_script('edu-classes-js', 'edu_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('edu_nonce'),
        ]);

        // Students JS (existent)
        wp_enqueue_script(
            'edu-students-js',
            plugin_dir_url(__FILE__) . 'admin/js/edu-students.js',
            ['jquery'],
            null,
            true
        );
        wp_localize_script('edu-students-js','edu_ajax',[
            'ajax_url'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('edu_nonce'),
        ]);
    }
});


// la activare
register_activation_hook(__FILE__, function(){
  if (function_exists('edu_schools_add_columns_tfr_siiir_mediu')) {
    edu_schools_add_columns_tfr_siiir_mediu();
  }
});

// și „defensiv” la încărcarea adminului (nu afectează performanța)
add_action('admin_init', function(){
  if (function_exists('edu_schools_add_columns_tfr_siiir_mediu')) {
    edu_schools_add_columns_tfr_siiir_mediu();
  }
});