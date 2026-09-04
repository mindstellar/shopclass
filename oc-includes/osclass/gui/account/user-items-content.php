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
 * The seller's own listings -- markup only, no heading and no chrome.
 *
 * Reads the `items` view variable and the search_* paging variables the
 * controller exported.
 */
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>

        <?php if (osc_count_items() === 0) { ?>
            <p class="oe-empty"><?php echo osc_esc_html(_m('You have not published anything yet.')); ?></p>
            <div class="oe-actions">
                <a class="oe-btn" href="<?php echo osc_esc_html(osc_item_post_url_in_category()); ?>"><?php
                    echo osc_esc_html(_m('Publish a listing')); ?></a>
            </div>
        <?php } else { ?>
            <ul class="oe-list">
                <?php while (osc_has_items()) {
                    require __DIR__ . '/parts/item-row.php';
                } ?>
            </ul>
            <?php $itemsPager = osc_pagination_items();
            if ($itemsPager !== '') { ?>
                <nav class="oe-pager" aria-label="<?php echo osc_esc_html(_m('Pages')); ?>"><?php
                    echo $itemsPager; ?></nav>
            <?php } ?>
        <?php } ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
