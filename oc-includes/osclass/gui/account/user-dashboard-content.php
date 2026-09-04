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
 * Account overview -- markup only. The heading belongs to whatever wraps this
 * (the theme's chrome, or core's shell), so it is not printed here.
 *
 * Reads the `items` view variable the controller exported.
 */
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>

        <p><?php printf(osc_esc_html(_m('Signed in as %s.')), osc_esc_html(osc_logged_user_name())); ?></p>

        <div class="oe-actions">
            <a class="oe-btn" href="<?php echo osc_esc_html(osc_item_post_url_in_category()); ?>"><?php
                echo osc_esc_html(_m('Publish a listing')); ?></a>
            <a class="oe-btn oe-secondary" href="<?php echo osc_esc_html(osc_user_list_items_url()); ?>"><?php
                echo osc_esc_html(_m('Your listings')); ?></a>
        </div>

        <h2><?php echo osc_esc_html(_m('Your latest listings')); ?></h2>
        <?php if (osc_count_items() === 0) { ?>
            <p class="oe-empty"><?php echo osc_esc_html(_m('You have not published anything yet.')); ?></p>
        <?php } else { ?>
            <ul class="oe-list">
                <?php while (osc_has_items()) {
                    require __DIR__ . '/parts/item-row.php';
                } ?>
            </ul>
            <p class="oe-muted">
                <a href="<?php echo osc_esc_html(osc_user_list_items_url()); ?>"><?php
                    echo osc_esc_html(_m('See all of your listings')); ?></a>
            </p>
        <?php } ?>

        <?php osc_run_hook('user_dashboard'); ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
