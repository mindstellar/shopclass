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
 * One listing in an account list: thumbnail, category, title, status, date and
 * the owner's edit link. Included from inside a `while (osc_has_items())` loop,
 * so it reads the current item through the osc_item_* helpers.
 *
 * $rowOwned (bool, default true) -- show the owner's actions and the status
 * flag. False on a public profile, where neither is the visitor's business.
 */

$rowOwned = isset($rowOwned) ? (bool) $rowOwned : true;
?>
<li class="oe-list-item">
    <?php if (osc_images_enabled_at_items() && osc_has_item_resources()) { ?>
        <img class="oe-thumb" src="<?php echo osc_esc_html(osc_resource_thumbnail_url()); ?>" alt=""
             width="88" height="73" loading="lazy" decoding="async">
    <?php } else { ?>
        <span class="oe-thumb oe-thumb-empty"><?php echo osc_esc_html(_m('No photo')); ?></span>
    <?php } ?>

    <div class="oe-list-body">
        <h3><a href="<?php echo osc_esc_html(osc_item_url()); ?>"><?php
            echo osc_esc_html(osc_item_title()); ?></a></h3>
        <p class="oe-meta">
            <?php if ($rowOwned) {
                if (osc_item_is_expired()) { ?>
                    <span class="oe-badge cancelled"><?php echo osc_esc_html(_m('Expired')); ?></span>
                <?php } elseif (!osc_item_is_active()) { ?>
                    <span class="oe-badge pending"><?php echo osc_esc_html(_m('Awaiting validation')); ?></span>
                <?php } else { ?>
                    <span class="oe-badge paid"><?php echo osc_esc_html(_m('Published')); ?></span>
                <?php }
            } ?>
            <span><?php echo osc_esc_html(osc_item_category()); ?></span>
            <time datetime="<?php echo osc_esc_html(date('Y-m-d', (int) strtotime((string) osc_item_pub_date()))); ?>"><?php
                echo osc_esc_html(osc_format_date(osc_item_pub_date())); ?></time>
            <?php if ($rowOwned) { ?>
                <a href="<?php echo osc_esc_html(osc_item_edit_url()); ?>"><?php
                    echo osc_esc_html(_m('Edit')); ?></a>
            <?php } ?>
        </p>
    </div>

    <p class="oe-price"><?php echo osc_esc_html(osc_item_formatted_price()); ?></p>
</li>
