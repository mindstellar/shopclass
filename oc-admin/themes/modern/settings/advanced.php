<?php
if (!defined('OC_ADMIN')) {
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


$current_host = parse_url(Params::getServerParam('HTTP_HOST'), PHP_URL_HOST);
if ($current_host === null) {
    $current_host = Params::getServerParam('HTTP_HOST');
}

//customize Head
function customHead()
{
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
         . __("Change advanced configuration of your Osclass. "
              . "<strong>Be careful</strong> when modifying default values if you're not sure what you're doing!")
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
    return sprintf(__('Advanced Settings &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php');
?>
<div id="general-setting">
    <!-- settings form -->
    <div id="general-settings">
        <?php
        $cache_type = Object_Cache_Factory::newInstance()->_get_cache();
        if ($cache_type !== 'default') { ?>
            <!--    Cache flush    -->
            <h2 class="render-title"><?php _e('Flush cache'); ?></h2>
            <form id="cache_flush" name="cache_flush" action="<?php echo osc_admin_base_url(true); ?>" method="post">
                <input type="hidden" name="page" value="settings"/>
                <input type="hidden" name="action" value="advanced_cache_flush"/>
                <fieldset>
                    <div class="form-horizontal">
                        <div class="form-row">
                            <div class="form-label"><?php _e('Flush cache'); ?></div>
                            <div class="form-controls"><input type="submit"
                                                              value="<?php echo osc_esc_html(__('Flush cache')); ?>"
                                                              class="btn btn-submit"/>
                                <div class="help-box"><?php _e('Remove all data from cache.'); ?>
                                    <b><?php echo $cache_type; ?></b></div>
                            </div>
                        </div>
                    </div>
                </fieldset>
            </form>
        <?php } ?>
        <h2 class="render-title"><?php _e('Advanced Settings'); ?></h2>
        <ul id="error_list"></ul>
        <form name="settings_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="advanced_post"/>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <div class="form-label"><?php _e('Subdomain type'); ?></div>
                        <div class="form-controls">
                            <select name="e_type" id="e_type">
                                <option value="" <?php if (osc_subdomain_type()) {
                                    ?>selected="selected"<?php
                                                 } ?>><?php _e('No subdomains'); ?></option>
                                <option value="category" <?php if (osc_subdomain_type() === 'category') {
                                    ?>selected="selected"<?php
                                                         } ?>><?php _e('Category based'); ?></option>
                                <option value="country" <?php if (osc_subdomain_type() === 'country') {
                                    ?>selected="selected"<?php
                                                        } ?>><?php _e('Country based'); ?></option>
                                <option value="region" <?php if (osc_subdomain_type() === 'region') {
                                    ?>selected="selected"<?php
                                                       } ?>><?php _e('Region based'); ?></option>
                                <option value="city" <?php if (osc_subdomain_type() === 'city') {
                                    ?>selected="selected"<?php
                                                     } ?>><?php _e('City based'); ?></option>
                                <option value="user" <?php if (osc_subdomain_type() === 'user') {
                                    ?>selected="selected"<?php
                                                     } ?>><?php _e('User based'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Host'); ?></div>
                        <div class="form-controls"><input type="text" class="xlarge" name="s_host"
                                                          value="<?php echo osc_esc_html(osc_subdomain_host()); ?>"/>
                            <div class="help-box"><?php _e('Your host is required to know the subdomain.'); ?>
                                <?php printf(
                                    __('Your current host is "%s". Add it without "www".'),
                                    $current_host
                                ); ?><?php _e('Remember to enable cookies for the subdomains too.'); ?></div>
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
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
