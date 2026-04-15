<?php
/* Template Name: Setări Admin */
if (!is_user_logged_in()) { wp_redirect(home_url('/login')); exit; }
if (!current_user_can('administrator')) { wp_redirect(home_url('/')); exit; }

$current_user = wp_get_current_user();
$user_id      = (int) $current_user->ID;

/** Helpers */
function es_clean_phone($s){ return preg_replace('/[^0-9+]/', '', (string)$s); }

/** Meta comune */
$profile_image_id = get_user_meta($user_id, 'profile_image', true);
$member_since     = $current_user->user_registered ? date_i18n(get_option('date_format'), strtotime($current_user->user_registered)) : '—';

/** Flash */
$flash = ['type'=>null,'msg'=>null];

/** === HANDLE FORM ACTIONS === */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Update profil (prenume, nume, email, telefon, poză)
  if (isset($_POST['update_profile']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'admin_update_profile')) {
    $first = sanitize_text_field($_POST['first_name'] ?? '');
    $last  = sanitize_text_field($_POST['last_name'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $phone = es_clean_phone($_POST['phone'] ?? '');

    // Validare email
    if (!$email || !is_email($email)) {
      $flash = ['type'=>'error','msg'=>'Adresa de email nu este validă.'];
    } else {
      // Dacă se schimbă emailul, verificăm dacă nu e deja folosit
      if (strtolower($email) !== strtolower($current_user->user_email)) {
        $u = get_user_by('email', $email);
        if ($u && (int)$u->ID !== $user_id) {
          $flash = ['type'=>'error','msg'=>'Emailul introdus este deja folosit de alt cont.'];
        }
      }
    }

    if (!$flash['type']) {
      $res = wp_update_user([
        'ID'         => $user_id,
        'first_name' => $first,
        'last_name'  => $last,
        'user_email' => $email,
      ]);
      if (is_wp_error($res)) {
        $flash = ['type'=>'error','msg'=>'A apărut o eroare la actualizarea profilului: '.$res->get_error_message()];
      } else {
        if (function_exists('update_field')) {
          update_field('telefon', $phone, 'user_' . $user_id);
        } else {
          update_user_meta($user_id, 'telefon', $phone);
        }
        // Upload imagine profil
        if (!empty($_FILES['profile_image']['name'])) {
          require_once ABSPATH . 'wp-admin/includes/file.php';
          require_once ABSPATH . 'wp-admin/includes/image.php';
          require_once ABSPATH . 'wp-admin/includes/media.php';
          $overrides = ['test_form' => false];
          $file = wp_handle_upload($_FILES['profile_image'], $overrides);
          if (!isset($file['error'])) {
            $attachment = [
              'post_mime_type' => $file['type'],
              'post_title'     => sanitize_file_name(basename($file['file'])),
              'post_content'   => '',
              'post_status'    => 'inherit'
            ];
            $attach_id = wp_insert_attachment($attachment, $file['file']);
            if (!is_wp_error($attach_id)) {
              $attach_data = wp_generate_attachment_metadata($attach_id, $file['file']);
              wp_update_attachment_metadata($attach_id, $attach_data);
              update_user_meta($user_id, 'profile_image', $attach_id);
              $profile_image_id = $attach_id;
            }
          } else {
            $flash = ['type' => 'error', 'msg' => 'Eroare la încărcarea imaginii: ' . esc_html($file['error'])];
          }
        }

        if (!$flash['type']) {
          // Refresh date curente pentru afișare
          $current_user = get_user_by('id', $user_id);
          $flash = ['type'=>'success','msg'=>'Datele au fost actualizate.'];
        }
      }
    }
  }

  // Schimbă parola
  if (isset($_POST['change_password']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'admin_change_password')) {
    $pass    = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if (strlen($pass) < 8) {
      $flash = ['type'=>'error','msg'=>'Parola trebuie să aibă cel puțin 8 caractere.'];
    } elseif ($pass !== $confirm) {
      $flash = ['type'=>'error','msg'=>'Parolele nu coincid.'];
    } else {
      wp_set_password($pass, $user_id);
      $flash = ['type'=>'success','msg'=>'Parola a fost schimbată. Te poți reautentifica dacă ți se solicită.'];
    }
  }
}
?>
<?php if ($flash['type']): ?>
  <script>window.__FLASH__ = <?= wp_json_encode($flash, JSON_UNESCAPED_UNICODE); ?>;</script>
<?php endif; ?>

<section class="w-full px-6 pb-8 my-6">
  <div class="relative overflow-hidden shadow-sm bg-gradient-to-r from-slate-700 to-slate-900 rounded-2xl">
    <div class="relative p-4">
      <div class="flex flex-col gap-6 lg:flex-row lg:items-center">
        <!-- Avatar -->
        <div class="shrink-0">
          <div class="overflow-hidden rounded-2xl">
            <?php if ($profile_image_id): ?>
              <img id="profilePreview" src="<?= esc_url(wp_get_attachment_image_url($profile_image_id, 'medium')); ?>" class="object-cover size-24" alt="Profil">
            <?php else: ?>
              <img id="profilePreview" src="<?= esc_url(get_template_directory_uri().'/assets/images/default-teach.svg'); ?>" class="object-cover size-24" alt="Profil">
            <?php endif; ?>
          </div>
        </div>

        <!-- Nume, rol, meta -->
        <div class="flex-1 min-w-0">
          <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold text-white truncate lg:text-2xl">
              <?= esc_html(trim(($current_user->first_name ?: $current_user->display_name).' '.$current_user->last_name)); ?>
            </h1>
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-slate-100 text-slate-800 ring-1 ring-inset ring-slate-200">Administrator</span>
          </div>
          <div class="flex mt-2 text-sm text-white gap-x-6 gap-y-2 mobile:flex-col">
            <div class="flex items-center gap-2">
              <svg class="text-white size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M2 6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v.511L12 12 2 6.511V6Z"/><path d="M2 8.489V18a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8.489l-9.445 5.523a2 2 0 0 1-2.11 0L2 8.489Z"/></svg>
              <a class="hover:underline" href="mailto:<?= esc_attr($current_user->user_email); ?>"><?= esc_html($current_user->user_email); ?></a>
            </div>
            <div class="flex items-center gap-2">
              <svg class="text-white size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-5.33 0-8 2.667-8 6a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1c0-3.333-2.67-6-8-6Z"/></svg>
              <span>ID utilizator: <strong>#<?= (int) $user_id; ?></strong></span>
            </div>
            <div class="flex items-center gap-2">
              <svg class="text-white size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M7 4h10a2 2 0 0 1 2 2v11l-3-2-3 2-3-2-3 2V6a2 2 0 0 1 2-2Z"/></svg>
              <span>Membru din: <strong><?= esc_html($member_since); ?></strong></span>
            </div>
          </div>
        </div>

        <!-- Placeholder statistici -->
        <div class="grid grid-cols-2 overflow-hidden divide-x divide-slate-200 rounded-xl bg-slate-50 ring-1 ring-slate-200">
          <div class="px-4 py-3 text-center">
            <div class="text-xs text-slate-500">Rol</div>
            <div class="text-sm font-semibold">Administrator</div>
          </div>
          <div class="px-4 py-3 text-center">
            <div class="text-xs text-slate-500">Ultima logare</div>
            <div class="text-sm font-semibold">—</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="relative w-full p-0 mb-12">
  <div class="max-w-5xl mx-auto space-y-8">

    <!-- ===== Card: Date personale ===== -->
    <div class="bg-white border shadow-sm rounded-2xl border-slate-200">
      <div class="px-6 py-4 border-b border-slate-100">
        <h2 class="text-sm font-semibold tracking-wide uppercase text-slate-700">Date personale</h2>
        <p class="mt-1 text-xs text-slate-500"><?= esc_html($current_user->first_name ?: $current_user->display_name); ?>, aici poți gestiona setările contului tău de administrator.</p>
      </div>

      <form method="post" enctype="multipart/form-data" class="px-6 py-6">
        <?php wp_nonce_field('admin_update_profile'); ?>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
          <!-- Prenume -->
          <div>
            <label class="block mb-2 text-xs font-semibold uppercase text-slate-600">Prenume</label>
            <div class="relative">
              <input type="text" name="first_name" value="<?= esc_attr($current_user->first_name) ?>" class="w-full px-3 py-2 pl-10 text-sm border shadow-sm rounded-xl border-slate-300 hover:border-slate-400 focus:border-slate-700 focus:ring-2 focus:ring-slate-700/30">
              <span class="absolute inset-y-0 left-0 flex items-center justify-center pointer-events-none w-9 text-slate-400">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/><path d="M4.5 20.118a7.5 7.5 0 0 1 15 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.5-1.632Z"/></svg>
              </span>
            </div>
          </div>

          <!-- Nume -->
          <div>
            <label class="block mb-2 text-xs font-semibold uppercase text-slate-600">Nume</label>
            <div class="relative">
              <input type="text" name="last_name" value="<?= esc_attr($current_user->last_name) ?>" class="w-full px-3 py-2 pl-10 text-sm border shadow-sm rounded-xl border-slate-300 hover:border-slate-400 focus:border-slate-700 focus:ring-2 focus:ring-slate-700/30">
              <span class="absolute inset-y-0 left-0 flex items-center justify-center pointer-events-none w-9 text-slate-400">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/><path d="M4.5 20.118a7.5 7.5 0 0 1 15 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.5-1.632Z"/></svg>
              </span>
            </div>
          </div>

          <!-- Email (editabil) -->
          <div>
            <label class="block mb-2 text-xs font-semibold uppercase text-slate-600">Email</label>
            <div class="relative">
              <input type="email" name="email" value="<?= esc_attr($current_user->user_email) ?>" class="w-full px-3 py-2 pl-10 text-sm border shadow-sm rounded-xl border-slate-300 hover:border-slate-400 focus:border-slate-700 focus:ring-2 focus:ring-slate-700/30">
              <span class="absolute inset-y-0 left-0 flex items-center justify-center pointer-events-none w-9 text-slate-400">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 7l9 6 9-6"/><path d="M21 7v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7"/></svg>
              </span>
            </div>
          </div>

          <!-- Telefon -->
          <div>
            <label class="block mb-2 text-xs font-semibold uppercase text-slate-600">Telefon</label>
            <div class="relative">
              <input type="text" name="phone" value="<?= esc_attr(function_exists('get_field') ? get_field('telefon', 'user_' . $user_id) : get_user_meta($user_id, 'telefon', true)) ?>" class="w-full px-3 py-2 pl-10 text-sm border shadow-sm rounded-xl border-slate-300 hover:border-slate-400 focus:border-slate-700 focus:ring-2 focus:ring-slate-700/30" placeholder="+407xx xxx xxx">
              <span class="absolute inset-y-0 left-0 flex items-center justify-center pointer-events-none w-9 text-slate-400">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 16.92v2a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 3.1 12.81 19.79 19.79 0  0 1 .03 4.18 2 2 0 0 1 2 2h2.09a2 2 0 0 1 2 1.72c.12.9.32 1.77.59 2.61a2 2 0 0 1-.45 2.11L5.4 9.91a16 16 0 0 0 6.69 6.69l1.47-1.83a2 2 0 0 1 2.11-.45c.84.27 1.71.47 2.61.59A2 2 0 0 1 22 16.92Z"/></svg>
              </span>
            </div>
          </div>

          <!-- Imagine profil -->
          <div class="md:col-span-2">
            <label class="block mb-2 text-xs font-semibold uppercase text-slate-600">Imagine de profil</label>
            <input type="file" name="profile_image" id="profile_image" class="block w-full cursor-pointer rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-sm file:mr-4 file:rounded-md file:border-0 file:bg-slate-800 file:px-3 file:py-1.5 file:text-white hover:border-slate-400" />
            <p class="mt-2 text-xs text-slate-500">PNG/JPG recomandat, max ~5MB</p>
          </div>
        </div>

        <div class="flex items-center justify-end gap-2 mt-6">
          <button type="submit" name="update_profile" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white rounded-xl bg-slate-800 hover:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-700/40">
            Salvează datele
          </button>
        </div>
      </form>
    </div>

    <!-- ===== Card: Schimbă parola ===== -->
    <div class="bg-white border shadow-sm rounded-2xl border-slate-200">
      <div class="px-6 py-4 border-b border-slate-100">
        <h2 class="text-sm font-semibold tracking-wide uppercase text-slate-700">Schimbă parola</h2>
      </div>

      <form method="post" class="px-6 py-6">
        <?php wp_nonce_field('admin_change_password'); ?>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
          <!-- Parola nouă -->
          <div>
            <label class="block mb-2 text-xs font-semibold uppercase text-slate-600">Parola nouă</label>
            <div class="relative">
              <input id="new_password" type="password" name="new_password" placeholder="Parolă nouă" class="w-full px-3 py-2 pr-10 text-sm border shadow-sm rounded-xl border-slate-300 hover:border-slate-400 focus:border-slate-700 focus:ring-2 focus:ring-slate-700/30">
              <button type="button" data-toggle="#new_password" class="absolute inset-y-0 right-0 flex items-center justify-center w-9 text-slate-400 hover:text-slate-600" aria-label="Afișează/ascunde parola">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12Z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            <!-- Strength meter -->
            <div class="flex items-center gap-2 mt-2">
              <div class="w-full h-1 overflow-hidden rounded bg-slate-200">
                <div id="pw-bar" class="w-0 h-full transition-all bg-rose-500"></div>
              </div>
              <span id="pw-label" class="text-xs font-medium text-rose-600 whitespace-nowrap">Foarte slabă</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Minim 8 caractere. Recomandat: litere mari/mici, cifre și simboluri.</p>
          </div>

          <!-- Confirmare -->
          <div>
            <label class="block mb-2 text-xs font-semibold uppercase text-slate-600">Repetă parola</label>
            <div class="relative">
              <input id="confirm_password" type="password" name="confirm_password" placeholder="Repetă parola" class="w-full px-3 py-2 pr-10 text-sm border shadow-sm rounded-xl border-slate-300 hover:border-slate-400 focus:border-slate-700 focus:ring-2 focus:ring-slate-700/30">
              <button type="button" data-toggle="#confirm_password" class="absolute inset-y-0 right-0 flex items-center justify-center w-9 text-slate-400 hover:text-slate-600" aria-label="Afișează/ascunde parola">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12Z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            <div class="mt-2 text-xs font-medium" id="pw-match">—</div>
          </div>
        </div>

        <div class="flex items-center justify-end mt-6">
          <button type="submit" name="change_password" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white rounded-xl bg-slate-800 hover:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-700/40">
            Actualizează parola
          </button>
        </div>
      </form>
    </div>

  </div>
</div>

<!-- Toast root -->
<div id="toast-root" class="fixed z-50 space-y-2 top-4 right-4"></div>

<!-- ===== JS: preview imagine + toggle password + strength + toasts ===== -->
<script>
(function(){
  // Preview imagine
  const file = document.getElementById('profile_image');
  const preview = document.getElementById('profilePreview');
  if (file && preview) {
    file.addEventListener('change', (e) => {
      const f = e.target.files && e.target.files[0];
      if (!f) return;
      const url = URL.createObjectURL(f);
      preview.src = url;
    });
  }

  // Toggle parola
  document.querySelectorAll('[data-toggle]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const sel = btn.getAttribute('data-toggle');
      const input = document.querySelector(sel);
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
    });
  });

  // Password strength
  const pw   = document.getElementById('new_password');
  const conf = document.getElementById('confirm_password');
  const bar  = document.getElementById('pw-bar');
  const label= document.getElementById('pw-label');
  const match= document.getElementById('pw-match');

  function scorePassword(s) {
    if (!s) return 0;
    let score = 0;
    const len = s.length;
    const sets = [
      /[a-z]/.test(s),
      /[A-Z]/.test(s),
      /\d/.test(s),
      /[^A-Za-z0-9]/.test(s),
    ].filter(Boolean).length;
    score += Math.min(3, Math.floor(len / 3)); // 0..3
    score += sets; // 0..4
    const common = ['password','parola','123456','qwerty','admin'];
    if (common.some(c => s.toLowerCase().includes(c))) score = Math.max(0, score-2);
    return Math.min(7, score); // 0..7
  }

  function paintStrength() {
    const val = pw ? pw.value : '';
    const sc  = scorePassword(val); // 0..7
    const pct = [0, 20, 35, 50, 65, 80, 92, 100][sc] + '%';
    const states = [
      {t:'Foarte slabă', c:'bg-rose-500',    tc:'text-rose-600'},
      {t:'Slabă',        c:'bg-rose-500',    tc:'text-rose-600'},
      {t:'Modestă',      c:'bg-amber-400',   tc:'text-amber-600'},
      {t:'Acceptabilă',  c:'bg-amber-400',   tc:'text-amber-600'},
      {t:'Bună',         c:'bg-emerald-500', tc:'text-emerald-600'},
      {t:'Foarte bună',  c:'bg-emerald-600', tc:'text-emerald-600'},
      {t:'Excelentă',    c:'bg-emerald-700', tc:'text-emerald-700'},
      {t:'Excelentă',    c:'bg-emerald-700', tc:'text-emerald-700'},
    ][sc];

    if (bar)   { bar.className = 'h-full w-0 transition-all ' + states.c; bar.style.width = pct; }
    if (label) { label.textContent = states.t; label.className = 'text-xs font-medium ' + states.tc; }

    if (conf) {
      const ok  = !!val && conf.value && (val === conf.value);
      const cls = ok ? 'text-emerald-600' : (conf.value ? 'text-rose-600' : 'text-slate-500');
      match.className = 'mt-2 text-xs font-medium ' + cls;
      match.textContent = ok ? 'Parolele coincid.' : (conf.value ? 'Parolele nu coincid.' : '—');
    }
  }

  if (pw)   pw.addEventListener('input', paintStrength);
  if (conf) conf.addEventListener('input', paintStrength);
  paintStrength();

  // Toasts
  const root = document.getElementById('toast-root');
  function showToast(type, msg, timeout=3200) {
    if (!root) return;
    const base = document.createElement('div');
    const palette = type === 'success'
      ? {wrap:'bg-white border-emerald-200', bar:'bg-emerald-500', title:'text-emerald-700'}
      : {wrap:'bg-white border-rose-200',    bar:'bg-rose-500',    title:'text-rose-700'};
    base.className = 'toast-item pointer-events-auto relative w-80 overflow-hidden rounded-xl border shadow-lg ring-1 ring-black/5 translate-y-[-8px] opacity-0 transition-all';
    base.innerHTML = `
      <div class="absolute left-0 top-0 h-full w-1 ${palette.bar}"></div>
      <div class="p-3 ${palette.wrap}">
        <div class="flex items-start gap-3">
          <div class="mt-0.5">
            ${type==='success'
              ? '<svg class="size-5 text-emerald-600" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>'
              : '<svg class="size-5 text-rose-600" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 1 0 10 10A10.012 10.012 0 0 0 12 2Zm1 15h-2v-2h2Zm0-4h-2V7h2Z"/></svg>'
            }
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold ${palette.title}">${type==='success'?'Succes':'Eroare'}</div>
            <div class="mt-0.5 text-sm text-slate-700 break-words">${msg}</div>
          </div>
          <button class="ml-auto text-slate-400 hover:text-slate-600" aria-label="Închide" onclick="this.closest('.toast-item').remove()">✕</button>
        </div>
      </div>
    `;
    root.appendChild(base);
    requestAnimationFrame(()=>{ base.style.opacity = '1'; base.style.transform = 'translateY(0)'; });
    setTimeout(()=>{ base.style.opacity = '0'; base.style.transform = 'translateY(-8px)'; setTimeout(()=> base.remove(), 250); }, timeout);
  }

  if (window.__FLASH__ && window.__FLASH__.type && window.__FLASH__.msg) {
    showToast(window.__FLASH__.type, window.__FLASH__.msg);
  }
})();
</script>
