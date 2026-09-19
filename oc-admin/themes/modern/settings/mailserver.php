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

/**
 * The chrome around the declared mail-settings form. The form itself -- its route, its
 * fields, their values and the submit row -- is core's, drawn from the declaration the
 * controller saves through.
 */

$form = __get('mailserver_form');

//customize Head
/**
 * Emit the mail-server script: the provider select fills and locks host/port, and the test button posts to the AJAX mailer.
 *
 * @return void
 */
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

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('Mail Settings'),
    'help'    => __("Modify the settings of the mail server from which your site's emails are sent. <strong>Be careful</strong>"
                    . ": these settings can vary depending on your hosting or server. If you run into any issues"
                    . ", check your hosting's help section."),
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="mail-setting">
    <!-- settings form -->
    <div id="mail-settings">
        <?php osc_admin_page_head(__('Mail Settings')); ?>
        <ul id="error_list"></ul>
        <?php
        $form['actions'] = array(
            array('label' => __('Save changes'), 'type' => 'submit', 'variant' => 'primary'),
            array(
                'label'   => __('Send a test email'),
                'type'    => 'button',
                'variant' => 'secondary',
                'attrs'   => array('id' => 'testMail'),
            ),
        );
        osc_admin_settings_form($form['id'], $form);
        ?>
        <div id="testMail_message" class="flashmessage" style="display:none"><p></p></div>
    </div>
    <!-- /settings form -->
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
