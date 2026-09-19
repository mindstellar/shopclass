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
 * Pins how a catalog icon or screenshot becomes a URL.
 *
 * Packages hosted in the registry itself carry a path relative to the catalog root, not a
 * full URL. Those were dropped as "host not allowed", so every one of them showed the
 * placeholder tile in Browse while only the externally hosted ones had artwork. The host
 * allow-list still has to hold for anything absolute.
 *
 * Usage: php tests/market-catalog-art.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

use mindstellar\market\Catalog;

$art = static function ($value, string $type = 'plugins') {
    $catalog = $type === 'plugins' ? Catalog::forPlugins() : Catalog::forThemes();
    $method  = new ReflectionMethod(Catalog::class, 'artUrl');
    $method->setAccessible(true);

    return $method->invoke($catalog, $value);
};

harness_section('a path relative to the catalog root');

pin(
    'a plugin icon resolves against the plugin catalog',
    'https://mindstellar.github.io/shopclass-plugins/v1/assets/age-warning/assets/icon.svg',
    $art('assets/age-warning/assets/icon.svg')
);
pin(
    'a theme screenshot resolves against the theme catalog',
    'https://mindstellar.github.io/shopclass-themes/v1/assets/folio/screenshot.png',
    $art('assets/folio/screenshot.png', 'themes')
);

harness_section('absolute urls keep the host rules');

pin(
    'an allowed host is kept',
    'https://raw.githubusercontent.com/mindstellar/shopclass-plugin-nginx-cache/main/assets/icon.svg',
    $art('https://raw.githubusercontent.com/mindstellar/shopclass-plugin-nginx-cache/main/assets/icon.svg')
);
pin('another host is dropped', null, $art('https://evil.example.com/icon.svg'));
pin('plain http is dropped', null, $art('http://raw.githubusercontent.com/x/icon.svg'));
pin('a data url is dropped', null, $art('data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='));
pin('a javascript url is dropped', null, $art('javascript:alert(1)'));

harness_section('paths that must not escape the catalog root');

pin('a root-relative path is dropped', null, $art('/etc/passwd'));
pin('a parent-directory path is dropped', null, $art('assets/../../secret.svg'));
pin('an empty value is dropped', null, $art(''));
pin('a missing value is dropped', null, $art(null));
pin('a non-string value is dropped', null, $art(array('src' => 'x.svg')));

exit(harness_result());
