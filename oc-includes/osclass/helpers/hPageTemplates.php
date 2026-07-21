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
