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
 * Write to whoever runs the site. The field names are core's contract --
 * CWebContact reads them from contact_post -- so every theme that shipped this
 * page was laying out a form it did not own.
 *
 * Both contact hooks fire, in the order and the places the bundled themes put
 * them: a plugin adding a field to one contact form expects it on all of them.
 * CSRF is injected on shutdown into any form not marked nocsrf.
 */

ob_start();
osc_run_hook('contact_page_aside');
$contactAside = trim((string) ob_get_clean());
?>
<div class="oe-contact">
    <div class="oe-contact-main">
        <?php osc_show_flash_message(); ?>

        <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post" name="contact_form">
            <input type="hidden" name="page" value="contact" />
            <input type="hidden" name="action" value="contact_post" />

            <div class="oe-field">
                <label class="oe-label" for="oe-contact-name"><?php echo osc_esc_html(_m('Your name')); ?></label>
                <input class="oe-input" id="oe-contact-name" type="text" name="yourName" autocomplete="name" required
                       value="<?php echo osc_esc_html(osc_gui_kept('yourName', osc_logged_user_name())); ?>" />
            </div>
            <div class="oe-field">
                <label class="oe-label" for="oe-contact-email"><?php echo osc_esc_html(_m('Your email address')); ?></label>
                <input class="oe-input" id="oe-contact-email" type="email" name="yourEmail" autocomplete="email" required
                       value="<?php echo osc_esc_html(osc_gui_kept('yourEmail', osc_logged_user_email())); ?>"
                       aria-describedby="oe-contact-email-hint" />
                <span class="oe-hint" id="oe-contact-email-hint"><?php
                    echo osc_esc_html(_m('We reply to this address.')); ?></span>
            </div>
            <?php osc_run_hook('contact_form_top'); ?>
            <div class="oe-field">
                <label class="oe-label" for="oe-contact-subject"><?php echo osc_esc_html(_m('Subject')); ?></label>
                <input class="oe-input" id="oe-contact-subject" type="text" name="subject" required
                       value="<?php echo osc_esc_html(osc_gui_kept('subject')); ?>" />
            </div>
            <div class="oe-field">
                <label class="oe-label" for="oe-contact-message"><?php echo osc_esc_html(_m('Message')); ?></label>
                <textarea class="oe-input" id="oe-contact-message" name="message" rows="6" required
                          minlength="10"><?php echo osc_esc_html(osc_gui_kept('message_body')); ?></textarea>
            </div>

            <?php if (osc_captcha_enabled()) { ?>
                <div class="oe-field"><?php osc_show_captcha('contact'); ?></div>
            <?php } ?>
            <?php osc_run_hook('contact_form'); ?>
            <?php osc_run_hook('admin_contact_form'); ?>

            <div class="oe-actions">
                <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Send message')); ?></button>
            </div>
        </form>
        <?php osc_run_hook('contact_form_after'); ?>
    </div>
    <?php if ($contactAside !== '') { ?>
        <aside class="oe-contact-aside"><?php echo $contactAside; ?></aside>
    <?php } ?>
</div>
