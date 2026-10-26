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

use mindstellar\billing\Wallet;

/**
 * Wallet balance and ledger history -- markup only: no page chrome, no heading
 * and no stylesheet of its own (core's shell or the theme's chrome supplies
 * all three).
 *
 * Registered as the 'billing/wallet' render target (see hBilling.php) so a
 * theme's user-custom.php can include it; CWebBilling renders it through
 * osc_gui_view() when the theme ships neither.
 *
 * Reads its own data through osc_logged_user_id() and the billing helpers
 * rather than variables exported by CWebBilling, so it renders safely from any
 * context, including a direct ?page=custom&file=billing/wallet hit: logged-out
 * or billing-disabled shows a calm empty state, never a warning.
 */

require_once dirname(__DIR__) . '/page-fn.php';

$perPage = 25;

if (!osc_is_web_user_logged_in()) {
    $emptyMessage = _m('Sign in to see your credits.');
} elseif (!osc_billing_enabled()) {
    $emptyMessage = _m('Credits are not available on this site.');
} else {
    $emptyMessage = null;

    $userId  = osc_logged_user_id();
    $pageNum = max(1, Params::getParamInt('pageNum'));
    $offset  = ($pageNum - 1) * $perPage;

    $balance = osc_user_credits($userId);
    $entries = Wallet::history($userId, $perPage, $offset);
    $total   = Wallet::historyCount($userId);
    $limit   = osc_user_listing_limit($userId);
    $canBuy  = osc_billing_can_buy();
    $offers  = osc_account_upgrade_offers($userId);
}

$reasonWords = array(
    Wallet::REASON_PURCHASE => _m('Purchase'),
    Wallet::REASON_SPEND    => _m('Spent'),
    Wallet::REASON_REFUND   => _m('Refund'),
    Wallet::REASON_GRANT    => _m('Added by admin'),
    Wallet::REASON_REVOKE   => _m('Removed by admin'),
);

// Column headings, also each cell's label when a row stacks on a phone.
$col = array(
    'what'   => _m('What happened'),
    'change' => _m('Change'),
    'after'  => _m('Balance after'),
    'date'   => _m('Date'),
);
?>
<div class="oe-account">
<div class="oe-account-main">
<?php osc_run_hook('account_page_before', 'billing-wallet'); ?>
<div class="oe-bill">


<?php if ($emptyMessage !== null) { ?>
    <?php osc_gui_panel_open('', 'oe-bill-card', '', 'div');
    osc_gui_empty($emptyMessage, 'oe-bill-empty');
    osc_gui_panel_close(); ?>
<?php } else { ?>
    <?php $billingTab = 'wallet';
    require ABS_PATH . 'oc-includes/osclass/gui/billing/tabs.php'; ?>
    <?php require ABS_PATH . 'oc-includes/osclass/gui/billing/slot-prompt.php'; ?>
    <?php osc_gui_panel_open('', 'oe-bill-card', '', 'div'); ?>
        <p class="oe-bill-balance<?php echo $balance < 0 ? ' neg' : ''; ?>">
            <?php echo osc_esc_html(number_format($balance)); ?>
        </p>
        <p class="oe-muted oe-bill-sub"><?php echo osc_esc_html(_m('credits available')); ?></p>
        <?php if ($limit !== -1) { ?>
            <p class="oe-bill-balance oe-bill-limit">
                <?php echo osc_esc_html(number_format(osc_user_listings_used($userId)) . ' / ' . number_format($limit)); ?>
            </p>
            <p class="oe-muted oe-bill-sub"><?php echo osc_esc_html(_m('listings used')); ?></p>
        <?php } ?>
        <?php if ($canBuy) { ?>
            <div class="oe-bill-actions">
                <a class="oe-btn oe-btn--primary oe-bill-btn" href="<?php echo osc_esc_html(osc_billing_buy_url()); ?>">
                    <?php echo osc_esc_html(_m('Buy more credits')); ?>
                </a>
            </div>
        <?php } ?>
    <?php osc_gui_panel_close(); ?>

    <?php if ($offers !== array()) { ?>
        <?php osc_gui_panel_open(_m('Upgrades for your account'), 'oe-bill-card', '', 'div'); ?>
            <ul class="oe-list">
                <?php foreach ($offers as $offer) {
                    $rowActions = array(array(
                        'label'  => $offer['credits'] > 0
                            ? sprintf(_mn('Buy for %d credit', 'Buy for %d credits', (int) $offer['credits']), (int) $offer['credits'])
                            : _m('Get it free'),
                        'url'    => osc_account_upgrade_url((string) $offer['feature']),
                        'method' => 'post',
                    )); ?>
                    <li class="oe-list-item">
                        <div class="oe-list-body">
                            <h3><?php echo osc_esc_html((string) $offer['label']); ?></h3>
                            <p class="oe-meta"><?php echo osc_esc_html((string) ($offer['detail'] ?? '')); ?></p>
                            <?php require ABS_PATH . 'oc-includes/osclass/gui/account/parts/row-actions.php'; ?>
                        </div>
                    </li>
                <?php } ?>
            </ul>
        <?php osc_gui_panel_close(); ?>
    <?php } ?>

    <?php osc_gui_panel_open(_m('History'), 'oe-bill-card', '', 'div'); ?>
        <?php if (empty($entries)) {
            osc_gui_empty(_m('Nothing has moved yet.'), 'oe-bill-empty');
        } else { ?>
            <div class="oe-scroll">
            <table class="oe-table">
                <thead>
                <tr>
                    <th scope="col"><?php echo osc_esc_html($col['what']); ?></th>
                    <th scope="col" class="oe-num oe-bill-num"><?php echo osc_esc_html($col['change']); ?></th>
                    <th scope="col" class="oe-num oe-bill-num"><?php echo osc_esc_html($col['after']); ?></th>
                    <th scope="col"><?php echo osc_esc_html($col['date']); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry) {
                    $amount = (int) $entry['i_amount'];
                    $reason = (string) $entry['s_reason']; ?>
                    <tr>
                        <td data-label="<?php echo osc_esc_html($col['what']); ?>"><?php echo osc_esc_html($reasonWords[$reason] ?? $reason); ?></td>
                        <td class="oe-num oe-bill-num" data-label="<?php echo osc_esc_html($col['change']); ?>">
                            <?php echo osc_esc_html(($amount > 0 ? '+' : '') . number_format($amount)); ?>
                        </td>
                        <td class="oe-num oe-bill-num" data-label="<?php echo osc_esc_html($col['after']); ?>"><?php echo osc_esc_html(number_format((int) $entry['i_balance_after'])); ?></td>
                        <td data-label="<?php echo osc_esc_html($col['date']); ?>"><?php echo osc_esc_html(osc_format_date($entry['dt_date'])); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            </div>
            <?php if ($total > $perPage) {
                $pager = array(
                    'prev'  => $pageNum > 1 ? osc_billing_wallet_url() . '&pageNum=' . ($pageNum - 1) : '',
                    'next'  => $pageNum * $perPage < $total ? osc_billing_wallet_url() . '&pageNum=' . ($pageNum + 1) : '',
                    'class' => 'oe-bill-pager',
                );
                require dirname(__DIR__) . '/parts/pager.php';
            } ?>
        <?php } ?>
    <?php osc_gui_panel_close(); ?>
<?php } ?>
</div>
<?php osc_run_hook('account_page_after', 'billing-wallet'); ?>
</div>

<?php require ABS_PATH . 'oc-includes/osclass/gui/account/nav.php'; ?>
</div>
