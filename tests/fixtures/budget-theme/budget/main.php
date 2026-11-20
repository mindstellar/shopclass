<?php osc_current_web_theme_path('header.php'); ?>
<ul><?php while (osc_has_categories()) { ?><li><a href="<?php echo osc_esc_html(osc_search_category_url()); ?>"><?php echo osc_esc_html(osc_category_name()); ?></a> <?php echo (int) osc_category_total_items(); ?></li><?php } ?></ul>
<ul><?php osc_get_premiums(4);
while (osc_has_premiums()) {
echo '<li>', osc_esc_html(osc_premium_title()), ' ', osc_esc_html(osc_premium_formated_price()), '</li>';
} ?></ul>
<ul><?php while (osc_has_latest_items()) {
budget_listing();
} ?></ul>
<?php osc_current_web_theme_path('footer.php'); ?>
