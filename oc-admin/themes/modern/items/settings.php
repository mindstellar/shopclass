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

osc_admin_page(array(
    'section' => __('Listings'),
    'title'   => __('Listing Settings'),
    'help'    => __('Modify the general settings for your listings. Decide if users have to register in order to publish something, the number of pictures allowed for each listing, etc.'),
));

//customize Head
/**
 * Emit the listing-settings script: the moderation toggle shows and resets the moderated-listing count.
 *
 * @return void
 */
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            var moderate = document.querySelector('input[name="moderate_items"]');
            function setRows(display) {
                document.querySelectorAll('.num-moderated-items').forEach(function (el) { el.style.display = display; });
            }
            if (moderate) {
                moderate.addEventListener('change', function () {
                    if (moderate.checked) {
                        setRows('');
                        var num = document.querySelector('input[name="num_moderate_items"]');
                        if (num) { num.value = 0; }
                    } else {
                        var lv = document.querySelector('input[name="logged_user_item_validation"]');
                        if (lv) { lv.checked = false; }
                        setRows('none');
                    }
                });
                if (!moderate.checked) {
                    setRows('none');
                }
            }
        });
    </script>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="general-setting">
    <!-- settings form -->
    <div id="item-settings">
        <?php osc_admin_page_head(__('Listing Settings')); ?>
        <?php osc_admin_form_open(array(
    'page'   => 'items',
    'action' => 'settings_post',
)); ?>
            <?php osc_admin_form_row_open(__('Settings')); ?>
                    <?php
                    osc_admin_checkbox(array(
                        'name'    => 'reg_user_post',
                        'label'   => __('Only logged in users can post listings'),
                        'checked' => osc_reg_user_post(),
                    ));
                    osc_admin_number(array(
                        'row'    => false,
                        'name'   => 'items_wait_time',
                        'value'  => osc_items_wait_time(),
                        'min'    => 0,
                        'prefix' => __('An user has to wait'),
                        'suffix' => __('seconds between each listing added'),
                        'help'   => __('If the value is set to zero, there is no wait period'),
                    ));
                    osc_admin_checkbox(array(
                        'name'    => 'moderate_admin_post',
                        'label'   => __('Hold new listings for admin moderation'),
                        'checked' => osc_moderate_admin_post(),
                    ));
                    osc_admin_checkbox(array(
                        'name'    => 'moderate_admin_edit',
                        'label'   => __('Hold edited listings for admin moderation'),
                        'checked' => osc_moderate_admin_edit(),
                    ));
                    osc_admin_checkbox(array(
                        'name'    => 'moderate_items',
                        'label'   => __('Users have to validate their listings'),
                        'checked' => osc_moderate_items() != -1,
                    )); ?>
                    <div class="num-moderated-items">
                        <?php
                        osc_admin_number(array(
                            'row'    => false,
                            'name'   => 'num_moderate_items',
                            'value'  => osc_moderate_items() == -1 ? '' : osc_moderate_items(),
                            'min'    => 0,
                            'prefix' => __('After'),
                            'suffix' => __("validated listings the user doesn't need to validate the listings any more"),
                            'help'   => __('If the value is zero, it means that each listing must be validated'),
                        ));
                        osc_admin_checkbox(array(
                            'name'    => 'logged_user_item_validation',
                            'label'   => __("Logged in users don't need to validate their listings"),
                            'checked' => osc_logged_user_item_validation(),
                        )); ?>
                    </div>
                    <?php osc_admin_checkbox(array(
                        'name'      => 'enabled_recaptcha_items',
                        'label'     => __('Show reCAPTCHA in add/edit listing form'),
                        'checked'   => osc_recaptcha_items_enabled() != '0',
                        'help_html' => __('<strong>Remember</strong> that you must configure reCAPTCHA first'),
                    )); ?>
            <?php osc_admin_form_row_close(); ?>
            <?php osc_admin_form_row_open(__('Contact publisher')); ?>
                    <?php
                    osc_admin_checkbox(array(
                        'name'    => 'reg_user_can_contact',
                        'label'   => __('Only allow registered users to contact publisher'),
                        'checked' => osc_reg_user_can_contact(),
                    ));
                    osc_admin_checkbox(array(
                        'name'    => 'item_attachment',
                        'label'   => __('Allow attached files in contact publisher form'),
                        'checked' => osc_item_attachment(),
                    )); ?>
            <?php osc_admin_form_row_close(); ?>
            <?php osc_admin_form_row_open(__('Share listing')); ?>
                    <?php
                    osc_admin_checkbox(array(
                        'name'    => 'enable_send_friend',
                        'label'   => __('Enable the "send to a friend" form'),
                        'checked' => osc_enable_send_friend(),
                        'help'    => __('This form emails a listing to a recipient the visitor types in. Off by default because it can be abused to relay mail; enable it only if you need it.'),
                    ));
                    osc_admin_checkbox(array(
                        'name'    => 'reg_user_can_send_friend',
                        'label'   => __('Only allow registered users to share listings'),
                        'checked' => osc_reg_user_can_send_friend(),
                    )); ?>
            <?php osc_admin_form_row_close(); ?>
            <?php osc_admin_form_row_open(__('Notifications')); ?>
                    <?php
                    osc_admin_checkbox(array(
                        'name'    => 'notify_new_item',
                        'label'   => __('Notify admin when a new listing is added'),
                        'checked' => osc_notify_new_item(),
                    ));
                    osc_admin_checkbox(array(
                        'name'    => 'notify_contact_item',
                        'label'   => __('Send admin a copy of the "contact publisher" email'),
                        'checked' => osc_notify_contact_item(),
                    ));
                    osc_admin_checkbox(array(
                        'name'    => 'notify_contact_friends',
                        'label'   => __('Send admin a copy to "share listing" email'),
                        'checked' => osc_notify_contact_friends(),
                    )); ?>
            <?php osc_admin_form_row_close(); ?>
            <?php
            osc_admin_number(array(
                'name'   => 'warn_expiration',
                'label'  => __('Warn about expiration'),
                'value'  => osc_warn_expiration(),
                'min'    => 0,
                'suffix' => __('days'),
                'help'   => __('This option will send an email X days before an ad expires to the author. 0 for no email.'),
            ));
            osc_admin_number(array(
                'name'   => 'max_chars_per_title',
                'label'  => __('Title length'),
                'value'  => osc_max_characters_per_title(),
                'min'    => 0,
                'suffix' => __('characters'),
            ));
            osc_admin_number(array(
                'name'   => 'max_chars_per_description',
                'label'  => __('Description length'),
                'value'  => osc_max_characters_per_description(),
                'min'    => 0,
                'suffix' => __('characters'),
            ));
            osc_admin_field(array(
                'type'      => 'checkbox',
                'row_label' => __('Rich Edit'),
                'name'      => 'tinymce',
                'label'     => __('Enable TinyMCE on frontend'),
                'checked'   => osc_tinymce_frontend(),
            )); ?>
            <?php osc_admin_form_row_open(__('Optional fields')); ?>
                    <?php
                    osc_admin_checkbox(array(
                        'name'    => 'enableField#f_price@items',
                        'label'   => __('Price'),
                        'checked' => osc_price_enabled_at_items(),
                    ));
                    osc_admin_checkbox(array(
                        'name'    => 'enableField#images@items',
                        'label'   => __('Attach images'),
                        'checked' => osc_images_enabled_at_items(),
                    ));
                    osc_admin_number(array(
                        'row'    => false,
                        'name'   => 'numImages@items',
                        'value'  => osc_max_images_per_item(),
                        'min'    => 0,
                        'prefix' => __('Attach'),
                        'suffix' => __('images per listing'),
                        'help'   => __('If the value is zero, it means an unlimited number of images is allowed'),
                    )); ?>
            <?php osc_admin_form_row_close(); ?>
            <?php osc_admin_select(array(
                'name'     => 'map_type',
                'label'    => __('Maps'),
                'selected' => (string)osc_item_map_type(),
                'options'  => array(
                    '0'          => __('None'),
                    'google'     => __('Google Maps'),
                    'openstreet' => __('OpenStreetMaps'),
                ),
                'help'     => __('Set the API key in Settings -> General.'),
            )); ?>
                    <?php osc_admin_form_close(array(
                        array('label' => __('Save changes'), 'type' => 'submit', 'attrs' => array('id' => 'save_changes')),
                    )); ?>
    </div>
    <!-- /settings form -->
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
