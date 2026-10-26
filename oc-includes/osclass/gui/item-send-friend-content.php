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

        <?php osc_gui_field('oe-sf-your-name', _m('Your name'), '<input class="oe-input" id="oe-sf-your-name" type="text" name="yourName" autocomplete="name" required'
            . ' value="' . osc_esc_html(osc_gui_kept('yourName', osc_logged_user_name())) . '" />'); ?>
        <?php osc_gui_field('oe-sf-your-email', _m('Your email address'), '<input class="oe-input" id="oe-sf-your-email" type="email" name="yourEmail" autocomplete="email" required'
            . ' value="' . osc_esc_html(osc_gui_kept('yourEmail', osc_logged_user_email())) . '" />'); ?>
        <?php osc_gui_field('oe-sf-friend-name', _m('Their name'), '<input class="oe-input" id="oe-sf-friend-name" type="text" name="friendName" required'
            . ' value="' . osc_esc_html(osc_gui_kept('friendName')) . '" />'); ?>
        <?php osc_gui_field('oe-sf-friend-email', _m('Their email address'), '<input class="oe-input" id="oe-sf-friend-email" type="email" name="friendEmail" required'
            . ' value="' . osc_esc_html(osc_gui_kept('friendEmail')) . '" aria-describedby="oe-sf-friend-email-hint" />',
            _m('We send them a link to this listing, nothing else.')); ?>
        <?php $sfMax = \mindstellar\security\MessageGuard::maxLength();
        osc_gui_field('oe-sf-message', _m('Message'), '<textarea class="oe-input" id="oe-sf-message" name="message" rows="4"'
            . ($sfMax > 0 ? ' maxlength="' . $sfMax . '"' : '') . '>' . osc_esc_html(osc_gui_kept('message_body')) . '</textarea>'); ?>

        <?php if (osc_captcha_enabled()) { ?>
            <div class="oe-field"><?php osc_show_captcha('send_friend'); ?></div>
        <?php } ?>
        <?php osc_run_hook('contact_form'); ?>
        <?php osc_run_hook('admin_contact_form'); ?>

        <?php osc_gui_actions(array(array('label' => _m('Send')))); ?>
    </form>
</div>
