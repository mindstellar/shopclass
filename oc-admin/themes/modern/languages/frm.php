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


function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?></h1>
    <?php
}


osc_add_hook('admin_page_header', 'customPageHeader');

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Edit language &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

//customize Head
function customHead()
{
    LanguageForm::js_validation();
}


osc_add_hook('admin_header', 'customHead', 10);

$aLocale = __get('aLocale');

osc_current_admin_theme_path('parts/header.php'); ?>
    <h2 class="render-title"><?php _e('Edit language'); ?></h2>
    <div id="language-form" class="col-lg-6">
        <ul id="error_list"></ul>
        <form name="language_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="languages"/>
            <input type="hidden" name="action" value="edit_post"/>
            <?php LanguageForm::primary_input_hidden($aLocale); ?>

            <div class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('Current version'); ?></div>
                    <div class="form-controls">
                        <?php echo $aLocale['s_version']; ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Name'); ?></div>
                    <div class="form-controls">
                        <?php LanguageForm::name_input_text($aLocale); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Short name'); ?></div>
                    <div class="form-controls">
                        <?php LanguageForm::short_name_input_text($aLocale); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Description'); ?></div>
                    <div class="form-controls">
                        <?php LanguageForm::description_input_text($aLocale); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Direction'); ?></div>
                    <div class="form-controls">
                        <?php LanguageForm::text_direction_select($aLocale); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Currency format'); ?></div>
                    <div class="form-controls">
                        <?php LanguageForm::currency_format_input_text($aLocale); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Number of decimals'); ?></div>
                    <div class="form-controls">
                        <?php LanguageForm::num_dec_input_text($aLocale); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Decimal point'); ?></div>
                    <div class="form-controls">
                        <?php LanguageForm::dec_point_input_text($aLocale); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Thousands separator'); ?></div>
                    <div class="form-controls">
                        <?php LanguageForm::thousands_sep_input_text($aLocale); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Date format'); ?></div>
                    <div class="form-controls">
                        <?php LanguageForm::date_format_input_text($aLocale); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Stopwords'); ?></div>
                    <div class="form-controls">
                        <?php LanguageForm::description_textarea($aLocale); ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-controls">
                        <div class="form-label-checkbox">
                            <?php LanguageForm::enabled_input_checkbox($aLocale); ?>
                            <?php _e('Enabled for the public website'); ?>
                        </div>
                        <div class="form-label-checkbox">
                            <?php LanguageForm::enabled_bo_input_checkbox($aLocale); ?>
                            <?php _e('Enabled for the backoffice (oc-admin)'); ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-submit"><?php echo osc_esc_html(__('Save changes')); ?></button>
            </div>
        </form>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>