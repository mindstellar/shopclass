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

osc_enqueue_script('tiny_mce');

function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?></h1>
    <?php
}


osc_add_hook('admin_page_header', 'customPageHeader');
//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        tinyMCE.init({
            selector: "textarea",
            promotion: false,
            width: "100%",
            height: "440px",
            language: 'en',
            theme_advanced_toolbar_align: "left",
            theme_advanced_toolbar_location: "top",
            plugins: [
                "advlist autolink lists link charmap preview anchor",
                "searchreplace visualblocks code fullscreen",
                "insertdatetime table paste"
            ],
            entity_encoding: "raw",
            theme_advanced_buttons1_add: "forecolorpicker,fontsizeselect",
            theme_advanced_buttons3: "",
            theme_advanced_disable: "styleselect,anchor",
            relative_urls: false,
            remove_script_host: false,
            convert_urls: false
        });


        document.addEventListener('DOMContentLoaded', function () {
            // First visible element for a selector (the active-locale field).
            function firstVisible(sel) {
                return Array.prototype.slice.call(document.querySelectorAll(sel))
                    .filter(function (el) { return el.offsetParent !== null; })[0] || null;
            }

            var displayBtn = document.getElementById('btn-display-test-it');
            if (displayBtn) {
                displayBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    (new bootstrap.Modal(document.getElementById('dialog-test-it'))).toggle();
                });
            }

            var testBtn = document.getElementById('btn-test-it');
            if (testBtn) {
                testBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var nameEl = firstVisible('input[name*="#s_title"]');
                    if (!nameEl) { return; }
                    var locale = nameEl.getAttribute('name').replace('#s_title', '');
                    var idTinymce = locale + '#s_text';
                    var emailEl = firstVisible('input[name="test_email"]');
                    var titleEl = firstVisible('input[name*="s_title"]');

                    var body = new URLSearchParams();
                    body.set('page', 'ajax');
                    body.set('action', 'test_mail_template');
                    body.set('email', emailEl ? emailEl.value : '');
                    body.set('title', titleEl ? titleEl.value : '');
                    body.set('body', tinyMCE.get(idTinymce).getContent({ format: 'html' }));

                    fetch('<?php echo osc_admin_base_url(true); ?>', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: body
                    }).then(function (r) {
                        return r.json();
                    }).then(function (data) {
                        var mb = document.querySelector('#dialog-test-it .modal-body');
                        if (mb) { mb.insertAdjacentHTML('beforeend', data.html); }
                    });
                });
            }
        });

    </script>
    <?php
}


osc_add_hook('admin_header', 'customHead', 10);

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Edit email template &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

$email      = __get('email');
$aEmailVars = EmailVariables::newInstance()->getVariables($email);

$locales = OSCLocale::newInstance()->listAllEnabled();

osc_current_admin_theme_path('parts/header.php'); ?>

<div class="grid-row mb-0">
    <div class="row-wrapper">
        <h2 class="render-title"><?php _e('Edit email template'); ?></h2>
    </div>
</div>
<div id="pretty-form">
    <div class="col">
        <div class="row-wrapper">
            <div id="item-form" class="row">
                <div class="col" id="left-side">
                    <?php PageForm::printMultiLangTab(); ?>
                    <form action="<?php echo osc_admin_base_url(true); ?>" method="post">
                        <input type="hidden" name="page" value="emails"/>
                        <input type="hidden" name="action" value="edit_post"/>
                        <?php PageForm::primary_input_hidden($email); ?>
                        <div id="left-side">
                            <div>
                                <label><?php _e('Internal name'); ?></label>
                                <?php PageForm::internal_name_input_text($email); ?>
                                <div class="callout-warning">
                                    <p><?php _e('Used to identify the email template'); ?></p>
                                </div>
                            </div>
                            <?php PageForm::printMultiLangTitleDesc($email, false)
                            ?>
                        </div>
                        <div class="clear"></div>
                        <div class="form-actions form-inline">
                            <button type="submit" class="btn btn-submit"><?php echo osc_esc_html(__('Save changes')); ?></button>
                            <a id="btn-display-test-it" class="btn btn-secondary"><?php _e('Test it'); ?></a>
                        </div>
                    </form>
                </div>
                <div class="col-lg-4 col-xl-3" id="right-side">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title"><?php _e('Legend'); ?></h3>
                        </div>
                        <div class="card-body">
                            <?php foreach ($aEmailVars as $key => $value) { ?>
                                <label><b><?php echo $key; ?></b><br/><?php echo $value; ?></label>
                                <hr/>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <div class="clear"></div>
            </div>
        </div>
    </div>
</div>
<div id="dialog-test-it" class="modal fade static">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <?php echo __('Send email'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input placeholder="someone@example.com" type="text" name="test_email" class="form-control
                form-control-sm"/>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php _e('Close'); ?></button>
                <button id="btn-test-it" class="btn btn-sm btn-primary" type="submit">
                    <?php _e('Send email'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php osc_current_admin_theme_path('parts/footer.php'); ?>
