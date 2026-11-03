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
 * Expects $receipt (Receipts::vars()), $backUrl and $backLabel, and $documents (the order's
 * invoice and credit notes). Every value is escaped here.
 *
 * @var \mindstellar\billing\Order $order
 * @var array<string,mixed> $receipt
 * @var \mindstellar\billing\Document[] $documents
 * @var string              $backUrl
 * @var string              $backLabel
 */

// The title carries the number, so no row repeats it under another name. A goods order
// shows what was bought and the amount in its own table, so those rows are for credits only.
$details = !empty($receipt['details']) ? $receipt['details'] : null;
$rows    = array(array(__('Date paid'), $receipt['date']));
if ($details === null) {
    $rows[] = array(__('What was bought'), $receipt['credits']);
    $rows[] = array(__('Amount paid'), $receipt['amount']);
}
$rows[] = array(__('Payment method'), $receipt['method']);
if (($receipt['for'] ?? '') !== '') {
    $rows[] = array(__('For'), $receipt['for']);
}
if ($receipt['ref'] !== '') {
    $rows[] = array(__('Payment reference'), $receipt['ref']);
}
if ($receipt['email'] !== '') {
    $rows[] = array(__('Paid by'), $receipt['email']);
}
foreach ($details['buyer_fields'] ?? array() as $field) {
    $rows[] = array((string) $field['label'], (string) $field['value']);
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
$codeLabel = \mindstellar\billing\Invoices::codeLabel($order);
$hasTax = false;
foreach ($items as $line) {
    $hasTax = $hasTax || is_array($line['tax'] ?? null);
}
$sign   = '';
$accent = (string) ($receipt['accent'] ?? '#0b7269');
?>
<!doctype html>
<html lang="<?php echo osc_esc_html(str_replace('_', '-', osc_current_user_locale())); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo osc_esc_html($title . ' · ' . $receipt['site']); ?></title>
<?php require __DIR__ . '/parts/print-style.php'; ?>
</head>
<body>
<div class="rc-wrap">
    <div class="rc-tools">
        <a href="<?php echo osc_esc_html($backUrl); ?>"><?php echo osc_esc_html($backLabel); ?></a>
        <?php foreach ($documents ?? array() as $document) { ?>
            <a href="<?php echo osc_esc_html(\mindstellar\billing\DocumentPage::url($document)); ?>"><?php
                echo osc_esc_html(sprintf($document->isCreditNote() ? __('Credit note %s') : __('Invoice %s'), $document->number)); ?></a>
        <?php } ?>
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
                <?php foreach ($rows as [$label, $value]) { ?>
                    <tr>
                        <th scope="row"><?php echo osc_esc_html($label); ?></th>
                        <td><?php echo osc_esc_html((string) $value); ?></td>
                    </tr>
                <?php } ?>
            </table>
        <?php } else { ?>
            <dl class="rc-meta">
                <?php foreach ($rows as [$label, $value]) { ?>
                    <div><dt><?php echo osc_esc_html($label); ?></dt><dd><?php echo osc_esc_html((string) $value); ?></dd></div>
                <?php } ?>
            </dl>
            <table class="rc-items">
                <thead>
                    <tr>
                        <th scope="col"><?php echo osc_esc_html(__('Item')); ?></th>
                        <th scope="col" class="rc-num"><?php echo osc_esc_html(__('Qty')); ?></th>
                        <th scope="col" class="rc-num rc-price"><?php echo osc_esc_html(__('Price')); ?></th>
                        <?php if ($hasTax) { ?>
                            <th scope="col" class="rc-num rc-tax"><?php echo osc_esc_html(__('Tax')); ?></th>
                        <?php } ?>
                        <th scope="col" class="rc-num"><?php echo osc_esc_html(__('Amount')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $line) { ?>
                        <tr>
                            <?php require __DIR__ . '/parts/line-name.php'; ?>
                            <td class="rc-num"><?php echo osc_esc_html($line['qty']); ?></td>
                            <td class="rc-num rc-price"><?php echo osc_esc_html($line['unit']); ?></td>
                            <?php if ($hasTax) {
                                $lineTax = $line['tax'] ?? null;
                                require __DIR__ . '/parts/line-tax.php';
                            } ?>
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
