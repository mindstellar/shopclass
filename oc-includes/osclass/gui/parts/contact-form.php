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
 *  - messaging (bool)  on-site message: only the message field, no name, email, phone,
 *                      subject or attachment
 *
 * CSRF is injected on shutdown into any form not marked nocsrf.
 */

require_once dirname(__DIR__) . '/page-fn.php';

/** @var array<string, mixed> $contactForm */
$cf       = $contactForm;
$cfId     = (string) $cf['prefix'];
$cfPrefix = osc_esc_html($cfId);
$cfMax    = \mindstellar\security\MessageGuard::maxLength();
$cfMsg    = !empty($cf['messaging']);
if ($cfMsg) { ?>
<p class="oe-muted"><?php printf(
    osc_esc_html(_m('You are writing as %s. The seller answers in your messages.')),
    osc_esc_html(osc_logged_user_name())
); ?></p>
<?php } ?>
<form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post"<?php
    echo !empty($cf['name']) ? ' name="' . osc_esc_html((string) $cf['name']) . '"' : '';
    echo !$cfMsg && !empty($cf['attachment']) ? ' enctype="multipart/form-data"' : ''; ?>>
    <?php $cfError = osc_gui_kept('contact_error');
    $cfLines = array_values(array_filter(array_map('trim', explode("\n", $cfError)), 'strlen'));
    if (count($cfLines) > 1) { ?>
        <ul class="oe-form-error oe-list" role="alert">
            <?php foreach ($cfLines as $cfLine) { ?><li><?php echo osc_esc_html($cfLine); ?></li><?php } ?>
        </ul>
    <?php } elseif ($cfError !== '') { ?>
        <p class="oe-form-error" role="alert"><?php echo osc_esc_html($cfError); ?></p>
    <?php }
    foreach ((array) $cf['hidden'] as $cfName => $cfValue) { ?>
        <input type="hidden" name="<?php echo osc_esc_html((string) $cfName); ?>" value="<?php
            echo osc_esc_html((string) $cfValue); ?>" />
    <?php } ?>

    <?php if (!$cfMsg) { ?>
    <?php osc_gui_field($cfId . '-name', empty($cf['optional']) ? _m('Your name') : _m('Your name (optional)'),
        '<input class="oe-input" id="' . $cfPrefix . '-name" type="text" name="yourName" autocomplete="name"'
        . (empty($cf['optional']) ? ' required' : '')
        . ' value="' . osc_esc_html(osc_gui_kept('yourName', osc_logged_user_name())) . '" />'); ?>
    <?php osc_gui_field($cfId . '-email', _m('Your email address'),
        '<input class="oe-input" id="' . $cfPrefix . '-email" type="email" name="yourEmail" autocomplete="email" required'
        . ' value="' . osc_esc_html(osc_gui_kept('yourEmail', osc_logged_user_email())) . '"'
        . (!empty($cf['hint']) ? ' aria-describedby="' . $cfPrefix . '-email-hint"' : '') . ' />',
        (string) ($cf['hint'] ?? '')); ?>
    <?php if (!empty($cf['phone'])) {
        osc_gui_field($cfId . '-phone', _m('Phone number'),
            '<input class="oe-input" id="' . $cfPrefix . '-phone" type="tel" name="phoneNumber" autocomplete="tel"'
            . ' value="' . osc_esc_html(osc_gui_kept('phoneNumber')) . '" />');
    }
    if (isset($cf['top'])) {
        ($cf['top'])();
    }
    if (!empty($cf['subject'])) {
        osc_gui_field($cfId . '-subject', empty($cf['optional']) ? _m('Subject') : _m('Subject (optional)'),
            '<input class="oe-input" id="' . $cfPrefix . '-subject" type="text" name="subject"'
            . (empty($cf['optional']) ? ' required' : '')
            . ' value="' . osc_esc_html(osc_gui_kept('subject')) . '" />');
    } ?>
    <?php } ?>
    <?php osc_gui_field($cfId . '-message', _m('Message'),
        '<textarea class="oe-input" id="' . $cfPrefix . '-message" name="message" rows="6" required minlength="10"'
        . ($cfMax > 0 ? ' maxlength="' . $cfMax . '"' : '') . '>' . osc_esc_html(osc_gui_kept('message_body')) . '</textarea>'); ?>
    <?php if (!$cfMsg && !empty($cf['attachment'])) {
        osc_gui_field($cfId . '-attachment', _m('Attachment'),
            '<input class="oe-input" id="' . $cfPrefix . '-attachment" type="file" name="attachment"'
            . ' accept="' . osc_esc_html('.' . implode(',.', \mindstellar\storage\UploadMimes::attachmentExtensions())) . '" />');
    } ?>

    <?php if (osc_captcha_enabled()) { ?>
        <div class="oe-field"><?php osc_show_captcha((string) $cf['captcha']); ?></div>
    <?php }
    if (isset($cf['hooks'])) {
        ($cf['hooks'])();
    } ?>

    <?php osc_gui_actions(array(array('label' => _m('Send message')))); ?>
</form>
