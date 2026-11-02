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
 * A printable invoice or credit note. It draws only the frozen document, never the live order
 * or settings, so it reads the same years later. Every value is escaped here.
 *
 * Expects $page (DocumentPage::render()), $backUrl and $backLabel.
 *
 * @var array<string,mixed> $page
 * @var string              $backUrl
 * @var string              $backLabel
 */

$sign   = (string) $page['sign'];
$accent = (string) ($page['accent'] ?? '#0b7269');
$title  = sprintf(__('%1$s %2$s'), $page['title'], $page['number']);
$items  = array();
$others = array();
foreach ($page['lines'] as $line) {
    if (($line['kind'] ?? '') === 'item') {
        $items[] = $line;
    } else {
        $others[] = $line;
    }
}
$buyer = (array) $page['buyer'];
$meta  = array(__('Date') => $page['date']);
if ($page['taxPoint'] !== '' && $page['taxPoint'] !== $page['date']) {
    $meta[__('Date paid')] = $page['taxPoint'];
}
if (($page['order']['label'] ?? '') !== '') {
    $meta[__('Order')] = $page['order']['label'];
}
if (($page['order']['method'] ?? '') !== '') {
    $meta[__('Payment method')] = $page['order']['method'];
}
if (($page['order']['ref'] ?? '') !== '') {
    $meta[__('Payment reference')] = $page['order']['ref'];
}
if (($page['refund']['reason'] ?? '') !== '') {
    $meta[__('Reason')] = $page['refund']['reason'];
}
?>
<!doctype html>
<html lang="<?php echo osc_esc_html(str_replace('_', '-', osc_current_user_locale())); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo osc_esc_html($title . ' · ' . $page['site']); ?></title>
<?php require __DIR__ . '/parts/print-style.php'; ?>
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
                <?php if (($page['logo'] ?? '') !== '') { ?>
                    <img class="rc-logo" src="<?php echo osc_esc_html($page['logo']); ?>" alt="<?php echo osc_esc_html($page['site']); ?>">
                <?php } else { ?>
                    <p class="rc-site"><?php echo osc_esc_html($page['site']); ?></p>
                <?php } ?>
                <?php if (!empty($page['seller']['details'])) { ?>
                    <p class="rc-business"><?php echo implode('<br>', array_map('osc_esc_html', array_map('strval', (array) $page['seller']['details']))); ?></p>
                <?php } ?>
                <?php if ($page['extra'] !== array()) { ?>
                    <ul class="rc-extra">
                        <?php foreach ($page['extra'] as $row) { ?>
                            <li><?php echo osc_esc_html((string) ($row['label'] ?? '')); ?>: <?php echo nl2br(osc_esc_html((string) ($row['value'] ?? '')), false); ?></li>
                        <?php } ?>
                    </ul>
                <?php } ?>
            </div>
            <div class="rc-titlebox">
                <h1 class="rc-title"><?php echo osc_esc_html($title); ?></h1>
                <?php if ($page['refInv'] !== null) { ?>
                    <p class="rc-ref"><?php echo osc_esc_html(sprintf(__('For invoice %s'), (string) ($page['refInv']['number'] ?? ''))); ?></p>
                <?php } ?>
            </div>
        </div>
        <dl class="rc-meta">
            <?php foreach ($meta as $label => $value) { ?>
                <div><dt><?php echo osc_esc_html($label); ?></dt><dd><?php echo osc_esc_html((string) $value); ?></dd></div>
            <?php } ?>
            <?php if (($buyer['name'] ?? '') !== '' || ($buyer['email'] ?? '') !== '' || !empty($buyer['address'])) { ?>
                <div>
                    <dt><?php echo osc_esc_html(__('Billed to')); ?></dt>
                    <dd><?php echo implode('<br>', array_map('osc_esc_html', array_filter(array_merge(
                        array((string) ($buyer['name'] ?? ''), (string) ($buyer['email'] ?? '')),
                        array_map('strval', (array) ($buyer['address'] ?? array()))
                    ), static fn (string $row): bool => $row !== ''))); ?></dd>
                </div>
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
                            <span class="rc-name"><?php echo osc_esc_html((string) ($line['title'] ?? '')); ?></span>
                            <?php if (($line['options'] ?? '') !== '') { ?>
                                <small><?php echo osc_esc_html((string) $line['options']); ?></small>
                            <?php } ?>
                            <?php if (($line['sku'] ?? '') !== '') { ?>
                                <small><?php echo osc_esc_html(sprintf(__('SKU %s'), (string) $line['sku'])); ?></small>
                            <?php } ?>
                        </td>
                        <td class="rc-num"><?php echo osc_esc_html((string) ($line['qty'] ?? '')); ?></td>
                        <td class="rc-num rc-price"><?php echo osc_esc_html((string) ($line['unit_text'] ?? '')); ?></td>
                        <td class="rc-num"><?php echo osc_esc_html($sign . ($line['amount_text'] ?? '')); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
        <table class="rc-totals">
            <?php foreach ($others as $line) { ?>
                <tr>
                    <th scope="row"><?php echo osc_esc_html((string) ($line['title'] ?? '')); ?></th>
                    <td><?php echo osc_esc_html(!empty($line['included'])
                        ? sprintf(__('%s (included)'), $sign . ($line['unit_text'] ?? ''))
                        : $sign . ($line['amount_text'] ?? '')); ?></td>
                </tr>
            <?php } ?>
            <tr class="rc-total">
                <th scope="row"><?php echo osc_esc_html($sign === '' ? __('Total') : __('Total credited')); ?></th>
                <td><?php echo osc_esc_html($sign . $page['total']); ?></td>
            </tr>
        </table>
        <?php if ($page['summary'] !== array()) { ?>
            <table class="rc-totals rc-summary">
                <?php foreach ($page['summary'] as $row) { ?>
                    <tr>
                        <th scope="row"><?php echo osc_esc_html((string) ($row['label'] ?? '')); ?></th>
                        <td><?php echo nl2br(osc_esc_html((string) ($row['value'] ?? '')), false); ?></td>
                    </tr>
                <?php } ?>
            </table>
        <?php } ?>
        <?php if ($page['footer'] !== '') { ?>
            <p class="rc-note"><?php echo nl2br(osc_esc_html($page['footer']), false); ?></p>
        <?php } ?>
    </main>
</div>
</body>
</html>
