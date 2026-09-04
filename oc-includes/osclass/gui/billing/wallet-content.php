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
    $entries = is_array($entries) ? $entries : array();
    $total   = Wallet::historyCount($userId);
}

$reasonWords = array(
    Wallet::REASON_PURCHASE => _m('Purchase'),
    Wallet::REASON_SPEND    => _m('Spent'),
    Wallet::REASON_REFUND   => _m('Refund'),
    Wallet::REASON_GRANT    => _m('Added by admin'),
    Wallet::REASON_REVOKE   => _m('Removed by admin'),
);
?>
<div class="oe-account">
<div class="oe-account-main">
<div class="oe-bill">


<?php if ($emptyMessage !== null) { ?>
    <div class="oe-panel oe-bill-card">
        <p class="oe-empty oe-bill-empty"><?php echo osc_esc_html($emptyMessage); ?></p>
    </div>
<?php } else { ?>
    <div class="oe-panel oe-bill-card">
        <p class="oe-bill-balance<?php echo $balance < 0 ? ' neg' : ''; ?>">
            <?php echo osc_esc_html(number_format($balance)); ?>
        </p>
        <p class="oe-muted oe-bill-sub"><?php echo osc_esc_html(_m('credits available')); ?></p>
        <div class="oe-bill-actions">
            <a class="oe-btn oe-bill-btn" href="<?php echo osc_esc_html(osc_billing_buy_url()); ?>">
                <?php echo osc_esc_html(_m('Buy more credits')); ?>
            </a>
        </div>
    </div>

    <div class="oe-panel oe-bill-card">
        <h2><?php echo osc_esc_html(_m('History')); ?></h2>
        <?php if (empty($entries)) { ?>
            <p class="oe-empty oe-bill-empty"><?php echo osc_esc_html(_m('Nothing has moved yet.')); ?></p>
        <?php } else { ?>
            <div class="oe-scroll">
            <table>
                <thead>
                <tr>
                    <th scope="col"><?php echo osc_esc_html(_m('What happened')); ?></th>
                    <th scope="col" class="oe-num oe-bill-num"><?php echo osc_esc_html(_m('Change')); ?></th>
                    <th scope="col" class="oe-num oe-bill-num"><?php echo osc_esc_html(_m('Balance after')); ?></th>
                    <th scope="col"><?php echo osc_esc_html(_m('Date')); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry) {
                    $amount = (int) $entry['i_amount'];
                    $reason = (string) $entry['s_reason']; ?>
                    <tr>
                        <td><?php echo osc_esc_html($reasonWords[$reason] ?? $reason); ?></td>
                        <td class="oe-num oe-bill-num">
                            <?php echo osc_esc_html(($amount > 0 ? '+' : '') . number_format($amount)); ?>
                        </td>
                        <td class="oe-num oe-bill-num"><?php echo osc_esc_html(number_format((int) $entry['i_balance_after'])); ?></td>
                        <td><?php echo osc_esc_html(osc_format_date($entry['dt_date'])); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            </div>
            <?php if ($total > $perPage) { ?>
                <div class="oe-pager oe-bill-pager">
                    <span>
                        <?php if ($pageNum > 1) { ?>
                            <a href="<?php echo osc_esc_html(osc_billing_wallet_url() . '&pageNum=' . ($pageNum - 1)); ?>">
                                <?php echo osc_esc_html(_m('Newer')); ?>
                            </a>
                        <?php } ?>
                    </span>
                    <span>
                        <?php if ($pageNum * $perPage < $total) { ?>
                            <a href="<?php echo osc_esc_html(osc_billing_wallet_url() . '&pageNum=' . ($pageNum + 1)); ?>">
                                <?php echo osc_esc_html(_m('Older')); ?>
                            </a>
                        <?php } ?>
                    </span>
                </div>
            <?php } ?>
        <?php } ?>
    </div>
<?php } ?>
</div>
</div>

<?php require ABS_PATH . 'oc-includes/osclass/gui/account/nav.php'; ?>
</div>
