<?php
/* Template Name: Login Page */
// if (is_user_logged_in()) {
//   wp_redirect(home_url('/panou'));
//   exit;
// }
get_header('blank'); ?>

<style>
  html, body {
    height: 100%;
    overflow: hidden;
  }
</style>

<div class="flex flex-col h-screen overflow-hidden md:flex-row">
  <!-- Left: Form -->
  <div class="flex items-center justify-center w-full p-8 bg-white md:w-1/2">
    <div class="w-full max-w-md space-y-6">
      <!-- Logo + Titlu -->
      <div class="text-center">
        <div class="flex items-center justify-center applogo">
          <a href="<?php echo get_site_url();?>" class="flex items-center mb-1 text-3xl leading-5 text-sky-800 gap-x-1 group"><span class="font-bold">edu</span><span class="mb-1 text-2xl italic transition-all duration-150 ease-in-out text-amber-500 group-hover:scale-110">start</span></a>
        </div>
        <h1 class="text-2xl font-bold text-gray-800">Bun venit! </h1>
        <p class="mt-2 text-sm text-gray-600">Folosește datele contului tău pentru a te loga pe platforma EduStart.</p>
      </div>

      <?php if (!empty($_GET['password']) && $_GET['password'] === 'changed'): ?>
        <div class="p-3 text-sm text-center border rounded-lg text-emerald-800 bg-emerald-50 border-emerald-200">
          Parola a fost setata cu succes. Te poti autentifica acum.
        </div>
      <?php endif; ?>

      <!-- Form login -->
      <form method="post" action="<?php echo wp_login_url( home_url('/panou') ); ?>" class="space-y-4">
        <div>
          <label for="user_login" class="sr-only">Email</label>
          <input id="user_login" name="log" type="text" required placeholder="Emailul tău"
            class="w-full px-4 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
        </div>

        <div>
          <label for="user_pass" class="sr-only">Parolă</label>
          <input id="user_pass" name="pwd" type="password" required placeholder="Parolă"
            class="w-full px-4 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
        </div>

        <div class="text-center">
          <a href="<?php echo wp_lostpassword_url(); ?>" class="text-sm text-es-orange hover:underline">Ai uitat parola?</a>
        </div>

        <button type="submit"
          class="w-full py-2 text-sm font-medium text-white transition duration-200 rounded-lg bg-es-orange hover:bg-purple-700">
          Intra in cont
        </button>
      </form>

      <!-- Separator -->
      <div class="flex items-center space-x-2 text-gray-400">
        <div class="flex-1 h-px bg-gray-300"></div>
        <div class="text-sm font-light">sau</div>
        <div class="flex-1 h-px bg-gray-300"></div>
      </div>

      <!-- Înregistrare -->
      <div class="text-sm text-center text-gray-600">
        Nu ai cont?
        <a href="<?php echo wp_registration_url(); ?>" class="font-medium text-es-orange hover:underline">Click aici</a>
      </div>
    </div>
  </div>

  <!-- Right: Image -->
  <div class="hidden md:block md:w-1/2" style="background-image: url('<?php echo get_stylesheet_directory_uri(); ?>/resources/images/bg-login.jpg'); background-size: cover; background-position: center;">
  </div>
</div>

<?php //get_footer('blank'); ?>
