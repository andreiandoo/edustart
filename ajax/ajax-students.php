<?php
// ajax-students.php — all AJAX handlers related to student management

// 1. Load students (AJAX)
add_action('wp_ajax_load_students', 'edu_load_students');
function edu_load_students() {
    global $wpdb;

    $class_id = intval($_POST['class_id'] ?? 0);
    $current_user_id = get_current_user_id();
    $classes_table = $wpdb->prefix . 'edu_classes';
    $students_table = $wpdb->prefix . 'edu_students';

    $is_own = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $classes_table WHERE id = %d AND teacher_id = %d", $class_id, $current_user_id));
    if (!$is_own) {
        wp_send_json_error('Acces interzis.');
    }

    $students = $wpdb->get_results($wpdb->prepare("SELECT * FROM $students_table WHERE class_id = %d ORDER BY last_name, first_name", $class_id));

    ob_start();
    foreach ($students as $student) {
        ?>
        <details class="p-4 border rounded-md bg-gray-50" data-student-id="<?= $student->id ?>">
            <summary class="font-medium cursor-pointer">
                <?= esc_html("$student->first_name $student->last_name") ?>
                <button class="float-right text-red-600 delete-student" data-id="<?= $student->id ?>">Șterge</button>
            </summary>
            <div class="mt-2 space-y-2 text-sm">
                <p><strong>Vârstă:</strong> <?= esc_html($student->age) ?></p>
                <p><strong>Gen:</strong> <?= esc_html($student->gender) ?></p>
                <p><strong>Observații:</strong> <?= esc_html($student->observation) ?></p>
                <p><strong>Absențe:</strong> <?= esc_html($student->sit_abs) ?></p>
                <p><strong>Frecvență Grădiniță:</strong> <?= esc_html($student->frecventa) ?></p>
                <p><strong>Bursă:</strong> <?= esc_html($student->bursa) ?></p>
                <p><strong>Diferență Limba:</strong> <?= esc_html($student->dif_limba) ?></p>
                <p><strong>Mențiuni:</strong> <?= esc_html($student->notes) ?></p>
            </div>
        </details>
        <?php
    }
    wp_send_json_success(ob_get_clean());
}

// 2. Add Multiple Students
add_action('wp_ajax_add_students', 'edu_add_students');
function edu_add_students() {
    global $wpdb;

    $class_id = intval($_POST['class_id'] ?? 0);
    $current_user_id = get_current_user_id();
    $classes_table = $wpdb->prefix . 'edu_classes';
    $students_table = $wpdb->prefix . 'edu_students';

    $is_own = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $classes_table WHERE id = %d AND teacher_id = %d", $class_id, $current_user_id));
    if (!$is_own) {
        wp_send_json_error('Acces interzis.');
    }

    $students = $_POST['students'] ?? [];
    $created_ids = [];

    foreach ($students as $row) {
        if (empty($row['first_name']) || empty($row['last_name'])) continue;
        $wpdb->insert($students_table, [
            'class_id'         => $class_id,
            'first_name'       => sanitize_text_field($row['first_name']),
            'last_name'        => sanitize_text_field($row['last_name']),
            'age'              => intval($row['age'] ?? 0),
            'gender'           => sanitize_text_field($row['gender'] ?? ''),
            'sit_abs'          => sanitize_text_field($row['sit_abs'] ?? ''),
            'frecventa'        => sanitize_text_field($row['frecventa'] ?? ''),
            'bursa'            => sanitize_text_field($row['bursa'] ?? ''),
            'dif_limba'        => sanitize_text_field($row['dif_limba'] ?? ''),
            'cauze_abs'        => sanitize_textarea_field($row['cauze_abs'] ?? ''),
            'risc_abandon'     => sanitize_text_field($row['risc_abandon'] ?? ''),
            'repeta_clasa'     => sanitize_text_field($row['repeta_clasa'] ?? ''),
            'observation'      => sanitize_text_field($row['observation'] ?? ''),
            'alte_obs'         => sanitize_textarea_field($row['alte_obs'] ?? ''),
            'demers_familie'   => sanitize_text_field($row['demers_familie'] ?? ''),
            'demers_conducere' => sanitize_text_field($row['demers_conducere'] ?? ''),
            'demers_consilier' => sanitize_text_field($row['demers_consilier'] ?? ''),
            'notes'            => sanitize_textarea_field($row['notes'] ?? ''),
        ]);
        if ($wpdb->insert_id) {
            $created_ids[] = (int) $wpdb->insert_id; // <— ID NOU
        }
    }
    if (!empty($created_ids)) {
        /**
         * Ascultat de: EduStart_Cube_CRUDBridge::on_students_created()
         * care mapează payload-ul și face enqueue în outbox.
         */
        do_action('edustart_student_created', $created_ids);
    }

    wp_send_json_success([
        'message'  => 'Elevi adăugați cu succes',
        'inserted' => count($created_ids),
        'ids'      => $created_ids,
    ]);
}

// 3. Delete a student
add_action('wp_ajax_delete_student', 'edu_delete_student');
function edu_delete_student() {
    global $wpdb;

    $student_id = intval($_POST['student_id'] ?? 0);
    $students_table = $wpdb->prefix . 'edu_students';
    $classes_table = $wpdb->prefix . 'edu_classes';
    $current_user_id = get_current_user_id();

    $student = $wpdb->get_row($wpdb->prepare("SELECT class_id, professor_id FROM $students_table WHERE id = %d", $student_id));
    if (!$student) {
        wp_send_json_error('Elevul nu a fost găsit.');
    }

    // Check ownership: either via professor_id (generation system) or class_id (legacy)
    $is_own = false;
    if ($student->professor_id && (int)$student->professor_id === $current_user_id) {
        $is_own = true;
    } elseif ($student->class_id) {
        $is_own = (bool)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $classes_table WHERE id = %d AND teacher_id = %d", $student->class_id, $current_user_id));
    }
    // Admins can always delete
    if (!$is_own && current_user_can('manage_options')) {
        $is_own = true;
    }
    if (!$is_own) {
        wp_send_json_error('Acces interzis.');
    }

    $ok = $wpdb->delete($students_table, ['id' => $student_id]);

    if ($ok) {
        // 🔔 TRIGGER INTEGRARE: marcare pentru drift / opțional ulterior student.deactivate
        do_action('edustart_student_deleted', $student_id);
        wp_send_json_success('Elev șters');
    } else {
        wp_send_json_error('Eroare la ștergere elev.');
    }
}
