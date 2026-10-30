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

$itemsType = (string) View::getInstance()->_get('items_type');
$itemsTabs = array(
    'all'     => _m('All'),
    'active'  => _m('Published'),
    'pending' => _m('Awaiting validation'),
    'expired' => _m('Expired'),
);
if (osc_billing_pay_at_post() || Item::getInstance()->countItemTypesByUserID((int) osc_logged_user_id(), 'unpaid') > 0) {
    $itemsTabs['unpaid'] = _m('Waiting for payment');
}
$listingLimit = osc_user_listing_limit();
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>
        <?php osc_run_hook('account_page_before', 'user-items'); ?>

        <?php $tabs = array('label' => _m('Filter by status'), 'items' => array(), 'current' => $itemsType);
        foreach ($itemsTabs as $tabType => $tabLabel) {
            $tabs['items'][$tabType] = array($tabLabel, osc_user_list_items_url('', $tabType === 'all' ? '' : $tabType));
        }
        require dirname(__DIR__) . '/parts/tabs.php'; ?>

        <?php if ($listingLimit !== -1) {
            // The limit, the next listing's price and paused bumps share one notice.
            $listingsUsed = osc_user_listings_used();
            $limitLines   = '';
            if (!osc_user_can_publish()) {
                $slotInline = true;
                ob_start();
                require ABS_PATH . 'oc-includes/osclass/gui/billing/slot-prompt.php';
                $slotBox = (string) ob_get_clean();
                if ($slotBox === '') {
                    $slotBox = '<p class="oe-meta">' . osc_esc_html(osc_listing_limit_message()) . '</p>';
                }
                $limitLines .= $slotBox;
            }
            $bumpPaused = osc_billing_bump_paused_message();
            if ($bumpPaused !== '') {
                $limitLines .= '<p class="oe-meta">' . osc_esc_html($bumpPaused) . '</p>';
            } ?>
            <div class="oe-limit-notice<?php echo $limitLines !== '' ? ' oe-callout' : ''; ?>">
                <p class="oe-muted"><?php
                    echo osc_esc_html($listingsUsed > $listingLimit
                        ? sprintf(_m('You have %1$d live listings, which is over your limit of %2$d.'), $listingsUsed, $listingLimit)
                        : sprintf(_m('You are using %1$d of your %2$d listings.'), $listingsUsed, $listingLimit)); ?></p>
                <?php echo $limitLines; ?>
            </div>
        <?php } ?>

        <?php if (osc_count_items() === 0) {
            osc_gui_empty($itemsType === 'all'
                ? _m('You have not published anything yet.')
                : ($itemsType === 'unpaid' ? _m('No listing is waiting for payment.') : _m('No listings with this status.')));
            osc_gui_actions(array(array('label' => _m('Publish a listing'), 'href' => osc_item_post_url_in_category())));
        } else {
            osc_gui_listing_list('user_items', true);

            osc_gui_print_pager();
        } ?>

        <?php osc_run_hook('account_page_after', 'user-items'); ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
