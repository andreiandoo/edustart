<?php

/**
 * Theme setup.
 */
function tailpress_setup() {
	add_theme_support( 'title-tag' );

	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'tailpress' ),
		)
	);

	add_theme_support(
		'html5',
		array(
			'search-form',
			'comment-form',
			'comment-list',
			'gallery',
			'caption',
		)
	);

    add_theme_support( 'custom-logo' );
	add_theme_support( 'post-thumbnails' );

	add_theme_support( 'align-wide' );
	add_theme_support( 'wp-block-styles' );

	add_theme_support( 'responsive-embeds' );

	add_theme_support( 'editor-styles' );
	add_editor_style( 'css/editor-style.css' );
}

add_action( 'after_setup_theme', 'tailpress_setup' );

/**
 * Enqueue theme assets.
 */
function tailpress_enqueue_scripts() {
	$theme = wp_get_theme();

	wp_enqueue_style( 'tailpress', tailpress_asset( 'css/app.css' ), array(), $theme->get( 'Version' ) );
	wp_enqueue_script( 'tailpress', tailpress_asset( 'js/app.js' ), array(), $theme->get( 'Version' ) );
}

add_action( 'wp_enqueue_scripts', 'tailpress_enqueue_scripts' );

/**
 * Get asset path.
 *
 * @param string  $path Path to asset.
 *
 * @return string
 */
function tailpress_asset( $path ) {
	if ( wp_get_environment_type() === 'production' ) {
		return get_stylesheet_directory_uri() . '/' . $path;
	}

	return add_query_arg( 'time', time(),  get_stylesheet_directory_uri() . '/' . $path );
}

/**
 * Adds option 'li_class' to 'wp_nav_menu'.
 *
 * @param string  $classes String of classes.
 * @param mixed   $item The current item.
 * @param WP_Term $args Holds the nav menu arguments.
 *
 * @return array
 */
function tailpress_nav_menu_add_li_class( $classes, $item, $args, $depth ) {
	if ( isset( $args->li_class ) ) {
		$classes[] = $args->li_class;
	}

	if ( isset( $args->{"li_class_$depth"} ) ) {
		$classes[] = $args->{"li_class_$depth"};
	}

	return $classes;
}

add_filter( 'nav_menu_css_class', 'tailpress_nav_menu_add_li_class', 10, 4 );

/**
 * Adds option 'submenu_class' to 'wp_nav_menu'.
 *
 * @param string  $classes String of classes.
 * @param mixed   $item The current item.
 * @param WP_Term $args Holds the nav menu arguments.
 *
 * @return array
 */
function tailpress_nav_menu_add_submenu_class( $classes, $args, $depth ) {
	if ( isset( $args->submenu_class ) ) {
		$classes[] = $args->submenu_class;
	}

	if ( isset( $args->{"submenu_class_$depth"} ) ) {
		$classes[] = $args->{"submenu_class_$depth"};
	}

	return $classes;
}

add_filter( 'nav_menu_submenu_css_class', 'tailpress_nav_menu_add_submenu_class', 10, 3 );


//#####################################################
// START CUSTOM DEVELOPMENT //
//#####################################################

// =========================================================================
// Disable the emoji's
// =========================================================================
function disable_emojis() {
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );    
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );  
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    
    // Remove from TinyMCE
    add_filter( 'tiny_mce_plugins', 'disable_emojis_tinymce' );
}
add_action( 'init', 'disable_emojis' );

function disable_emojis_tinymce( $plugins ) {
    if ( is_array( $plugins ) ) {
        return array_diff( $plugins, array( 'wpemoji' ) );
    } else {
        return array();
    }
}
// =========================================================================
// REMOVE USELESS FROM BACKEND
// =========================================================================
function remove_screenoptions() {
    echo '<style type="text/css">
    #screen-meta-links,.notice.is-dismissible, .notice.notice-warning, .wcpdf-extensions-ad{ display: none; }
    #wpcontent { background:#fff;}
    </style>';
}
//add_action('admin_head', 'remove_screenoptions');
function no_update_notification() {
    remove_action('admin_notices', 'update_nag', 3);
}
add_action('admin_notices', 'no_update_notification', 1);


function remove_dashboard_widgets() {
  global $wp_meta_boxes;
  unset($wp_meta_boxes['dashboard']['normal']['core']['dashboard_right_now']);  //remove at-a-glance
  unset($wp_meta_boxes['dashboard']['side']['core']['dashboard_secondary']);    //remove WordPress-newsfeed
  unset($wp_meta_boxes['dashboard']['side']['core']['dashboard_quick_press']);  //remove quick-draft
}
add_action('wp_dashboard_setup', 'remove_dashboard_widgets');

// =========================================================================
// REMOVE USELESS FROM FRONTEND
// =========================================================================
function wpbeginner_remove_version() {
    return '';
}
add_filter('the_generator', 'wpbeginner_remove_version');

// remove wp version number from scripts and styles
function remove_css_js_version( $src ) {
    if( strpos( $src, '?ver=' ) )
        $src = remove_query_arg( 'ver', $src );
    return $src;
}
add_filter( 'style_loader_src', 'remove_css_js_version', 9999 );
add_filter( 'script_loader_src', 'remove_css_js_version', 9999 );

remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_generator');
remove_action ('wp_head', 'rsd_link');
remove_action('wp_head', 'wp_shortlink_wp_head');
remove_action('wp_head', 'feed_links_extra', 3); // Remove Every Extra Links to Rss Feeds.
remove_action('wp_head', 'feed_links', 2);
remove_action('wp_head', 'wc_products_rss_feed');
remove_action('wp_head', 'adjacent_posts_rel_link_wp_head'); // Remove pagination Previous Next Articles
// Remove API & JSON Embed
remove_action( 'wp_head','rest_output_link_wp_head');
remove_action( 'wp_head','wp_oembed_add_discovery_links');
remove_action( 'wp_head','rest_output_link_header', 11);
// Remove useless styles
add_action( 'wp_print_styles', 'remove_styles', 100 );
function remove_styles(){
    //wp_deregister_style('dashicons');
}
// Remove useless scripts
add_action('wp_enqueue_scripts', 'remove_scripts', 100);
function remove_scripts(){
    wp_dequeue_style( 'wp-block-library' );
    wp_dequeue_style( 'wp-block-library-theme' );
    wp_dequeue_style( 'wc-blocks-style' );
    wp_dequeue_style( 'global-styles' );
    wp_dequeue_style( 'classic-theme-styles' );
}

// Remove ALL WordPress global inline styles and SVG filters
add_action( 'init', function() {
    remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
    remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
    remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
    remove_action( 'in_admin_header', 'wp_global_styles_render_svg_filters' );
});

// =========================================================================
// REMOVE USELESS FROM WOOCOMMERCE
// =========================================================================
add_filter( 'woocommerce_marketing_menu_items', '__return_empty_array' );

add_filter( 'woocommerce_admin_features', 'disable_features' );
function disable_features( $features ) {
    $marketing = array_search('marketing', $features);
    unset( $features[$marketing] );
    return $features;
}

add_filter( 'woocommerce_admin_disabled', '__return_true' );

/* Disable extensions menu WooCommerce */
add_action( 'admin_menu', 'wcbloat_remove_admin_addon_submenu', 999 );
function wcbloat_remove_admin_addon_submenu() {
    remove_submenu_page( 'woocommerce', 'wc-addons');
}

add_filter( 'woocommerce_allow_marketplace_suggestions', '__return_false', 999 ); //Extension suggestions
add_filter( 'woocommerce_helper_suppress_admin_notices', '__return_true' ); //Connect to woocommerce.com

/* Disable WooCommerce dashboard status widget */
add_action('wp_dashboard_setup', 'wcbloat_disable_woocommerce_status');
function wcbloat_disable_woocommerce_status() {
    remove_meta_box('woocommerce_dashboard_status', 'dashboard', 'normal');
}

/* Disable WooCommerce widgets */
add_action('widgets_init', 'wphelp_disable_widgets_woo', 99);
function wphelp_disable_widgets_woo() {
    unregister_widget('WC_Widget_Products');
    unregister_widget('WC_Widget_Product_Categories');
    unregister_widget('WC_Widget_Product_Tag_Cloud');
    unregister_widget('WC_Widget_Cart');
    unregister_widget('WC_Widget_Layered_Nav');
    unregister_widget('WC_Widget_Layered_Nav_Filters');
    unregister_widget('WC_Widget_Price_Filter');
    unregister_widget('WC_Widget_Product_Search');
    unregister_widget('WC_Widget_Recently_Viewed');
    unregister_widget('WC_Widget_Recent_Reviews');
    unregister_widget('WC_Widget_Top_Rated_Products');
    unregister_widget('WC_Widget_Rating_Filter');
}

/* Disable styles and scripts WooCommerce */
add_action('wp_enqueue_scripts', 'wphelp_disable_scripts_woocommerce', 99);
function wphelp_disable_scripts_woocommerce() {
    if(function_exists('is_woocommerce')) {
        if(!is_woocommerce() && !is_cart() && !is_checkout() && !is_account_page() && !is_product() && !is_product_category() && !is_shop()) {
            //Styles
            wp_dequeue_style('woocommerce-general');
            wp_dequeue_style('woocommerce-layout');
            wp_dequeue_style('woocommerce-smallscreen');
            wp_dequeue_style('woocommerce_frontend_styles');
            wp_dequeue_style('woocommerce_fancybox_styles');
            wp_dequeue_style('woocommerce_chosen_styles');
            wp_dequeue_style('woocommerce_prettyPhoto_css');
            //Scripts
            wp_dequeue_script('wc_price_slider');
            wp_dequeue_script('wc-single-product');
            wp_dequeue_script('wc-add-to-cart');
            wp_dequeue_script('wc-checkout');
            wp_dequeue_script('wc-add-to-cart-variation');
            wp_dequeue_script('wc-single-product');
            wp_dequeue_script('wc-cart');
            wp_dequeue_script('wc-chosen');
            wp_dequeue_script('woocommerce');
            wp_dequeue_script('prettyPhoto');
            wp_dequeue_script('prettyPhoto-init');
            wp_dequeue_script('jquery-blockui');
            wp_dequeue_script('jquery-placeholder');
            wp_dequeue_script('fancybox');
            wp_dequeue_script('jqueryui');
        }
    }
}
// =========================================================================
// ADD SVG SUPPORT
// =========================================================================
/**
 * Allow SVG uploads for administrator users.
 */
add_filter(
	'upload_mimes',
	function ( $upload_mimes ) {
		// By default, only administrator users are allowed to add SVGs.
		// To enable more user types edit or comment the lines below but beware of
		// the security risks if you allow any user to upload SVG files.
		if ( ! current_user_can( 'administrator' ) ) {
			return $upload_mimes;
		}

		$upload_mimes['svg']  = 'image/svg+xml';
		$upload_mimes['svgz'] = 'image/svg+xml';

		return $upload_mimes;
	}
);

/**
 * Add SVG files mime check.
 */
add_filter(
	'wp_check_filetype_and_ext',
	function ( $wp_check_filetype_and_ext, $file, $filename, $mimes, $real_mime ) {

		if ( ! $wp_check_filetype_and_ext['type'] ) {

			$check_filetype  = wp_check_filetype( $filename, $mimes );
			$ext             = $check_filetype['ext'];
			$type            = $check_filetype['type'];
			$proper_filename = $filename;

			if ( $type && 0 === strpos( $type, 'image/' ) && 'svg' !== $ext ) {
				$ext  = false;
				$type = false;
			}

			$wp_check_filetype_and_ext = compact( 'ext', 'type', 'proper_filename' );
		}

		return $wp_check_filetype_and_ext;

	},
	10,
	5
);

//#####################################################
// CUSTOM MENUS ##
//#####################################################
function register_footer_menus() {
    register_nav_menu('footer_menu', 'Footer menu');
    register_nav_menu('footer_logged_menu', 'Footer Logged menu');
}
// function register_top_menus() {
//     register_nav_menu('top_left_menu', 'Top Left');
//     register_nav_menu('top_nav_shop', 'Shop');
//     register_nav_menu('top_nav_configurator', 'Configurator');
//     register_nav_menu('top_nav_blog', 'Blog');
// }
add_action('init', 'register_footer_menus');
// add_action('init', 'register_top_menus');



add_action('pre_get_posts', function($query) {
    if ($query->is_main_query() && !is_admin() && is_home()) {
        $paged = get_query_var('paged') ?: (get_query_var('page') ?: 1);
        $query->set('paged', $paged);
    }
});

function estimate_reading_time($content, $wpm = 200) {
    $word_count = str_word_count(strip_tags($content));
    $minutes = ceil($word_count / $wpm);
    return max(1, $minutes);
}


add_filter('logout_redirect', function($redirect_to, $requested_redirect_to, $user) {
    return home_url();
}, 10, 3);

/**
 * Redirect utilizatori logați (non-admin) către /panou
 * și blochează accesul la wp-admin.
 */
add_action('admin_init', function() {
    if (is_user_logged_in() && !current_user_can('administrator')) {
        // Permitem acces doar la AJAX (pentru ca editorul/tema să funcționeze corect)
        if (!(defined('DOING_AJAX') && DOING_AJAX)) {
            wp_redirect(home_url('/panou'));
            exit;
        }
    }
});

add_filter('login_redirect', function($redirect_to, $request, $user) {
    if (isset($user->roles) && is_array($user->roles)) {
        if (!in_array('administrator', $user->roles, true)) {
            return home_url('/panou');
        }
    }
    return $redirect_to; // default pt. admin
}, 10, 3);

// După resetarea parolei, redirect la pagina custom de login
add_action('after_password_reset', function($user, $new_pass) {
    $login_page = home_url('/login/');
    wp_safe_redirect($login_page . '?password=changed');
    exit;
}, 10, 2);

// ===========================================
// REWRITE RULES FOR CLASSES
// ===========================================
function edu_add_rewrite_rules() {
    add_rewrite_rule(
        '^panou/clase/clasa-([0-9]+)/?$',
        'index.php?pagename=view-class&class_id=$matches[1]',
        'top'
    );
}
add_action('init', 'edu_add_rewrite_rules');
// ===========================================
// ADD QUERY VARS FOR CLASSES
// ===========================================
// This function adds 'class_id' to the list of query variables
// so that it can be used in the URL.
// This is necessary for the rewrite rule to work correctly.
function edu_add_query_vars($vars) {
    $vars[] = 'class_id';
    return $vars;
}
add_filter('query_vars', 'edu_add_query_vars');

add_filter('redirect_canonical', function ($redirect_url) {
    if (get_query_var('class_id')) {
        return false;
    }
    return $redirect_url;
});


require_once get_template_directory() . '/ajax/ajax-students.php';


// === GET STUDENTS (TUTOR → ADMIN → PROF) ===
add_action('wp_ajax_get_students', 'get_students_callback');
function get_students_callback() {
  if (!is_user_logged_in()) {
    wp_send_json_error('Autentifică-te.');
  }

  global $wpdb;

  // Acceptăm generation_id; compat cu JS vechi (class_id)
  $generation_id = intval($_POST['generation_id'] ?? ($_POST['class_id'] ?? 0));
  if (!$generation_id) {
    wp_send_json_error('Lipsește generation_id.');
  }

  $students_table    = $wpdb->prefix . 'edu_students';
  $generations_table = $wpdb->prefix . 'edu_generations';
  $results_table     = $wpdb->prefix . 'edu_results';

  // — Helpers mb_*
  if (!function_exists('edus_mb_substr')) {
    function edus_mb_substr($s, $start, $len = null) {
      return function_exists('mb_substr') ? mb_substr($s, $start, $len ?? null) : substr($s, $start, $len ?? null);
    }
  }
  if (!function_exists('edus_mb_lc')) {
    function edus_mb_lc($s) {
      return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
  }

  // — Generație + proprietar
  $gen = $wpdb->get_row($wpdb->prepare(
    "SELECT id, professor_id, level FROM {$generations_table} WHERE id = %d",
    $generation_id
  ));
  if (!$gen) {
    wp_send_json_error('Generație inexistentă.');
  }
  $owner_prof_id = (int) $gen->professor_id;

  // — Roluri & prioritate: TUTOR → ADMIN → PROF
  $current_user_id = get_current_user_id();
  $current_user    = wp_get_current_user();
  $roles           = (array) $current_user->roles;

  $is_prof  = in_array('profesor', $roles, true);
  $is_tutor = in_array('tutor',    $roles, true);
  $is_admin = current_user_can('manage_options');

  $viewer_mode = 'UNKNOWN';

  // 1) TUTOR (dacă e alocat profesorului proprietar)
  if ($is_tutor) {
    $assigned_tid = (int) get_user_meta($owner_prof_id, 'assigned_tutor_id', true);
    if ($assigned_tid === (int)$current_user_id) {
      $viewer_mode = 'TUTOR';
    }
  }

  // 2) ADMIN (dacă nu e deja TUTOR)
  if ($viewer_mode === 'UNKNOWN' && $is_admin) {
    $viewer_mode = 'ADMIN';
  }

  // 3) PROF (deținătorul generației)
  if ($viewer_mode === 'UNKNOWN' && $is_prof && $owner_prof_id === (int)$current_user_id) {
    $viewer_mode = 'PROF';
  }

  if ($viewer_mode === 'UNKNOWN') {
    wp_send_json_error('Nu ai permisiuni pentru această generație.');
  }

  $can_edit = ($viewer_mode === 'PROF' || $viewer_mode === 'ADMIN');

  $class_level = trim((string)$gen->level);
  $group_slug  = sanitize_title($class_level ?: '');

  // — Elevii generației (ai profesorului proprietar!)
  $students = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$students_table} WHERE generation_id = %d AND professor_id = %d ORDER BY last_name, first_name",
    $generation_id, $owner_prof_id
  ));

  // — helpers: SEL group per elev (pt. „primar” împărțit: mic/mare)
  $resolve_sel_group_for_student = function($class_level, $class_label) use ($group_slug) {
    if ($class_level !== 'primar') return $group_slug;
    $label = edus_mb_lc(trim((string)$class_label));
    $n = null;
    if (preg_match('/\b(\d{1,2})\b/u', $label, $m)) {
      $n = (int)$m[1];
    } elseif (strpos($label, 'pregat') !== false || strpos($label, 'pregăt') !== false) {
      $n = 0; // clasa pregătitoare
    }
    if ($n === null) return 'primar-mic';
    return ($n <= 2) ? 'primar-mic' : 'primar-mare';
  };

  // — helpers: LIT base per elev (în funcție de nivel + eticheta clasei)
  $resolve_lit_base_for_student = function($class_level, $class_label) {
    $lvl = edus_mb_lc(trim((string)$class_level));
    $label = edus_mb_lc(trim((string)$class_label));

    if ($lvl === 'prescolar') return 'literatie-prescolar';
    if ($lvl === 'gimnazial') return 'literatie-gimnaziu';

    if ($lvl === 'primar') {
      $n = null;
      if (preg_match('/\b(\d{1,2})\b/u', $label, $m)) {
        $n = (int)$m[1];
      } elseif (strpos($label, 'pregat') !== false || strpos($label, 'pregăt') !== false) {
        $n = 0;
      }
      if ($n === 0) return 'literatie-clasa-pregatitoare';
      return 'literatie-primar';
    }
    // liceu => fallback
    return 'literatie-primar';
  };

  // — Helper: buton evaluare (activ sau disabled)
  $render_eval_btn = function($label, $proc, $type, $slug, $student_id, $status = '') use ($can_edit) {
    $proc = (int) $proc;
    $is_draft = ($proc > 0 && $status === 'draft');

    $color = 'bg-slate-100 text-slate-800 ring-slate-200';
    if     ($proc >= 75) $color = 'bg-emerald-600 text-white ring-emerald-600/20';
    elseif ($proc >= 50) $color = 'bg-orange-500 text-white ring-orange-500/20';
    elseif ($proc >= 25) $color = 'bg-yellow-400 text-slate-900 ring-yellow-300/40';
    elseif ($proc > 0)   $color = 'bg-rose-600 text-white ring-rose-600/20';

    $draft_icon = $is_draft
      ? '<span class="inline-flex items-center justify-center w-4 h-4 ml-1 text-[10px] font-bold text-amber-800 bg-amber-400 rounded-full shrink-0" title="Salvat temporar">!</span>'
      : '';

    if ($can_edit) {
      return '<span class="inline-flex items-center">'
             . '<button class="start-questionnaire inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-medium rounded-full shadow-sm ring-1 ring-inset hover:opacity-95 '
             . esc_attr($color) . '"'
             . ' data-id="' . esc_attr($student_id) . '"'
             . ' data-type="' . esc_attr($type) . '"'
             . ' data-modul="' . esc_attr($slug) . '">'
             . esc_html($label) . ' (' . $proc . '%)</button>'
             . $draft_icon . '</span>';
    }

    // read-only (Tutor): vizibil dar neclickabil
    return '<span class="inline-flex items-center">'
           . '<button class="inline-flex items-center justify-center px-2.5 py-1.5 text-xs font-medium rounded-full shadow-sm ring-1 ring-inset opacity-60 cursor-not-allowed '
           . esc_attr($color) . '" disabled aria-disabled="true" tabindex="-1">'
           . esc_html($label) . ' (' . $proc . '%)</button>'
           . $draft_icon . '</span>';
  };

  ob_start();

  if (!empty($students)) : ?>
    <div class="relative">
      <div class="relative min-h-screen overflow-x-auto bg-white border shadow-sm rounded-2xl border-slate-200">
        <table class="relative w-full text-sm table-auto">
          <thead class="sticky top-0 bg-sky-800 backdrop-blur">
            <tr class="text-white">
              <th class="px-3 py-3 font-semibold text-left border-b border-slate-200">#</th>
              <th class="px-3 py-3 font-semibold text-left border-b border-slate-200">Nume Elev</th>
              <th class="px-3 py-3 font-semibold text-center border-b border-slate-200">Vârstă</th>
              <th class="px-3 py-3 font-semibold text-center border-b border-slate-200">Gen</th>
              <th class="px-3 py-3 font-semibold text-center border-b border-slate-200">Clasă</th>
              <th colspan="3" class="px-3 py-3 font-semibold text-center border-b border-slate-200">Evaluări SEL</th>
              <th colspan="2" class="px-3 py-3 font-semibold text-center border-b border-slate-200">Evaluări Literație</th>
              <th class="px-3 py-3 font-semibold text-center border-b border-slate-200">Raport</th>
              <?php if ($can_edit): ?>
                <th class="px-3 py-3 font-semibold text-center border-b border-slate-200">Acțiuni</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody class="[&>tr:nth-child(odd)]:bg-slate-50/40">
            <?php foreach ($students as $index => $student): ?>
              <?php $initials = strtoupper(edus_mb_substr($student->first_name, 0, 1) . edus_mb_substr($student->last_name, 0, 1)); ?>
              <tr id="student-row-<?php echo esc_attr($student->id); ?>" data-student-row="<?php echo esc_attr($student->id); ?>" class="transition-colors border-t border-slate-200">
                <td class="px-3 py-3 align-middle text-slate-600"><?php echo $index + 1; ?></td>

                <td class="px-3 py-3 align-middle">
                  <div class="flex items-center gap-3">
                    <span class="inline-flex items-center justify-center text-xs font-bold text-white rounded-full shadow-sm size-8 bg-gradient-to-br from-slate-600 to-slate-800">
                      <?php echo esc_html($initials); ?>
                    </span>
                    <button type="button"
                            class="font-semibold student-name text-slate-900 hover:text-emerald-700 toggle-details"
                            data-id="<?php echo esc_attr($student->id); ?>">
                      <?php echo esc_html("{$student->first_name} {$student->last_name}"); ?>
                    </button>
                  </div>
                </td>

                <td class="px-3 py-3 text-center align-middle text-slate-700"><?php echo esc_html($student->age); ?> ani</td>
                <td class="px-3 py-3 text-center align-middle text-slate-700"><?php echo esc_html($student->gender ?: '—'); ?></td>
                <td class="px-3 py-3 text-center align-middle text-slate-700"><?php echo esc_html($student->class_label ?: '—'); ?></td>

                <?php
                  // — SEL (per elev)
                  $sel_group_slug = $resolve_sel_group_for_student($class_level, $student->class_label);
                  $sel_evals = [
                    ['base' => 'sel-t0', 'label' => 'SEL T0'],
                    ['base' => 'sel-ti', 'label' => 'SEL Ti'],
                    ['base' => 'sel-t1', 'label' => 'SEL T1'],
                  ];
                  foreach ($sel_evals as $eval) {
                    $full_slug = $sel_group_slug ? ($eval['base'] . '-' . $sel_group_slug) : $eval['base'];
                    $result = $wpdb->get_row($wpdb->prepare(
                      "SELECT completion, status FROM {$results_table}
                        WHERE student_id = %d AND modul_type = 'sel' AND modul = %s
                        ORDER BY created_at DESC LIMIT 1",
                      $student->id, $full_slug
                    ));
                    $proc = $result ? intval($result->completion) : 0;
                    $rstatus = $result ? (string)$result->status : '';

                    echo '<td class="px-2 py-3 text-center align-middle">';
                    echo $render_eval_btn($eval['label'], $proc, 'sel', $full_slug, $student->id, $rstatus);
                    echo '</td>';
                  }

                  // — LIT (T0 & T1)
                  $lit_base = $resolve_lit_base_for_student($class_level, $student->class_label);
                  $lit_evals = [
                    ['slug' => $lit_base . '-t0', 'label' => 'LIT T0'],
                    ['slug' => $lit_base . '-t1', 'label' => 'LIT T1'],
                  ];
                  foreach ($lit_evals as $lit) {
                    $result = $wpdb->get_row($wpdb->prepare(
                      "SELECT completion, status FROM {$results_table}
                        WHERE student_id = %d AND modul_type = 'lit' AND modul = %s
                        ORDER BY created_at DESC LIMIT 1",
                      $student->id, $lit['slug']
                    ));
                    $proc = $result ? intval($result->completion) : 0;
                    $rstatus = $result ? (string)$result->status : '';

                    echo '<td class="px-2 py-3 text-center align-middle">';
                    echo $render_eval_btn($lit['label'], $proc, 'lit', $lit['slug'], $student->id, $rstatus);
                    echo '</td>';
                  }
                ?>

                <!-- Raport elev -->
                <td class="px-3 py-3 text-center align-middle">
                  <a href="<?php echo esc_url(home_url('/panou/raport/elev/' . $student->id)); ?>"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-full text-white bg-sky-600 hover:bg-sky-700 shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4">
                      <path fill-rule="evenodd" d="M5.625 1.5c-1.036 0-1.875.84-1.875 1.875v17.25c0 1.035.84 1.875 1.875 1.875h12.75c1.035 0 1.875-.84 1.875-1.875V12.75A3.75 3.75 0 0 0 16.5 9h-1.875a1.875 1.875 0 0 1-1.875-1.875V5.25A3.75 3.75 0 0 0 9 1.5H5.625ZM7.5 15a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5A.75.75 0 0 1 7.5 15Zm.75 2.25a.75.75 0 0 0 0 1.5H12a.75.75 0 0 0 0-1.5H8.25Z" clip-rule="evenodd" />
                      <path d="M12.971 1.816A5.23 5.23 0 0 1 14.25 5.25v1.875c0 .207.168.375.375.375H16.5a5.23 5.23 0 0 1 3.434 1.279 9.768 9.768 0 0 0-6.963-6.963Z" />
                    </svg>
                    Raport
                  </a>
                </td>

                <?php if ($can_edit): ?>
                <!-- Acțiuni (doar PROF/ADMIN) -->
                <td class="px-3 py-3 align-middle">
                  <div class="flex items-center justify-center gap-2">
                    <button class="inline-flex items-center justify-center bg-white border rounded-full shadow-sm edit-student size-8 border-slate-200 text-slate-700 hover:bg-slate-50"
                            title="Editează elevul"
                            data-id="<?php echo esc_attr($student->id); ?>">
                      <svg xmlns="http://www.w3.org/2000/svg" class="size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M21.731 2.269a2.625 2.625 0 0 0-3.712 0l-1.157 1.157 3.712 3.712 1.157-1.157a2.625 2.625 0 0 0 0-3.712ZM19.513 8.199l-3.712-3.712-12.15 12.15a5.25 5.25 0 0 0-1.32 2.214l-.8 2.685a.75.75 0 0 0 .933.933l2.685-.8a5.25 5.25 0 0 0 2.214-1.32L19.513 8.2Z"/></svg>
                    </button>
                    <button class="inline-flex items-center justify-center text-white rounded-full shadow-sm delete-student size-8 bg-rose-600 hover:bg-rose-700"
                            title="Șterge elevul"
                            data-id="<?php echo esc_attr($student->id); ?>">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-4">
                        <path fill-rule="evenodd" d="M16.5 4.478v.227a48.816 48.816 0 0 1 3.878.512.75.75 0 1 1-.256 1.478l-.209-.035-1.005 13.07a3 3 0 0 1-2.991 2.77H8.084a3 3 0 0 1-2.991-2.77L4.087 6.66l-.209.035a.75.75 0 0 1-.256-1.478A48.567 48.567 0 0 1 7.5 4.705v-.227c0-1.564 1.213-2.9 2.816-2.951a52.662 52.662 0 0 1 3.369 0c1.603.051 2.815 1.387 2.815 2.951Zm-6.136-1.452a51.196 51.196 0 0 1 3.273 0C14.39 3.05 15 3.684 15 4.478v.113a49.488 49.488 0 0 0-6 0v-.113c0-.794.609-1.428 1.364-1.452Zm-.355 5.945a.75.75 0 1 0-1.5.058l.347 9a.75.75 0 1 0 1.499-.058l-.346-9Zm5.48.058a.75.75 0 1 0-1.498-.058l-.347 9a.75.75 0 0 0 1.5.058l.345-9Z" clip-rule="evenodd" />
                      </svg>

                    </button>
                  </div>
                </td>
                <?php endif; ?>
              </tr>

              <!-- Detalii elev -->
              <tr id="student-details-<?php echo esc_attr($student->id); ?>" class="hidden">
                <td colspan="<?php echo $can_edit ? 12 : 11; ?>" class="px-4 py-4 border-t bg-slate-50 border-slate-100">
                  <div class="flex flex-wrap items-center justify-between gap-4 text-sm">
                    <p><span class="font-medium text-slate-500">Sit. absenteism:</span> <span class="font-bold text-slate-800"><?php echo esc_html($student->sit_abs ?: '—'); ?></span></p>
                    <p><span class="font-medium text-slate-500">Frecvență:</span> <span class="font-bold text-slate-800"><?php echo esc_html($student->frecventa ?: '—'); ?></span></p>
                    <p><span class="font-medium text-slate-500">Bursă:</span> <span class="font-bold text-slate-800"><?php echo esc_html($student->bursa ?: '—'); ?></span></p>
                    <p><span class="font-medium text-slate-500">Limba diferită:</span> <span class="font-bold text-slate-800"><?php echo esc_html($student->dif_limba ?: '—'); ?></span></p>
                    <?php if (!empty($student->cauze_abs)): ?>
                      <p><span class="font-medium text-slate-500">Cauze absenteism:</span> <span class="font-bold text-slate-800"><?php echo esc_html($student->cauze_abs); ?></span></p>
                    <?php endif; ?>
                    <p><span class="font-medium text-slate-500">Risc abandon:</span> <span class="font-bold text-slate-800"><?php echo esc_html(($student->risc_abandon ?? '') ?: '—'); ?></span></p>
                    <p><span class="font-medium text-slate-500">Repetă clasa:</span> <span class="font-bold text-slate-800"><?php echo esc_html(($student->repeta_clasa ?? '') ?: '—'); ?></span></p>
                    <p><span class="font-medium text-slate-500">Observație:</span> <span class="font-bold text-slate-800"><?php echo esc_html($student->observation ?: '—'); ?></span></p>
                    <?php if (!empty($student->alte_obs)): ?>
                      <p><span class="font-medium text-slate-500">Alte observații:</span> <span class="font-bold text-slate-800"><?php echo esc_html($student->alte_obs); ?></span></p>
                    <?php endif; ?>
                    <p><span class="font-medium text-slate-500">Demers familie:</span> <span class="font-bold text-slate-800"><?php echo esc_html(($student->demers_familie ?? '') ?: '—'); ?></span></p>
                    <p><span class="font-medium text-slate-500">Demers conducere:</span> <span class="font-bold text-slate-800"><?php echo esc_html(($student->demers_conducere ?? '') ?: '—'); ?></span></p>
                    <p><span class="font-medium text-slate-500">Demers consilier:</span> <span class="font-bold text-slate-800"><?php echo esc_html(($student->demers_consilier ?? '') ?: '—'); ?></span></p>
                    <p><span class="font-medium text-slate-500">Mențiuni:</span> <span class="font-bold text-slate-800"><?php echo esc_html($student->notes ?: '—'); ?></span></p>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php else: ?>
    <div class="px-4 py-3 bg-white border shadow-sm rounded-xl border-slate-200 text-slate-500">Niciun elev înregistrat momentan.</div>
  <?php endif;

  wp_send_json_success(ob_get_clean());
}




add_action('wp_ajax_get_student', 'get_student_callback');
function get_student_callback() {
  global $wpdb;
  $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;

  if (!$student_id) {
    wp_send_json_error('ID invalid');
    return;
  }

  $student = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}edu_students WHERE id = %d",
    $student_id
  ), ARRAY_A);

  if ($student) {
    wp_send_json_success($student);
  } else {
    wp_send_json_error('Elevul nu a fost găsit.');
  }
}

// Ștergere elev
add_action('wp_ajax_delete_student', 'delete_student_callback');
function delete_student_callback() {
  global $wpdb;
  $student_id = intval($_POST['student_id']);
  $table = $wpdb->prefix . 'edu_students';
  $deleted = $wpdb->delete($table, ['id' => $student_id]);
  if ($deleted) {
    wp_send_json_success();
  } else {
    wp_send_json_error('Nu s-a putut șterge elevul.');
  }
}

// Salvează modificările elevului
add_action('wp_ajax_edit_student', 'edit_student_callback');
function edit_student_callback() {
  global $wpdb;
  $id = intval($_POST['student_id']);
  $data = [
    'first_name'       => sanitize_text_field($_POST['first_name']),
    'last_name'        => sanitize_text_field($_POST['last_name']),
    'age'              => intval($_POST['age']),
    'gender'           => sanitize_text_field($_POST['gender']),
    'sit_abs'          => sanitize_text_field($_POST['sit_abs'] ?? ''),
    'frecventa'        => sanitize_text_field($_POST['frecventa'] ?? ''),
    'bursa'            => sanitize_text_field($_POST['bursa'] ?? ''),
    'dif_limba'        => sanitize_text_field($_POST['dif_limba'] ?? ''),
    'cauze_abs'        => sanitize_textarea_field($_POST['cauze_abs'] ?? ''),
    'risc_abandon'     => sanitize_text_field($_POST['risc_abandon'] ?? ''),
    'repeta_clasa'     => sanitize_text_field($_POST['repeta_clasa'] ?? ''),
    'observation'      => sanitize_text_field($_POST['observation'] ?? ''),
    'alte_obs'         => sanitize_textarea_field($_POST['alte_obs'] ?? ''),
    'demers_familie'   => sanitize_text_field($_POST['demers_familie'] ?? ''),
    'demers_conducere' => sanitize_text_field($_POST['demers_conducere'] ?? ''),
    'demers_consilier' => sanitize_text_field($_POST['demers_consilier'] ?? ''),
    'notes'            => sanitize_textarea_field($_POST['notes'] ?? ''),
  ];

  $updated = $wpdb->update("{$wpdb->prefix}edu_students", $data, ['id' => $id]);
  if ($updated !== false) {
    wp_send_json_success();
  } else {
    wp_send_json_error('Eroare la salvare.');
  }
}

/* =========================
 *  EDITARE ELEV — AJAX
 * ========================= */

add_action('wp_ajax_edu_get_student_edit_form', 'edu_get_student_edit_form');
add_action('wp_ajax_edu_update_student', 'edu_update_student');

function edu_current_professor_id() {
  if (!is_user_logged_in()) wp_send_json_error('Nu ești autentificat.');
  $u = wp_get_current_user();
  // profesor sau admin
  if (in_array('profesor', (array)$u->roles, true) || current_user_can('manage_options')) {
    return (int)$u->ID;
  }
  wp_send_json_error('Acces restricționat.');
}

function edu_db_tables() {
  global $wpdb;
  return [
    'students'    => $wpdb->prefix . 'edu_students',
    'generations' => $wpdb->prefix . 'edu_generations',
  ];
}

/**
 * Returnează HTML-ul casetei de editare pentru un elev.
 * Input: student_id (POST)
 * Output: { success, data: { html } }
 */
function edu_get_student_edit_form() {
  $prof_id = edu_current_professor_id();
  $student_id = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
  if ($student_id <= 0) wp_send_json_error('Elev invalid.');

  $t = edu_db_tables();
  global $wpdb;

  // Citim elevul + verificăm că aparține profesorului
  $student = $wpdb->get_row($wpdb->prepare("
    SELECT s.*
    FROM {$t['students']} s
    WHERE s.id = %d
    LIMIT 1
  ", $student_id));

  if (!$student) wp_send_json_error('Elev inexistent.');
  if ((int)$student->professor_id !== $prof_id && !current_user_can('manage_options')) {
    wp_send_json_error('Nu ai drepturi pentru acest elev.');
  }

  // Aducem level-ul generației pt opțiuni clasă (folosim classOptions din JS)
  $gen = $wpdb->get_row($wpdb->prepare("
    SELECT level FROM {$t['generations']} WHERE id = %d LIMIT 1
  ", (int)$student->generation_id));
  $level_code = $gen ? sanitize_text_field(strtolower($gen->level)) : '';

  // Helper pt value
  $v = function($x){ return esc_attr((string)$x); };

  // Observație -> show notes
  $obs = (string)$student->observation;
  $showNotes = $obs !== '' ? '' : 'display:none;';

  // HTML casetă (se inserează sub rândul elevului)
  ob_start(); ?>
  <td colspan="20" class="overflow-hidden bg-slate-300 edus-edit-wrap">
    <div class="flex items-center justify-between px-4 py-2 border-b bg-slate-800">
      <div class="text-sm font-medium text-white">
        Editează elevul: <span class="font-semibold text-white"><?php echo esc_html($student->first_name . ' ' . $student->last_name); ?></span>
      </div>
      <div class="flex items-center gap-2">
        <button type="button" class="edus-cancel-edit px-3 py-1.5 text-sm rounded-lg border border-slate-300 bg-white hover:bg-slate-100" data-id="<?php echo (int)$student->id; ?>">Anulează</button>
        <button type="button" class="edus-save-edit px-3 py-1.5 text-sm rounded-lg bg-emerald-600 text-white hover:bg-emerald-700" data-id="<?php echo (int)$student->id; ?>">Salvează</button>
      </div>
    </div>

    <form id="edus-edit-form-<?php echo (int)$student->id; ?>" class="grid grid-cols-2 gap-3 p-4 md:grid-cols-4" data-student-id="<?php echo (int)$student->id; ?>" data-level="<?php echo esc_attr($level_code); ?>">
      <input type="hidden" name="action" value="edu_update_student">
      <input type="hidden" name="student_id" value="<?php echo (int)$student->id; ?>">

      <label class="grid gap-1 text-sm">
        <span class="font-medium text-slate-700">Prenume</span>
        <input type="text" name="first_name" required class="edus-inp" value="<?php echo $v($student->first_name); ?>">
      </label>

      <label class="grid gap-1 text-sm">
        <span class="font-medium text-slate-700">Nume</span>
        <input type="text" name="last_name" required class="edus-inp" value="<?php echo $v($student->last_name); ?>">
      </label>

      <label class="grid gap-1 text-sm">
        <span class="font-medium text-slate-700">Vârstă *</span>
        <input type="number" name="age" min="1" required class="edus-inp" value="<?php echo $v($student->age); ?>">
      </label>

      <label class="grid gap-1 text-sm">
        <span class="font-medium text-slate-700">Gen *</span>
        <select name="gender" required class="edus-inp">
          <option value=""></option>
          <option value="M" <?php selected($student->gender==='M'); ?>>M</option>
          <option value="F" <?php selected($student->gender==='F'); ?>>F</option>
        </select>
      </label>

      <label class="grid gap-1 text-sm">
        <span class="font-medium text-slate-700">Clasa *</span>
        <select name="class_label" required class="edus-inp edus-class-select" data-current="<?php echo $v($student->class_label); ?>"></select>
      </label>

      <?php
      $sit_abs_val = (string)$student->sit_abs;
      $sit_abs_opts = [''=>'','Nu absentează deloc'=>'Nu absentează deloc','Absentează uneori/rar'=>'Absentează uneori/rar','Absentează des'=>'Absentează des','Absentează foarte des'=>'Absentează foarte des','Nu a venit niciodată'=>'Nu a venit niciodată'];
      $sit_abs_map = ['Deloc'=>'Nu absentează deloc','Uneori/Rar'=>'Absentează uneori/rar','Des'=>'Absentează des','Foarte Des'=>'Absentează foarte des'];
      if (isset($sit_abs_map[$sit_abs_val])) $sit_abs_val = $sit_abs_map[$sit_abs_val];
      $show_abs_extra = in_array($sit_abs_val, ['Absentează des','Absentează foarte des','Nu a venit niciodată']);
      $show_cauze     = ($sit_abs_val !== '' && $sit_abs_val !== 'Nu absentează deloc');
      ?>

      <label class="grid gap-1 text-sm">
        <span class="font-medium text-slate-700">Sit. absenteism</span>
        <select name="sit_abs" class="edus-inp sit-abs-select">
          <?php foreach ($sit_abs_opts as $val=>$lab) {
            printf('<option value="%s"%s>%s</option>', esc_attr($val), selected($sit_abs_val===$val, true, false), esc_html($lab));
          } ?>
        </select>
      </label>

      <label class="grid gap-1 text-sm">
        <span class="font-medium text-slate-700">Frecvență grădiniță</span>
        <select name="frecventa" class="edus-inp">
          <?php
          $opts = [''=>'','Nu'=>'Nu','Da (1an)'=>'Da (1an)','Da (2ani)'=>'Da (2ani)','Da (3ani)'=>'Da (3ani)','Da (4ani)'=>'Da (4ani)','Date indisponibile'=>'Date indisponibile'];
          foreach ($opts as $val=>$lab) {
            printf('<option value="%s"%s>%s</option>', esc_attr($val), selected((string)$student->frecventa===$val, true, false), esc_html($lab));
          }
          ?>
        </select>
      </label>

      <label class="grid gap-1 text-sm">
        <span class="font-medium text-slate-700">Bursă socială</span>
        <select name="bursa" class="edus-inp">
          <option value=""></option>
          <option value="Nu" <?php selected($student->bursa==='Nu'); ?>>Nu</option>
          <option value="Da" <?php selected($student->bursa==='Da'); ?>>Da</option>
        </select>
      </label>

      <label class="grid gap-1 text-sm">
        <span class="font-medium text-slate-700">Limba diferită</span>
        <select name="dif_limba" class="edus-inp">
          <option value=""></option>
          <option value="Nu" <?php selected($student->dif_limba==='Nu'); ?>>Nu</option>
          <option value="Da" <?php selected($student->dif_limba==='Da'); ?>>Da</option>
        </select>
      </label>

      <label class="grid gap-1 text-sm">
        <span class="font-medium text-slate-700">Repetă clasa</span>
        <select name="repeta_clasa" class="edus-inp">
          <option value=""></option>
          <option value="Da" <?php selected(($student->repeta_clasa ?? '')==='Da'); ?>>Da</option>
          <option value="Nu" <?php selected(($student->repeta_clasa ?? '')==='Nu'); ?>>Nu</option>
        </select>
      </label>

      <label class="grid gap-1 text-sm">
        <span class="font-medium text-slate-700">Observație</span>
        <select name="observation" class="edus-inp edus-observation">
          <option value=""></option>
          <option value="abandon" <?php selected($obs==='abandon'); ?>>Abandon</option>
          <option value="plecat_tara" <?php selected($obs==='plecat_tara'); ?>>A plecat din țară</option>
          <option value="transferat_din" <?php selected($obs==='transferat_din'); ?>>S-a transferat din clasă</option>
          <option value="transferat_in" <?php selected($obs==='transferat_in'); ?>>S-a transferat în clasă</option>
        </select>
      </label>

      <label class="grid gap-1 text-sm">
        <span class="font-medium text-slate-700">Alte observații</span>
        <textarea name="alte_obs" rows="2" class="edus-inp"><?php echo esc_textarea((string)($student->alte_obs ?? '')); ?></textarea>
      </label>

      <!-- Conditional fields: hidden by default -->
      <label class="grid gap-1 text-sm cauze-abs-cell" style="<?php echo $show_cauze ? '' : 'display:none;'; ?>">
        <span class="font-medium text-slate-700">Cauze absenteism</span>
        <textarea name="cauze_abs" rows="2" class="edus-inp"><?php echo esc_textarea((string)($student->cauze_abs ?? '')); ?></textarea>
      </label>

      <label class="grid gap-1 text-sm risc-abandon-cell<?php echo $show_abs_extra ? ' bg-rose-50 rounded-lg p-1' : ''; ?>" style="<?php echo $show_abs_extra ? '' : 'display:none;'; ?>">
        <span class="font-medium text-slate-700">Risc abandon</span>
        <select name="risc_abandon" class="edus-inp risc-abandon-select<?php echo $show_abs_extra ? ' !border-rose-400 !text-rose-700' : ''; ?>">
          <option value=""></option>
          <option value="Da" <?php selected(($student->risc_abandon ?? '')==='Da'); ?>>Da</option>
          <option value="Nu" <?php selected(($student->risc_abandon ?? '')==='Nu'); ?>>Nu</option>
        </select>
      </label>

      <label class="grid gap-1 text-sm edus-notes-wrap" style="<?php echo $obs !== '' ? '' : 'display:none;'; ?>">
        <span class="font-medium text-slate-700">Mențiuni</span>
        <textarea name="notes" rows="2" class="edus-inp"><?php echo esc_textarea((string)$student->notes); ?></textarea>
      </label>
    </form>

    <!-- Demersuri section -->
    <?php
    $demers_opts = [''=>'','Da'=>'Da','Nu'=>'Nu','Nu a fost cazul'=>'Nu a fost cazul'];
    $demers_fields_data = [
      'demers_familie'   => ['label' => 'Am discutat cu familia',                  'val' => (string)($student->demers_familie ?? '')],
      'demers_conducere' => ['label' => 'Am discutat cu conducerea școlii',        'val' => (string)($student->demers_conducere ?? '')],
      'demers_consilier' => ['label' => 'Am apelat la consilier / mediator școlar','val' => (string)($student->demers_consilier ?? '')],
    ];
    ?>
    <div class="px-3 pt-3 pb-2 mx-4 mt-0 mb-4 border-t rounded-lg demersuri-section border-amber-200 bg-amber-50/50" style="<?php echo $show_abs_extra ? '' : 'display:none;'; ?>"
         data-form-id="edus-edit-form-<?php echo (int)$student->id; ?>">
      <p class="mb-2 text-xs font-semibold text-amber-800">In caz de absenteism cronic, ce demersuri ați intreprins?</p>
      <div class="grid grid-cols-3 gap-2">
        <?php foreach ($demers_fields_data as $dfield => $dinfo): ?>
        <div class="demers-<?php echo str_replace('demers_','',$dfield); ?>-cell">
          <span class="text-xs font-medium text-slate-500"><?php echo esc_html($dinfo['label']); ?></span>
          <select name="<?php echo esc_attr($dfield); ?>" form="edus-edit-form-<?php echo (int)$student->id; ?>" class="edus-inp demers-select" style="margin-top:2px;">
            <?php foreach ($demers_opts as $dov=>$dol) {
              printf('<option value="%s"%s>%s</option>', esc_attr($dov), selected($dinfo['val']===$dov, true, false), esc_html($dol));
            } ?>
          </select>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </td>
  <style>
    .edus-inp{border:1px solid #cbd5e1;border-radius:.75rem;padding:.5rem .75rem;background:#fff;box-shadow:0 1px 1px rgba(0,0,0,.02)}
    .edus-edit-inline{display:none}
  </style>
  <?php
  $html = ob_get_clean();
  wp_send_json_success(['html' => $html]);
}

/**
 * Salvează modificările elevului.
 * Expectă câmpurile minimale din formular.
 */
function edu_update_student() {
  $prof_id = edu_current_professor_id();
  $student_id = isset($_POST['student_id']) ? (int) $_POST['student_id'] : 0;
  if ($student_id <= 0) wp_send_json_error('Elev invalid.');

  $t = edu_db_tables();
  global $wpdb;

  $student = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['students']} WHERE id=%d LIMIT 1", $student_id));
  if (!$student) wp_send_json_error('Elev inexistent.');
  if ((int)$student->professor_id !== $prof_id && !current_user_can('manage_options')) {
    wp_send_json_error('Nu ai drepturi pentru acest elev.');
  }

  // Sanitizare
  $fields = [
    'first_name'       => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'last_name'        => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'age'              => FILTER_SANITIZE_NUMBER_INT,
    'gender'           => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'class_label'      => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'sit_abs'          => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'frecventa'        => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'bursa'            => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'dif_limba'        => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'cauze_abs'        => FILTER_UNSAFE_RAW,
    'risc_abandon'     => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'repeta_clasa'     => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'observation'      => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'alte_obs'         => FILTER_UNSAFE_RAW,
    'demers_familie'   => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'demers_conducere' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'demers_consilier' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'notes'            => FILTER_UNSAFE_RAW,
  ];
  $in = [];
  foreach ($fields as $k=>$flt) {
    $val = isset($_POST[$k]) ? filter_var(wp_unslash($_POST[$k]), $flt) : '';
    $in[$k] = is_string($val) ? trim($val) : $val;
  }

  // Validări simple
  if ($in['first_name']==='' || $in['last_name']==='') {
    wp_send_json_error('Completează prenumele și numele.');
  }
  if ($in['age']!=='' && (int)$in['age'] < 1) $in['age'] = 1;

  // Build update data dynamically based on existing columns
  $existing_cols = array_flip($wpdb->get_col("SHOW COLUMNS FROM {$t['students']}"));

  $update_data = [];
  $update_fmt  = [];
  $col_map = [
    'first_name'       => ['val' => $in['first_name'],       'fmt' => '%s'],
    'last_name'        => ['val' => $in['last_name'],        'fmt' => '%s'],
    'age'              => ['val' => (int)$in['age'],         'fmt' => '%d'],
    'gender'           => ['val' => $in['gender'],           'fmt' => '%s'],
    'class_label'      => ['val' => $in['class_label'],      'fmt' => '%s'],
    'sit_abs'          => ['val' => $in['sit_abs'],          'fmt' => '%s'],
    'frecventa'        => ['val' => $in['frecventa'],        'fmt' => '%s'],
    'bursa'            => ['val' => $in['bursa'],            'fmt' => '%s'],
    'dif_limba'        => ['val' => $in['dif_limba'],        'fmt' => '%s'],
    'cauze_abs'        => ['val' => $in['cauze_abs'],        'fmt' => '%s'],
    'risc_abandon'     => ['val' => $in['risc_abandon'],     'fmt' => '%s'],
    'repeta_clasa'     => ['val' => $in['repeta_clasa'],     'fmt' => '%s'],
    'observation'      => ['val' => $in['observation'],      'fmt' => '%s'],
    'alte_obs'         => ['val' => $in['alte_obs'],         'fmt' => '%s'],
    'demers_familie'   => ['val' => $in['demers_familie'],   'fmt' => '%s'],
    'demers_conducere' => ['val' => $in['demers_conducere'], 'fmt' => '%s'],
    'demers_consilier' => ['val' => $in['demers_consilier'], 'fmt' => '%s'],
    'notes'            => ['val' => $in['notes'],            'fmt' => '%s'],
    'updated_at'       => ['val' => current_time('mysql'),   'fmt' => '%s'],
  ];
  foreach ($col_map as $col => $def) {
    if (isset($existing_cols[$col])) {
      $update_data[$col] = $def['val'];
      $update_fmt[]      = $def['fmt'];
    }
  }

  $updated = $wpdb->update(
    $t['students'],
    $update_data,
    [ 'id' => $student_id ],
    $update_fmt,
    [ '%d' ]
  );

  if ($updated === false) {
    wp_send_json_error('Nu s-au putut salva modificările.');
  }

  wp_send_json_success(['saved' => true, 'id' => $student_id]);
}


/**
 * Map SEL modul slug to the correct ACF field group key based on level suffix.
 * Slug format: sel-{stage}-{level}, e.g. sel-t0-primar-mic, sel-ti-gimnaziu
 */
function edus_sel_acf_group_key($modul_slug) {
  $level_groups = [
    'primar-mic'  => 'group_6890d7e176dbc',
    'primar-mare' => 'group_68950d737ff62',
    'prescolar'   => 'group_68950e502607b',
    'gimnaziu'    => 'group_68951177472d5',
    'gimnazial'   => 'group_68951177472d5',
  ];
  // Try longest suffix first to avoid "primar" matching before "primar-mic"
  foreach ($level_groups as $suffix => $key) {
    if (substr($modul_slug, -strlen($suffix)) === $suffix) {
      return $key;
    }
  }
  // Fallback to primar-mic
  return 'group_6890d7e176dbc';
}

add_action('wp_ajax_load_questionnaire_form', 'load_questionnaire_form_callback');
function load_questionnaire_form_callback() {
  $student_id = intval($_POST['student_id']);
  $modul_type   = sanitize_text_field($_POST['modul_type']);
  $modul_slug = sanitize_text_field($_POST['modul']);
  $acf_group_key = edus_sel_acf_group_key($modul_slug);

  global $wpdb;
  $table = $wpdb->prefix . 'edu_results';
  $existing = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table
     WHERE student_id = %d AND modul_type = %s AND modul = %s
     ORDER BY created_at DESC LIMIT 1",
    $student_id, $modul_type, $modul_slug
  ));

  $readonly = $existing && $existing->status === 'final';
  $values = $existing ? json_decode($existing->results, true) : [];

  $fields = acf_get_fields($acf_group_key);
  if (!$fields) {
    wp_send_json_success('<p class="text-red-500">❌ Nu s-au putut prelua câmpurile din ACF.</p>');
  }

  // Grupare pe secțiuni cunoscute
  $sections_order = [
    'constientizaredesine' => 'Conștientizare de sine',
    'autoreglare' => 'Autoreglare',
    'constientizaresociala' => 'Conștientizare socială',
    'relationare' => 'Relaționare',
    'luareadeciziilor' => 'Luarea deciziilor',
  ];

  $grouped_fields = [];
  foreach ($fields as $field) {
    if ($field['type'] === 'accordion') continue;
    foreach ($sections_order as $prefix => $label) {
      if (strpos($field['name'], $prefix . '_q') === 0) {
        $grouped_fields[$prefix][] = $field;
        break;
      }
    }
  }

  ob_start();

  echo '<div class="sticky top-0 z-50 flex items-center justify-between p-4 text-white bg-slate-800">';
    echo '<div class="flex items-center justify-between w-full gap-x-6">';
      //echo '<h2 id="questionnaireTitle" class="text-lg font-bold">Modul: ' . strtoupper($modul_type) . ' — ' . esc_html($modul_slug) . '</h2>';
      echo '<h2 id="questionnaireTitle" class="text-lg font-bold"><span class="">' . strtoupper($modul_type) . '</span> </h2>';
      echo '<span id="questionnaire-progress" class="text-sm text-white/80 mt-[5px] mr-[10px]">Completat: 0 din ' . count($fields) . '</span>';
    echo '</div>';
    echo '<button id="closeQuestionnaire" class="text-2xl font-semibold text-white">&times;</button>';
  echo '</div>';
  
  echo '<form id="questionnaireForm" data-student="' . esc_attr($student_id) . '" data-type="' . esc_attr($modul_type) . '" data-modul="' . esc_attr($modul_slug) . '">';
  echo '<div class="pb-20">'; // pentru spațiu sub butoane sticky
  foreach ($sections_order as $prefix => $section_title) {
    if (empty($grouped_fields[$prefix])) continue;
    // Afișează titlul secțiunii
    echo '<h3 class="sticky top-0 w-full px-4 py-3 mb-3 text-lg font-semibold tracking-wider text-center text-white uppercase bg-es-orange">' . esc_html($section_title) . '</h3>';
    $q = 1;
    foreach ($grouped_fields[$prefix] as $field) {
      $field_name = $field['name'];
      $field_label = $field['label'] ?? $field_name;
      $field_value = $values[$field_name] ?? '';
      $choices = $field['choices'] ?? [];

      echo '<div class="px-4 mb-6 question-wrapper">';
        echo '<label class="block mb-2 font-medium text-slate-800 question-label">' . $q . '. ' . esc_html($field['label']) . '</label>';
        echo '<div class="flex flex-wrap gap-2">';

      foreach ($choices as $key => $choice_label) {
        $is_checked = $key === $field_value;
        $selected_class = $is_checked ? 'selected' : '';
        echo '<label class="selectable-option ' . $selected_class . '">';
        echo '<input type="radio" name="' . esc_attr($field_name) . '" value="' . esc_attr($key) . '" class="hidden" ' . ($is_checked ? 'checked="checked"' : '') . ($readonly ? ' disabled' : '') . '>';
        echo '<span>' . esc_html($choice_label) . '</span>';
        echo '</label>';
      }

      echo '</div>';
      echo '</div>';
      $q++;
    }
  }

  echo '</div>';

  if (!$readonly) {
    echo '<div class="sticky bottom-0 left-0 right-0 z-50 flex items-center justify-center px-4 py-3 bg-slate-800">';
      echo '<div class="flex items-center justify-end gap-2 px-6">';
        echo '<button type="button" class="px-4 py-2 text-white border rounded bg-white/10 border-white/20 save-questionnaire hover:bg-white/80 hover:text-sky-800" data-status="draft">Salvează Temporar</button>';
        echo '<button type="button" class="flex items-center gap-2 px-4 py-2 text-white rounded bg-emerald-600 rounded-br-xl save-questionnaire hover:bg-emerald-700" data-status="final">';
        echo '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
        echo 'Salvează Permanent</button>';
      echo '</div>';
    echo '</div>';
  } else {
    echo '<p class="mt-6 text-sm text-gray-600">✔️ Acest chestionar a fost completat definitiv și nu mai poate fi modificat.</p>';
  }

  echo '</form>';

  wp_send_json_success(ob_get_clean());
    wp_die(); 
}

add_action('wp_ajax_save_questionnaire', 'save_questionnaire_callback');
function save_questionnaire_callback() {
  global $wpdb;

  $student_id   = intval($_POST['student_id']);
  $modul_type   = sanitize_text_field($_POST['modul_type']);
  $modul_slug   = sanitize_text_field($_POST['modul']); // Ex: sel-t0, sel-ti, sel-t1
  $modul_id     = 268; 
  $status       = $_POST['status'] === 'final' ? 'final' : 'draft';
  $professor_id = get_current_user_id();
  $class_id     = intval($_POST['class_id']);

  $excluded_keys = ['action', 'student_id', 'modul_type', 'status', 'class_id', 'modul'];
  $results = ['modul' => $modul_slug];

  foreach ($_POST as $key => $value) {
    if (!in_array($key, $excluded_keys)) {
      $results[$key] = sanitize_text_field($value);
    }
  }

  $acf_group_key = edus_sel_acf_group_key($modul_slug);
  $fields = acf_get_fields($acf_group_key);
  $total_questions = 0;
  $answered_questions = 0;

  foreach ($fields as $field) {
    if ($field['type'] === 'accordion') continue;
    $total_questions++;
    if (!empty($results[$field['name']])) $answered_questions++;
  }

  $completion_percent = $total_questions > 0 ? round(($answered_questions / $total_questions) * 100) : 0;

  // Calculează scor SEL pe capitole
  $score = null;
  if ($modul_type === 'sel') {
    $weights = [3, 2, 1];
    $sections_order = [
      'constientizaredesine'    => 'Conștientizare de sine',
      'autoreglare'             => 'Autoreglare',
      'constientizaresociala'   => 'Conștientizare socială',
      'relationare'             => 'Relaționare',
      'luareadeciziilor'        => 'Luarea deciziilor',
    ];
    $grouped_fields = [];

    foreach ($fields as $field) {
      if ($field['type'] === 'accordion') continue;
      foreach ($sections_order as $prefix => $label) {
        if (strpos($field['name'], $prefix . '_q') === 0) {
          $grouped_fields[$prefix][] = $field;
          break;
        }
      }
    }

    $chapter_scores = [];

    foreach ($sections_order as $prefix => $label) {
      if (empty($grouped_fields[$prefix])) continue;
      $sum = 0;
      $count = 0;
      foreach ($grouped_fields[$prefix] as $field) {
        $name = $field['name'];
        $user_value = $results[$name] ?? null;
        if ($user_value && !empty($field['choices'])) {
          $options = array_keys($field['choices']);
          $index = array_search($user_value, $options);
          if ($index !== false && isset($weights[$index])) {
            $sum += $weights[$index];
            $count++;
          }
        }
      }
      if ($count > 0) {
        $chapter_scores[$label] = round($sum / $count, 2);
      }
    }

    $score = maybe_serialize($chapter_scores);
  }

  // Șterge versiunile anterioare draft
  $wpdb->delete(
    $wpdb->prefix . 'edu_results',
    [
      'student_id' => $student_id,
      'modul_type' => $modul_type,
      'modul'      => $modul_slug,
      'status'     => 'draft',
    ]
  );

  // Salvează rezultatul
  $wpdb->insert(
    $wpdb->prefix . 'edu_results',
    [
      'student_id'    => $student_id,
      'modul_type'    => $modul_type,
      'modul_id'      => $modul_id,
      'modul'      => $modul_slug,
      'status'        => $status,
      'results'       => wp_json_encode($results),
      'created_at'    => current_time('mysql'),
      'professor_id'  => $professor_id,
      'class_id'      => $class_id,
      'score'         => $score,
      'completion'    => $completion_percent,
    ]
  );

  wp_send_json_success();
}

// Adaugă elevi într-o generație (nu mai folosim clasa)
//add_action('wp_ajax_add_students', 'edu_add_students_handler');
add_action('wp_ajax_edu_add_students', 'edu_add_students_handler');
function edu_add_students_handler() {
  if ( ! is_user_logged_in() ) {
    wp_send_json_error('Autentifică-te.');
  }

  global $wpdb;
  $students_table    = $wpdb->prefix . 'edu_students';
  $generations_table = $wpdb->prefix . 'edu_generations';
  $current_user_id   = get_current_user_id();

  // --- input ---
  $generation_id = intval($_POST['generation_id'] ?? 0);
  if (!$generation_id) {
    // compat vechi
    $generation_id = intval($_POST['class_id'] ?? 0);
  }
  if (!$generation_id) {
    wp_send_json_error('Lipsește ID generație.');
  }

  // class_id poate fi gol pe generații; îl tratăm separat
  $class_id_raw = isset($_POST['class_id']) ? trim((string)$_POST['class_id']) : '';
  $class_id     = ctype_digit($class_id_raw) ? (int)$class_id_raw : 0;
  if ($class_id <= 0) $class_id = 0;

  // --- ownership ---
  $owner = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT professor_id FROM {$generations_table} WHERE id = %d",
    $generation_id
  ));
  if ($owner !== $current_user_id && ! current_user_can('manage_options')) {
    wp_send_json_error('Nu ai permisiuni pentru această generație.');
  }

  $rows = $_POST['students'] ?? [];
  if ( ! is_array($rows) || empty($rows) ) {
    wp_send_json_error('Nu ai trimis elevi.');
  }

  $inserted = 0;
  $errors   = [];

  foreach ($rows as $row) {
    $first = sanitize_text_field($row['first_name'] ?? '');
    $last  = sanitize_text_field($row['last_name']  ?? '');
    if (!$first || !$last) { continue; }

    $class_label = sanitize_text_field($row['class_label'] ?? '');
    $age = max(1, min(20, intval($row['age'] ?? 0)));
    $gender      = sanitize_text_field($row['gender'] ?? '');
    $obs         = sanitize_text_field($row['observation'] ?? '');
    $sit_abs     = sanitize_text_field($row['sit_abs'] ?? '');
    $frec        = sanitize_text_field($row['frecventa'] ?? '');
    $bursa       = sanitize_text_field($row['bursa'] ?? '');
    $dif         = sanitize_text_field($row['dif_limba'] ?? '');
    $notes       = sanitize_textarea_field($row['notes'] ?? '');

    $sql = $wpdb->prepare(
      "INSERT INTO {$students_table}
        (generation_id, professor_id, class_id, class_label, first_name, last_name, age, gender, observation, sit_abs, frecventa, bursa, dif_limba, notes)
      VALUES
        (%d, %d, %d, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s)",
      $generation_id, $owner, $class_id,
      $class_label, $first, $last, $age, $gender, $obs, $sit_abs, $frec, $bursa, $dif, $notes
    );

    $ok = $wpdb->query($sql);

    if ($ok === false) {
      $errors[] = $wpdb->last_error ?: 'insert_failed';
    } else {
      $inserted++;
    }
  }

  if ($inserted > 0) {
    wp_send_json_success([ 'inserted' => $inserted ]);
  }

  // dacă nu s-a inserat niciun rând, întoarcem motivul
  wp_send_json_error([
    'message' => 'Nu s-a salvat niciun elev.',
    'errors'  => $errors,
  ]);
}


add_action('init', function () {
    add_rewrite_rule(
        '^panou/raport/elev/([0-9]+)/?$',
        'index.php?pagename=panou/raport-elev&student_id=$matches[1]',
        'top'
    );
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'student_id';
    return $vars;
});


// === Raport generație: /panou/raport/generatie/{id} ===
add_action('init', function () {
  // permite query var-ul
  add_rewrite_tag('%generation_id%', '([0-9]+)');

  // mapăm URL-ul pe o pagină WP cu slug-ul raport-generatie
  add_rewrite_rule(
    '^panou/raport/generatie/([0-9]+)/?$',
    'index.php?pagename=panou/raport-generatie&generation_id=$matches[1]',
    'top'
  );
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'generation_id';
    return $vars;
});

add_action('after_switch_theme', function () {
    flush_rewrite_rules();
});

add_action('admin_post_edustart_export_class_report', 'edustart_export_class_report');
add_action('admin_post_nopriv_edustart_export_class_report', 'edustart_export_class_report');

function edustart_export_class_report() {
    if (!isset($_GET['class_id'], $_GET['mod'], $_GET['format'])) {
        status_header(400);
        echo 'Parametri lipsă.'; exit;
    }
    global $wpdb;

    $class_id = intval($_GET['class_id']);
    $mod      = strtolower(sanitize_text_field($_GET['mod']));     // 'sel' | 'lit'
    $format   = strtolower(sanitize_text_field($_GET['format']));  // 'csv' | 'pdf'

    // Tabele
    $tbl_classes  = $wpdb->prefix.'edu_classes';
    $tbl_schools  = $wpdb->prefix.'edu_schools';
    $tbl_cities   = $wpdb->prefix.'edu_cities';
    $tbl_counties = $wpdb->prefix.'edu_counties';
    $tbl_students = $wpdb->prefix.'edu_students';
    $tbl_results  = $wpdb->prefix.'edu_results';

    // Meta clasă + profesor
    $class = $wpdb->get_row($wpdb->prepare("
      SELECT c.*, s.name AS school_name, ci.name AS city_name, co.name AS county_name
      FROM {$tbl_classes} c
      LEFT JOIN {$tbl_schools}  s  ON s.id = c.school_id
      LEFT JOIN {$tbl_cities}   ci ON ci.id = s.city_id
      LEFT JOIN {$tbl_counties} co ON co.id = ci.county_id
      WHERE c.id = %d
    ", $class_id));

    if (!$class) { status_header(404); echo 'Clasa nu a fost găsită.'; exit; }

    $teacher = '';
    if (!empty($class->teacher_id)) {
        $u = get_userdata(intval($class->teacher_id));
        if ($u) $teacher = $u->display_name;
    }
    if (!$teacher) $teacher = 'Profesor';

    // Elevi
    $students = $wpdb->get_results($wpdb->prepare("
      SELECT id, first_name, last_name FROM {$tbl_students}
      WHERE class_id = %d
      ORDER BY last_name, first_name
    ", $class_id));

    // Coloane results
    $cols = $wpdb->get_results("SHOW COLUMNS FROM {$tbl_results}");
    $colnames = array_map(fn($c)=>$c->Field,$cols);
    $has_updated_at = in_array('updated_at', $colnames, true);
    $has_created_at = in_array('created_at', $colnames, true);

    $selectCols = ['id','student_id','modul_type','modul','results','score','completion','status','class_id'];
    if ($has_updated_at) $selectCols[]='updated_at';
    if ($has_created_at) $selectCols[]='created_at';
    $selectColsSQL = implode(', ', $selectCols);

    // Toate rezultatele clasei
    $rowsRaw = $wpdb->get_results($wpdb->prepare("
      SELECT {$selectColsSQL}
      FROM {$tbl_results}
      WHERE class_id = %d
    ", $class_id));

    // Helpers comune
    if (!function_exists('edus_str_starts_with')){
        function edus_str_starts_with($hay,$needle){ return $needle==='' || strpos($hay,$needle)===0; }
    }
    if (!function_exists('edus_is_serialized')){
        function edus_is_serialized($data){
            if (!is_string($data)) return false;
            $data=trim($data);
            if ($data==='N;') return true;
            if (!preg_match('/^[adObis]:/',$data)) return false;
            return @unserialize($data)!==false || $data==='b:0;';
        }
    }
    $row_order_key = function($r) {
        if (!empty($r->updated_at)) return 't:'.strtotime($r->updated_at);
        if (!empty($r->created_at)) return 'c:'.strtotime($r->created_at);
        return 'i:'.intval($r->id ?? 0);
    };

    // Filtrare după tip
    if ($mod === 'sel') {
        $rows = array_values(array_filter($rowsRaw, function($r){
            $mt = strtolower(trim($r->modul_type ?? ''));
            $mm = strtolower(trim($r->modul ?? ''));
            return ($mt==='sel') || edus_str_starts_with($mm,'sel-');
        }));

        // Capitole SEL
        $CHAPS = ['Conștientizare de sine','Autoreglare','Conștientizare socială','Relaționare','Luarea deciziilor'];

        // Etapă din modul
        $stage_of = function($modul){
            $m=strtolower(trim($modul??''));
            if (edus_str_starts_with($m,'sel-t0')) return 'sel-t0';
            if (edus_str_starts_with($m,'sel-ti')) return 'sel-ti';
            if (edus_str_starts_with($m,'sel-t1')) return 'sel-t1';
            return null;
        };
        // Parse score map
        $parse_map = function($raw) use ($CHAPS){
            $map = array_fill_keys($CHAPS, null);
            if (edus_is_serialized($raw)) {
                $arr = @unserialize($raw);
                if (is_array($arr)) {
                    foreach ($CHAPS as $c) {
                        $map[$c] = (isset($arr[$c]) && is_numeric($arr[$c])) ? floatval($arr[$c]) : null;
                    }
                }
            }
            return $map;
        };

        // Cel mai nou pe fiecare etapă, per elev
        $perStage = [];
        foreach ($rows as $r){
            $st = $stage_of($r->modul);
            if (!$st) continue;
            $sid = intval($r->student_id);
            $k   = $row_order_key($r);
            if (!isset($perStage[$sid][$st]) || $k > $perStage[$sid][$st]['_k']) {
                $perStage[$sid][$st] = ['row'=>$r,'_k'=>$k];
            }
        }
        // Ultimul disponibil (T1→Ti→T0)
        $latestAny = [];
        foreach ($perStage as $sid=>$x) {
            foreach (['sel-t1','sel-ti','sel-t0'] as $pref) {
                if (!empty($x[$pref]['row'])) { $latestAny[$sid]=$x[$pref]['row']; break; }
            }
        }

        // === Output ===
        // Numele fișierului
        $fileBase = sprintf('Raport - SEL - %s - %s', $class->name ?? ('Clasa '.$class_id), $teacher);
        // curăță pentru sistemul de fișiere
        $safe = preg_replace('/[\/\\\\:*?"<>|]+/u','-', $fileBase);

        if ($format === 'csv') {
            // CSV: Elev, Capitol, T0, Ti, T1, Δ(Ti-T0), Δ(T1-Ti), Δ(T1-T0), Completare (ult.)
            $rowsCsv = [];
            $rowsCsv[] = ['Elev','Capitol','T0','Ti','T1','Δ(Ti-T0)','Δ(T1-Ti)','Δ(T1-T0)','Completare (ult.)'];

            foreach ($students as $s) {
                $sid  = intval($s->id);
                $name = trim(($s->last_name ?? '').' '.($s->first_name ?? ''));

                $map_t0 = $map_ti = $map_t1 = array_fill_keys($CHAPS, null);
                if (!empty($perStage[$sid]['sel-t0']['row'])) $map_t0 = $parse_map($perStage[$sid]['sel-t0']['row']->score);
                if (!empty($perStage[$sid]['sel-ti']['row'])) $map_ti = $parse_map($perStage[$sid]['sel-ti']['row']->score);
                if (!empty($perStage[$sid]['sel-t1']['row'])) $map_t1 = $parse_map($perStage[$sid]['sel-t1']['row']->score);
                $cmpl = isset($latestAny[$sid]) ? intval($latestAny[$sid]->completion) : null;

                foreach ($CHAPS as $cap) {
                    $t0=$map_t0[$cap]; $ti=$map_ti[$cap]; $t1=$map_t1[$cap];
                    $d_ti_t0 = ($ti!==null && $t0!==null) ? $ti-$t0 : null;
                    $d_t1_ti = ($t1!==null && $ti!==null) ? $t1-$ti : null;
                    $d_t1_t0 = ($t1!==null && $t0!==null) ? $t1-$t0 : null;
                    $rowsCsv[] = [$name, $cap, $t0, $ti, $t1, $d_ti_t0, $d_t1_ti, $d_t1_t0, $cmpl];
                }
            }

            // Headers pt. download + BOM UTF-8
            nocache_headers();
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="'.$safe.'.csv"');
            header('X-Content-Type-Options: nosniff');
            echo "\xEF\xBB\xBF"; // BOM pentru diacritice în Excel
            $out = fopen('php://output','w');
            foreach ($rowsCsv as $r) { fputcsv($out, $r); }
            fclose($out);
            exit;
        }

        if ($format === 'pdf') {
            // PDF (Dompdf)
            if (file_exists(ABSPATH.'vendor/autoload.php')) require_once ABSPATH.'vendor/autoload.php';
            if (!class_exists('\Dompdf\Dompdf')) {
                status_header(503);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Dompdf lipsă. Instalează o soluție de PDF (ex. plugin bazat pe Dompdf).';
                exit;
            }

            // Build HTML simplu
            ob_start(); ?>
            <style>
              body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
              table { border-collapse: collapse; width: 100%; }
              th, td { border: 1px solid #ddd; padding: 6px; }
              th { background: #f3f4f6; }
              .h1 { font-size: 18px; font-weight: 700; margin: 0 0 8px; }
              .muted { color: #555; margin-bottom: 8px; }
            </style>
            <div class="h1">Raport — SEL — <?= htmlspecialchars($class->name ?? '') ?> — <?= htmlspecialchars($teacher) ?></div>
            <div class="muted"><?= htmlspecialchars($class->school_name ?? '') ?>, <?= htmlspecialchars($class->city_name ?? '') ?>, <?= htmlspecialchars($class->county_name ?? '') ?></div>
            <table>
              <thead>
              <tr>
                <th>Elev</th><th>Capitol</th><th>T0</th><th>Ti</th><th>T1</th><th>Δ(Ti−T0)</th><th>Δ(T1−Ti)</th><th>Δ(T1−T0)</th><th>Completare(ult.)</th>
              </tr>
              </thead>
              <tbody>
              <?php foreach ($students as $s):
                $sid  = intval($s->id);
                $name = trim(($s->last_name ?? '').' '.($s->first_name ?? ''));
                $map_t0 = $map_ti = $map_t1 = array_fill_keys($CHAPS, null);
                if (!empty($perStage[$sid]['sel-t0']['row'])) $map_t0 = $parse_map($perStage[$sid]['sel-t0']['row']->score);
                if (!empty($perStage[$sid]['sel-ti']['row'])) $map_ti = $parse_map($perStage[$sid]['sel-ti']['row']->score);
                if (!empty($perStage[$sid]['sel-t1']['row'])) $map_t1 = $parse_map($perStage[$sid]['sel-t1']['row']->score);
                $cmpl = isset($latestAny[$sid]) ? intval($latestAny[$sid]->completion) : null;
                foreach ($CHAPS as $cap):
                    $t0=$map_t0[$cap]; $ti=$map_ti[$cap]; $t1=$map_t1[$cap];
                    $d_ti_t0 = ($ti!==null && $t0!==null) ? $ti-$t0 : null;
                    $d_t1_ti = ($t1!==null && $ti!==null) ? $t1-$ti : null;
                    $d_t1_t0 = ($t1!==null && $t0!==null) ? $t1-$t0 : null; ?>
                  <tr>
                    <td><?= htmlspecialchars($name) ?></td>
                    <td><?= htmlspecialchars($cap) ?></td>
                    <td><?= $t0!==null?number_format($t0,2):'—' ?></td>
                    <td><?= $ti!==null?number_format($ti,2):'—' ?></td>
                    <td><?= $t1!==null?number_format($t1,2):'—' ?></td>
                    <td><?= $d_ti_t0!==null?number_format($d_ti_t0,2):'—' ?></td>
                    <td><?= $d_t1_ti!==null?number_format($d_t1_ti,2):'—' ?></td>
                    <td><?= $d_t1_t0!==null?number_format($d_t1_t0,2):'—' ?></td>
                    <td><?= $cmpl!==null?($cmpl.'%'):'—' ?></td>
                  </tr>
                <?php endforeach; endforeach; ?>
              </tbody>
            </table>
            <?php
            $html = ob_get_clean();
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true,'defaultFont'=>'DejaVu Sans']);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            nocache_headers();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="'.$safe.'.pdf"');
            echo $dompdf->output();
            exit;
        }

        status_header(400); echo 'Format necunoscut.'; exit;
    }

    // ======== LIT ========
    if ($mod === 'lit') {
        $rows = array_values(array_filter($rowsRaw, function($r){
            $mt = strtolower(trim($r->modul_type ?? ''));
            $mm = strtolower(trim($r->modul ?? ''));
            return ($mt === 'literatie' || $mt === 'lit') || edus_str_starts_with($mm, 'literatie-');
        }));

        // Cel mai nou rând per elev (orice modul literație)
        $latest = [];
        foreach ($rows as $r){
            $sid = intval($r->student_id);
            $k   = $row_order_key($r);
            if (!isset($latest[$sid]) || $k > $latest[$sid]['_k']) $latest[$sid] = ['row'=>$r,'_k'=>$k];
        }
        $latestRows = [];
        foreach ($latest as $sid=>$wrap) $latestRows[$sid] = $wrap['row'];

        // Numele fișierului
        $fileBase = sprintf('Raport - Literație - %s - %s', $class->name ?? ('Clasa '.$class_id), $teacher);
        $safe = preg_replace('/[\/\\\\:*?"<>|]+/u','-', $fileBase);

        if ($format === 'csv') {
            $csv = [['Elev','Scor','Completare','Status','Modul']];
            foreach ($students as $s){
                $sid  = intval($s->id);
                $name = trim(($s->last_name ?? '').' '.($s->first_name ?? ''));
                if (isset($latestRows[$sid])) {
                    $r = $latestRows[$sid];
                    $score = is_numeric($r->score) ? floatval($r->score) : null;
                    $csv[] = [$name, $score, intval($r->completion), ($r->status ?? ''), ($r->modul ?? '')];
                } else {
                    $csv[] = [$name, null, null, null, null];
                }
            }
            nocache_headers();
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="'.$safe.'.csv"');
            header('X-Content-Type-Options: nosniff');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output','w');
            foreach ($csv as $r) fputcsv($out, $r);
            fclose($out);
            exit;
        }

        if ($format === 'pdf') {
            if (file_exists(ABSPATH.'vendor/autoload.php')) require_once ABSPATH.'vendor/autoload.php';
            if (!class_exists('\Dompdf\Dompdf')) {
                status_header(503);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Dompdf lipsă. Instalează o soluție de PDF (ex. plugin bazat pe Dompdf).';
                exit;
            }
            ob_start(); ?>
            <style>
              body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
              table { border-collapse: collapse; width: 100%; }
              th, td { border: 1px solid #ddd; padding: 6px; }
              th { background: #f3f4f6; }
              .h1 { font-size: 18px; font-weight: 700; margin: 0 0 8px; }
            </style>
            <div class="h1">Raport — Literație — <?= htmlspecialchars($class->name ?? '') ?> — <?= htmlspecialchars($teacher) ?></div>
            <table>
              <thead><tr><th>Elev</th><th>Scor</th><th>Completare</th><th>Status</th><th>Modul</th></tr></thead>
              <tbody>
              <?php foreach ($students as $s):
                $sid  = intval($s->id);
                $name = trim(($s->last_name ?? '').' '.($s->first_name ?? ''));
                if (isset($latestRows[$sid])) { $r=$latestRows[$sid]; ?>
                  <tr>
                    <td><?= htmlspecialchars($name) ?></td>
                    <td><?= is_numeric($r->score)?number_format(floatval($r->score),2):'—' ?></td>
                    <td><?= intval($r->completion) ?>%</td>
                    <td><?= htmlspecialchars($r->status ?? '—') ?></td>
                    <td><?= htmlspecialchars($r->modul ?? '—') ?></td>
                  </tr>
                <?php } else { ?>
                  <tr><td><?= htmlspecialchars($name) ?></td><td colspan="4">—</td></tr>
                <?php } endforeach; ?>
              </tbody>
            </table>
            <?php
            $html = ob_get_clean();
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true,'defaultFont'=>'DejaVu Sans']);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            nocache_headers();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="'.$safe.'.pdf"');
            echo $dompdf->output();
            exit;
        }

        status_header(400); echo 'Format necunoscut.'; exit;
    }

    status_header(400); echo 'Mod necunoscut.'; exit;
}








(function () {
    $base = get_template_directory() . '/lib/dompdf-2.0.4/dompdf';
    $autoload = $base . '/autoload.inc.php';
    $vendor   = $base . '/vendor/autoload.php';

    // debug: vezi în log exact ce se încarcă
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[EDU-PDF] dompdf base=' . $base);
        error_log('[EDU-PDF] autoload.inc.php exists=' . (is_file($autoload) ? '1' : '0'));
        error_log('[EDU-PDF] vendor/autoload.php exists=' . (is_file($vendor) ? '1' : '0'));
    }

    if (is_file($autoload)) {
        require_once $autoload;
    } else {
        add_action('admin_notices', function () use ($autoload) {
            echo '<div class="notice notice-error"><p><strong>Dompdf 2.0.4 autoload lipsește:</strong> '
               . esc_html($autoload)
               . '</p></div>';
        });
        return;
    }

    // fallback: dacă autoload.inc.php nu a încărcat încă vendor-ul, încearcă direct
    if (!class_exists(\Dompdf\Dompdf::class) && is_file($vendor)) {
        require_once $vendor;
    }

    // verificare finală
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[EDU-PDF] Dompdf loaded=' . (class_exists(\Dompdf\Dompdf::class) ? '1' : '0'));
    }
})();

// =================== EXPORT PDF — GENERAȚIE (SEL/LIT) ===================

use Dompdf\Dompdf;
use Dompdf\Options;

add_action('admin_post_edu_export_generation_pdf', 'edu_export_generation_pdf');
add_action('admin_post_nopriv_edu_export_generation_pdf', 'edu_export_generation_pdf');

/**
 * URL helper pentru buton:
 *  echo esc_url( edu_generation_pdf_url($generation_id, 'sel') );
 *  echo esc_url( edu_generation_pdf_url($generation_id, 'lit') );
 */
function edu_generation_pdf_url(int $generation_id, string $module): string {
    $module = strtolower($module) === 'lit' ? 'lit' : 'sel';
    $nonce  = wp_create_nonce('edu_export_generation_pdf_' . $generation_id . '_' . $module);
    return add_query_arg([
        'action'        => 'edu_export_generation_pdf',
        'module'        => $module,
        'generation_id' => $generation_id,
        '_wpnonce'      => $nonce,
    ], admin_url('admin-post.php'));
}

function edu_export_generation_pdf() {
    if (!is_user_logged_in()) {
        wp_die('Trebuie să fii autentificat pentru export.', 'Acces restricționat', 403);
    }

    $module        = isset($_GET['module']) ? strtolower(sanitize_text_field($_GET['module'])) : '';
    $generation_id = isset($_GET['generation_id']) ? intval($_GET['generation_id']) : 0;
    $nonce         = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';

    if (!in_array($module, ['sel', 'lit'], true)) {
        wp_die('Parametrul module trebuie să fie "sel" sau "lit".', 'Parametru invalid', 400);
    }
    if ($generation_id <= 0) {
        wp_die('Lipsește generation_id.', 'Parametru lipsă', 400);
    }
    if (!wp_verify_nonce($nonce, 'edu_export_generation_pdf_' . $generation_id . '_' . $module)) {
        wp_die('Cerere invalidă (nonce).', 'Securitate', 403);
    }
    if (!current_user_can('read')) {
        wp_die('Nu ai permisiunea să exporți acest raport.', 'Acces restricționat', 403);
    }

    // HTML pentru PDF
    $html = edu_render_generation_pdf_html($generation_id, $module);

    if (!class_exists(\Dompdf\Dompdf::class)) {
        wp_die('Dompdf nu este încărcat. Verifică /lib/dompdf-2.0.4/dompdf/autoload.inc.php.', 'Dompdf indisponibil', 500);
    }

    // DOMPDF 2.0.4 – I/O dirs (Windows safe)
    $uploads     = wp_get_upload_dir();
    $baseUploads = rtrim($uploads['basedir'] ?? (WP_CONTENT_DIR . '/uploads'), DIRECTORY_SEPARATOR);
    $temp_dir    = $baseUploads . '/dompdf_tmp';
    $font_cache  = $baseUploads . '/dompdf_font_cache';
    $log_file    = $baseUploads . '/dompdf_log.html';

    if (!function_exists('wp_mkdir_p')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    wp_mkdir_p($temp_dir);
    wp_mkdir_p($font_cache);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[EDU-PDF] using dompdf 2.0.4');
        error_log('[EDU-PDF] temp_dir=' . $temp_dir . ' exists=' . (is_dir($temp_dir) ? '1' : '0'));
        error_log('[EDU-PDF] font_cache=' . $font_cache . ' exists=' . (is_dir($font_cache) ? '1' : '0'));
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', ABSPATH);
    $options->set('tempDir', $temp_dir);
    $options->set('fontCache', $font_cache);
    $options->set('logOutputFile', $log_file);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    $filename = sprintf('Raport-Generatie-%d-%s.pdf', $generation_id, strtoupper($module));
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

/* Normalizează etapa din modul: ex. "SEL-T1-PRIMAR-MARE" -> "sel-t1" */
if (!function_exists('edu_normalize_sel_stage')) {
    function edu_normalize_sel_stage(?string $mod): string {
        if (!$mod) return '';
        $m = strtolower($mod);
        if (preg_match('/\bsel-(t0|ti|t1)\b/', $m, $mm)) return 'sel-' . $mm[1];
        if (preg_match('/\b(t0|ti|t1)\b/', $m, $mm))     return 'sel-' . $mm[1];
        if (preg_match('/^sel-(t0|ti|t1)-/', $m, $mm))    return 'sel-' . $mm[1];
        return '';
    }
}

function edu_render_generation_pdf_html(int $generation_id, string $module): string {
    $data = edu_collect_generation_data_for_pdf($generation_id, $module);

    // Variabile pentru template & helper — ca pe pagina web
    $GEN                     = $data['GEN'];
    $students                = $data['students'];
    $rowsRaw                 = $data['rowsRaw']; // stdClass
    $GLOBALS['student_name'] = $data['student_name_map'];

    // === PRE-PROCESARE: stdClass -> array + normalizare etapă (SEL) ===
    $rows = [];
    if (!empty($rowsRaw)) {
        foreach ($rowsRaw as $r) {
            $row = (array) $r; // tab-ul web lucrează cu array-uri

            if ($module === 'sel') {
                $stage = edu_normalize_sel_stage($row['modul'] ?? '');
                if ($stage !== '') {
                    $row['stage'] = $stage;   // "sel-t0|sel-ti|sel-t1"
                    $row['modul'] = $stage;   // compat maximă cu helperul SEL
                } else {
                    $row['stage'] = '';
                }
            }

            if (isset($row['completion']) && $row['completion'] !== null) {
                $row['completion'] = is_numeric($row['completion']) ? (float)$row['completion'] : null;
            }
            if (isset($row['score']) && $row['score'] !== null) {
                $row['score'] = is_numeric($row['score']) ? (float)$row['score'] : null;
            }
            $rows[] = $row;
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[EDU-PDF] rowsRaw=' . count($rowsRaw) . ' rows=' . count($rows) . ' module=' . $module);
        if (!empty($rows)) {
            $dbg = [
                'student_id' => $rows[0]['student_id'] ?? null,
                'modul'      => $rows[0]['modul'] ?? null,
                'stage'      => $rows[0]['stage'] ?? null,
            ];
            error_log('[EDU-PDF] firstRow=' . json_encode($dbg));
        }
    }

    $generation_id = (int)($GEN['id']); // unele taburi îl folosesc

    // ====== COMPAT SHIMS + BOOTSTRAP — DEFINITE ÎNAINTE DE INCLUDE-URI ======
    // Acceptă $row ca array SAU obiect (stdClass) — helperul tău le poate amesteca.
    if (!function_exists('row_order_key')) {
        function row_order_key($row) {
            $get = static function($k) use ($row) {
                if (is_array($row))  return $row[$k] ?? null;
                if (is_object($row)) return $row->$k ?? null;
                return null;
            };
            $mod = $get('modul'); if ($mod === null) $mod = $get('stage');
            $mod = is_string($mod) ? strtolower($mod) : '';
            $stageRankMap = ['sel-t0'=>0,'t0'=>0,'lit-t0'=>0,'sel-ti'=>1,'ti'=>1,'sel-t1'=>2,'t1'=>2,'lit-t1'=>2];
            $rank = $stageRankMap[$mod] ?? 9;
            $sid  = (int)($get('student_id') ?? 0);
            $ts   = 0; $created = $get('created_at');
            if ($created) { $t = @strtotime((string)$created); $ts = $t !== false ? (int)$t : 0; }
            return sprintf('%06d-%02d-%010d', $sid, $rank, $ts);
        }
    }
    if (!function_exists('row_order_cmp')) {
        function row_order_cmp($a, $b) {
            $ka = row_order_key($a); $kb = row_order_key($b);
            if ($ka === $kb) return 0; return ($ka < $kb) ? -1 : 1;
        }
    }
    if (!function_exists('badge_score_class')) {
        function badge_score_class($val) {
            if (!is_numeric($val)) return 'gray';
            $v=(float)$val; if ($v<1.5) return 'red'; if ($v<2.0) return 'orange'; if ($v<2.5) return 'yellow'; return 'green';
        }
    }

    // Bootstrap (fallback) pentru variabile globale așteptate în tab
    if (!isset($SEL_CHAPTERS)) {
        $SEL_CHAPTERS = [
            'cons_sine'      => 'Conștientizare de sine',
            'autoreglare'    => 'Autoreglare',
            'cons_sociala'   => 'Conștientizare socială',
            'relationare'    => 'Relaționare',
            'decizii'        => 'Luarea deciziilor',
        ];
    }
    if (!isset($sel_stage_overall_avg))     $sel_stage_overall_avg = [];  // e.g. ['t0'=>..., 'ti'=>..., 't1'=>...]
    if (!isset($sel_overall_allStages_avg)) $sel_overall_allStages_avg = null;

    ob_start();
    $theme_dir = get_template_directory();

    // === ORDIN CORECT pentru SEL: DOAR HELPER -> PDF (fără TAB web!) ===
    if ($module === 'sel') {
        $helper_sel = $theme_dir . '/dashboard/raport-generatie-helper-sel.php';
        if (file_exists($helper_sel)) require $helper_sel;
        require $theme_dir . '/dashboard/raport-generatie-pdf-sel.php';
    } else {
        $helper_lit = $theme_dir . '/dashboard/raport-generatie-helper-lit.php';
        if (file_exists($helper_lit)) require $helper_lit;
        require $theme_dir . '/dashboard/raport-generatie-pdf-lit.php';
    }

    return ob_get_clean();
}




function edu_collect_generation_data_for_pdf(int $generation_id, string $module): array {
    global $wpdb;

    // Mese conform schemei tale
    $tbl_generations = $wpdb->prefix . 'edu_generations';
    $tbl_students    = $wpdb->prefix . 'edu_students';
    $tbl_results     = $wpdb->prefix . 'edu_results';
    $tbl_schools     = $wpdb->prefix . 'edu_schools';
    $tbl_cities      = $wpdb->prefix . 'edu_cities';

    // 1) Generație
    $GEN = $wpdb->get_row(
        $wpdb->prepare("
            SELECT id, name, professor_id, level, class_label, class_labels_json, year, created_at
            FROM {$tbl_generations}
            WHERE id = %d
            LIMIT 1
        ", $generation_id),
        ARRAY_A
    );
    if (!$GEN) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[edu_export_pdf] Generația nu a fost găsită: id={$generation_id} în {$tbl_generations}");
        }
        wp_die('Generația nu a fost găsită.', 'Eroare', 404);
    }

    // 2) Profesor
    $GEN['professor_name'] = '—';
    if (!empty($GEN['professor_id'])) {
        $u = get_user_by('id', (int)$GEN['professor_id']);
        if ($u) $GEN['professor_name'] = $u->display_name ?: ($u->user_nicename ?: ('user#' . (int)$GEN['professor_id']));
    }

    // 3) Tutori din usermeta (assigned_tutor_id)
    $tutor_ids = edu_normalize_assigned_tutors((int)($GEN['professor_id'] ?? 0));
    $GEN['tutors_names'] = '—';
    if (!empty($tutor_ids)) {
        $names = [];
        foreach ($tutor_ids as $tid) {
            $tu = get_user_by('id', (int)$tid);
            $names[] = $tu ? ($tu->display_name ?: $tu->user_nicename ?: ('user#'.$tid)) : ('user#'.$tid);
        }
        $GEN['tutors_names'] = implode(', ', $names);
    }

    // 4) Școala (din generație dacă ai coloana school_id; altfel fallback din usermeta profesor)
    $school_id = null;
    $col_school_exists = $wpdb->get_var("SHOW COLUMNS FROM {$tbl_generations} LIKE 'school_id'");
    if ($col_school_exists) {
        $school_id = $wpdb->get_var($wpdb->prepare("SELECT school_id FROM {$tbl_generations} WHERE id=%d", $generation_id));
        if ($school_id !== null) $school_id = (int)$school_id;
    }
    if (empty($school_id) && !empty($GEN['professor_id'])) {
        foreach (['school_id','assigned_school_id','prof_school_id'] as $k) {
            $v = get_user_meta((int)$GEN['professor_id'], $k, true);
            if ($v && is_numeric($v)) { $school_id = (int)$v; break; }
        }
    }

    $GEN['school'] = [];
    $GEN['school_label'] = '—';
    if (!empty($school_id)) {
        $school = $wpdb->get_row(
            $wpdb->prepare("
                SELECT id, name, location, superior_location, county, city_id,
                       regiune_tfr, statut, medie_irse, scor_irse, mediu
                FROM {$tbl_schools}
                WHERE id = %d
            ", $school_id),
            ARRAY_A
        );
        if ($school) {
            $city_name = '—';
            if (!empty($school['city_id'])) {
                $city = $wpdb->get_row(
                    $wpdb->prepare("SELECT name FROM {$tbl_cities} WHERE id = %d", (int)$school['city_id']),
                    ARRAY_A
                );
                if ($city && !empty($city['name'])) $city_name = $city['name'];
            }
            $school['city_name'] = $city_name;
            $GEN['school'] = $school;

            $parts = [];
            if (!empty($school['name'])) $parts[] = $school['name'];
            $loc   = array_filter([$school['city_name'] ?? '', $school['county'] ?? '']);
            if (!empty($loc)) $parts[] = implode(', ', $loc);
            $GEN['school_label'] = !empty($parts) ? implode(' — ', $parts) : '—';
        }
    }

    // 5) Elevi
    $students = $wpdb->get_results(
        $wpdb->prepare("
            SELECT id, first_name, last_name, age, gender
            FROM {$tbl_students}
            WHERE generation_id = %d
            ORDER BY last_name, first_name
        ", $generation_id)
    );
    $GEN['total_students'] = $students ? count($students) : 0;

    $student_ids = $students ? array_map(fn($s)=>(int)$s->id, $students) : [];
    $student_name_map = [];
    if ($students) {
        foreach ($students as $s) {
            $student_name_map[(int)$s->id] = trim(($s->last_name ?? '') . ' ' . ($s->first_name ?? ''));
        }
    }

    // 6) Rezultate brute (SEL/LIT)
    $rowsRaw = [];
    if (!empty($student_ids)) {
        $ids = implode(',', array_map('intval', $student_ids));
        $rowsRaw = $wpdb->get_results("
            SELECT id, student_id, modul_type, modul, results, score, completion, status, created_at
            FROM {$tbl_results}
            WHERE modul_type = '" . esc_sql($module) . "'
              AND student_id IN ({$ids})
            ORDER BY created_at ASC
        ");
    }

    // Compat: unele template-uri folosesc level_type; îl populăm cu level
    $GEN['level_type'] = $GEN['level'];

    return [
        'GEN'              => $GEN,
        'students'         => $students ?: [],
        'rowsRaw'          => $rowsRaw ?: [],
        'student_name_map' => $student_name_map,
    ];
}

/**
 * Normalizează tutori din usermeta 'assigned_tutor_id' (single/multiple/CSV).
 */
function edu_normalize_assigned_tutors(int $prof_user_id): array {
    if ($prof_user_id <= 0) return [];

    $all = get_user_meta($prof_user_id, 'assigned_tutor_id', false); // toate valorile
    $ids = [];

    $push = static function (&$ids, $val) {
        if ($val === null || $val === '' || $val === false) return;
        if (is_numeric($val)) { $ids[] = (int)$val; return; }
        if (is_array($val))   { foreach ($val as $v) { if (is_numeric($v)) $ids[] = (int)$v; } return; }
        if (is_string($val)) {
            if (strpos($val, ',') !== false) {
                foreach (explode(',', $val) as $chunk) {
                    $chunk = trim($chunk);
                    if ($chunk !== '' && is_numeric($chunk)) $ids[] = (int)$chunk;
                }
                return;
            }
            if (preg_match_all('/\d+/', $val, $m)) {
                foreach ($m[0] as $num) $ids[] = (int)$num;
            }
        }
    };

    if (!empty($all)) {
        foreach ($all as $v) $push($ids, $v);
    } else {
        $single = get_user_meta($prof_user_id, 'assigned_tutor_id', true);
        $push($ids, $single);
    }

    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}




add_action('wp_ajax_get_questionnaire', 'edus_lit_get_questionnaire'); // folosit de prefill
function edus_lit_get_questionnaire() {
    if ( ! is_user_logged_in() ) wp_send_json_error('Unauthenticated.');

    global $wpdb;
    $student_id = intval($_POST['student_id'] ?? 0);
    $modul      = sanitize_text_field($_POST['modul'] ?? '');

    if ($student_id <= 0 || $modul === '') {
        wp_send_json_success(['results'=>[], 'status'=>null]); // gol, dar success
    }

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT results, status
         FROM {$wpdb->prefix}edu_results
         WHERE student_id = %d AND modul = %s AND modul_type IN ('lit','literatie')
         ORDER BY created_at DESC, id DESC
         LIMIT 1",
        $student_id, $modul
    ));

    $results = $row ? (json_decode($row->results ?? '{}', true) ?: []) : [];
    $status  = $row->status ?? null;

    wp_send_json_success(['results'=>$results, 'status'=>$status]);
}


add_action('wp_ajax_get_lit_progress', 'edus_lit_get_progress'); // folosit de coloana „Evaluare Literație”
function edus_lit_get_progress() {
    if ( ! is_user_logged_in() ) wp_send_json_error('Unauthenticated.');

    global $wpdb;
    $student_id = intval($_POST['student_id'] ?? 0);
    $modul      = sanitize_text_field($_POST['modul'] ?? '');

    if ($student_id <= 0 || $modul === '') {
        wp_send_json_success(['percent'=>0]);
    }

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT completion
         FROM {$wpdb->prefix}edu_results
         WHERE student_id = %d AND modul = %s AND modul_type IN ('lit','literatie')
         ORDER BY created_at DESC, id DESC
         LIMIT 1",
        $student_id, $modul
    ));

    wp_send_json_success(['percent' => $row ? intval($row->completion) : 0]);
}


add_action('wp_ajax_save_questionnaire_lit', 'edu_save_questionnaire_lit');
function edu_save_questionnaire_lit() {
  if ( ! is_user_logged_in() ) wp_send_json_error('Autentifică-te.');

  global $wpdb;
  $results_table   = $wpdb->prefix . 'edu_results';
  $students_table  = $wpdb->prefix . 'edu_students';
  $generations_tbl = $wpdb->prefix . 'edu_generations';
  $uid = get_current_user_id();

  $student_id = intval($_POST['student_id'] ?? 0);
  $modul_type = sanitize_text_field($_POST['modul_type'] ?? 'lit');
  $modul      = sanitize_text_field($_POST['modul'] ?? '');
  $status     = sanitize_text_field($_POST['status'] ?? 'draft'); // draft|final
  $completion = intval($_POST['completion'] ?? 0);

  if (!$student_id || $modul_type !== 'lit' || !$modul) {
    wp_send_json_error('Date insuficiente.');
  }

  // --- Helpers locale ----------------------------------------------------
  $normalize_class_label = function($label) {
    $s = mb_strtolower(trim((string)$label));
    $s = str_replace(['â','ă','î','ș','ţ','ț'],['a','a','i','s','t','t'],$s);
    return $s;
  };
  $grade_from_classlabel = function($label) use ($normalize_class_label) {
    $s = $normalize_class_label($label);
    // pregatitoare/preparator => 0
    if (strpos($s,'pregatitoare') !== false || strpos($s,'preparator') !== false) return 0;
    // preșcolar -> nu are clasă (tratăm ca 0)
    if (strpos($s,'prescolar') !== false || strpos($s,'gradinita') !== false) return 0;
    if (preg_match('/\b([0-9])\b/', $s, $m)) {
      $n = intval($m[1]);
      if ($n >= 0 && $n <= 8) return $n;
    }
    return 0;
  };
  $scheme_from_context = function($label, $modul) use ($normalize_class_label) {
    $s = $normalize_class_label($label);
    $m = $normalize_class_label($modul);
    if (strpos($m,'prescolar')      !== false || strpos($s,'prescolar')      !== false) return 'prescolar';
    if (strpos($m,'pregatitoare')   !== false || strpos($s,'pregatitoare')   !== false || strpos($s,'preparator') !== false) return 'pregatitoare';
    // altfel: primar/gimnaziu
    return 'primar_gimnaziu';
  };
  $level_from_P_P_or_1_4 = function($v) { // PP => -1, P => 0, 1..4 => int
    $v = strtoupper(trim((string)$v));
    if ($v === 'PP') return -1;
    if ($v === 'P')  return 0;
    if (is_numeric($v)) {
      $n = intval($v);
      if ($n >= -1 && $n <= 8) return $n;
    }
    return null;
  };
  $array_key_by_name = function($breakdown) {
    $out = [];
    foreach ($breakdown as $it) {
      if (!empty($it['name'])) $out[$it['name']] = $it;
    }
    return $out;
  };

  // Ownership (profesorul curent)
  $student = $wpdb->get_row($wpdb->prepare(
    "SELECT s.*, g.professor_id 
     FROM {$students_table} s 
     LEFT JOIN {$generations_tbl} g ON g.id = s.generation_id
     WHERE s.id = %d", $student_id
  ));
  if (!$student) wp_send_json_error('Elev inexistent.');
  if ((int)$student->professor_id !== $uid && ! current_user_can('manage_options')) {
    wp_send_json_error('Nu ai permisiuni.');
  }

  // results: începem CU 'modul' PRIMUL, apoi toate lit_q*
  $results = ['modul' => $modul];
  foreach ($_POST as $k => $v) {
    if (preg_match('/^lit_q\d+$/', $k)) {
      $results[$k] = is_array($v) ? sanitize_text_field(reset($v)) : sanitize_text_field($v);
    }
  }

  // Score primit din UI (îl folosim DOAR ca fallback; recalculăm server-side)
  $score_breakdown = json_decode(stripslashes($_POST['score_breakdown'] ?? '[]'), true);
  if (!is_array($score_breakdown)) $score_breakdown = [];
  $score_meta      = json_decode(stripslashes($_POST['score_meta'] ?? '{}'), true);
  if (!is_array($score_meta)) $score_meta = [];

  // ---- Derivări pentru LIT ----------------------------------------------
  $class_label   = $student->class_label ?? '';
  $class_value   = $grade_from_classlabel($class_label);             // 0..8
  $scheme_key    = $scheme_from_context($class_label, $modul);       // prescolar | pregatitoare | primar_gimnaziu
  $bd_by_name    = $array_key_by_name($score_breakdown);

  // valorile brute pentru q2 (Acuratețe citire) și q4 (Comprehensiune citire)
  $acc_raw  = $results['lit_q2'] ?? ($bd_by_name['lit_q2']['value'] ?? null);
  $comp_raw = $results['lit_q4'] ?? ($bd_by_name['lit_q4']['value'] ?? null);

  $acc_lvl  = $level_from_P_P_or_1_4($acc_raw);
  $comp_lvl = $level_from_P_P_or_1_4($comp_raw);

  $dif_acc  = ($acc_lvl  !== null) ? ($acc_lvl  - $class_value) : null;
  $dif_comp = ($comp_lvl !== null) ? ($comp_lvl - $class_value) : null;

  // Remedial: doar pentru primar/gimnaziu, când Acuratețe ∈ {P, PP}
  $is_remedial = false;
  if ($scheme_key === 'primar_gimnaziu') {
    $acc_upper = strtoupper(trim((string)$acc_raw));
    if ($acc_upper === 'P' || $acc_upper === 'PP') $is_remedial = true;
  }

  // ---- Recalculare TOTAL pe puncte --------------------------------------
  // IMPORTANT: ignorăm score_total din POST și calculăm corect aici.
  // Logica:
  //  - prescolar           => sumăm TOATE itemele numerice (din breakdown)
  //  - pregatitoare        => sumăm TOATE itemele numerice (din breakdown)
  //  - primar_gimnaziu     => sumăm NUMAI itemele condiționate de PP la Acuratețe
  //                           (preset: lit_q7..lit_q12). Dacă nu sunt prezente, total=0.
  $numericFrom = function($it) {
    // dacă item-ul din breakdown are max numeric => e item cu punctaj
    if (isset($it['max']) && is_numeric($it['max'])) {
      $v = is_numeric($it['value'] ?? null) ? floatval($it['value']) : 0;
      return $v;
    }
    // altfel, dacă value e numeric (rar), îl luăm
    if (is_numeric($it['value'] ?? null)) return floatval($it['value']);
    return 0;
  };

  $conditional_keys = ['lit_q7','lit_q8','lit_q9','lit_q10','lit_q11','lit_q12']; // ajustabil dacă în UI ai altele

  $score_total = 0;
  if ($scheme_key === 'prescolar' || $scheme_key === 'pregatitoare') {
    foreach ($score_breakdown as $it) $score_total += $numericFrom($it);
  } else { // primar_gimnaziu
    // „doar pe acelea condiționate” — în practică: q7..q12 (vizibile când Acuratețe=PP)
    foreach ($conditional_keys as $ck) {
      if (!empty($bd_by_name[$ck])) {
        $score_total += $numericFrom($bd_by_name[$ck]);
      }
    }
  }

  // total maxim util (pt referință în meta)
  $total_max = ($scheme_key === 'prescolar') ? 51 : 150;

  // ---- Construim score-ul ce va fi salvat -------------------------------
  $score_arr = [
    // 1) Semnalizare Remedial
    'remedial'   => $is_remedial ? 1 : 0,

    // 2) Diferențele față de clasă (salvate direct)
    'dif_clasa'  => [
      'acuratete'      => $dif_acc,
      'comprehensiune' => $dif_comp,
      'class_value'    => $class_value,   // util la raportări
    ],

    // 3) Total recalculat corect
    'total'      => (int) round($score_total),

    // pentru compatibilitate UI & raportare
    'breakdown'  => $score_breakdown,
    'meta'       => array_merge($score_meta, [
                      'module'     => $modul,
                      'scheme'     => $scheme_key,
                      'total_max'  => $total_max,
                    ]),
  ];
  $score_serialized = maybe_serialize($score_arr);

  // Șterge draft-urile anterioare pentru același student + modul (exact ca la SEL)
  $wpdb->delete($results_table, [
    'student_id' => $student_id,
    'modul_type' => 'lit',
    'modul'      => $modul,
    'status'     => 'draft',
  ]);

  // Insert
  $ok = $wpdb->insert($results_table, [
    'student_id'    => $student_id,
    'professor_id'  => $uid,
    'modul_type'    => 'lit',
    'modul'         => $modul,
    'status'        => $status,
    'completion'    => $completion,
    'results'       => wp_json_encode($results, JSON_UNESCAPED_UNICODE),
    'score'         => $score_serialized,
    'created_at'    => current_time('mysql'),
  ], [
    '%d','%d','%s','%s','%s','%d','%s','%s','%s'
  ]);

  if (!$ok) wp_send_json_error('DB error la salvare: '.$wpdb->last_error);
  wp_send_json_success(['id' => $wpdb->insert_id, 'completion' => $completion]);
}



add_action('wp_ajax_edu_rename_generation', function(){
    if (!is_user_logged_in()) wp_send_json_error('Nu ești autentificat.');
    $uid = get_current_user_id();
    $gen_id = isset($_POST['generation_id']) ? intval($_POST['generation_id']) : 0;
    $name   = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';

    if (!$gen_id) wp_send_json_error('ID invalid.');
    global $wpdb;
    $table = $wpdb->prefix.'edu_generations';
    // verificăm ownership
    $owner = (int)$wpdb->get_var($wpdb->prepare("SELECT professor_id FROM {$table} WHERE id=%d", $gen_id));
    if ($owner !== $uid && !current_user_can('manage_options')) wp_send_json_error('Nu ai permisiuni.');

    $wpdb->update($table, ['name'=>$name], ['id'=>$gen_id]);
    wp_send_json_success(true);
});





if (!function_exists('es_user_is_profesor')) {
  function es_user_is_profesor(): bool {
    if (!is_user_logged_in()) return false;
    $u = wp_get_current_user();
    return in_array('profesor', (array)$u->roles, true);
  }
}

add_action('wp_enqueue_scripts', function () {
  if (!is_user_logged_in()) return;

  wp_enqueue_script(
    'edustart-header',
    get_stylesheet_directory_uri() . '/js/edustart-header.js',
    ['jquery'],
    '1.1.0',
    true
  );

  wp_enqueue_script(
    'edus-tooltips',
    get_stylesheet_directory_uri() . '/js/edus-tooltips.js',
    [],
    '1.0.0',
    true // în footer
  );

  $report_base = home_url('/panou/raport/elev/');

  wp_localize_script('edustart-header', 'ES_HEADER', [
    'ajax'        => admin_url('admin-ajax.php'),
    'nonce'       => wp_create_nonce('es_header_nonce'),
    'reportBase'  => trailingslashit($report_base),
  ]);
});

/**
 * CONFIG: nume tabel și coloane (ajustează dacă ai altă schemă!)
 */
function es_students_table_name(): string {
  global $wpdb;
  return $wpdb->prefix . 'edu_students'; // ex: wp_edu_students
}
function es_students_columns(): array {
  return [
    'id'           => 'id',
    'first_name'   => 'first_name',
    'last_name'    => 'last_name',
    'professor_id' => 'professor_id',
    'class_label'  => 'class_label',
  ];
}

/**
 * Util: verifică dacă tabela există
 */
function es_table_exists(string $table): bool {
  global $wpdb;
  $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
  return (strtolower($found ?? '') === strtolower($table));
}

/**
 * === AJAX: căutare elevi ===
 */
add_action('wp_ajax_es_student_search', function () {
  check_ajax_referer('es_header_nonce', 'nonce');

  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'Autentifică-te.'], 401);
  }

  $current   = wp_get_current_user();
  $roles     = (array) $current->roles;
  $is_admin  = current_user_can('manage_options');
  $is_prof   = in_array('profesor', $roles, true);
  $is_tutor  = in_array('tutor', $roles, true);

  if (!$is_admin && !$is_prof && !$is_tutor) {
    wp_send_json_error(['message' => 'Nu ai permisiuni.'], 403);
  }

  $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
  if ($q === '') {
    wp_send_json_success(['items' => []]);
  }

  global $wpdb;

  $table = es_students_table_name();
  $cols  = es_students_columns();

  if (!es_table_exists($table)) {
    wp_send_json_error([
      'message' => "Tabela pentru elevi nu există: {$table}. Ajustează es_students_table_name()."
    ], 500);
  }

  // LIKE pregătit
  $like = '%' . $wpdb->esc_like($q) . '%';

  // === Scope pe profesor(i) în funcție de rol ===
  $where_prof = '';
  $params     = [];

  if ($is_prof && !$is_admin) {
    // Profesor: doar propriii elevi
    $where_prof = " AND {$cols['professor_id']} = %d";
    $params[]   = (int) $current->ID;

  } elseif ($is_tutor && !$is_admin) {
    // Tutor: elevii profesorilor gestionați de el (assigned_tutor_id = tutor_id)
    $uq = new WP_User_Query([
      'role'       => 'profesor',
      'fields'     => 'ID',
      'number'     => -1,
      'meta_query' => [[
        'key'     => 'assigned_tutor_id',
        'value'   => (int) $current->ID,
        'compare' => '=',
      ]],
    ]);
    $prof_ids = array_map('intval', (array) $uq->get_results());

    if (empty($prof_ids)) {
      wp_send_json_success(['items' => []]); // niciun profesor sub acest tutor
    }

    $ph          = implode(',', array_fill(0, count($prof_ids), '%d'));
    $where_prof  = " AND {$cols['professor_id']} IN ($ph)";
    $params      = array_merge($params, $prof_ids);

    // (opțional) dacă vrei să limitezi și pe generații, aici poți adăuga AND {$cols['generation_id']} IN (...)

  } else {
    // Admin: fără restricție pe profesor
    // (poți accepta opțional un GET['prof_id'] ca să restrângi, dar nu e necesar)
  }

  // === Interogare ===
  $sql = "
    SELECT
      {$cols['id']}          AS id,
      {$cols['first_name']}  AS first_name,
      {$cols['last_name']}   AS last_name,
      {$cols['class_label']} AS class_label
    FROM {$table}
    WHERE 1=1
      {$where_prof}
      AND (
           {$cols['first_name']} LIKE %s
        OR {$cols['last_name']}  LIKE %s
        OR CONCAT({$cols['first_name']}, ' ', {$cols['last_name']}) LIKE %s
      )
    ORDER BY {$cols['last_name']} ASC, {$cols['first_name']} ASC
    LIMIT 12
  ";

  // adaugăm parametrii LIKE la final
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;

  $prepared = $wpdb->prepare($sql, $params);
  $rows     = $wpdb->get_results($prepared);

  if ($wpdb->last_error) {
    wp_send_json_error([
      'message' => 'Eroare DB la căutare elevi.',
      'debug'   => $wpdb->last_error,
    ], 500);
  }

  $items = array_map(function($r){
    return [
      'id'    => (int) $r->id,
      'name'  => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')),
      'class' => $r->class_label ?? '',
    ];
  }, $rows ?? []);

  wp_send_json_success(['items' => $items]);
});


/**
 * === Notificări (CPT: notificare, tax: grup-notificari) ===
 * "Beculeț" pe existență de notificări noi față de meta-ul userului: notifications_last_seen (timestamp).
 * Filtrăm după term: 'Profesor' sau 'General' (slug-uri recomandate: 'profesor', 'general')
 */
/**
 * Găsește numele corect al taxonomiei (grup-notificari vs grup_notificari).
 */
function es_notif_tax_name(): ?string {
  $candidates = ['grup-notificari', 'grup_notificari'];
  foreach ($candidates as $tx) {
    if (taxonomy_exists($tx)) return $tx;
  }
  return null; // nu există taxonomia
}

/**
 * Returnează array de term IDs pentru slugs date, în taxonomia detectată.
 * Dacă nu găsește termeni, întoarce array gol.
 */
function es_notif_term_ids(array $slugs): array {
  $tax = es_notif_tax_name();
  if (!$tax) return [];
  $terms = get_terms([
    'taxonomy'   => $tax,
    'hide_empty' => false,
    'slug'       => $slugs,
    'fields'     => 'ids',
  ]);
  if (is_wp_error($terms) || empty($terms)) return [];
  return $terms;
}

/**
 * Returnează termenii eligibili pentru userul curent, ca slugs.
 */
function es_notif_role_slugs_for_current_user(): array {
  $slugs = ['general'];
  $u = wp_get_current_user();
  $roles = (array) ($u->roles ?? []);
  if (in_array('profesor', $roles, true)) $slugs[] = 'profesor';
  // poți adăuga: if (in_array('tutor', $roles, true)) $slugs[] = 'tutore';
  return $slugs;
}

/**
 * Construcție WP_Query args pentru notificări, cu fallback dacă nu există taxonomie/termeni.
 */
function es_notif_query_args_base(array $extra = []): array {
  $args = wp_parse_args($extra, [
    'post_type'      => 'notificare',
    'post_status'    => 'publish',
    'orderby'        => 'date',
    'order'          => 'DESC',
  ]);

  $tax = es_notif_tax_name();
  if ($tax) {
    $slugs   = es_notif_role_slugs_for_current_user();
    $term_ids = es_notif_term_ids($slugs);
    if (!empty($term_ids)) {
      $args['tax_query'] = [[
        'taxonomy' => $tax,
        'field'    => 'term_id',
        'terms'    => $term_ids,
      ]];
    } // dacă nu găsim termeni → NU punem tax_query (fallback: arătăm toate notificările)
  }

  return $args;
}

/** Beculeț: există notificări noi? */
function es_has_new_notifications_for_current_user(): bool {
  if (!is_user_logged_in()) return false;

  $u = wp_get_current_user();
  $last_seen = (int) get_user_meta($u->ID, 'notifications_last_seen', true);
  $now = current_time('timestamp', true);

  $after = $last_seen
    ? gmdate('Y-m-d H:i:s', $last_seen)
    : gmdate('Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS);

  $args = es_notif_query_args_base([
    'posts_per_page' => 1,
    'date_query'     => [[
      'after'     => $after,
      'column'    => 'post_date_gmt',
      'inclusive' => true,
    ]],
    'fields' => 'ids',
    'no_found_rows' => true,
  ]);

  $q = new WP_Query($args);
  return !empty($q->posts);
}

add_action('wp_ajax_es_notifications_ping', function () {
  check_ajax_referer('es_header_nonce', 'nonce');
  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'Nu ești logat.'], 401);
  }

  $u = wp_get_current_user();
  $last_seen = (int) get_user_meta($u->ID, 'notifications_last_seen', true);

  // 1) Luăm cel mai nou post eligibil (după rol/taxonomie)
  $args_latest = es_notif_query_args_base([
    'posts_per_page' => 1,
    'fields'         => 'ids',
    'no_found_rows'  => true,
  ]);
  $q_latest = new WP_Query($args_latest);
  $latest_ts = 0;
  if (!empty($q_latest->posts)) {
    $latest_ts = (int) get_post_time('U', true, $q_latest->posts[0]); // GMT
  }

  // 2) Count scurt (max 9) doar pentru UI (balonaș)
  $args_count = es_notif_query_args_base([
    'posts_per_page' => 9,
    'fields'         => 'ids',
    'no_found_rows'  => true,
  ]);
  $q_count = new WP_Query($args_count);

  // E "nouă" dacă există ceva publicat DUPĂ last_seen
  $has_new = ($latest_ts > $last_seen);

  // dacă nu ai deschis niciodată (last_seen = 0) dar există notificări,
  // aprindem becul ca să fie evident
  if ($last_seen === 0 && $latest_ts > 0) {
    $has_new = true;
  }

  // câte sunt "noi" (pentru cifra mică)
  $new_count = 0;
  if ($q_count->posts) {
    foreach ($q_count->posts as $pid) {
      $ts = (int) get_post_time('U', true, $pid);
      if ($ts > $last_seen) $new_count++;
    }
  }

  wp_send_json_success([
    'hasNew'      => $has_new,
    'newCount'    => $new_count,
    'latest_ts'   => $latest_ts,      // util la debug
    'last_seen'   => $last_seen,      // util la debug
  ]);
});


add_action('wp_ajax_es_notifications_list', function () {
  check_ajax_referer('es_header_nonce', 'nonce');
  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'Nu ești logat.'], 401);
  }

  $args = es_notif_query_args_base([
    'posts_per_page' => 20,
  ]);

  $q = new WP_Query($args);
  $items = [];
  if ($q->have_posts()) {
    while ($q->have_posts()) {
      $q->the_post();
      $pid   = get_the_ID();
      $title = get_the_title();
      $body  = get_field('corp', $pid);
      $date  = get_the_date('d.m.Y H:i');

      $items[] = [
        'id'    => $pid,
        'title' => $title,
        'body'  => wpautop(wp_kses_post($body ?: '')),
        'date'  => $date,
      ];
    }
    wp_reset_postdata();
  }

  wp_send_json_success(['items' => $items]);
});

add_action('wp_ajax_es_notifications_mark_seen', function () {
  check_ajax_referer('es_header_nonce', 'nonce');
  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'Nu ești logat.'], 401);
  }
  update_user_meta(get_current_user_id(), 'notifications_last_seen', current_time('timestamp', true));
  wp_send_json_success(['ok' => true]);
});


add_action('init', function () {
  add_rewrite_rule(
    '^panou/generatia/([0-9]+)/?$',
    'index.php?pagename=panou/generatia&gen=$matches[1]',
    'top'
  );
});

// Acceptă variabilele în query (opțional 'prof' și 'debug')
add_filter('query_vars', function ($vars) {
  $vars[] = 'gen';
  $vars[] = 'prof';
  $vars[] = 'debug';
  return $vars;
});


add_action('init', function () {
  // găsim pagina "Profil" (copilul lui "Panou")
  $page = get_page_by_path('panou/profil'); // parent/child
  if ($page && $page->ID) {
    // /panou/profesor/123  -> page_id=ID-ul lui "Profil" + profesor_id
    add_rewrite_rule(
      '^panou/profesor/([0-9]+)/?$',
      'index.php?page_id=' . $page->ID . '&profesor_id=$matches[1]',
      'top'
    );

    // /panou/tutor/456 -> page_id=ID-ul lui "Profil" + tutor_id
    add_rewrite_rule(
      '^panou/tutor/([0-9]+)/?$',
      'index.php?page_id=' . $page->ID . '&tutor_id=$matches[1]',
      'top'
    );
  }
}, 20);


// Declară query vars
add_filter('query_vars', function ($qv) {
  foreach (['profesor_id','prof_id','user_id','id','tutor_id'] as $k) {
    if (!in_array($k, $qv, true)) $qv[] = $k;
  }
  return $qv;
});

add_action('wp_login', function($user_login, $user){
  update_user_meta($user->ID, 'last_login', time());
}, 10, 2);



/* ===================== Export profesori CSV ===================== */

add_action('admin_post_edus_export_teachers_csv', 'edus_export_teachers_csv');
add_action('admin_post_nopriv_edus_export_teachers_csv', 'edus_export_teachers_csv'); // va pica pe auth check

if (!function_exists('edus_export_teachers_csv')) {
  function edus_export_teachers_csv() {
    if (!is_user_logged_in()) {
      wp_die('Autentificare necesară.', 401);
    }

    // nonce
    $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'edus_export_teachers_csv')) {
      wp_die('Link invalid sau expirat (nonce).', 403);
    }

    // permisiuni
    $current_user = wp_get_current_user();
    $uid   = (int) ($current_user->ID ?? 0);
    $roles = (array) ($current_user->roles ?? []);
    $is_admin = current_user_can('manage_options');
    $is_tutor = in_array('tutor', $roles, true);

    if (!$is_admin && !$is_tutor) {
      wp_die('Acces interzis.', 403);
    }

    // filtre din GET (aliniate cu UI)
    $q       = isset($_GET['q'])       ? sanitize_text_field(wp_unslash($_GET['q']))       : '';
    $level   = isset($_GET['level'])   ? sanitize_text_field(wp_unslash($_GET['level']))   : '';
    $status  = isset($_GET['status'])  ? sanitize_text_field(wp_unslash($_GET['status']))  : '';
    $gen     = isset($_GET['gen'])     ? sanitize_text_field(wp_unslash($_GET['gen']))     : ''; // an generație
    if ($gen === '' && isset($_GET['gen_year'])) { // fallback dacă vine alt nume
      $gen = sanitize_text_field(wp_unslash($_GET['gen_year']));
    }
    $county  = isset($_GET['county'])  ? sanitize_text_field(wp_unslash($_GET['county']))  : '';

    /* ===== Helpers locale (fără coliziuni) ===== */
    if (!function_exists('edus_es_normalize_level_code')) {
      function edus_es_normalize_level_code($raw) {
        $c = strtolower(trim((string)$raw));
        if ($c === 'primar-mic' || $c === 'primar mare' || $c === 'primar-mare') $c = 'primar';
        if ($c === 'gimnaziu') $c = 'gimnazial';
        if ($c === 'preșcolar' || $c === 'prescolari' || $c === 'preșcolari') $c = 'prescolar';
        return in_array($c, ['prescolar','primar','gimnazial','liceu'], true) ? $c : ($c ?: '');
      }
    }
    if (!function_exists('edus_es_level_label')) {
      function edus_es_level_label($code) {
        $map = ['prescolar'=>'Preșcolar','primar'=>'Primar','gimnazial'=>'Gimnazial','liceu'=>'Liceu'];
        $code = edus_es_normalize_level_code($code);
        return $map[$code] ?? '—';
      }
    }
    if (!function_exists('edus_es_format_dt')) {
      function edus_es_format_dt($ts_or_str) {
        if (!$ts_or_str) return '—';
        $ts = is_numeric($ts_or_str) ? (int)$ts_or_str : strtotime($ts_or_str);
        if (!$ts) return '—';
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts);
      }
    }

    global $wpdb;
    $tbl_users       = $wpdb->users;
    $tbl_generations = $wpdb->prefix . 'edu_generations';
    $tbl_students    = $wpdb->prefix . 'edu_students';
    $tbl_schools     = $wpdb->prefix . 'edu_schools';
    $tbl_cities      = $wpdb->prefix . 'edu_cities';
    $tbl_counties    = $wpdb->prefix . 'edu_counties';

    /* ===== Pas 1: Query WP_User_Query pentru profesori ===== */
    $meta_query = ['relation' => 'AND'];

    // Tutor: restrângem la profesorii alocați lui
    if ($is_tutor && !$is_admin) {
      $meta_query[] = [
        'key'     => 'assigned_tutor_id',
        'value'   => $uid,
        'compare' => '=',
        'type'    => 'NUMERIC',
      ];
    }

    // Nivel predare (ACF user meta)
    if ($level !== '') {
      $meta_query[] = [
        'key'     => 'nivel_predare',
        'value'   => $level,
        'compare' => '=',
      ];
    }

    // Statut (posibil în mai multe chei)
    if ($status !== '') {
      $meta_query[] = [
        'relation' => 'OR',
        ['key' => 'user_status_profesor', 'value' => $status, 'compare' => '='],
        ['key' => 'statut_prof',          'value' => $status, 'compare' => '='],
        ['key' => 'statut',               'value' => $status, 'compare' => '='],
      ];
    }

    $args = [
      'role'       => 'profesor',
      'number'     => -1, // tot setul; filtrăm după gen/județ manual mai jos
      'orderby'    => 'display_name',
      'order'      => 'ASC',
      'meta_query' => $meta_query,
    ];
    if ($q !== '') {
      $args['search']         = '*' . esc_attr($q) . '*';
      $args['search_columns'] = ['user_login','user_nicename','user_email','display_name'];
    }

    $user_query = new WP_User_Query($args);
    $all_prof   = $user_query->get_results(); // array WP_User
    if (empty($all_prof)) {
      // Trimitem CSV gol, cu header
      $filename = 'profesori_' . date('Y-m-d_His') . '.csv';
      nocache_headers();
      header('Content-Type: text/csv; charset=UTF-8');
      header('Content-Disposition: attachment; filename="'.$filename.'"');
      $out = fopen('php://output', 'w');
      fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM
      fputcsv($out, ['ID','Nume','Email','Cod SLF','Statut','Nivel predare','Experiență','Materie','Județ(e)','#Elevi','Generații (id·nivel·an)','Ultima activitate','Înregistrare']);
      fclose($out);
      exit;
    }

    $prof_ids = array_map(fn($u)=>(int)$u->ID, $all_prof);

    /* ===== Pas 2: Preluăm date asociate (generații, elevi, județe) ===== */

    // Generații per profesor
    $gens_by_prof = [];
    $years_available = [];
    if (!empty($prof_ids)) {
      $in = implode(',', array_fill(0, count($prof_ids), '%d'));
      $gens = $wpdb->get_results($wpdb->prepare("
        SELECT id, professor_id, name, level, year
        FROM {$tbl_generations}
        WHERE professor_id IN ($in)
        ORDER BY year DESC, id DESC
      ", ...$prof_ids));
      foreach ($gens as $g) {
        $pid = (int)$g->professor_id;
        if (!isset($gens_by_prof[$pid])) $gens_by_prof[$pid] = [];
        $gens_by_prof[$pid][] = $g;
        $yr = trim((string)$g->year);
        if ($yr !== '') $years_available[$yr] = true;
      }
    }

    // Număr elevi per profesor
    $students_count = [];
    if (!empty($prof_ids)) {
      $in = implode(',', array_fill(0, count($prof_ids), '%d'));
      $sc = $wpdb->get_results($wpdb->prepare("
        SELECT professor_id, COUNT(*) AS total
        FROM {$tbl_students}
        WHERE professor_id IN ($in)
        GROUP BY professor_id
      ", ...$prof_ids));
      foreach ($sc as $row) {
        $students_count[(int)$row->professor_id] = (int)$row->total;
      }
    }

    // Toate school_ids de pe toți profesorii
    $school_ids_all = [];
    foreach ($prof_ids as $pid) {
      $sids = get_user_meta($pid, 'assigned_school_ids', true);
      if (is_array($sids)) {
        foreach ($sids as $sid) {
          $sid = (int)$sid;
          if ($sid > 0) $school_ids_all[$sid] = true;
        }
      }
    }
    $school_ids_all = array_keys($school_ids_all);

    // Mapăm school_id => county_name
    $county_by_school = [];
    if (!empty($school_ids_all)) {
      $in2 = implode(',', array_fill(0, count($school_ids_all), '%d'));
      $rows = $wpdb->get_results($wpdb->prepare("
        SELECT s.id AS school_id, j.name AS county_name
        FROM {$tbl_schools} s
        LEFT JOIN {$tbl_cities}   c ON s.city_id = c.id
        LEFT JOIN {$tbl_counties} j ON c.county_id = j.id
        WHERE s.id IN ($in2)
      ", ...$school_ids_all));
      foreach ($rows as $r) {
        $county_by_school[(int)$r->school_id] = (string)$r->county_name;
      }
    }

    // Județe per profesor
    $counties_by_prof = [];
    foreach ($prof_ids as $pid) {
      $sids = get_user_meta($pid, 'assigned_school_ids', true);
      $set = [];
      if (is_array($sids)) {
        foreach ($sids as $sid) {
          $sid = (int)$sid;
          if ($sid > 0 && isset($county_by_school[$sid])) {
            $nm = trim((string)$county_by_school[$sid]);
            if ($nm !== '') $set[$nm] = true;
          }
        }
      }
      $counties_by_prof[$pid] = array_keys($set);
    }

    /* ===== Pas 3: Aplicăm filtrele rămase (an generație, județ) ===== */
    $filtered = $all_prof;

    // filtru an generație
    if ($gen !== '') {
      $by_year = [];
      foreach ($filtered as $u) {
        $pid = (int)$u->ID;
        $has = false;
        if (!empty($gens_by_prof[$pid])) {
          foreach ($gens_by_prof[$pid] as $g) {
            if ((string)$g->year === (string)$gen) { $has = true; break; }
          }
        }
        if ($has) $by_year[] = $u;
      }
      $filtered = $by_year;
    }

    // filtru județ
    if ($county !== '') {
      $by_cty = [];
      foreach ($filtered as $u) {
        $pid = (int)$u->ID;
        $ctys = $counties_by_prof[$pid] ?? [];
        if (in_array($county, $ctys, true)) $by_cty[] = $u;
      }
      $filtered = $by_cty;
    }

    /* ===== Pas 4: Emitere CSV ===== */
    $filename = 'profesori_' . date('Y-m-d_His') . '.csv';

    // antete
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');

    $out = fopen('php://output', 'w');
    // BOM pt. Excel
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    // header CSV
    fputcsv($out, [
      'ID','Nume','Email','Cod SLF','Statut','Nivel predare','Experiență','Materie',
      'Județ(e)','#Elevi','Generații (id·nivel·an)',
      'Ultima activitate','Înregistrare'
    ]);

    // rânduri
    foreach ($filtered as $u) {
      $pid  = (int)$u->ID;
      $name = trim(($u->first_name ?: $u->display_name).' '.($u->last_name ?: ''));
      if ($name === '') $name = $u->display_name ?: $u->user_login;

      $cod        = get_user_meta($pid, 'cod_slf', true);
      $nivel_val  = get_user_meta($pid, 'nivel_predare', true);
      $exp        = get_user_meta($pid, 'experienta', true);
      $mat        = get_user_meta($pid, 'materia_predata', true);

      $stat = get_user_meta($pid, 'user_status_profesor', true);
      if ($stat==='') $stat = get_user_meta($pid, 'statut_prof', true);
      if ($stat==='') $stat = get_user_meta($pid, 'statut', true);

      $ctys    = $counties_by_prof[$pid] ?? [];
      $cty_str = $ctys ? implode('; ', $ctys) : '';

      $elevi = (int)($students_count[$pid] ?? 0);

      $gen_bits = [];
      if (!empty($gens_by_prof[$pid])) {
        foreach ($gens_by_prof[$pid] as $g) {
          $gen_bits[] = '#'.$g->id.'·'.edus_es_level_label($g->level).'·'.$g->year;
        }
      }

      // Ultima activitate = last_login (fallback last_activity / last_seen)
      $last_login_ts = get_user_meta($pid, 'last_login', true);
      if (!$last_login_ts) $last_login_ts = get_user_meta($pid, 'last_activity', true);
      if (!$last_login_ts) $last_login_ts = get_user_meta($pid, 'last_seen', true);
      $last_login_ts = $last_login_ts ? (int)$last_login_ts : 0;

      // Înregistrare = user_registered
      $registered_ts = $u->user_registered ? strtotime($u->user_registered) : 0;

      fputcsv($out, [
        $pid,
        $name,
        $u->user_email,
        $cod ?: '',
        $stat ?: '',
        edus_es_level_label($nivel_val),
        $exp ?: '',
        $mat ?: '',
        $cty_str,
        $elevi,
        implode(' | ', $gen_bits),
        edus_es_format_dt($last_login_ts),
        edus_es_format_dt($registered_ts),
      ]);
    }

    fclose($out);
    exit;
  }
}


add_action('admin_post_edustart_create_notif', 'edustart_handle_create_notif');
// dacă vrei să permiți și non-admin logați, adaugă și linia de mai jos și ajustează permisiunile din handler
// add_action('admin_post_nopriv_edustart_create_notif', 'edustart_handle_create_notif');

function edustart_handle_create_notif() {
    if (!is_user_logged_in() || !current_user_can('administrator')) {
        wp_safe_redirect( home_url('/') );
        exit;
    }

    // URL-ul către care ne întoarcem (pagina ta cu lista)
    $redirect_to = isset($_POST['_redirect_to']) ? esc_url_raw($_POST['_redirect_to']) : home_url('/');

    // Nonce
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'admin_notif_create')) {
        $url = add_query_arg(['err' => rawurlencode('Sesiune invalidă. Încearcă din nou.')], $redirect_to);
        wp_safe_redirect($url);
        exit;
    }

    // CPT / TAX / META keys — păstrăm aceleași chei ca în template
    $CPT        = 'notificari';
    $TAX        = 'grup_notificare';
    $META_CORP  = 'corp';
    $META_VIEWS = 'vizualizari';

    // Date
    $title     = sanitize_text_field( wp_unslash($_POST['notif_title'] ?? '') );
    $corp_raw  = wp_unslash($_POST['notif_corp'] ?? '');
    $corp      = wp_kses_post($corp_raw);
    $target    = isset($_POST['target_groups']) && is_array($_POST['target_groups'])
                   ? array_map('sanitize_text_field', (array) $_POST['target_groups'])
                   : [];

    // Validare
    if (!$title || !$corp || empty($target)) {
        // Salvăm valorile în transient ca să re-populăm formularul după redirect
        $transient_key = 'edustart_notif_form_' . get_current_user_id();
        set_transient($transient_key, [
            'title'  => $title,
            'corp'   => $corp_raw, // îl ții raw pentru textarea
            'target' => $target,
        ], 120); // 2 minute sunt suficiente

        $url = add_query_arg(['err' => rawurlencode('Completează titlul, corpul și selectează cel puțin un grup.')], $redirect_to);
        wp_safe_redirect($url);
        exit;
    }

    // Asigurăm termenii în taxonomie
    foreach ($target as $slug) {
        if (!term_exists($slug, $TAX)) {
            wp_insert_term(ucfirst($slug), $TAX, ['slug' => $slug]);
        }
    }

    // Creăm notificarea
    $post_id = wp_insert_post([
        'post_type'    => $CPT,
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => $corp,
    ], true);

    if (is_wp_error($post_id)) {
        $transient_key = 'edustart_notif_form_' . get_current_user_id();
        set_transient($transient_key, [
            'title'  => $title,
            'corp'   => $corp_raw,
            'target' => $target,
        ], 120);

        $url = add_query_arg(['err' => rawurlencode('Eroare la creare: ' . $post_id->get_error_message())], $redirect_to);
        wp_safe_redirect($url);
        exit;
    }

    // Meta
    update_post_meta($post_id, $META_CORP, $corp);
    if (!metadata_exists('post', $post_id, $META_VIEWS)) {
        update_post_meta($post_id, $META_VIEWS, 0);
    }

    // Taxonomie
    wp_set_object_terms($post_id, $target, $TAX, false);

    // Gata → PRG
    $url = add_query_arg(['ok' => 1], $redirect_to);
    wp_safe_redirect($url);
    exit;
}

add_action('wp_ajax_edu_search_students', function(){
  check_ajax_referer('edu_nonce', 'nonce');
  if ( ! current_user_can('manage_options') && ! current_user_can('tutor') ) {
    wp_send_json([]); // sau wp_send_json_error(...)
  }
  global $wpdb;
  $q = sanitize_text_field($_POST['q'] ?? '');
  if ($q === '') wp_send_json([]);

  $tbl = $wpdb->prefix.'edu_students';
  $like = '%'.$wpdb->esc_like($q).'%';
  $rows = $wpdb->get_results($wpdb->prepare("
    SELECT id, first_name, last_name
    FROM {$tbl}
    WHERE first_name LIKE %s
       OR last_name LIKE %s
       OR CONCAT_WS(' ', first_name, last_name) LIKE %s
    ORDER BY last_name, first_name
    LIMIT 20
  ", $like, $like, $like));

  $out = array_map(function($r){
    $name = trim(($r->first_name ?: '').' '.($r->last_name ?: ''));
    return [
      'id'   => (int)$r->id,
      'text' => $name !== '' ? $name : ('#'.$r->id),
    ];
  }, $rows ?: []);

  wp_send_json($out);
});

add_action('wp_ajax_edu_search_teachers', function(){
  check_ajax_referer('edu_nonce', 'nonce');

  // Doar admin sau tutor (același model ca pagina)
  if ( ! current_user_can('manage_options') ) {
    $u = wp_get_current_user();
    if ( ! in_array('tutor', (array)$u->roles, true) ) {
      wp_send_json([]); // fără permisiuni
    }
  }

  $q = sanitize_text_field($_POST['q'] ?? '');
  if ($q === '') wp_send_json([]);

  // căutăm în user_email, user_login, display_name; plus prenume/nume din meta
  $args = [
    'role'           => 'profesor',
    'number'         => 20,
    'search'         => '*'.esc_attr($q).'*',
    'search_columns' => ['user_login','user_email','display_name'],
    'orderby'        => 'display_name',
    'order'          => 'ASC',
  ];
  $uq = new WP_User_Query($args);
  $users = $uq->get_results();

  // completăm cu match și pe meta first_name/last_name (dacă nu a prins deja)
  if (count($users) < 20) {
    global $wpdb;
    $like = '%'.$wpdb->esc_like($q).'%';
    $meta_hits = $wpdb->get_col($wpdb->prepare("
      SELECT DISTINCT user_id
      FROM {$wpdb->usermeta}
      WHERE ( (meta_key='first_name' AND meta_value LIKE %s)
           OR (meta_key='last_name'  AND meta_value LIKE %s) )
      LIMIT 40
    ", $like, $like));

    if ($meta_hits) {
      $already = array_map(fn($u)=>(int)$u->ID, $users);
      $missing = array_diff(array_map('intval',$meta_hits), $already);
      if ($missing) {
        $extra = get_users(['include'=>$missing, 'role'=>'profesor', 'number'=>20-count($users)]);
        $users = array_merge($users, $extra);
      }
    }
  }

  $out = [];
  foreach ($users as $u) {
    $name = trim(($u->first_name ?: $u->display_name).' '.($u->last_name ?: ''));
    if ($name === '') $name = $u->display_name ?: $u->user_login;
    $out[] = [
      'id'   => (int)$u->ID,
      'text' => $name.' — '.$u->user_email,
    ];
  }
  wp_send_json($out);
});

/* ── Check if email already exists (for add/edit teacher modal) ── */
add_action('wp_ajax_edu_check_email', function(){
  check_ajax_referer('edu_nonce', 'nonce');

  if ( ! current_user_can('manage_options') ) {
    $u = wp_get_current_user();
    if ( ! in_array('tutor', (array)$u->roles, true) ) {
      wp_send_json_error(['message' => 'Permisiuni insuficiente.']);
    }
  }

  $email   = sanitize_email($_POST['email'] ?? '');
  $user_id = intval($_POST['user_id'] ?? 0);

  if ( ! is_email($email) ) {
    wp_send_json_error(['message' => 'Adresa de email nu este validă.']);
  }

  $existing = get_user_by('email', $email);

  if ( $existing && ( ! $user_id || (int)$existing->ID !== $user_id ) ) {
    wp_send_json_error(['message' => 'Acest email există deja în sistem.']);
  }

  wp_send_json_success(['message' => 'Email disponibil.']);
});


if (!function_exists('es_send_csv')) {
  /**
   * Trimite CSV ca download și oprește execuția.
   *
   * @param string        $filename
   * @param array<string> $headers
   * @param iterable      $rows
   */
  function es_send_csv(string $filename, array $headers, iterable $rows): void {
    // Curăță output buffers, ca să nu stricăm header-ele
    while (ob_get_level()) { ob_end_clean(); }

    // Header-e corecte + no-cache
    if (!headers_sent()) {
      nocache_headers();
      header('Content-Type: text/csv; charset=UTF-8');
      header('Content-Disposition: attachment; filename="'.$filename.'"');
      header('X-Content-Type-Options: nosniff');
    }

    // BOM pentru Excel (diacritice)
    echo chr(0xEF).chr(0xBB).chr(0xBF);

    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);

    foreach ($rows as $row) {
      $clean = [];
      foreach ((array)$row as $v) {
        if (is_bool($v)) {
          $v = $v ? '1' : '0';
        } elseif (is_array($v) || is_object($v)) {
          $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        $v = (string)$v;
        $v = str_replace(["\r\n","\r","\n"], ' ', $v); // fără CR/LF în celule
        $clean[] = $v;
      }
      fputcsv($out, $clean);
    }

    fclose($out);
    flush();
    exit;
  }
}


// Exports helpers
add_action('admin_post_es_export_students', function () {
  // Permisiuni: admin sau rol "tutor"
  $user = wp_get_current_user();
  $is_admin = current_user_can('manage_options');
  $is_tutor = in_array('tutor', (array)($user->roles ?? []), true);
  if (!$is_admin && !$is_tutor) {
    status_header(403);
    echo 'Acces restricționat.';
    exit;
  }

  $nonce = $_GET['_wpnonce'] ?? '';
  if (!wp_verify_nonce($nonce, 'es_export_students')) {
    status_header(403);
    echo 'Link invalid (nonce).';
    exit;
  }
  require_once trailingslashit( get_stylesheet_directory() ) . 'exports/export-students.php';
  exit;
});

// === Export profesori (CSV, full set, fără "Profil") ===
add_action('admin_post_edus_export_teachers_csv', function () {
  $user     = wp_get_current_user();
  $is_admin = current_user_can('manage_options');
  $is_tutor = in_array('tutor', (array)($user->roles ?? []), true);
  if (!$is_admin && !$is_tutor) {
    status_header(403);
    echo 'Acces restricționat.';
    exit;
  }

  $nonce = $_GET['nonce'] ?? '';
  if (!wp_verify_nonce($nonce, 'edus_export_teachers_csv')) {
    status_header(403);
    echo 'Link invalid (nonce).';
    exit;
  }

  require_once trailingslashit(get_stylesheet_directory()) . 'exports/export-teachers.php';
  exit;
});


// === Export școli (CSV, full set) ===
add_action('admin_post_edus_export_schools_csv', function () {
  if (!is_user_logged_in()) {
    status_header(403);
    echo 'Autentificare necesară.';
    exit;
  }
  $nonce = $_GET['nonce'] ?? '';
  if (!wp_verify_nonce($nonce, 'edus_export_schools_csv')) {
    status_header(403);
    echo 'Link invalid (nonce).';
    exit;
  }
  
  require_once trailingslashit(get_stylesheet_directory()) . 'exports/export-schools.php';
  exit;
});


// ===================== DB Schema Upgrades =====================
// GENERATIONS
// =====================

// === Upgrade/ensure schema for wp_edu_generations ===
add_action('admin_init', function () {
  global $wpdb;
  $table = $wpdb->prefix . 'edu_generations';

  // Dacă nu există tabela, o creăm cu schema completă
  $exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table) );
  if ($exists !== $table) {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      name VARCHAR(191) NOT NULL,
      professor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
      level VARCHAR(32) NOT NULL,
      class_label VARCHAR(64) NOT NULL DEFAULT '',
      class_labels_json LONGTEXT NULL,
      year VARCHAR(64) NOT NULL,
      created_at DATETIME NOT NULL,
      sel_t0 TINYINT(1) NOT NULL DEFAULT 0,
      sel_ti TINYINT(1) NOT NULL DEFAULT 0,
      sel_t1 TINYINT(1) NOT NULL DEFAULT 0,
      lit_t0 TINYINT(1) NOT NULL DEFAULT 0,
      lit_t1 TINYINT(1) NOT NULL DEFAULT 0,
      num_t0 TINYINT(1) NOT NULL DEFAULT 0,
      num_t1 TINYINT(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      KEY professor_id (professor_id),
      KEY year (year)
    ) {$charset_collate};";
    dbDelta($sql);
    return;
  }

  // Dacă există, adăugăm doar coloanele lipsă
  $cols = array_flip( (array) $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0) );

  $alters = [];

  if (!isset($cols['class_label'])) {
    $alters[] = "ADD COLUMN class_label VARCHAR(64) NOT NULL DEFAULT '' AFTER level";
  }
  if (!isset($cols['class_labels_json'])) {
    $alters[] = "ADD COLUMN class_labels_json LONGTEXT NULL AFTER class_label";
  }

  foreach (['sel_t0','sel_ti','sel_t1','lit_t0','lit_t1','num_t0','num_t1'] as $c) {
    if (!isset($cols[$c])) {
      $alters[] = "ADD COLUMN {$c} TINYINT(1) NOT NULL DEFAULT 0 AFTER created_at";
    }
  }

  if (!empty($alters)) {
    $sql = "ALTER TABLE {$table} " . implode(', ', $alters);
    $wpdb->query($sql); // intenționat fără die; dacă vrei, poți loga $wpdb->last_error
  }
});

add_action('after_switch_theme', 'edu_ensure_generations_table_v2');
function edu_ensure_generations_table_v2(){
  global $wpdb;
  $table = $wpdb->prefix . 'edu_generations';
  $charset_collate = $wpdb->get_charset_collate();
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  $sql = "CREATE TABLE {$table} (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    professor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(191) NOT NULL,
    level VARCHAR(32) NOT NULL,
    year VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY professor_id (professor_id),
    KEY year (year)
  ) {$charset_collate};";
  dbDelta($sql);
}

// Utils
function es_normalize_level_code_srv($raw){
  $c = strtolower(trim((string)$raw));
  if ($c === 'primar-mic' || $c === 'primar mare' || $c === 'primar-mare') $c = 'primar';
  if ($c === 'gimnaziu') $c = 'gimnazial';
  if ($c === 'preșcolar' || $c === 'prescolari' || $c === 'preșcolari') $c = 'prescolar';
  return in_array($c, ['prescolar','primar','gimnazial','liceu'], true) ? $c : '';
}
function es_level_label_srv($code){
  $map = ['prescolar'=>'Preșcolar','primar'=>'Primar','gimnazial'=>'Gimnazial','liceu'=>'Liceu'];
  $code = es_normalize_level_code_srv($code);
  return $map[$code] ?? '—';
}
function es_current_school_year_srv(){
  // WP timezone aware
  $ts = current_time('timestamp');
  $y  = (int) date('Y', $ts);
  $m  = (int) date('n', $ts);
  // An școlar începe în august: Aug(8)–Dec -> Y-(Y+1), Ian–Iul -> (Y-1)-Y
  if ($m >= 8) return $y . '-' . ($y+1);
  return ($y-1) . '-' . $y;
}

// AJAX: întoarce nivelul profesorului (admin-only)
add_action('wp_ajax_edu_get_prof_level', function(){
  if ( ! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'edu_nonce') ) {
    wp_send_json_error(['message' => 'Token invalid.'], 403);
  }
  if ( ! current_user_can('manage_options') ) {
    wp_send_json_error(['message' => 'Doar admin.'], 403);
  }
  $pid = isset($_POST['professor_id']) ? absint($_POST['professor_id']) : 0;
  if (!$pid) wp_send_json_error(['message'=>'Lipsește professor_id.'], 400);

  $u = get_userdata($pid);
  if ( ! $u || ! in_array('profesor', (array)$u->roles, true) ) {
    wp_send_json_error(['message'=>'professor_id invalid.'], 400);
  }

  $raw = get_user_meta($pid, 'nivel_predare', true);
  if (is_array($raw)) $raw = (string) ($raw[0] ?? '');
  $code  = es_normalize_level_code_srv($raw);
  $label = es_level_label_srv($code);

  wp_send_json_success([
    'level_code'  => $code,
    'level_label' => $label,
    'year'        => es_current_school_year_srv(),
  ]);
});

// AJAX: creează generație (admin-only). Nivelul și anul se DERIVĂ pe server.
add_action('wp_ajax_edu_create_generation', function () {
  if ( ! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'edu_nonce') ) {
    wp_send_json_error(['message' => 'Token invalid.'], 403);
  }
  if ( ! current_user_can('manage_options') ) {
    wp_send_json_error(['message' => 'Doar administratorul poate crea/aloca generații.'], 403);
  }

  $professor_id = isset($_POST['professor_id']) ? absint($_POST['professor_id']) : 0;
  $name         = isset($_POST['name']) ? sanitize_text_field( wp_unslash($_POST['name']) ) : '';

  if (!$professor_id || $name === '') {
    wp_send_json_error(['message'=>'Selectează profesorul și completează numele generației.'], 400);
  }

  $u = get_userdata($professor_id);
  if ( ! $u || ! in_array('profesor', (array)($u->roles ?? []), true) ) {
    wp_send_json_error(['message'=>'professor_id invalid (nu e utilizator cu rol „profesor”).'], 400);
  }

  // Nivel din meta profesor (afișat read-only în UI)
  $raw_level = get_user_meta($professor_id, 'nivel_predare', true);
  if (is_array($raw_level)) $raw_level = (string)($raw_level[0] ?? '');
  $level = es_normalize_level_code_srv($raw_level);
  if ($level === '') {
    wp_send_json_error(['message'=>'Profesorul nu are setat un nivel valid.'], 400);
  }

  // An școlar — din dropdown (ex: 2025-2026); fallback: auto
  $year = isset($_POST['year']) ? sanitize_text_field(wp_unslash($_POST['year'])) : '';
  if ($year === '' || !preg_match('/^\d{4}-\d{4}$/', $year)) {
    $y = (int) date('Y', current_time('timestamp'));
    $m = (int) date('n', current_time('timestamp'));
    $year = ($m >= 8) ? "{$y}-".($y+1) : ($y-1)."-{$y}";
  }

  // Opțional: clasa selectată + lista claselor disponibile
  $class_label = isset($_POST['class_label']) ? sanitize_text_field( wp_unslash($_POST['class_label']) ) : '';
  $class_labels = [];
  if (isset($_POST['class_labels'])) {
    $arr = is_array($_POST['class_labels']) ? $_POST['class_labels'] : [$_POST['class_labels']];
    foreach ($arr as $v) { $class_labels[] = sanitize_text_field( wp_unslash($v) ); }
  }
  $class_labels_json = !empty($class_labels) ? wp_json_encode(array_values($class_labels)) : null;

  global $wpdb;
  $table = $wpdb->prefix . 'edu_generations';

  // Siguranță: tabelul chiar există?
  $exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table) );
  if ($exists !== $table) {
    wp_send_json_error(['message'=>'Tabela nu există. Reîncarcă adminul ca să ruleze migrarea automată.'], 500);
  }

  // Inserează doar coloanele care există
  $cols = (array) $wpdb->get_col("SHOW COLUMNS FROM {$table}");
  if (empty($cols)) {
    wp_send_json_error(['message'=>'Nu pot citi coloanele din tabel.'], 500);
  }
  $cols = array_flip($cols);

  $payload = [
    'name'         => $name,
    'professor_id' => $professor_id,
    'level'        => $level,
    'year'         => $year,
    'created_at'   => current_time('mysql'),
  ];
  if (isset($cols['class_label']))        $payload['class_label']        = $class_label;
  if (isset($cols['class_labels_json']))  $payload['class_labels_json']  = $class_labels_json;

  // Setăm explicit noile booleene la 0 dacă există coloana (au oricum default 0)
  foreach (['sel_t0','sel_ti','sel_t1','lit_t0','lit_t1','num_t0','num_t1'] as $flag) {
    if (isset($cols[$flag])) $payload[$flag] = 0;
  }

  $format = [];
  foreach ($payload as $k=>$v) { $format[] = is_int($v) ? '%d' : '%s'; }

  $ok = $wpdb->insert($table, $payload, $format);
  if (!$ok) {
    wp_send_json_error([
      'message'  => 'Inserarea a eșuat.',
      'db_error' => $wpdb->last_error,
      'data'     => $payload,
    ], 500);
  }

  $new_id = (int) $wpdb->insert_id;

  $label_map = ['prescolar'=>'Preșcolar','primar'=>'Primar','gimnazial'=>'Gimnazial','liceu'=>'Liceu'];
  wp_send_json_success([
    'id'                 => $new_id,
    'professor_id'       => $professor_id,
    'name'               => $name,
    'level'              => $level,
    'level_label'        => $label_map[$level] ?? $level,
    'class_label'        => $class_label,
    'class_labels'       => $class_labels,
    'year'               => $year,
    'flags'              => [
      'sel_t0'=>0,'sel_ti'=>0,'sel_t1'=>0,
      'lit_t0'=>0,'lit_t1'=>0,'num_t0'=>0,'num_t1'=>0
    ],
  ]);
});

// — Toggle un singur modul pe o generație
add_action('wp_ajax_edu_toggle_generation_module', 'edu_toggle_generation_module');
function edu_toggle_generation_module() {
  if ( ! current_user_can('manage_options') ) {
    wp_send_json_error('Permisiune refuzată.');
  }
  check_ajax_referer('genmod_nonce', 'nonce');

  global $wpdb;
  $table  = $wpdb->prefix . 'edu_generations';
  $gid    = (int)($_POST['gid'] ?? 0);
  $module = sanitize_key($_POST['module'] ?? '');
  $value  = isset($_POST['value']) ? (int)$_POST['value'] : 0;

  $allowed = ['sel_t0','sel_ti','sel_t1','lit_t0','lit_t1','num_t0','num_t1'];
  if (!$gid || !in_array($module, $allowed, true)) {
    wp_send_json_error('Parametri invalizi.');
  }
  $value = $value ? 1 : 0;

  $ok = $wpdb->update($table, [ $module => $value ], [ 'id' => $gid ], ['%d'], ['%d']);
  if ($ok === false) {
    wp_send_json_error('DB error: ' . $wpdb->last_error);
  }
  wp_send_json_success(['gid'=>$gid, 'module'=>$module, 'value'=>$value]);
}

// — Toggle în masă al unui modul pe mai multe generații
add_action('wp_ajax_edu_bulk_toggle_generation_modules', 'edu_bulk_toggle_generation_modules');
function edu_bulk_toggle_generation_modules() {
  if ( ! current_user_can('manage_options') ) {
    wp_send_json_error('Permisiune refuzată.');
  }
  check_ajax_referer('genmod_nonce', 'nonce');

  global $wpdb;
  $table  = $wpdb->prefix . 'edu_generations';
  $module = sanitize_key($_POST['module'] ?? '');
  $value  = isset($_POST['value']) ? (int)$_POST['value'] : 0;

  $allowed = ['sel_t0','sel_ti','sel_t1','lit_t0','lit_t1','num_t0','num_t1'];
  if (!in_array($module, $allowed, true)) {
    wp_send_json_error('Modul invalid.');
  }

  $ids = json_decode(stripslashes($_POST['gids'] ?? '[]'), true);
  if (!is_array($ids) || empty($ids)) {
    wp_send_json_error('Lista de ID-uri lipsă.');
  }

  $value = $value ? 1 : 0;
  $updated = 0;
  foreach ($ids as $id) {
    $gid = (int)$id;
    if ($gid <= 0) continue;
    $ok = $wpdb->update($table, [ $module => $value ], [ 'id' => $gid ], ['%d'], ['%d']);
    if ($ok !== false) $updated++;
  }
  wp_send_json_success(['updated'=>$updated, 'module'=>$module, 'value'=>$value]);
}
