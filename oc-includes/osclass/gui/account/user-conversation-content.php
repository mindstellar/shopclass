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
 * One conversation: the listing, the messages oldest first, a compose box and
 * the block and report actions -- markup only.
 *
 * Reads the `conversation`, `messages`, `names`, `title`, `item_url`, `can_send`,
 * `my_block` and `their_block` view variables.
 */

$convoView  = View::getInstance();
$convo      = (array) $convoView->_get('conversation');
$convoMsgs  = (array) $convoView->_get('messages');
$convoNames = (array) $convoView->_get('names');
$convoTitle = (string) $convoView->_get('title');
$convoItem  = $convoView->_get('item_url');
$convoCan   = (string) $convoView->_get('can_send');
$myBlock    = (bool) $convoView->_get('my_block');
$theirBlock = (bool) $convoView->_get('their_block');
$convoId    = (int) $convo['pk_i_id'];
$convoState = (string) $convo['s_state'];
$meId       = (int) osc_logged_user_id();
$convoMax   = \mindstellar\security\MessageGuard::maxLength();
$convoFmt   = osc_date_format() . ' ' . osc_time_format();
$lastId     = $convoMsgs === array() ? 0 : (int) end($convoMsgs)['pk_i_id'];

$otherName  = (string) ($convoNames[\mindstellar\messaging\ConversationStore::otherParty($convo, $meId)] ?? _m('Former user'));

$stopLine = '';
if ($convoCan === \mindstellar\messaging\MessagePolicy::CLOSED) {
    $stopLine = _m('This conversation is closed.');
} elseif ($convoState === 'reported') {
    $stopLine = _m('This conversation was reported and is waiting for review.');
} elseif ($myBlock) {
    $stopLine = _m('You blocked this conversation.');
} elseif ($theirBlock) {
    $stopLine = _m('The other person blocked this conversation.');
}

$convoActions = array();
if ($myBlock) {
    $convoActions['unblock'] = array(
        'label'  => _m('Unblock'),
        'url'    => osc_base_url(true),
        'method' => 'post',
        'fields' => array('page' => 'messages', 'action' => 'unblock_post', 'id' => $convoId),
    );
} elseif ($convoState !== 'closed') {
    $convoActions['block'] = array(
        'label'   => _m('Block'),
        'url'     => osc_base_url(true),
        'method'  => 'post',
        'fields'  => array('page' => 'messages', 'action' => 'block_post', 'id' => $convoId),
        'class'   => 'oe-danger-link',
        'confirm' => _m('Block this conversation? Neither of you can write until it is unblocked.'),
    );
}
$rowActions = $convoActions;
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>
        <?php osc_run_hook('account_page_before', 'user-conversation'); ?>

        <p class="oe-meta">
            <a href="<?php echo osc_esc_html(osc_messaging_inbox_url()); ?>"><?php
                echo osc_esc_html(_m('All messages')); ?></a>
        </p>
        <h2 class="oe-thread-title oe-panel__title">
            <?php if (!empty($convoItem)) { ?>
                <a href="<?php echo osc_esc_html((string) $convoItem); ?>"><?php echo osc_esc_html($convoTitle); ?></a>
            <?php } else { ?>
                <?php echo osc_esc_html($convoTitle); ?>
                <span class="oe-badge refunded"><?php echo osc_esc_html(_m('Removed')); ?></span>
            <?php } ?>
        </h2>

        <?php if ($convoMsgs === array()) {
            osc_gui_empty(_m('No messages yet.'));
        } ?>
        <ol class="oe-messages" data-osc-messages
            data-conversation="<?php echo (int) $convoId; ?>"
            data-last-id="<?php echo (int) $lastId; ?>"
            data-me="<?php echo (int) $meId; ?>"
            data-format="<?php echo osc_esc_html($convoFmt); ?>"
            data-send-url="<?php echo osc_esc_html(osc_base_url(true) . '?page=ajax&action=messages_send'); ?>"
            data-since-url="<?php echo osc_esc_html(osc_base_url(true) . '?page=ajax&action=messages_since'); ?>"
            data-check-url="<?php echo osc_esc_html(osc_base_url(true) . '?page=ajax&action=messages_check'); ?>"
            data-live-url="<?php echo osc_esc_html(osc_messaging_live_url()); ?>"
            data-held-label="<?php echo osc_esc_html(_m('Awaiting review')); ?>"
            data-other-label="<?php echo osc_esc_html($otherName); ?>"
            data-me-label="<?php echo osc_esc_html(_m('You')); ?>">
            <?php foreach ($convoMsgs as $convoMsg) {
                $isMine = (int) $convoMsg['fk_i_sender_id'] === $meId;
                $isHeld = (string) $convoMsg['s_state'] === 'held';
                $author = $isMine ? _m('You') : (string) ($convoNames[(int) $convoMsg['fk_i_sender_id']] ?? _m('Former user'));
                ?>
                <li class="oe-message<?php echo $isMine ? ' is-mine' : ''; echo $isHeld ? ' is-held' : ''; ?>"
                    data-id="<?php echo (int) $convoMsg['pk_i_id']; ?>">
                    <div class="oe-message-body"><?php echo osc_esc_html((string) $convoMsg['s_body']); ?></div>
                    <p class="oe-message-meta">
                        <span><?php echo osc_esc_html($author); ?></span>
                        <time datetime="<?php echo osc_esc_html((string) $convoMsg['dt_created']); ?>"><?php
                            echo osc_esc_html(osc_format_date((string) $convoMsg['dt_created'], $convoFmt)); ?></time>
                        <?php if ($isHeld) { ?>
                            <span class="oe-badge pending"><?php echo osc_esc_html(_m('Awaiting review')); ?></span>
                        <?php } ?>
                    </p>
                </li>
            <?php } ?>
        </ol>

        <?php if ($stopLine !== '') { ?>
            <p class="oe-muted"><?php echo osc_esc_html($stopLine); ?></p>
        <?php } else { ?>
            <form class="oe-compose" method="post" action="<?php echo osc_esc_html(osc_base_url(true)); ?>"
                  data-error-text="<?php echo osc_esc_html(_m('The message could not be sent. Try again.')); ?>">
                <?php $composeError = osc_gui_kept('contact_error');
                if ($composeError !== '') { ?>
                    <p class="oe-form-error" role="alert"><?php echo osc_esc_html($composeError); ?></p>
                <?php } ?>
                <input type="hidden" name="page" value="messages" />
                <input type="hidden" name="action" value="send_post" />
                <input type="hidden" name="id" value="<?php echo (int) $convoId; ?>" />
                <?php osc_gui_field('oe-compose-body', _m('Your message'), static function () use ($convoMax): void { ?>
                    <textarea id="oe-compose-body" name="body" rows="4" required<?php
                        echo $convoMax > 0 ? ' maxlength="' . (int) $convoMax . '"' : ''; ?>><?php
                        echo osc_esc_html(osc_gui_kept('body')); ?></textarea>
                <?php }); ?>
                <?php osc_gui_actions(array(array('label' => _m('Send')))); ?>
            </form>
            <?php require __DIR__ . '/parts/push-optin.php'; ?>
        <?php } ?>

        <div class="oe-thread-actions">
            <?php require __DIR__ . '/parts/row-actions.php'; ?>
            <?php if ($convoState === 'open') { ?>
                <details>
                    <summary><?php echo osc_esc_html(_m('Report')); ?></summary>
                    <form method="post" action="<?php echo osc_esc_html(osc_base_url(true)); ?>">
                        <input type="hidden" name="page" value="messages" />
                        <input type="hidden" name="action" value="report_post" />
                        <input type="hidden" name="id" value="<?php echo (int) $convoId; ?>" />
                        <fieldset>
                            <legend><?php echo osc_esc_html(_m('Why are you reporting this conversation?')); ?></legend>
                            <?php $reasonLabels = array(
                                'spam'      => _m('Spam'),
                                'scam'      => _m('Scam or fraud'),
                                'offensive' => _m('Offensive or abusive'),
                                'other'     => _m('Something else'),
                            );
                            foreach (\mindstellar\messaging\MessagePolicy::REPORT_REASONS as $reasonCode) { ?>
                                <label class="oe-check">
                                    <input type="radio" name="reason" value="<?php echo osc_esc_html($reasonCode); ?>" required />
                                    <?php echo osc_esc_html($reasonLabels[$reasonCode] ?? $reasonCode); ?>
                                </label>
                            <?php } ?>
                        </fieldset>
                        <?php osc_gui_actions(array(array('label' => _m('Report conversation'), 'style' => 'danger'))); ?>
                    </form>
                </details>
            <?php } ?>
        </div>

        <?php if ($stopLine === '') { ?>
            <script src="<?php echo osc_esc_html(osc_asset_url_versioned(osc_assets_url('osclass/messages.js'))); ?>" defer></script>
            <?php if (osc_push_enabled()) { ?>
                <script src="<?php echo osc_esc_html(osc_asset_url_versioned(osc_assets_url('osclass/push.js'))); ?>" defer></script>
            <?php } ?>
        <?php } ?>

        <?php osc_run_hook('account_page_after', 'user-conversation'); ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
