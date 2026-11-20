<footer><?php foreach (osc_menu('footer') as $link) { ?><a href="<?php echo osc_esc_html($link['url']); ?>"><?php echo osc_esc_html($link['label']); ?></a><?php } ?>
<p><?php echo osc_esc_html(osc_page_description()); ?></p></footer>
<?php osc_run_hook('footer'); ?>
</body></html>
