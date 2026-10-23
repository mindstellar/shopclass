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

    <?php
    $contactHooks = static function () {
        osc_run_hook('item_contact_form');
    };
    if (osc_item_uses_messaging()) {
        $cfMax = \mindstellar\security\MessageGuard::maxLength();
        ?>
        <p class="oe-muted"><?php printf(
            osc_esc_html(_m('You are writing as %s. The seller answers in your messages.')),
            osc_esc_html(osc_logged_user_name())
        ); ?></p>
        <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
            <?php $cfError = osc_gui_kept('contact_error');
            if ($cfError !== '') { ?>
                <p class="oe-form-error" role="alert"><?php echo osc_esc_html($cfError); ?></p>
            <?php } ?>
            <input type="hidden" name="page" value="item" />
            <input type="hidden" name="action" value="contact_post" />
            <input type="hidden" name="id" value="<?php echo (int) osc_item_id(); ?>" />
            <div class="oe-field">
                <label class="oe-label" for="oe-your-message"><?php echo osc_esc_html(_m('Message')); ?></label>
                <textarea class="oe-input" id="oe-your-message" name="message" rows="6" required
                          minlength="10"<?php echo $cfMax > 0 ? ' maxlength="' . $cfMax . '"' : ''; ?>><?php echo osc_esc_html(osc_gui_kept('message_body')); ?></textarea>
            </div>
            <?php if (osc_captcha_enabled()) { ?>
                <div class="oe-field"><?php osc_show_captcha('contact_seller'); ?></div>
            <?php }
            $contactHooks(); ?>
            <div class="oe-actions">
                <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Send message')); ?></button>
            </div>
        </form>
        <?php
    } else {
        $contactForm = array(
            'hidden'     => array('page' => 'item', 'action' => 'contact_post', 'id' => (int) osc_item_id()),
            'prefix'     => 'oe-your',
            'hint'       => _m('The seller replies to this address.'),
            'phone'      => true,
            'attachment' => (bool) osc_item_attachment(),
            'captcha'    => 'contact_seller',
            'hooks'      => $contactHooks,
        );
        require __DIR__ . '/parts/contact-form.php';
    }
    ?>
</div>
