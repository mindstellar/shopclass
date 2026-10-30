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

// The title carries the number, so no row repeats it under another name. A goods order
// shows what was bought and the amount in its own table, so those rows are for credits only.
$details = !empty($receipt['details']) ? $receipt['details'] : null;
$rows    = array(__('Date paid') => $receipt['date']);
if ($details === null) {
    $rows[__('What was bought')] = $receipt['credits'];
    $rows[__('Amount paid')]     = $receipt['amount'];
}
$rows[__('Payment method')] = $receipt['method'];
if (($receipt['for'] ?? '') !== '') {
    $rows[__('For')] = $receipt['for'];
}
if ($receipt['ref'] !== '') {
    $rows[__('Payment reference')] = $receipt['ref'];
}
if ($receipt['email'] !== '') {
    $rows[__('Paid by')] = $receipt['email'];
}
$title  = $details === null ? sprintf(__('Receipt %s'), $receipt['label']) : sprintf(__('%1$s %2$s'), $details['title'], $receipt['label']);
$items  = array();
$others = array();
foreach ($details['lines'] ?? array() as $line) {
    if ($line['kind'] === 'item') {
        $items[] = $line;
    } else {
        $others[] = $line;
    }
}
$accent = (string) ($receipt['accent'] ?? '#0b7269');
?>
<!doctype html>
<html lang="<?php echo osc_esc_html(str_replace('_', '-', osc_current_user_locale())); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo osc_esc_html($title . ' · ' . $receipt['site']); ?></title>
<style>
:root{color-scheme:light;--accent:<?php echo osc_esc_html($accent); ?>;--bg:#f3f4f2;--paper:#fff;--ink:#16191d;--muted:#5d646c;--line:#e3e5e1;--soft:#f6f7f5;--warn:#9a3412;--warn-bg:#fff1e6}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 "Inter","Segoe UI Variable Text","Segoe UI",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif;-webkit-font-smoothing:antialiased}
.rc-wrap{max-width:760px;margin:0 auto;padding:24px 16px 56px}
.rc-tools{display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;align-items:center;margin-bottom:16px}
.rc-tools a{color:var(--ink);text-underline-offset:3px}
.rc-print{font:inherit;font-weight:600;padding:8px 18px;border:0;border-radius:999px;background:var(--accent);color:#fff;cursor:pointer}
.rc-print:focus-visible,.rc-tools a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.rc-paper{background:var(--paper);border:1px solid var(--line);border-top:4px solid var(--accent);border-radius:10px;padding:36px 36px 32px}
.rc-head{display:flex;flex-wrap:wrap;gap:16px 32px;justify-content:space-between;align-items:flex-start}
.rc-brand{min-width:0}
.rc-logo{display:block;max-height:44px;max-width:220px;width:auto}
.rc-site{margin:0;font-size:1.35rem;font-weight:700;letter-spacing:-.01em;color:var(--accent)}
.rc-business{margin:6px 0 0;color:var(--muted);font-size:.875rem}
.rc-extra{margin:6px 0 0;padding:0;list-style:none;color:var(--muted);font-size:.875rem}
.rc-titlebox{text-align:right}
.rc-title{margin:0;font-size:1.5rem;line-height:1.2;letter-spacing:-.01em}
.rc-date{margin:4px 0 0;color:var(--muted);font-size:.9rem}
.rc-status{display:inline-block;margin:8px 0 0;padding:3px 10px;border-radius:999px;background:var(--warn-bg);color:var(--warn);font-weight:600;font-size:.85rem}
.rc-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px 28px;margin:28px 0 0;padding:18px 20px;background:var(--soft);border-radius:8px}
.rc-meta div{min-width:0}
.rc-meta dt{color:var(--muted);font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.rc-meta dd{margin:2px 0 0;overflow-wrap:anywhere}
.rc-rows{width:100%;margin-top:24px;border-collapse:collapse}
.rc-rows th,.rc-rows td{padding:10px 0;border-top:1px solid var(--line);text-align:left;vertical-align:top}
.rc-rows th{width:42%;padding-right:16px;color:var(--muted);font-weight:400}
.rc-rows td{overflow-wrap:anywhere}
.rc-items{width:100%;margin-top:28px;border-collapse:collapse;font-variant-numeric:tabular-nums}
.rc-items thead th{padding:0 0 8px;border-bottom:2px solid var(--ink);color:var(--muted);font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;text-align:left}
.rc-items td{padding:12px 0;border-bottom:1px solid var(--line);vertical-align:top}
.rc-items .rc-num{text-align:right;white-space:nowrap;padding-left:16px}
.rc-items small{display:block;color:var(--muted);font-size:.825rem}
.rc-name{font-weight:600}
.rc-totals{width:min(100%,340px);margin:12px 0 0 auto;border-collapse:collapse;font-variant-numeric:tabular-nums}
.rc-totals th,.rc-totals td{padding:6px 0;text-align:left;font-weight:400}
.rc-totals th{color:var(--muted);padding-right:16px}
.rc-totals td{text-align:right;white-space:nowrap}
.rc-totals .rc-total th,.rc-totals .rc-total td{padding-top:12px;border-top:2px solid var(--ink);color:var(--ink);font-weight:700;font-size:1.1rem}
.rc-summary{width:100%;margin-top:8px}
.rc-summary th,.rc-summary td{font-size:.875rem}
.rc-summary th{width:1%;white-space:nowrap}
.rc-summary td{white-space:normal;overflow-wrap:anywhere}
.rc-ship{margin-top:28px;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px 28px}
.rc-ship h2{margin:0 0 4px;color:var(--muted);font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.rc-ship p{margin:0}
.rc-note{margin:32px 0 0;padding-top:16px;border-top:1px solid var(--line);color:var(--muted);font-size:.875rem}
@media (max-width:560px){.rc-paper{padding:24px 18px}.rc-titlebox{text-align:left}.rc-rows th,.rc-rows td{display:block;width:auto;padding:0}.rc-rows th{padding-top:10px}.rc-rows td{border-top:0;padding-bottom:10px}.rc-items .rc-price{display:none}.rc-summary th,.rc-summary td{display:block;width:auto;padding:0;white-space:normal;text-align:left}.rc-summary th{padding-top:10px}.rc-summary td{padding-bottom:4px}}
@media print{:root{--bg:#fff;--soft:#fff;--line:#bbb;--muted:#444}body{font-size:11pt}.rc-wrap{max-width:none;padding:0}.rc-tools{display:none}.rc-paper{border:0;border-radius:0;padding:0}.rc-meta{padding:0}.rc-status{border:1px solid currentColor}}
</style>
</head>
<body>
<div class="rc-wrap">
    <div class="rc-tools">
        <a href="<?php echo osc_esc_html($backUrl); ?>"><?php echo osc_esc_html($backLabel); ?></a>
        <button type="button" class="rc-print" onclick="window.print()"><?php echo osc_esc_html(__('Print / Save as PDF')); ?></button>
    </div>
    <main class="rc-paper">
        <div class="rc-head">
            <div class="rc-brand">
                <?php if (($receipt['logo'] ?? '') !== '') { ?>
                    <img class="rc-logo" src="<?php echo osc_esc_html($receipt['logo']); ?>" alt="<?php echo osc_esc_html($receipt['site']); ?>">
                <?php } else { ?>
                    <p class="rc-site"><?php echo osc_esc_html($receipt['site']); ?></p>
                <?php } ?>
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
            </div>
            <div class="rc-titlebox">
                <h1 class="rc-title"><?php echo osc_esc_html($title); ?></h1>
                <?php if ($receipt['refunded']) { ?>
                    <p class="rc-status"><?php echo osc_esc_html(__('Refunded')); ?></p>
                <?php } ?>
            </div>
        </div>
        <?php if ($details === null) { ?>
            <table class="rc-rows">
                <?php foreach ($rows as $label => $value) { ?>
                    <tr>
                        <th scope="row"><?php echo osc_esc_html($label); ?></th>
                        <td><?php echo osc_esc_html((string) $value); ?></td>
                    </tr>
                <?php } ?>
            </table>
        <?php } else { ?>
            <dl class="rc-meta">
                <?php foreach ($rows as $label => $value) { ?>
                    <div><dt><?php echo osc_esc_html($label); ?></dt><dd><?php echo osc_esc_html((string) $value); ?></dd></div>
                <?php } ?>
            </dl>
            <table class="rc-items">
                <thead>
                    <tr>
                        <th scope="col"><?php echo osc_esc_html(__('Item')); ?></th>
                        <th scope="col" class="rc-num"><?php echo osc_esc_html(__('Qty')); ?></th>
                        <th scope="col" class="rc-num rc-price"><?php echo osc_esc_html(__('Price')); ?></th>
                        <th scope="col" class="rc-num"><?php echo osc_esc_html(__('Amount')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $line) { ?>
                        <tr>
                            <td>
                                <span class="rc-name"><?php echo osc_esc_html($line['title']); ?></span>
                                <?php if ($line['options'] !== '') { ?>
                                    <small><?php echo osc_esc_html($line['options']); ?></small>
                                <?php } ?>
                                <?php if ($line['sku'] !== '') { ?>
                                    <small><?php echo osc_esc_html(sprintf(__('SKU %s'), $line['sku'])); ?></small>
                                <?php } ?>
                            </td>
                            <td class="rc-num"><?php echo osc_esc_html($line['qty']); ?></td>
                            <td class="rc-num rc-price"><?php echo osc_esc_html($line['unit']); ?></td>
                            <td class="rc-num"><?php echo osc_esc_html($line['amount']); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <table class="rc-totals">
                <?php foreach ($others as $line) { ?>
                    <tr>
                        <th scope="row"><?php echo osc_esc_html($line['title']); ?></th>
                        <td><?php echo osc_esc_html(!empty($line['included']) ? sprintf(__('%s (included)'), $line['unit']) : $line['amount']); ?></td>
                    </tr>
                <?php } ?>
                <tr class="rc-total">
                    <th scope="row"><?php echo osc_esc_html(__('Total')); ?></th>
                    <td><?php echo osc_esc_html($details['total']); ?></td>
                </tr>
            </table>
            <?php if ($details['summary'] !== array()) { ?>
                <table class="rc-totals rc-summary">
                    <?php foreach ($details['summary'] as $row) { ?>
                        <tr>
                            <th scope="row"><?php echo osc_esc_html($row['label']); ?></th>
                            <td><?php echo nl2br(osc_esc_html($row['value']), false); ?></td>
                        </tr>
                    <?php } ?>
                </table>
            <?php } ?>
            <?php if ($details['address'] !== array()) { ?>
                <div class="rc-ship">
                    <div>
                        <h2><?php echo osc_esc_html(__('Delivery address')); ?></h2>
                        <p><?php echo implode('<br>', array_map('osc_esc_html', $details['address'])); ?></p>
                    </div>
                    <?php if ($details['shipping'] !== '') { ?>
                        <div>
                            <h2><?php echo osc_esc_html(__('Delivery')); ?></h2>
                            <p><?php echo osc_esc_html($details['shipping']); ?></p>
                        </div>
                    <?php } ?>
                </div>
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
