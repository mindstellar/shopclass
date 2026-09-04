<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The account section nav, shared by every core account partial.
 *
 * Built from the same `user_menu_filter` list `osc_private_user_menu()` passes
 * through, so a plugin that already adds an account entry -- core's own billing
 * links among them -- appears here too, and the `user_menu` hook still fires.
 *
 * Rendered here rather than by calling that helper because the helper emits its
 * own `<ul class="user_menu">` and an inline script; this page has a documented
 * class vocabulary of its own and needs no script to mark the current entry.
 */

$navItems = array(
    array('name' => _m('Dashboard'), 'url' => osc_user_dashboard_url(), 'class' => 'opt_dashboard'),
    array('name' => _m('Your listings'), 'url' => osc_user_list_items_url(), 'class' => 'opt_items'),
    array('name' => _m('Alerts'), 'url' => osc_user_alerts_url(), 'class' => 'opt_alerts'),
    array('name' => _m('Public profile'),
          'url' => osc_user_public_profile_url(osc_logged_user_id()), 'class' => 'opt_publicprofile'),
    array('name' => _m('Profile'), 'url' => osc_user_profile_url(), 'class' => 'opt_account'),
    array('name' => _m('Email address'), 'url' => osc_change_user_email_url(), 'class' => 'opt_change_email'),
    array('name' => _m('Password'), 'url' => osc_change_user_password_url(), 'class' => 'opt_change_password'),
    array('name' => _m('Username'), 'url' => osc_change_user_username_url(), 'class' => 'opt_change_username'),
);

$deleteUrl = osc_user_delete_url();
if ($deleteUrl !== '') {
    $navItems[] = array('name' => _m('Delete your account'), 'url' => $deleteUrl, 'class' => 'opt_delete_account');
}
$navItems[] = array('name' => _m('Log out'), 'url' => osc_user_logout_url(), 'class' => 'opt_logout');

$navItems = osc_apply_filter('user_menu_filter', $navItems);
if (!is_array($navItems) || $navItems === array()) {
    return;
}

// Same rule as osc_private_user_menu(): appending is the natural way for a
// plugin to add an entry, which would otherwise push log out into the middle.
foreach ($navItems as $navKey => $navItem) {
    if (isset($navItem['class']) && $navItem['class'] === 'opt_logout') {
        unset($navItems[$navKey]);
        $navItems[] = $navItem;
        break;
    }
}
$navItems = array_values($navItems);

$navCurrent = '';
if (osc_is_user_dashboard()) {
    $navCurrent = 'opt_dashboard';
} elseif (osc_is_list_items()) {
    $navCurrent = 'opt_items';
} elseif (osc_is_list_alerts()) {
    $navCurrent = 'opt_alerts';
} elseif (osc_is_user_profile()) {
    $navCurrent = 'opt_account';
} elseif (osc_is_change_email_page()) {
    $navCurrent = 'opt_change_email';
} elseif (osc_is_change_password_page()) {
    $navCurrent = 'opt_change_password';
} elseif (osc_is_change_username_page()) {
    $navCurrent = 'opt_change_username';
} elseif (osc_is_current_page('user', 'delete')) {
    $navCurrent = 'opt_delete_account';
}
?>
<nav class="oe-account-nav" aria-label="<?php echo osc_esc_html(_m('Your account')); ?>">
    <h2><?php echo osc_esc_html(_m('Your account')); ?></h2>
    <ul>
        <?php foreach ($navItems as $navItem) {
            if (empty($navItem['name']) || empty($navItem['url'])) {
                continue;
            }
            $navClass = isset($navItem['class']) ? (string) $navItem['class'] : '';
            $isHere   = $navClass !== '' && $navClass === $navCurrent;
            ?>
            <li class="<?php echo osc_esc_html($navClass); ?>">
                <a href="<?php echo osc_esc_html($navItem['url']); ?>"
                   <?php echo $isHere ? 'aria-current="page"' : ''; ?>><?php
                    echo osc_esc_html($navItem['name']); ?></a>
            </li>
        <?php } ?>
    </ul>
    <?php osc_run_hook('user_menu'); ?>
</nav>
