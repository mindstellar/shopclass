<?php

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

use mindstellar\utility\Deprecate;

/**
 * Class PageForm
 */
class PageForm extends Form
{
    /**
     * Echo the hidden input carrying the page id, when the page exists.
     *
     * @param array<string,mixed>|null $page
     *
     * @return void
     */
    public static function primary_input_hidden($page = null)
    {
        if (isset($page['pk_i_id'])) {
            $attributes['id'] = 'id';
            echo (new self())->hidden('id', $page['pk_i_id'], $attributes);
        }
    }

    /**
     * Echo the internal-name field, read-only for indelible pages.
     *
     * @param array<string,mixed>|null $page
     *
     * @return void
     */
    public static function internal_name_input_text($page = null)
    {
        $internal_name = '';
        if (is_array($page) && isset($page['s_internal_name'])) {
            $internal_name = $page['s_internal_name'];
        }
        if (Session::newInstance()->_getForm('s_internal_name') != '') {
            $internal_name = Session::newInstance()->_getForm('s_internal_name');
        }
        $attrs = array();
        if ((isset($page['b_indelible']) && $page['b_indelible'] == 1)) {
            $attrs['readonly'] = true;
            $attrs['disabled'] = true;
        }
        osc_admin_field(array(
            'row'   => false,
            'id'    => 's_internal_name',
            'name'  => 's_internal_name',
            'value' => $internal_name,
            'attrs' => $attrs,
        ));
    }

    /**
     * Echo the "show in links" checkbox for a page.
     *
     * @param array<string,mixed>|null $page
     *
     * @return void
     */
    public static function link_checkbox($page = null)
    {
        $attributes['id']           = 'b_link';
        if (isset($page['b_link']) && $page['b_link']) {
            $attributes['checked'] = true;
        }
        echo (new self())->checkbox('b_link', 1, $attributes);
    }

    /**
     * Echo the per-locale title and body fields in the legacy tabber markup.
     *
     * @param array<int,array<string,mixed>> $locales
     * @param array<string,mixed>|null       $page
     *
     * @return void
     * @deprecated since 5.1.0
     * @see printMultiLangTitleDesc
     */
    public static function multilanguage_name_description($locales, $page = null)
    {
        Deprecate::deprecatedFunction(
            'multilanguage_name_description',
            '5.1.0',
            'printMultiLangTitleDesc'
        );
        $num_locales = count($locales);
        if ($num_locales > 1) {
            echo '<div class="tabber">';
        }
        $aFieldsDescription = Session::newInstance()->_getForm('aFieldsDescription');
        foreach ($locales as $locale) {
            if ($num_locales > 1) {
                echo '<div class="tabbertab">';
                echo '<h2>' . $locale['s_name'] . '</h2>';
            }
            echo '<div class="FormElement">';
            echo '<div class="FormElementName">' . __('Title') . '</div>';
            echo '<div class="FormElementInput">';
            $title = '';
            if (isset($page['locale'][$locale['pk_c_code']])) {
                $title = $page['locale'][$locale['pk_c_code']]['s_title'];
            }
            if (isset($aFieldsDescription[$locale['pk_c_code']]['s_title'])
                && $aFieldsDescription[$locale['pk_c_code']]['s_title']
            ) {
                $title = $aFieldsDescription[$locale['pk_c_code']]['s_title'];
            }
            $attributes['id'] = $locale['pk_c_code'] . '#s_title';

            echo (new self())->text($locale['pk_c_code'] . '#s_title', $title, $attributes);

            echo '</div>';
            echo '</div>';
            echo '<div class="FormElement">';
            echo '<div class="FormElementName">' . __('Body') . '</div>';
            echo '<div class="FormElementInput">';
            $description = '';
            if (isset($page['locale'][$locale['pk_c_code']])) {
                $description = $page['locale'][$locale['pk_c_code']]['s_text'];
            }
            if (isset($aFieldsDescription[$locale['pk_c_code']]['s_text'])
                && $aFieldsDescription[$locale['pk_c_code']]['s_text']
            ) {
                $description = $aFieldsDescription[$locale['pk_c_code']]['s_text'];
            }
            $attributes1['id'] =  $locale['pk_c_code'] . '#s_text';
            echo (new self())->textarea($locale['pk_c_code'] . '#s_text', $description, $attributes1);
            echo '</div>';
            echo '</div>';
            if ($num_locales > 1) {
                echo '</div>';
            }
        }
        if ($num_locales > 1) {
            echo '</div>';
        }
    }

    /**
     * Generate MultiLanguage Title Description Fields for Item
     *
     * @param array<string,mixed>|null $page
     * @param bool                     $with_tab Also print the locale tab strip
     *
     * @return void
     */
    public static function printMultiLangTitleDesc($page = null, $with_tab = true)
    {
        if ($with_tab) {
            self::printMultiLangTab();
        }
        echo '<div class="mb-3" id="multiLangTabsContent">';

        foreach (osc_get_admin_locales() as $locale) {
            $hidden = ($locale['pk_c_code'] === osc_current_admin_locale()) ? '' : ' hidden';
            echo '<div id="' . osc_esc_html($locale['pk_c_code']) . '" role="tabpanel"' . $hidden . '>';
            self::printPageTitleInput($locale, $page);
            self::printPageDescriptionInput($locale, $page);
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Print MultiLang Tab
     *
     * @return void
     */
    public static function printMultiLangTab()
    {
        $locales = osc_get_admin_locales();
        if (count($locales) > 1) {
            echo '<div id="language-tab" class="ui-osc-tabs osc-tab mt-3">';
            echo '<ul>';
            foreach ($locales as $locale) {
                $active = ($locale['pk_c_code'] === osc_current_admin_locale()) ? ' class="ui-tabs-active ui-state-active"' : '';
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
     * @param array<string,mixed> $locale
     * @param array<string,mixed> $page
     *
     * @return void
     */
    private static function printPageTitleInput($locale, array $page)
    {
        $aFieldsDescription = Session::newInstance()->_getForm('aFieldsDescription');
        $title = '';
        if (isset($aFieldsDescription[$locale['pk_c_code']]['s_title'])) {
            $title = $aFieldsDescription[$locale['pk_c_code']]['s_title'];
        } elseif (isset($page['locale'][$locale['pk_c_code']])) {
            $title = $page['locale'][$locale['pk_c_code']]['s_title'];
        }
        $value        = osc_apply_filter('admin_page_title', $title, $page, $locale);
        $name         = $locale['pk_c_code'] . '#s_title';
        $attributes   = [
            'id'          => $name,
            'placeholder' => __('Enter title here') . ' *',
        ];
        $options      = [
            'sanitize' => 'html',
            'label'    => __('Title') . ' *'
        ];
        try {
            echo (new self())->text($name, $value, $attributes, $options);
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
     * @param array<string,mixed>|null $page
     *
     * @return void
     */
    private static function printPageDescriptionInput($locale, ?array $page = null)
    {
        $description = '';
        $aFieldsDescription = Session::newInstance()->_getForm('aFieldsDescription');
        if (isset($page['locale'][$locale['pk_c_code']])) {
            $description = $page['locale'][$locale['pk_c_code']]['s_text'];
        }
        if (isset($aFieldsDescription[$locale['pk_c_code']]['s_text'])
            && $aFieldsDescription[$locale['pk_c_code']]['s_text']
        ) {
            $description = $aFieldsDescription[$locale['pk_c_code']]['s_text'];
        }

        $value = osc_apply_filter('admin_page_description', $description, $page, $locale);
        $name = $locale['pk_c_code'] . '#s_text';
        $attributes  = [
            'id'       => $name,
            'rows'     => '20'
        ];
        $options     = [
            'label'    => __('Description') . ' *',
            'sanitize' => null,
        ];
        try {
            // Wrapper so a consumer can show/hide the whole editor (label + control
            // + any TinyMCE UI) as a unit — the page builder toggles it off when a
            // page is composed from widgets instead.
            echo '<div class="multilang-description">';
            echo (new self())->textarea($name, $value, $attributes, $options);
            echo '</div>';
        } catch (Exception $e) {
            if (OSC_DEBUG) {
                trigger_error($e->getTraceAsString());
            }
        }
    }
}
