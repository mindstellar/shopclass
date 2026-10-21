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
 * A printable payment receipt: a whole page of its own, so it prints the same under any
 * theme and in the admin.
 *
 * Expects $receipt (Receipts::vars()), $backUrl and $backLabel. Every value is escaped here.
 *
 * @var array<string,mixed> $receipt
 * @var string              $backUrl
 * @var string              $backLabel
 */

$rows = array(
    __('Receipt number')  => $receipt['label'],
    __('Date paid')       => $receipt['date'],
    __('What was bought') => $receipt['credits'],
    __('Amount paid')     => $receipt['amount'],
    __('Payment method')  => $receipt['method'],
);
if (($receipt['for'] ?? '') !== '') {
    $rows[__('For')] = $receipt['for'];
}
if ($receipt['ref'] !== '') {
    $rows[__('Payment reference')] = $receipt['ref'];
}
if ($receipt['email'] !== '') {
    $rows[__('Paid by')] = $receipt['email'];
}
$details = !empty($receipt['details']) ? $receipt['details'] : null;
$title   = $details === null ? sprintf(__('Receipt %s'), $receipt['label']) : sprintf(__('%1$s %2$s'), $details['title'], $receipt['label']);
?>
<!doctype html>
<html lang="<?php echo osc_esc_html(str_replace('_', '-', osc_current_user_locale())); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo osc_esc_html($title . ' · ' . $receipt['site']); ?></title>
<style>
:root{color-scheme:light dark;--bg:#f4f4f2;--paper:#fff;--ink:#1d1d1b;--muted:#6b6b66;--line:#e2e2dc;--warn:#9a3412;--warn-bg:#fff1e6}
@media (prefers-color-scheme:dark){:root{--bg:#141413;--paper:#1f1f1d;--ink:#ecece8;--muted:#a3a39c;--line:#34342f;--warn:#fdba74;--warn-bg:#3a2414}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.rc-wrap{max-width:640px;margin:0 auto;padding:24px 16px 48px}
.rc-tools{display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;align-items:center;margin-bottom:16px}
.rc-tools a{color:inherit}
.rc-print{font:inherit;padding:8px 16px;border:1px solid var(--line);border-radius:6px;background:var(--paper);color:var(--ink);cursor:pointer}
.rc-paper{background:var(--paper);border:1px solid var(--line);border-radius:8px;padding:32px 28px}
.rc-site{margin:0;font-size:1.1rem;font-weight:600}
.rc-business{margin:4px 0 0;color:var(--muted);font-size:.9rem}
.rc-title{margin:28px 0 4px;font-size:1.6rem;line-height:1.2}
.rc-status{display:inline-block;margin:8px 0 0;padding:4px 10px;border-radius:4px;background:var(--warn-bg);color:var(--warn);font-weight:600}
.rc-rows{width:100%;margin-top:24px;border-collapse:collapse}
.rc-rows th,.rc-rows td{padding:10px 0;border-top:1px solid var(--line);text-align:left;vertical-align:top}
.rc-rows th{width:42%;padding-right:16px;color:var(--muted);font-weight:400}
.rc-rows td{overflow-wrap:anywhere}
.rc-note{margin:24px 0 0;color:var(--muted);font-size:.9rem}
.rc-extra{margin:12px 0 0;padding:0;list-style:none;color:var(--muted);font-size:.9rem}
@media (max-width:480px){.rc-paper{padding:24px 18px}.rc-rows th,.rc-rows td{display:block;width:auto;padding:0}.rc-rows th{padding-top:10px}.rc-rows td{border-top:0;padding-bottom:10px}}
@media print{:root{--bg:#fff;--paper:#fff;--ink:#000;--muted:#444;--line:#bbb;--warn:#000;--warn-bg:#fff}body{font-size:12pt}.rc-wrap{max-width:none;padding:0}.rc-tools{display:none}.rc-paper{border:0;padding:0}.rc-status{border:2px solid #000}}
</style>
</head>
<body>
<div class="rc-wrap">
    <div class="rc-tools">
        <a href="<?php echo osc_esc_html($backUrl); ?>"><?php echo osc_esc_html($backLabel); ?></a>
        <button type="button" class="rc-print" onclick="window.print()"><?php echo osc_esc_html(__('Print / Save as PDF')); ?></button>
    </div>
    <main class="rc-paper">
        <p class="rc-site"><?php echo osc_esc_html($receipt['site']); ?></p>
        <?php if ($receipt['business'] !== '') { ?>
            <p class="rc-business"><?php echo nl2br(osc_esc_html($receipt['business']), false); ?></p>
        <?php } ?>
        <?php if ($details !== null && $details['extra'] !== array()) { ?>
            <ul class="rc-extra">
                <?php foreach ($details['extra'] as $row) { ?>
                    <li><?php echo osc_esc_html($row['label']); ?>: <?php echo nl2br(osc_esc_html($row['value']), false); ?></li>
                <?php } ?>
            </ul>
        <?php } ?>
        <h1 class="rc-title"><?php echo osc_esc_html($title); ?></h1>
        <?php if ($receipt['refunded']) { ?>
            <p class="rc-status"><?php echo osc_esc_html(__('Refunded')); ?></p>
        <?php } ?>
        <table class="rc-rows">
            <?php foreach ($rows as $label => $value) { ?>
                <tr>
                    <th scope="row"><?php echo osc_esc_html($label); ?></th>
                    <td><?php echo osc_esc_html((string) $value); ?></td>
                </tr>
            <?php } ?>
        </table>
        <?php if ($details !== null) { ?>
            <table class="rc-rows rc-lines">
                <?php foreach ($details['lines'] as $line) { ?>
                    <tr>
                        <th scope="row">
                            <?php echo osc_esc_html($line['title']); ?>
                            <?php if ($line['kind'] === 'item') { ?>
                                <?php echo osc_esc_html(' × ' . $line['qty']); ?>
                            <?php } ?>
                            <?php if ($line['options'] !== '' || $line['sku'] !== '') { ?>
                                <br><small><?php echo osc_esc_html(trim($line['options'] . ' ' . ($line['sku'] !== '' ? sprintf(__('SKU %s'), $line['sku']) : ''))); ?></small>
                            <?php } ?>
                        </th>
                        <td><?php echo osc_esc_html(!empty($line['included']) ? sprintf(__('%s (included)'), $line['unit']) : $line['amount']); ?></td>
                    </tr>
                <?php } ?>
            </table>
            <?php if ($details['summary'] !== array()) { ?>
                <table class="rc-rows rc-summary">
                    <?php foreach ($details['summary'] as $row) { ?>
                        <tr>
                            <th scope="row"><?php echo osc_esc_html($row['label']); ?></th>
                            <td><?php echo nl2br(osc_esc_html($row['value']), false); ?></td>
                        </tr>
                    <?php } ?>
                </table>
            <?php } ?>
            <?php if ($details['address'] !== array()) { ?>
                <table class="rc-rows">
                    <tr>
                        <th scope="row"><?php echo osc_esc_html(__('Delivery address')); ?></th>
                        <td><?php echo implode('<br>', array_map('osc_esc_html', $details['address'])); ?></td>
                    </tr>
                    <?php if ($details['shipping'] !== '') { ?>
                        <tr>
                            <th scope="row"><?php echo osc_esc_html(__('Delivery')); ?></th>
                            <td><?php echo osc_esc_html($details['shipping']); ?></td>
                        </tr>
                    <?php } ?>
                </table>
            <?php } ?>
        <?php } ?>
        <p class="rc-note">
            <?php echo nl2br(osc_esc_html($receipt['refunded']
                ? ($details === null
                    ? __('This payment was refunded. The credits were taken back.')
                    : __('This payment was refunded.'))
                : ($details !== null && $details['footer'] !== '' ? $details['footer'] : __('Thank you for your payment. This is a receipt, not a tax invoice.'))), false); ?>
        </p>
    </main>
</div>
</body>
</html>
