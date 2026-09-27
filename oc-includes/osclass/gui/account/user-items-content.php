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
 * The seller's own listings -- markup only, no heading and no chrome.
 *
 * Reads the `items` view variable and the search_* paging variables the
 * controller exported.
 */

$itemsType = (string) View::newInstance()->_get('items_type');
$itemsTabs = array(
    'all'     => _m('All'),
    'active'  => _m('Published'),
    'pending' => _m('Awaiting validation'),
    'expired' => _m('Expired'),
);
$listingLimit = osc_user_listing_limit();
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>
        <?php osc_run_hook('account_page_before', 'user-items'); ?>

        <nav class="oe-tabs" aria-label="<?php echo osc_esc_html(_m('Filter by status')); ?>">
            <?php foreach ($itemsTabs as $tabType => $tabLabel) { ?>
                <a href="<?php echo osc_esc_html(osc_user_list_items_url('', $tabType === 'all' ? '' : $tabType)); ?>"<?php
                    echo $itemsType === $tabType ? ' aria-current="page"' : ''; ?>><?php
                    echo osc_esc_html($tabLabel); ?></a>
            <?php } ?>
        </nav>

        <?php if ($listingLimit !== -1) {
            $listingsUsed = osc_user_listings_used(); ?>
            <p class="oe-muted"><?php
                echo osc_esc_html($listingsUsed > $listingLimit
                    ? sprintf(_m('You have %1$d live listings, which is over your limit of %2$d.'), $listingsUsed, $listingLimit)
                    : sprintf(_m('You are using %1$d of your %2$d listings.'), $listingsUsed, $listingLimit)); ?></p>
            <?php if (!osc_user_can_publish()) { ?>
                <p class="oe-muted"><?php echo osc_esc_html(osc_listing_limit_message()); ?></p>
            <?php }
        } ?>

        <?php if (osc_count_items() === 0) { ?>
            <p class="oe-empty"><?php echo osc_esc_html($itemsType === 'all'
                ? _m('You have not published anything yet.')
                : _m('No listings with this status.')); ?></p>
            <div class="oe-actions">
                <a class="oe-btn" href="<?php echo osc_esc_html(osc_item_post_url_in_category()); ?>"><?php
                    echo osc_esc_html(_m('Publish a listing')); ?></a>
            </div>
        <?php } else {
            osc_gui_listing_list('user_items', true);

            osc_gui_print_pager();
        } ?>

        <?php osc_run_hook('account_page_after', 'user-items'); ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
