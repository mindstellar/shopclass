<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            function fld(name) { return document.querySelector('[name="' + name + '"]'); }

            var typeSel = document.querySelector('select[name="mailserver_type"]');
            if (typeSel) {
                typeSel.addEventListener('change', function () {
                    var host = fld('mailserver_host');
                    var port = fld('mailserver_port');
                    if (typeSel.value === 'gmail') {
                        if (host) { host.value = 'smtp.gmail.com'; host.readOnly = true; }
                        if (port) { port.value = '465'; port.readOnly = true; }
                        var u = fld('mailserver_username'); if (u) { u.value = ''; }
                        var pw = fld('mailserver_password'); if (pw) { pw.value = ''; }
                        var ssl = fld('mailserver_ssl'); if (ssl) { ssl.value = 'ssl'; }
                        var auth = fld('mailserver_auth'); if (auth) { auth.checked = true; }
                        var pop = fld('mailserver_pop'); if (pop) { pop.checked = false; }
                    } else {
                        if (host) { host.readOnly = false; }
                        if (port) { port.readOnly = false; }
                    }
                });
            }

            var testBtn = document.getElementById('testMail');
            if (testBtn) {
                testBtn.addEventListener('click', function () {
                    fetch("<?php echo osc_admin_base_url(true)?>?page=ajax&action=test_mail", {
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    }).then(function (r) {
                        return r.json();
                    }).then(function (data) {
                        var msg = document.getElementById('testMail_message');
                        if (!msg) { return; }
                        var pEl = msg.querySelector('p');
                        if (pEl) { pEl.innerHTML = data.html; }
                        msg.style.display = 'block';
                        msg.classList.add(data.status == 1 ? 'ok' : 'error');
                    });
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


function addHelp()
{
    echo '<p>'
         . __("Modify the settings of the mail server from which your site's emails are sent. <strong>Be careful</strong>"
              . ": these settings can vary depending on your hosting or server. If you run into any issues"
              . ", check your hosting's help section.")
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Mail Settings &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="mail-setting">
    <!-- settings form -->
    <div id="mail-settings">
        <h2 class="render-title"><?php _e('Mail Settings'); ?></h2>
        <ul id="error_list"></ul>
        <form name="settings_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="mailserver_post"/>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <div class="form-label"><?php _e('Server type'); ?></div>
                        <div class="form-controls">
                            <select class="form-select form-select-sm " name="mailserver_type">
                                <option value="custom" <?php echo (osc_mailserver_type() === 'custom')
                                    ? 'selected="true"' : ''; ?>><?php _e('Custom Server'); ?></option>
                                <option value="gmail" <?php echo (osc_mailserver_type() === 'gmail') ? 'selected="true"'
                                    : ''; ?>><?php _e('GMail Server'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Hostname'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-large" name="mailserver_host"
                                   value="<?php echo osc_esc_html(osc_mailserver_host()); ?>"/>
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
                            <?php _e('Options: blank, ssl or tls'); ?>
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
                                <?php _e('Use POP before SMTP'); ?></div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <input type="submit" id="save_changes" value="<?php echo osc_esc_html(__('Save changes')); ?>"
                               class="btn btn-submit"/>
                    </div>
                </div>
            </fieldset>
        </form>
    </div>
    <!-- /settings form -->
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
