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
 * The pages behind the links in message mail, markup only: 'confirm' shows a held message
 * to send or delete, 'report' reports its sender; only the buttons change anything.
 */
$isReport  = __get('message_mode') === 'report';
$done      = (string) __get('message_done');
$token     = (string) __get('message_token');
$sender    = (string) __get('report_sender');
$permanent = (bool) __get('report_permanent');
$canSubmit = $token !== '' && (!$permanent || osc_is_admin_user_logged_in());

if ($done === 'deleted') {
    $intro = _m('The message has been deleted. Nothing was sent.');
} elseif ($done !== '') {
    $intro = $isReport
        ? _m('Thank you. The sender has been reported.')
        : _m('Your message has been sent. Messages you send from this browser in the next 30 days go straight out.');
} elseif ($token === '') {
    $intro = _m('This link is not valid or has expired.');
} elseif (!$isReport) {
    $intro = sprintf(_m('This message is waiting to be sent from your address to %s.'), (string) __get('message_to'));
} elseif ($permanent && !$canSubmit) {
    $intro = _m('Only a site admin can ban a sender for good. Sign in to the admin in this browser, then open the link again.');
} elseif ($permanent) {
    $intro = sprintf(
        _m('Ban %s from this site for good? They will not be able to sign in, post or send messages. You can undo this under Users → Ban rules.'),
        $sender
    );
} else {
    $intro = sprintf(
        _m('Report %1$s? They will not be able to send messages on this site for %2$d days. The site admin can see and undo this.'),
        $sender,
        (int) __get('report_days')
    );
}
?>
<div class="oe-message-link">
    <?php osc_show_flash_message(); ?>
    <p><?php echo osc_esc_html($intro); ?></p>

    <?php if ($done !== '') { ?>
        <p><a href="<?php echo osc_esc_html(osc_base_url()); ?>"><?php echo osc_esc_html(_m('Go to the home page')); ?></a></p>
    <?php } elseif ($permanent && !$canSubmit) { ?>
        <p><a href="<?php echo osc_esc_html(osc_admin_base_url()); ?>"><?php echo osc_esc_html(_m('Sign in to the admin')); ?></a></p>
    <?php } elseif ($canSubmit) { ?>
        <?php if (!$isReport) { ?>
            <blockquote class="oe-message-text"><?php echo nl2br(osc_esc_html((string) __get('message_text'))); ?></blockquote>
            <p><?php echo osc_esc_html(_m('Did you not write this? Delete it, and nothing is sent.')); ?></p>
        <?php } ?>
        <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
            <input type="hidden" name="page" value="contact" />
            <input type="hidden" name="action" value="<?php echo $isReport ? 'report_post' : 'confirm_post'; ?>" />
            <input type="hidden" name="t" value="<?php echo osc_esc_html($token); ?>" />
            <div class="oe-actions">
                <?php if ($isReport) { ?>
                    <button class="oe-btn oe-btn-danger" type="submit"><?php echo osc_esc_html(_m('Report the sender')); ?></button>
                    <a class="oe-muted" href="<?php echo osc_esc_html(osc_base_url()); ?>"><?php echo osc_esc_html(_m('Cancel')); ?></a>
                <?php } else { ?>
                    <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Send my message')); ?></button>
                    <button class="oe-btn oe-btn-danger" type="submit" name="discard" value="1"><?php
                        echo osc_esc_html(_m('This was not me, delete it')); ?></button>
                <?php } ?>
            </div>
        </form>
    <?php } ?>
</div>
