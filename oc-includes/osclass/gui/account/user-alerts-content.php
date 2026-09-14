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
 * Saved searches that email the user when something new matches -- markup only.
 *
 * Reads the `alerts` view variable. osc_has_alerts() re-exports each alert's own
 * matching listings into `items`, so the inner loop is the ordinary item loop.
 */
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>

        <p class="oe-muted"><?php echo osc_esc_html(
            _m('A saved search that emails you when a new listing matches it.')
        ); ?></p>

        <?php if (osc_count_alerts() === 0) { ?>
            <p class="oe-empty"><?php echo osc_esc_html(_m('You have no alerts yet.')); ?></p>
            <div class="oe-actions">
                <a class="oe-btn" href="<?php echo osc_esc_html(osc_search_url()); ?>"><?php
                    echo osc_esc_html(_m('Browse listings')); ?></a>
            </div>
        <?php } else {
            while (osc_has_alerts()) { ?>
                <section class="oe-panel">
                    <h2><?php echo osc_esc_html(osc_alert_search()); ?></h2>
                    <p class="oe-meta">
                        <?php if (osc_alert_is_active()) { ?>
                            <span class="oe-badge paid"><?php echo osc_esc_html(_m('Active')); ?></span>
                        <?php } else { ?>
                            <span class="oe-badge refunded"><?php echo osc_esc_html(_m('Unsubscribed')); ?></span>
                        <?php } ?>
                        <a href="<?php echo osc_esc_html(osc_user_unsubscribe_alert_url()); ?>"><?php
                            echo osc_esc_html(_m('Delete this alert')); ?></a>
                    </p>

                    <?php if (osc_count_items() === 0) { ?>
                        <p class="oe-empty"><?php echo osc_esc_html(_m('Nothing matches it yet.')); ?></p>
                    <?php } else { ?>
                        <ul class="oe-list">
                            <?php $rowOwned = false;
                            while (osc_has_items()) {
                                require __DIR__ . '/parts/item-row.php';
                            }
                            unset($rowOwned); ?>
                        </ul>
                    <?php } ?>
                </section>
            <?php }
        } ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
