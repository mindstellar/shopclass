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
 * The message form shared by the contact page, the seller contact page and the
 * public profile. The field names are core's contract; each controller reads them
 * from its own contact_post.
 *
 * Reads $contactForm:
 *  - hidden   (array)  name => value, where the form goes
 *  - prefix   (string) id prefix, e.g. oe-contact gives oe-contact-name
 *  - hint     (string) help text under the email field, optional
 *  - phone, subject, attachment (bool) the optional fields
 *  - captcha  (string) captcha context
 *  - top      (callable) runs before Subject and Message, optional
 *  - hooks    (callable) runs before the button, optional
 *  - name     (string) the form's name attribute, optional
 *  - optional (bool)   name and subject may be left empty (the contact page)
 *
 * CSRF is injected on shutdown into any form not marked nocsrf.
 */

$cf       = $contactForm;
$cfPrefix = osc_esc_html((string) $cf['prefix']);
?>
<form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post"<?php
    echo !empty($cf['name']) ? ' name="' . osc_esc_html((string) $cf['name']) . '"' : '';
    echo !empty($cf['attachment']) ? ' enctype="multipart/form-data"' : ''; ?>>
    <?php $cfError = osc_gui_kept('contact_error');
    if ($cfError !== '') { ?>
        <p class="oe-form-error" role="alert"><?php echo osc_esc_html($cfError); ?></p>
    <?php }
    foreach ((array) $cf['hidden'] as $cfName => $cfValue) { ?>
        <input type="hidden" name="<?php echo osc_esc_html((string) $cfName); ?>" value="<?php
            echo osc_esc_html((string) $cfValue); ?>" />
    <?php } ?>

    <div class="oe-field">
        <label class="oe-label" for="<?php echo $cfPrefix; ?>-name"><?php
            echo osc_esc_html(empty($cf['optional']) ? _m('Your name') : _m('Your name (optional)')); ?></label>
        <input class="oe-input" id="<?php echo $cfPrefix; ?>-name" type="text" name="yourName" autocomplete="name"<?php
            echo empty($cf['optional']) ? ' required' : ''; ?>
               value="<?php echo osc_esc_html(osc_gui_kept('yourName', osc_logged_user_name())); ?>" />
    </div>
    <div class="oe-field">
        <label class="oe-label" for="<?php echo $cfPrefix; ?>-email"><?php echo osc_esc_html(_m('Your email address')); ?></label>
        <input class="oe-input" id="<?php echo $cfPrefix; ?>-email" type="email" name="yourEmail" autocomplete="email" required
               value="<?php echo osc_esc_html(osc_gui_kept('yourEmail', osc_logged_user_email())); ?>"<?php
               echo !empty($cf['hint']) ? ' aria-describedby="' . $cfPrefix . '-email-hint"' : ''; ?> />
        <?php if (!empty($cf['hint'])) { ?>
            <span class="oe-hint" id="<?php echo $cfPrefix; ?>-email-hint"><?php echo osc_esc_html((string) $cf['hint']); ?></span>
        <?php } ?>
    </div>
    <?php if (!empty($cf['phone'])) { ?>
        <div class="oe-field">
            <label class="oe-label" for="<?php echo $cfPrefix; ?>-phone"><?php echo osc_esc_html(_m('Phone number')); ?></label>
            <input class="oe-input" id="<?php echo $cfPrefix; ?>-phone" type="tel" name="phoneNumber" autocomplete="tel"
                   value="<?php echo osc_esc_html(osc_gui_kept('phoneNumber')); ?>" />
        </div>
    <?php }
    if (isset($cf['top'])) {
        ($cf['top'])();
    }
    if (!empty($cf['subject'])) { ?>
        <div class="oe-field">
            <label class="oe-label" for="<?php echo $cfPrefix; ?>-subject"><?php
                echo osc_esc_html(empty($cf['optional']) ? _m('Subject') : _m('Subject (optional)')); ?></label>
            <input class="oe-input" id="<?php echo $cfPrefix; ?>-subject" type="text" name="subject"<?php
                echo empty($cf['optional']) ? ' required' : ''; ?>
                   value="<?php echo osc_esc_html(osc_gui_kept('subject')); ?>" />
        </div>
    <?php } ?>
    <div class="oe-field">
        <label class="oe-label" for="<?php echo $cfPrefix; ?>-message"><?php echo osc_esc_html(_m('Message')); ?></label>
        <textarea class="oe-input" id="<?php echo $cfPrefix; ?>-message" name="message" rows="6" required
                  minlength="10"><?php echo osc_esc_html(osc_gui_kept('message_body')); ?></textarea>
    </div>
    <?php if (!empty($cf['attachment'])) { ?>
        <div class="oe-field">
            <label class="oe-label" for="<?php echo $cfPrefix; ?>-attachment"><?php echo osc_esc_html(_m('Attachment')); ?></label>
            <input class="oe-input" id="<?php echo $cfPrefix; ?>-attachment" type="file" name="attachment" />
        </div>
    <?php } ?>

    <?php if (osc_captcha_enabled()) { ?>
        <div class="oe-field"><?php osc_show_captcha((string) $cf['captcha']); ?></div>
    <?php }
    if (isset($cf['hooks'])) {
        ($cf['hooks'])();
    } ?>

    <div class="oe-actions">
        <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Send message')); ?></button>
    </div>
</form>
