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
 * The name cell of one printed line: title, options, SKU and tax code. Expects $line and $codeLabel.
 *
 * @var array<string,mixed> $line
 * @var string              $codeLabel
 */
?>
<td>
    <span class="rc-name"><?php echo osc_esc_html((string) ($line['title'] ?? '')); ?></span>
    <?php if ((string) ($line['options'] ?? '') !== '') { ?>
        <small><?php echo osc_esc_html((string) $line['options']); ?></small>
    <?php } ?>
    <?php if ((string) ($line['sku'] ?? '') !== '') { ?>
        <small><?php echo osc_esc_html(sprintf(__('SKU %s'), (string) $line['sku'])); ?></small>
    <?php } ?>
    <?php if (is_array($line['tax'] ?? null) && (string) ($line['tax']['code'] ?? '') !== '') { ?>
        <small><?php echo osc_esc_html($codeLabel . ' ' . (string) $line['tax']['code']); ?></small>
    <?php } ?>
</td>
