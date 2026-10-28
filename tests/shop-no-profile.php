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
 * Shop mode has no public user profile: the route answers 404 for everyone, the profile URL
 * helpers and the API link are empty, and the sitemap lists none. Classifieds mode is unchanged.
 * DB-free.  Usage: php tests/shop-no-profile.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

$GLOBALS['shop'] = false;

function osc_is_shop_mode(): bool
{
    return $GLOBALS['shop'];
}

function osc_is_web_user_logged_in(): bool
{
    return false;
}

function osc_is_admin_user_logged_in(): bool
{
    return true;
}

function osc_base_url(): string
{
    return 'http://x.test/';
}

function osc_user_id()
{
    return 7;
}

function osc_rewrite_enabled(): bool
{
    return false;
}

function osc_core_url(string $route, array $args = []): string
{
    return 'http://x.test/' . $route . '/' . implode(',', $args);
}

class User
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function findByPrimaryKey($id)
    {
        return null;
    }

    public function findByUsername($name)
    {
        return null;
    }
}

class Params
{
    public static function getParam($key)
    {
        return '';
    }

    public static function getParamInt($key)
    {
        return 1;
    }
}

$helpers = ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
foreach (['osc_user_public_profile_url', 'osc_user_list_items_pub_profile_url'] as $fn) {
    eval(harness_method_source($helpers, $fn));
}

// The controller method, run on a stand-in that records what it was told to do.
$source = harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebUserNonSecure.php', 'publicProfile');
eval('class ProfileProbe { public array $log = []; '
    . 'public function do404() { $this->log[] = "404"; } '
    . 'public function redirectShopperHome() { $this->log[] = "shopper"; } '
    . 'public function redirectTo($url) { $this->log[] = "redirect"; } '
    . 'public function run() { $this->publicProfile(); } '
    . str_replace('private function', 'public function', $source) . ' }');

harness_section('route');
$GLOBALS['shop'] = true;
$probe           = new ProfileProbe();
$probe->run();
pin('shop mode: 404 before any admin, shopper or guest rule', ['404'], $probe->log);
$GLOBALS['shop'] = false;
$probe           = new ProfileProbe();
$probe->run();
pin('classifieds mode still runs the shopper check, then looks the user up', ['shopper', '404'], $probe->log);

harness_section('url helpers');
$GLOBALS['shop'] = true;
pin('shop mode: profile url is empty', '', osc_user_public_profile_url(7));
pin('shop mode: profile url of the current user is empty', '', osc_user_public_profile_url());
pin('shop mode: paged profile url is empty', '', osc_user_list_items_pub_profile_url(2, 20));
$GLOBALS['shop'] = false;
pin('classifieds mode: profile url', 'http://x.test/user_pub_profile_id/7', osc_user_public_profile_url(7));
pin('classifieds mode: paged profile url', 'http://x.test/user_pub_profile_id/7?itemsPerPage=20&iPage=2', osc_user_list_items_pub_profile_url(2, 20));

harness_section('API link');
$links           = new mindstellar\api\serializer\SiteLinks();
$GLOBALS['shop'] = true;
pin('shop mode: the user link is empty', '', $links->user(7, 'ana'));
$GLOBALS['shop'] = false;
pin('classifieds mode: the user link', 'http://x.test/user_pub_profile_id/7', $links->user(7, 'ana'));

harness_section('sitemap');
$sitemap = harness_code_only((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/Sitemap.php'));
check('the sitemap builds no profile url', !str_contains($sitemap, 'profile'));

harness_section('API: GET users/{id} in shop mode');

$visible = new ReflectionMethod(\mindstellar\api\controller\UsersController::class, 'visible');
$visible->setAccessible(true);
$seen = static function (array $user, \mindstellar\apikey\Credential $who, bool $profiles) use ($visible): bool {
    try {
        $visible->invoke(null, $user, $who, $profiles);
    } catch (\mindstellar\api\ProblemException $e) {
        return false;
    }

    return true;
};
$live  = ['pk_i_id' => 7, 'b_enabled' => 1, 'b_active' => 1];
$guest = \mindstellar\apikey\Credential::anonymous(['listings:read']);
$self  = new \mindstellar\apikey\Credential(\mindstellar\apikey\CredentialKind::USER, ['listings:read'], 7);
check('classifieds: a visitor sees a live user', $seen($live, $guest, true));
check('shop mode: a visitor gets 404 for a live user', !$seen($live, $guest, false));
check('shop mode: the user still sees themself', $seen($live, $self, false));

exit(harness_result());

/* file end: ./tests/shop-no-profile.php */
