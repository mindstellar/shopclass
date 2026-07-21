<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\widgets\WidgetRegistry;

/**
 * Register a widget type so it can be placed from the admin appearance screen
 * and rendered on the front end. Thin wrapper over WidgetRegistry::register().
 *
 * See WidgetRegistry::register() for the $spec shape.
 *
 * @param string $id   Namespaced slug, [a-z0-9_.-]{1,60}.
 * @param array  $spec Type specification.
 *
 * @return void
 */
function osc_register_widget($id, $spec)
{
    WidgetRegistry::instance()->register($id, $spec);
}


/**
 * All registered widget types, keyed by id.
 *
 * @return array
 */
function osc_widget_types()
{
    return WidgetRegistry::instance()->all();
}


/**
 * Render a single widget row.
 *
 * Dispatcher used by osc_show_widgets() / osc_show_widgets_by_description() and
 * by admin previews. A typed row (non-empty s_type) is rendered by its
 * registered type's callable; an unknown type (its plugin deactivated) is
 * skipped silently — no output, no fatal. A row with no s_type follows the
 * legacy path and echoes s_content unchanged.
 *
 * @param array $widgetRow A t_widget row.
 *
 * @return void
 */
function osc_render_widget($widgetRow)
{
    if (!empty($widgetRow['s_type'])) {
        $type = WidgetRegistry::instance()->get($widgetRow['s_type']);
        if ($type === null) {
            // Registering plugin/theme is not active — render nothing.
            return;
        }

        $config = array();
        if (isset($widgetRow['s_config']) && $widgetRow['s_config'] !== '') {
            $decoded = json_decode($widgetRow['s_config'], true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }

        $out = ($type['render'])($config, $widgetRow);
        if (is_string($out)) {
            echo $out;
        }

        return;
    }

    // Legacy stored-content path — byte-identical to the historical behaviour.
    echo $widgetRow['s_content'];
}


/*
 * Core "Custom Code" widget type — the sanctioned raw HTML/JavaScript escape
 * hatch. Registered unconditionally so the type always exists; who may author
 * it is enforced per-user by its 'super_admin' capability (the type picker and
 * the save path both reject it for a moderator). Its single 'code' field is
 * stored unfiltered (bypassing HTMLPurifier) only because the type is
 * super_admin-gated — see CAdminAppearance::buildWidgetConfig(). Render echoes
 * the stored code verbatim onto the public page.
 */
osc_register_widget('core.custom_code', array(
    'capability'  => 'super_admin',
    'label'       => 'Custom Code (HTML / JavaScript)',
    'description' => 'Runs unfiltered HTML and JavaScript on your public pages. '
        . 'Anyone who can edit this widget can take over the site.',
    'fields'      => array(
        array(
            'name'  => 'code',
            'label' => 'Code',
            'type'  => 'code',
        ),
    ),
    'render'      => static function ($config) {
        echo $config['code'] ?? '';
    },
));
