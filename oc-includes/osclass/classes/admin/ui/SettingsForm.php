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

use mindstellar\settings\SettingsImage;

/**
 * The form of a declared page: the <form>, its route, every declared field, and the
 * submit row. Body of osc_admin_settings_form().
 *
 * One renderer for every declared page, whichever controller drives it. A screen with
 * chrome of its own -- a section, a title that changes between add and edit, a wrapper
 * the theme styles -- keeps that chrome in its view and calls this for the form, so the
 * two front doors cannot drift into rendering a declared page two different ways.
 *
 * The route is the caller's, because only the caller knows which action it posts to and
 * which row it is editing. Nothing here reads the request.
 *
 * @package mindstellar\admin\ui
 */
final class SettingsForm
{
    /**
     * Draw a declared page's form: route, every field of every group, and the submit row.
     *
     * @param array<string,mixed> $page   normalised page spec
     * @param array<string,mixed> $values values to show, keyed by field name
     * @param array<string,mixed> $opts   'route'   => hidden fields, 'page' and 'action' among
     *                                                 them; defaults to the generic settings
     *                                                 controller's;
     *                                    'actions' => the submit row, as
     *                                                 osc_admin_form_actions() takes it;
     *                                    'name'    => the form's name attribute;
     *                                    'url'     => the form's action attribute;
     *                                    'upload'  => true to post multipart/form-data,
     *                                                 for a page drawing a file control; a
     *                                                 page with an image field always does
     *
     * @return void
     */
    public static function render(array $page, array $values, array $opts = array())
    {
        if (!isset($page['id'], $page['groups'])) {
            // A page that is not registered has no fields to draw and no route of its own;
            // an empty form would post to whatever the request already said.
            return;
        }

        $route = isset($opts['route']) && is_array($opts['route'])
            ? $opts['route']
            : array('page' => 'settings', 'action' => 'custom_post', 'id' => $page['id']);

        osc_admin_form_open(array(
            'url'    => $opts['url'] ?? null,
            'name'   => $opts['name'] ?? null,
            'upload' => !empty($opts['upload']) || self::hasImage($page),
            'page'   => $route['page'] ?? null,
            'action' => $route['action'] ?? null,
            'fields' => array_diff_key($route, array('page' => true, 'action' => true)),
        ));

        foreach ($page['groups'] as $index => $group) {
            if ($group['title'] !== '') {
                osc_admin_page_head($group['title']);
            }
            if ($group['intro'] !== '') {
                echo '<p class="form-intro">' . osc_esc_html($group['intro']) . '</p>';
            }

            foreach ($group['fields'] as $field) {
                self::field($field, $page['id'], $values);
            }

            // A schema that cannot express something must not force a plugin back to raw
            // HTML for the whole page: this appends to a declared group without owning the
            // page it sits on.
            osc_run_hook('settings_page_after_group', $page['id'], $group, $index);
        }

        // Every declared page gets the dirty-tracking action row; a hand-written screen
        // opts in by passing 'dirty' to osc_admin_form_close().
        osc_admin_form_close($opts['actions'] ?? array(), array('dirty' => true));
    }

    /**
     * Whether any field on the page takes a file.
     *
     * @param array<string,mixed> $page normalised page spec
     *
     * @return bool
     */
    private static function hasImage(array $page)
    {
        foreach ($page['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if (($field['type'] ?? '') === 'image') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * One declared field, carrying the value it should show.
     *
     * @param array<string,mixed> $field
     * @param string              $pageId
     * @param array<string,mixed> $values
     *
     * @return void
     */
    private static function field(array $field, $pageId, array $values)
    {
        $name = $field['name'];
        if ($field['type'] === 'custom') {
            // What a custom field draws is often conditional on the rest of the form, so it
            // gets the same values every declared control is drawn from -- including the
            // submission a rejected save is handing back.
            $field['values'] = $values;
        } elseif ($field['type'] === 'image') {
            $field['value']       = $values[$name] ?? '';
            $field['preview_url'] = SettingsImage::url($field['value'], 'thumbnail');
            $field['remove_name'] = SettingsImage::removeName($name);
        } elseif ($field['type'] === 'checkbox') {
            $field['row_label'] = $field['row_label'] ?? '';
            $field['checked']   = !empty($values[$name]);
        } else {
            $field['value'] = $values[$name] ?? '';
            // Resolved here, not inside the field: drawing a control must not query
            // anything, and render and store have to expand a translated field over the
            // same list.
            $locales = osc_settings_field_locales($field);
            if ($locales !== array()) {
                $field['locales'] = $locales;
            }
        }

        // A filter over the field spec: a listener can add a hint, retitle a field or mark
        // it readonly. It cannot suppress the control or put a different one in its place
        // -- the field below renders regardless.
        $filtered = osc_apply_filter('admin_form_render_field', $field, $pageId, $values);

        osc_admin_field(is_array($filtered) ? $filtered : $field);
    }
}
