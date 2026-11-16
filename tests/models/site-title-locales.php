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
 * The site title and description, one per language: the fallback after an update, the
 * visitor's language, mail, SEO and the API.
 * Usage: php tests/models/site-title-locales.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\admin\ExposedSettings;
use mindstellar\admin\form\MainSettingsScreen;
use mindstellar\api\read\SiteFacts;
use mindstellar\mail\CoreEmails;
use mindstellar\mail\Mailer;
use mindstellar\seo\SeoResolver;
use PHPMailer\PHPMailer\PHPMailer;

$admin = api_admin_boot('osc_models_site_title_locales');
scratchdb_pin_site_mode($admin, 'classifieds');

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';

$p = DB_TABLE_PREFIX;
seed_locale($admin);
seed_locale($admin, 'es_ES', 'Espanol');
seed_currency($admin);
// A site from before the update: one title and one description, nothing per language.
foreach (['pageTitle' => 'Old Market', 'pageDesc' => 'Old words', 'contactEmail' => 'owner@example.test', 'language' => 'en_US',
    'currency' => 'USD', 'timezone' => 'UTC', 'dateFormat' => 'Y-m-d', 'timeFormat' => 'H:i'] as $k => $v) {
    Preference::getInstance()->replace($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.71';

$pref = static fn (string $name): ?string => $admin->query(
    "SELECT s_value FROM {$p}t_preference WHERE s_section = 'osclass' AND s_name = '" . $admin->real_escape_string($name) . "'"
)->fetch_row()[0] ?? null;
$as   = static function (string $locale, callable $fn) {
    $_COOKIE['oc_userLocale'] = $locale;
    try {
        return $fn();
    } finally {
        unset($_COOKIE['oc_userLocale']);
    }
};
$set  = static function (array $prefs): void {
    foreach ($prefs as $k => $v) {
        osc_set_preference($k, $v);
    }
    osc_reset_preferences();
};

harness_section('an updated site with only the old single value');
pin('every language shows the old title', ['Old Market', 'Old Market'], [$as('en_US', 'osc_page_title'), $as('es_ES', 'osc_page_title')]);
pin('and the old description', ['Old words', 'Old words'], [$as('en_US', 'osc_page_description'), $as('es_ES', 'osc_page_description')]);
$page = MainSettingsScreen::register();
pin('the settings screen fills every language with it', [
    'pageTitle' => ['en_US' => 'Old Market', 'es_ES' => 'Old Market'],
    'pageDesc'  => ['en_US' => 'Old words', 'es_ES' => 'Old words'],
], array_intersect_key(osc_settings_values($page), ['pageTitle' => 1, 'pageDesc' => 1]));
pin('nothing is written by reading', [null, null], [$pref('pageTitleen_US'), $pref('pageTitlees_ES')]);

harness_section('saved per language');
(new ExposedSettings())->save([
    'site_title_translations'       => ['en_US' => 'Market', 'es_ES' => 'Mercado'],
    'site_description_translations' => ['en_US' => 'Things for sale', 'es_ES' => 'Cosas en venta'],
]);
osc_reset_preferences();
pin('each language is stored under its own key', ['Market', 'Mercado', 'Things for sale', 'Cosas en venta'], [
    $pref('pageTitleen_US'), $pref('pageTitlees_ES'), $pref('pageDescen_US'), $pref('pageDesces_ES'),
]);
pin('the single value follows the default language, for plugins that read it', ['Market', 'Things for sale'], [$pref('pageTitle'), $pref('pageDesc')]);
pin('a visitor in Spanish sees the Spanish title and description', ['Mercado', 'Cosas en venta'], $as('es_ES', static fn () => [osc_page_title(), osc_page_description()]));
pin('a visitor in English the English ones', ['Market', 'Things for sale'], $as('en_US', static fn () => [osc_page_title(), osc_page_description()]));
pin('a visitor with no choice the default language\'s', 'Market', osc_page_title());

harness_section('fallback');
$set(['pageTitlees_ES' => '']);
pin('a language with no title of its own shows the default language\'s', 'Market', $as('es_ES', 'osc_page_title'));
$set(['pageTitleen_US' => '']);
pin('with neither, the single value', 'Market', $as('es_ES', 'osc_page_title'));
$set(['pageTitle' => 'Single', 'pageTitleen_US' => 'Market', 'pageTitlees_ES' => 'Mercado']);
pin('a language the site does not have falls back to the default language', 'Market', $as('fr_FR', 'osc_page_title'));

harness_section('home page head');
$seo = SeoResolver::fromSite();
pin('the SEO title and site name follow the language asked', ['Mercado', 'Mercado', 'Market'], [
    $seo->home('es_ES')['title'], $seo->home('es_ES')['og']['site_name'], $seo->home('en_US')['title'],
]);

harness_section('mail');
if (!function_exists('osc_get_domain')) {
    function osc_get_domain()
    {
        return 'example.test';
    }
}
if (!function_exists('osc_themes_path')) {
    function osc_themes_path()
    {
        return ABS_PATH . 'oc-content/themes/';
    }
}
final class SiteTitleRecordingMailer extends PHPMailer
{
    public static ?array $last = null;

    public function send()
    {
        self::$last = ['from' => $this->FromName, 'body' => $this->Body];

        return true;
    }
}
osc_add_filter('init_send_mail', static fn () => new SiteTitleRecordingMailer(true));
$mail = $as('en_US', static function (): array {
    $template = CoreEmails::template('email_user_forgot_password', 'es_ES');
    $title    = osc_mailBeauty('{WEB_TITLE}', [[], []]);
    SiteTitleRecordingMailer::$last = null;
    osc_sendMail(['to' => 'user@example.test', 'subject' => 's', 'body' => '<p>' . $title . '</p>']);
    $sent  = SiteTitleRecordingMailer::$last;
    $after = osc_mailBeauty('{WEB_TITLE}', [[], []]);

    return [$template !== null, $title, $sent['from'] ?? null, str_contains((string) ($sent['body'] ?? ''), 'You received this e-mail from Mercado'), $after];
});
pin('a mail written in Spanish for a user while the sender browses in English uses the Spanish title', [true, 'Mercado', 'Mercado', true, 'Market'], $mail);
pin('a send may name the language itself', 'Mercado', Mailer::siteTitle(['locale' => 'es_ES']));

harness_section('API');
$bossId = api_admin_seed_admin($admin, 'boss');
$boss   = api_admin_key($bossId);
$facts  = new SiteFacts('en_US', ['en_US' => ['name' => 'English', 'direction' => 'ltr'], 'es_ES' => ['name' => 'Espanol', 'direction' => 'ltr']]);
$call   = api_admin_caller(null, null, $facts);
$r      = $call('GET', '', null, $boss, [], ['locale' => 'es_ES']);
pin('GET / answers in the asked language, with every language beside it', [200, 'Mercado', ['en_US' => ['name' => 'Market', 'description' => 'Things for sale'], 'es_ES' => ['name' => 'Mercado', 'description' => 'Cosas en venta']]], [
    $r->status(), $r->body()['data']['name'] ?? null, $r->body()['data']['translations'] ?? null,
]);
pin('and Accept-Language picks it too', 'Mercado', $call('GET', '', null, $boss, ['Accept-Language' => 'es-ES'])->body()['data']['name'] ?? null);
pin('matches the schema', [], api_admin_schema_errors('SiteDocument', $r));
$r = $call('GET', 'admin/settings', null, $boss);
pin('admin/settings keeps site_title as the default language\'s, and adds the map', ['Market', ['en_US' => 'Market', 'es_ES' => 'Mercado'], 'Cosas en venta'], [
    $r->body()['data']['site_title'] ?? null, $r->body()['data']['site_title_translations'] ?? null, $r->body()['data']['site_description_translations']['es_ES'] ?? null,
]);
pin('matches the schema', [], api_admin_schema_errors('SettingsDocument', $r));
$r = $call('PATCH', 'admin/settings', ['site_title_translations' => ['es_ES' => 'Tienda']], $boss);
pin('PATCH one language changes only it', [200, 'Tienda', 'Market', 'Market'], [$r->status(), $pref('pageTitlees_ES'), $pref('pageTitleen_US'), $pref('pageTitle')]);
$r = $call('PATCH', 'admin/settings', ['site_title' => 'Shop'], $boss);
pin('PATCH site_title still sets the default language\'s title', [200, 'Shop', 'Shop', 'Tienda'], [$r->status(), $pref('pageTitleen_US'), $pref('pageTitle'), $pref('pageTitlees_ES')]);
$r = $call('PATCH', 'admin/settings', ['site_title_translations' => ['de_DE' => 'Laden']], $boss);
pin('a language the site does not have is 422 and saves nothing', [422, null], [$r->status(), $pref('pageTitlede_DE')]);
$r = $call('PATCH', 'admin/settings', ['site_title_translations' => ['es_ES' => '']], $boss);
pin('an empty title is refused', [422, 'Tienda'], [$r->status(), $pref('pageTitlees_ES')]);

exit(harness_result());
