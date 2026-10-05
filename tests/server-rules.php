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
 * Pins ServerRules: an .htaccess an older release wrote is brought up to the current rules,
 * which pass the Authorization header to PHP; a hand-edited one is never touched. And
 * ReservedSlugs: "api" and "api/..." are refused as slugs.
 *
 * DB-free.  Usage:  php tests/server-rules.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\routing\ReservedSlugs;
use mindstellar\routing\ServerRules;

$dir  = sys_get_temp_dir() . '/osc-server-rules-' . bin2hex(random_bytes(4));
mkdir($dir);
$file = $dir . '/.htaccess';
$base = '/shop/';

$older = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /shop/\nRewriteRule ^index\\.php$ - [L]\n"
    . "RewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /shop/index.php [L]\n</IfModule>";

harness_section('the current rules');
check('pass the Authorization header on', str_contains(ServerRules::apache($base), ServerRules::AUTHORIZATION . "\n"));
pin('no file: nothing to do, and none is made', [ServerRules::ABSENT, false], [ServerRules::refresh($file, $base), is_file($file)]);

harness_section('a file an older release wrote');
file_put_contents($file, $older . "\n<IfModule mod_mime.c>\nAddType text/xsl .xsl\n</IfModule>");
pin('6.x rules are core\'s, and are refreshed', [ServerRules::OLD, ServerRules::UPDATED], [ServerRules::status($file, $base), ServerRules::refresh($file, $base)]);
pin('...to exactly the current rules', ServerRules::apache($base), file_get_contents($file));
pin('a second run changes nothing', ServerRules::CURRENT, ServerRules::refresh($file, $base));
file_put_contents($file, str_replace("\n", "\r\n", $older) . "\r\n");
pin('older rules without mod_mime, saved with CRLF, are refreshed too', ServerRules::UPDATED, ServerRules::refresh($file, $base));
check('...and pass the header now', ServerRules::passesAuthorization($file));

harness_section('a hand-edited file');
$edited = $older . "\n# Block a bot\nSetEnvIf User-Agent BadBot deny\n";
file_put_contents($file, $edited);
pin('is left alone', [ServerRules::CUSTOM, $edited], [ServerRules::refresh($file, $base), file_get_contents($file)]);
check('...and reported as not passing the header', !ServerRules::passesAuthorization($file));
file_put_contents($file, str_replace('/shop/', '/other/', $older));
pin('so are another base\'s rules', ServerRules::CUSTOM, ServerRules::refresh($file, $base));

unlink($file);
rmdir($dir);

harness_section('reserved slugs');
foreach (['api', 'API', '/api/', 'api/v1', 'api/'] as $slug) {
    check('"' . $slug . '" is reserved', ReservedSlugs::taken($slug));
}
foreach (['apis', 'api_1', 'api-p3', 'my-api', '', 'rapid'] as $slug) {
    check('"' . $slug . '" is free', !ReservedSlugs::taken($slug));
}

exit(harness_result());
