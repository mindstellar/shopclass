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
 * The tax of one line as a table cell: the rate, then the amount ("incl." when the price already
 * holds it). An empty cell when the line has no tax. Expects $lineTax (array or null) and $sign.
 *
 * @var array<string,mixed>|null $lineTax
 * @var string                   $sign
 */
$lineTax = is_array($lineTax ?? null) ? $lineTax : null;
$rate    = $lineTax === null ? '' : (string) ($lineTax['rate_text'] ?? '');
if ($lineTax !== null && $rate === '') {
    $rate = \mindstellar\billing\LineTaxView::rateText((array) ($lineTax['parts'] ?? array()));
}
?>
<td class="rc-num rc-tax"><?php if ($lineTax !== null) {
    echo osc_esc_html($rate);
    $amount = \mindstellar\billing\DocumentPage::signed($sign, (string) ($lineTax['amount_text'] ?? ''));
    ?><small><?php echo osc_esc_html(!empty($lineTax['included']) ? sprintf(__('%s incl.'), $amount) : $amount); ?></small><?php
} ?></td>
