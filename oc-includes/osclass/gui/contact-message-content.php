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
 * The pages behind the links in message mail -- markup only. 'confirm' sends a held message,
 * 'report' reports its sender; the GET page does neither, only its button does.
 */
$messageMode  = (string) __get('message_mode');
$messageDone  = (bool) __get('message_done');
$messageToken = (string) __get('message_token');
$reportSender = (string) __get('report_sender');
$reportDays   = (int) __get('report_days');
$isReport     = $messageMode === 'report';
?>
<div class="oe-message-link">
    <?php osc_show_flash_message(); ?>

    <?php if ($messageDone) { ?>
        <p><?php echo osc_esc_html($isReport
            ? sprintf(_m('Thank you. The sender cannot send messages on this site for %d days.'), $reportDays)
            : _m('Your message has been sent. Messages you send from this browser in the next 30 days go straight out.')); ?></p>
        <p><a href="<?php echo osc_esc_html(osc_base_url()); ?>"><?php echo osc_esc_html(_m('Go to the home page')); ?></a></p>
    <?php } elseif ($messageToken === '') { ?>
        <p><?php echo osc_esc_html(_m('This link is not valid or has expired.')); ?></p>
    <?php } else { ?>
        <p><?php echo osc_esc_html($isReport
            ? sprintf(
                _m('Report %1$s? They will not be able to send messages on this site for %2$d days. The site admin can see and undo this.'),
                $reportSender,
                $reportDays
            )
            : _m('Click the button to send your message.')); ?></p>
        <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
            <input type="hidden" name="page" value="contact" />
            <input type="hidden" name="action" value="<?php echo $isReport ? 'report_post' : 'confirm_post'; ?>" />
            <input type="hidden" name="t" value="<?php echo osc_esc_html($messageToken); ?>" />
            <div class="oe-actions">
                <button class="oe-btn<?php echo $isReport ? ' oe-btn-danger' : ''; ?>" type="submit"><?php
                    echo osc_esc_html($isReport ? _m('Report the sender') : _m('Send my message')); ?></button>
                <a class="oe-muted" href="<?php echo osc_esc_html(osc_base_url()); ?>"><?php echo osc_esc_html(_m('Cancel')); ?></a>
            </div>
        </form>
    <?php } ?>
</div>
