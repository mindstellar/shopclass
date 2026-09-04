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
 * A page core or a plugin owns, rendered inside the account layout -- markup
 * only. Core's credits pages arrive here, which is why the account nav is beside
 * it: whatever osc_render_file() prints is still an account page.
 */
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>
        <?php osc_render_file(); ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
