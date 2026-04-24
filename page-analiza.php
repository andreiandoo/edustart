<?php
/* Template Name: Analiza */
include get_template_directory() . '/partials/logged-styles.php';
?>

<div x-data="{ sidebarOpen: true }" class="<?php echo $main_classes; ?>">
  <?php include get_template_directory() . '/partials/dashboard-sidebar.php'; ?>

  <main :class="sidebarOpen ? 'w-full' : 'w-[calc(100%-4rem)]'" class="<?php echo $sidebar_classes; ?>">
    <?php include get_template_directory() . '/partials/dashboard-topbar.php'; ?>

    <div class="<?php echo $panel_classes; ?>">
      <?php include get_template_directory() . '/partials/panou-analiza.php'; ?>
      <?php get_footer('blank'); ?>
