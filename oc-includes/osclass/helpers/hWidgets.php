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
 * The widget sections the active theme offers, in the order it offers them:
 * slug => array('label' => string, 'description' => string).
 *
 * Resolution, first hit wins:
 *   1. osc_add_theme_support('widget_locations', array('header' => array('label' => …)))
 *      -- labelled, described, ordered, and declarable conditionally.
 *   2. the `Widgets:` line in the theme's index.php, each slug standing in as its
 *      own label. This is what every theme written before the declaration existed
 *      does, and it keeps behaving exactly as it did.
 *   3. neither -- no sections.
 *
 * A plugin adds one through the `widget_locations` filter, which sees the merged
 * map. The admin uses this to build its drop zones *and* to refuse a forged
 * section on a move, so anything added here becomes placeable.
 *
 * @return array<string,array{label:string,description:string}>
 */
function osc_widget_locations()
{
    $locations = _osc_widget_locations_normalize(osc_theme_supports('widget_locations'));

    if ($locations === array()) {
        $info   = WebThemes::newInstance()->loadThemeInfo(osc_theme());
        $header = (is_array($info) && isset($info['locations']) && is_array($info['locations']))
            ? $info['locations']
            : array();
        $locations = _osc_widget_locations_normalize($header);
    }

    $filtered = osc_apply_filter('widget_locations', $locations);

    return is_array($filtered) ? _osc_widget_locations_normalize($filtered) : $locations;
}

/**
 * Coerce any of the shapes a location map arrives in into slug => spec.
 *
 * Accepts a list of slugs (the `Widgets:` header), a map of slug => spec, and a
 * map of slug => label. A slug that is not [a-zA-Z0-9_.-]{1,60} is dropped: it
 * reaches an HTML attribute and a database column, and no theme has ever used
 * anything else.
 *
 * @param mixed $raw
 *
 * @return array<string,array{label:string,description:string}>
 */
function _osc_widget_locations_normalize($raw)
{
    if (!is_array($raw)) {
        return array();
    }

    $locations = array();
    foreach ($raw as $slug => $spec) {
        if (is_int($slug)) {
            // A bare list: array('header', 'footer').
            $slug = $spec;
            $spec = array();
        }
        if (is_string($spec)) {
            $spec = array('label' => $spec);
        }
        if (!is_string($slug) || !is_array($spec)) {
            continue;
        }
        $slug = trim($slug);
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,60}$/', $slug)) {
            continue;
        }

        $label = (isset($spec['label']) && is_string($spec['label']) && trim($spec['label']) !== '')
            ? trim($spec['label'])
            : $slug;
        $description = (isset($spec['description']) && is_string($spec['description']))
            ? trim($spec['description'])
            : '';

        $locations[$slug] = array('label' => $label, 'description' => $description);
    }

    return $locations;
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
 * Core "Rich text" widget — the default, safe text block, registered first so it
 * is the picker's default choice. Registered unconditionally so the page builder
 * always has a usable block type without any plugin. Its single field is a
 * textarea whose value is HTML-purified on save (the default per-field path, not
 * the raw 'code' path), so it is safe for any admin — including moderators — to
 * author. Render runs the stored text through osc_autop so blank lines become
 * paragraphs and single newlines become line breaks, exactly as a static page's
 * own text renders. This is the block that lets classic pages and builder pages
 * share one formatting path.
 */
osc_register_widget('core.rich_text', array(
    'label'       => 'Rich text',
    'description' => 'A block of formatted text. Blank lines become paragraphs on the page.',
    'fields'      => array(
        array(
            'name'  => 'text',
            'label' => 'Text',
            'type'  => 'textarea',
        ),
    ),
    'render'      => static function ($config) {
        echo osc_autop($config['text'] ?? '');
    },
));

/*
 * Core "Image" widget — an image block, chosen from the media library and
 * optionally linked. Registered unconditionally so the page builder can place
 * images out of the box. The 'image' and 'link' values are stored as validated
 * http(s) URLs (see buildWidgetConfig); alt text is purified. Safe for any admin.
 */
osc_register_widget('core.image', array(
    'label'       => 'Image',
    'description' => 'An image from your media library, optionally linking somewhere.',
    'fields'      => array(
        array('name' => 'image', 'label' => 'Image', 'type' => 'image'),
        array('name' => 'alt', 'label' => 'Alt text', 'type' => 'text'),
        array('name' => 'link', 'label' => 'Link URL (optional)', 'type' => 'text'),
    ),
    'render'      => static function ($config) {
        $src = (string) ($config['image'] ?? '');
        if ($src === '') {
            return;
        }
        $img  = '<img src="' . osc_esc_html($src) . '" alt="' . osc_esc_html((string) ($config['alt'] ?? ''))
            . '" style="max-width:100%;height:auto"/>';
        $link = (string) ($config['link'] ?? '');
        if ($link !== '' && preg_match('#^https?://#i', $link)) {
            echo '<a href="' . osc_esc_html($link) . '">' . $img . '</a>';
        } else {
            echo $img;
        }
    },
));

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
    // Its own group so the palette can sort the raw-markup escape hatch last.
    'group'       => 'Legacy',
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
