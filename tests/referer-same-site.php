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
 * The referer core redirects to must be on this site. A failed CSRF check redirected to
 * Utils::getHttpReferer(), which took a ?http_referer= argument as it came, so a link to
 * the site could bounce a visitor to any other site.
 *
 * DB-free: Rewrite and Session are stood in.  Usage: php tests/referer-same-site.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

final class Rewrite
{
    public static string $ref = '';

    public static function newInstance(): self
    {
        return new self();
    }

    public function get_http_referer(): string
    {
        return self::$ref;
    }
}

final class Session
{
    public static string $ref = '';

    public static function newInstance(): self
    {
        return new self();
    }

    public function _getReferer(): string
    {
        return self::$ref;
    }
}

function osc_base_url($withIndex = false)
{
    return 'https://shop.example/';
}

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\utility\Utils;

$referer = static function (string $rewrite, string $session, string $header): string {
    Rewrite::$ref = $rewrite;
    Session::$ref = $session;
    $_SERVER['HTTP_REFERER'] = $header;
    Params::init();

    return Utils::getHttpReferer();
};

harness_section('which URLs count as this site');
check('same host', Utils::isLocalUrl('https://shop.example/item/5'));
check('same host, other scheme', Utils::isLocalUrl('http://shop.example/'));
check('host case does not matter', Utils::isLocalUrl('https://SHOP.example/x'));
check('another site', !Utils::isLocalUrl('https://evil.example/phish'));
check('a look-alike host', !Utils::isLocalUrl('https://shop.example.evil.example/'));
check('a host that ends the same', !Utils::isLocalUrl('https://evilshop.example/'));
check('protocol-relative', !Utils::isLocalUrl('//evil.example/'));
check('javascript: scheme', !Utils::isLocalUrl('javascript://shop.example/%0aalert(1)'));
check('userinfo trick', !Utils::isLocalUrl('https://shop.example@evil.example/'));
check('empty', !Utils::isLocalUrl(''));

harness_section('getHttpReferer() drops off-site values');
pin('an off-site ?http_referer= is ignored', '', $referer('https://evil.example/phish', '', ''));
pin(
    'an off-site ?http_referer= falls through to a local header',
    'https://shop.example/contact',
    $referer('https://evil.example/phish', '', 'https://shop.example/contact')
);
pin('a local ?http_referer= is used', 'https://shop.example/a', $referer('https://shop.example/a', '', ''));
pin('an off-site Referer header is ignored', '', $referer('', '', 'https://evil.example/'));
pin('a local stored referer is used', 'https://shop.example/s', $referer('', 'https://shop.example/s', ''));

harness_section('osc_get_http_referer() uses the same rule');
$src = file_get_contents(ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php');
check(
    'osc_get_http_referer() delegates to Utils::getHttpReferer()',
    (bool) preg_match('/function osc_get_http_referer\(\)\s*\{\s*return \\\\mindstellar\\\\utility\\\\Utils::getHttpReferer\(\);/', $src)
);

exit(harness_result());
