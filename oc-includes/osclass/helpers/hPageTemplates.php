<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\pages\PageTemplateRegistry;

/**
 * Register a static-page template so it can be picked from the page editor and
 * rendered for a static page. Thin wrapper over PageTemplateRegistry::register().
 *
 * See PageTemplateRegistry::register() for the $spec shape.
 *
 * @param string $id   Namespaced slug, [a-z0-9_.-]{1,60}.
 * @param array  $spec Template specification.
 *
 * @return void
 */
function osc_register_page_template($id, $spec)
{
    PageTemplateRegistry::instance()->register($id, $spec);
}

/**
 * All registered page templates, keyed by id.
 *
 * @return array
 */
function osc_page_templates()
{
    return PageTemplateRegistry::instance()->all();
}

/**
 * The spec for a registered page template, or null when the id is not registered.
 *
 * @param string $id
 *
 * @return array|null
 */
function osc_page_template($id)
{
    return PageTemplateRegistry::instance()->get($id);
}

/**
 * The widget location key for a static page's block canvas. Page blocks are
 * ordinary t_widget rows stored at this location, so the whole widget stack
 * (types, ordering, render dispatch) composes a page with no parallel system.
 *
 * @param int|null $pageId Page id, or null to use the current static page.
 *
 * @return string e.g. "page.42"
 */
function osc_page_builder_location($pageId = null)
{
    if ($pageId === null) {
        $pageId = osc_static_page_id();
    }

    return 'page.' . (int)$pageId;
}

/**
 * Render the current static page's blocks — the widgets placed on its canvas.
 * Themes call this from a template-widgets.php; the core fallback renderer calls
 * it too. Emits nothing when the page has no blocks.
 *
 * @return void
 */
function osc_show_page_widgets()
{
    osc_show_widgets(osc_page_builder_location());
}

/*
 * Core "Page builder" template — the block canvas. Registered unconditionally so
 * the option always exists in the page editor without any plugin. Its render is
 * theme-agnostic: if the active theme ships a template-widgets.php it owns the
 * page (and calls osc_show_page_widgets() within its own chrome); otherwise the
 * blocks are filtered into the theme's page.php, so a widgetized page renders
 * inside whatever chrome that theme uses. See PAGE-BUILDER.md.
 */
osc_register_page_template('core.page_builder', array(
    'label'       => 'Page builder (blocks)',
    'description' => 'Compose this page from widget blocks instead of the text editor.',
    'builder'     => true,
    'render'      => static function ($page) {
        $themeFile = osc_themes_path() . osc_theme() . '/template-widgets.php';
        if (file_exists($themeFile)) {
            require $themeFile;

            return;
        }

        // No theme canvas: render the blocks through the theme's own page view,
        // the only static-page template core's view contract guarantees. A root
        // header.php is not part of that contract — storefront has none, and
        // shopclass's carries site chrome only, with the document head living in
        // each view — so this pair emitted a page fragment with no <head>, and
        // therefore no title, description, canonical or robots meta.
        osc_add_filter('static_page_text', static function () {
            ob_start();
            echo '<div class="page-builder">';
            osc_show_page_widgets();
            echo '</div>';

            return ob_get_clean();
        });
        osc_current_web_theme_path('page.php');
    },
));
