<?php
// admin/tools-schema.php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function(){
    add_submenu_page(
        'tools.php',
        'EDU: Upgrade schema',
        'EDU: Upgrade schema',
        'manage_options',
        'edu-upgrade-schema',
        'edu_render_upgrade_schema_page'
    );
});

function edu_render_upgrade_schema_page() {
    if (!current_user_can('manage_options')) return;

    $msg = '';

    // Upgrade schema
    if (isset($_POST['run']) && check_admin_referer('edu_upgrade_schema')) {
        edu_create_generations_table();
        edu_alter_students_add_generation_professor();
        edu_alter_generations_add_name();
        edu_alter_generations_add_classlabels();
        edu_alter_students_add_class_label();
        edu_alter_students_add_extended_fields();
        $msg = 'Schema a fost verificată/creată. (Tabelul edu_generations + coloanele generation_id/professor_id în edu_students)';
    }

    // Backfill professor_id
    if ( isset($_POST['run_backfill_prof']) && check_admin_referer('edu_backfill_prof') ) {
        global $wpdb;
        $students_table    = $wpdb->prefix . 'edu_students';
        $classes_table     = $wpdb->prefix . 'edu_classes';
        $generations_table = $wpdb->prefix . 'edu_generations';

        $updated_total = 0;

        // 1) Din clase → profesor
        $sql1 = "
            UPDATE {$students_table} st
            INNER JOIN {$classes_table} c ON c.id = st.class_id
            SET st.professor_id = c.teacher_id
            WHERE (st.professor_id IS NULL OR st.professor_id = 0)
              AND c.teacher_id IS NOT NULL
              AND c.teacher_id <> 0
        ";
        $r1 = $wpdb->query($sql1);
        if ($r1 !== false) $updated_total += (int)$r1;

        // 2) (opțional) Din generații → profesor
        $sql2 = "
            UPDATE {$students_table} st
            INNER JOIN {$generations_table} g ON g.id = st.generation_id
            SET st.professor_id = g.professor_id
            WHERE (st.professor_id IS NULL OR st.professor_id = 0)
              AND st.generation_id IS NOT NULL
              AND st.generation_id <> 0
              AND g.professor_id IS NOT NULL
              AND g.professor_id <> 0
        ";
        $r2 = $wpdb->query($sql2);
        if ($r2 !== false) $updated_total += (int)$r2;

        $msg .= ($msg ? ' ' : '') . 'Backfill completat. Rânduri actualizate: ' . $updated_total . '.';
    }

    if ( isset($_POST['run_backfill_generation']) && check_admin_referer('edu_backfill_generation') ) {
        global $wpdb;
        $students_table    = $wpdb->prefix . 'edu_students';
        $classes_table     = $wpdb->prefix . 'edu_classes';
        $generations_table = $wpdb->prefix . 'edu_generations';

        // Subselect: ultima generație per (profesor_id, level), folosim created_at; dacă nu ai, schimbă pe MAX(id)
        $latest_gen_sql = "
        SELECT g1.*
        FROM {$generations_table} g1
        INNER JOIN (
            SELECT professor_id, level, MAX(created_at) AS max_created
            FROM {$generations_table}
            GROUP BY professor_id, level
        ) m ON m.professor_id = g1.professor_id AND m.level = g1.level AND m.max_created = g1.created_at
        ";

        // Mapăm nivelul din wp_edu_classes → codul nou din generații
        // Prescolar → 'prescolar'
        // Primar / Primar Mic / Primar Mare → 'primar'
        // Gimnazial → 'gimnazial'
        // Liceu → 'liceu'
        $sql = "
        UPDATE {$students_table} st
        INNER JOIN {$classes_table} c ON c.id = st.class_id
        INNER JOIN ( {$latest_gen_sql} ) g
            ON g.professor_id = c.teacher_id
        AND g.level = CASE
            WHEN c.level = 'Prescolar' THEN 'prescolar'
            WHEN c.level IN ('Primar','Primar Mic','Primar Mare') THEN 'primar'
            WHEN c.level = 'Gimnazial' THEN 'gimnazial'
            WHEN c.level = 'Liceu' THEN 'liceu'
            ELSE g.level
        END
        SET st.generation_id = g.id
        WHERE (st.generation_id IS NULL OR st.generation_id = 0)
        ";

        $updated = $wpdb->query($sql);
        if ($updated === false) $updated = 0;

        echo '<div class="notice notice-success is-dismissible"><p>Backfill generație completat. Rânduri actualizate: <strong>' . esc_html($updated) . '</strong>.</p></div>';
    }

    ?>
    <div class="wrap">
        <h1>EDU: Upgrade schema</h1>
        <?php if ($msg): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($msg); ?></p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('edu_upgrade_schema'); ?>
            <p>Apasă butonul pentru a crea/actualiza schema: <code>wp_edu_generations</code> și a adăuga <code>generation_id</code>, <code>professor_id</code> în <code>wp_edu_students</code>.</p>
            <p><button class="button button-primary" name="run" value="1">Rulează upgrade schema</button></p>
        </form>

        <form method="post" style="margin-top:16px;">
            <?php wp_nonce_field('edu_backfill_prof'); ?>
            <p>Populează <code>professor_id</code> în <code>wp_edu_students</code> din clase (și generații, dacă există).</p>
            <p><button class="button" name="run_backfill_prof" value="1">Rulează backfill profesor</button></p>
        </form>

        <form method="post" style="margin-top:16px;">
            <?php wp_nonce_field('edu_backfill_generation'); ?>
            <p>Leagă elevii existenți (fără generation_id) de cea mai nouă generație cu același profesor & nivel.</p>
            <p><button class="button" name="run_backfill_generation" value="1">Rulează backfill generație</button></p>
        </form>

    </div>
    
    <?php
}
