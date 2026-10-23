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
 * The signed-in user's conversations, newest first -- markup only.
 *
 * Reads the `conversations` view variable (each row carries title, other_name,
 * unread, url) and `older`, the link to the next page or null.
 */

$inboxRows  = (array) View::getInstance()->_get('conversations');
$inboxOlder = View::getInstance()->_get('older');
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>
        <?php osc_run_hook('account_page_before', 'user-messages'); ?>

        <?php if ($inboxRows === array()) { ?>
            <p class="oe-empty"><?php echo osc_esc_html(_m('No messages yet.')); ?></p>
        <?php } else { ?>
            <ul class="oe-threads">
                <?php foreach ($inboxRows as $inboxRow) {
                    $inboxState  = (string) $inboxRow['s_state'];
                    $inboxUnread = (int) $inboxRow['unread'];
                    $inboxBlock  = (int) $inboxRow['b_buyer_blocked'] === 1 || (int) $inboxRow['b_seller_blocked'] === 1;
                    ?>
                    <li class="oe-thread oe-panel">
                        <h2 class="oe-thread-title"><a href="<?php echo osc_esc_html((string) $inboxRow['url']); ?>"><?php
                            echo osc_esc_html((string) $inboxRow['title']); ?></a></h2>
                        <p class="oe-meta">
                            <span><?php echo osc_esc_html((string) $inboxRow['other_name']); ?></span>
                            <span class="oe-thread-last"><time datetime="<?php
                                echo osc_esc_html((string) $inboxRow['dt_last_message']); ?>"><?php
                                echo osc_esc_html(osc_format_date((string) $inboxRow['dt_last_message'])); ?></time></span>
                            <?php if ($inboxUnread > 0) { ?>
                                <span class="oe-badge oe-unread"><?php echo osc_esc_html(sprintf(
                                    _mn('%d new message', '%d new messages', $inboxUnread),
                                    $inboxUnread
                                )); ?></span>
                            <?php }
                            if ($inboxState === 'held') { ?>
                                <span class="oe-badge pending"><?php echo osc_esc_html(_m('Awaiting review')); ?></span>
                            <?php }
                            if ($inboxState === 'reported') { ?>
                                <span class="oe-badge failed"><?php echo osc_esc_html(_m('Reported')); ?></span>
                            <?php }
                            if ($inboxState === 'closed') { ?>
                                <span class="oe-badge refunded"><?php echo osc_esc_html(_m('Closed')); ?></span>
                            <?php }
                            if ($inboxBlock) { ?>
                                <span class="oe-badge refunded"><?php echo osc_esc_html(_m('Blocked')); ?></span>
                            <?php } ?>
                        </p>
                    </li>
                <?php } ?>
            </ul>
            <?php if (!empty($inboxOlder)) { ?>
                <div class="oe-actions">
                    <a class="oe-btn oe-secondary" href="<?php echo osc_esc_html((string) $inboxOlder); ?>"><?php
                        echo osc_esc_html(_m('Older')); ?></a>
                </div>
            <?php }
        } ?>

        <?php osc_run_hook('account_page_after', 'user-messages'); ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
