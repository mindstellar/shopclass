<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\widgets\WidgetConfig;
use mindstellar\widgets\WidgetRegistry;

/**
 * Register a widget type so it can be placed from the admin appearance screen
 * and rendered on the front end. Thin wrapper over WidgetRegistry::register().
 *
 * See WidgetRegistry::register() for the $spec shape.
 *
 * @param string              $id   Namespaced slug, [a-z0-9_.-]{1,60}.
 * @param array<string,mixed> $spec Type specification.
 *
 * @return void
 */
function osc_register_widget($id, $spec)
{
    WidgetRegistry::getInstance()->register($id, $spec);
}

/**
 * All registered widget types, keyed by id.
 *
 * @return array<string,array<string,mixed>>
 */
function osc_widget_types()
{
    return WidgetRegistry::getInstance()->all();
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
 *      does, and it keeps behaving exactly as it did. A child theme with no such
 *      line uses its parent's.
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

    // A child theme with no `Widgets:` line offers its parent's sections.
    $theme = $locations === array() ? osc_theme() : '';
    while ($theme !== '') {
        $themes = WebThemes::getInstance();
        $info   = $themes->loadThemeInfo($theme);
        $header = (is_array($info) && isset($info['locations']) && is_array($info['locations']))
            ? $info['locations']
            : array();
        $locations = _osc_widget_locations_normalize($header);
        $theme     = $locations === array() && $theme === osc_theme() ? $themes->parentOf($theme) : '';
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
 * skipped silently — no output, no fatal, and so is a row whose config holds
 * `_enabled => false` (hidden, not deleted). A row with no s_type follows the
 * legacy path and echoes s_content unchanged.
 *
 * @param array<string,mixed> $widgetRow A t_widget row.
 *
 * @return void
 */
function osc_render_widget($widgetRow)
{
    if (!empty($widgetRow['s_type'])) {
        $type = WidgetRegistry::getInstance()->get($widgetRow['s_type']);
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
        if (!WidgetConfig::enabled($config)) {
            return;
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

/**
 * What the active theme lets the owner customise, checked; null when it declares nothing usable.
 *
 * Read from osc_add_theme_support('customiser', array(...)):
 *
 *   'locations' => array('home' => array('label' => …, 'types' => array(…), 'preview' => url)),
 *   'settings'  => array('page' => settings page id, 'fields' => array(field names)),
 *   'swatches'  => array(field => array(option => '#rrggbb')),
 *   'live'      => array(field => 'data-…'),
 *
 * A location must be a declared widget location, a type a registered widget type, a field a
 * field of that settings page (not secret, custom, category_modes, richtext or translated),
 * a swatch a six-digit hex, a preview URL one on this site. Anything else is dropped.
 *
 * @return array{locations:array<string,array{label:string,types:string[],preview:string}>,settings:array{page:string,fields:string[]},swatches:array<string,array<string,string>>,live:array<string,string>}|null
 */
function osc_customiser_spec(): ?array
{
    $raw = osc_theme_supports('customiser');
    if (!is_array($raw)) {
        return null;
    }

    $declared  = osc_widget_locations();
    $types     = osc_widget_types();
    $base      = osc_base_url();
    $locations = array();
    foreach ((array) ($raw['locations'] ?? array()) as $slug => $loc) {
        if (!is_string($slug) || !isset($declared[$slug]) || !is_array($loc)) {
            continue;
        }
        $allowed = array();
        foreach ((array) ($loc['types'] ?? array()) as $type) {
            if (is_string($type) && isset($types[$type]) && !in_array($type, $allowed, true)) {
                $allowed[] = $type;
            }
        }
        if ($allowed === array()) {
            continue;
        }
        $preview = is_string($loc['preview'] ?? null) ? $loc['preview'] : '';
        if ($preview === '' || strpos($preview, $base) !== 0) {
            $preview = $base;
        }
        $locations[$slug] = array(
            'label'   => is_string($loc['label'] ?? null) && trim($loc['label']) !== '' ? trim($loc['label']) : $declared[$slug]['label'],
            'types'   => $allowed,
            'preview' => $preview,
        );
    }

    $page   = is_string($raw['settings']['page'] ?? null) ? $raw['settings']['page'] : '';
    $fields = array();
    if ($page !== '' && osc_settings_page($page) !== null) {
        $declaredFields = \mindstellar\settings\SettingsPageRegistry::getInstance()->fields($page);
        foreach ((array) ($raw['settings']['fields'] ?? array()) as $name) {
            $field = is_string($name) ? ($declaredFields[$name] ?? null) : null;
            if ($field === null || in_array($field['type'], array('secret', 'custom', 'category_modes', 'richtext'), true)
                || osc_settings_field_locales($field) !== array() || in_array($name, $fields, true)
            ) {
                continue;
            }
            $fields[] = $name;
        }
    }

    $swatches = array();
    foreach ((array) ($raw['swatches'] ?? array()) as $name => $map) {
        if (!in_array($name, $fields, true) || !is_array($map)) {
            continue;
        }
        foreach ($map as $option => $hex) {
            $hex = is_string($hex) ? strtolower($hex) : '';
            if (preg_match('/^#[0-9a-f]{6}$/D', $hex)) {
                $swatches[$name][(string) $option] = $hex;
            }
        }
    }

    $live = array();
    foreach ((array) ($raw['live'] ?? array()) as $name => $attr) {
        if (in_array($name, $fields, true) && is_string($attr) && preg_match('/^data-[a-z0-9-]{1,40}$/D', $attr)) {
            $live[$name] = $attr;
        }
    }

    if ($locations === array() && $fields === array()) {
        return null;
    }

    return array(
        'locations' => $locations,
        'settings'  => array('page' => $page, 'fields' => $fields),
        'swatches'  => $swatches,
        'live'      => $live,
    );
}

/**
 * The draft this request previews, or null. A public page previews only with ?osc_preview=1
 * and a signed-in admin, never in the admin or the API. With no draft saved for the theme being
 * shown it previews an empty draft, so the published page still answers the customiser.
 * Read once per request.
 *
 * @return array<string,mixed>|null
 */
function osc_customiser_preview_draft(): ?array
{
    if (array_key_exists('osc_customiser_preview', $GLOBALS)) {
        return $GLOBALS['osc_customiser_preview'];
    }
    $draft = null;
    if ((!defined('OC_ADMIN') || OC_ADMIN !== true)
        && Params::getParamString('osc_preview') === '1'
        && !osc_is_api_request()
        && osc_logged_admin_id() > 0
        && osc_is_admin_user_logged_in()
    ) {
        $theme = osc_current_web_theme();
        $draft = (new \mindstellar\widgets\CustomiserDraft($theme, osc_logged_admin_id()))->read()
            ?? array('theme' => $theme, 'locations' => array(), 'settings' => array(), 'updated_at' => 0);
    }

    return $GLOBALS['osc_customiser_preview'] = $draft;
}

/**
 * Whether this request renders the signed-in admin's customiser draft.
 *
 * @return bool
 */
function osc_customiser_preview(): bool
{
    return osc_customiser_preview_draft() !== null;
}

/**
 * The drafted value of one look setting while previewing, or null outside a preview or when
 * that setting is not drafted.
 *
 * @param string $page settings page id the theme declared
 * @param string $name field name
 *
 * @return mixed
 */
function osc_customiser_draft_value(string $page, string $name)
{
    $draft = osc_customiser_preview_draft();
    if ($draft === null) {
        return null;
    }
    $raw = osc_theme_supports('customiser');
    if (!is_array($raw) || ($raw['settings']['page'] ?? null) !== $page
        || !in_array($name, (array) ($raw['settings']['fields'] ?? array()), true)
    ) {
        return null;
    }

    return array_key_exists($name, $draft['settings']) ? $draft['settings'][$name] : null;
}

/**
 * The preview address of a customisable location, with osc_preview=1, or '' when the theme
 * does not let that location be customised.
 *
 * @param string $location
 *
 * @return string
 */
function osc_customiser_preview_url(string $location = 'home'): string
{
    $spec = osc_customiser_spec();
    if ($spec === null || !isset($spec['locations'][$location])) {
        return '';
    }
    $url = $spec['locations'][$location]['preview'];

    return $url . (strpos($url, '?') === false ? '?' : '&') . 'osc_preview=1';
}

/**
 * The drafted rows of one location as t_widget rows, or null when it is not drafted or this
 * is not a preview. An unsaved row has pk_i_id 0; s_key is the id, or the draft key of an
 * unsaved row, for the theme's data-sec attribute.
 *
 * @param string $location
 *
 * @return array<int,array<string,string|null>>|null
 */
function osc_customiser_preview_rows(string $location): ?array
{
    $draft = osc_customiser_preview_draft();
    if ($draft === null || !is_array($draft['locations'][$location] ?? null)) {
        return null;
    }
    $spec  = osc_customiser_spec();
    $types = $spec['locations'][$location]['types'] ?? null;
    if ($types === null) {
        return null;
    }

    $rows = array();
    foreach (array_values($draft['locations'][$location]) as $i => $row) {
        if (!is_array($row) || !in_array($row['type'] ?? null, $types, true)) {
            continue;
        }
        $id     = (int) ($row['id'] ?? 0);
        $rows[] = array(
            'pk_i_id'       => (string) $id,
            's_key'         => $id > 0 ? (string) $id : (string) ($row['key'] ?? ''),
            's_description' => (string) ($row['description'] ?? ''),
            's_location'    => $location,
            'e_kind'        => 'html',
            's_content'     => '',
            'i_order'       => (string) $i,
            's_type'        => (string) $row['type'],
            's_config'      => (string) json_encode(is_array($row['config'] ?? null) ? $row['config'] : array()),
        );
    }

    return $rows;
}

/**
 * Headers a preview response carries, or none outside a preview.
 *
 * @return string[]
 */
function osc_customiser_preview_headers(): array
{
    return osc_customiser_preview() ? array('X-Robots-Tag: noindex') : array();
}

/**
 * On a preview request: keep the page out of every cache and out of search results.
 *
 * @return void
 */
function osc_customiser_preview_start(): void
{
    if (!osc_customiser_preview()) {
        return;
    }
    osc_mark_response_cacheable(false);
    osc_add_filter('response_is_cacheable', '_osc_customiser_not_cacheable');
    if (!headers_sent()) {
        foreach (osc_customiser_preview_headers() as $header) {
            header($header);
        }
    }
}

/**
 * The response_is_cacheable answer for a preview.
 *
 * @return bool
 */
function _osc_customiser_not_cacheable(): bool
{
    return false;
}

if (function_exists('osc_add_hook')) {
    osc_add_hook('init', 'osc_customiser_preview_start');
}

/**
 * A menu as links in the visitor's language: `[['label' => …, 'url' => …, 'children' => […]], …]`.
 * `main` and `footer` list the top categories and the linked pages until the owner builds them
 * in Appearance > Menus. Labels are plain text: escape them when printing.
 *
 * @param string $id `main`, `footer` or the id of a menu the owner made
 *
 * @return array<int,array<string,mixed>> empty for an unknown menu
 */
function osc_menu(string $id): array
{
    return \mindstellar\menu\MenuService::getInstance()->resolve($id, (string) osc_current_user_locale());
}

/**
 * The menus a theme or widget can show, id => name.
 *
 * @return array<string,string>
 */
function osc_menu_choices(): array
{
    $service = \mindstellar\menu\MenuService::getInstance();
    $places  = $service->themePlaces();
    $names   = array('main' => __('Main menu'), 'footer' => __('Footer menu'));
    foreach ($service->editableIds() as $id) {
        if (!isset($names[$id])) {
            $saved      = $service->load($id);
            $names[$id] = $saved !== null && $saved['label'] !== '' ? $saved['label'] : (isset($places[$id]) ? $places[$id]['label'] : $id);
        }
        if (isset($places[$id]) && $names[$id] !== $places[$id]['label']) {
            $names[$id] .= ' — ' . $places[$id]['label'];
        }
    }

    return $names;
}

/**
 * Menu links as nested lists.
 *
 * @param array<int,array<string,mixed>> $items from osc_menu()
 */
function _osc_menu_list(array $items): string
{
    $html = '<ul>';
    foreach ($items as $item) {
        $html .= '<li><a href="' . osc_esc_html((string) $item['url']) . '">' . osc_esc_html((string) $item['label']) . '</a>';
        if (!empty($item['children']) && is_array($item['children'])) {
            $html .= _osc_menu_list($item['children']);
        }
        $html .= '</li>';
    }

    return $html . '</ul>';
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
 * Core "Menu" widget: one of the site's menus as nested lists, with an optional heading.
 * Core prints the markup only; the theme styles .osc-menu.
 */
osc_register_widget('core.menu', array(
    'label'       => 'Menu',
    'description' => 'One of your menus as a list of links.',
    'fields'      => array(
        array('name' => 'menu', 'label' => 'Menu', 'type' => 'select', 'default' => 'main', 'options' => 'osc_menu_choices'),
        array('name' => 'heading', 'label' => 'Heading (optional)', 'type' => 'text'),
    ),
    'render'      => static function ($config) {
        $id    = is_string($config['menu'] ?? null) ? $config['menu'] : 'main';
        $items = osc_menu($id);
        if ($items === array()) {
            return;
        }
        $heading = trim(is_string($config['heading'] ?? null) ? $config['heading'] : '');
        $name    = $heading !== '' ? $heading : (osc_menu_choices()[$id] ?? $id);
        echo '<nav class="osc-menu" aria-label="' . osc_esc_html($name) . '">'
            . ($heading !== '' ? '<h2 class="osc-menu-title">' . osc_esc_html($heading) . '</h2>' : '')
            . _osc_menu_list($items) . '</nav>';
    },
));

/*
 * Core "Image" widget — an image block, chosen from the media library and
 * optionally linked. Registered unconditionally so the page builder can place
 * images out of the box. The 'image' and 'link' values are stored as validated
 * http(s) URLs or site paths (see WidgetConfig::fromPosted); alt text is purified. Safe for any admin.
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
        $src = \mindstellar\utility\Utils::toAbsolute((string) ($config['image'] ?? ''));
        if ($src === '') {
            return;
        }
        $img  = '<img src="' . osc_esc_html($src) . '" alt="' . osc_esc_html((string) ($config['alt'] ?? ''))
            . '" loading="lazy" decoding="async" style="max-width:100%;height:auto"/>';
        $link = \mindstellar\utility\Utils::toAbsolute((string) ($config['link'] ?? ''));
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
 * super_admin-gated — see WidgetConfig::fromPosted(). Render echoes
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
