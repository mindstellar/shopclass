<!doctype html>
<html lang="<?php echo osc_esc_html(osc_current_user_locale()); ?>">
<head><?php osc_run_hook('header'); ?></head>
<body>
<header><a href="<?php echo osc_esc_html(osc_base_url()); ?>"><?php echo osc_esc_html(osc_page_title()); ?></a>
<nav><?php foreach (osc_menu('main') as $link) { ?><a href="<?php echo osc_esc_html($link['url']); ?>"><?php echo osc_esc_html($link['label']); ?></a><?php } ?></nav>
</header>
<?php osc_show_flash_message(); ?>
