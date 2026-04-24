<?php
// admin/ajax-handlers.php

require_once plugin_dir_path(__FILE__) . '../includes/helpers.php';

// — Nivel profesor + opțiuni de clasă —
// Nivel profesor + opțiuni de "Clasa" pentru pagina Generații
add_action('wp_ajax_edu_get_professor_level', function () {
    check_ajax_referer('edu_nonce', 'nonce');

    // permite și editorilor tăi custom, nu doar admin
    if ( ! current_user_can('manage_options') && ! current_user_can('manage_edu_classes') ) {
        wp_send_json_error(['msg' => 'no perms']);
    }

    $prof_id = isset($_POST['professor_id']) ? intval($_POST['professor_id']) : 0;
    if ($prof_id <= 0) wp_send_json_error(['msg' => 'invalid professor']);

    $level_code = edu_get_professor_level($prof_id); // prescolar|primar|gimnazial|liceu
    $labels     = $level_code ? edu_class_labels_by_level($level_code) : [];

    wp_send_json_success([
        'level'  => $level_code, // cod intern
        'labels' => $labels      // opțiunile pentru "Clasa"
    ]);
});


// ————————————————
// 1️⃣ Returnează formularul (add / edit)
// ————————————————
add_action('wp_ajax_edu_get_user_form', function() {
    check_ajax_referer('edu_nonce', 'nonce');
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : null;

    require_once plugin_dir_path(__FILE__) . 'user-manager.php';

    ob_start();
    edu_render_user_manager($user_id);
    $html = ob_get_clean();

    wp_send_json_success(['form_html' => $html]);
});

// ————————————————
// 3️⃣ Salvează (inserare sau actualizare) și setează rolul
// ————————————————
add_action('wp_ajax_edu_save_user_form', function () {
    check_ajax_referer('edu_nonce', 'nonce');

    $uid     = intval($_POST['user_id'] ?? 0);
    $is_edit = $uid > 0;
    $role    = sanitize_text_field($_POST['user_role'] ?? '');

    // Basic user props
    $email   = sanitize_email($_POST['email'] ?? '');
    $first   = sanitize_text_field($_POST['first_name'] ?? '');
    $last    = sanitize_text_field($_POST['last_name'] ?? '');

    $user_data = [
        'ID'         => $uid,
        'user_login' => $email,
        'user_email' => $email,
        'first_name' => $first,
        'last_name'  => $last,
    ];

    if ($is_edit) {
        $result = wp_update_user($user_data);
    } else {
        $user_data['user_pass'] = wp_generate_password();
        $result = wp_insert_user($user_data);
    }

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    $user_id = is_numeric($result) ? $result : $uid;

    // Set role
    $user = new WP_User($user_id);
    $user->set_role($role);

    // General meta
    update_user_meta($user_id, 'phone', sanitize_text_field($_POST['phone'] ?? ''));

    // Alumni: no extra fields beyond changing role

    // Tutor-specific meta
    if ($role === 'tutor') {
        update_user_meta($user_id, 'user_status_tutor', sanitize_text_field($_POST['user_status_tutor'] ?? ''));
    }

    // Profesor-specific meta
    if ($role === 'profesor') {
        // existing fields
        update_user_meta($user_id, 'user_status_profesor', sanitize_text_field($_POST['user_status_profesor'] ?? ''));
        update_user_meta($user_id, 'assigned_tutor_id', intval($_POST['assigned_tutor_id'] ?? 0));
        update_user_meta($user_id, 'assigned_school_ids', array_map('intval', $_POST['assigned_school_ids'] ?? []));

        // NEW fields
        update_user_meta($user_id, 'generatie',      sanitize_text_field($_POST['generatie'] ?? ''));
        update_user_meta($user_id, 'an_program',     sanitize_text_field($_POST['an_program'] ?? ''));
        update_user_meta($user_id, 'cod_slf',        sanitize_text_field($_POST['cod_slf'] ?? ''));
        update_user_meta($user_id, 'statut_prof',    sanitize_text_field($_POST['statut_prof'] ?? ''));
        update_user_meta($user_id, 'calificare',     sanitize_text_field($_POST['calificare'] ?? ''));
        update_user_meta($user_id, 'experienta',     sanitize_text_field($_POST['experienta'] ?? ''));
        update_user_meta($user_id, 'segment_rsoi',   sanitize_text_field($_POST['segment_rsoi'] ?? ''));
        update_user_meta($user_id, 'nivel_predare',  sanitize_text_field($_POST['nivel_predare'] ?? ''));
        update_user_meta($user_id, 'materia_predata',sanitize_text_field($_POST['materia_predata'] ?? ''));
        update_user_meta($user_id, 'materia_alta',   sanitize_text_field($_POST['materia_alta'] ?? ''));

        // Three mentors
        update_user_meta($user_id, 'mentor_sel',       intval($_POST['mentor_sel'] ?? 0));
        update_user_meta($user_id, 'mentor_literatie', intval($_POST['mentor_literatie'] ?? 0));
        update_user_meta($user_id, 'mentor_numeratie', intval($_POST['mentor_numeratie'] ?? 0));
    }

    // Profile image
    if (!empty($_FILES['profile_image']['tmp_name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $att_id = media_handle_upload('profile_image', 0);
        if (!is_wp_error($att_id)) {
            update_user_meta($user_id, 'profile_image', $att_id);
        }
    }

    // Optionally send reset link (manual trigger from table button)
    if (!empty($_POST['send_reset_link']) && $role === 'profesor') {
        wp_send_new_user_notifications($user_id, 'user');
    }

    // Auto-send welcome email when creating a new professor
    if (!$is_edit && $role === 'profesor') {
        edu_send_welcome_email_profesor($user_id, $first, $last, $email);
    }

    // Re-generate the table row
    require_once plugin_dir_path(__FILE__) . 'user-listing.php';
    $statusuri = [
        'in_asteptare'         => 'În așteptare',
        'activ'                => 'Activ',
        'drop-out'             => 'Drop-out',
        'eliminat'             => 'Eliminat',
        'concediu_maternitate' => 'Concediu maternitate',
        'concediu_studii'      => 'Concediu studii',
    ];
    $roles_map = [
        'profesor'  => 'Profesor',
        'tutor'     => 'Tutor',
        'alumni'    => 'Alumni',
        'non-teach' => 'Non-Teach',
    ];
    $user_obj = get_userdata($user_id);
    $row_html = edu_render_user_row($user_obj, $statusuri, $roles_map);

    wp_send_json_success([
        'user_id'  => $user_id,
        'row_html' => $row_html,
    ]);
});

// ————————————————
// 2️⃣ Șterge utilizator
// ————————————————
add_action('wp_ajax_edu_delete_user', function() {
    check_ajax_referer('edu_nonce', 'nonce');
    $user_id = intval($_POST['user_id'] ?? 0);

    if ($user_id && current_user_can('delete_users')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user_id);
        wp_send_json_success();
    }
    wp_send_json_error();
});

// 4️⃣ Trimite link resetare parolă individuală
add_action('wp_ajax_edu_send_reset_link', function() {
    check_ajax_referer('edu_nonce', 'nonce');

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    if (!$user_id || !current_user_can('edit_user', $user_id)) {
        wp_send_json_error(['message' => 'Permisiuni insuficiente']);
    }

    $user = get_userdata($user_id);
    if (!$user) {
        wp_send_json_error(['message' => 'Utilizator inexistent']);
    }

    // Folosește sistemul WP pentru resetare parolă
    $result = retrieve_password($user->user_login);
    if (!is_wp_error($result)) {
        wp_send_json_success();
    } else {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }
});

// Search / Load Schools (for admin and for teacher/tutor)
add_action('wp_ajax_edu_search_schools', function() {
    check_ajax_referer('edu_nonce', 'nonce');
    global $wpdb;

    $q         = sanitize_text_field($_POST['q'] ?? '');
    $county_id = intval($_POST['county_id'] ?? 0);
    $city_id   = intval($_POST['city_id'] ?? 0);
    $table_s   = $wpdb->prefix . 'edu_schools';
    $table_c   = $wpdb->prefix . 'edu_cities';
    $table_ct  = $wpdb->prefix . 'edu_counties';

    // If not admin, limit to assigned schools (unchanged)
    if ( ! current_user_can('manage_options') ) {
        $assigned = get_user_meta(get_current_user_id(), 'assigned_school_ids', true) ?: [];
        if ( empty($assigned) ) {
            wp_send_json([]);
        }
        $placeholders = implode(',', array_fill(0, count($assigned), '%d'));
        $rows = $wpdb->get_results( $wpdb->prepare("
            SELECT s.id, s.cod, s.name, c.name AS city, ct.name AS county
            FROM {$table_s} s
            JOIN {$table_c}  c  ON s.city_id   = c.id
            JOIN {$table_ct} ct ON c.county_id = ct.id
            WHERE s.id IN ({$placeholders})
            ORDER BY s.name
        ", ...$assigned) );

    } else {
        // Admin: search by name OR code OR city OR county, with optional county/city filters
        $where_parts = [];
        $params      = [];

        if ($county_id > 0) {
            $where_parts[] = 'c.county_id = %d';
            $params[]      = $county_id;
        }
        if ($city_id > 0) {
            $where_parts[] = 's.city_id = %d';
            $params[]      = $city_id;
        }
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where_parts[] = '(s.name LIKE %s OR CAST(s.cod AS CHAR) LIKE %s OR c.name LIKE %s OR ct.name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
        $limit = ($county_id > 0 || $city_id > 0) ? 500 : 50;

        $sql = "
            SELECT s.id, s.cod, s.name, c.name AS city, ct.name AS county
            FROM {$table_s} s
            JOIN {$table_c}  c  ON s.city_id   = c.id
            JOIN {$table_ct} ct ON c.county_id = ct.id
            {$where_sql}
            ORDER BY ct.name, c.name, s.name
            LIMIT {$limit}
        ";

        if (!empty($params)) {
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        } else {
            $rows = $wpdb->get_results($sql);
        }
    }

    // Map for Select2
    $out = array_map(function($r){
        return [
            'id'     => (int)$r->id,
            'cod'    => (string)$r->cod,
            'name'   => $r->name,
            'city'   => $r->city,
            'county' => $r->county,
            'text'   => "{$r->cod} – {$r->name} – {$r->city} – {$r->county}",
        ];
    }, $rows ?: []);

    wp_send_json($out);
});

// -- Check if email already exists in WP users --
add_action('wp_ajax_edu_check_email', function(){
    check_ajax_referer('edu_nonce', 'nonce');
    $email   = sanitize_email(isset($_POST['email']) ? $_POST['email'] : '');
    $user_id = intval(isset($_POST['user_id']) ? $_POST['user_id'] : 0);

    if (!$email || !is_email($email)) {
        wp_send_json_error(array('exists' => false, 'message' => 'Email invalid.'));
    }

    $existing = email_exists($email);
    if ($existing && (int)$existing !== $user_id) {
        $u = get_userdata($existing);
        $fn = $u ? trim($u->first_name . ' ' . $u->last_name) : '';
        if (!$fn && $u) $fn = $u->display_name ? $u->display_name : $u->user_login;
        wp_send_json_error(array(
            'exists'  => true,
            'message' => 'Emailul este deja folosit de: ' . $fn . ' (#' . $existing . ').',
        ));
    }

    wp_send_json_success(array('exists' => false));
});

// — Fetch a teacher's nivel_predare meta —
add_action('wp_ajax_edu_get_teacher_meta', function(){
    check_ajax_referer('edu_nonce','nonce');
    $tid = intval($_POST['teacher_id'] ?? 0);
    if (!$tid) wp_send_json_error();
    $nivel = get_user_meta($tid,'nivel_predare',true);
    wp_send_json_success(['nivel'=>$nivel]);
});

// — AJAX search for professors — 
add_action('wp_ajax_edu_search_teachers', function(){
    check_ajax_referer('edu_nonce','nonce');
    $q = sanitize_text_field($_POST['q'] ?? '');
    $args = [
        'role'           => 'profesor',
        'search'         => "*{$q}*",
        'search_columns' => ['display_name','user_email','user_login'],
        'number'         => 50,
    ];
    $users = get_users($args);
    $out = [];
    foreach ($users as $u) {
        $out[] = ['id'=>$u->ID,'text'=>$u->display_name];
    }
    wp_send_json($out);
});

add_action('wp_ajax_edu_add_generatie', function(){
  check_ajax_referer('edu_nonce','nonce');
  $g = sanitize_text_field($_POST['generatie']);
  $gens = get_option('edu_generatii', []);
  if (!in_array($g,$gens)){
    $gens[] = $g;
    update_option('edu_generatii',$gens);
  }
  wp_send_json_success();
});


// ————————————————
// 🆕 Returnează toate detaliile unui user
// ————————————————
add_action('wp_ajax_edu_get_user_details', function(){
    check_ajax_referer('edu_nonce','nonce');

    $uid = intval($_POST['user_id'] ?? 0);
    if(!$uid) wp_send_json_error();

    $u = get_userdata($uid);
    if(!$u) wp_send_json_error();

    ob_start();
    ?>
    <ul style="list-style:none;padding:0;">
      <li><strong>Nume complet:</strong> <?php echo esc_html($u->display_name); ?></li>
      <li><strong>Email:</strong> <?php echo esc_html($u->user_email); ?></li>
      <li><strong>Rol:</strong> <?php echo esc_html(implode(', ',$u->roles)); ?></li>
      <li><strong>Telefon:</strong> <?php echo esc_html(get_user_meta($uid,'phone',true)); ?></li>
    <?php
    // profesor-only metas
    if(in_array('profesor',$u->roles,true)){
      $fields = [
        'user_status_profesor'=>'Status profesor',
        'generatie'=>'Generație',
        'an_program'=>'An program',
        'cod_slf'=>'Cod prof SLF',
        'statut_prof'=>'Statut',
        'calificare'=>'Calificare',
        'experienta'=>'Experiență',
        'segment_rsoi'=>'Segment RSOI',
        'nivel_predare'=>'Nivel de predare',
        'materia_predata'=>'Materia predată',
        'materia_alta'=>'Materie (altă)',
        'mentor_sel'=>'Mentor SEL',
        'mentor_literatie'=>'Mentor literatie',
        'mentor_numeratie'=>'Mentor numeratie',
      ];
      foreach($fields as $meta_key=>$label){
        $val = get_user_meta($uid,$meta_key,true);
        if(!$val) continue;
        echo "<li><strong>".esc_html($label).":</strong> ".esc_html($val)."</li>";
      }
    }
    ?>
    </ul>
    <?php
    $html = ob_get_clean();
    wp_send_json_success(['html'=>$html]);
});


// Orașe după județ (pentru filtrul din pagina Elevi)
add_action('wp_ajax_edu_cities_by_county', function () {
    check_ajax_referer('edu_nonce', 'nonce');
    if ( ! current_user_can('manage_edu_classes') && ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'no perms']);
    }

    global $wpdb;
    $county_id = isset($_POST['county_id']) ? intval($_POST['county_id']) : 0;
    $cities_table = $wpdb->prefix . 'edu_cities';

    if ($county_id > 0) {
        $cities = $wpdb->get_results(
            $wpdb->prepare("SELECT id, name FROM {$cities_table} WHERE county_id = %d ORDER BY name", $county_id)
        );
    } else {
        $cities = $wpdb->get_results("SELECT id, name FROM {$cities_table} ORDER BY name");
    }

    wp_send_json_success([
        'cities' => array_map(function($c){ return ['id'=>(int)$c->id,'name'=>$c->name]; }, $cities ?: [])
    ]);
});

// ————————————————
// Email bun venit profesor nou
// ————————————————
function edu_send_welcome_email_profesor($user_id, $first_name, $last_name, $email) {
    $user = get_userdata($user_id);
    if (!$user) return;

    $reset_key = get_password_reset_key($user);
    if (is_wp_error($reset_key)) return;

    $set_password_url = network_site_url(
        "wp-login.php?action=rp&key={$reset_key}&login=" . rawurlencode($user->user_login),
        'login'
    );

    // Escape pentru HTML email (previne HTML injection în nume/email)
    $first_name = esc_html($first_name);
    $last_name  = esc_html($last_name);
    $email      = esc_html($email);
    $set_password_url = esc_url($set_password_url);

    $site_name = 'EduStart';
    $logo_url  = esc_url(get_stylesheet_directory_uri() . '/resources/images/edustart-logo.png');
    $year      = date('Y');

    $body = <<<HTML
<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f1f5f9;padding:40px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);">

          <!-- Header -->
          <tr>
            <td style="background-color:#0f172a;padding:32px 40px;text-align:center;">
              <img src="{$logo_url}" alt="EduStart" height="40" style="height:40px;display:inline-block;">
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:40px;">
              <h1 style="margin:0 0 8px;font-size:22px;font-weight:700;color:#0f172a;">
                Bun venit pe platforma EduStart!
              </h1>
              <p style="margin:0 0 24px;font-size:15px;color:#475569;line-height:1.6;">
                Stimat/&#259; {$first_name} {$last_name},
              </p>
              <p style="margin:0 0 24px;font-size:15px;color:#475569;line-height:1.6;">
                &#206;&#539;i confirm&#259;m c&#259; contul t&#259;u a fost creat cu succes pe platforma
                <strong style="color:#0f172a;">EduStart</strong>, parte a programului Teach for Romania.
                Prin intermediul acestei platforme vei putea gestiona clasele, elevii &#537;i evalu&#259;rile tale.
              </p>

              <!-- Account details -->
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 32px;background-color:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;">
                <tr>
                  <td style="padding:20px 24px;">
                    <p style="margin:0 0 4px;font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;">
                      Detalii cont
                    </p>
                    <p style="margin:0;font-size:15px;color:#0f172a;line-height:1.6;">
                      <strong>Email (user):</strong> {$email}
                    </p>
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 28px;font-size:15px;color:#475569;line-height:1.6;">
                Pentru a-&#539;i activa contul, te rug&#259;m s&#259; &#238;&#539;i setezi o parol&#259;
                ap&#259;s&#226;nd butonul de mai jos:
              </p>

              <!-- CTA Button -->
              <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto 32px;">
                <tr>
                  <td align="center" style="border-radius:12px;background-color:#057a55;">
                    <a href="{$set_password_url}"
                       target="_blank"
                       style="display:inline-block;padding:14px 40px;font-size:16px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:12px;">
                      Seteaz&#259; parola
                    </a>
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 8px;font-size:13px;color:#94a3b8;line-height:1.5;">
                Dac&#259; butonul nu func&#539;ioneaz&#259;, copiaz&#259; &#537;i lipe&#537;te acest link &#238;n browser:
              </p>
              <p style="margin:0 0 24px;font-size:13px;color:#3b82f6;word-break:break-all;line-height:1.5;">
                {$set_password_url}
              </p>

              <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">

              <p style="margin:0;font-size:15px;color:#475569;line-height:1.6;">
                Dup&#259; setarea parolei, vei fi direc&#539;ionat c&#259;tre pagina de autentificare.
                Te a&#537;tept&#259;m cu drag pe platform&#259;!
              </p>
              <p style="margin:16px 0 0;font-size:15px;color:#475569;line-height:1.6;">
                Cu stim&#259;,<br>
                <strong style="color:#0f172a;">Echipa EduStart</strong>
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background-color:#f8fafc;padding:24px 40px;border-top:1px solid #e2e8f0;text-align:center;">
              <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.5;">
                &copy; {$year} EduStart &middot; Teach for Romania<br>
                Acest email a fost trimis automat. Te rug&#259;m s&#259; nu r&#259;spunzi la acest mesaj.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $subject = 'Bun venit pe platforma EduStart — Activeaz&#259;-&#539;i contul';

    wp_mail($email, html_entity_decode($subject, ENT_QUOTES, 'UTF-8'), $body, $headers);
}
