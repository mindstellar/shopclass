<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Created by Mindstellar Community.
 * User: navjottomer
 * Date: 06-08-2021
 * Time: 16:03
 * License is provided in root directory.
 */

namespace mindstellar\form\admin;

use Exception;
use mindstellar\form\base\FormInputs;
use mindstellar\utility\Escape;
use mindstellar\utility\Sanitize;
use Session;

/**
 * Admin Item Form
 */
class Item extends FormInputs
{
    /**
     * @var \mindstellar\form\admin\Item
     */
    private static $instance;
    protected $textClass = 'form-control form-control-sm';
    protected $selectClass = 'form-select form-select-sm';
    protected $passwordClass = 'form-control form-control-sm';
    /**
     * @var \Session
     */
    private $Session;
    /**
     * @var array
     */
    private $adminLocales;
    /**
     * @var string
     */
    private $adminLocaleCode;
    /**
     * @var string
     */
    private $defaultLocaleCode;
    /**
     * @var array
     */
    private $userLocales;

    /**
     * @param \mindstellar\utility\Escape|null   $escape   Defaults to a new Escape instance
     * @param \mindstellar\utility\Sanitize|null $sanitize Defaults to a new Sanitize instance
     */
    public function __construct(?Escape $escape = null, ?Sanitize $sanitize = null)
    {
        parent::__construct($escape, $sanitize);
        $this->Session           = Session::newInstance();
        $this->adminLocales      = osc_get_admin_locales();
        $this->adminLocaleCode   = osc_current_admin_locale();
        $this->defaultLocaleCode = osc_language();
        $this->userLocales       = osc_get_locales();
    }

    /**
     * The shared admin item form instance.
     *
     * @return \mindstellar\form\admin\Item
     */
    public static function instance(): Item
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Generate MultiLanguage Title Description Fields for Item
     *
     * @param array<string,mixed>|null $item     Defaults to the current item
     * @param bool                     $with_tab Also print the locale tab strip
     *
     * @return void
     */
    public function printMultiLangTitleDesc($item = null, $with_tab = true)
    {
        if ($item === null) {
            $item = osc_item();
        }
        if ($with_tab) {
            $this->printMultiLangTab();
        }
        echo '<div class="mb-3" id="multiLangTabsContent">';

        foreach ($this->userLocales as $locale) {
            $hidden = ($locale['pk_c_code'] === $this->defaultLocaleCode) ? '' : ' hidden';
            echo '<div id="' . osc_esc_html($locale['pk_c_code']) . '" role="tabpanel"' . $hidden . '>';
            $this->printItemTitleInput($locale, $item);
            $this->printItemDescriptionInput($locale, $item);
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Print MultiLang Tab
     *
     * @return void
     */
    public function printMultiLangTab()
    {
        if (count($this->userLocales) > 1) {
            echo '<div id="language-tab" class="ui-osc-tabs osc-tab mt-3">';
            echo '<ul>';
            foreach ($this->userLocales as $locale) {
                $active = ($locale['pk_c_code'] === $this->defaultLocaleCode) ? ' class="ui-tabs-active ui-state-active"' : '';
                echo '<li' . $active . '><a href="#' . osc_esc_html($locale['pk_c_code']) . '">'
                     . osc_esc_html($locale['s_name']) . '</a></li>';
            }
            echo '</ul>';
            echo '</div>';
        }
    }

    /**
     * Print Item Title Input
     *
     * @param array<string,mixed>      $locale
     * @param array<string,mixed>|null $item
     *
     * @return void
     */
    private function printItemTitleInput($locale, ?array $item = null)
    {
        $sessionTitle = $this->Session->_getForm('title');
        $value        = $sessionTitle[$locale['pk_c_code']] ?? $item['locale'][$locale['pk_c_code']]['s_title'] ?? '';
        $value        = osc_apply_filter('admin_item_title', $value, $item, $locale);
        $name         = 'title' . '[' . $locale['pk_c_code'] . ']';
        $attributes   = [
            'id'          => $name,
            'placeholder' => __('Enter title here') . ' *',
        ];
        $options      = [
            'sanitize' => 'html',
            'label'    => __('Title') . ' *'
        ];
        try {
            echo $this->text($name, $value, $attributes, $options);
        } catch (Exception $e) {
            if (OSC_DEBUG) {
                trigger_error($e->getTraceAsString());
            }
        }
    }

    /**
     * Print Item Description Text Area
     *
     * @param array<string,mixed>      $locale
     * @param array<string,mixed>|null $item
     *
     * @return void
     */
    private function printItemDescriptionInput($locale, ?array $item = null)
    {
        $sessionDesc = $this->Session->_getForm('description');
        $value       = $sessionDesc[$locale['pk_c_code']] ?? $item['locale'][$locale['pk_c_code']]['s_description'] ?? '';
        $value       = osc_apply_filter('admin_item_description', $value, $item, $locale);
        $name        = 'description' . '[' . $locale['pk_c_code'] . ']';
        $attributes  = [
            'id'   => $name,
            'rows' => '20'
        ];
        $options     = [
            'label'    => __('Description') . ' *',
            'sanitize' => null,
        ];
        try {
            echo $this->textarea($name, $value, $attributes, $options);
        } catch (Exception $e) {
            if (OSC_DEBUG) {
                trigger_error($e->getTraceAsString());
            }
        }
    }

    /**
     * print price field and Currency Select Input
     *
     * @return void
     * @throws \Exception when an input name is empty
     */
    public function itemPrice()
    {
        if (osc_price_enabled_at_items()) {
            $currencies = osc_get_currencies();
            $item       = osc_item();
            echo '<label>' . __('Price') . '</label>';
            echo '<div class="item-price input-group input-group-sm">';
            $this->printPriceInput($item);
            // Create currency select
            if ($this->Session->_getForm('currency')) {
                $item['fk_c_currency_code'] = $this->Session->_getForm('currency');
            }
            if (count($currencies) > 1) {
                $default_key = null;
                $currency    = \Preference::newInstance()->get('currency');
                if (isset($item['fk_c_currency_code'])) {
                    $default_key = $item['fk_c_currency_code'];
                } elseif (isset($currency)) {
                    $default_key = $currency;
                }
                $attributes['id']    = 'currency';
                $attributes['style'] = 'max-width:150px';

                $options['selectPlaceholder'] = __('Select Currency');
                $options['selectOptions']     = [];
                foreach ($currencies as $i) {
                    $options['selectOptions'][$i['pk_c_code']] = $i['s_description'];
                }

                echo $this->select('currency', $default_key, $attributes, $options);
            } elseif (count($currencies) === 1) {
                echo $this->hidden('currency', $currencies[0]['pk_c_code']);
                echo '<div class="input-group-append">';
                echo $currencies[0]['s_description'];
                echo '</div>';
            }
            echo '</div>';
        }
    }

    /**
     * Print Price Input without currency select
     *
     * @param array<string,mixed>|null $item
     *
     * @return void
     */
    private function printPriceInput(?array $item = null)
    {
        if ($this->Session->_getForm('price')) {
            $item['i_price'] = $this->Session->_getForm('price');
        }
        $attr['id']           = 'price';
        $attr['maxlength']    = null;
        $attr['placeholder']  = __('Enter price');
        $attr['autocomplete'] = 'off';

        try {
            echo (new self())->text('price', isset($item['i_price']) ? osc_prepare_price($item['i_price']) : null, $attr);
        } catch (Exception $e) {
            if (OSC_DEBUG) {
                trigger_error($e->getTraceAsString());
            }
        }
        unset($attr);
    }
}
