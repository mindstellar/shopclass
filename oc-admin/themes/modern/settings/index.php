<?php use mindstellar\utility\Utils;

if (!defined('OC_ADMIN')) {
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


$dateFormats = array('F j, Y', 'Y/m/d', 'm/d/Y', 'd/m/Y');
$timeFormats = array('g:i a', 'g:i A', 'H:i');

$aLanguages  = __get('aLanguages');
$aCurrencies = __get('aCurrencies');

//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            oscValidateForm(document.querySelector('form[name=settings_form]'), {
                rules: {
                    pageTitle: { required: true, minlength: 1 },
                    contactEmail: { required: true, email: true },
                    num_rss_items: { required: true, digits: true },
                    max_latest_items_at_home: { required: true, digits: true },
                    default_results_per_page: { required: true, digits: true }
                },
                messages: {
                    pageTitle: {
                        required: '<?php echo osc_esc_js(__('Page title: this field is required')); ?>.',
                        minlength: '<?php echo osc_esc_js(__('Page title: this field is required')); ?>.'
                    },
                    contactEmail: {
                        required: '<?php echo osc_esc_js(__('Email: this field is required')); ?>.',
                        email: '<?php echo osc_esc_js(__('Invalid email address')); ?>.'
                    },
                    num_rss_items: {
                        required: '<?php echo osc_esc_js(__('Listings shown in RSS feed: this field is required')); ?>.',
                        digits: '<?php echo osc_esc_js(__('Listings shown in RSS feed: this field must only contain numeric characters')); ?>.'
                    },
                    max_latest_items_at_home: {
                        required: '<?php echo osc_esc_js(__('Latest listings shown: this field is required')); ?>.',
                        digits: '<?php echo osc_esc_js(__('Latest listings shown: this field must only contain numeric characters')); ?>.'
                    },
                    default_results_per_page: {
                        required: '<?php echo osc_esc_js(__('The search page shows: this field is required')); ?>.',
                        digits: '<?php echo osc_esc_js(__('The search page shows: this field must only contain numeric characters')); ?>.'
                    }
                },
                errorContainer: '#error_list',
                onInvalid: function () {
                    var h1 = document.querySelector('h1');
                    if (h1) { h1.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                }
            });
        });

        function custom_date(date_format) {
            fetch("<?php echo osc_admin_base_url(true); ?>?page=ajax&action=date_format&format=" + encodeURIComponent(date_format), {
                credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); }).then(function (data) {
                var el = document.getElementById('custom_date');
                if (el) { el.textContent = data.str_formatted != '' ? ' <?php echo osc_esc_js(__('Preview')); ?>: ' + data.str_formatted : ''; }
            });
        }

        function custom_time(time_format) {
            fetch("<?php echo osc_admin_base_url(true); ?>?page=ajax&action=date_format&format=" + encodeURIComponent(time_format), {
                credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); }).then(function (data) {
                var el = document.getElementById('custom_time');
                if (el) { el.textContent = data.str_formatted != '' ? ' <?php echo osc_esc_js(__('Preview')); ?>: ' + data.str_formatted : ''; }
            });
        }
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
         . __("Change the basic configuration of your Shopclass. From here, you can modify variables such as the site’s name, "
              . "the default currency or how lists of listings are displayed. <strong>Be careful</strong> when modifying default "
              . "values if you're not sure what you're doing!")
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
    return sprintf(__('General Settings &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="general-setting">
    <!-- settings form -->
    <div id="general-settings">
        <h2 class="render-title"><?php _e('General Settings'); ?></h2>
        <ul id="error_list"></ul>
        <form name="settings_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="update"/>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <div class="form-label"><?php _e('Page title'); ?></div>
                        <div class="form-controls"><input type="text" class="xlarge" name="pageTitle"
                                                          value="<?php echo osc_esc_html(osc_page_title()); ?>"/></div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Page description'); ?></div>
                        <div class="form-controls"><input type="text" class="xlarge" name="pageDesc"
                                                          value="<?php echo osc_esc_html(osc_page_description()); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Contact e-mail'); ?></div>
                        <div class="form-controls"><input type="text" class="xlarge" name="contactEmail"
                                                          value="<?php echo osc_esc_html(osc_contact_email()); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Default language'); ?></div>
                        <div class="form-controls">
                            <select class="form-select form-select-sm " name="language">
                                <?php foreach ($aLanguages as $lang) { ?>
                                    <option value="<?php echo $lang['pk_c_code']; ?>" <?php echo((osc_language()
                                                                                                  === $lang['pk_c_code'])
                                        ? 'selected="selected"'
                                        : ''); ?>><?php echo $lang['s_name']; ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Default currency'); ?></div>
                        <div class="form-controls">
                            <select class="form-select form-select-sm " name="currency" id="currency_admin">
                                <?php foreach ($aCurrencies as $currency) { ?>
                                    <option value="<?php echo osc_esc_html($currency['pk_c_code']); ?>" <?php echo((osc_currency()
                                                                                                                    === $currency['pk_c_code'])
                                        ? 'selected="selected"'
                                        : ''); ?>><?php echo $currency['pk_c_code'] ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Week starts on'); ?></div>
                        <div class="form-controls">
                            <select class="form-select form-select-sm " name="weekStart" id="weekStart">
                                <option value="0" <?php if (osc_week_starts_at() == '0') {
                                    ?>selected="selected"<?php
                                                  } ?>><?php _e('Sunday'); ?></option>
                                <option value="1" <?php if (osc_week_starts_at() == '1') {
                                    ?>selected="selected"<?php
                                                  } ?>><?php _e('Monday'); ?></option>
                                <option value="2" <?php if (osc_week_starts_at() == '2') {
                                    ?>selected="selected"<?php
                                                  } ?>><?php _e('Tuesday'); ?></option>
                                <option value="3" <?php if (osc_week_starts_at() == '3') {
                                    ?>selected="selected"<?php
                                                  } ?>><?php _e('Wednesday'); ?></option>
                                <option value="4" <?php if (osc_week_starts_at() == '4') {
                                    ?>selected="selected"<?php
                                                  } ?>><?php _e('Thursday'); ?></option>
                                <option value="5" <?php if (osc_week_starts_at() == '5') {
                                    ?>selected="selected"<?php
                                                  } ?>><?php _e('Friday'); ?></option>
                                <option value="6" <?php if (osc_week_starts_at() == '6') {
                                    ?>selected="selected"<?php
                                                  } ?>><?php _e('Saturday'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Timezone'); ?></div>
                        <div class="form-controls">
                            <select class="form-select form-select-sm " name="timezone" id="timezone">
                                <?php $selected_tz = osc_timezone(); ?>
                                <option value="" selected="selected"><?php _e('Select a timezone...'); ?></option>
                                <?php
                                $timezone = Utils::timezoneList();
                                foreach ($timezone as $tz) { ?>
                                    <option value="<?php echo $tz; ?>" <?php if ($selected_tz === $tz) {
                                        ?> selected="selected" <?php
                                                   } ?>><?php echo $tz; ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Date & time format'); ?></div>
                        <div class="form-controls">
                            <div class="custom-date-time pt-2">
                                <div id="date">
                                    <div><?php _e('Date'); ?>:</div>
                                    <?php
                                    $custom_checked = true;
                                    foreach ($dateFormats as $dfIndex => $df) {
                                        $checked = false;
                                        if ($df === osc_date_format()) {
                                            $custom_checked = false;
                                            $checked        = true;
                                        } ?>
                                        <div>
                                            <input type="radio" name="df" id="df_<?php echo $dfIndex; ?>"
                                                   value="<?php echo osc_esc_html($df); ?>" <?php echo($checked ? 'checked="checked"'
                                                    : ''); ?>
                                                   onclick="document.getElementById('dateFormat').value = '<?php echo osc_esc_js($df); ?>';"/>
                                            <label for="df_<?php echo $dfIndex; ?>"><?php echo date($df); ?></label>
                                        </div>
                                    <?php } ?>
                                    <div class="input-group input-group-sm pe-1">
                                        <div class="input-group-text">
                                            <input type="radio" name="df" id="df_custom"
                                                   aria-label="<?php echo osc_esc_html(__('Custom date format')); ?>"
                                                   value="df_custom" <?php echo($custom_checked ? 'checked="checked"'
                                                    : ''); ?> />
                                        </div>
                                        <input type="text" name="df_custom_text" id="df_custom_text"
                                               class="input-medium" <?php echo($custom_checked ? 'value="'
                                                                                                 . osc_esc_html(osc_date_format()) . '"'
                                                : ''); ?>
                                               onchange="document.getElementById('dateFormat').value = this.value;"
                                               onkeyup="custom_date(this.value);"/>
                                    </div>
                                    <span id="custom_date"></span>
                                    <input type="hidden" name="dateFormat" id="dateFormat"
                                           value="<?php echo osc_date_format(); ?>"/>
                                </div>
                                <div id="time">
                                    <div><?php _e('Time'); ?>:</div>
                                    <?php
                                    $custom_checked = true;
                                    foreach ($timeFormats as $tfIndex => $tf) {
                                        $checked = false;
                                        if ($tf === osc_time_format()) {
                                            $custom_checked = false;
                                            $checked        = true;
                                        }
                                        ?>
                                        <div>
                                            <input type="radio" name="tf" id="tf_<?php echo $tfIndex; ?>"
                                                   value="<?php echo osc_esc_html($tf); ?>" <?php echo($checked ? 'checked="checked"'
                                                    : ''); ?>
                                                   onclick="document.getElementById('timeFormat').value = '<?php echo osc_esc_js($tf); ?>';"/>
                                            <label for="tf_<?php echo $tfIndex; ?>"><?php echo date($tf); ?></label>
                                        </div>
                                    <?php } ?>
                                    <div class="input-group input-group-sm pe-1">
                                        <div class="input-group-text">
                                            <input type="radio" name="tf" id="tf_custom"
                                                   aria-label="<?php echo osc_esc_html(__('Custom time format')); ?>"
                                                   value="tf_custom" <?php echo($custom_checked ? 'checked="checked"'
                                                    : ''); ?> />
                                        </div>
                                        <input type="text" class="input-medium" <?php echo($custom_checked ? 'value="'
                                                                                                             . osc_esc_html(osc_time_format())
                                                                                                             . '"' : ''); ?>
                                               onchange="document.getElementById('timeFormat').value = this.value;"
                                               onkeyup="custom_time(this.value);"/>
                                    </div>
                                    <span id="custom_time"></span>
                                    <input type="hidden" name="timeFormat" id="timeFormat"
                                           value="<?php echo osc_esc_html(osc_time_format()); ?>"/>
                                </div>
                            </div>
                            <div class="help-box" style="clear:both; float:none;">
                                <a href="http://php.net/date"
                                   target="_blank"><?php _e('Documentation on date and time formatting'); ?></a>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('RSS shows'); ?></div>
                        <div class="form-controls">
                            <input type="number" min="0" class="input-small" name="num_rss_items"
                                   value="<?php echo osc_esc_html(osc_num_rss_items()); ?>"/>
                            <?php _e('listings at most'); ?>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Latest listings shown'); ?></div>
                        <div class="form-controls">
                            <input type="number" min="0" class="input-small" name="max_latest_items_at_home"
                                   value="<?php echo osc_esc_html(osc_max_latest_items_at_home()); ?>"/>
                            <?php _e('at most'); ?>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Search page shows'); ?></div>
                        <div class="form-controls">
                            <input type="number" min="0" class="input-small" name="default_results_per_page"
                                   value="<?php echo osc_esc_html(osc_default_results_per_page_at_search()); ?>"/>
                            <?php _e('listings at most'); ?>
                        </div>
                    </div>
                    <h2 class="render-title separate-top"><?php _e('Category settings'); ?></h2>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Parent categories'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <label>
                                    <input type="checkbox" <?php echo(osc_selectable_parent_categories()
                                        ? 'checked="checked"' : ''); ?> name="selectable_parent_categories" value="1"/>
                                    <?php _e('Allow users to select a parent category as a category
                                    when inserting or editing a listing '); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <h2 class="render-title separate-top"><?php _e('Contact Settings'); ?></h2>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Attachments'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <label>
                                    <input type="checkbox" <?php echo(osc_contact_attachment() ? 'checked="checked"'
                                        : ''); ?> name="enabled_attachment" value="1"/>
                                    <?php _e('Allow people to attach a file to the contact form'); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <h2 class="render-title separate-top"><?php _e('Cron Settings'); ?></h2>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Automatic cron process'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <label>
                                    <input type="checkbox" <?php echo(osc_auto_cron() ? 'checked="checked"' : ''); ?>
                                           name="auto_cron"/>
                                    <?php printf(
                                        __('Allow Shopclass to run a built-in <a href="%s" target="_blank">cron</a>' . ' '
                                           . 'automatically without setting crontab'),
                                        'http://en.wikipedia.org/wiki/Cron'
                                    ); ?>
                                </label>
                            </div>
                            <span class="help-box">
                                <?php _e('It is <b>recommended</b> to have this option enabled, because some features require it.'); ?>
                            </span>
                        </div>
                    </div>
                    <h2 class="render-title separate-top"><?php _e('Google Analytics'); ?></h2>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Tracking ID'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="xlarge" name="ga_tracking_id"
                                   value="<?php echo osc_esc_html(osc_google_analytics_id()); ?>"/>
                            <div class="help-box">
                                <?php _e('Add your Google Analytics measurement ID. Example: G-XXXXXXXXXX'); ?>
                            </div>
                        </div>
                    </div>
                    <h2 class="render-title separate-top"><?php _e('Maps'); ?></h2>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Google Maps key'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="xlarge" name="googlemaps_api_key"
                                   value="<?php echo osc_esc_html(osc_google_maps_api_key()); ?>"/>
                            <div class="help-box">
                                <?php _e('Add your Google Maps JavaScript API key.'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('OpenStreetMaps key'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="xlarge" name="openstreet_api_key"
                                   value="<?php echo osc_esc_html(osc_openstreet_api_key()); ?>"/>
                            <div class="help-box">
                                <?php _e('Add your Mapquest Consumer key.'); ?>
                            </div>
                        </div>
                    </div>
                    <h2 class="render-title separate-top"><?php _e('Software updates'); ?></h2>
                    <?php
                    /**
                     *
                     * <div class="form-row">
                     * <div class="form-label"><?php _e('Core updates'); ?></div>
                     * <div class="form-controls">
                     * <select name="auto_update[]" id="auto_update_core">
                     * <option value="disabled" ><?php _e('Disabled'); ?></option>
                     * <option value="branch" <?php if(strpos(osc_auto_update(),'branch')!==false) {
                     * ?>selected="selected"<?php } ?>><?php _e('Branch - big changes'); ?></option>
                     * <option value="major" <?php if(strpos(osc_auto_update(),'major')!==false) {
                     * ?>selected="selected"<?php } ?>><?php _e('Major - new features'); ?></option>
                     * <option value="minor" <?php if(strpos(osc_auto_update(),'minor')!==false) {
                     * ?>selected="selected"<?php } ?>><?php _e('Minor - bug fixes'); ?></option>
                     * </select>
                     * </div>
                     * </div>
                     * <div class="form-row">
                     * <div class="form-label"><?php _e('Plugin updates'); ?></div>
                     * <div class="form-controls">
                     * <div class="form-label-checkbox">
                     * <label>
                     * <input type="checkbox" <?php echo ( (strpos(osc_auto_update(),'plugins')!==false) ?
                     * 'checked="checked"' : '' ); ?> name="auto_update[]" value="plugins" />
                     * <?php _e('Allow auto-updates plugins'); ?>
                     * </label>
                     * </div>
                     * </div>
                     * </div>
                     * <div class="form-row">
                     * <div class="form-label"><?php _e('Theme updates'); ?></div>
                     * <div class="form-controls">
                     * <div class="form-label-checkbox">
                     * <label>
                     * <input type="checkbox" <?php echo ( (strpos(osc_auto_update(),'themes')!==false) ?
                     * 'checked="checked"' : '' ); ?> name="auto_update[]" value="themes" />
                     * <?php _e('Allow auto-updates of themes'); ?>
                     * </label>
                     * </div>
                     * </div>
                     * </div>
                     * <div class="form-row">
                     * <div class="form-label"><?php _e('Language updates'); ?></div>
                     * <div class="form-controls">
                     * <div class="form-label-checkbox">
                     * <label>
                     * <input type="checkbox" <?php echo ( (strpos(osc_auto_update(),'languages')!==false) ?
                     * 'checked="checked"' : '' ); ?> name="auto_update[]" value="languages" />
                     * <?php _e('Allow auto-updates of languages'); ?>
                     * </label>
                     * </div>
                     * </div>
                     * </div>
                     */
                    ?>
                    <div class="form-row">
                        <div class="form-label"></div>
                        <div class="form-controls">
                            <span id="last-version-check">
                                <?php
                                echo __('Last checked on ') . osc_format_date(date('d-m-Y h:i:s', osc_get_preference('last_version_check'))
                                    ); ?></span>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="checkOsclassUpdate()"><?php _e('Check updates'); ?></button>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Allow Prerelease'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <label>
                                    <input type="checkbox" <?php
                                    echo osc_get_preference('allow_update_prerelease') ? 'checked="checked"' : '';
                                    ?> name="allow_update_prerelease" value="1"/>
                                    <?php _e('Allow prerelease update'); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="clear"></div>
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
<script>
    function checkOsclassUpdate() {
        var lastVersionElement = document.getElementById("last-version-check");
        fetch('<?php echo osc_admin_base_url(true); ?>?page=ajax&action=check_version')
            .then(response => response.json())
            .then(data => {
                lastVersionElement.textContent = '<?php echo osc_esc_js(__('Last checked on ')); ?>' + new Date().toLocaleString();
                setJsMessage('info', data.msg);
            })
            .catch(error => setJsMessage('error', error));
    }
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
