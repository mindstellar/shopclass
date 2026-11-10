<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
$oscNoticeView  = osc_admin_notices();
$oscNoticeCount = $oscNoticeView->count();
?>
<li class="nav-item osc-notice-bell"
    data-osc-notice-url="<?php echo osc_esc_html(osc_admin_base_url(true) . '?page=ajax'); ?>"
    data-osc-notice-token="<?php echo osc_esc_html(osc_csrf_token_url()); ?>"
    data-osc-notice-hidden="<?php echo osc_esc_html(__('Hidden for 7 days.')); ?>"
    data-osc-notice-shown="<?php echo osc_esc_html(__('Shown again.')); ?>"
    data-osc-notice-fail="<?php echo osc_esc_html(__('The notice could not be changed. Reload the page and try again.')); ?>">
    <button type="button" id="oscNoticeBell" class="admin-topbar-btn"
            aria-haspopup="dialog" aria-expanded="false" aria-controls="oscNoticePanel"
            aria-label="<?php echo osc_esc_html(osc_admin_notice_name($oscNoticeCount, $oscNoticeView->actionCount(), true)); ?>"
            title="<?php echo osc_esc_html(__('Notices')); ?>">
        <i class="bi bi-bell" aria-hidden="true"></i>
        <?php if ($oscNoticeCount > 0) { ?>
        <i class="circle <?php echo $oscNoticeView->actionCount() > 0 ? 'circle-red' : 'circle-gray'; ?>" aria-hidden="true"><?php echo $oscNoticeCount; ?></i>
        <?php } ?>
    </button>
    <section id="oscNoticePanel" class="osc-notice-panel" role="dialog" aria-labelledby="oscNoticeTitle" tabindex="-1">
        <?php require __DIR__ . '/notices-panel.php'; ?>
    </section>
    <div class="osc-notice-backdrop"></div>
    <script src="<?php echo osc_esc_html(osc_asset_url_versioned(osc_current_admin_theme_js_url('notices.js'))); ?>" defer></script>
</li>
