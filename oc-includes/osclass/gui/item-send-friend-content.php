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
 * Pass a listing on to someone else. Core owns the field names -- CWebItem reads
 * them from send_friend_post -- and both contact hooks fire here as they do on
 * the other contact forms, so a plugin adding a field reaches all of them.
 */
?>
<div class="oe-form-page">
    <?php osc_show_flash_message(); ?>

    <p class="oe-muted"><?php printf(
        osc_esc_html(_m('About “%s”')),
        osc_esc_html(osc_item_title())
    ); ?></p>

    <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post" name="sendfriend">
        <input type="hidden" name="page" value="item" />
        <input type="hidden" name="action" value="send_friend_post" />
        <input type="hidden" name="id" value="<?php echo (int) osc_item_id(); ?>" />

        <div class="oe-field">
            <label class="oe-label" for="oe-sf-your-name"><?php echo osc_esc_html(_m('Your name')); ?></label>
            <input class="oe-input" id="oe-sf-your-name" type="text" name="yourName" autocomplete="name" required
                   value="<?php echo osc_esc_html(osc_logged_user_name()); ?>" />
        </div>
        <div class="oe-field">
            <label class="oe-label" for="oe-sf-your-email"><?php echo osc_esc_html(_m('Your email address')); ?></label>
            <input class="oe-input" id="oe-sf-your-email" type="email" name="yourEmail" autocomplete="email" required
                   value="<?php echo osc_esc_html(osc_logged_user_email()); ?>" />
        </div>
        <div class="oe-field">
            <label class="oe-label" for="oe-sf-friend-name"><?php echo osc_esc_html(_m('Their name')); ?></label>
            <input class="oe-input" id="oe-sf-friend-name" type="text" name="friendName" required />
        </div>
        <div class="oe-field">
            <label class="oe-label" for="oe-sf-friend-email"><?php
                echo osc_esc_html(_m('Their email address')); ?></label>
            <input class="oe-input" id="oe-sf-friend-email" type="email" name="friendEmail" required
                   aria-describedby="oe-sf-friend-email-hint" />
            <span class="oe-hint" id="oe-sf-friend-email-hint"><?php
                echo osc_esc_html(_m('We send them a link to this listing, nothing else.')); ?></span>
        </div>
        <div class="oe-field">
            <label class="oe-label" for="oe-sf-message"><?php echo osc_esc_html(_m('Message')); ?></label>
            <textarea class="oe-input" id="oe-sf-message" name="message" rows="4"></textarea>
        </div>

        <?php osc_run_hook('contact_form'); ?>
        <?php osc_run_hook('admin_contact_form'); ?>

        <div class="oe-actions">
            <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Send')); ?></button>
        </div>
    </form>
</div>
