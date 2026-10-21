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
 * Account overview -- markup only. The heading belongs to whatever wraps this
 * (the theme's chrome, or core's shell), so it is not printed here.
 *
 * Reads the `items` view variable the controller exported; a shopper's page reads `orders`
 * instead and offers no seller buttons.
 */

$dashboardShopper = osc_is_shopper();
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>
        <?php osc_run_hook('account_page_before', 'user-dashboard'); ?>

        <p class="oe-dashboard-intro"><?php printf(osc_esc_html(_m('Signed in as %s.')), osc_esc_html(osc_logged_user_name())); ?></p>

        <?php if ($dashboardShopper) {
            $orders = View::getInstance()->_exists('orders') ? (array) View::getInstance()->_get('orders') : array(); ?>
            <h2 class="oe-dashboard-title"><?php echo osc_esc_html(_m('Your recent orders')); ?></h2>
            <?php if ($orders === array()) { ?>
                <p class="oe-empty"><?php echo osc_esc_html(_m('You have not ordered anything yet.')); ?></p>
            <?php } else { ?>
                <div class="oe-bill">
                    <?php require ABS_PATH . 'oc-includes/osclass/gui/billing/parts/order-list.php'; ?>
                </div>
                <p class="oe-muted oe-dashboard-more">
                    <a href="<?php echo osc_esc_html(osc_billing_orders_url()); ?>"><?php
                        echo osc_esc_html(_m('See all of your orders')); ?></a>
                </p>
            <?php } ?>
        <?php } else { ?>
            <div class="oe-actions oe-dashboard-actions">
                <a class="oe-btn" href="<?php echo osc_esc_html(osc_item_post_url_in_category()); ?>"><?php
                    echo osc_esc_html(_m('Publish a listing')); ?></a>
                <a class="oe-btn oe-secondary" href="<?php echo osc_esc_html(osc_user_list_items_url()); ?>"><?php
                    echo osc_esc_html(_m('Your listings')); ?></a>
            </div>

            <h2 class="oe-dashboard-title"><?php echo osc_esc_html(_m('Your latest listings')); ?></h2>
            <?php if (osc_count_items() === 0) { ?>
                <p class="oe-empty"><?php echo osc_esc_html(_m('You have not published anything yet.')); ?></p>
            <?php } else { ?>
                <?php osc_gui_listing_list('dashboard', true); ?>
                <p class="oe-muted oe-dashboard-more">
                    <a href="<?php echo osc_esc_html(osc_user_list_items_url()); ?>"><?php
                        echo osc_esc_html(_m('See all of your listings')); ?></a>
                </p>
            <?php } ?>
        <?php } ?>

        <?php osc_run_hook('user_dashboard'); ?>

        <?php osc_run_hook('account_page_after', 'user-dashboard'); ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
