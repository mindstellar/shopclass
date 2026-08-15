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
 * Core's fallback wallet page -- rendered only when the active theme has no
 * user-billing-wallet.php of its own. See CWebBilling::doView().
 */

use mindstellar\billing\Wallet;

$balance = (int) __get('balance');
$entries = __get('entries');
$entries = is_array($entries) ? $entries : array();
$total   = (int) __get('total');
$pageNum = max(1, (int) __get('pageNum'));
$perPage = max(1, (int) __get('perPage'));

$reasonWords = array(
    Wallet::REASON_PURCHASE => _m('Purchase'),
    Wallet::REASON_SPEND    => _m('Spent'),
    Wallet::REASON_REFUND   => _m('Refund'),
    Wallet::REASON_GRANT    => _m('Added by admin'),
    Wallet::REASON_REVOKE   => _m('Removed by admin'),
);

$themePath = WebThemes::newInstance()->getCurrentThemePath();
$hasChrome = file_exists($themePath . 'header.php') && file_exists($themePath . 'footer.php');

if ($hasChrome) {
    osc_current_web_theme_path('header.php');
} else {
    ?><!doctype html>
<html lang="<?php echo osc_esc_html(str_replace('_', '-', osc_current_user_locale())); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo osc_esc_html(_m('Credits')); ?></title>
<style>html,body{margin:0;padding:0;background:#f7f9fb;}</style>
</head>
<body>
    <?php
}
?>
<div class="oe-bill">
<style>
  .oe-bill{max-width:880px;margin:0 auto;padding:32px 16px;font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;color:#14181f;}
  .oe-bill *{box-sizing:border-box;}
  .oe-bill h1{font-size:1.5rem;font-weight:600;letter-spacing:-.01em;margin:0 0 20px;}
  .oe-bill .oe-bill-card{background:#fff;border:1px solid #dde3ea;border-radius:6px;padding:20px 24px;margin-bottom:20px;}
  .oe-bill .oe-bill-balance{font-size:2rem;font-weight:700;margin:0;}
  .oe-bill .oe-bill-balance.neg{color:#c22826;}
  .oe-bill .oe-bill-sub{color:#5f6b7a;margin:4px 0 0;font-size:.875rem;}
  .oe-bill table{width:100%;border-collapse:collapse;font-size:.9375rem;}
  .oe-bill th,.oe-bill td{text-align:left;padding:10px 8px;border-bottom:1px solid #eef1f5;}
  .oe-bill th{color:#5f6b7a;font-weight:500;font-size:.8125rem;text-transform:uppercase;letter-spacing:.02em;}
  .oe-bill .oe-bill-num{text-align:right;font-variant-numeric:tabular-nums;}
  .oe-bill .oe-bill-empty{color:#5f6b7a;padding:24px 0;text-align:center;}
  .oe-bill .oe-bill-btn{display:inline-block;text-decoration:none;border-radius:4px;padding:9px 18px;font-size:.9375rem;font-weight:500;background:#0b7269;color:#fff;border:1px solid #0b7269;cursor:pointer;}
  .oe-bill .oe-bill-btn:hover{background:#09625c;}
  .oe-bill .oe-bill-actions{margin-top:16px;display:flex;gap:12px;flex-wrap:wrap;}
  .oe-bill .oe-bill-pager{display:flex;justify-content:space-between;margin-top:16px;font-size:.875rem;}
  .oe-bill .oe-bill-pager a{color:#0b7269;text-decoration:none;}
</style>

<h1><?php echo osc_esc_html(_m('Credits')); ?></h1>

<div class="oe-bill-card">
    <p class="oe-bill-balance<?php echo $balance < 0 ? ' neg' : ''; ?>">
        <?php echo osc_esc_html(number_format($balance)); ?>
    </p>
    <p class="oe-bill-sub"><?php echo osc_esc_html(_m('credits available')); ?></p>
    <div class="oe-bill-actions">
        <a class="oe-bill-btn" href="<?php echo osc_esc_html(osc_billing_buy_url()); ?>">
            <?php echo osc_esc_html(_m('Buy more credits')); ?>
        </a>
    </div>
</div>

<div class="oe-bill-card">
    <h2><?php echo osc_esc_html(_m('History')); ?></h2>
    <?php if (empty($entries)) { ?>
        <p class="oe-bill-empty"><?php echo osc_esc_html(_m('Nothing has moved yet.')); ?></p>
    <?php } else { ?>
        <table>
            <thead>
            <tr>
                <th scope="col"><?php echo osc_esc_html(_m('What happened')); ?></th>
                <th scope="col" class="oe-bill-num"><?php echo osc_esc_html(_m('Change')); ?></th>
                <th scope="col" class="oe-bill-num"><?php echo osc_esc_html(_m('Balance after')); ?></th>
                <th scope="col"><?php echo osc_esc_html(_m('Date')); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry) {
                $amount = (int) $entry['i_amount'];
                $reason = (string) $entry['s_reason']; ?>
                <tr>
                    <td><?php echo osc_esc_html($reasonWords[$reason] ?? $reason); ?></td>
                    <td class="oe-bill-num">
                        <?php echo osc_esc_html(($amount > 0 ? '+' : '') . number_format($amount)); ?>
                    </td>
                    <td class="oe-bill-num"><?php echo osc_esc_html(number_format((int) $entry['i_balance_after'])); ?></td>
                    <td><?php echo osc_esc_html(osc_format_date($entry['dt_date'])); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php if ($total > $perPage) { ?>
            <div class="oe-bill-pager">
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

</div>
<?php
if ($hasChrome) {
    osc_current_web_theme_path('footer.php');
} else {
    ?>
</body>
</html>
<?php
}
