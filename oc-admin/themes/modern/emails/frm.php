<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

osc_enqueue_script('tiny_mce');
osc_enqueue_script('admin-editor');

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('Edit email template'),
));

$email      = __get('email');
$aEmailVars   = EmailVariables::newInstance()->getVariables($email);
$aCommonVars  = EmailVariables::newInstance()->getCommonVariables();

// What was typed wins over what is stored, so a refused save comes back with the work in it.
$emailErrors    = __get('editorErrors');
$emailErrors    = is_array($emailErrors) ? $emailErrors : array();
$emailSubmitted = Session::newInstance()->_getForm('aFieldsDescription');
$emailName      = (string)($email['s_internal_name'] ?? '');
$emailLocales  = array();
$emailSubjects = array();
$emailBodies   = array();
foreach (osc_get_admin_locales() as $emailLocale) {
    $code                 = $emailLocale['pk_c_code'];
    $emailLocales[$code]  = $emailLocale['s_name'];
    $emailSubjects[$code] = $emailSubmitted[$code]['s_title'] ?? $email['locale'][$code]['s_title'] ?? '';
    $emailBodies[$code]   = $emailSubmitted[$code]['s_text'] ?? $email['locale'][$code]['s_text'] ?? '';
}

$emailBackUrl = osc_admin_base_url(true) . '?page=emails';

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="adminEmailForm">
    <?php
    osc_admin_page_head(__('Edit email template'), array(
        array('label' => __('Back to email templates'), 'url' => $emailBackUrl, 'variant' => 'dim'),
    ));

    osc_admin_editor_open(array(
        'id'           => 'email-form',
        'class'        => 'email-editor',
        'page'         => 'emails',
        'action'       => 'edit_post',
        'main_id'      => 'left-side',
        'errors'       => $emailErrors,
        'error_labels' => array('s_title' => __('Subject')),
        'error_ids'    => array('s_title' => osc_admin_field_id(array('name' => 's_title'))),
    ));

    PageForm::primary_input_hidden($email);

    osc_admin_field(array(
        'type'           => 'text',
        'name'           => 's_title',
        'translate_name' => '%s#s_title',
        'label'          => __('Subject'),
        'layout'         => 'stacked',
        'translate'      => true,
        'locales'        => $emailLocales,
        'value'          => $emailSubjects,
        'error'          => $emailErrors['s_title'] ?? '',
        'class'          => 'osc-editor-title',
    ));

    osc_admin_field(array(
        'type'           => 'richtext',
        'name'           => 's_text',
        'translate_name' => '%s#s_text',
        'label'          => __('Message'),
        'layout'         => 'stacked',
        'translate'      => true,
        // The subject's strip above switches this field too.
        'tabs'           => false,
        'locales'        => $emailLocales,
        'value'          => $emailBodies,
        'preset'         => 'basic',
        'height'         => 440,
        // A template is markup an admin wrote, so it keeps the source view.
        'config'         => array(
            'plugins' => 'autolink lists link code',
            'toolbar' => 'undo redo | bold italic underline | bullist numlist | link | removeformat | code',
        ),
    ));

    osc_admin_editor_rail(array('id' => 'right-side'));

    // The template's own placeholders, then the ones osc_mailBeauty() fills in every email.
    $varGroups = array_filter(array(
        __('In this email')  => $aEmailVars,
        __('In every email') => $aCommonVars,
    ));
    osc_admin_panel_open(__('Placeholders'), array('class' => 'email-vars-panel')); ?>
    <p class="email-hint">
        <?php _e('Swapped for real values when the email is sent. Click one to add it at the cursor.'); ?>
    </p>
    <?php foreach ($varGroups as $groupName => $groupVars) { ?>
        <p class="email-vars-group"><?php echo osc_esc_html($groupName); ?></p>
        <ul class="email-vars">
            <?php foreach ($groupVars as $key => $value) { ?>
                <li>
                    <button type="button" class="email-var" data-var="<?php echo osc_esc_html($key); ?>"
                            title="<?php echo osc_esc_html($value); ?>">
                        <code><?php echo osc_esc_html($key); ?></code>
                        <span><?php echo osc_esc_html($value); ?></span>
                    </button>
                </li>
            <?php } ?>
        </ul>
    <?php }
    osc_admin_panel_close();

    osc_admin_panel_open(__('Send a test'), array('class' => 'email-test-panel')); ?>
    <p class="email-hint">
        <?php _e('Sends what is on screen now. Placeholders are not filled in.'); ?>
    </p>
    <div class="osc-field">
        <label class="form-label" for="email-test-to"><?php _e('Send to'); ?></label>
        <input type="email" id="email-test-to" class="form-control" autocomplete="email"
               value="<?php echo osc_esc_html(osc_logged_admin_email()); ?>"/>
    </div>
    <button type="button" class="btn btn-secondary btn-sm" id="email-test-send"><?php _e('Send test email'); ?></button>
    <p class="email-test-status" id="email-test-status" role="status" aria-live="polite"></p>
    <?php
    osc_admin_panel_close();

    osc_admin_panel_open(__('Template'));
    osc_admin_definition(array(
        array('label' => __('Internal name'), 'value' => '<code>' . osc_esc_html($emailName) . '</code>', 'html' => true),
    ));
    ?>
    <p class="email-hint"><?php _e('Core finds the template by this name, so it cannot change.'); ?></p>
    <?php
    osc_admin_panel_close();

    osc_admin_editor_close(array(
        array('label' => __('Save changes'), 'type' => 'submit', 'variant' => 'primary'),
        array('label' => __('Back to email templates'), 'url' => $emailBackUrl, 'variant' => 'dim'),
    )); ?>
</div>
<script>
    (function () {
        'use strict';
        var form = document.getElementById('email-form');
        if (!form) { return; }

        // The subject and message of the language on screen. Only the active tab's
        // panel is visible, so the first visible control is the one being edited.
        function visible(selector) {
            return Array.prototype.filter.call(form.querySelectorAll(selector), function (el) {
                return el.closest('[hidden]') === null && el.closest('.tab-pane:not(.active)') === null;
            })[0] || null;
        }
        function subjectInput() { return visible('input[name$="#s_title"]'); }
        function messageEditor() {
            var area = visible('textarea[name$="#s_text"]');
            return area && window.tinymce ? tinymce.get(area.id) : null;
        }

        // A placeholder goes to whichever of the two was edited last.
        var lastField = 'message';
        form.addEventListener('focusin', function (e) {
            if (e.target.matches && e.target.matches('input[name$="#s_title"]')) { lastField = 'subject'; }
        });
        if (window.tinymce) {
            tinymce.on('AddEditor', function (e) {
                e.editor.on('focus', function () { lastField = 'message'; });
            });
        }

        form.querySelectorAll('.email-var').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var token = btn.getAttribute('data-var');
                var input = subjectInput();
                if (lastField === 'subject' && input) {
                    var start = input.selectionStart ?? input.value.length;
                    var end = input.selectionEnd ?? start;
                    input.setRangeText(token, start, end, 'end');
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.focus();
                    return;
                }
                var editor = messageEditor();
                if (editor) {
                    editor.focus();
                    editor.insertContent(token);
                }
            });
        });

        var sendBtn = document.getElementById('email-test-send');
        var status = document.getElementById('email-test-status');
        var to = document.getElementById('email-test-to');
        function say(text, ok) {
            status.textContent = text;
            status.classList.toggle('is-error', !ok);
        }
        sendBtn.addEventListener('click', function () {
            if (!to.checkValidity() || to.value === '') {
                say(<?php echo json_encode(__('Enter an email address to send the test to.')); ?>, false);
                to.focus();
                return;
            }
            var editor = messageEditor();
            var input = subjectInput();
            var body = new URLSearchParams();
            body.set('email', to.value);
            body.set('title', input ? input.value : '');
            body.set('body', editor ? editor.getContent({ format: 'html' }) : '');

            sendBtn.disabled = true;
            say(<?php echo json_encode(__('Sending…')); ?>, true);
            fetch(<?php echo json_encode(
                osc_admin_base_url(true) . '?page=ajax&action=test_mail_template&' . osc_csrf_token_url()
            ); ?>, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            }).then(function (r) { return r.json(); }).then(function (data) {
                say(data.html || data.msg || '', data.status === '1');
            }).catch(function () {
                say(<?php echo json_encode(__('An error occurred while sending email')); ?>, false);
            }).finally(function () {
                sendBtn.disabled = false;
            });
        });
    })();
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
