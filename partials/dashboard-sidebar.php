<?php
$current_user  = wp_get_current_user();
$user_id       = $current_user->ID;
$profile_image = get_user_meta($user_id, 'profile_image', true);
$user_roles    = (array) $current_user->roles;

global $wp;
$current_url = trailingslashit(home_url(add_query_arg([], $wp->request)));

/** Normalizează și extrage PATH-ul (fără domeniu / query), cu slash la final */
function es_url_path_trailing($url) {
  $path = wp_parse_url($url, PHP_URL_PATH) ?? '/';
  return trailingslashit($path);
}

// Path-ul curent normalizat (ex: /panou/, /panou/lista/)
$current_path = es_url_path_trailing($current_url);

/** Definim toate item-urile disponibile (admin vede toate) */
$all_menu = [
  'dashboard'   => ['label' => 'Dashboard',      'href' => home_url('/panou'),             'icon' => 'home'],
  'lista'       => ['label' => 'Elevii mei',     'href' => home_url('/panou/lista'),       'icon' => 'book-open'],
  'elevi'       => ['label' => 'Elevi',          'href' => home_url('/panou/elevi'),       'icon' => 'book-open'],
  'generatii'   => ['label' => 'Generații',      'href' => home_url('/panou/generatii'),   'icon' => 'lists'],
  'evaluari'    => ['label' => 'Evaluări',      'href' => home_url('/panou/evaluari'),   'icon' => 'chart-bar'],
  'profesori'   => ['label' => 'Profesori',      'href' => home_url('/profesori'),   'icon' => 'academic-cap'],
  'scoli'       => ['label' => 'Școli', 'href' => home_url('/panou/scoli'),       'icon' => 'building'],
  'chestionare' => ['label' => 'Chestionare',    'href' => home_url('/panou/chestionare'), 'icon' => 'clipboard-list'],
  'rapoarte'    => ['label' => 'Rapoarte',      'href' => home_url('/panou/rapoarte'),    'icon' => 'chart-bar'],
  'analiza'     => ['label' => 'Analiză',       'href' => home_url('/panou/analiza'),     'icon' => 'clipboard-list'],
  'utilizatori' => ['label' => 'Utilizatori',      'href' => home_url('/panou/utilizatori'),    'icon' => 'users'],
  'profil'      => ['label' => 'Profil',         'href' => home_url('/panou/profil'),      'icon' => 'user'],
  'notificari'      => ['label' => 'Notificări',         'href' => home_url('/panou/notificari'),      'icon' => 'bell'],
  'setari'      => ['label' => 'Setări',         'href' => home_url('/panou/setari'),      'icon' => 'cog'],
  'optiuni'      => ['label' => 'Opțiuni',         'href' => home_url('/panou/optiuni-website'),      'icon' => 'squares'],
];

/** Filtrare după rol */
if ( current_user_can('manage_options') ) {
  $visible_keys = ['dashboard','elevi','generatii','profesori','scoli','rapoarte','analiza','utilizatori','profil','notificari','setari','optiuni'];
} elseif ( in_array('tutor', $user_roles, true) ) {
  $visible_keys = ['dashboard','generatii','profesori','rapoarte','analiza','setari'];
} elseif ( in_array('profesor', $user_roles, true) ) {
  $visible_keys = ['dashboard','lista','evaluari','profil','setari'];
} else {
  $visible_keys = ['dashboard','profil','setari']; // fallback minim
}

/** Helper pentru SVG-uri (păstrăm stilul curent) */
function es_sidebar_icon($name) {
  switch ($name) {
    case 'home':
      return '<path fill="currentColor" fill-opacity="0.3" d="M5 14.059c0-1.01 0-1.514.222-1.945.221-.43.632-.724 1.453-1.31l4.163-2.974c.56-.4.842-.601 1.162-.601.32 0 .601.2 1.162.601l4.163 2.974c.821.586 1.232.88 1.453 1.31.222.43.222.935.222 1.945V19c0 .943 0 1.414-.293 1.707C18.414 21 17.943 21 17 21H7c-.943 0-1.414 0-1.707-.293C5 20.414 5 19.943 5 19v-4.94Z"></path><path fill="currentColor" d="M3 12.387c0 .267 0 .4.084.441.084.041.19-.04.4-.204l7.288-5.669c.59-.459.885-.688 1.228-.688.343 0 .638.23 1.228.688l7.288 5.669c.21.163.316.245.4.204.084-.04.084-.174.084-.441v-.409c0-.48 0-.72-.102-.928-.101-.208-.291-.355-.67-.65l-7-5.445c-.59-.459-.885-.688-1.228-.688-.343 0-.638.23-1.228.688l-7 5.445c-.379.295-.569.442-.67.65-.102.208-.102.448-.102.928v.409Z"></path><path fill="currentColor" d="M11.5 15.5h1A1.5 1.5 0 0 1 14 17v3.5h-4V17a1.5 1.5 0 0 1 1.5-1.5Z"></path><path fill="currentColor" d="M17.5 5h-1a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5Z"></path>';
    case 'book-open':
      return '<path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25M16.5 7.5V18a2.25 2.25 0 0 0 2.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 0 0 2.25 2.25h13.5M6 7.5h3v3H6v-3Z" />';
      
    case 'academic-cap':
      return '<path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5" />';
      
    case 'clipboard-list':
      return '<path fill="currentColor" fill-opacity="0.35" d="M12.105 17.21a8.105 8.105 0 1 0 0-16.21 8.105 8.105 0  0 0 0 16.21Z"></path><path stroke="currentColor" stroke-linecap="round" d="M10.947 14.895v-4.92A1.447 1.447 0 1 0 9.5 11.421h5.21a1.447 1.447 0 1 0-1.447-1.448v4.921"></path><path fill="currentColor" fill-rule="evenodd" d="M15.579 17.863a.178.178 0 0 0-.25-.162 8.08 8.08 0  0 1-3.224.667 8.078 8.078 0 0 1-3.223-.666.178.178 0 0 0-.25.16v2.243A2.895 2.895 0  0 0 11.526 23h1.158a2.895 2.895 0  0 0 2.895-2.895v-2.242Z" clip-rule="evenodd"></path>';
    case 'user':
      return '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0  0 1 7.5 0ZM4.501 20.118a7.5 7.5 0  0 1 14.998 0A17.933 17.933 0  0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"></path>';
    case 'lists':
      return '<path stroke-linecap="round" stroke-linejoin="round" d="M6 6.878V6a2.25 2.25 0 0 1 2.25-2.25h7.5A2.25 2.25 0 0 1 18 6v.878m-12 0c.235-.083.487-.128.75-.128h10.5c.263 0 .515.045.75.128m-12 0A2.25 2.25 0 0 0 4.5 9v.878m13.5-3A2.25 2.25 0 0 1 19.5 9v.878m0 0a2.246 2.246 0 0 0-.75-.128H5.25c-.263 0-.515.045-.75.128m15 0A2.25 2.25 0 0 1 21 12v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6c0-.98.626-1.813 1.5-2.122" />';
      
    case 'cog':
      return '<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />';
      
    case 'layers':
      return '<path fill="currentColor" d="M12 3 1.5 9 12 15 22.5 9 12 3Z"></path><path fill="currentColor" fill-opacity=".3" d="M3 10.5v5.25L12 21l9-5.25V10.5L12 16.5 3 10.5Z"></path>';
    case 'users':
      return '<path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />';
    case 'squares':
      return '<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 16.875h3.375m0 0h3.375m-3.375 0V13.5m0 3.375v3.375M6 10.5h2.25a2.25 2.25 0 0 0 2.25-2.25V6a2.25 2.25 0 0 0-2.25-2.25H6A2.25 2.25 0 0 0 3.75 6v2.25A2.25 2.25 0 0 0 6 10.5Zm0 9.75h2.25A2.25 2.25 0 0 0 10.5 18v-2.25a2.25 2.25 0 0 0-2.25-2.25H6a2.25 2.25 0 0 0-2.25 2.25V18A2.25 2.25 0 0 0 6 20.25Zm9.75-9.75H18a2.25 2.25 0 0 0 2.25-2.25V6A2.25 2.25 0 0 0 18 3.75h-2.25A2.25 2.25 0 0 0 13.5 6v2.25a2.25 2.25 0 0 0 2.25 2.25Z" />';
      
    case 'bell':
      return '<path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" />';
      
    case 'chart-bar':
      return '<path d="M18.375 2.25c-1.035 0-1.875.84-1.875 1.875v15.75c0 1.035.84 1.875 1.875 1.875h.75c1.035 0 1.875-.84 1.875-1.875V4.125c0-1.036-.84-1.875-1.875-1.875h-.75ZM9.75 8.625c0-1.036.84-1.875 1.875-1.875h.75c1.036 0 1.875.84 1.875 1.875v11.25c0 1.035-.84 1.875-1.875 1.875h-.75a1.875 1.875 0 0 1-1.875-1.875V8.625ZM3 13.125c0-1.036.84-1.875 1.875-1.875h.75c1.036 0 1.875.84 1.875 1.875v6.75c0 1.035-.84 1.875-1.875 1.875h-.75A1.875 1.875 0 0 1 3 19.875v-6.75Z" />';
    case 'building':
      return '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.75 3l-1.5.545m0 6.205 3 1m1.5.5-1.5-.5M6.75 7.364V3h-3v18m3-13.636 10.5-3.819" />';
      
      
  }
  return '';
}

/** Creează lista finală pentru meniu (includem și key) */
$menu_items = [];
foreach ($visible_keys as $k) {
  $menu_items[] = $all_menu[$k] + ['_key' => $k];
}
?>

<!-- Sidebar -->
<aside <?php if ($vp === 'mobile') : ?>x-data="{ sidebarOpen: false }"<?php else : ?>x-data="{ sidebarOpen: true }"<?php endif; ?>
   <?php if ($vp === 'mobile') : ?>
      :class="sidebarOpen ? 'w-64 h-screen bg-gradient-to-r from-sky-700 to-sky-600' : 'w-16 h-16 py-4 px-0 -mr-16 bg-transparent'"
      class="sticky top-0 flex flex-col items-stretch text-white z-[40] overflow-hidden transition-all duration-300 ease-in-out"
    <?php else : ?>
      :class="sidebarOpen ? 'w-64' : 'w-16'"
      class="sticky top-0 h-screen flex flex-col items-stretch bg-gradient-to-r from-sky-700 to-sky-600 text-white z-[40] overflow-hidden transition-all duration-300 ease-in-out"
    <?php endif; ?>>

  <!-- Header user -->
  <div class="relative flex items-center justify-between h-16 px-3">
    <div :class="sidebarOpen ? 'flex' : 'hidden'" class="items-center min-w-0 gap-3">
      <div class="relative flex-none w-10 h-10">
        <?php if ($profile_image): ?>
          <img src="<?= esc_url(wp_get_attachment_image_url($profile_image, 'thumbnail')); ?>"
               alt="Profil" class="object-cover w-10 h-10 rounded-full">
        <?php else: ?>
          <img src="<?= esc_url(get_template_directory_uri().'/assets/images/default-teach.svg'); ?>"
               alt="Profil" class="object-cover w-10 h-10 rounded-full">
        <?php endif; ?>
      </div>
      <div class="flex flex-col flex-1 truncate" x-show="sidebarOpen" x-cloak>
        <span class="text-[11px] uppercase font-semibold tracking-wider text-white/80">Salut</span>
        <span class="text-sm font-semibold leading-5 truncate"><?= esc_html($current_user->display_name); ?></span>
      </div>
    </div>

    <!-- Toggle -->
    <button @click="sidebarOpen = !sidebarOpen"
            class="inline-flex items-center justify-center p-2 ml-auto rounded-lg hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white/20 mobile:bg-white/10 mobile:ring-white/20 mobile:ring-2">
      <svg x-show="sidebarOpen" xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      <svg x-show="!sidebarOpen" xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/></svg>
    </button>
  </div>

  <!-- Menu -->
  <nav class="flex-1 py-6 overflow-x-hidden overflow-y-auto text-sm"
       :class="sidebarOpen ? '' : ''">
    <?php foreach ($menu_items as $item): ?>
      <?php
        $item_path = es_url_path_trailing($item['href']);
        $is_active = ($current_path === $item_path);

        if($item['label'] === 'Dashboard' ) :
          $active_classes = implode(' ', [
            'bg-slate-100 uppercase mr-0 ml-2 rounded-r-none rounded-l-full text-es-blue-dark py-2 pr-6 pl-2 ring-0',
          ]);
        else : 
          // Clasele pentru item activ (inclusiv pseudo before/after)
          $active_classes = implode(' ', [
            'bg-slate-100 uppercase mr-0 ml-2 rounded-r-none rounded-l-full text-es-blue-dark py-1 my-1 pr-6 pl-2 ring-0',
            // BEFORE – colțul de sus-dreapta
            'before:content-[\'\'] before:absolute before:right-[-6px] before:top-[-10px]',
            'before:w-4 before:h-4 before:bg-[#0283c6]',
            'before:rounded-[20px] before:border-[6px] before:border-[#f1f5f9]',
            'before:border-l-0 before:border-t-0 before:rounded-bl-none before:rounded-tr-none',
            // AFTER – colțul de jos-dreapta (versiunea corectă din cerință)
            'after:content-[\'\'] after:absolute after:right-[-6px] after:bottom-[-10px]',
            'after:w-4 after:h-4 after:bg-[#0283c6]',
            'after:rounded-[20px] after:border-[6px] after:border-[#f1f5f9]',
            'after:border-l-0 after:border-b-0 after:rounded-tl-none after:rounded-br-none',
          ]);
        endif;
      ?>
      <a href="<?= esc_url($item['href']); ?>"
        class="group relative z-50 mx-2 flex items-center gap-3 rounded-xl transition-all ease-in-out duration-150 <?= $is_active ? esc_attr($active_classes) : 'text-slate-100 hover:text-base'; ?>"
        x-bind:class="sidebarOpen
          ? 'justify-start px-2 my-2'
          : 'justify-start !ml-2 px-1 <?= $is_active ? 'mb-2 opacity-100' : 'mb-2 hover:scale-110 opacity-50 hover:opacity-100' ?>'">
        <span class="inline-flex items-center justify-center rounded-lg p-1.5 ring-1 transition-all ease-in-out duration-150"
          x-bind:class="sidebarOpen 
          ? '<?= $is_active ? 'text-es-blue-dark ring-white/10 bg-white/10' : 'ring-sky-600 bg-slate-100 text-sky-800'; ?>'
          : '<?= $is_active ? 'text-es-blue-dark ring-white/10 bg-white/10' : 'ring-sky-600 bg-slate-100 text-sky-800'; ?>'">
          <svg xmlns="http://www.w3.org/2000/svg" class="size-5" x-bind:class="sidebarOpen ? 'size-6' : 'size-6'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25">
            <?= es_sidebar_icon($item['icon']); ?>
          </svg>
        </span>

        <span x-show="!sidebarOpen" x-cloak class="absolute z-50 hidden px-3 py-1 text-sm text-white transition-all duration-150 ease-in-out delay-150 rounded group-hover:block left-14 bg-sky-800"><?= esc_html($item['label']); ?></span>

        <span x-show="sidebarOpen" x-cloak class="font-semibold truncate"><?= esc_html($item['label']); ?></span>

        <?php if ($is_active): ?>
          <span x-show="sidebarOpen" x-cloak class="ml-auto inline-flex size-2 rounded-full bg-emerald-400 shadow-[0_0_0_3px_rgba(255,255,255,0.08)]"></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <!-- Logout -->
  <div class="p-2 mt-auto">
    <a href="<?= esc_url(wp_logout_url(home_url('/'))); ?>"
       class="group inline-flex items-center rounded-xl px-2 py-2 mx-0.5 text-sm font-medium transition
              bg-white/5 hover:bg-white/10 ring-1 ring-inset ring-white/10 text-white"
       x-bind:class="sidebarOpen ? 'gap-3 justify-start pr-8' : 'justify-center'">
      <span class="inline-flex items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/10 p-1.5">
        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25">
          <path d="M17 16l4-4m0 0l-4-4m4 4H7"></path><path d="M7 4h6a4 4 0 0 1 4 4v1"></path><path d="M17 15v1a4 4 0 0 1-4 4H7"></path>
        </svg>
      </span>
      <span x-show="sidebarOpen" x-cloak>Deconectare</span>
    </a>
    <div class="h-3"></div>
  </div>

  <div x-show="sidebarOpen" class="" x-cloak>
    <div class="inline-flex items-center p-2 pb-6 mx-0.5 text-sm font-medium transition text-white/80">
      <span>Versiunea: 1.6.0</span>
    </div>
  </div>
</aside>
