<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * A custom route page is headed by its route's title, whether it was opened by its friendly
 * URL or by ?page=custom&route=<id>.
 * Usage: php tests/custom-route-heading.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

function _m($text)
{
    return $text;
}

/** Stands in for Rewrite: a query-form request matched no friendly route, so it has no title. */
class Rewrite
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function get_title(): string
    {
        return '';
    }

    public function getRoutes(): array
    {
        return array(
            'heading-test'     => array('title' => 'Downloads'),
            'heading-untitled' => array('title' => 'Custom'),
        );
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hTheme.php';

$_GET = array('page' => 'custom', 'route' => 'heading-test');
Params::init();
pin('the query form takes the route title', 'Downloads', osc_gui_custom_heading());

$_GET = array('page' => 'custom', 'route' => 'heading-untitled');
Params::init();
pin('a route with no title of its own is "Your account"', 'Your account', osc_gui_custom_heading());

$_GET = array('page' => 'custom', 'route' => 'nope');
Params::init();
pin('so is an unknown route', 'Your account', osc_gui_custom_heading());

exit(harness_result());
