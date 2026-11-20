<?php osc_current_web_theme_path('header.php'); ?>
<h1><?php echo osc_esc_html(osc_item_title()); ?></h1>
<p><?php echo osc_esc_html(osc_item_formatted_price()); ?> <?php echo osc_esc_html(osc_item_category()); ?></p>
<div><?php echo osc_item_description(); ?></div>
<dl><?php while (osc_has_item_meta()) { ?><dt><?php echo osc_esc_html(osc_item_meta_name()); ?></dt><dd><?php echo osc_item_meta_value(); ?></dd><?php } ?></dl>
<?php while (osc_has_item_resources()) { ?><img src="<?php echo osc_esc_html(osc_resource_thumbnail_url()); ?>" alt=""><?php } ?>
<?php osc_run_hook('item_detail', osc_item()); ?>
<?php osc_current_web_theme_path('footer.php'); ?>
