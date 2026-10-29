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
 * The chat pane of the messenger: the person and listing, the messages oldest first, and the
 * composer or the line that replaces it -- markup only.
 *
 * Reads the `conversation`, `messages`, `names`, `other_name`, `other_initial`, `seen_at`, `title`,
 * `item_url`, `listing_live`, `listing_photo`, `listing_price`, `role`, `their_unread`, `server_now`,
 * `can_send`, `my_block` and `their_block` view variables.
 */

use mindstellar\messaging\MessagePolicy;
use mindstellar\messaging\MessageTime;
use mindstellar\security\MessageGuard;

$convoView   = View::getInstance();
$convo       = (array) $convoView->_get('conversation');
$convoMsgs   = array_values((array) $convoView->_get('messages'));
$convoNames  = (array) $convoView->_get('names');
$convoTitle  = (string) $convoView->_get('title');
$convoItem   = $convoView->_get('item_url');
$convoPhoto  = (string) $convoView->_get('listing_photo');
$convoLive   = !empty($convoItem) && (bool) $convoView->_get('listing_live');
$convoPrice  = (string) $convoView->_get('listing_price');
$convoRole   = (string) $convoView->_get('role');
$theirUnread = (int) $convoView->_get('their_unread');
$serverNow   = (int) $convoView->_get('server_now');
$convoCan    = (string) $convoView->_get('can_send');
$myBlock     = (bool) $convoView->_get('my_block');
$theirBlock  = (bool) $convoView->_get('their_block');
$convoId     = (int) $convo['pk_i_id'];
$convoState  = (string) $convo['s_state'];
$meId        = (int) osc_logged_user_id();
$convoMax    = MessageGuard::maxLength();
$convoFmt    = osc_date_format() . ' ' . osc_time_format();
$lastId      = $convoMsgs === array() ? 0 : (int) end($convoMsgs)['pk_i_id'];
$liveUrl     = osc_messaging_live_url();

$otherName    = (string) $convoView->_get('other_name');
$otherInitial = (string) $convoView->_get('other_initial');
$otherHue     = (int) $convoView->_get('other_hue');
$seenAt       = $convoView->_exists('seen_at') ? (int) $convoView->_get('seen_at') : -1;
$postUrl      = osc_base_url(true);

$stopLine = '';
if ($convoCan === MessagePolicy::CLOSED) {
    $stopLine = _m('This conversation is closed.');
} elseif ($convoState === 'reported') {
    $stopLine = _m('This conversation was reported and is waiting for review.');
} elseif ($myBlock) {
    $stopLine = _m('You blocked this conversation.');
} elseif ($theirBlock) {
    $stopLine = _m('The other person blocked this conversation.');
}
$canUnblock = $myBlock && $convoCan !== MessagePolicy::CLOSED && $convoState !== 'reported';

$postForm = static function (string $action, string $label, string $class, string $confirm = '') use ($convoId, $postUrl): void { ?>
    <form class="oe-inline-form nocsrf" method="post" action="<?php echo osc_esc_html($postUrl); ?>">
        <?php echo osc_csrf_token_form(); ?>
        <input type="hidden" name="page" value="messages">
        <input type="hidden" name="action" value="<?php echo osc_esc_html($action); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $convoId; ?>">
        <button type="submit" class="<?php echo osc_esc_html($class); ?>"<?php
            echo $confirm !== '' ? ' data-osc-confirm="' . osc_esc_html($confirm) . '"' . ' data-dialog-cancel="' . osc_esc_html(_m('Cancel')) . '" data-dialog-close="' . osc_esc_html(_m('Close')) . '"' : ''; ?>><?php
            echo osc_esc_html($label); ?></button>
    </form>
<?php };
$blockConfirm = _m('Block this conversation? Neither of you can write until it is unblocked.');
?>
<section class="oe-messenger-chat" aria-labelledby="oe-chat-name">
    <div class="oe-chat-head">
        <a class="oe-chat-back" href="<?php echo osc_esc_html(osc_messaging_inbox_url()); ?>"
           aria-label="<?php echo osc_esc_html(_m('All messages')); ?>">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <span class="oe-thread-initial" style="--oe-hue:<?php echo (int) $otherHue; ?>" aria-hidden="true"><?php echo osc_esc_html($otherInitial); ?></span>
        <h2 class="oe-chat-name" id="oe-chat-name"><?php echo osc_esc_html($otherName); ?></h2>
        <div class="oe-chat-role" data-role="<?php echo osc_esc_html($convoRole); ?>"><?php echo osc_esc_html($convoRole); ?></div>
        <details class="oe-menu">
            <summary class="oe-icon-btn" aria-label="<?php echo osc_esc_html(_m('Conversation options')); ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>
            </summary>
            <div class="oe-menu-panel oe-thread-actions">
                <?php if (osc_push_enabled() && osc_push_public_key() !== '') { ?>
                    <button type="button" class="oe-menu-item" data-osc-push-toggle hidden><?php
                        echo osc_esc_html(_m('Notify me on this device')); ?></button>
                    <p class="oe-menu-note" data-osc-push-menu-note hidden></p>
                <?php }
                if ($myBlock) {
                    $postForm('unblock_post', _m('Unblock'), 'oe-menu-item');
                } elseif ($convoState !== 'closed') {
                    $postForm('block_post', _m('Block'), 'oe-menu-item oe-danger-link', $blockConfirm);
                }
                if ($convoState === 'open') { ?>
                    <details class="oe-menu-report">
                        <summary class="oe-menu-item"><?php echo osc_esc_html(_m('Report')); ?></summary>
                        <form method="post" action="<?php echo osc_esc_html($postUrl); ?>"
                              data-dialog-title="<?php echo osc_esc_html(_m('Report conversation')); ?>"
                              data-dialog-text="<?php echo osc_esc_html(_m('The site team reviews it. Neither of you can write while it is under review.')); ?>"
                              data-dialog-cancel="<?php echo osc_esc_html(_m('Cancel')); ?>"
                              data-dialog-close="<?php echo osc_esc_html(_m('Close')); ?>">
                            <input type="hidden" name="page" value="messages">
                            <input type="hidden" name="action" value="report_post">
                            <input type="hidden" name="id" value="<?php echo (int) $convoId; ?>">
                            <fieldset>
                                <legend><?php echo osc_esc_html(_m('Why are you reporting this conversation?')); ?></legend>
                                <?php $reasonLabels = array(
                                    'spam'      => _m('Spam'),
                                    'scam'      => _m('Scam or fraud'),
                                    'offensive' => _m('Offensive or abusive'),
                                    'other'     => _m('Something else'),
                                );
                                foreach (MessagePolicy::REPORT_REASONS as $reasonCode) { ?>
                                    <label class="oe-check">
                                        <input type="radio" name="reason" value="<?php echo osc_esc_html($reasonCode); ?>" required>
                                        <?php echo osc_esc_html($reasonLabels[$reasonCode] ?? $reasonCode); ?>
                                    </label>
                                <?php } ?>
                            </fieldset>
                            <?php osc_gui_actions(array(array('label' => _m('Report conversation'), 'style' => 'danger'))); ?>
                        </form>
                    </details>
                <?php } ?>
            </div>
        </details>
    </div>

    <div class="oe-listing-card<?php echo $convoLive ? '' : ' is-removed'; ?>">
        <?php if ($convoPhoto !== '') { ?>
            <img class="oe-listing-photo" src="<?php echo osc_esc_html($convoPhoto); ?>" alt="" loading="lazy">
        <?php } else { ?>
            <span class="oe-listing-photo" aria-hidden="true"></span>
        <?php }
        if ($convoLive) { ?>
            <a class="oe-listing-title" href="<?php echo osc_esc_html((string) $convoItem); ?>"><?php echo osc_esc_html($convoTitle); ?></a>
            <span class="oe-listing-price"><?php echo osc_esc_html($convoPrice); ?></span>
            <a class="oe-listing-link" href="<?php echo osc_esc_html((string) $convoItem); ?>" tabindex="-1" aria-hidden="true"><?php
                echo osc_esc_html(_m('View listing')); ?> &#8599;</a>
        <?php } else { ?>
            <span class="oe-listing-title"><?php echo osc_esc_html($convoTitle); ?></span>
            <span class="oe-thread-flag"><?php echo osc_esc_html(_m('Removed')); ?></span>
        <?php } ?>
    </div>

    <ol class="oe-messages" data-osc-messages role="log" aria-label="<?php echo osc_esc_html(_m('Messages')); ?>"
        data-conversation="<?php echo (int) $convoId; ?>"
        data-last-id="<?php echo (int) $lastId; ?>"
        data-me="<?php echo (int) $meId; ?>"
        data-format="<?php echo osc_esc_html($convoFmt); ?>"
        data-send-url="<?php echo osc_esc_html($postUrl . '?page=ajax&action=messages_send'); ?>"
        data-since-url="<?php echo osc_esc_html($postUrl . '?page=ajax&action=messages_since'); ?>"
        data-check-url="<?php echo osc_esc_html($postUrl . '?page=ajax&action=messages_check'); ?>"
        data-live-url="<?php echo osc_esc_html($liveUrl); ?>"
        <?php if ($liveUrl !== '') { ?>data-typing-url="<?php echo osc_esc_html($postUrl . '?page=ajax&action=messages_typing'); ?>"
        <?php } ?>data-server-now="<?php echo (int) $serverNow; ?>"
        data-their-unread="<?php echo (int) $theirUnread; ?>"
        data-held-label="<?php echo osc_esc_html(_m('Awaiting review')); ?>"
        data-seen-label="<?php echo osc_esc_html(_m('Seen')); ?>"
        data-typing-label="<?php echo osc_esc_html(_m('typing…')); ?>"
        data-other-label="<?php echo osc_esc_html($otherName); ?>"
        data-me-label="<?php echo osc_esc_html(_m('You')); ?>">
        <?php foreach ($convoMsgs as $i => $convoMsg) {
            $prev   = $convoMsgs[$i - 1] ?? null;
            $next   = $convoMsgs[$i + 1] ?? null;
            $at     = (string) $convoMsg['dt_created'];
            $sender = (int) $convoMsg['fk_i_sender_id'];
            $isMine = $sender === $meId;
            $isHeld = (string) $convoMsg['s_state'] === 'held';
            $author = $isMine ? _m('You') : (string) ($convoNames[$sender] ?? _m('Former user'));
            $newDay = $prev === null || MessageTime::dayKey((string) $prev['dt_created']) !== MessageTime::dayKey($at);
            $joins  = static fn (?array $other): bool => $other !== null && (int) $other['fk_i_sender_id'] === $sender
                && MessageTime::dayKey((string) $other['dt_created']) === MessageTime::dayKey($at)
                && abs(strtotime((string) $other['dt_created']) - strtotime($at)) <= 600;
            $isFirst = !$joins($prev);
            $isLast  = !$joins($next);
            if ($newDay) { ?>
                <li class="oe-day"><span><?php echo osc_esc_html(MessageTime::day($at, $serverNow ?: null)); ?></span></li>
            <?php } ?>
            <li class="oe-message<?php echo $isMine ? ' is-mine' : ''; echo $isHeld ? ' is-held' : '';
                echo $isFirst ? ' is-first' : ''; echo $isLast ? ' is-last' : ''; ?>"
                data-id="<?php echo (int) $convoMsg['pk_i_id']; ?>">
                <span class="oe-sr-only"><?php echo osc_esc_html($author . ', ' . MessageTime::clock($at) . ': '); ?></span>
                <div class="oe-message-body"><?php echo osc_esc_html((string) $convoMsg['s_body']); ?></div>
                <?php if ($isLast || $isHeld || $i === $seenAt) { ?>
                    <p class="oe-message-meta">
                        <?php if ($isLast) { ?>
                            <time datetime="<?php echo osc_esc_html($at); ?>"><?php echo osc_esc_html(MessageTime::clock($at)); ?></time>
                        <?php }
                        if ($isHeld) { ?>
                            <span class="oe-held"><?php echo osc_esc_html(_m('Awaiting review')); ?></span>
                        <?php }
                        if ($i === $seenAt) { ?>
                            <span class="oe-seen"><?php echo osc_esc_html(_m('Seen')); ?></span>
                        <?php } ?>
                    </p>
                <?php } ?>
            </li>
        <?php } ?>
    </ol>
    <?php if ($liveUrl !== '') { ?>
        <div class="oe-typing" hidden><div class="oe-typing-bubble"><span class="oe-sr-only"><?php
            echo osc_esc_html(_m('Typing')); ?></span><i></i><i></i><i></i></div></div>
    <?php } ?>

    <?php if ($stopLine !== '') { ?>
        <div class="oe-stopbar">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/></svg>
            <p><?php echo osc_esc_html($stopLine); ?></p>
            <?php if ($canUnblock) {
                $postForm('unblock_post', _m('Unblock'), 'oe-btn oe-secondary');
            } ?>
        </div>
    <?php } else { ?>
        <form class="oe-compose" method="post" action="<?php echo osc_esc_html($postUrl); ?>"
              data-error-text="<?php echo osc_esc_html(_m('The message could not be sent. Try again.')); ?>">
            <?php $composeError = osc_gui_kept('contact_error');
            if ($composeError !== '') { ?>
                <p class="oe-form-error" role="alert"><?php echo osc_esc_html($composeError); ?></p>
            <?php } ?>
            <input type="hidden" name="page" value="messages">
            <input type="hidden" name="action" value="send_post">
            <input type="hidden" name="id" value="<?php echo (int) $convoId; ?>">
            <div class="oe-compose-row">
                <label class="oe-sr-only" for="oe-compose-body"><?php echo osc_esc_html(_m('Your message')); ?></label>
                <textarea id="oe-compose-body" name="body" rows="1" required
                          placeholder="<?php echo osc_esc_html(_m('Write a message')); ?>"<?php
                    echo $convoMax > 0 ? ' maxlength="' . (int) $convoMax . '"' : ''; ?>><?php
                    echo osc_esc_html(osc_gui_kept('body')); ?></textarea>
                <button class="oe-compose-send" type="submit" aria-label="<?php echo osc_esc_html(_m('Send')); ?>">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14.5 21.7a.5.5 0 0 0 .9 0l6.5-19a.5.5 0 0 0-.6-.6l-19 6.5a.5.5 0 0 0 0 .9l7.9 3.2a2 2 0 0 1 1.1 1.1zM21.9 2.1 10.9 13.1"/></svg>
                </button>
            </div>
            <div class="oe-compose-foot">
                <span class="oe-compose-hint"><?php echo osc_esc_html(_m('Enter to send · Shift + Enter for a new line')); ?></span>
                <span class="oe-compose-counter" aria-live="polite" data-label="<?php
                    /* translators: %d is the number of characters left */
                    echo osc_esc_html(_m('%d left')); ?>"></span>
            </div>
        </form>
    <?php } ?>
    <?php if (!$myBlock && $convoState !== 'closed') {
        osc_gui_print_confirm_script();
    } ?>
</section>
