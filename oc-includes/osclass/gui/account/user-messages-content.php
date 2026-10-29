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
 * The signed-in user's conversations, newest first, with nothing open -- markup only.
 *
 * Reads the `conversations` view variable (see parts/messenger-list.php) and `older`.
 */

$messengerEmpty = (array) View::getInstance()->_get('conversations') === array();
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>
        <?php osc_run_hook('account_page_before', 'user-messages'); ?>

        <div class="oe-messenger<?php echo $messengerEmpty ? ' is-empty' : ''; ?>">
            <?php require __DIR__ . '/parts/messenger-list.php'; ?>
            <?php if (!$messengerEmpty) { ?>
                <section class="oe-messenger-chat oe-chat-empty" aria-label="<?php echo osc_esc_html(_m('Conversation')); ?>">
                    <p class="oe-muted"><?php echo osc_esc_html(_m('Choose a conversation')); ?></p>
                </section>
            <?php } ?>
        </div>
        <script src="<?php echo osc_esc_html(osc_asset_url_versioned(osc_assets_url('osclass/messages.js'))); ?>" defer></script>
        <?php if (osc_push_enabled()) { ?>
            <script src="<?php echo osc_esc_html(osc_asset_url_versioned(osc_assets_url('osclass/push.js'))); ?>" defer></script>
        <?php } ?>

        <?php osc_run_hook('account_page_after', 'user-messages'); ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
