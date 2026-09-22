<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\ui;

/**
 * The rendering behind osc_admin_category_picker(), osc_admin_location_picker() and
 * osc_admin_user_picker(). Those functions are the public, plugin-facing API and stay
 * procedural; this class holds the markup.
 *
 * Each picker is three things at once: the controls whose posted names the save path
 * already reads, the ids the shipped scripts already bind to, and a way of choosing that
 * is not a stack of cascading selects. The first two are why none of them invents a name
 * or an id of its own.
 */
class Picker
{
    /**
     * The category chooser: the hidden field the save reads, a button showing the chosen
     * path, and a searchable list of the whole tree. Body of osc_admin_category_picker().
     *
     * Picking fires `change` on the hidden field, which is where the plugin-field loader
     * and the price show/hide already listen, so both keep working untouched.
     *
     * @param array<string,mixed> $opts
     *
     * @return void
     */
    public static function category(array $opts = array())
    {
        $name    = (string)($opts['name'] ?? 'catId');
        $id      = (string)($opts['id'] ?? $name);
        $value   = (string)($opts['value'] ?? '');
        $label   = (string)($opts['label'] ?? __('Category'));
        $pickId  = $id . '-picker';
        $listId  = $id . '-list';
        $rows    = $opts['categories'] ?? null;
        if (!is_array($rows)) {
            $rows = \Category::newInstance()->listEnabled();
        }
        $options = self::categoryOptions($rows);
        $chosen  = $options[$value] ?? null;
        $error   = (string)($opts['error'] ?? '');

        echo '<div class="osc-field osc-catpick" data-osc-catpick>';
        echo '<label class="form-label' . (empty($opts['required']) ? '' : ' osc-field-required') . '"'
            . ' for="' . osc_esc_html($pickId) . '">' . osc_esc_html($label) . '</label>';
        echo '<input type="hidden" id="' . osc_esc_html($id) . '" name="' . osc_esc_html($name) . '"'
            . ' value="' . osc_esc_html($value) . '" />';

        // input-text is what the admin draws a control with; a bare .form-control would be
        // a size taller than the fields beside it.
        echo '<button type="button" class="input-text osc-catpick-value" id="' . osc_esc_html($pickId) . '"'
            . ' aria-haspopup="listbox" aria-expanded="false" aria-controls="' . osc_esc_html($listId) . '"'
            . ($error === '' ? '' : ' aria-describedby="' . osc_esc_html($pickId . '-error') . '"')
            . ' data-osc-catpick-empty="' . osc_esc_html(__('Choose a category')) . '">';
        echo '<span class="osc-catpick-path" data-osc-catpick-path>'
            . ($chosen === null
                ? '<span class="osc-catpick-placeholder">' . osc_esc_html(__('Choose a category')) . '</span>'
                : self::categoryPathHtml($chosen['trail']))
            . '</span>';
        echo '<i class="bi bi-chevron-expand" aria-hidden="true"></i>';
        echo '</button>';

        echo '<div class="osc-catpick-pop" hidden>';
        echo '<input type="text" class="form-control osc-catpick-search" autocomplete="off"'
            . ' placeholder="' . osc_esc_html(__('Search categories')) . '"'
            . ' aria-label="' . osc_esc_html(__('Search categories')) . '" />';
        echo '<ul class="osc-catpick-list" id="' . osc_esc_html($listId) . '" role="listbox"'
            . ' aria-label="' . osc_esc_html($label) . '">';
        foreach ($options as $optValue => $option) {
            echo '<li class="osc-catpick-option" role="option" id="' . osc_esc_html($pickId . '-' . $optValue) . '"'
                . ' data-value="' . osc_esc_html((string)$optValue) . '"'
                . ' data-path="' . osc_esc_html($option['path']) . '"'
                . ' style="--osc-catpick-depth: ' . (int)$option['depth'] . '"'
                . ' aria-selected="' . ((string)$optValue === $value ? 'true' : 'false') . '">'
                . '<span class="osc-catpick-name">' . osc_esc_html($option['name']) . '</span>'
                . ($option['parent'] === ''
                    ? ''
                    : '<span class="osc-catpick-under">' . osc_esc_html($option['parent']) . '</span>')
                . '</li>';
        }
        echo '</ul>';
        echo '<p class="osc-catpick-empty" hidden>' . osc_esc_html(__('No category matches that.')) . '</p>';
        echo '</div>';

        if ($error !== '') {
            echo '<p class="field-error" id="' . osc_esc_html($pickId . '-error') . '">'
                . osc_esc_html($error) . '</p>';
        }
        Field::help($opts);
        echo '</div>';
    }

    /**
     * Where a listing is: the country select, the region and city inputs with their hidden
     * ids, and the rest of the address. Body of osc_admin_location_picker().
     *
     * Every control keeps the id the shipped autocomplete binds to, so that script is
     * reused as it stands.
     *
     * @param array<string,mixed> $opts
     *
     * @return void
     */
    public static function location(array $opts = array())
    {
        $value     = (array)($opts['value'] ?? array());
        $names     = (array)($opts['names'] ?? array());
        $errors    = (array)($opts['errors'] ?? array());
        $countries = $opts['countries'] ?? null;
        if (!is_array($countries)) {
            $countries = osc_get_countries();
        }
        $detail = (string)($opts['detail'] ?? 'disclosure');

        $field = static function ($key, $label, array $extra = array()) use ($value, $names, $errors) {
            osc_admin_field(array(
                'type'   => 'text',
                'id'     => $key,
                'name'   => (string)($names[$key] ?? $key),
                'label'  => $label,
                'layout' => 'stacked',
                'value'  => (string)($value[$key] ?? ''),
                'error'  => (string)($errors[$key] ?? ''),
            ) + $extra);
        };
        $hidden = static function ($key) use ($value, $names) {
            echo '<input type="hidden" id="' . osc_esc_html($key) . '"'
                . ' name="' . osc_esc_html((string)($names[$key] ?? $key)) . '"'
                . ' value="' . osc_esc_html((string)($value[$key] ?? '')) . '" />';
        };

        if ($countries !== array()) {
            $options = array();
            foreach ($countries as $country) {
                $options[$country['pk_c_code']] = $country['s_name'];
            }
            osc_admin_field(array(
                'type'        => 'select',
                'id'          => 'countryId',
                'name'        => (string)($names['countryId'] ?? 'countryId'),
                'label'       => (string)($opts['label_country'] ?? __('Country')),
                'layout'      => 'stacked',
                'options'     => $options,
                'value'       => (string)($value['countryId'] ?? ''),
                'placeholder' => __('Select a country...'),
                'error'       => (string)($errors['countryId'] ?? ''),
            ));
        } else {
            $field('country', (string)($opts['label_country'] ?? __('Country')));
        }

        $field('region', (string)($opts['label_region'] ?? __('Region')), array('attrs' => array('autocomplete' => 'off')));
        $hidden('regionId');
        $field('city', (string)($opts['label_city'] ?? __('City')), array('attrs' => array('autocomplete' => 'off')));
        $hidden('cityId');

        if ($detail === 'none') {
            return;
        }

        if ($detail === 'disclosure') {
            osc_admin_disclosure_open(
                (string)($opts['detail_title'] ?? __('More address detail')),
                array(
                    'summary_hint' => (string)($opts['detail_hint'] ?? __('City area, ZIP, street')),
                    'open'         => !empty(array_filter(array(
                        (string)($value['cityArea'] ?? ''),
                        (string)($value['zip'] ?? ''),
                        (string)($value['address'] ?? ''),
                    ))),
                )
            );
        }

        $field('cityArea', (string)($opts['label_city_area'] ?? __('City area')));
        $field('zip', (string)($opts['label_zip'] ?? __('ZIP code')));
        $field('address', (string)($opts['label_address'] ?? __('Street address')));

        if ($detail === 'disclosure') {
            osc_admin_disclosure_close();
        }
    }

    /**
     * Who a record belongs to: the card when a registered user matches, and the search
     * that fills the contact fields when one is picked. Body of osc_admin_user_picker().
     *
     * @param array<string,mixed> $opts
     *
     * @return void
     */
    public static function user(array $opts = array())
    {
        $user   = is_array($opts['user'] ?? null) ? $opts['user'] : null;
        $id     = (string)($opts['id'] ?? 'userPicker');
        $fields = (array)($opts['fields'] ?? array());

        echo '<div class="osc-user-picker" data-osc-user-picker>';

        if ($user !== null) {
            echo '<div class="osc-user-card">';
            echo '<i class="bi bi-person-circle" aria-hidden="true"></i>';
            echo '<div class="osc-user-card-body">';
            echo '<span class="osc-user-card-name">' . osc_esc_html((string)($user['name'] ?? '')) . '</span>';
            echo '<span class="osc-user-card-mail">' . osc_esc_html((string)($user['email'] ?? '')) . '</span>';
            echo '<span class="osc-user-card-meta">' . osc_esc_html(__('Registered user'));
            if (!empty($user['url'])) {
                echo '<a href="' . osc_esc_html((string)$user['url']) . '">' . osc_esc_html(__('View profile')) . '</a>';
            }
            echo '</span></div></div>';
        }

        // No name: the search is how a user is found, not something the form posts.
        osc_admin_field(array(
            'type'        => 'text',
            'id'          => $id,
            'name'        => '',
            'label'       => (string)($opts['label'] ?? __('Find a registered user')),
            'layout'      => 'stacked',
            'class'       => 'osc-user-search',
            'placeholder' => (string)($opts['placeholder'] ?? __('Search by name or e-mail')),
            'help'        => (string)($opts['help'] ?? ''),
            'attrs'       => array(
                'autocomplete'          => 'off',
                'data-osc-user-search'  => '1',
                'data-osc-user-source'  => (string)($opts['source'] ?? ''),
                'data-osc-user-fields'  => (string)json_encode($fields),
            ),
        ));

        echo '</div>';
    }

    /**
     * The category tree flattened into the order it is shown in: every category once, with
     * its depth and the path that says where it sits.
     *
     * @param array<int,array<string,mixed>> $rows Category rows carrying pk_i_id, fk_i_parent_id, s_name
     *
     * @return array<string,array{name:string,parent:string,path:string,depth:int}>
     */
    public static function categoryOptions(array $rows)
    {
        $children = array();
        foreach ($rows as $row) {
            $parent              = (string)($row['fk_i_parent_id'] ?? '');
            $parent              = $parent === '' ? '0' : $parent;
            $children[$parent][] = $row;
        }

        $options = array();
        $walk    = static function ($parent, $trail, $depth) use (&$walk, $children, &$options) {
            foreach ($children[(string)$parent] ?? array() as $row) {
                $key           = (string)$row['pk_i_id'];
                $name          = (string)($row['s_name'] ?? '');
                $options[$key] = array(
                    'name'   => $name,
                    'parent' => implode(' › ', $trail),
                    'path'   => implode(' › ', array_merge($trail, array($name))),
                    'trail'  => array_merge($trail, array($name)),
                    'depth'  => $depth,
                );
                $walk($key, array_merge($trail, array($name)), $depth + 1);
            }
        };
        $walk('0', array(), 0);

        return $options;
    }

    /**
     * A category path as the button shows it, each step separated by its own element so the
     * separators can be styled apart from the words.
     *
     * @param array<int,string> $trail
     *
     * @return string
     */
    private static function categoryPathHtml(array $trail)
    {
        return implode(
            '<span class="osc-catpick-sep" aria-hidden="true">›</span>',
            array_map('osc_esc_html', $trail)
        );
    }
}

/* file end: ./oc-includes/osclass/classes/admin/ui/Picker.php */
