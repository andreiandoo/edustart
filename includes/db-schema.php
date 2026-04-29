<?php
// includes/db-schema.php
if (!defined('ABSPATH')) exit;





function edu_create_generations_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = $wpdb->prefix . 'edu_generations';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        professor_id BIGINT UNSIGNED NOT NULL,
        level VARCHAR(20) NOT NULL,
        class_label VARCHAR(30) NOT NULL,
        year INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY professor_id (professor_id),
        KEY level (level),
        KEY year (year),
        UNIQUE KEY uniq_prof_level_label_year (professor_id, level, class_label, year)
    ) {$charset_collate};";

    dbDelta($sql);
}

function edu_alter_generations_add_name() {
    global $wpdb;
    $table = $wpdb->prefix . 'edu_generations';
    $cols  = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    if (!$cols) return;

    if (!in_array('name', $cols, true)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN name VARCHAR(190) NULL AFTER id;");
        // index pe name nu e obligatoriu acum
    }
}

// Adaugă coloana care stochează multiple clase la o generație (JSON)
function edu_alter_generations_add_classlabels() {
    global $wpdb;
    $table = $wpdb->prefix . 'edu_generations';
    $cols  = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    if (!$cols) return;

    if (!in_array('class_labels_json', $cols, true)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN class_labels_json LONGTEXT NULL AFTER class_label;");
    }
}

function edu_alter_students_add_generation_professor() {
    global $wpdb;
    $table = $wpdb->prefix . 'edu_students';
    $cols  = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    if (!$cols) return;

    // generation_id
    if (!in_array('generation_id', $cols, true)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN generation_id BIGINT UNSIGNED NULL AFTER id;");
        $wpdb->query("ALTER TABLE {$table} ADD KEY generation_id (generation_id);");
    }

    // professor_id
    if (!in_array('professor_id', $cols, true)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN professor_id BIGINT UNSIGNED NULL AFTER generation_id;");
        $wpdb->query("ALTER TABLE {$table} ADD KEY professor_id (professor_id);");
    }

    // created_at (pentru ordonare / audit)
    if (!in_array('created_at', $cols, true)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER notes;");
        $wpdb->query("ALTER TABLE {$table} MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;");
    }
}

function edu_install_schema() {
    // Creează tabelul de generații + alter elevi
    edu_create_generations_table();
    edu_alter_students_add_generation_professor();
}

function edu_create_location_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $counties = $wpdb->prefix . 'edu_counties';
    $cities   = $wpdb->prefix . 'edu_cities';
    $schools  = $wpdb->prefix . 'edu_schools';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE $counties (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL
    ) $charset;");

    dbDelta("CREATE TABLE $cities (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        county_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        parent_city_id BIGINT UNSIGNED DEFAULT NULL,
        FOREIGN KEY (county_id) REFERENCES $counties(id) ON DELETE CASCADE,
        FOREIGN KEY (parent_city_id) REFERENCES $cities(id) ON DELETE SET NULL
    ) $charset;");

    dbDelta("CREATE TABLE $schools (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        city_id BIGINT UNSIGNED NOT NULL,
        village_id BIGINT UNSIGNED DEFAULT NULL,
        cod BIGINT NOT NULL,
        name VARCHAR(255) NOT NULL,
        short_name VARCHAR(100),
        location VARCHAR(100),
        superior_location VARCHAR(100),
        county VARCHAR(100),
        regiune_tfr ENUM('RMD', 'RCV', 'SUD') NOT NULL,
        statut VARCHAR(100),
        medie_irse FLOAT,
        scor_irse FLOAT,
        strategic TINYINT(1) DEFAULT 0,
        FOREIGN KEY (city_id) REFERENCES $cities(id) ON DELETE CASCADE,
        FOREIGN KEY (village_id) REFERENCES $cities(id) ON DELETE SET NULL
    ) $charset;");
}

function edu_create_classes_table() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $classes = $wpdb->prefix . 'edu_classes';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("
      CREATE TABLE $classes (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        school_id   BIGINT UNSIGNED NOT NULL,
        teacher_id  BIGINT UNSIGNED NOT NULL,
        name        VARCHAR(100) NOT NULL,
        level       VARCHAR(50)     NOT NULL,
        PRIMARY KEY (id),
        KEY school_id  (school_id),
        KEY teacher_id (teacher_id),
        FOREIGN KEY (school_id)  REFERENCES {$wpdb->prefix}edu_schools(id) ON DELETE CASCADE,
        FOREIGN KEY (teacher_id) REFERENCES {$wpdb->prefix}users(id)        ON DELETE CASCADE
      ) $charset;
    ");
}

function edu_create_students_table() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = $wpdb->prefix . 'edu_students';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta(
        "CREATE TABLE {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_id    BIGINT UNSIGNED NOT NULL,
            first_name  VARCHAR(100)      NOT NULL,
            last_name   VARCHAR(100)      NOT NULL,
            age         INT               NOT NULL,
            gender      ENUM('M','F')     NOT NULL,
            -- old observation enum, without absenteism:
            observation  ENUM('transfer','abandon') NULL,
            notes        TEXT              NULL,
            -- new columns:
            sit_abs      ENUM('Deloc','Uneori/Rar','Des','Foarte Des') NULL,
            frecventa    ENUM('Nu','Da (1an)','Da (2ani)','Da (3ani)') NULL,
            bursa        ENUM('Nu','Da')   NULL,
            dif_limba    ENUM('Nu','Da')   NULL,
            PRIMARY KEY (id),
            KEY class_id (class_id),
            CONSTRAINT fk_edu_students_class
                FOREIGN KEY (class_id)
                REFERENCES {$wpdb->prefix}edu_classes(id)
                ON DELETE CASCADE
            ) {$charset};
        "
    );
}

function edu_alter_students_add_class_label() {
    global $wpdb;
    $table = $wpdb->prefix . 'edu_students';
    $col   = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'class_label'");
    if (!$col) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN class_label VARCHAR(50) NULL DEFAULT '' AFTER generation_id");
    }
}

function edu_alter_students_add_extended_fields() {
    global $wpdb;
    $table = $wpdb->prefix . 'edu_students';
    $new_cols = [
        'cauze_abs'        => "TEXT NULL",
        'risc_abandon'     => "VARCHAR(10) NULL DEFAULT ''",
        'repeta_clasa'     => "VARCHAR(10) NULL DEFAULT ''",
        'alte_obs'         => "TEXT NULL",
        'demers_familie'   => "VARCHAR(30) NULL DEFAULT ''",
        'demers_conducere' => "VARCHAR(30) NULL DEFAULT ''",
        'demers_consilier' => "VARCHAR(30) NULL DEFAULT ''",
    ];
    foreach ($new_cols as $col_name => $col_def) {
        $exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE '{$col_name}'");
        if (!$exists) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col_name} {$col_def}");
        }
    }
    // updated_at – timestamp pentru editări
    $exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'updated_at'");
    if (!$exists) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN updated_at DATETIME NULL DEFAULT NULL");
    }

    // Extind observation de la ENUM la VARCHAR pentru a suporta valori noi
    $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN observation VARCHAR(50) NULL DEFAULT ''");
}

if (!function_exists('edu_schools_add_columns_tfr_siiir_mediu')) {
  function edu_schools_add_columns_tfr_siiir_mediu(){
    global $wpdb;
    $tbl = $wpdb->prefix . 'edu_schools';

    // helper local (nu ciocnim cu altele existente)
    $col_exists = function($col) use ($wpdb, $tbl){
      return (bool) $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$tbl} LIKE %s", $col) );
    };

    // cod — migrare de la INT la BIGINT (codurile SIIIR depășesc INT max)
    $col_info = $wpdb->get_row("SHOW COLUMNS FROM {$tbl} LIKE 'cod'");
    if ($col_info && stripos($col_info->Type, 'bigint') === false) {
      $wpdb->query("ALTER TABLE {$tbl} MODIFY COLUMN cod BIGINT NOT NULL");
    }

    // index_vulnerabilitate_tfr — VARCHAR(10) pt valori ca "3NO"
    if (!$col_exists('index_vulnerabilitate_tfr')) {
      $wpdb->query("ALTER TABLE {$tbl} ADD COLUMN index_vulnerabilitate_tfr VARCHAR(10) NULL AFTER strategic");
    } else {
      // migrare de la FLOAT la VARCHAR(10) dacă e necesar
      $col_info = $wpdb->get_row("SHOW COLUMNS FROM {$tbl} LIKE 'index_vulnerabilitate_tfr'");
      if ($col_info && stripos($col_info->Type, 'float') !== false) {
        $wpdb->query("ALTER TABLE {$tbl} MODIFY COLUMN index_vulnerabilitate_tfr VARCHAR(10) NULL");
      }
    }

    // numar_elevi_siiir
    if (!$col_exists('numar_elevi_siiir')) {
      $wpdb->query("ALTER TABLE {$tbl} ADD COLUMN numar_elevi_siiir INT NULL AFTER index_vulnerabilitate_tfr");
    }

    // mediu
    if (!$col_exists('mediu')) {
      $wpdb->query("ALTER TABLE {$tbl} ADD COLUMN mediu ENUM('urban','rural') NULL AFTER numar_elevi_siiir");
    }

    // tip (Public / Privat)
    if (!$col_exists('tip')) {
      $wpdb->query("ALTER TABLE {$tbl} ADD COLUMN tip VARCHAR(20) NULL AFTER statut");
    }

    // first_year_tfr (ex: 2024-2025)
    if (!$col_exists('first_year_tfr')) {
      $wpdb->query("ALTER TABLE {$tbl} ADD COLUMN first_year_tfr VARCHAR(20) NULL AFTER tip");
    }
  }
}

/**
 * Repară FK-urile pe wp_edu_schools care indică spre o tabelă cu prefix greșit
 * (ex: pe instalațiile cu prefix `wpif_`, FK-ul vechi pointa spre `wp_edu_cities`).
 * Dacă găsim o constrângere care NU referă tabela cu prefixul curent, o ștergem.
 * Dacă nu există FK pe city_id deloc după curățare, îl recreăm corect.
 */
if (!function_exists('edu_schools_fix_foreign_keys')) {
  function edu_schools_fix_foreign_keys(){
    global $wpdb;
    if (!defined('DB_NAME')) return;
    $tbl_schools = $wpdb->prefix . 'edu_schools';
    $tbl_cities  = $wpdb->prefix . 'edu_cities';

    // Confirmă că ambele tabele există
    $exists_s = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_schools));
    $exists_c = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl_cities));
    if ($exists_s !== $tbl_schools || $exists_c !== $tbl_cities) return;

    // FK-urile existente pe schools
    $fks = $wpdb->get_results($wpdb->prepare("
      SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
      FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = %s
        AND TABLE_NAME   = %s
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ", DB_NAME, $tbl_schools));

    $has_correct_city_fk = false;
    foreach ((array)$fks as $fk) {
      $col  = (string)$fk->COLUMN_NAME;
      $ref  = (string)$fk->REFERENCED_TABLE_NAME;
      $name = (string)$fk->CONSTRAINT_NAME;
      // pe noi ne interesează FK-urile către un fel de tabel "edu_cities"
      $is_city_fk = (substr($ref, -strlen('edu_cities')) === 'edu_cities');
      if (($col === 'city_id' || $col === 'village_id') && $is_city_fk) {
        if ($ref === $tbl_cities) {
          if ($col === 'city_id') $has_correct_city_fk = true;
          continue; // OK
        }
        // FK greșit — îl ștergem (escape backticks defensiv)
        $name_esc = str_replace('`', '``', $name);
        $wpdb->query("ALTER TABLE `{$tbl_schools}` DROP FOREIGN KEY `{$name_esc}`");
      }
    }

    // Re-creăm FK-ul pe city_id (esențial — village_id e opțional, îl lăsăm)
    if (!$has_correct_city_fk) {
      // Nu mai re-adăugăm FK ca să nu refuze rânduri vechi cu city_id eventual orfan;
      // dropdown-ul UI deja restrânge alegerile la wpif_edu_cities valide. Dacă vrei totuși
      // să forțezi integritatea, decomentează linia de mai jos.
      // $wpdb->query("ALTER TABLE `{$tbl_schools}` ADD CONSTRAINT FOREIGN KEY (`city_id`) REFERENCES `{$tbl_cities}` (`id`) ON DELETE CASCADE");
    }
  }
}