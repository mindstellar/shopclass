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
 * A row of tabs. Reads $tabs: label (what the row is, for screen readers), items
 * (key => [label, url]) and current (the key of the page being shown).
 */

/** @var array<string, mixed> $tabs */
?>
<nav class="oe-tabs" aria-label="<?php echo osc_esc_html((string) $tabs['label']); ?>">
    <?php foreach ((array) $tabs['items'] as $tabKey => [$tabLabel, $tabUrl]) { ?>
        <a href="<?php echo osc_esc_html((string) $tabUrl); ?>"<?php
            echo (string) ($tabs['current'] ?? '') === (string) $tabKey ? ' aria-current="page"' : ''; ?>><?php
            echo osc_esc_html((string) $tabLabel); ?></a>
    <?php } ?>
</nav>
