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
 * The list pane of the messenger: the head with the notification bell, the filter, and one row
 * per conversation -- markup only.
 *
 * Reads the `conversations` rows, `older` (the next page's link or null) and `current_id`.
 */

$listRows    = (array) View::getInstance()->_get('conversations');
$listOlder   = View::getInstance()->_get('older');
$listCurrent = (int) View::getInstance()->_get('current_id');
?>
<section class="oe-messenger-list" aria-labelledby="oe-list-title">
    <div class="oe-list-head">
        <h2 id="oe-list-title"><?php echo osc_esc_html(_m('Messages')); ?></h2>
        <?php $pushVariant = 'bell';
        require __DIR__ . '/push-optin.php'; ?>
        <div class="oe-list-filter" hidden>
            <label class="oe-sr-only" for="oe-list-filter"><?php echo osc_esc_html(_m('Search by name or listing')); ?></label>
            <input id="oe-list-filter" type="search" autocomplete="off"
                   placeholder="<?php echo osc_esc_html(_m('Search by name or listing')); ?>">
        </div>
        <div class="oe-list-tabs" role="group" aria-label="<?php echo osc_esc_html(_m('Show')); ?>" hidden>
            <button type="button" data-filter="all" aria-pressed="true"><?php echo osc_esc_html(_m('All')); ?></button>
            <button type="button" data-filter="unread" aria-pressed="false"><?php echo osc_esc_html(_m('Unread')); ?></button>
        </div>
    </div>
    <div data-osc-inbox
         data-check-url="<?php echo osc_esc_html(osc_base_url(true) . '?page=ajax&action=messages_check'); ?>"
         data-live-url="<?php echo osc_esc_html(osc_messaging_live_url()); ?>"
         data-typing-label="<?php echo osc_esc_html(_m('typing…')); ?>">
    <?php if ($listRows === array()) { ?>
        <p class="oe-muted"><?php echo osc_esc_html(_m('No conversations yet. When you contact a seller, or someone asks about your listing, the conversation shows here.')); ?>
            <a href="<?php echo osc_esc_html(osc_search_url()); ?>"><?php echo osc_esc_html(_m('Browse listings')); ?></a></p>
    <?php } else { ?>
        <ul class="oe-threads" aria-label="<?php echo osc_esc_html(_m('Conversations')); ?>">
            <?php foreach ($listRows as $listRow) {
                $rowState  = (string) $listRow['s_state'];
                $rowUnread = (int) $listRow['unread'];
                $rowBlock  = (int) $listRow['b_buyer_blocked'] === 1 || (int) $listRow['b_seller_blocked'] === 1;
                $rowFlag   = '';
                if ($rowState === 'closed') {
                    $rowFlag = _m('Closed');
                } elseif ($rowState === 'reported') {
                    $rowFlag = _m('Reported');
                } elseif ($rowState === 'held') {
                    $rowFlag = _m('Awaiting review');
                } elseif ($rowBlock) {
                    $rowFlag = _m('Blocked');
                } elseif (!$listRow['listing_live']) {
                    $rowFlag = _m('Removed');
                }
                $rowPreview = ($listRow['preview_mine'] ? _m('You') . ': ' : '') . $listRow['preview'];
                ?>
                <li class="oe-thread<?php echo $rowUnread > 0 ? ' is-unread' : ''; echo !empty($listRow['is_current']) ? ' is-current' : ''; ?>"
                    data-id="<?php echo (int) $listRow['pk_i_id']; ?>"
                    data-unread="<?php echo (int) $rowUnread; ?>"
                    data-search="<?php echo osc_esc_html(mb_strtolower($listRow['other_name'] . ' ' . $listRow['title'])); ?>">
                    <a class="oe-thread-link" href="<?php echo osc_esc_html((string) $listRow['url']); ?>"<?php
                        echo !empty($listRow['is_current']) ? ' aria-current="page"' : ''; ?>>
                        <?php if ($listRow['photo_url'] !== '') { ?>
                            <img class="oe-thread-photo" src="<?php echo osc_esc_html((string) $listRow['photo_url']); ?>" alt="" loading="lazy">
                        <?php } else { ?>
                            <span class="oe-thread-photo" aria-hidden="true"></span>
                        <?php } ?>
                        <span class="oe-thread-initial" aria-hidden="true"><?php echo osc_esc_html((string) $listRow['initial']); ?></span>
                        <span class="oe-thread-name"><?php echo osc_esc_html((string) $listRow['other_name']); ?></span>
                        <time class="oe-thread-time oe-thread-last" datetime="<?php
                            echo osc_esc_html((string) $listRow['dt_last_message']); ?>"><?php
                            echo osc_esc_html((string) $listRow['short_time']); ?></time>
                        <span class="oe-thread-title"><?php echo osc_esc_html((string) $listRow['title']); ?></span>
                        <span class="oe-thread-preview"><?php echo osc_esc_html($rowPreview); ?></span>
                        <?php if ($rowUnread > 0) { ?>
                            <span class="oe-unread"><span aria-hidden="true"><?php echo (int) $rowUnread; ?></span><span class="oe-sr-only"><?php
                                echo osc_esc_html(sprintf(_mn('%d new message', '%d new messages', $rowUnread), $rowUnread)); ?></span></span>
                        <?php }
                        if ($rowFlag !== '') { ?>
                            <span class="oe-thread-flag"><?php echo osc_esc_html($rowFlag); ?></span>
                        <?php } ?>
                    </a>
                </li>
            <?php } ?>
        </ul>
        <?php if (!empty($listOlder)) {
            osc_gui_actions(array(array('label' => _m('Older'), 'href' => (string) $listOlder, 'style' => 'secondary')));
        }
    } ?>
    </div>
</section>
