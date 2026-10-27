<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The account section nav, shared by every core account partial. The list comes from
 * osc_account_nav_items(), and the `user_menu` hook still fires inside it.
 */

$navItems = osc_account_nav_items();
if ($navItems === array()) {
    return;
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
            $isHere   = !empty($navItem['current']);
            ?>
            <li class="<?php echo osc_esc_html($navClass); ?>">
                <a href="<?php echo osc_esc_html($navItem['url']); ?>"
                   <?php echo $isHere ? 'aria-current="page"' : ''; ?>><?php
                    echo osc_esc_html($navItem['name']); ?></a>
            </li>
        <?php }
        // Inside the list, as osc_private_user_menu() has always fired it: every
        // plugin written against this hook echoes an <li>, and outside the <ul>
        // that is a stray element in the nav.
        osc_run_hook('user_menu'); ?>
    </ul>
</nav>
