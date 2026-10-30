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

use mindstellar\billing\ListingCheckout;
use mindstellar\billing\Order;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\currency\CurrencyService;

/**
 * The pay step of a listing held at the end of posting -- markup only: no page chrome, no
 * heading and no stylesheet of its own. Registered as the 'billing/publish' render target.
 *
 * Draws what CWebBilling::publishView() exported: `item` (id, title, url), `state` (see
 * ListingCheckout::state()), `packages`, `gateways`, and after an in-page checkout `order`
 * and `checkoutHtml`. A hit without them shows a short note instead.
 */

require_once ABS_PATH . 'oc-includes/osclass/gui/page-fn.php';

$payItem  = __get('item');
$payState = __get('state');
$payReady = is_array($payItem) && is_array($payState) && isset($payState['step'], $payState['quote']);

$order        = __get('order');
$order        = $order instanceof Order ? $order : null;
$checkoutHtml = __get('checkoutHtml');
$checkoutHtml = is_string($checkoutHtml) ? $checkoutHtml : '';

if ($payReady) {
    $payStep     = (string) $payState['step'];
    $payQuote    = $payState['quote'];
    $payCredits  = (int) $payQuote['credits'];
    $payBalance  = (int) $payQuote['balance'];
    $payShort    = (int) $payQuote['shortfall'];
    $payItemId   = (int) $payItem['id'];
    $payPackages = (array) __get('packages');
    $payGateways = (array) __get('gateways');
    $payHeld     = in_array($payStep, array('pay', 'waiting', 'failed'), true);
    $payFields   = array('page' => 'billing', 'action' => 'publish', 'itemId' => $payItemId);
    $payFailed   = $payStep === 'failed' ? (string) ($payState['failed']['reason'] ?? '') : '';
    $payRefused  = $payFailed === 'refused';
    $payOrder    = $payStep === 'waiting' && ($payState['order'] ?? null) instanceof Order ? $payState['order'] : null;
    $payHowTo    = $payOrder === null ? null : PaymentGatewayRegistry::getInstance()->instructions($payOrder);
}
?>
<div class="oe-account">
<div class="oe-account-main">
<?php osc_run_hook('account_page_before', 'billing-publish'); ?>
<div class="oe-bill oe-bill-publish">

<?php if (!$payReady) { ?>
    <?php osc_gui_panel_open('', 'oe-bill-card', '', 'div');
    osc_gui_empty(_m('Open the listing you want to publish from your listings.'), 'oe-bill-empty'); ?>
        <div class="oe-bill-actions">
            <a class="oe-btn oe-btn--primary oe-bill-btn" href="<?php echo osc_esc_html(osc_user_list_items_url()); ?>"><?php echo osc_esc_html(_m('Your listings')); ?></a>
        </div>
    <?php osc_gui_panel_close('div'); ?>
<?php } else { ?>
    <?php if ($checkoutHtml !== '' && $order !== null) { ?>
        <?php osc_gui_panel_open(sprintf(_m('Order #%d'), $order->getId()), 'oe-bill-card', '', 'div'); ?>
            <div class="oe-bill-instructions"><?php echo $checkoutHtml; /* gateway-authored, see CheckoutIntent::html() */ ?></div>
        <?php osc_gui_panel_close('div'); ?>
    <?php } elseif ($payHowTo !== null) { ?>
        <?php osc_gui_panel_open(sprintf(_m('Order #%d'), $payOrder->getId()), 'oe-bill-card', '', 'div'); ?>
            <?php $payMoney = CurrencyService::make()->money($payOrder->getAmount(), $payOrder->getCurrency()); ?>
            <div class="oe-bill-amount" role="group" aria-label="<?php echo osc_esc_html(sprintf(_m('Amount to pay: %s'), $payMoney)); ?>">
                <p class="oe-bill-amount-label"><?php echo osc_esc_html(_m('Amount to pay')); ?></p>
                <p class="oe-figure oe-bill-amount-figure"><?php echo osc_esc_html($payMoney); ?></p>
            </div>
            <div class="oe-bill-instructions"><p><?php echo osc_gui_payment_text($payHowTo, array(osc_billing_order_number($payOrder->getId()))); ?></p></div>
        <?php osc_gui_panel_close('div'); ?>
    <?php } ?>

    <?php osc_gui_panel_open((string) $payItem['title'], 'oe-bill-card oe-bill-publish-card', '', 'div'); ?>

        <?php if ($payStep === 'published') { ?>
            <p class="oe-meta"><?php echo osc_esc_html(_m('Your listing is published.')); ?></p>
            <div class="oe-bill-actions">
                <a class="oe-btn oe-btn--primary oe-bill-btn" href="<?php echo osc_esc_html((string) $payItem['url']); ?>"><?php echo osc_esc_html(_m('View your listing')); ?></a>
            </div>
        <?php } elseif (in_array($payStep, array('validation', 'moderation', 'closed'), true)) { ?>
            <p class="oe-meta"><?php echo osc_esc_html(match ($payStep) {
                'validation' => _m('Check your inbox to validate your listing'),
                'moderation' => _m('Your listing will be published after an admin approves it.'),
                default      => _m('This listing is not waiting for payment.'),
            }); ?></p>
            <div class="oe-bill-actions">
                <a class="oe-btn oe-btn--primary oe-bill-btn" href="<?php echo osc_esc_html(osc_user_list_items_url()); ?>"><?php echo osc_esc_html(_m('Your listings')); ?></a>
            </div>
        <?php } else { ?>
            <?php if ($payStep === 'waiting') { ?>
                <p class="oe-meta oe-bill-publish-status"><?php echo osc_esc_html(_m('Waiting for the payment to be confirmed.')); ?>
                    <a href="<?php echo osc_esc_html(osc_billing_publish_url($payItemId)); ?>"><?php echo osc_esc_html(_m('Refresh')); ?></a></p>
            <?php } elseif ($payStep === 'failed') { ?>
                <p class="oe-meta oe-bill-publish-status"><?php echo osc_esc_html(sprintf(
                    _m('Your payment arrived, but the listing is not published yet: %s.'),
                    ListingCheckout::failureReason($payFailed)
                )); ?>
                    <?php if ($payFailed !== 'insufficient_credits') {
                        echo osc_esc_html(_m('Contact the site owner.'));
                    } ?></p>
            <?php } ?>

            <?php if ($payCredits === 0) { ?>
                <p class="oe-meta"><?php echo osc_esc_html(_m('This listing is free to publish now.')); ?></p>
            <?php } elseif ($payHowTo !== null || $checkoutHtml !== '') { ?>
                <p class="oe-meta"><?php echo osc_esc_html(sprintf(
                    _m('This listing costs %1$s. You have %2$s.'),
                    sprintf(_mn('%d credit', '%d credits', $payCredits), $payCredits),
                    sprintf(_mn('%d credit', '%d credits', $payBalance), $payBalance)
                )); ?></p>
            <?php } else {
                $payFrom = $payShort > 0 && $payPackages !== array() ? $payPackages[0] : null; ?>
                <div class="oe-bill-amount">
                    <p class="oe-bill-amount-label"><?php echo osc_esc_html(_m('Price to publish')); ?></p>
                    <p class="oe-figure oe-bill-amount-figure"><?php echo osc_esc_html(sprintf(_mn('%d credit', '%d credits', $payCredits), $payCredits)); ?></p>
                    <p class="oe-muted"><?php echo osc_esc_html(sprintf(_m('You have %s.'), sprintf(_mn('%d credit', '%d credits', $payBalance), $payBalance)));
                    if ($payFrom !== null) {
                        echo ' ' . osc_esc_html(sprintf(
                            _m('Credit packages that cover it start at %s.'),
                            CurrencyService::make()->money((int) $payFrom['i_amount'], (string) $payFrom['s_currency'])
                        ));
                    } ?></p>
                </div>
            <?php } ?>
            <?php if (!empty($payState['deadline'])) { ?>
                <p class="oe-callout oe-bill-deadline" role="note"><?php echo osc_esc_html(sprintf(
                    _m('If it is not paid for by %s, this listing will be deleted.'),
                    osc_format_date((string) $payState['deadline'])
                )); ?></p>
            <?php } ?>
            <?php if ($payHeld && $payShort === 0 && !$payRefused) { ?>
                <form class="oe-inline-form nocsrf oe-bill-actions" method="post" action="<?php echo osc_esc_html(osc_base_url(true)); ?>">
                    <?php echo osc_csrf_token_form(); ?>
                    <?php foreach ($payFields + array('do' => 'credits') as $payName => $payValue) { ?>
                        <input type="hidden" name="<?php echo osc_esc_html((string) $payName); ?>" value="<?php echo osc_esc_html((string) $payValue); ?>"/>
                    <?php } ?>
                    <button type="submit" class="oe-btn oe-btn--primary oe-bill-btn"><?php echo osc_esc_html(match (true) {
                        $payCredits > 0 && osc_moderate_admin_post() => sprintf(_mn('Use %d credit and send for review', 'Use %d credits and send for review', $payCredits), $payCredits),
                        $payCredits > 0                               => sprintf(_mn('Use %d credit and publish', 'Use %d credits and publish', $payCredits), $payCredits),
                        osc_moderate_admin_post()                     => _m('Send for review'),
                        default                                       => _m('Publish for free'),
                    }); ?></button>
                </form>
            <?php } ?>
        <?php } ?>
    <?php osc_gui_panel_close('div'); ?>

    <?php if ($payHeld) { ?>
        <?php if ($payShort > 0) { ?>
        <?php if ($payStep === 'waiting') { ?>
        <details class="oe-bill-other">
            <summary><?php echo osc_esc_html(_m('Pay another way')); ?></summary>
        <?php } ?>
        <?php osc_gui_panel_open('', 'oe-bill-card', '', 'div'); ?>
            <?php if ($payPackages === array()) { ?>
                <p class="oe-meta"><?php echo osc_esc_html(sprintf(_mn('No package covers %d credit.', 'No package covers %d credits.', $payShort), $payShort)); ?></p>
                <div class="oe-bill-actions">
                    <a class="oe-btn oe-btn--primary oe-bill-btn" href="<?php echo osc_esc_html(osc_billing_buy_url()); ?>"><?php echo osc_esc_html(_m('Buy credits')); ?></a>
                </div>
            <?php } elseif ($payGateways === array()) {
                osc_gui_empty(_m('No payment method is set up yet.'), 'oe-bill-empty');
            } else { ?>
                <p class="oe-meta"><?php echo osc_esc_html(sprintf(_mn('You need %d more credit. Credits left over stay in your wallet.', 'You need %d more credits. Credits left over stay in your wallet.', $payShort), $payShort)); ?></p>
                <?php
                $pickerPackages = $payPackages;
                $pickerGateways = $payGateways;
                $pickerFields   = $payFields + array('do' => 'order');
                $pickerSubmit   = _m('Continue to payment');
                require ABS_PATH . 'oc-includes/osclass/gui/billing/parts/package-picker.php';
                ?>
            <?php } ?>
        <?php osc_gui_panel_close('div'); ?>
        <?php if ($payStep === 'waiting') { ?>
        </details>
        <?php } ?>
        <?php } ?>

        <div class="oe-actions oe-bill-publish-more">
            <a class="oe-secondary" href="<?php echo osc_esc_html(osc_user_list_items_url()); ?>"><?php echo osc_esc_html(_m('Pay later')); ?></a>
        </div>
        <form class="oe-inline-form nocsrf oe-bill-delete-form" method="post" action="<?php echo osc_esc_html(osc_base_url(true)); ?>">
            <?php echo osc_csrf_token_form(); ?>
            <input type="hidden" name="page" value="item"/>
            <input type="hidden" name="action" value="item_delete"/>
            <input type="hidden" name="id" value="<?php echo $payItemId; ?>"/>
            <button type="submit" class="oe-link-btn oe-danger-link oe-bill-delete" data-osc-confirm="<?php
                echo osc_esc_html(sprintf(_m('Delete "%s"? This cannot be undone.'), (string) $payItem['title'])); ?>"><?php
                echo osc_esc_html(_m('Delete this listing')); ?></button>
        </form>
        <?php osc_gui_print_confirm_script(); ?>
    <?php } ?>
<?php } ?>
</div>
<?php osc_run_hook('account_page_after', 'billing-publish'); ?>
</div>

<?php require ABS_PATH . 'oc-includes/osclass/gui/account/nav.php'; ?>
</div>
