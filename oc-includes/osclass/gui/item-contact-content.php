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
 * Write to the seller about one listing. The field names are core's contract --
 * CWebItem reads them from contact_post -- so a theme that shipped this page was
 * laying out a form it did not own.
 *
 * CSRF is injected on shutdown into any form not marked nocsrf.
 */
?>
<div class="oe-form-page">
    <?php osc_show_flash_message(); ?>

    <p class="oe-muted"><?php printf(
        osc_esc_html(_m('About “%s”')),
        osc_esc_html(osc_item_title())
    ); ?></p>

    <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post"
          <?php if (osc_item_attachment()) { echo 'enctype="multipart/form-data"'; } ?>>
        <input type="hidden" name="action" value="contact_post" />
        <input type="hidden" name="page" value="item" />
        <input type="hidden" name="id" value="<?php echo (int) osc_item_id(); ?>" />

        <div class="oe-field">
            <label class="oe-label" for="oe-your-name"><?php echo osc_esc_html(_m('Your name')); ?></label>
            <input class="oe-input" id="oe-your-name" type="text" name="yourName" autocomplete="name" required
                   value="<?php echo osc_esc_html(osc_logged_user_name()); ?>" />
        </div>
        <div class="oe-field">
            <label class="oe-label" for="oe-your-email"><?php echo osc_esc_html(_m('Your email address')); ?></label>
            <input class="oe-input" id="oe-your-email" type="email" name="yourEmail" autocomplete="email" required
                   value="<?php echo osc_esc_html(osc_logged_user_email()); ?>"
                   aria-describedby="oe-your-email-hint" />
            <span class="oe-hint" id="oe-your-email-hint"><?php
                echo osc_esc_html(_m('The seller replies to this address.')); ?></span>
        </div>
        <div class="oe-field">
            <label class="oe-label" for="oe-your-phone"><?php echo osc_esc_html(_m('Phone number')); ?></label>
            <input class="oe-input" id="oe-your-phone" type="tel" name="phoneNumber" autocomplete="tel" />
        </div>
        <div class="oe-field">
            <label class="oe-label" for="oe-message"><?php echo osc_esc_html(_m('Message')); ?></label>
            <textarea class="oe-input" id="oe-message" name="message" rows="6" required minlength="10"></textarea>
        </div>

        <?php if (osc_item_attachment()) { ?>
            <div class="oe-field">
                <label class="oe-label" for="oe-attachment"><?php echo osc_esc_html(_m('Attachment')); ?></label>
                <input class="oe-input" id="oe-attachment" type="file" name="attachment" />
            </div>
        <?php } ?>

        <?php osc_run_hook('item_contact_form'); ?>

        <div class="oe-actions">
            <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Send message')); ?></button>
        </div>
    </form>
</div>
