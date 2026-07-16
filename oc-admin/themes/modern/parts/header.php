<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
// This admin's saved light/dark choice, emitted on <html> so Bootstrap 5.3 has it before
// first paint — no flash. Stored per admin id in t_preference (Osclass has no admin-meta
// table); '' when never set, so the default is light. Whitelisted on the way out too, not
// only on the way in, since a hand-edited preference row is still untrusted input.
$oscAdminTheme = osc_get_preference((string) osc_logged_admin_id(), 'admin_theme');
if ($oscAdminTheme !== 'dark' && $oscAdminTheme !== 'light') {
    $oscAdminTheme = 'light';
}
// Same store, same reasoning as the theme above: this admin's collapsed/expanded
// sidebar choice, emitted on <html> before first paint so the rail never flashes
// open on load. Default expanded; whitelisted on the way out, not only in.
$oscSidebar = osc_get_preference((string) osc_logged_admin_id(), 'admin_sidebar');
if ($oscSidebar !== 'collapsed') {
    $oscSidebar = 'expanded';
} ?>
<!DOCTYPE html>
<html lang="<?php echo substr(osc_current_admin_locale(), 0, 2); ?>" data-bs-theme="<?php echo $oscAdminTheme; ?>" data-osc-sidebar="<?php echo $oscSidebar; ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo osc_apply_filter('admin_title', osc_page_title() . ' - Osclass'); ?></title>
    <meta name="title" content="<?php echo osc_apply_filter('admin_title', osc_page_title() . ' - Osclass'); ?>"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="content-language" content="<?php echo osc_current_admin_locale(); ?>"/>
    <meta name="apple-mobile-web-app-capable" content="yes"/>
    <?php osc_run_hook('admin_header'); ?>
</head>
<body class="<?php echo implode(' ', osc_apply_filter('admin_body_class', array())); ?>">
<div id="content" class="container-fluid">
    <div class="row flex-nowrap">
        <nav id="sidebar-wrapper" class="col-auto dashboard-sidebar">
            <a id="osc_toolbar_home" class="sidebar-brand" target="_blank" rel="noopener"
               href="<?php echo osc_base_url(); ?>"
               title="<?php echo osc_esc_html(__('View your site')); ?>">
                <i class="bi bi-house-door-fill" aria-hidden="true"></i>
                <span class="sidebar-brand-label"><?php echo osc_esc_html(osc_page_title()); ?></span>
            </a>
            <div class="sidebar-scroll-frame">
                <div class="sidebar-scroll">
                    <?php osc_draw_admin_menu(); ?>
                </div>
            </div>
            <div class="sidebar-footer">
                <button type="button" id="oscSidebarToggle" class="sidebar-collapse-toggle"
                        aria-controls="sidebar-wrapper"
                        aria-expanded="<?php echo $oscSidebar === 'collapsed' ? 'false' : 'true'; ?>"
                        aria-label="<?php echo osc_esc_html(__('Collapse or expand the menu')); ?>">
                    <i class="bi bi-chevron-double-left" aria-hidden="true"></i>
                    <span class="sidebar-collapse-toggle-label"><?php _e('Collapse'); ?></span>
                </button>
            </div>
        </nav>
        <main id="content-render" class="col">
            <header id="header" class="admin-topbar navbar navbar-expand-md">
                <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar-wrapper"
                        aria-label="<?php echo osc_esc_html(__('Menu')); ?>">
                    <div class="toggle-icon"><span></span><span></span><span></span></div>
                </button>
                <ul class="navbar-nav admin-topbar-tools ms-auto">
                    <?php AdminToolbar::newInstance()->render(); ?>
                    <li class="nav-item admin-topbar-divider" aria-hidden="true"></li>
                    <li class="nav-item">
                        <button type="button" id="oscThemeToggle" class="admin-topbar-btn"
                                title="<?php echo osc_esc_html(__('Toggle dark mode')); ?>"
                                aria-label="<?php echo osc_esc_html(__('Toggle dark mode')); ?>">
                            <i class="bi <?php echo $oscAdminTheme === 'dark' ? 'bi-sun-fill' : 'bi-moon-stars-fill'; ?>" aria-hidden="true"></i>
                        </button>
                    </li>
                    <li class="nav-item dropdown admin-topbar-account">
                        <button type="button" id="userDropdown" class="admin-topbar-btn admin-topbar-account-btn"
                                data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"
                                title="<?php echo osc_esc_html(__('User Menu')); ?>">
                            <i class="bi bi-person-circle admin-topbar-avatar" aria-hidden="true"></i>
                            <span class="admin-topbar-account-name"><?php echo osc_esc_html(osc_logged_admin_username()); ?></span>
                            <i class="bi bi-chevron-down admin-topbar-account-caret" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <div class="admin-topbar-account-head">
                                <i class="bi bi-person-circle" aria-hidden="true"></i>
                                <div class="admin-topbar-account-head-text">
                                    <span class="admin-topbar-account-head-name"><?php echo osc_esc_html(osc_logged_admin_name() ?: osc_logged_admin_username()); ?></span>
                                    <span class="admin-topbar-account-head-mail"><?php echo osc_esc_html(osc_logged_admin_email()); ?></span>
                                </div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item"
                               href="<?php echo osc_admin_base_url(true) . '?page=admins&action=edit&id=' . osc_logged_admin_id(); ?>">
                                <i class="bi bi-person-lines-fill"></i> <?php _e('Edit Profile'); ?></a>
                            <a class="dropdown-item" href="<?php echo osc_admin_base_url(true).'?page=settings'?>">
                                <i class="bi bi-gear-fill"></i> <?php _e('Settings'); ?></a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="<?php echo osc_admin_base_url(true) . '?action=logout'; ?>">
                                <i class="bi bi-box-arrow-right"></i> <?php _e('Sign out') ?></a>
                        </div>
                    </li>
                </ul>
            </header>
            <div id="content-head" class="row">
                <div class="col">
                    <?php osc_run_hook('admin_page_header'); ?>
                </div>
            </div>
            <div id="help-wrapper" class="row">
                <div class="col">
                    <div id="help-box" class="pt-2 collapse">
                        <div class="help-callout" role="note">
                            <i class="bi bi-info-circle help-callout-icon" aria-hidden="true"></i>
                            <div class="help-callout-body">
                                <?php osc_run_hook('help_box'); ?>
                            </div>
                            <button type="button" class="help-callout-close" data-bs-toggle="collapse" href="#help-box"
                                    aria-label="<?php echo osc_esc_html(__('Close')); ?>">
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div id="message-wrapper" class="row">
                <div class="col">
                    <?php osc_show_flash_message('admin'); ?>
                    <div id="jsMessage" class="jsMessage flashmessage flashmessage-info hide shadow-sm">
                        <a class="btn ico btn-mini ico-close">×</a>
                        <p></p>
                    </div>
                </div>
            </div>
            <div id="content-page" class="row">
                <div class="col">
                    <div class="row-wrapper <?php echo osc_apply_filter('render-wrapper', ''); ?>">
