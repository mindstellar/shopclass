<?php
/*
Plugin Name: Sample Widgets
Plugin URI: https://github.com/mindstellar/Osclass
Description: Reference plugin showing how to register functional widgets through the widget-type registry: a static notice, a live "recent listings" list, a category-filtered list, and a super-admin-gated raw embed.
Version: 1.0.0
Author: Mindstellar Community
Author URI: https://mindstellar.com
Short Name: sample-widgets
*/

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/widgets/recent-listings.php';
require_once __DIR__ . '/widgets/category-list.php';

/**
 * Install callback. This plugin only registers widget types at load time, so
 * there is nothing to install: no tables, no preferences, no admin pages.
 *
 * @return void
 */
function sample_widgets_install()
{
}

osc_register_plugin(osc_plugin_path(__FILE__), 'sample_widgets_install');

/*
 * Register the demo widget types. Every registry call is guarded so that
 * dropping this folder onto an older core (one without the widget registry)
 * degrades to a no-op instead of a fatal error.
 */
if (function_exists('osc_register_widget')) {
    // 1. Static notice — proves the registry plus declarative fields. The
    // message is purified by the controller when the widget is saved (default
    // per-field HTMLPurifier path), so the stored value is echoed as-is here.
    osc_register_widget('sample_widgets.notice', array(
        'label'       => 'Sample: Notice box',
        'description' => 'A static message shown in a styled box. Edit the text in the widget config.',
        'fields'      => array(
            array(
                'name'    => 'message',
                'label'   => 'Message',
                'type'    => 'textarea',
                'default' => 'Hello from the Sample Widgets plugin.',
            ),
        ),
        'render'      => static function ($config) {
            $message = $config['message'] ?? '';
            if ($message === '') {
                return '';
            }

            return '<div class="sample-widget sample-widget-notice">' . $message . '</div>';
        },
    ));

    // 2. Recent listings — proves a functional widget backed by live data.
    // No configuration: it always lists the newest published listings.
    osc_register_widget('sample_widgets.recent_listings', array(
        'label'       => 'Sample: Recent listings',
        'description' => 'Lists the newest published listings as links. No configuration.',
        'render'      => 'sample_widgets_render_recent_listings',
    ));

    // 3. Category listings — proves an s_config JSON round-trip driving
    // behaviour: how many listings, and which category to pull them from.
    osc_register_widget('sample_widgets.category_listings', array(
        'label'       => 'Sample: Category listings',
        'description' => 'Lists the newest published listings from a chosen category.',
        'fields'      => array(
            array(
                'name'    => 'count',
                'label'   => 'Number of listings',
                'type'    => 'number',
                'default' => 5,
            ),
            array(
                'name'    => 'category',
                'label'   => 'Category',
                'type'    => 'select',
                'default' => '',
                'options' => sample_widgets_category_options(),
            ),
        ),
        'render'      => 'sample_widgets_render_category_listings',
    ));

    // 4. Embed code — proves that plugins can ship gated raw types too, using
    // the same 'super_admin' capability plumbing as core.custom_code. The
    // 'code' field is stored unfiltered ONLY because the type is super_admin
    // gated (enforced by the controller); render echoes it verbatim.
    osc_register_widget('sample_widgets.embed_code', array(
        'capability'  => 'super_admin',
        'label'       => 'Sample: Embed code (raw)',
        'description' => 'Outputs an unfiltered HTML/JavaScript snippet (e.g. an analytics or '
            . 'embed tag) directly onto your public pages. Anyone who can edit this widget can '
            . 'run arbitrary code on your site. Super admins only.',
        'fields'      => array(
            array(
                'name'  => 'code',
                'label' => 'Embed / snippet code',
                'type'  => 'code',
            ),
        ),
        'render'      => static function ($config) {
            echo $config['code'] ?? '';
        },
    ));
}

/*
 * Register a demo static-page template. Guarded so dropping this folder onto an
 * older core (one without the page-template registry) degrades to a no-op. This
 * proves a plugin can ship a page layout the site owner picks per page: the
 * callable render owns the whole page, reusing the theme's header/footer and the
 * standard osc_static_page_* helpers (osc_static_page_text() already formats the
 * stored content on output).
 */
if (function_exists('osc_register_page_template')) {
    osc_register_page_template('sample_widgets.plain', array(
        'label'       => 'Sample: Plain page (full width)',
        'description' => 'A minimal, full-width page layout shipped by the sample-widgets plugin.',
        'render'      => static function ($page) {
            osc_current_web_theme_path('header.php');
            echo '<div class="sample-plain-page" style="max-width:820px;margin:2rem auto;padding:0 1rem;">';
            echo '<h1>' . osc_esc_html(osc_static_page_title()) . '</h1>';
            echo osc_static_page_text();
            echo '</div>';
            osc_current_web_theme_path('footer.php');
        },
    ));
}
