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
 * The next step for a seller at their listing limit: buy an extra listing slot and go
 * straight to the post form, or buy credits first. A seller who needs several slots buys
 * them one at a time. Renders nothing otherwise.
 */

require_once dirname(__DIR__) . '/page-fn.php';

if (!osc_is_web_user_logged_in() || !osc_billing_credits_enabled() || osc_user_can_publish()) {
    return;
}

$slotUserId = (int) osc_logged_user_id();
$slotOffer  = array_column(osc_account_upgrade_offers($slotUserId), null, 'feature')['listing.slot'] ?? null;
$slotLimit  = osc_user_listing_limit($slotUserId);
if ($slotOffer === null || $slotLimit === -1) {
    return;
}
// Purchases needed before the seller can post one more listing.
$slotQuote   = osc_billing_next_listing_quote($slotUserId);
$slotsNeeded = $slotQuote['slots'];

$slotPrice   = (int) $slotOffer['credits'];
$slotCredits = $slotQuote['balance'];
$slotCanPay  = $slotCredits >= $slotPrice;
if (!$slotCanPay && !osc_billing_can_buy()) {
    return;
}
?>
<?php osc_gui_panel_open(_m('Post another listing'), 'oe-bill-card oe-slot-prompt', '', 'div'); ?>
    <p class="oe-meta"><?php echo osc_esc_html(sprintf(
        _m('%1$s: %2$s. You have %3$s.'),
        (string) $slotOffer['label'],
        $slotPrice > 0 ? sprintf(_mn('%d credit', '%d credits', $slotPrice), $slotPrice) : _m('free'),
        sprintf(_mn('%d credit', '%d credits', $slotCredits), $slotCredits)
    )); ?></p>
    <?php if ($slotsNeeded > 1) { ?>
        <p class="oe-meta"><?php echo osc_esc_html(sprintf(
            _m('You are over your listing limit. Buy this %d times, or close some listings, to post again.'),
            $slotsNeeded
        )); ?></p>
    <?php } ?>
    <div class="oe-actions">
        <?php if ($slotCanPay) { ?>
            <form class="oe-inline-form nocsrf" method="post" action="<?php echo osc_esc_html(osc_account_upgrade_url('listing.slot')); ?>">
                <?php echo osc_csrf_token_form(); ?>
                <?php if ($slotsNeeded === 1) { ?>
                    <input type="hidden" name="next" value="post">
                    <button type="submit" class="oe-btn oe-btn--primary"><?php echo osc_esc_html($slotPrice > 0
                        ? sprintf(_mn('Use %d credit and post', 'Use %d credits and post', $slotPrice), $slotPrice)
                        : _m('Get it free and post')); ?></button>
                <?php } else { ?>
                    <button type="submit" class="oe-btn oe-btn--primary"><?php echo osc_esc_html($slotPrice > 0
                        ? sprintf(_mn('Use %d credit', 'Use %d credits', $slotPrice), $slotPrice)
                        : _m('Get it free')); ?></button>
                <?php } ?>
            </form>
        <?php } else { ?>
            <a class="oe-btn oe-btn--primary" href="<?php echo osc_esc_html(osc_billing_buy_url()); ?>"><?php
                echo osc_esc_html(_m('Buy credits')); ?></a>
        <?php } ?>
    </div>
<?php osc_gui_panel_close(); ?>
