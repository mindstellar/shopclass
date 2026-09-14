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
    'section' => __('Settings'),
    'title'   => __('Edit language'),
));

//customize Head
/**
 * Emit the language form's client-side validation.
 *
 * @return void
 */
function customHead()
{
    LanguageForm::js_validation();
}

osc_add_hook('admin_header', 'customHead', 10);

$aLocale = __get('aLocale');

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Edit language')); ?>
    <div id="language-form" class="col-lg-6">
        <ul id="error_list"></ul>
        <?php osc_admin_form_open(array(
            'name'   => 'language_form',
            'page'   => 'languages',
            'action' => 'edit_post',
            'fields' => array('pk_c_code' => $aLocale['pk_c_code']),
        )); ?>
            <?php osc_admin_form_row_open(__('Current version')); ?>
                    <?php echo osc_esc_html($aLocale['s_version']); ?>
            <?php osc_admin_form_row_close(); ?>
            <?php osc_admin_text(array('name' => 's_name', 'label' => __('Name'), 'value' => $aLocale['s_name'] ?? '')); ?>
            <?php osc_admin_text(array('name' => 's_short_name', 'label' => __('Short name'), 'value' => $aLocale['s_short_name'] ?? '')); ?>
            <?php osc_admin_text(array('name' => 's_description', 'label' => __('Description'), 'value' => $aLocale['s_description'] ?? '')); ?>
            <?php osc_admin_select(array(
                'name'     => 's_direction',
                'label'    => __('Direction'),
                'selected' => $aLocale['s_direction'] ?? 'ltr',
                'options'  => array('ltr' => 'ltr', 'rtl' => 'rtl'),
            )); ?>
            <?php osc_admin_text(array('name' => 's_currency_format', 'label' => __('Currency format'), 'value' => $aLocale['s_currency_format'] ?? '')); ?>
            <?php osc_admin_number(array('name' => 'i_num_dec', 'label' => __('Number of decimals'), 'value' => $aLocale['i_num_dec'] ?? '', 'min' => 0)); ?>
            <?php osc_admin_text(array('name' => 's_dec_point', 'label' => __('Decimal point'), 'value' => $aLocale['s_dec_point'] ?? '')); ?>
            <?php osc_admin_text(array('name' => 's_thousands_sep', 'label' => __('Thousands separator'), 'value' => $aLocale['s_thousands_sep'] ?? '')); ?>
            <?php osc_admin_text(array('name' => 's_date_format', 'label' => __('Date format'), 'value' => $aLocale['s_date_format'] ?? '')); ?>
            <?php osc_admin_textarea(array('name' => 's_stop_words', 'label' => __('Stopwords'), 'value' => $aLocale['s_stop_words'] ?? '')); ?>
            <?php osc_admin_form_row_open(__('Availability')); ?>
                    <?php osc_admin_checkbox(array(
                        'name'    => 'b_enabled',
                        'id'      => 'b_enabled',
                        'value'   => '1',
                        'label'   => __('Enabled for the public website'),
                        'checked' => ($aLocale['b_enabled'] ?? 0) == 1,
                    ));
                    osc_admin_checkbox(array(
                        'name'    => 'b_enabled_bo',
                        'id'      => 'b_enabled_bo',
                        'value'   => '1',
                        'label'   => __('Enabled for the backoffice (oc-admin)'),
                        'checked' => ($aLocale['b_enabled_bo'] ?? 0) == 1,
                    )); ?>
            <?php osc_admin_form_row_close(); ?>
        <?php osc_admin_form_close(); ?>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>