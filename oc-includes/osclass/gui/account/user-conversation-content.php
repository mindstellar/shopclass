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
 * One conversation beside the list of conversations -- markup only.
 *
 * Reads the view variables named in parts/messenger-chat.php and parts/messenger-list.php.
 */

?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>
        <?php osc_run_hook('account_page_before', 'user-conversation'); ?>

        <div class="oe-messenger is-chat">
            <?php require __DIR__ . '/parts/messenger-list.php'; ?>
            <?php require __DIR__ . '/parts/messenger-chat.php'; ?>
        </div>
        <script src="<?php echo osc_esc_html(osc_asset_url_versioned(osc_assets_url('osclass/messages.js'))); ?>" defer></script>
        <?php if (osc_push_enabled()) { ?>
            <script src="<?php echo osc_esc_html(osc_asset_url_versioned(osc_assets_url('osclass/push.js'))); ?>" defer></script>
        <?php } ?>

        <?php osc_run_hook('account_page_after', 'user-conversation'); ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
