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

use mindstellar\currency\CurrencyCode;

//customize Head
function customHead()
{
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            var code = document.querySelector('input[name=pk_c_code]');
            var decimals = document.querySelector('input[name=i_decimals]');
            var odd = <?php echo json_encode(CurrencyCode::exceptions(), JSON_FORCE_OBJECT); ?>;
            if (code && decimals && !code.disabled) {
                code.addEventListener('input', function () {
                    var c = code.value.trim().toUpperCase();
                    if (c.length === 3 && !decimals.dataset.touched) {
                        decimals.value = Object.prototype.hasOwnProperty.call(odd, c) ? odd[c] : 2;
                    }
                });
                decimals.addEventListener('input', function () { decimals.dataset.touched = '1'; });
            }
            oscValidateForm(document.querySelector('form[name=currency_form]'), {
                rules: {
                    pk_c_code: { required: true, minlength: 3, maxlength: 3 },
                    s_name: { required: true, minlength: 1 }
                },
                messages: {
                    pk_c_code: {
                        required: '<?php echo osc_esc_js(__('Currency code: this field is required')); ?>.',
                        minlength: '<?php echo osc_esc_js(__('Currency code: this field is required')); ?>.',
                        maxlength: '<?php echo osc_esc_js(__('Currency code: this field is required')); ?>.'
                    },
                    s_name: {
                        required: '<?php echo osc_esc_js(__('Name: this field is required')); ?>.',
                        minlength: '<?php echo osc_esc_js(__('Name: this field is required')); ?>.'
                    }
                },
                errorContainer: '#error_list',
                onInvalid: function () {
                    var h1 = document.querySelector('h1');
                    if (h1) { h1.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                }
            });
        });
    </script>
    <?php
}

osc_add_hook('admin_header', 'customHead', 10);

osc_admin_page(array(
    'section' => __('Settings'),
    'heading' => static fn () => customText('title'),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => osc_admin_base_url(true) . '?page=settings&action=currencies&type=add',
            'title' => __('Add'),
        ),
    ),
));

$typeForm = __get('typeForm');
/**
 * @param string $return
 *
 * @return mixed
 */
function customText($return = 'title')
{
    $typeForm = __get('typeForm');
    $text     = array();
    switch ($typeForm) {
        case ('add_post'):
            $text['title']  = __('Add currency');
            $text['button'] = __('Add currency');
            break;
        case ('edit_post'):
            $text['title']  = __('Edit currency');
            $text['button'] = __('Save changes');
            break;
    }

    return $text[$return];
}

/**
 * @param string $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf('%s &raquo; %s', customText('title'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');

$aCurrency = View::getInstance()->_get('aCurrency');

osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_body_open(array('id' => 'add-currency-settings')); ?>
        <ul id="error_list"></ul>
        <?php
        $currencyFields = array('type' => $typeForm);
        if ($typeForm === 'edit_post') {
            // The code is the primary key, so an edit has to carry the row it is editing.
            $currencyFields['pk_c_code'] = $aCurrency['pk_c_code'];
        }
        osc_admin_form_open(array(
            'name'   => 'currency_form',
            'page'   => 'settings',
            'action' => 'currencies',
            'fields' => $currencyFields,
        )); ?>
                    <?php
                    osc_admin_text(array(
                        'name'      => 'pk_c_code',
                        'label'     => __('Currency Code'),
                        'value'     => $aCurrency['pk_c_code'],
                        'width'     => 'num',
                        'disabled'  => $typeForm === 'edit_post',
                        'attrs'     => array('maxlength' => 3),
                        'help_html' => sprintf(
                            __('Must be a three-character code according to the <a href="%s" target="_blank" rel="noopener">ISO 4217</a>'),
                            'https://en.wikipedia.org/wiki/ISO_4217'
                        ),
                    ));
                    osc_admin_text(array(
                        'name'  => 's_description',
                        'label' => __('Currency symbol'),
                        'value' => $aCurrency['s_description'],
                        'width' => 'num',
                    ));
                    osc_admin_number(array(
                        'name'      => 'i_decimals',
                        'label'     => __('Decimals'),
                        'value'     => $aCurrency['i_decimals'] ?? CurrencyCode::decimals((string) $aCurrency['pk_c_code']),
                        'min'       => 0,
                        'max'       => 4,
                        'step'      => 1,
                        'help'      => __('Digits after the point in prices, from 0 to 4. Yen has 0, dinar has 3.'),
                    ));
                    osc_admin_text(array(
                        'name'  => 's_name',
                        'label' => __('Name'),
                        'value' => $aCurrency['s_name'],
                    )); ?>
                    <?php
                    $currencyActions = array(
                        array('label' => customText('button'), 'type' => 'submit', 'variant' => 'primary'),
                    );
                    if ($typeForm === 'edit_post') {
                        $currencyActions[] = array(
                            'label'   => __('Cancel'),
                            'variant' => 'secondary',
                            'url'     => osc_admin_base_url(true) . '?page=settings&action=currencies',
                        );
                    }
                    osc_admin_form_close($currencyActions); ?>
<?php osc_admin_body_close(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>