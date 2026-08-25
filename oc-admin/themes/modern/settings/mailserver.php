<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

//customize Head
function customHead()
{
    $mailType     = osc_mailserver_normalize_type(osc_mailserver_type());
    $mailPresets  = osc_mailserver_presets();
    $mailDefaults = array();
    foreach (osc_mailserver_allowed_types() as $mailT) {
        $mailDefaults[$mailT] = osc_mailserver_type_defaults($mailT);
    }
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            function fld(name) { return document.querySelector('[name="' + name + '"]'); }

            var mailDefaults = <?php echo json_encode($mailDefaults, $jsonFlags); ?>;
            var mailPresets = <?php echo json_encode($mailPresets, $jsonFlags); ?>;
            var mailType = <?php echo json_encode($mailType, $jsonFlags); ?>;

            function readForm() {
                return {
                    host: fld('mailserver_host') ? fld('mailserver_host').value : '',
                    port: fld('mailserver_port') ? fld('mailserver_port').value : '',
                    username: fld('mailserver_username') ? fld('mailserver_username').value : '',
                    password: fld('mailserver_password') ? fld('mailserver_password').value : '',
                    ssl: fld('mailserver_ssl') ? fld('mailserver_ssl').value : '',
                    auth: (fld('mailserver_auth') && fld('mailserver_auth').checked) ? '1' : '',
                    pop: (fld('mailserver_pop') && fld('mailserver_pop').checked) ? '1' : '',
                    mail_from: fld('mailserver_mail_from') ? fld('mailserver_mail_from').value : '',
                    name_from: fld('mailserver_name_from') ? fld('mailserver_name_from').value : ''
                };
            }

            function lock(type) {
                var locked = (type === 'gmail');
                if (fld('mailserver_host')) { fld('mailserver_host').readOnly = locked; }
                if (fld('mailserver_port')) { fld('mailserver_port').readOnly = locked; }
            }

            function pick(slot, defaults, key) {
                if (slot && slot[key] !== undefined && slot[key] !== '') {
                    return slot[key];
                }
                if (defaults && defaults[key] !== undefined) {
                    return defaults[key];
                }
                return '';
            }

            function apply(type) {
                var defaults = mailDefaults[type] || {};
                var slot = mailPresets[type] || {};
                var host = pick(slot, defaults, 'host');
                var port = pick(slot, defaults, 'port');
                var ssl = pick(slot, defaults, 'ssl');
                if (type === 'gmail') {
                    host = defaults.host || 'smtp.gmail.com';
                    port = defaults.port || '465';
                    ssl = defaults.ssl || 'ssl';
                }
                if (fld('mailserver_host')) { fld('mailserver_host').value = host; }
                if (fld('mailserver_port')) { fld('mailserver_port').value = port; }
                if (fld('mailserver_ssl')) { fld('mailserver_ssl').value = ssl; }
                if (fld('mailserver_username')) {
                    fld('mailserver_username').value = pick(slot, defaults, 'username');
                }
                if (fld('mailserver_password')) {
                    fld('mailserver_password').value = (slot.password !== undefined) ? slot.password : '';
                }
                if (fld('mailserver_mail_from')) {
                    fld('mailserver_mail_from').value = pick(slot, defaults, 'mail_from');
                }
                if (fld('mailserver_name_from')) {
                    fld('mailserver_name_from').value = pick(slot, defaults, 'name_from');
                }
                var authOn = pick(slot, defaults, 'auth');
                if (authOn === '') { authOn = '1'; }
                var popOn = (slot.pop !== undefined && slot.pop !== '') ? slot.pop : (defaults.pop || '');
                if (fld('mailserver_auth')) {
                    fld('mailserver_auth').checked = (authOn === '1' || authOn === true);
                }
                if (fld('mailserver_pop')) {
                    fld('mailserver_pop').checked = (popOn === '1' || popOn === true);
                }
                lock(type);
            }

            function stash() {
                mailPresets[mailType] = readForm();
                var hidden = document.getElementById('mailserver_presets_json');
                if (hidden) { hidden.value = JSON.stringify(mailPresets); }
            }

            var typeSel = document.querySelector('select[name="mailserver_type"]');
            if (typeSel) {
                lock(mailType);
                stash();
                typeSel.addEventListener('change', function () {
                    stash();
                    mailType = typeSel.value;
                    apply(mailType);
                    stash();
                });
            }

            var form = document.querySelector('form[name="settings_form"]');
            if (form) {
                form.addEventListener('submit', function () { stash(); });
            }

            var testBtn = document.getElementById('testMail');
            if (testBtn) {
                testBtn.addEventListener('click', function () {
                    var msg = document.getElementById('testMail_message');
                    var pEl = msg ? msg.querySelector('p') : null;
                    function show(html, ok) {
                        if (!msg || !pEl) { return; }
                        pEl.textContent = html;
                        msg.style.display = 'block';
                        msg.classList.remove('flashmessage-ok', 'flashmessage-error');
                        msg.classList.add(ok ? 'flashmessage-ok' : 'flashmessage-error');
                    }
                    var controller = (typeof AbortController === 'function') ? new AbortController() : null;
                    var timer = window.setTimeout(function () {
                        if (controller) { controller.abort(); }
                    }, 25000);
                    var opts = {
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    };
                    if (controller) { opts.signal = controller.signal; }
                    fetch("<?php echo osc_admin_base_url(true)?>?page=ajax&action=test_mail", opts)
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            show(data.html, data.status == 1);
                        })
                        .catch(function () {
                            show(<?php echo json_encode(
                                __('Mail server did not respond in time. Check host, port and encryption, and that POP before SMTP is off.')
                            ); ?>, false);
                        })
                        .then(function () { window.clearTimeout(timer); });
                });
            }
        });
    </script>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('Mail Settings'),
    'help'    => __("Modify the settings of the mail server from which your site's emails are sent. <strong>Be careful</strong>"
                    . ": these settings can vary depending on your hosting or server. If you run into any issues"
                    . ", check your hosting's help section."),
));

osc_current_admin_theme_path('parts/header.php');
$mailTypeNow = osc_mailserver_normalize_type(osc_mailserver_type());
?>
<div id="mail-setting">
    <!-- settings form -->
    <div id="mail-settings">
        <?php osc_admin_page_head(__('Mail Settings')); ?>
        <ul id="error_list"></ul>
        <form name="settings_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="mailserver_post"/>
            <input type="hidden" name="mailserver_presets_json" id="mailserver_presets_json"
                   value="<?php echo osc_esc_html(json_encode(osc_mailserver_presets())); ?>"/>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <div class="form-label"><?php _e('Server type'); ?></div>
                        <div class="form-controls">
                            <select class="form-select form-select-sm " name="mailserver_type">
                                <option value="custom" <?php echo ($mailTypeNow === 'custom')
                                    ? 'selected="true"' : ''; ?>><?php _e('Custom Server'); ?></option>
                                <option value="gmail" <?php echo ($mailTypeNow === 'gmail')
                                    ? 'selected="true"' : ''; ?>><?php _e('GMail Server'); ?></option>
                                <option value="brevo" <?php echo ($mailTypeNow === 'brevo')
                                    ? 'selected="true"' : ''; ?>><?php _e('Brevo'); ?></option>
                                <option value="smtp2go" <?php echo ($mailTypeNow === 'smtp2go')
                                    ? 'selected="true"' : ''; ?>><?php _e('SMTP2GO'); ?></option>
                                <option value="ses" <?php echo ($mailTypeNow === 'ses')
                                    ? 'selected="true"' : ''; ?>><?php _e('Amazon SES'); ?></option>
                            </select>
                            <div class="help-box"><?php _e('Each type is stored separately. Saving Brevo does not erase Gmail or SMTP2GO. Switch the menu to recall a saved provider, then Save on the one that should send.'); ?></div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Hostname'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-large" name="mailserver_host"
                                   value="<?php echo osc_esc_html(osc_mailserver_host()); ?>"/>
                            <div class="help-box"><?php _e('Gmail, Brevo, SMTP2GO and Amazon SES fill a typical host and port. For SES, change the region in the hostname (email-smtp.{region}.amazonaws.com) to match your account. If port 587 is blocked, Brevo and SMTP2GO also accept 2525 with tls.'); ?></div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Mail from'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-large" name="mailserver_mail_from"
                                   value="<?php echo osc_esc_html(osc_mailserver_mail_from()); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Name from'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-large" name="mailserver_name_from"
                                   value="<?php echo osc_esc_html(osc_mailserver_name_from()); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Server port'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-large" name="mailserver_port"
                                   value="<?php echo osc_esc_html(osc_mailserver_port()); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Username'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-large" name="mailserver_username"
                                   value="<?php echo osc_esc_html(osc_mailserver_username()); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Password'); ?></div>
                        <div class="form-controls">
                            <input type="password" class="input-large" name="mailserver_password"
                                   value="<?php echo osc_esc_html(osc_mailserver_password()); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Encryption'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-medium" name="mailserver_ssl"
                                   value="<?php echo osc_esc_html(osc_mailserver_ssl()); ?>"/>
                            <?php _e('Options: blank, ssl or tls. Gmail uses ssl on 465. Brevo, SMTP2GO and SES typically use tls.'); ?>
                            <?php if (PHP_SAPI === 'cgi-fcgi' || PHP_SAPI === 'cgi') { ?>
                                <div class="callout-warning">
                                    <p><?php _e('Cannot be sure that Apache Module <b>mod_ssl</b> is loaded.'); ?></p>
                                </div>
                            <?php } elseif (!@apache_mod_loaded('mod_ssl')) { ?>
                                <div class="callout-warning">
                                    <p><?php _e('Apache Module <b>mod_ssl</b> is not loaded'); ?></p>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('SMTP'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox"><input type="checkbox" <?php echo(osc_mailserver_auth()
                                    ? 'checked="checked"' : ''); ?> name="mailserver_auth" value="1"/>
                                <?php _e('SMTP authentication enabled'); ?></div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('POP'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox"><input type="checkbox" <?php echo(osc_mailserver_pop()
                                    ? 'checked="checked"' : ''); ?> name="mailserver_pop" value="1"/>
                                <?php _e('Use POP before SMTP'); ?>
                                <span class="help-box"><?php _e('Leave unchecked for Amazon SES, SMTP2GO, Brevo, and most SMTP hosts.'); ?></span>
                            </div>
                        </div>
                    </div>
                    <div id="testMail_message" class="flashmessage" style="display:none"><p></p></div>
                    <?php osc_admin_form_actions(array(
                        array('label' => __('Save changes'), 'type' => 'submit', 'variant' => 'primary'),
                        array(
                            'label'   => __('Send a test email'),
                            'type'    => 'button',
                            'variant' => 'secondary',
                            'attrs'   => array('id' => 'testMail'),
                        ),
                    )); ?>
                </div>
            </fieldset>
        </form>
    </div>
    <!-- /settings form -->
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
