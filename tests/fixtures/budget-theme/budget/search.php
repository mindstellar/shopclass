<?php osc_current_web_theme_path('header.php'); ?>
<h1><?php echo (int) osc_search_total_items(); ?></h1>
<ul><?php while (osc_has_items()) {
budget_listing();
} ?></ul>
<?php echo osc_search_pagination(); ?>
<?php osc_current_web_theme_path('footer.php'); ?>
