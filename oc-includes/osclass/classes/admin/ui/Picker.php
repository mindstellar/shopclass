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
            $rows = \Category::getInstance()->listEnabled();
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
     * @return array<string,array{name:string,parent:string,path:string,depth:int,trail:array<int,string>}>
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
     * A category checkbox tree drawn as one card per main category. Body of
     * osc_admin_tree_picker().
     *
     * The posted name (categories[]), the root id, the ul#cat<id> branches checkCat() ticks
     * and the "Check all / Uncheck all" links are the ones the tree has always had, so
     * screens and plugins that read or script it keep working.
     *
     * @param array<string,mixed> $opts
     *
     * @return void
     */
    public static function categoryChecks(array $opts)
    {
        $id       = (string)($opts['id'] ?? '');
        $name     = (string)($opts['name'] ?? 'categories[]');
        $selected = array_map('intval', (array)($opts['selected'] ?? array()));
        $tree     = is_array($opts['categories'] ?? null) ? $opts['categories'] : array();

        echo '<div class="form-row osc-catcards-row"'
            . (!empty($opts['wrapper_id']) ? ' id="' . osc_esc_html((string)$opts['wrapper_id']) . '"' : '') . '>';
        if (($opts['intro'] ?? '') !== '') {
            echo '<div class="form-label" id="' . osc_esc_html($id . '-intro') . '">' . osc_esc_html((string)$opts['intro']) . '</div>';
        }
        echo '<div class="form-controls">';
        echo '<div class="osc-catcards-bar">'
            . '<span class="osc-catcards-summary" data-osc-catcards-summary="' . osc_esc_html($id) . '" aria-live="polite"></span>'
            . '<span class="osc-catcards-actions">'
            . '<button type="button" class="osc-catcards-action" data-tree-toggle="' . osc_esc_html($id) . '" data-tree-check="1">'
            . osc_esc_html(__('Check all')) . '</button>'
            . '<button type="button" class="osc-catcards-action" data-tree-toggle="' . osc_esc_html($id) . '" data-tree-check="0">'
            . osc_esc_html(__('Uncheck all')) . '</button>'
            . '</span></div>';
        echo '<ul id="' . osc_esc_html($id) . '" class="osc-catcards" data-osc-catcards="checks"'
            . (($opts['intro'] ?? '') !== '' ? ' aria-labelledby="' . osc_esc_html($id . '-intro') . '"' : '')
            . ' data-toggle-label="' . osc_esc_html(__('Subcategories of %s')) . '"'
            . ' data-label-count="' . osc_esc_html(__('%1$s of %2$s selected')) . '"'
            . ' data-label-selected="' . osc_esc_html(__('%s selected')) . '">';
        self::categoryCheckItems($tree, $id, $name, $selected, 0);
        echo '</ul>';
        if ($tree === array()) {
            echo '<p class="osc-catcards-intro">' . osc_esc_html(__('There are no categories yet.')) . '</p>';
        }
        echo '</div></div>';
    }

    /**
     * One level of categoryChecks(): a checkbox and its label per category, and the
     * category's children in a ul#cat<id> below it.
     *
     * @param array<int,array<string,mixed>> $nodes
     * @param string                         $id
     * @param string                         $name
     * @param int[]                          $selected
     * @param int                            $depth
     *
     * @return void
     */
    private static function categoryCheckItems(array $nodes, $id, $name, array $selected, $depth)
    {
        foreach ($nodes as $node) {
            $catId    = (int)$node['pk_i_id'];
            $children = self::childrenOf($node);
            $boxId    = $id . '-cat-' . $catId;

            echo '<li' . ($depth === 0 ? ' class="osc-catcard"' : '') . '>'
                . '<input type="checkbox" id="' . osc_esc_html($boxId) . '" name="' . osc_esc_html($name) . '"'
                . ' value="' . $catId . '" onclick="checkCat(\'' . $catId . '\', this.checked);"'
                . (in_array($catId, $selected, true) ? ' checked' : '') . ' />'
                . '<label for="' . osc_esc_html($boxId) . '">' . osc_esc_html((string)($node['s_name'] ?? '')) . '</label>';
            if ($depth === 0 && $children !== array()) {
                echo '<span class="osc-catcard-meta" data-osc-catcard-meta>' . osc_esc_html(self::subcategoryCount($children)) . '</span>';
            }
            if ($children !== array()) {
                echo '<ul id="cat' . $catId . '">';
                self::categoryCheckItems($children, $id, $name, $selected, $depth + 1);
                echo '</ul>';
            }
            echo '</li>';
        }
    }

    /**
     * A mode per category, drawn like categoryChecks(): a switch on each main category and
     * a select on each subcategory, which follows its parent until it is set apart. Body of
     * the category_modes settings field.
     *
     * Posts $name[<category id>] = mode; an empty value means "off" on a main category and
     * "same as parent" on a subcategory.
     *
     * @param array<string,mixed> $opts 'id', 'name', 'modes' (value => label), 'value' (the
     *                                  stored JSON or an id => mode array), 'categories'
     *                                  (defaults to the whole tree)
     *
     * @return void
     */
    public static function categoryModes(array $opts)
    {
        $id    = (string)($opts['id'] ?? '');
        $name  = (string)($opts['name'] ?? '');
        $modes = array_map('strval', (array)($opts['modes'] ?? array()));
        $saved = \mindstellar\category\CategoryModes::parse($opts['value'] ?? array());
        $tree  = $opts['categories'] ?? \Category::getInstance()->toTree(true);

        if (!is_array($tree) || $tree === array()) {
            echo '<p class="osc-catcards-intro">' . osc_esc_html(__('There are no categories yet.')) . '</p>';

            return;
        }

        echo '<div class="osc-catcards-bar"><span class="osc-catcards-summary" data-osc-catcards-summary="'
            . osc_esc_html($id) . '"></span></div>';
        echo '<ul id="' . osc_esc_html($id) . '" class="osc-catcards" data-osc-catcards="modes"'
            . ' data-toggle-label="' . osc_esc_html(__('Subcategories of %s')) . '"'
            . ' data-label-off="' . osc_esc_html(__('Off in every category')) . '"'
            . ' data-label-on="' . osc_esc_html(__('On in: %s')) . '"'
            . ' data-label-item="' . osc_esc_html(__('%1$s (%2$s)')) . '"'
            . ' data-label-path="' . osc_esc_html(__('%1$s › %2$s')) . '"'
            . ' data-label-apart="' . osc_esc_html(__('%s set differently')) . '"'
            . ' data-label-same="' . osc_esc_html(__('all use this setting')) . '"'
            . ' data-label-inherit="' . osc_esc_html(__('Same as parent (%s)')) . '">';

        $off = array('' => __('Off')) + $modes;
        foreach ($tree as $node) {
            $catId    = (int)$node['pk_i_id'];
            $children = self::childrenOf($node);
            $current  = isset($modes[$saved[$catId] ?? '']) ? $saved[$catId] : '';
            $labelId  = $id . '-cat-' . $catId;
            $setApart = $children !== array() ? self::countSetApart($children, $saved) : 0;

            echo '<li class="osc-catcard"' . ($setApart > 0 ? ' data-osc-tree-open' : '') . '>'
                . '<span class="osc-catcard-name" id="' . osc_esc_html($labelId) . '">'
                . osc_esc_html((string)($node['s_name'] ?? '')) . '</span>';
            if ($children !== array()) {
                $count = self::subcategoryCount($children);
                echo '<span class="osc-catcard-meta" data-osc-catcard-meta data-base="' . osc_esc_html($count) . '">'
                    . osc_esc_html($count . ' · ' . ($setApart > 0
                        ? sprintf(__('%s set differently'), $setApart)
                        : __('all use this setting'))) . '</span>';
            }
            echo '<fieldset class="osc-segmented" aria-labelledby="' . osc_esc_html($labelId) . '">';
            $i = 0;
            foreach ($off as $value => $label) {
                $optId = $labelId . '-' . $i++;
                echo '<input type="radio" id="' . osc_esc_html($optId) . '" name="' . osc_esc_html($name . '[' . $catId . ']') . '"'
                    . ' value="' . osc_esc_html((string)$value) . '"' . ((string)$value === $current ? ' checked' : '') . ' />'
                    . '<label for="' . osc_esc_html($optId) . '">' . osc_esc_html((string)$label) . '</label>';
            }
            echo '</fieldset>';
            if ($children !== array()) {
                echo '<ul>';
                self::categoryModeItems($children, $id, $name, $modes, $saved);
                echo '</ul>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    /**
     * The subcategory rows of categoryModes(), nested like the tree.
     *
     * @param array<int,array<string,mixed>> $nodes
     * @param string                         $id
     * @param string                         $name
     * @param array<string,string>           $modes
     * @param array<int,string>              $saved
     *
     * @return void
     */
    private static function categoryModeItems(array $nodes, $id, $name, array $modes, array $saved)
    {
        $choices = array('' => __('Same as parent'), 'off' => __('Off')) + $modes;
        foreach ($nodes as $node) {
            $catId    = (int)$node['pk_i_id'];
            $children = self::childrenOf($node);
            $current  = $saved[$catId] ?? '';
            $selectId = $id . '-cat-' . $catId;

            echo '<li' . ($children !== array() && self::countSetApart($children, $saved) > 0 ? ' data-osc-tree-open' : '') . '>'
                . '<span class="osc-catcard-row"><label for="' . osc_esc_html($selectId) . '">'
                . osc_esc_html((string)($node['s_name'] ?? '')) . '</label>'
                . '<select id="' . osc_esc_html($selectId) . '" name="' . osc_esc_html($name . '[' . $catId . ']') . '"'
                . ' class="field-select">';
            foreach ($choices as $value => $label) {
                echo '<option value="' . osc_esc_html((string)$value) . '"' . ((string)$value === $current ? ' selected' : '') . '>'
                    . osc_esc_html((string)$label) . '</option>';
            }
            echo '</select></span>';
            if ($children !== array()) {
                echo '<ul>';
                self::categoryModeItems($children, $id, $name, $modes, $saved);
                echo '</ul>';
            }
            echo '</li>';
        }
    }

    /**
     * A tree node's children.
     *
     * @param array<string,mixed> $node
     *
     * @return array<int,array<string,mixed>>
     */
    private static function childrenOf(array $node)
    {
        return !empty($node['categories']) && is_array($node['categories']) ? $node['categories'] : array();
    }

    /**
     * Every category id at every level under these nodes.
     *
     * @param array<int,array<string,mixed>> $nodes
     *
     * @return int[]
     */
    private static function descendantIds(array $nodes)
    {
        $ids = array();
        foreach ($nodes as $node) {
            $ids[] = (int)$node['pk_i_id'];
            $ids   = array_merge($ids, self::descendantIds(self::childrenOf($node)));
        }

        return $ids;
    }

    /**
     * "N subcategories", counting every level below a main category.
     *
     * @param array<int,array<string,mixed>> $children
     *
     * @return string
     */
    private static function subcategoryCount(array $children)
    {
        $count = count(self::descendantIds($children));

        return sprintf(_n('%d subcategory', '%d subcategories', $count), $count);
    }

    /**
     * How many categories under these nodes have a mode of their own.
     *
     * @param array<int,array<string,mixed>> $nodes
     * @param array<int,string>              $saved
     *
     * @return int
     */
    private static function countSetApart(array $nodes, array $saved)
    {
        return count(array_intersect_key(array_flip(self::descendantIds($nodes)), $saved));
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
