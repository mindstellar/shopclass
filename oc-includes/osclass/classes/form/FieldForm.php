<?php

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

use mindstellar\utility\Escape;
use mindstellar\utility\Sanitize;

/**
 * Class FieldForm
 */
class FieldForm extends Form
{
    private static $instance;
    /**
     * Enabled admin locale
     * @var array
     */
    private $adminLocales;
    /**
     * Current admin locale
     * @var string
     */
    private $activeAdminLocale;
    /**
     * Enabled user locales
     * @var array
     */
    private $userLocales;
    /**
     * Current User Locale
     * @var string
     */
    private $activeUserLocale;

    public function __construct(Escape $escape = null, Sanitize $sanitize = null)
    {
        $this->adminLocales = osc_get_admin_locales();
        $this->activeAdminLocale = osc_current_admin_locale();

        $this->userLocales = osc_get_locales();
        $this->activeUserLocale = osc_current_user_locale();
        parent::__construct($escape, $sanitize);
    }

    /**
     * Retained for backward compatibility. Custom-field dates now use a native
     * <input type="date"> (see initDatePicker), so no jQuery-UI datepicker
     * locale bootstrap is needed and this is a no-op.
     *
     * @deprecated 5.3.0 native date input needs no locale bootstrap
     */
    public static function i18n_datePicker()
    {
    }

    /**
     * @param null $field
     */
    public static function primary_input_hidden($field = null)
    {
        if (isset($field['pk_i_id'])) {
            echo self::getInstance()->hidden('id', $field['pk_i_id']);
        }
    }

    /**
     * This returns the instance of FieldForm class or creates a new one
     * for private use.
     *
     * @return \FieldForm
     */
    private static function getInstance()
    {
        if (!self::$instance) {
            return self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param null $field
     *
     */
    public static function name_input_text($field = null)
    {
        $name                       = 's_name';
        $value                      = $field['s_name'] ?? '';
        $attributes['id']           = $name;
        $attributes['autocomplete'] = 'off';
        echo self::getInstance()->text($name, $value, $attributes);
    }

    /**
     * @param null $field
     *
     */
    public static function options_input_text($field = null)
    {
        $name             = 's_options';
        $value            = (isset($field['s_options'])) ? html_entity_decode($field['s_options']) : '';
        $attributes['id'] = $name;
        echo self::getInstance()->text($name, $value, $attributes);
    }

    /**
     * @param null $field
     */
    public static function required_checkbox($field = null)
    {
        $name             = 'field_required';
        $attributes['id'] = $name;
        if (($field !== null && isset($field['b_required']) && $field['b_required'])) {
            $attributes['checked'] = true;
        }
        echo self::getInstance()->checkbox($name, 1, $attributes);
    }

    /**
     * @param null $field
     */
    public static function searchable_checkbox($field = null)
    {
        $name             = 'field_searchable';
        $attributes['id'] = $name;
        if ($field !== null && isset($field['b_searchable']) && $field['b_searchable']) {
            $attributes['checked'] = true;
        }
        echo self::getInstance()->checkbox($name, 1, $attributes);
    }

    /**
     * @param null $field
     */
    public static function newtab_checkbox($field = null)
    {
        $name             = 'b_new_tab';
        $attributes['id'] = $name;

        if (!empty($field)) {
            $json_decoded_field = json_decode($field['s_meta'], true);
        }
        if (isset($json_decoded_field['b_new_tab']) && $json_decoded_field['b_new_tab']) {
            $attributes['checked'] = true;
        }

        try {
            echo self::getInstance()->checkbox($name, 1, $attributes);
        } catch (Exception $e) {
            trigger_error($e->getMessage());
        }
    }

    /**
     * @param null $field
     *
     */
    public static function type_select($field = null)
    {
        $name                         = 'field_type';
        $attributes['id']             = $name;
        $options['selectPlaceholder'] = false;
        $options['selectOptions']     = 'TEXT,NUMBER,TEXTAREA,DROPDOWN,RADIO,CHECKBOX,URL,DATE,DATEINTERVAL';
        echo self::getInstance()->select($name, $field['e_type'] ?? '', $attributes, $options);
    }

    /**
     * @param null $catId
     *
     * @return bool|false|void
     */
    public static function meta_fields_search($catId = null)
    {
        // we received the categoryID
        if ($catId === null) {
            return false;
        }

        $aCustomFields = array();
        // we check if the category is the same as our plugin
        foreach ($catId as $id) {
            $aTemp = Field::newInstance()->findByCategory($id);
            foreach ($aTemp as $field) {
                if ($field['b_searchable']) {
                    $aCustomFields[$field['pk_i_id']] = $field;
                }
            }
        }

        if (count($aCustomFields) > 0) {
            echo '<fieldset>';
            foreach ($aCustomFields as $field) {
                if ($field['e_type'] === 'DATEINTERVAL' || $field['e_type'] === 'NUMBER') {
                    echo '<div class="row two_input">';
                } elseif ($field['e_type'] === 'CHECKBOX') {
                    echo '<div class="row checkbox">';
                } elseif ($field['e_type'] === 'RADIO') {
                    echo '<div class="row radio">';
                } else {
                    echo '<div class="row one_input">';
                }
                self::meta($field, true);
                echo '</div>';
            }
            echo '</fieldset>';
        }
    }

    /**
     * @param null $field
     * @param bool $search
     */
    public static function meta($field = null, bool $search = false)
    {

        if ($field !== null) {
            if ($field['e_type'] === 'DATEINTERVAL' || ($field['e_type'] === 'NUMBER' && $search)) {
                $field['s_value']         = array();
                $field['s_value']['from'] = '';
                $field['s_value']['to']   = '';
            }

            // date interval
            if ($field['e_type'] === 'DATEINTERVAL') {
                if (!$search) {
                    $aInterval = Field::newInstance()
                                      ->getDateIntervalByPrimaryKey($field['fk_i_item_id'], $field['pk_i_id']);

                    if (is_array($aInterval) && !empty($aInterval)) {
                        $temp['from']     = @$aInterval['from'];
                        $temp['to']       = @$aInterval['to'];
                        $field['s_value'] = $temp;
                    }
                } else {
                    $_meta            = Params::getParam('meta');
                    $temp['from']     = @(int)$_meta[$field['pk_i_id']]['from'];
                    $temp['to']       = @(int)$_meta[$field['pk_i_id']]['to'];
                    $field['s_value'] = $temp;
                }
            }
            // end date interval
            if (Session::newInstance()->_getForm('meta_' . $field['pk_i_id']) != '') {
                $field['s_value'] = Session::newInstance()->_getForm('meta_' . $field['pk_i_id']);
            } elseif (!isset($field['s_value']) || !$field['s_value']) {
                $s_value          = Params::getParam('meta');
                $field['s_value'] = '';
                if (isset($s_value[$field['pk_i_id']])) {
                    $field['s_value'] = $s_value[$field['pk_i_id']];
                }
                unset($s_value);
            }
            //switch using $field['e_type']
            $name             = 'meta[' . $field['pk_i_id'] . ']';
            $id               = 'meta_' . $field['s_slug'];
            $label            = $field['s_name'];
            $value            = $field['s_value'];
            $attributes['id'] = $id;
            $options = [];

            switch ($field['e_type']) {
                case 'TEXTAREA':
                    if ($search) {
                        $options['sanitize'] = 'html';

                        echo '<h6>' . $label . '</h6>';
                        echo self::getInstance()->text($name, $value, $attributes, $options);
                    } else {
                        $value              = osc_apply_filter(
                            'osc_item_edit_meta_textarea_value_filter',
                            $value,
                            $field
                        );
                        $attributes['rows'] = 10;
                        $options['label']   = $label;
                        echo self::getInstance()->textarea($name, $value, $attributes, $options);
                    }
                    break;
                case 'DROPDOWN':
                    if ($search) {
                        echo '<h6>' . $label . '</h6>';
                    } else {
                        $options['label'] = $label;
                    }

                    if (isset($field['s_options'])) {
                        $options['selectOptions']     = $field['s_options'];
                        $options['selectPlaceholder'] = __('Select', 'osclass');
                        echo self::getInstance()->select($name, $value, $attributes, $options);
                    }
                    break;
                case 'RADIO':
                    if ($search) {
                        echo '<h6>' . $label . '</h6>';
                    } else {
                        $options['label'] = $label;
                    }
                    if (isset($field['s_options'])) {
                        $options['radioOptions'] = $field['s_options'];
                        echo self::getInstance()->radio($name, $value, $attributes, $options);
                    }
                    break;
                case 'CHECKBOX':
                    $options['divClass'] = 'form-check';
                    $options['label']    = $label;
                    if ($value) {
                        $attributes['checked'] = true;
                    }
                    echo self::getInstance()->checkbox($name, 1, $attributes, $options);
                    break;
                case 'DATE':
                    if ($search) {
                        echo '<h6>' . $label . '</h6>';
                    } else {
                        $options['label'] = $label;
                    }
                    // add cf_date class to the input field; the visible control is a
                    // native date input, the hidden field carries the unix timestamp.
                    $attributes['class'] = self::getInstance()->textClass . ' cf_date ' . $id;
                    $attributes['type']  = 'date';
                    echo self::getInstance()->hidden($name, $value, ['id' => $id]);
                    unset($attributes['id']);
                    echo self::getInstance()->text('datepicker-placeholder', '', $attributes, $options);
                    // timestamp/1000 (javascript timestamp)
                    self::initDatePicker(
                        'meta_' . $field['s_slug'],
                        osc_date_format(),
                        $field['s_value']
                    );
                    break;
                case 'DATEINTERVAL':
                    if ($search) {
                        echo '<h6>' . $label . '</h6>';
                    } else {
                        // print label tag
                        echo '<label for="meta_' . $field['s_slug'] . '_from">' . $label . '</label>';
                    }
                    // add cf_date_interval class to the input field; native date
                    // inputs, hidden fields carry the unix timestamps.
                    $attributes['type']  = 'date';
                    $attributes['class'] = self::getInstance()->textClass . ' cf_date_interval ' . $id . '_from';
                    echo self::getInstance()->hidden($name . '[from]', $value['from'], ['id' => $id . '_from']);
                    echo '<div class="input-group input-group-sm">';
                    echo '<span class="input-group-text">' . ucfirst(__('from')) . ' </span>';
                    unset($attributes['id']);
                    echo self::getInstance()->text('datepicker-placeholder-from', '', $attributes);

                    echo '<span class="input-group-text">' . ucfirst(__('to')) . ' </span>';
                    $attributes['class'] = self::getInstance()->textClass . ' cf_date_interval ' . $id . '_to';
                    echo self::getInstance()->hidden($name . '[to]', $value['to'], ['id' => $id . '_to']);
                    unset($attributes['id']);
                    echo self::getInstance()->text('datepicker-placeholder-to', '', $attributes);
                    echo '</div>';

                    self::initDatePicker(
                        'meta_' . $field['s_slug'] . '_from',
                        osc_date_format(),
                        $field['s_value']['from'],
                        'from'
                    );
                    self::initDatePicker(
                        'meta_' . $field['s_slug'] . '_to',
                        osc_date_format(),
                        $field['s_value']['to'],
                        'to'
                    );
                    break;
                case 'NUMBER':
                    if ($search) {
                        echo '<h6>' . $label . '</h6>';

                        echo '<div class="input-group input-group-sm">';
                        echo '<span class="input-group-text">' . ucfirst(__('from')) . ' </span>';
                        echo self::getInstance()->text($name . '[from]', $value['from'], $attributes);
                        echo '<span class="input-group-text">' . ucfirst(__('to')) . ' </span>';
                        echo self::getInstance()->text($name . '[to]', $value['to'], $attributes);
                        echo '</div>';
                    } else {
                        $options['label'] = $label;
                        $attributes['type'] = 'number';
                        echo self::getInstance()->text($name, $value, $attributes, $options);
                    }
                    break;
                default:
                    if ($search) {
                        echo '<h6>' . $label . '</h6>';
                    } else {
                        $options['label'] = $label;
                    }
                    echo self::getInstance()->text($name, $value, $attributes, $options);
                    break;
            }
        }
    }

    /**
     * Wire a custom-field date input. The visible control is a native
     * <input type="date"> (ISO yyyy-mm-dd); the hidden field submitted to the
     * backend carries a unix timestamp (seconds) — the stored contract is
     * unchanged. Vanilla JS, no jQuery/jQuery-UI.
     *
     * @param        $id_field
     * @param        $dateFormat  kept for signature compatibility; unused now
     *                            (the native input renders in the browser locale)
     * @param        $value       stored unix timestamp, or 0
     * @param string $type        'from' | 'to' | 'none'
     */
    public static function initDatePicker($id_field, $dateFormat, $value, $type = 'none')
    {
        if (!$value) {
            $value = 0;
        } ?>
        <script type="text/javascript">
            (function () {
                var id = <?php echo json_encode($id_field); ?>;
                var stored = <?php echo (int)$value; ?>;
                var type = <?php echo json_encode($type); ?>;

                var hidden = document.getElementById(id);
                var visible = document.querySelector('.' + id);
                if (!hidden || !visible) {
                    return;
                }

                function pad(n) { return (n < 10 ? '0' : '') + n; }

                // Prefill the native date input from the stored timestamp.
                if (stored > 0) {
                    var d = new Date(stored * 1000);
                    visible.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
                }

                // On change, convert the ISO date back to a unix timestamp, keeping
                // the from/to day-boundary behaviour of the old picker.
                visible.addEventListener('change', function () {
                    if (!visible.value) {
                        hidden.value = '';
                        return;
                    }
                    var p = visible.value.split('-');
                    var dt = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
                    if (type === 'to') {
                        dt.setHours(23, 59, 59, 0);
                    } else {
                        dt.setHours(0, 0, 0, 0);
                    }
                    hidden.value = Math.floor(dt.getTime() / 1000);
                });
            })();
        </script>
        <?php
    }

    /**
     * @param null $catId
     * @param null $itemId
     */
    public static function meta_fields_input($catId = null, $itemId = null)
    {
        $fields = Field::newInstance()->findByCategoryItem($catId, $itemId);
        if (count($fields) > 0) {
            echo '<div class="meta_list card-body">';
            foreach ($fields as $field) {
                echo '<div class="meta">';
                self::meta($field);
                echo '</div>';
            }
            echo '</div>';
        }
    }
    /**
     * Generate MultiLanguage Title Description Fields for Item
     *
     * @param null $field
     */
    public static function multiLangTitle($field)
    {
        $locales  = osc_get_admin_locales();
        $currentLocale = osc_current_admin_locale();
        self::getInstance()->printMultiLangTab($locales, $currentLocale);

        $locales  = osc_get_admin_locales();
        $currentLocale = osc_current_admin_locale();
        echo '<div class="tab-content mb-3" id="multiLangTabsContent" >';

        foreach ($locales as $locale) {
            // Add class active if $current_locale is equal to $locale['pk_c_code']
            $active = '';
            if ($locale['pk_c_code'] === $currentLocale) {
                $active = 'show active';
            }
            echo '<div class="tab-pane fade ' . $active . '" id="meta_' . $locale['pk_c_code'] . '" role="tabpanel">';
            self::getInstance()->printFieldTitle($locale, $field);
            echo '</div>';
        }
        echo '</div>';
    }
     /**
     * Print MultiLang Tab
     */
    private function printMultiLangTab($locales, $activeLocaleCode)
    {
        if ($locales > 1) {
            echo '<div id="language-tab" class="mt-3">';
            echo '<ul class="nav nav-tabs nav-tabs-sm" id="multiLangTabs" role="tablist">';
            foreach ($locales as $locale) {
                $active = '';
                if ($locale['pk_c_code'] === $activeLocaleCode) {
                    $active = 'show active';
                }
                echo '<li class="nav-item"><a class="nav-link ' . $active . '" href="#meta_' . $locale['pk_c_code']
                    . '" data-bs-toggle="tab">'
                    . $locale['s_name'] . '</a></li>';
            }
            echo '</ul>';
            echo '</div>';
        }
    }

    /**
     * Print Multi language Field Name Input
     *
     * @param                                   $locale
     * @param array                             $field
     */
    private function printFieldTitle($locale, array $field = null)
    {
        $fieldTitleInputName         = 'meta_s_name' . '[' . $locale['pk_c_code'] . ']';
        $valueTitleInput        = $field['locale'][$locale['pk_c_code']]['s_name'] ?? '';
        $fieldTitleAttributes   = [
            'id'          => $fieldTitleInputName,
            'placeholder' => __('Enter field name'),
        ];
        $fieldTitleOptions      = [
            'sanitize' => 'html',
            'label'    => __('Name'),
            'inputDivClass' => 'form-controls',
            'divClass' => 'form-row meta-name-inputs'
        ];
        try {
            echo $this->text($fieldTitleInputName, $valueTitleInput, $fieldTitleAttributes, $fieldTitleOptions);
        } catch (Exception $e) {
            if (defined('OSC_DEBUG') && OSC_DEBUG) {
                trigger_error($e->getTraceAsString(), E_USER_WARNING);
            }
        }

    }
}
