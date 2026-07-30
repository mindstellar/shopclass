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

function addHelp()
{
    echo '<p>'
         . __('Modify the general settings for your listings. Decide if users have to register in order to publish something, the number of pictures allowed for each listing, etc.')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

//customize Head
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

/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}


osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Listings'); ?>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box" aria-label="<?php echo osc_esc_html(__('Help')); ?>"></a>
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
    return sprintf(__('Listing Settings &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="general-setting">
    <!-- settings form -->
    <div id="item-settings">
        <h2 class="render-title"><?php _e('Listing Settings'); ?></h2>
        <form action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="items"/>
            <input type="hidden" name="action" value="settings_post"/>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <div class="form-label"> <?php _e('Settings'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <label>
                                    <input type="checkbox" <?php echo(osc_reg_user_post() ? 'checked="checked"'
                                        : ''); ?> name="reg_user_post" value="1"/>
                                    <?php _e('Only logged in users can post listings'); ?>
                                </label>
                            </div>
                            <div>
                                <?php printf(__('An user has to wait %s seconds between each listing added'),
                                             '<input type="text" class="input-small" name="items_wait_time" value="'
                                             . osc_items_wait_time() . '" />'); ?>
                                <div class="help-box">
                                    <?php _e('If the value is set to zero, there is no wait period'); ?>
                                </div>
                            </div>
                            <div class="separate-top-medium">
                                <label>
                                    <input type="checkbox" name="moderate_admin_post" value="1" <?php if (osc_moderate_admin_post()) {
                                        echo 'checked';
                                                                                                } ?> />
                                    <?php _e('Hold new listings for admin moderation'); ?>
                                </label>
                            </div>
                            <div class="separate-top-medium">
                                <label>
                                    <input type="checkbox" name="moderate_admin_edit" value="1" <?php if (osc_moderate_admin_edit()) {
                                        echo 'checked';
                                                                                                } ?>/>
                                    <?php _e('Hold edited listings for admin moderation'); ?>
                                </label>
                            </div>
                            <div class="separate-top-medium">
                                <label>
                                    <input type="checkbox" <?php echo((osc_moderate_items() == -1) ? ''
                                        : 'checked="checked"'); ?> name="moderate_items" value="1"/>
                                    <?php _e('Users have to validate their listings'); ?>
                                </label>
                            </div>
                            <div class="num-moderated-items">
                                <div>
                                    <?php printf(__("After %s validated listings the user doesn't need to validate the listings any more"),
                                                 '<input type="text" class="input-small" name="num_moderate_items" value="'
                                                 . ((osc_moderate_items() == -1) ? '' : osc_moderate_items()) . '" />'); ?>
                                    <div class="help-box">
                                        <?php _e('If the value is zero, it means that each listing must be validated'); ?>
                                    </div>
                                </div>
                                <div class="separate-top-medium">
                                    <label>
                                        <input type="checkbox" <?php echo(osc_logged_user_item_validation()
                                            ? 'checked="checked"' : ''); ?> name="logged_user_item_validation"
                                               value="1"/>
                                        <?php _e("Logged in users don't need to validate their listings"); ?>
                                    </label>
                                </div>
                            </div>
                            <div class="separate-top-medium">
                                <label>
                                    <input type="checkbox" <?php echo((osc_recaptcha_items_enabled() == '0') ? ''
                                        : 'checked="checked"'); ?> name="enabled_recaptcha_items" value="1"/>
                                    <?php _e('Show reCAPTCHA in add/edit listing form'); ?>
                                </label>
                                <div class="help-box"><?php _e('<strong>Remember</strong> that you must configure reCAPTCHA first'); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"> <?php _e('Contact publisher'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <label>
                                    <input type="checkbox" <?php echo(osc_reg_user_can_contact() ? 'checked="checked"'
                                        : ''); ?> name="reg_user_can_contact" value="1"/>
                                    <?php _e('Only allow registered users to contact publisher'); ?>
                                </label>
                            </div>
                            <div class="separate-top-medium">
                                <label>
                                    <input type="checkbox" <?php echo(osc_item_attachment() ? 'checked="checked"'
                                        : ''); ?> name="item_attachment" value="1"/>
                                    <?php _e('Allow attached files in contact publisher form'); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"> <?php _e('Notifications'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <label>
                                    <input type="checkbox" <?php echo(osc_notify_new_item() ? 'checked="checked"'
                                        : ''); ?> name="notify_new_item" value="1"/>
                                    <?php _e('Notify admin when a new listing is added'); ?>
                                </label>
                            </div>
                            <div class="separate-top-medium">
                                <label>
                                    <input type="checkbox" <?php echo(osc_notify_contact_item() ? 'checked="checked"'
                                        : ''); ?> name="notify_contact_item" value="1"/>
                                    <?php _e('Send admin a copy of the "contact publisher" email'); ?>
                                </label>
                            </div>
                            <div class="separate-top-medium">
                                <label>
                                    <input type="checkbox" <?php echo(osc_notify_contact_friends() ? 'checked="checked"'
                                        : ''); ?> name="notify_contact_friends" value="1"/>
                                    <?php _e('Send admin a copy to "share listing" email'); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Warn about expiration'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-small" name="warn_expiration"
                                   value="<?php echo osc_esc_html(osc_warn_expiration()); ?>"/>
                            <?php _e('days'); ?>
                            <div class="help-box"><?php _e('This option will send an email X days before an ad expires to the author. 0 for no email.'); ?></div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"> <?php _e('Title length'); ?></div>
                        <div class="form-controls">
                            <div class="separate-top-medium">
                                <?php printf(__('%s characters '),
                                             '<input type="text" class="input-small" name="max_chars_per_title" value="'
                                             . osc_max_characters_per_title() . '" />'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"> <?php _e('Description length'); ?></div>
                        <div class="separate-top-medium">
                            <div class="form-controls">
                                <?php printf(__('%s characters '),
                                             '<input type="text" class="input-small" name="max_chars_per_description" value="'
                                             . osc_max_characters_per_description() . '" />'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"> <?php _e('Rich Edit'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <label>
                                    <input type="checkbox" <?php echo(osc_tinymce_frontend() ? 'checked="checked"'
                                        : ''); ?> name="tinymce" value="1"/>
                                    <?php _e('Enable TinyMCE on frontend'); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"> <?php _e('Optional fields'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <label>
                                    <input type="checkbox" <?php echo(osc_price_enabled_at_items() ? 'checked="checked"'
                                        : ''); ?> name="enableField#f_price@items" value="1"/>
                                    <?php _e('Price'); ?>
                                </label>
                                <div class="separate-top-medium">
                                    <label>
                                        <input type="checkbox" <?php echo(osc_images_enabled_at_items()
                                            ? 'checked="checked"' : ''); ?> name="enableField#images@items" value="1"/>
                                        <?php _e('Attach images'); ?>
                                    </label>
                                </div>
                                <div class="separate-top-medium">
                                    <?php printf(__('Attach %s images per listing'),
                                                 '<input type="text" class="input-small" name="numImages@items" value="'
                                                 . osc_max_images_per_item() . '" />'); ?>
                                    <div class="help-box"><?php _e('If the value is zero, it means an unlimited number of images is allowed'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"> <?php _e('Maps'); ?></div>
                        <div class="separate-top-medium">
                            <div class="form-controls">
                                <?php $map_type = osc_item_map_type(); ?>
                                <select class="input-small" name="map_type">
                                    <option value="0" <?php if (!$map_type) {
                                        echo 'selected';
                                                      } ?>><?php _e('None'); ?></option>
                                    <option value="google" <?php if ($map_type === 'google') {
                                        echo 'selected';
                                                           } ?>><?php _e('Google Maps'); ?></option>
                                    <option value="openstreet" <?php if ($map_type === 'openstreet') {
                                        echo 'selected';
                                                               } ?>><?php _e('OpenStreetMaps'); ?></option>
                                </select>
                                <div class="help-box"><?php _e('Set the API key in Settings -> General.'); ?></div>
                            </div>
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
