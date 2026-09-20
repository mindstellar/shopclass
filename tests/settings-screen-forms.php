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
 * The nine declared settings screens, saved through their declarations rather than through
 * hand-written Params::getParam / validate / osc_set_preference blocks.
 *
 * What this is guarding, in the order it would go wrong:
 *
 *  - The preference a control writes is not always the control's own name. Four of them --
 *    the latest-listings count, the search page size, the contact attachment switch and the
 *    retention answer -- keep the name their page's script and its client-side validation
 *    already know while the value lives under the key every reader uses. Get one wrong and
 *    the screen saves happily into a key nothing reads, and the setting silently reverts.
 *  - Two controls write one preference. Comment moderation is -1 when it is off and a count
 *    when it is on, and how long searches are kept is picked from a list or typed into a
 *    box. Losing the derivation stores the tick rather than the count, or the word "custom"
 *    rather than a number.
 *  - A blank mail-server password means "leave it alone". Written through, it clears the
 *    password and every mail the site sends stops going out, with a success message.
 *  - The clamped numbers are clamped, not refused. A zero sign-in window would shut the
 *    form for everyone including whoever typed it, and the hand-written screens floored the
 *    value rather than bouncing the save.
 *  - Nothing is written when anything is refused, and the effects do not run either --
 *    recalculating the location slugs, or re-registering a billing product, after a save
 *    that did not happen is a site whose behaviour no longer matches its settings.
 *  - And what lands in each preference row -- the value and the type column beside it --
 *    has to be what landed before.
 *
 * DB-backed: the rows are the point, so t_preference is read back with raw SQL rather than
 * through the code under test. The controllers are driven the way a browser drives them,
 * because a rule in a declaration that no request reaches is the same install as no rule.
 *
 * Usage:  php tests/settings-screen-forms.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

// The media screen moves its watermark into osc_uploads_path(), so that is a scratch folder
// of its own rather than the shared temp dir the bootstrap would otherwise point it at.
$GLOBALS['fakeUploads'] = rtrim((string)tempnam(sys_get_temp_dir(), 'oscuploads_'), '/');
@unlink($GLOBALS['fakeUploads']);
@mkdir($GLOBALS['fakeUploads']);
if (!defined('UPLOADS_PATH')) {
    define('UPLOADS_PATH', $GLOBALS['fakeUploads'] . '/');
}
register_shutdown_function(static function () {
    foreach ((array)glob($GLOBALS['fakeUploads'] . '/{,.}*', GLOB_BRACE) as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($GLOBALS['fakeUploads']);
});

// move_uploaded_file() only moves a file PHP itself received, and a CLI run receives none.
// The call is unqualified inside the form's namespace, so this stand-in answers it: it moves
// what the test marked as uploaded and refuses anything else, as the real one does.
$GLOBALS['uploaded'] = array();
eval('namespace mindstellar\\admin\\form;'
     . ' function move_uploaded_file($from, $to) {'
     . ' if (!isset($GLOBALS["uploaded"][$from])) { return false; }'
     . ' unset($GLOBALS["uploaded"][$from]); return rename($from, $to); }');

$admin = scratchdb_session('osc_settings_screen_forms');

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}
if (!function_exists('_e')) {
    function _e($key, $domain = 'core')
    {
        echo $key;
    }
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/index.php';
    }
}
if (!function_exists('osc_add_admin_submenu_page')) {
    function osc_add_admin_submenu_page($menu, $title, $url, $id, $capability = 'administrator')
    {
    }
}

// Recorded rather than enforced: whether the check runs at all, and on which actions, is one
// of the things this file asserts.
$GLOBALS['csrfChecks'] = array();
if (!function_exists('osc_csrf_check')) {
    function osc_csrf_check($die = true)
    {
        $GLOBALS['csrfChecks'][] = Params::getParam('action');

        return true;
    }
}
if (!function_exists('osc_csrf_token_url')) {
    function osc_csrf_token_url()
    {
        return 'CSRFName=test&CSRFToken=test';
    }
}
foreach (array('error', 'ok', 'warning', 'info') as $kind) {
    if (!function_exists('osc_add_flash_' . $kind . '_message')) {
        eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") {'
             . '$GLOBALS["flashes"][] = array("' . $kind . '", $msg); }');
    }
}

// Permalinks writes a file and rebuilds the rule cache. The file goes to a scratch root, not
// to the checkout, and the rebuild is recorded rather than run: what is under test is that
// each happens once on a save and not at all on a refusal.
if (!defined('REL_WEB_URL')) {
    define('REL_WEB_URL', '/');
}
$GLOBALS['fakeRoot'] = rtrim((string)tempnam(sys_get_temp_dir(), 'oscroot_'), '/');
@unlink($GLOBALS['fakeRoot']);
@mkdir($GLOBALS['fakeRoot']);
register_shutdown_function(static function () {
    @unlink($GLOBALS['fakeRoot'] . '/.htaccess');
    @unlink($GLOBALS['fakeRoot'] . '/robots.txt');
    @rmdir($GLOBALS['fakeRoot'] . '/robots.txt');
    @rmdir($GLOBALS['fakeRoot']);
});
if (!function_exists('osc_base_path')) {
    function osc_base_path()
    {
        return $GLOBALS['fakeRoot'] . '/';
    }
}
if (!function_exists('apache_mod_loaded')) {
    function apache_mod_loaded($mod)
    {
        return false;
    }
}

/** The rule-cache rebuild, recorded. Declared before anything can autoload the real one. */
class Rewrite
{
    private static $instance;

    public static function newInstance()
    {
        return self::$instance ?? (self::$instance = new self());
    }

    public function rebuildAndPersistRules()
    {
        $GLOBALS['effects'][] = 'rewrite:rebuild';

        return array();
    }
}

// The effects the declarations own. Recorded, because the guarantee under test is that they
// run once on a save and not at all on a refusal.
$GLOBALS['effects'] = array();
if (!function_exists('osc_calculate_location_slug')) {
    function osc_calculate_location_slug($type)
    {
        $GLOBALS['effects'][] = 'slug:' . $type;
    }
}
if (!function_exists('osc_sitemap_clear_cache')) {
    function osc_sitemap_clear_cache()
    {
        $GLOBALS['effects'][] = 'sitemap:clear';
    }
}
if (!function_exists('osc_sitemap_default_robots_txt')) {
    function osc_sitemap_default_robots_txt()
    {
        return "User-agent: *\nDisallow: /oc-admin/\n";
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($withIndex = false)
    {
        return 'https://example.test/';
    }
}
foreach (array('premium', 'slot', 'item_upgrades', 'seller_limits') as $product) {
    if (!function_exists('osc_register_billing_' . $product)) {
        eval('function osc_register_billing_' . $product . '() {'
             . '$GLOBALS["effects"][] = "billing:' . $product . '"; }');
    }
}

require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
if (!function_exists('osc_current_admin_theme_path')) {
    function osc_current_admin_theme_path($file = '')
    {
    }
}
if (!function_exists('osc_current_admin_theme_url')) {
    function osc_current_admin_theme_url($file = '')
    {
        return 'https://example.test/oc-admin/themes/modern/' . $file;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
// After hSanitize.php, so its real osc_esc_html() is not shadowed by stubs.php's.
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';

/**
 * The controller's base class, stubbed: a redirect is recorded rather than taken (the real
 * one exits), and the section permission check has already happened by the time doModel()
 * runs.
 */
class AdminSecBaseModel
{
    protected $action;

    protected $page;

    public function __construct()
    {
        $this->action = Params::getParam('action');
        $this->page   = Params::getParam('page');
    }

    public function doModel()
    {
    }

    public function isModerator()
    {
        return false;
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    public function _exportVariableToView($key, $value)
    {
        View::newInstance()->_exportVariableToView($key, $value);
    }

    /**
     * The admin theme's view for this screen, drawn where the real one would draw it.
     *
     * Every settings view declares a customHead() at file scope under that one name, so a
     * process that draws more than one of them -- which a request never does and this file
     * does constantly -- would die on the redeclaration. Each view is therefore included
     * through a copy whose head function carries a name of its own. Nothing else about the
     * file is touched, so what is asserted below is the view as it ships.
     */
    public function doView($view)
    {
        $GLOBALS['views'][] = $view;

        $suffix = 'v' . count($GLOBALS['viewCopies']);
        $src    = (string)file_get_contents(ABS_PATH . 'oc-admin/themes/modern/' . $view);
        $src    = str_replace(
            array('function customHead(', "'customHead'"),
            array('function customHead_' . $suffix . '(', "'customHead_" . $suffix . "'"),
            $src
        );
        $copy = tempnam(sys_get_temp_dir(), 'oscview_') . '.php';
        file_put_contents($copy, $src);
        $GLOBALS['viewCopies'][] = $copy;
        register_shutdown_function(static fn () => @unlink($copy));

        include $copy;
    }

    /** Mirrors the real guard: flashes, redirects, and says that it refused. */
    protected function refuseOnDemo($redirectUrl = null)
    {
        if (!defined('DEMO')) {
            return false;
        }
        osc_add_flash_warning_message('This action cannot be done because it is a demo site', 'admin');
        $this->redirectTo($redirectUrl ?? osc_admin_base_url(true));

        return true;
    }
}

foreach (array(
    'Main',
    'Comments',
    'Mailserver',
    'LatestSearches',
    'Advanced',
    'SpamnBots',
    'Billing',
    'Permalinks',
    'Sitemap',
    'Media',
    'Storage',
) as $screen) {
    require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/settings/CAdminSettings' . $screen . '.php';
}

use mindstellar\admin\form\AdvancedSettingsForm;
use mindstellar\admin\form\CommentSettingsForm;
use mindstellar\admin\form\KeywordBlockSettingsForm;
use mindstellar\admin\form\LatestSearchSettingsForm;
use mindstellar\admin\form\MailServerSettingsForm;
use mindstellar\admin\form\MainSettingsForm;
use mindstellar\admin\form\MediaSettingsForm;
use mindstellar\admin\form\PermalinkSettingsForm;
use mindstellar\admin\form\SitemapSettingsForm;
use mindstellar\admin\form\SpamSettingsForm;
use mindstellar\admin\form\StorageSettingsForm;
use mindstellar\admin\form\store\PreferenceStore;
use mindstellar\settings\SettingsPageRegistry;

$GLOBALS['flashes']   = array();
$GLOBALS['redirects'] = array();
$GLOBALS['views']     = array();
$GLOBALS['viewCopies'] = array();

/** One preference row exactly as the table holds it, or null when there is none. */
function pref(mysqli $admin, string $name, string $section = 'osclass'): ?array
{
    $stmt = $admin->prepare(
        'SELECT s_value, e_type FROM ' . DB_TABLE_PREFIX . 't_preference WHERE s_section = ? AND s_name = ?'
    );
    $stmt->bind_param('ss', $section, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row === null ? null : array((string)$row['s_value'], (string)$row['e_type']);
}

/** Write a preference the way the installer does, without going through the code under test. */
function seed_pref(mysqli $admin, string $name, string $value, string $type = 'STRING', string $section = 'osclass'): void
{
    $stmt = $admin->prepare(
        'REPLACE INTO ' . DB_TABLE_PREFIX . 't_preference (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('ssss', $section, $name, $value, $type);
    $stmt->execute();
    $stmt->close();
}

/** Put a request in front of a real controller the way a browser would. */
function drive(string $controller, string $action, array $fields = array(), array $files = array()): array
{
    $_GET   = array('page' => 'settings', 'action' => $action);
    $_POST  = $fields;
    $_FILES = $files;
    Params::init();
    $GLOBALS['flashes']    = array();
    $GLOBALS['redirects']  = array();
    $GLOBALS['csrfChecks'] = array();
    $GLOBALS['effects']    = array();
    $GLOBALS['views']      = array();

    ob_start();
    $instance = new $controller();
    $instance->doModel();
    $drawn = (string)ob_get_clean();

    return array(
        'flashes'   => $GLOBALS['flashes'],
        'redirects' => $GLOBALS['redirects'],
        'csrf'      => $GLOBALS['csrfChecks'],
        'effects'   => $GLOBALS['effects'],
        'views'     => $GLOBALS['views'],
        'drawn'     => $drawn,
    );
}

/** The messages a run flashed, as "kind:body" so both halves are asserted at once. */
function flashed(array $result): array
{
    return array_map(static fn ($flash) => $flash[0] . ':' . $flash[1], $result['flashes']);
}

seed_locale($admin, 'en_US', 'English');
seed_locale($admin, 'es_ES', 'Espanol');
seed_currency($admin, 'USD', 'US Dollar');
seed_currency($admin, 'EUR', 'Euro');

foreach (array(
    array('pageTitle', 'Shopclass', 'STRING'),
    array('pageDesc', '', 'STRING'),
    array('contactEmail', 'admin@example.test', 'STRING'),
    array('language', 'en_US', 'STRING'),
    array('currency', 'USD', 'STRING'),
    array('weekStart', '0', 'STRING'),
    array('timezone', 'Europe/Madrid', 'STRING'),
    array('dateFormat', 'F j, Y', 'STRING'),
    array('timeFormat', 'g:i a', 'STRING'),
    array('num_rss_items', '50', 'INTEGER'),
    array('maxLatestItems@home', '12', 'INTEGER'),
    array('defaultResultsPerPage@search', '12', 'INTEGER'),
    array('moderate_comments', '0', 'INTEGER'),
    array('comments_per_page', '10', 'INTEGER'),
    array('purge_latest_searches', 'week', 'STRING'),
    array('save_latest_searches', '0', 'BOOLEAN'),
    array('mailserver_password', 'the-old-one', 'STRING'),
    array('mailserver_type', 'custom', 'STRING'),
    array('mailserver_host', 'localhost', 'STRING'),
    array('subdomain_type', '', 'STRING'),
    array('subdomain_host', '', 'STRING'),
    array('report_threshold', '5', 'INTEGER'),
    array('akismetKey', '', 'STRING'),
    array('recaptcha_version', '2', 'STRING'),
    array('billing_currency', 'USD', 'STRING'),
) as $seed) {
    seed_pref($admin, $seed[0], $seed[1], $seed[2]);
}
osc_reset_preferences();

/* ---------------------------------------------------------------------------------------
 * The map: which preference each control writes, and in which section. This is the one
 * thing a reader of the declarations cannot check by eye, because four of the keys are not
 * the field's own name and three of the controls are not preferences at all.
 * ------------------------------------------------------------------------------------ */

harness_section('every control writes the key the readers already use');

/** Every declared field of a page as "field => section/key", or "field => (not stored)". */
function keymap(string $pageId): array
{
    $page = osc_settings_page($pageId);
    $map  = array();
    foreach (SettingsPageRegistry::instance()->fields($pageId) as $name => $field) {
        if ($field['type'] === 'custom') {
            continue;
        }
        $map[$name] = ($field['persist'] ?? null) === false
            ? '(not stored)'
            : $page['section'] . '/' . PreferenceStore::key($name, $field);
    }

    return $map;
}

pin(
    'the general settings screen',
    array(
        'pageTitle'                    => 'osclass/pageTitle',
        'pageDesc'                     => 'osclass/pageDesc',
        'contactEmail'                 => 'osclass/contactEmail',
        'language'                     => 'osclass/language',
        'currency'                     => 'osclass/currency',
        'weekStart'                    => 'osclass/weekStart',
        'timezone'                     => 'osclass/timezone',
        'dateFormat'                   => 'osclass/dateFormat',
        'timeFormat'                   => 'osclass/timeFormat',
        'num_rss_items'                => 'osclass/num_rss_items',
        'max_latest_items_at_home'     => 'osclass/maxLatestItems@home',
        'default_results_per_page'     => 'osclass/defaultResultsPerPage@search',
        'selectable_parent_categories' => 'osclass/selectable_parent_categories',
        'enabled_attachment'           => 'osclass/contact_attachment',
        'auto_cron'                    => 'osclass/auto_cron',
        'googlemaps_api_key'           => 'osclass/googlemaps_api_key',
        'openstreet_api_key'           => 'osclass/openstreet_api_key',
        'allow_update_prerelease'      => 'osclass/allow_update_prerelease',
    ),
    keymap(MainSettingsForm::register())
);
pin(
    'the comment screen, where the moderation count is the switch and not the box',
    array(
        'enabled_comments'           => 'osclass/enabled_comments',
        'reg_user_post_comments'     => 'osclass/reg_user_post_comments',
        'enabled_recaptcha_comments' => 'osclass/enabled_recaptcha_comments',
        'moderate_comments'          => 'osclass/moderate_comments',
        'num_moderate_comments'      => '(not stored)',
        'comments_per_page'          => 'osclass/comments_per_page',
        'notify_new_comment'         => 'osclass/notify_new_comment',
        'notify_new_comment_user'    => 'osclass/notify_new_comment_user',
    ),
    keymap(CommentSettingsForm::register())
);
pin(
    'the latest-searches screen, where the presets pick and the hidden field carries',
    array(
        'save_latest_searches' => 'osclass/save_latest_searches',
        'purge_searches'       => '(not stored)',
        'customPurge'          => 'osclass/purge_latest_searches',
    ),
    keymap(LatestSearchSettingsForm::register())
);
pin(
    'the advanced screen',
    array(
        'e_type' => 'osclass/subdomain_type',
        's_host' => 'osclass/subdomain_host',
    ),
    keymap(AdvancedSettingsForm::register())
);
// Only the switch is renamed on the way to storage, and it is the one whose key nothing
// else spells: every reader asks getBoolPreference('rewriteEnabled'), and a control called
// rewrite_enabled writing under its own name would leave friendly URLs permanently off.
pin(
    'the permalinks screen, where the switch is the only renamed key',
    array(
        'rewrite_enabled'                   => 'osclass/rewriteEnabled',
        'rewrite_item_url'                  => 'osclass/rewrite_item_url',
        'rewrite_page_url'                  => 'osclass/rewrite_page_url',
        'rewrite_cat_url'                   => 'osclass/rewrite_cat_url',
        'seo_url_search_prefix'             => 'osclass/seo_url_search_prefix',
        'rewrite_search_url'                => 'osclass/rewrite_search_url',
        'rewrite_search_country'            => 'osclass/rewrite_search_country',
        'rewrite_search_region'             => 'osclass/rewrite_search_region',
        'rewrite_search_city'               => 'osclass/rewrite_search_city',
        'rewrite_search_city_area'          => 'osclass/rewrite_search_city_area',
        'rewrite_search_category'           => 'osclass/rewrite_search_category',
        'rewrite_search_user'               => 'osclass/rewrite_search_user',
        'rewrite_search_pattern'            => 'osclass/rewrite_search_pattern',
        'rewrite_contact'                   => 'osclass/rewrite_contact',
        'rewrite_feed'                      => 'osclass/rewrite_feed',
        'rewrite_language'                  => 'osclass/rewrite_language',
        'rewrite_item_mark'                 => 'osclass/rewrite_item_mark',
        'rewrite_item_send_friend'          => 'osclass/rewrite_item_send_friend',
        'rewrite_item_contact'              => 'osclass/rewrite_item_contact',
        'rewrite_item_new'                  => 'osclass/rewrite_item_new',
        'rewrite_item_activate'             => 'osclass/rewrite_item_activate',
        'rewrite_item_edit'                 => 'osclass/rewrite_item_edit',
        'rewrite_item_delete'               => 'osclass/rewrite_item_delete',
        'rewrite_item_resource_delete'      => 'osclass/rewrite_item_resource_delete',
        'rewrite_user_login'                => 'osclass/rewrite_user_login',
        'rewrite_user_dashboard'            => 'osclass/rewrite_user_dashboard',
        'rewrite_user_logout'               => 'osclass/rewrite_user_logout',
        'rewrite_user_register'             => 'osclass/rewrite_user_register',
        'rewrite_user_activate'             => 'osclass/rewrite_user_activate',
        'rewrite_user_activate_alert'       => 'osclass/rewrite_user_activate_alert',
        'rewrite_user_profile'              => 'osclass/rewrite_user_profile',
        'rewrite_user_items'                => 'osclass/rewrite_user_items',
        'rewrite_user_alerts'               => 'osclass/rewrite_user_alerts',
        'rewrite_user_recover'              => 'osclass/rewrite_user_recover',
        'rewrite_user_forgot'               => 'osclass/rewrite_user_forgot',
        'rewrite_user_change_password'      => 'osclass/rewrite_user_change_password',
        'rewrite_user_change_email'         => 'osclass/rewrite_user_change_email',
        'rewrite_user_change_email_confirm' => 'osclass/rewrite_user_change_email_confirm',
        'rewrite_user_change_username'      => 'osclass/rewrite_user_change_username',
    ),
    keymap(PermalinkSettingsForm::register())
);
pin(
    'the mail-server screen',
    array(
        'mailserver_type'      => 'osclass/mailserver_type',
        'mailserver_host'      => 'osclass/mailserver_host',
        'mailserver_mail_from' => 'osclass/mailserver_mail_from',
        'mailserver_name_from' => 'osclass/mailserver_name_from',
        'mailserver_port'      => 'osclass/mailserver_port',
        'mailserver_username'  => 'osclass/mailserver_username',
        'mailserver_password'  => 'osclass/mailserver_password',
        'mailserver_ssl'       => 'osclass/mailserver_ssl',
        'mailserver_auth'      => 'osclass/mailserver_auth',
        'mailserver_pop'       => 'osclass/mailserver_pop',
    ),
    keymap(MailServerSettingsForm::register())
);
pin(
    'the keyword-blocklist moderation switches',
    array(
        'keyword_spam_enabled'      => 'osclass/keyword_spam_enabled',
        'keyword_spam_hard_block'   => 'osclass/keyword_spam_hard_block',
        'report_autoblock'          => 'osclass/report_autoblock',
        'report_threshold'          => 'osclass/report_threshold',
        'enabled_recaptcha_reports' => 'osclass/enabled_recaptcha_reports',
    ),
    keymap(KeywordBlockSettingsForm::register())
);
pin(
    'the captcha screen, whose version is a hidden constant',
    array(
        'recaptchaVersion'   => 'osclass/recaptcha_version',
        'captchaProvider'    => 'osclass/captchaProvider',
        'recaptchaPubKey'    => 'osclass/recaptchaPubKey',
        'recaptchaPrivKey'   => 'osclass/recaptchaPrivKey',
        'turnstileSiteKey'   => 'osclass/turnstileSiteKey',
        'turnstileSecretKey' => 'osclass/turnstileSecretKey',
    ),
    keymap(SpamSettingsForm::registerCaptcha())
);
pin(
    'the sign-in limiter, which is the one screen here that is not an osclass preference',
    array(
        'login_throttle_enabled'       => 'security/login_throttle_enabled',
        'login_throttle_window'        => 'security/login_throttle_window',
        'login_throttle_max_ip'        => 'security/login_throttle_max_ip',
        'login_throttle_max_account'   => 'security/login_throttle_max_account',
        'login_attempt_retention_days' => 'security/login_attempt_retention_days',
    ),
    keymap(SpamSettingsForm::registerLoginThrottle())
);
pin(
    'and the Akismet key and the search-alert rule',
    array('akismetKey' => 'osclass/akismetKey', 'alerts_require_login' => 'osclass/alerts_require_login'),
    keymap(SpamSettingsForm::registerAkismet()) + keymap(SpamSettingsForm::registerAlerts())
);
// The sitemap readers ask for these exact keys in the osclass section, and robots.txt is a
// file: a preference row under its name would be a second copy nothing reads.
pin(
    'the sitemap settings, every one under its own name',
    array(
        'sitemap_number'      => 'osclass/sitemap_number',
        'sitemap_categories'  => 'osclass/sitemap_categories',
        'sitemap_pages'       => 'osclass/sitemap_pages',
        'sitemap_cities'      => 'osclass/sitemap_cities',
        'sitemap_regions'     => 'osclass/sitemap_regions',
        'sitemap_countries'   => 'osclass/sitemap_countries',
        'sitemap_cat_regions' => 'osclass/sitemap_cat_regions',
        'sitemap_cat_city'    => 'osclass/sitemap_cat_city',
    ),
    keymap(SitemapSettingsForm::register())
);
pin('and the robots.txt box, which is no preference at all', array('sitemap_robots' => '(not stored)'), keymap(SitemapSettingsForm::registerRobots()));
// Two position selects write one preference, each only while its watermark type is chosen.
// The type itself and the text options are no preference: the type is read back from which
// watermark is set, and the options travel as one JSON preference the after_save writes.
pin(
    'the media screen, where both watermark positions are one key',
    array(
        'dimThumbnail'          => 'osclass/dimThumbnail',
        'dimPreview'            => 'osclass/dimPreview',
        'dimNormal'             => 'osclass/dimNormal',
        'keep_original_image'   => 'osclass/keep_original_image',
        'force_jpeg'            => 'osclass/force_jpeg',
        'jpeg_quality'          => 'osclass/jpeg_quality',
        'force_aspect_image'    => 'osclass/force_aspect_image',
        'maxSizeKb'             => 'osclass/maxSizeKb',
        'use_imagick'           => 'osclass/use_imagick',
        'watermark_type'        => '(not stored)',
        'watermark_text'        => 'osclass/watermark_text',
        'watermark_width'       => '(not stored)',
        'watermark_height'      => '(not stored)',
        'text_offset_x'         => '(not stored)',
        'text_offset_y'         => '(not stored)',
        'text_angle'            => '(not stored)',
        'watermark_text_color'  => 'osclass/watermark_text_color',
        'background_color'      => '(not stored)',
        'watermark_text_place'  => 'osclass/watermark_place',
        'watermark_image_place' => 'osclass/watermark_place',
    ),
    keymap(MediaSettingsForm::register())
);
// The storage adapter, the worker and the connection test all read these exact keys, and the
// Better S3 adoption writes the same ones by hand.
pin(
    'the storage screen, every one under its own name',
    array(
        'storage_active'         => 'osclass/storage_active',
        'storage_s3_provider'    => 'osclass/storage_s3_provider',
        'storage_s3_bucket'      => 'osclass/storage_s3_bucket',
        'storage_s3_region'      => 'osclass/storage_s3_region',
        'storage_s3_endpoint'    => 'osclass/storage_s3_endpoint',
        'storage_s3_access_key'  => 'osclass/storage_s3_access_key',
        'storage_s3_secret_key'  => 'osclass/storage_s3_secret_key',
        'storage_s3_path_style'  => 'osclass/storage_s3_path_style',
        'storage_s3_public_url'  => 'osclass/storage_s3_public_url',
        'storage_s3_signed_urls' => 'osclass/storage_s3_signed_urls',
        'storage_s3_signed_ttl'  => 'osclass/storage_s3_signed_ttl',
        'storage_keep_local'     => 'osclass/storage_keep_local',
    ),
    keymap(StorageSettingsForm::register())
);

/* ------------------------------------------------------------------------------------ */

harness_section('general settings');

$main = array(
    'pageTitle'                    => 'My Classifieds',
    'pageDesc'                     => 'Things for sale',
    'contactEmail'                 => 'owner@example.test',
    'language'                     => 'es_ES',
    'currency'                     => 'EUR',
    'weekStart'                    => '1',
    'timezone'                     => 'Europe/Madrid',
    'dateFormat'                   => 'Y/m/d',
    'timeFormat'                   => 'H:i',
    'num_rss_items'                => '25',
    'max_latest_items_at_home'     => '9',
    'default_results_per_page'     => '20',
    'selectable_parent_categories' => '1',
    'enabled_attachment'           => '1',
    'auto_cron'                    => '1',
    'googlemaps_api_key'           => ' gmk ',
    'openstreet_api_key'           => 'osm',
    'allow_update_prerelease'      => '1',
);
$run = drive('CAdminSettingsMain', 'update', $main);

pin('the save is CSRF-checked', array('update'), $run['csrf']);
pin('and reports one success', array('ok:General settings have been updated'), flashed($run));
pin('and goes back to the screen', array('https://example.test/oc-admin/index.php?page=settings'), $run['redirects']);
pin('the page title lands as typed', array('My Classifieds', 'STRING'), pref($admin, 'pageTitle'));
pin('the contact address too', array('owner@example.test', 'STRING'), pref($admin, 'contactEmail'));
pin('the latest-listings count lands under the key readers use', array('9', 'INTEGER'), pref($admin, 'maxLatestItems@home'));
pin('and the search page size under its own', array('20', 'INTEGER'), pref($admin, 'defaultResultsPerPage@search'));
pin('the attachment switch under contact_attachment', array('1', 'BOOLEAN'), pref($admin, 'contact_attachment'));
check('and not under the name of its control', pref($admin, 'enabled_attachment') === null);
pin('a hidden date format is stored like any other value', array('Y/m/d', 'STRING'), pref($admin, 'dateFormat'));
pin('and the time format is stored beside it', array('H:i', 'STRING'), pref($admin, 'timeFormat'));
pin('a key is trimmed', array('gmk', 'STRING'), pref($admin, 'googlemaps_api_key'));

// A checkbox that was not ticked submits nothing at all, and the preference has to say so
// rather than keeping the value from the last save.
$run = drive('CAdminSettingsMain', 'update', array_diff_key($main, array('auto_cron' => 1)));
pin('an unticked switch stores a zero, not an empty string', array('0', 'BOOLEAN'), pref($admin, 'auto_cron'));

// The one rule the hand-written screen had that a type cannot express: a title of
// punctuation is not a title.
$run = drive('CAdminSettingsMain', 'update', array('pageTitle' => '...') + $main);
pin('a title with no letter or digit is refused', array('warning:Page title field is required'), flashed($run));
pin('nothing is stored for it', array('My Classifieds', 'STRING'), pref($admin, 'pageTitle'));
pin('and the screen is redrawn rather than redirected away from', array('settings/index.php'), $run['views']);
pin('with no redirect at all', array(), $run['redirects']);
check('with the rejected date format still in it', strpos($run['drawn'], 'value="..."') !== false);

$run = drive('CAdminSettingsMain', 'update', array('pageTitle' => '') + $main);
pin('an empty title is refused too', array('warning:Page title cannot be left empty'), flashed($run));

$run = drive('CAdminSettingsMain', 'update', array('language' => 'de_DE') + $main);
pin(
    'a language the site does not offer is refused rather than stored',
    array('warning:Default language is not one of the available options'),
    flashed($run)
);
pin('and the stored one is untouched', array('es_ES', 'STRING'), pref($admin, 'language'));

$run = drive('CAdminSettingsMain', 'update', array('num_rss_items' => '-1') + $main);
pin('a negative count is refused', array('warning:RSS shows must be 0 or more'), flashed($run));
$run = drive('CAdminSettingsMain', 'update', array('num_rss_items' => '') + $main);
pin('and a blank one is the zero the screen has always stored', array('0', 'INTEGER'), pref($admin, 'num_rss_items'));

harness_section('comment settings');

$comments = array(
    'enabled_comments'      => '1',
    'moderate_comments'     => '1',
    'num_moderate_comments' => '3',
    'comments_per_page'     => '25',
);
$run = drive('CAdminSettingsComments', 'comments_post', $comments);
pin('moderation on stores the count, not the tick', array('3', 'STRING'), pref($admin, 'moderate_comments'));
check('and the box beside it is no preference of its own', pref($admin, 'num_moderate_comments') === null);
pin('the page size lands', array('25', 'INTEGER'), pref($admin, 'comments_per_page'));
pin('and the switches beside it', array('1', 'BOOLEAN'), pref($admin, 'enabled_comments'));
pin('an unticked one stores a zero', array('0', 'BOOLEAN'), pref($admin, 'notify_new_comment'));

$run = drive('CAdminSettingsComments', 'comments_post', array('moderate_comments' => '') + $comments);
pin('moderation off is the -1 every reader understands', array('-1', 'STRING'), pref($admin, 'moderate_comments'));

$run = drive('CAdminSettingsComments', 'comments_post', array('comments_per_page' => '') + $comments);
pin(
    'a blank page size is refused, as it always was',
    array('warning:Comments per page cannot be left empty'),
    flashed($run)
);
pin('and nothing was written', array('-1', 'STRING'), pref($admin, 'moderate_comments'));

// Both counts were checked with osc_validate_int() before the screen was declared, so a
// decimal never reached the column. Nothing downstream would notice one -- every reader
// casts -- which is exactly why it would sit there.
$run = drive('CAdminSettingsComments', 'comments_post', array('comments_per_page' => '2.5') + $comments);
pin('a page size typed with a decimal point is stored whole', array('2', 'INTEGER'), pref($admin, 'comments_per_page'));
$run = drive('CAdminSettingsComments', 'comments_post', array('num_moderate_comments' => '3.7') + $comments);
pin('and so is the count the moderation switch takes its value from', array('3', 'STRING'), pref($admin, 'moderate_comments'));

// The box lives inside .comments_approved, which the page's own script sets to display:none
// while moderation is off. A browser will not submit a form holding a required control it
// cannot show: no submit event fires, the page's validator never runs, #error_list stays
// empty and the save button looks dead. The save enforces the rule instead.
$html = drive('CAdminSettingsComments', 'comments')['drawn'];
preg_match('/<input[^>]*name="num_moderate_comments"[^>]*>/', $html, $hidden);
check('the box the page sometimes hides is drawn', $hidden !== array());
check(
    'and carries no native required',
    $hidden !== array() && strpos($hidden[0], 'required') === false,
    $hidden[0] ?? '(not drawn)'
);
preg_match('/<input[^>]*name="comments_per_page"[^>]*>/', $html, $always);
check(
    'while the field beside it that is always on screen still does',
    $always !== array() && strpos($always[0], 'required') !== false,
    $always[0] ?? '(not drawn)'
);
$run = drive('CAdminSettingsComments', 'comments_post', array('num_moderate_comments' => '') + $comments);
pin(
    'and an empty box with moderation on is still refused, by the save',
    array('warning:Moderated comments cannot be left empty'),
    flashed($run)
);
$run = drive('CAdminSettingsComments', 'comments_post', array(
    'moderate_comments'     => '',
    'num_moderate_comments' => '',
) + $comments);
pin(
    'with moderation off it is not asked for at all',
    array('ok:Comment settings have been updated'),
    flashed($run)
);
pin('and the -1 lands', array('-1', 'STRING'), pref($admin, 'moderate_comments'));

harness_section('latest searches');

$run = drive('CAdminSettingsLatestSearches', 'latestsearches_post', array(
    'save_latest_searches' => '1',
    'purge_searches'       => 'custom',
    'customPurge'          => '250',
));
pin('the hidden field is what is stored', array('250', 'STRING'), pref($admin, 'purge_latest_searches'));
pin('and the switch beside it', array('1', 'BOOLEAN'), pref($admin, 'save_latest_searches'));
check('while the presets are stored nowhere', pref($admin, 'purge_searches') === null);

$run = drive('CAdminSettingsLatestSearches', 'latestsearches_post', array(
    'save_latest_searches' => '',
    'purge_searches'       => 'custom',
    'customPurge'          => '',
));
pin('an empty answer is refused', array('warning:Custom number cannot be left empty'), flashed($run));
pin('and the switch is not written either -- a refused save is not half a save', array('1', 'BOOLEAN'), pref($admin, 'save_latest_searches'));

harness_section('mail server');

$mail = array(
    'mailserver_type'      => 'custom',
    'mailserver_host'      => 'smtp.example.test',
    'mailserver_mail_from' => 'noreply@example.test',
    'mailserver_name_from' => 'Example',
    'mailserver_port'      => '587',
    'mailserver_username'  => 'postmaster',
    'mailserver_password'  => '',
    'mailserver_ssl'       => 'tls',
);
$run = drive('CAdminSettingsMailserver', 'mailserver_post', $mail);
pin('a blank password leaves the stored one alone', array('the-old-one', 'STRING'), pref($admin, 'mailserver_password'));
pin('the host lands', array('smtp.example.test', 'STRING'), pref($admin, 'mailserver_host'));
pin('and the port is a number', array('587', 'INTEGER'), pref($admin, 'mailserver_port'));

$run = drive('CAdminSettingsMailserver', 'mailserver_post', array('mailserver_password' => 'a new one') + $mail);
pin('a typed password replaces it', array('a new one', 'STRING'), pref($admin, 'mailserver_password'));

$run = drive('CAdminSettingsMailserver', 'mailserver_post', array('mailserver_mail_from' => 'not an address') + $mail);
pin(
    'an address that is not one is refused rather than rewritten',
    array('warning:Mail from is not a valid email address'),
    flashed($run)
);
pin('and nothing else was written either', array('a new one', 'STRING'), pref($admin, 'mailserver_password'));

// The one type that keeps its whitespace. An SMTP password with a space at either end is
// the password; a trimmed copy of it fails to authenticate, and the screen that stored it
// says the settings have been updated.
$run = drive('CAdminSettingsMailserver', 'mailserver_post', array(
    'mailserver_password' => '  pa ss  ',
    'mailserver_username' => ' postmaster ',
) + $mail);
pin('a password is stored exactly as typed', array('  pa ss  ', 'STRING'), pref($admin, 'mailserver_password'));
pin('while the box above it is trimmed like everything else', array('postmaster', 'STRING'), pref($admin, 'mailserver_username'));

harness_section('advanced settings, and the effect that belongs to them');

$run = drive('CAdminSettingsAdvanced', 'advanced_post', array('e_type' => 'city', 's_host' => ' example.test '));
pin('the subdomain type lands under its own key', array('city', 'STRING'), pref($admin, 'subdomain_type'));
pin('and the host, trimmed', array('example.test', 'STRING'), pref($admin, 'subdomain_host'));
pin('the slugs are recalculated once', array('slug:city'), $run['effects']);

$run = drive('CAdminSettingsAdvanced', 'advanced_post', array('e_type' => 'planet', 's_host' => 'x'));
pin(
    'a subdomain type the screen never offered is refused',
    array('warning:Subdomain type is not one of the available options'),
    flashed($run)
);
pin('nothing is recalculated for a save that did not happen', array(), $run['effects']);
pin('and the stored type is untouched', array('city', 'STRING'), pref($admin, 'subdomain_type'));

harness_section('spam and bots');

$throttle = array(
    'login_throttle_enabled'       => '1',
    'login_throttle_window'        => '0',
    'login_throttle_max_ip'        => '0',
    'login_throttle_max_account'   => '-5',
    'login_attempt_retention_days' => '-1',
);
$run = drive('CAdminSettingsSpamnBots', 'login_throttle_post', $throttle);
pin('a zero window is floored, not refused', array('1', 'INTEGER'), pref($admin, 'login_throttle_window', 'security'));
pin('and so is a negative account limit', array('1', 'INTEGER'), pref($admin, 'login_throttle_max_account', 'security'));
pin('retention floors at zero instead', array('0', 'INTEGER'), pref($admin, 'login_attempt_retention_days', 'security'));
pin('the throttle switch is a boolean', array('1', 'BOOLEAN'), pref($admin, 'login_throttle_enabled', 'security'));
check('and none of it landed in the osclass section', pref($admin, 'login_throttle_window') === null);
pin('the save reports itself once', array('ok:Sign-in protection settings have been updated'), flashed($run));

$run = drive('CAdminSettingsSpamnBots', 'akismet_post', array('akismetKey' => '  abc123  '));
pin('an Akismet key is trimmed', array('abc123', 'STRING'), pref($admin, 'akismetKey'));
pin('and a key that was set says so', array('ok:Your Akismet key has been updated'), flashed($run));
$run = drive('CAdminSettingsSpamnBots', 'akismet_post', array('akismetKey' => ''));
pin('a cleared one says the other thing', array('info:Your Akismet key has been cleared'), flashed($run));
pin('and the preference is empty', array('', 'STRING'), pref($admin, 'akismetKey'));

$run = drive('CAdminSettingsSpamnBots', 'recaptcha_post', array(
    'recaptchaVersion'   => '2',
    'captchaProvider'    => 'turnstile',
    'recaptchaPubKey'    => '',
    'recaptchaPrivKey'   => '',
    'turnstileSiteKey'   => 'site',
    'turnstileSecretKey' => 'secret',
));
pin('the provider lands', array('turnstile', 'STRING'), pref($admin, 'captchaProvider'));
pin('the hidden version keeps the key it has always had', array('2', 'STRING'), pref($admin, 'recaptcha_version'));
pin('and a blank key is cleared rather than left alone', array('', 'STRING'), pref($admin, 'recaptchaPubKey'));

$run = drive('CAdminSettingsSpamnBots', 'alerts_post', array('alerts_require_login' => '1'));
pin('the alert rule is a boolean', array('1', 'BOOLEAN'), pref($admin, 'alerts_require_login'));

harness_section('keyword blocklist moderation');

$run = drive('CAdminSettingsKeywordBlock', 'keyword_block_prefs_post', array(
    'keyword_spam_enabled' => '1',
    'report_threshold'     => '0',
));
pin('the threshold is floored at one reporter', array('1', 'INTEGER'), pref($admin, 'report_threshold'));
pin('the filter switch is a boolean', array('1', 'BOOLEAN'), pref($admin, 'keyword_spam_enabled'));
pin('and an untouched switch is off', array('0', 'BOOLEAN'), pref($admin, 'report_autoblock'));

harness_section('billing, and the registrations that follow a save');

$pricing = array(
    'billing_free_live_listings' => '5',
    'billing_slot_enabled'       => '1',
    'billing_slot_credits'       => '-3',
    'billing_slot_quantity'      => '0',
    'billing_premium_enabled'    => '',
    'billing_premium_credits'    => '10',
    'billing_premium_days'       => '30',
    'billing_currency'           => 'eur',
);
$run = drive('CAdminSettingsBilling', 'billing_pricing_post', $pricing);
pin('a currency is stored upper-cased', array('EUR', 'STRING'), pref($admin, 'billing_currency'));
pin('a negative price is floored', array('0', 'INTEGER'), pref($admin, 'billing_slot_credits'));
pin('and a zero quantity at one', array('1', 'INTEGER'), pref($admin, 'billing_slot_quantity'));
pin('the products are re-registered once each', array('billing:premium', 'billing:slot'), $run['effects']);

$run = drive('CAdminSettingsBilling', 'billing_pricing_post', array('billing_currency' => 'e1') + $pricing);
pin(
    'a currency that is not three letters is refused',
    array('warning:Currency must be a 3-letter code, e.g. USD.'),
    flashed($run)
);
$run = drive('CAdminSettingsBilling', 'billing_pricing_post', array('billing_currency' => 'euro') + $pricing);
pin(
    'and one longer than the box allows is refused by the cap the box shows',
    array('warning:Currency must be 3 characters or fewer'),
    flashed($run)
);
pin('nothing is re-registered for a save that did not happen', array(), $run['effects']);
pin('and the stored currency is untouched', array('EUR', 'STRING'), pref($admin, 'billing_currency'));

$run = drive('CAdminSettingsBilling', 'billing_post', array('billing_enabled' => '1'));
pin('the billing switch is a boolean', array('1', 'BOOLEAN'), pref($admin, 'billing_enabled'));
pin('and reports itself', array('ok:Billing settings have been updated'), flashed($run));

harness_section('permalinks, and the two effects that follow a save');

// The structure as the screen posts it, with the shapes the hand-written controller
// normalised: a doubled slash, a trailing one, and the older spelling of the category
// keyword. Every value here is valid, so the refusals below differ from it in one field.
$permalinks = array(
    'rewrite_enabled'                   => '1',
    'rewrite_item_url'                  => '{CATEGORIES}//{ITEM_TITLE}_i{ITEM_ID}/',
    'rewrite_page_url'                  => '{PAGE_SLUG}-p{PAGE_ID}',
    'rewrite_cat_url'                   => '{CATEGORY_SLUG}',
    'seo_url_search_prefix'             => 'shop/',
    'rewrite_search_url'                => 'search',
    'rewrite_search_country'            => 'country/',
    'rewrite_search_region'             => 'region',
    'rewrite_search_city'               => 'city',
    'rewrite_search_city_area'          => 'cityarea',
    'rewrite_search_category'           => 'category',
    'rewrite_search_user'               => 'user',
    'rewrite_search_pattern'            => 'pattern',
    'rewrite_contact'                   => 'contact',
    'rewrite_feed'                      => 'feed',
    'rewrite_language'                  => 'language',
    'rewrite_item_mark'                 => 'item/mark',
    'rewrite_item_send_friend'          => 'item/send-friend',
    'rewrite_item_contact'              => 'item/contact',
    'rewrite_item_new'                  => 'item/new',
    'rewrite_item_activate'             => 'item/activate',
    'rewrite_item_edit'                 => 'item//edit/',
    'rewrite_item_delete'               => 'item/delete',
    'rewrite_item_resource_delete'      => 'resource/delete',
    'rewrite_user_login'                => 'user/login',
    'rewrite_user_dashboard'            => 'user/dashboard',
    'rewrite_user_logout'               => 'user/logout',
    'rewrite_user_register'             => 'user/register',
    'rewrite_user_activate'             => 'user/activate',
    'rewrite_user_activate_alert'       => 'alert/confirm',
    'rewrite_user_profile'              => 'user/profile',
    'rewrite_user_items'                => 'user/items',
    'rewrite_user_alerts'               => 'user/alerts',
    'rewrite_user_recover'              => 'user/recover',
    'rewrite_user_forgot'               => 'user/forgot',
    'rewrite_user_change_password'      => 'password/change',
    'rewrite_user_change_email'         => 'email/change',
    'rewrite_user_change_email_confirm' => 'email/confirm',
    'rewrite_user_change_username'      => 'username/change',
);

$htaccess = $GLOBALS['fakeRoot'] . '/.htaccess';
@unlink($htaccess);
seed_pref($admin, 'rewriteEnabled', '0', 'BOOLEAN');
seed_pref($admin, 'mod_rewrite_loaded', '0', 'BOOLEAN');
foreach ($permalinks as $name => $ignored) {
    if ($name !== 'rewrite_enabled') {
        seed_pref($admin, $name, 'seeded');
    }
}
seed_pref($admin, 'rewrite_item_url', '{CATEGORIES}/{ITEM_TITLE}_i{ITEM_ID}');
osc_reset_preferences();

$run = drive('CAdminSettingsPermalinks', 'permalinks_post', $permalinks);
pin('the switch lands under the key every reader asks for', array('1', 'BOOLEAN'), pref($admin, 'rewriteEnabled'));
pin('a doubled slash is collapsed and the trailing one dropped', array('item/edit', 'STRING'), pref($admin, 'rewrite_item_edit'));
pin('and in the listing structure too', array('{CATEGORIES}/{ITEM_TITLE}_i{ITEM_ID}', 'STRING'), pref($admin, 'rewrite_item_url'));
pin('the retired category keyword is still accepted', array('{CATEGORY_NAME}', 'STRING'), pref($admin, 'rewrite_cat_url'));
pin('the search prefix loses its trailing slash', array('shop', 'STRING'), pref($admin, 'seo_url_search_prefix'));
// Characterization, not a preference: the seven search keywords never took the slash pass
// on the hand-written screen, and a route built from them would move if they started to.
pin('a search keyword is stored exactly as typed', array('country/', 'STRING'), pref($admin, 'rewrite_search_country'));
check('the server rules are on disk', file_exists($htaccess));
pin(
    'and they are byte for byte the ones the screen tells the administrator to paste',
    osc_server_rewrite_rules(),
    (string)file_get_contents($htaccess)
);
pin('the rule cache is rebuilt exactly once', array('rewrite:rebuild'), $run['effects']);
pin(
    'and off mod_php the save says so rather than claiming mod_rewrite is loaded',
    array('warning:Permalinks structure updated. However, we can\'t check if Apache module <b>mod_rewrite</b> is loaded. If you experience some problems with the URLs, you should deactivate <em>Friendly URLs</em>'),
    flashed($run)
);
pin('a saved form redirects rather than redrawing', 1, count($run['redirects']));

// The file is now ours and unchanged, which is the case the byte comparison decides.
$run = drive('CAdminSettingsPermalinks', 'permalinks_post', $permalinks);
pin(
    'saving again over our own file reports success rather than a permanent warning',
    array('ok:Permalinks structure updated'),
    flashed($run)
);

$run = drive('CAdminSettingsPermalinks', 'permalinks_post', array(
    'rewrite_item_url' => '{ITEM_TITLE}',
) + $permalinks);
pin(
    'a listing structure with no {ITEM_ID} is refused',
    array('warning:The listing permalink structure must include {ITEM_ID}.'),
    flashed($run)
);
pin('nothing is rebuilt for a save that did not happen', array(), $run['effects']);
pin('the stored structure is untouched', array('{CATEGORIES}/{ITEM_TITLE}_i{ITEM_ID}', 'STRING'), pref($admin, 'rewrite_item_url'));
pin('and the form comes back to be corrected', array('settings/permalinks.php'), $run['views']);
check('with the rejected listing structure still in it', strpos($run['drawn'], 'value="{ITEM_TITLE}"') !== false);

$run = drive('CAdminSettingsPermalinks', 'permalinks_post', array(
    'rewrite_user_login' => '',
    'rewrite_feed'       => '-',
) + $permalinks);
pin(
    'every empty and every letterless fragment is reported at once, not the first one only',
    array(
        'warning:Feed is not in the expected format',
        'warning:User login cannot be left empty',
    ),
    flashed($run)
);

// The label is what the refusal is worded from, so a label punctuated for the row column
// reads back as "Page URL: cannot be left empty".
$run = drive('CAdminSettingsPermalinks', 'permalinks_post', array('rewrite_page_url' => '') + $permalinks);
pin('a refusal names the field without the row column\'s punctuation', array('warning:Page URL cannot be left empty'), flashed($run));
pin('and neither refusal touches the rules on disk', osc_server_rewrite_rules(), (string)file_get_contents($htaccess));

// A refused save redraws the form from the submission, and the two blocks the declaration
// draws by hand have to be drawn from it as well. An administrator switching friendly URLs
// on for the first time and mistyping one structure is sent back to the one screen whose
// job is to hand them the rules to paste.
seed_pref($admin, 'rewriteEnabled', '0', 'BOOLEAN');
osc_reset_preferences();
$run = drive('CAdminSettingsPermalinks', 'permalinks_post', array(
    'rewrite_item_url' => '{ITEM_TITLE}',
) + $permalinks);
check('a refused first switch-on still shows the switch ticked', strpos($run['drawn'], 'name="rewrite_enabled" id="rewrite_enabled" value="1" checked') !== false);
check('the disclosure it opens is not sent back hidden', strpos($run['drawn'], 'id="custom_rules" data-osc-depends="rewrite_enabled" hidden') === false);
check('and the rules block is on the page to be copied', strpos($run['drawn'], 'class="server-config"') !== false);

// Switching friendly URLs off posts the structure boxes as well -- they are on the page,
// merely hidden -- and none of it may be stored, exactly as the hand-written screen's
// single if arranged.
$run = drive('CAdminSettingsPermalinks', 'permalinks_post', array(
    'rewrite_feed' => 'a-different-feed',
) + array_diff_key($permalinks, array('rewrite_enabled' => true)));
pin('the switch goes off', array('0', 'BOOLEAN'), pref($admin, 'rewriteEnabled'));
pin('and so does the module flag beside it', array('0', 'BOOLEAN'), pref($admin, 'mod_rewrite_loaded'));
pin('a structure typed while the switch was off is discarded', array('feed', 'STRING'), pref($admin, 'rewrite_feed'));
check('our own rules file is taken back off disk', !file_exists($htaccess));
pin('the rule cache is not rebuilt for a structure that was not stored', array(), $run['effects']);
pin('and the screen says so', array('ok:Friendly URLs successfully deactivated'), flashed($run));

// A file somebody else wrote is not ours to delete: it may be carrying rules that have
// nothing to do with this, and the byte comparison is the only thing that can tell.
file_put_contents($htaccess, "# hand written\n");
$run = drive('CAdminSettingsPermalinks', 'permalinks_post', array_diff_key($permalinks, array('rewrite_enabled' => true)));
check('a .htaccess written by hand is left where it is', file_exists($htaccess));
pin(
    'and the screen says why',
    array('warning:Friendly URLs deactivated, but .htaccess file was modified outside Shopclass and was not deleted'),
    flashed($run)
);
@unlink($htaccess);

// nginx reads no .htaccess at all, so there is nothing to write and no mod_rewrite to test.
$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.27.0';
$run = drive('CAdminSettingsPermalinks', 'permalinks_post', $permalinks);
check('on nginx no file is written', !file_exists($htaccess));
pin(
    'and the save points at the server block instead of a file nobody reads',
    array('ok:Permalinks structure updated nginx does not read .htaccess: check the server rules below are in your nginx configuration, then reload nginx.'),
    flashed($run)
);
pin('the structure is still stored', array('1', 'BOOLEAN'), pref($admin, 'rewriteEnabled'));
$run = drive('CAdminSettingsPermalinks', 'permalinks');
check(
    'and the screen shows the nginx location block, not an .htaccess body',
    strpos($run['drawn'], 'try_files') !== false && strpos($run['drawn'], 'mod_rewrite') === false
);
unset($_SERVER['SERVER_SOFTWARE']);

harness_section('sitemap, and the robots.txt it writes');

// Nothing saved yet: the count is the default and categories and pages are included, because
// the sitemap itself treats an unset preference that way.
$run = drive('CAdminSettingsSitemap', 'sitemap');
pin('the screen is drawn', array('settings/sitemap.php'), $run['views']);
check('an unsaved count shows the default', strpos($run['drawn'], 'name="sitemap_number" class="input-text field-num" value="5000"') !== false);
check('categories show included before they are ever saved', strpos($run['drawn'], 'name="sitemap_categories" id="sitemap_categories" value="1" checked') !== false);
check('and so do pages', strpos($run['drawn'], 'name="sitemap_pages" id="sitemap_pages" value="1" checked') !== false);
check('while a location toggle starts off', strpos($run['drawn'], 'name="sitemap_cities" id="sitemap_cities" value="1" />') !== false);
check('the robots.txt box starts from the default body', strpos($run['drawn'], "Disallow: /oc-admin/\n</textarea>") !== false);

$sitemap = array(
    'sitemap_number'     => '1234',
    'sitemap_categories' => '1',
    'sitemap_cities'     => '1',
);
$run = drive('CAdminSettingsSitemap', 'sitemap_settings_post', $sitemap);
pin('the save is CSRF-checked', array('sitemap_settings_post'), $run['csrf']);
pin('the count lands as a number', array('1234', 'INTEGER'), pref($admin, 'sitemap_number'));
pin('a ticked toggle is a boolean', array('1', 'BOOLEAN'), pref($admin, 'sitemap_cities'));
pin('an unticked default-on toggle stores the zero that switches it off', array('0', 'BOOLEAN'), pref($admin, 'sitemap_pages'));
pin('and an unticked location toggle a zero too', array('0', 'BOOLEAN'), pref($admin, 'sitemap_cat_city'));
pin('the cached sitemap is cleared exactly once', array('sitemap:clear'), $run['effects']);
pin('the save reports itself once', array('ok:Sitemap settings have been updated'), flashed($run));
pin('and goes back to the screen', array('https://example.test/oc-admin/index.php?page=settings&action=sitemap'), $run['redirects']);
$run = drive('CAdminSettingsSitemap', 'sitemap');
check('pages opted out stay opted out on the next draw', strpos($run['drawn'], 'name="sitemap_pages" id="sitemap_pages" value="1" />') !== false);

// Corrected rather than refused, exactly as getParamInt() and the clamp did.
foreach (array(
    array('', '5000', 'a blank count is the default'),
    array('0', '5000', 'so is a zero'),
    array('-7', '5000', 'and a negative one'),
    array('abc', '5000', 'and one with no number in it'),
    array('2.5', '2', 'a decimal is stored whole'),
    array('999999', (string)Sitemap::MAX_SITEMAP_URLS, 'and a count past one file\'s limit is held to it'),
) as $case) {
    $run = drive('CAdminSettingsSitemap', 'sitemap_settings_post', array('sitemap_number' => $case[0]) + $sitemap);
    pin($case[2], array($case[1], 'INTEGER'), pref($admin, 'sitemap_number'));
}
pin('none of them is a refusal', array('ok:Sitemap settings have been updated'), flashed($run));

// A zero written before the clamp existed reads as the default rather than as a zero box.
seed_pref($admin, 'sitemap_number', '0', 'INTEGER');
osc_reset_preferences();
$run = drive('CAdminSettingsSitemap', 'sitemap');
check('a stored zero draws the default', strpos($run['drawn'], 'name="sitemap_number" class="input-text field-num" value="5000"') !== false);

$robots = $GLOBALS['fakeRoot'] . '/robots.txt';
@unlink($robots);
$typed = "User-agent: *\r\nDisallow: /x?a=1&b=2\r\n# keep <this>\r\n";
$run   = drive('CAdminSettingsSitemap', 'sitemap_robots_post', array('sitemap_robots' => $typed));
pin('the robots save is CSRF-checked', array('sitemap_robots_post'), $run['csrf']);
// Raw: the XSS filter would turn the & into &amp; and delete the <angle-bracketed> word, and the
// trim every declared box gets would drop the final newline.
pin(
    'robots.txt is written as typed, with its line endings made the file\'s own',
    "User-agent: *\nDisallow: /x?a=1&b=2\n# keep <this>\n",
    (string)file_get_contents($robots)
);
pin('and the write says so', array('ok:robots.txt has been updated'), flashed($run));
pin('a written file goes back to the screen', 1, count($run['redirects']));
check('the box is stored in no preference row', pref($admin, 'sitemap_robots') === null);
pin('and clears nothing: the sitemap did not change', array(), $run['effects']);

$run = drive('CAdminSettingsSitemap', 'sitemap');
check('the next draw shows the file, escaped', strpos($run['drawn'], "Disallow: /x?a=1&amp;b=2\n# keep &lt;this&gt;\n</textarea>") !== false);

// A root with no folder under it: nothing there can be written, whoever runs this.
$realRoot             = $GLOBALS['fakeRoot'];
$GLOBALS['fakeRoot']  = $realRoot . '/missing';
$run = drive('CAdminSettingsSitemap', 'sitemap_robots_post', array('sitemap_robots' => "User-agent: refused\n"));
$GLOBALS['fakeRoot']  = $realRoot;
pin(
    'an unwritable robots.txt is refused',
    array('warning:robots.txt is not writable. Fix the file or folder permissions and try again'),
    flashed($run)
);
pin('with no redirect', array(), $run['redirects']);
pin('and the screen is redrawn', array('settings/sitemap.php'), $run['views']);
check('with what was typed still in the box', strpos($run['drawn'], "User-agent: refused\n</textarea>") !== false);
check('and its save button disabled', strpos($run['drawn'], 'disabled="disabled"') !== false);
pin('the file that is there is untouched', "User-agent: *\nDisallow: /x?a=1&b=2\n# keep <this>\n", (string)file_get_contents($robots));

// A write that fails after the check passed: robots.txt is a folder, which is writable and
// cannot be written as a file.
unlink($robots);
mkdir($robots);
set_error_handler(static fn () => true, E_WARNING);
$run = drive('CAdminSettingsSitemap', 'sitemap_robots_post', array('sitemap_robots' => "User-agent: failed\n"));
restore_error_handler();
rmdir($robots);
pin('a write that fails is reported', array('error:robots.txt could not be saved'), flashed($run));
pin('and is not treated as saved', array(), $run['redirects']);
check('the box comes back with what was typed', strpos($run['drawn'], "User-agent: failed\n</textarea>") !== false);

harness_section('media, and the watermark it uploads');

foreach (array(
    array('dimThumbnail', '240x200'),
    array('dimPreview', '480x340'),
    array('dimNormal', '640x480'),
    array('maxSizeKb', '2048', 'INTEGER'),
    array('keep_original_image', '1', 'BOOLEAN'),
    array('watermark_text', ''),
    array('watermark_text_color', ''),
    array('watermark_place', 'centre'),
    array('watermark_image', '/an/earlier/watermark.png'),
) as $seed) {
    seed_pref($admin, $seed[0], $seed[1], $seed[2] ?? 'STRING');
}
osc_reset_preferences();

$limitKb = MediaSettingsForm::uploadLimitKb();
$run     = drive('CAdminSettingsMedia', 'media');
pin('the media screen is drawn', array('settings/media.php'), $run['views']);
check('with an image set, Image is the type shown', strpos($run['drawn'], 'id="watermark_image" name="watermark_type" value="image" checked') !== false);
check('its block is drawn open', strpos($run['drawn'], 'id="watermark_image_box" data-osc-depends="watermark_type" data-osc-depends-value="[&quot;image&quot;]">') !== false);
check('and the text block hidden', strpos($run['drawn'], 'id="watermark_text_box" class="table-backoffice-form" data-osc-depends="watermark_type" data-osc-depends-value="[&quot;text&quot;]" hidden>') !== false);
check('the text options show their defaults before any are saved', strpos($run['drawn'], 'name="watermark_width" class="input-text field-num" value="200"') !== false);

$media = array(
    'dimThumbnail'         => '240X200',
    'dimPreview'           => '480x340',
    'dimNormal'            => ' 640x480 ',
    'keep_original_image'  => '1',
    'jpeg_quality'         => '70',
    'maxSizeKb'            => '1024',
    'use_imagick'          => '1',
    'watermark_type'       => 'text',
    'watermark_text'       => 'Hello <b>there</b>',
    'watermark_text_color' => '#ff0000',
    'watermark_width'      => '150',
    'watermark_height'     => '40',
    'text_offset_x'        => '5',
    'text_offset_y'        => '',
    'text_angle'           => '15',
    'background_color'     => '#00ff00',
    'watermark_text_place' => 'br',
    'watermark_image_place' => 'tl',
);
$run = drive('CAdminSettingsMedia', 'media_post', $media);
pin('the media save is CSRF-checked', array('media_post'), $run['csrf']);
pin('and reports one success', array('ok:Media config has been updated'), flashed($run));
pin('and goes back to the screen', array('https://example.test/oc-admin/index.php?page=settings&action=media'), $run['redirects']);
pin('an image size is lower-cased', array('240x200', 'STRING'), pref($admin, 'dimThumbnail'));
pin('and trimmed', array('640x480', 'STRING'), pref($admin, 'dimNormal'));
pin('a ticked switch is a boolean', array('1', 'BOOLEAN'), pref($admin, 'keep_original_image'));
pin('an unticked one stores a zero', array('0', 'BOOLEAN'), pref($admin, 'force_jpeg'));
pin(
    'ImageMagick is on only where the library is loaded',
    array(extension_loaded('imagick') ? '1' : '0', 'STRING'),
    pref($admin, 'use_imagick')
);
pin('the JPEG quality lands as a number', array('70', 'INTEGER'), pref($admin, 'jpeg_quality'));
pin('the maximum size too', array('1024', 'INTEGER'), pref($admin, 'maxSizeKb'));
pin('a text watermark writes its text, stripped of tags', array('Hello there', 'STRING'), pref($admin, 'watermark_text'));
pin('and its colour', array('#ff0000', 'STRING'), pref($admin, 'watermark_text_color'));
pin('the text position, not the image one', array('br', 'STRING'), pref($admin, 'watermark_place'));
pin('and clears the image', array('', 'STRING'), pref($admin, 'watermark_image'));
pin(
    'the text options land as one JSON object, the key order and the int casts as they were',
    array('{"watermark_width":150,"watermark_height":40,"text_offset_x":5,"text_offset_y":0,"text_angle":15,"background_color":"#00ff00"}', 'STRING'),
    pref($admin, 'watermark_text_options')
);
foreach (array('watermark_type', 'watermark_width', 'text_angle', 'background_color', 'watermark_text_place', 'watermark_image_place') as $control) {
    check('"' . $control . '" is stored in no preference row of its own', pref($admin, $control) === null);
}

$run = drive('CAdminSettingsMedia', 'media_post', array('watermark_type' => 'none') + $media);
pin('no watermark clears the text', array('', 'STRING'), pref($admin, 'watermark_text'));
pin('its colour', array('', 'STRING'), pref($admin, 'watermark_text_color'));
pin('and the image', array('', 'STRING'), pref($admin, 'watermark_image'));
pin('and writes no position', array('br', 'STRING'), pref($admin, 'watermark_place'));
pin('nor the text options', '{"watermark_width":150,"watermark_height":40,"text_offset_x":5,"text_offset_y":0,"text_angle":15,"background_color":"#00ff00"}', pref($admin, 'watermark_text_options')[0]);

/** A file as PHP hands a POST upload to the request: marked as received, then described. */
function upload(string $bytes, int $error = UPLOAD_ERR_OK, bool $received = true): array
{
    $tmp = (string)tempnam(sys_get_temp_dir(), 'oscupload_');
    file_put_contents($tmp, $bytes);
    register_shutdown_function(static fn () => @unlink($tmp));
    if ($received) {
        $GLOBALS['uploaded'][$tmp] = true;
    }

    return array('watermark_image' => array(
        'name'     => 'watermark.png',
        'type'     => 'image/png',
        'tmp_name' => $error === UPLOAD_ERR_OK ? $tmp : '',
        'error'    => $error,
        'size'     => $error === UPLOAD_ERR_OK ? strlen($bytes) : 0,
    ));
}

$png       = (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$watermark = osc_uploads_path() . '/watermark.png';
$image     = array('watermark_type' => 'image') + $media;
@unlink($watermark);

$run = drive('CAdminSettingsMedia', 'media_post', $image, upload($png));
pin('a PNG is taken', array('ok:Media config has been updated'), flashed($run));
pin('and put where the watermark has always lived', $png, (string)@file_get_contents($watermark));
pin('which is what the preference names', array($watermark, 'STRING'), pref($admin, 'watermark_image'));
pin('the image position is written', array('tl', 'STRING'), pref($admin, 'watermark_place'));
pin('and the text watermark is cleared', array('', 'STRING'), pref($admin, 'watermark_text'));

// A text left behind with no colour: the refusal below redraws the screen, and a stored text
// and colour together would have it render the preview through the image library.
seed_pref($admin, 'watermark_text', 'kept');
osc_reset_preferences();
$run = drive('CAdminSettingsMedia', 'media_post', array('watermark_image_place' => 'bl') + $image);
pin('no upload keeps the image that is there', array($watermark, 'STRING'), pref($admin, 'watermark_image'));
pin('while the rest of the image type is written', array('bl', 'STRING'), pref($admin, 'watermark_place'));
pin('and clears the text', array('', 'STRING'), pref($admin, 'watermark_text'));

seed_pref($admin, 'watermark_text', 'kept');
osc_reset_preferences();
$run = drive('CAdminSettingsMedia', 'media_post', array('dimPreview' => '999x999', 'watermark_image_place' => 'tr') + $image, upload('GIF89a not a png'));
pin('a file that is not a PNG is refused', array('warning:The watermark image has to be a .PNG file'), flashed($run));
pin('with no redirect', array(), $run['redirects']);
pin('and the screen is redrawn', array('settings/media.php'), $run['views']);
check('with what was typed still in it', strpos($run['drawn'], 'value="999x999"') !== false);
pin('nothing beside it is written', array('480x340', 'STRING'), pref($admin, 'dimPreview'));
pin('not the position', array('bl', 'STRING'), pref($admin, 'watermark_place'));
pin('not the text it would have cleared', array('kept', 'STRING'), pref($admin, 'watermark_text'));
pin('and the watermark on disk is untouched', $png, (string)@file_get_contents($watermark));

$run = drive('CAdminSettingsMedia', 'media_post', $image, upload('', UPLOAD_ERR_INI_SIZE));
pin('an upload PHP itself refused is refused too, not skipped', array('warning:There was a problem uploading the watermark image'), flashed($run));
pin('and writes nothing', array('kept', 'STRING'), pref($admin, 'watermark_text'));

// A real PNG that cannot be moved: validation passed, so what saved stays saved and the move
// is what is reported.
@unlink($watermark);
$run = drive('CAdminSettingsMedia', 'media_post', $image, upload($png, UPLOAD_ERR_OK, false));
pin('a PNG that cannot be moved into place says so, and only that', array('error:There was a problem uploading the watermark image'), flashed($run));
pin('the rest of the save stands', array('', 'STRING'), pref($admin, 'watermark_text'));
pin('the image that was there is still named', array($watermark, 'STRING'), pref($admin, 'watermark_image'));
pin('and the screen is gone back to', 1, count($run['redirects']));

// Validation checks the file only for a submitted image type, so a before_save listener that
// switches the type afterwards must not get an unchecked file moved into place.
file_put_contents($watermark, $png);
$toImage = static function ($values, $pageId) {
    if ($pageId === MediaSettingsForm::PAGE_ID) {
        $values['watermark_type'] = 'image';
    }

    return $values;
};
osc_add_filter('admin_form_before_save', $toImage);
$run = drive('CAdminSettingsMedia', 'media_post', $media, upload('GIF89a not a png'));
osc_remove_filter('admin_form_before_save', $toImage);
pin('a type switched to image after validation still has its file checked', array('error:The watermark image has to be a .PNG file'), flashed($run));
pin('and the file is not moved', $png, (string)@file_get_contents($watermark));
pin('nor named', array($watermark, 'STRING'), pref($admin, 'watermark_image'));

$run = drive('CAdminSettingsMedia', 'media_post', array('watermark_type' => 'sepia') + $media);
pin('a watermark type the screen never offered is refused', array('warning:Watermark type is not one of the available options'), flashed($run));

// Refused, where the hand-written screen corrected a bare number to NxN and anything else to
// 100x100 behind a browser check that had already refused both.
foreach (array(
    array('200', 'warning:Thumbnail size is not in the expected format', 'a bare number is refused'),
    array('10x10px', 'warning:Thumbnail size is not in the expected format', 'and so is a size with anything after it'),
    array('', 'warning:Thumbnail size cannot be left empty', 'and a blank one'),
) as $case) {
    $run = drive('CAdminSettingsMedia', 'media_post', array('dimThumbnail' => $case[0], 'jpeg_quality' => '33') + $media);
    pin($case[2], array($case[1]), flashed($run));
}
pin('none of them writes anything', array('70', 'INTEGER'), pref($admin, 'jpeg_quality'));
check('and the box carries the shape the browser checks', strpos($run['drawn'], 'name="dimThumbnail" class="input-text field-num" value="" pattern="[0-9]+[xX][0-9]+" required') !== false);

// Corrected rather than refused, as getParamInt() and the clamp did.
foreach (array(
    array('0', '82', 'a zero JPEG quality is the default'),
    array('101', '82', 'so is one past 100'),
    array('abc', '82', 'and one with no number in it'),
    array('1', '1', 'the lowest quality is kept'),
    array('100', '100', 'and the highest'),
) as $case) {
    $run = drive('CAdminSettingsMedia', 'media_post', array('jpeg_quality' => $case[0]) + $media);
    pin($case[2], array($case[1], 'INTEGER'), pref($admin, 'jpeg_quality'));
}
pin('none of them is a refusal', array('ok:Media config has been updated'), flashed($run));

foreach (array(
    array('', 'warning:Maximum size cannot be left empty', 'a blank maximum size is refused'),
    array('0', 'warning:Maximum size must be 1 or more', 'and a zero'),
    array('12.5', 'warning:Maximum size must be a whole number', 'and a decimal'),
    array('lots', 'warning:Maximum size must be a number', 'and a word'),
) as $case) {
    $run = drive('CAdminSettingsMedia', 'media_post', array('maxSizeKb' => $case[0]) + $media);
    pin($case[2], array($case[1]), flashed($run));
}

$run = drive('CAdminSettingsMedia', 'media_post', array('maxSizeKb' => (string)($limitKb + 1), 'dimPreview' => '500x500') + $media);
pin('a maximum size past what PHP allows is lowered to it', array((string)$limitKb, 'INTEGER'), pref($admin, 'maxSizeKb'));
pin(
    'with a warning rather than a success',
    array('warning:You cannot set a maximum file size higher than the one allowed in the PHP configuration: <b>' . $limitKb . ' KB</b>'),
    flashed($run)
);
pin('while the rest still saves', array('500x500', 'STRING'), pref($admin, 'dimPreview'));
pin('and goes back to the screen', 1, count($run['redirects']));

// The limit arithmetic. The hand-written save read "1G" as 1 and multiplied: 1024 KB.
pin('a gigabyte is a million kilobytes, not 1024', 1048576, MediaSettingsForm::sizeToKb('1G'));
pin('megabytes', 8192, MediaSettingsForm::sizeToKb('8M'));
pin('a lower-case suffix', 8192, MediaSettingsForm::sizeToKb('8m'));
pin('kilobytes as they are', 512, MediaSettingsForm::sizeToKb('512K'));
pin('a bare number is bytes, as php.ini reads it', 1024, MediaSettingsForm::sizeToKb('1048576'));
pin('and the controller\'s own converter answers the same', 1048576, (new CAdminSettingsMedia())->_sizeToKB('1G'));
$memory = (string)ini_get('memory_limit');
ini_set('memory_limit', '-1');
check('an unlimited memory_limit is no limit, not a limit of -1', MediaSettingsForm::uploadLimitKb() > 0);
pin(
    'so the smallest limit left is the one that applies',
    min(MediaSettingsForm::sizeToKb((string)ini_get('upload_max_filesize')), MediaSettingsForm::sizeToKb((string)ini_get('post_max_size')) ?: PHP_INT_MAX),
    MediaSettingsForm::uploadLimitKb()
);
ini_set('memory_limit', $memory);

pin('the maximum size names PHP\'s limit', '<span class="callout-warning">Maximum size PHP configuration allows: 2048 KB</span>', MediaSettingsForm::sizeHelp(2048));
pin('and says nothing when PHP sets none', '', MediaSettingsForm::sizeHelp(PHP_INT_MAX));
$unlimited = shell_exec(escapeshellarg(PHP_BINARY) . ' -d upload_max_filesize=0 -d post_max_size=0 -d memory_limit=-1 -r '
    . escapeshellarg('require "' . ABS_PATH . 'oc-includes/vendor/autoload.php"; echo mindstellar\admin\form\MediaSettingsForm::uploadLimitKb();'));
pin('which is what every limit set to unlimited answers', (string)PHP_INT_MAX, trim((string)$unlimited));

$run = drive('CAdminSettingsMedia', 'images_post');
pin('regenerating is still the sibling action it was, CSRF and all', array('images_post'), $run['csrf']);

harness_section('storage, and the connection it keeps');

// Nothing saved yet: local disk, the custom provider and the default lifetime.
$run = drive('CAdminSettingsStorage', 'storage');
pin('the storage screen is drawn', array('settings/storage.php'), $run['views']);
check('an unsaved backend shows local disk', strpos($run['drawn'], '<option value="local" selected>') !== false);
check('an unsaved provider opens on custom', strpos($run['drawn'], '<option value="custom" selected>') !== false);
check('an unsaved lifetime shows 900', strpos($run['drawn'], 'name="storage_s3_signed_ttl" class="input-text field-num" value="900"') !== false);
check('and local copies are kept', strpos($run['drawn'], '<option value="all" selected>') !== false);

$storage = array(
    'storage_active'         => 's3',
    'storage_s3_provider'    => 'aws',
    'storage_s3_bucket'      => 'my-bucket',
    'storage_s3_region'      => 'eu-west-1',
    'storage_s3_endpoint'    => 'https://s3.eu-west-1.amazonaws.com',
    'storage_s3_access_key'  => 'AKIAEXAMPLE',
    'storage_s3_secret_key'  => 'first&secret<key> ',
    'storage_s3_path_style'  => '1',
    'storage_s3_public_url'  => 'https://cdn.example.test/media',
    'storage_s3_signed_urls' => '1',
    'storage_s3_signed_ttl'  => '3600',
    'storage_keep_local'     => 'none',
);
$payloads = array();
$spy      = static function (...$args) use (&$payloads) {
    $payloads[] = $args;

    return $args[0];
};
osc_add_filter('admin_form_before_save', $spy);
osc_add_hook('admin_form_after_save', $spy);
osc_add_hook('settings_page_saved', $spy);
$run = drive('CAdminSettingsStorage', 'storage_post', $storage);
osc_remove_filter('admin_form_before_save', $spy);
osc_remove_hook('admin_form_after_save', $spy);
osc_remove_hook('settings_page_saved', $spy);

pin('the storage save is CSRF-checked', array('storage_post'), $run['csrf']);
pin('and reports the success it always did', array('ok:Storage settings updated'), flashed($run));
pin('and goes back to the screen', array('https://example.test/oc-admin/index.php?page=settings&action=storage'), $run['redirects']);
pin('the backend is written', array('s3', 'STRING'), pref($admin, 'storage_active'));
pin('the provider', array('aws', 'STRING'), pref($admin, 'storage_s3_provider'));
pin('the bucket', array('my-bucket', 'STRING'), pref($admin, 'storage_s3_bucket'));
pin('a typed region', array('eu-west-1', 'STRING'), pref($admin, 'storage_s3_region'));
pin('the endpoint', array('https://s3.eu-west-1.amazonaws.com', 'STRING'), pref($admin, 'storage_s3_endpoint'));
pin('the access key', array('AKIAEXAMPLE', 'STRING'), pref($admin, 'storage_s3_access_key'));
pin('the secret, exactly as typed', array('first&secret<key> ', 'STRING'), pref($admin, 'storage_s3_secret_key'));
pin('a ticked path-style switch', array('1', 'BOOLEAN'), pref($admin, 'storage_s3_path_style'));
pin('the public URL', array('https://cdn.example.test/media', 'STRING'), pref($admin, 'storage_s3_public_url'));
pin('a ticked signed-URL switch', array('1', 'BOOLEAN'), pref($admin, 'storage_s3_signed_urls'));
pin('the lifetime, typed as it always was', array('3600', 'STRING'), pref($admin, 'storage_s3_signed_ttl'));
pin('and local copies', array('none', 'STRING'), pref($admin, 'storage_keep_local'));

// Each of the three is handed the submission; not one of them is handed the secret key.
pin('before_save, after_save and settings_page_saved each ran once', 3, count($payloads));
foreach ($payloads as $args) {
    $values = $args[0] === StorageSettingsForm::PAGE_ID ? $args[1] : $args[0];
    check('a hook payload carries no secret key', is_array($values) && !array_key_exists('storage_s3_secret_key', $values));
    check('while it still carries the rest', is_array($values) && ($values['storage_s3_bucket'] ?? null) === 'my-bucket');
}
check('the typed secret appears nowhere in any payload', strpos(serialize($payloads), 'first&secret') === false);

// Keep-if-blank: a blank box leaves the stored key where it is.
$run = drive('CAdminSettingsStorage', 'storage_post', array('storage_s3_secret_key' => '', 'storage_s3_bucket' => 'renamed') + $storage);
pin('a blank secret leaves the stored one alone', array('first&secret<key> ', 'STRING'), pref($admin, 'storage_s3_secret_key'));
pin('while the rest saves', array('renamed', 'STRING'), pref($admin, 'storage_s3_bucket'));
$run = drive('CAdminSettingsStorage', 'storage_post', array_diff_key($storage, array('storage_s3_secret_key' => true)));
pin('so does a request with no secret in it', array('first&secret<key> ', 'STRING'), pref($admin, 'storage_s3_secret_key'));
$run = drive('CAdminSettingsStorage', 'storage_post', array('storage_s3_secret_key' => array('x')) + $storage);
pin('and one posting the box as a list', array('first&secret<key> ', 'STRING'), pref($admin, 'storage_s3_secret_key'));
$run = drive('CAdminSettingsStorage', 'storage_post', array('storage_s3_secret_key' => 'zq-second-key') + $storage);
pin('a new one replaces it', array('zq-second-key', 'STRING'), pref($admin, 'storage_s3_secret_key'));

$run = drive('CAdminSettingsStorage', 'storage');
check('the stored secret is never drawn back', strpos($run['drawn'], 'zq-second-key') === false);
check(
    'the box draws empty over the keep-it hint',
    strpos($run['drawn'], 'name="storage_s3_secret_key" class="input-text field-key" value="" autocomplete="off" spellcheck="false" autocomplete="new-password" placeholder="Leave blank to keep the currently saved secret key"') !== false
);
check('the form reopens on the stored provider', strpos($run['drawn'], '<option value="aws" selected>') !== false);
ob_start();
$redraw = StorageSettingsForm::formVars(array('storage_s3_secret_key' => 'typed-then-refused') + $storage);
osc_admin_settings_form($redraw['id'], $redraw);
check('nor is a typed one on a refused redraw', strpos((string)ob_get_clean(), 'typed-then-refused') === false);

// Corrected rather than refused, exactly as the hand-written save did.
foreach (array(
    array('storage_active', 'local', 'local', 'local stays local'),
    array('storage_active', 'S3', 'local', 'a backend that is not exactly s3 is local'),
    array('storage_active', 'ftp', 'local', 'and so is one never offered'),
    array('storage_active', array('s3'), 'local', 'and one posted as a list'),
    array('storage_s3_provider', 'r2', 'r2', 'a known provider is kept'),
    array('storage_s3_provider', 'dropbox', 'custom', 'an unknown provider is custom'),
    array('storage_s3_provider', '', 'custom', 'and so is a blank one'),
    array('storage_s3_provider', array('aws'), 'custom', 'and one posted as a list'),
    array('storage_keep_local', 'all', 'all', 'keeping local copies is kept'),
    array('storage_keep_local', 'NONE', 'all', 'anything but exactly none keeps them'),
    array('storage_keep_local', 'some', 'all', 'including a value never offered'),
    array('storage_s3_endpoint', 'http://minio_s3:9000', 'http://minio_s3:9000', 'an http endpoint is kept, underscore host and all'),
    array('storage_s3_endpoint', 'HTTPS://S3.EXAMPLE.TEST', 'HTTPS://S3.EXAMPLE.TEST', 'the scheme is matched in any case'),
    array('storage_s3_endpoint', 'javascript:alert(1)', '', 'a javascript: endpoint is blank'),
    array('storage_s3_endpoint', 'data:text/html;base64,PHNjcmlwdD4=', '', 'and a data: one'),
    array('storage_s3_endpoint', 'ftp://files.example.test', '', 'and an ftp one'),
    array('storage_s3_endpoint', 's3.example.test', '', 'and one with no scheme'),
    array('storage_s3_endpoint', 'https://s3 .example.test', 'https://s3.example.test', 'osc_sanitize_url runs first'),
    array('storage_s3_public_url', 'javascript:alert(document.cookie)', '', 'a javascript: public URL is blank'),
    array('storage_s3_public_url', 'data:image/svg+xml,<svg onload=alert(1)>', '', 'and a data: one'),
    array('storage_s3_public_url', ' javascript://https://cdn.example.test', '', 'and a javascript: one hiding an https inside'),
    array('storage_s3_public_url', 'https://cdn.example.test/"><script>alert(1)</script>', 'https://cdn.example.test/"&gt;', 'and markup is stripped before it is stored'),
    array('storage_s3_public_url', '', '', 'a blank public URL stays blank'),
    array('storage_s3_signed_ttl', '3600', '3600', 'a lifetime in range is kept'),
    array('storage_s3_signed_ttl', '1', '60', 'one below 60 is raised to it'),
    array('storage_s3_signed_ttl', '60', '60', '60 is kept'),
    array('storage_s3_signed_ttl', '604800', '604800', 'and so is a week'),
    array('storage_s3_signed_ttl', '999999', '604800', 'past a week is held to a week'),
    array('storage_s3_signed_ttl', '0', '900', 'a zero is 900'),
    array('storage_s3_signed_ttl', '-5', '900', 'and so is a negative one'),
    array('storage_s3_signed_ttl', '', '900', 'and a blank one'),
    array('storage_s3_signed_ttl', 'soon', '900', 'and one with no number in it'),
    array('storage_s3_signed_ttl', array('3600'), '900', 'and one posted as a list'),
) as $case) {
    $run = drive('CAdminSettingsStorage', 'storage_post', array($case[0] => $case[1]) + $storage);
    pin($case[3], $case[2], pref($admin, $case[0])[0] ?? null);
}
pin('none of them is a refusal', array('ok:Storage settings updated'), flashed($run));

// A checkbox is its presence.
$run = drive('CAdminSettingsStorage', 'storage_post', array_diff_key($storage, array('storage_s3_path_style' => true, 'storage_s3_signed_urls' => true)));
pin('an unticked path-style switch stores the zero that switches it off', array('0', 'BOOLEAN'), pref($admin, 'storage_s3_path_style'));
pin('and so does an unticked signed-URL switch', array('0', 'BOOLEAN'), pref($admin, 'storage_s3_signed_urls'));

// The region fallback runs at the write, against the provider as it was corrected and as any
// before_save listener left it.
foreach (array(
    array('r2', '', 'auto', 'a blank region under a provider that locks it is the preset region'),
    array('r2', '   ', 'auto', 'and so is one that is only spaces'),
    array('r2', 'weur', 'weur', 'a region typed under a locking provider is kept'),
    array('aws', '', '', 'a blank region under a provider that does not lock it stays blank'),
    array('R2', '', '', 'a provider that is not a known preset locks nothing'),
    array('r2', array('weur'), 'auto', 'a region posted as a list under a locking provider is the preset region'),
    array(array('r2'), '', '', 'nor does one posted as a list'),
) as $case) {
    $run = drive('CAdminSettingsStorage', 'storage_post', array('storage_s3_provider' => $case[0], 'storage_s3_region' => $case[1]) + $storage);
    pin($case[3], $case[2], pref($admin, 'storage_s3_region')[0] ?? null);
}
pin('the fallback follows the provider that was stored', array('custom', 'STRING'), pref($admin, 'storage_s3_provider'));

$toR2 = static function ($values, $pageId) {
    if ($pageId === StorageSettingsForm::PAGE_ID) {
        $values['storage_s3_provider'] = 'r2';
    }

    return $values;
};
osc_add_filter('admin_form_before_save', $toR2);
$run = drive('CAdminSettingsStorage', 'storage_post', array('storage_s3_provider' => 'aws', 'storage_s3_region' => '') + $storage);
osc_remove_filter('admin_form_before_save', $toR2);
pin('a provider a before_save listener switches to r2 is stored', array('r2', 'STRING'), pref($admin, 'storage_s3_provider'));
pin('and a blank region under it is the preset region', array('auto', 'STRING'), pref($admin, 'storage_s3_region'));
check('the form reads nothing out of the request', strpos((string)file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/admin/form/StorageSettingsForm.php'), 'Params::') === false);

pin('the corrections, called directly: provider', array('r2', 'custom', 'custom'), array(StorageSettingsForm::provider('r2'), StorageSettingsForm::provider('nope'), StorageSettingsForm::provider(null)));
pin('region', array('auto', '', 'x'), array(StorageSettingsForm::region('', 'r2'), StorageSettingsForm::region('', 'custom'), StorageSettingsForm::region('x', 'r2')));
pin('lifetime', array(60, 604800, 900, 900), array(StorageSettingsForm::ttl('59'), StorageSettingsForm::ttl('604801'), StorageSettingsForm::ttl('0'), StorageSettingsForm::ttl(array())));

// The Better S3 adoption writes the endpoint through the controller's own guard, which has to
// answer exactly as the declared one does.
$guard = new ReflectionMethod('CAdminSettingsStorage', '_httpUrlOrEmpty');
$guard->setAccessible(true);
foreach (array('https://a.example.test', 'javascript:alert(1)', 'data:text/plain,x', 'https://' . 'x y.test', '') as $url) {
    pin(
        'the adoption guard and the declared one agree on "' . $url . '"',
        StorageSettingsForm::httpUrlOrEmpty($url),
        $guard->invoke(new CAdminSettingsStorage(), $url)
    );
}

$run = drive('CAdminSettingsStorage', 'storage_migrate_post', array('op' => 'adopt_better_s3'));
pin('adoption is still its own CSRF-checked action', array('storage_migrate_post'), $run['csrf']);
pin('and with nothing to adopt, still says so', array('error:Better S3 is not configured; nothing to adopt.'), flashed($run));

/* ---------------------------------------------------------------------------------------
 * What the page around the form still reaches for. A declared field's id is derived from
 * its name -- field-<name> -- while the hand-written view it replaced wrote its own. Where
 * the page's script looks one up by hand and the id moved, getElementById answers null, the
 * script quietly does nothing, and the control it was driving submits whatever it held when
 * the page loaded: no error, no warning, and a green "settings have been updated" over the
 * old value.
 * ------------------------------------------------------------------------------------ */

harness_section('the ids and form names each page\'s own script reaches for');

$screenViews = array(
    'settings/index.php'      => array('CAdminSettingsMain', 'settings'),
    'settings/comments.php'   => array('CAdminSettingsComments', 'comments'),
    'settings/mailserver.php' => array('CAdminSettingsMailserver', 'mailserver'),
    'settings/searches.php'   => array('CAdminSettingsLatestSearches', 'latestsearches'),
    'settings/advanced.php'   => array('CAdminSettingsAdvanced', 'advanced'),
    'settings/spamNbots.php'  => array('CAdminSettingsSpamnBots', 'spamNbots'),
    'settings/billing.php'    => array('CAdminSettingsBilling', 'billing'),
    'settings/permalinks.php' => array('CAdminSettingsPermalinks', 'permalinks'),
    'settings/sitemap.php'    => array('CAdminSettingsSitemap', 'sitemap'),
    'settings/media.php'      => array('CAdminSettingsMedia', 'media'),
    'settings/storage.php'    => array('CAdminSettingsStorage', 'storage'),
);
$drawn = array();
foreach ($screenViews as $view => $screen) {
    $drawn[$view] = drive($screen[0], $screen[1])['drawn'];
}

// The three the declarations have to spell out, because a page's script writes the answer
// into them and the save reads nothing else.
check(
    'the retention answer is written into the id searches.php names',
    strpos($drawn['settings/searches.php'], 'id="customPurge"') !== false
);
check(
    'the date format into the one index.php names',
    strpos($drawn['settings/index.php'], 'id="dateFormat"') !== false
);
check(
    'and the time format beside it',
    strpos($drawn['settings/index.php'], 'id="timeFormat"') !== false
);
// Permalinks used to drive its disclosure from a script of its own, reaching for these two
// ids by hand; the declaration replaced the listener with the shared conditional-field
// attribute. The ids stay because a forked admin theme's copy of the view may still be the
// hand-written one, and because field-rewrite_enabled is not the name anything looks up.
check(
    'the friendly-URLs switch keeps the id the screen has always given it',
    strpos($drawn['settings/permalinks.php'], 'id="rewrite_enabled"') !== false
);
check(
    'the structure disclosure keeps its own',
    strpos($drawn['settings/permalinks.php'], 'id="custom_rules"') !== false
);
check(
    'and it is hidden and shown by the shared attribute rather than a listener of its own',
    strpos($drawn['settings/permalinks.php'], 'id="custom_rules" data-osc-depends="rewrite_enabled"') !== false
);
// No script on the sitemap screen reaches for these today, but a forked theme's copy may, and
// the declared defaults would have moved every one of them to field-<name>.
foreach (array(
    'id="sitemap_number"',
    'id="submit_sitemap_settings"',
    'id="sitemap_robots"',
    'name="settings_form"',
    'name="sitemap_robots_form"',
) as $needle) {
    check('the sitemap screen still draws ' . $needle, strpos($drawn['settings/sitemap.php'], $needle) !== false);
}
foreach (array_keys(SitemapSettingsForm::TOGGLES) as $toggle) {
    check('and the toggle id "' . $toggle . '"', strpos($drawn['settings/sitemap.php'], 'id="' . $toggle . '"') !== false);
}
check(
    'the settings form posts the action it always did',
    strpos($drawn['settings/sitemap.php'], 'name="settings_form"><input type="hidden" name="page" value="settings"/><input type="hidden" name="action" value="sitemap_settings_post"/>') !== false
);
check(
    'and so does the robots.txt one',
    strpos($drawn['settings/sitemap.php'], 'name="sitemap_robots_form"><input type="hidden" name="page" value="settings"/><input type="hidden" name="action" value="sitemap_robots_post"/>') !== false
);
// The keep-original recommendation still reaches for the three radios and the switch by id,
// and a forked theme's copy of the old script also drives the two blocks by theirs.
foreach (array(
    'id="watermark_none"',
    'id="watermark_text"',
    'id="watermark_image"',
    'id="keep_original_image"',
    'id="watermark_text_box"',
    'id="watermark_image_box"',
    'id="colorpickerField1"',
    'id="colorpickerField2"',
    'id="watermark_text_place"',
    'id="watermark_image_place"',
    'id="watermark_image_file"',
    'id="dialog-watermark-warning"',
) as $needle) {
    check('the media screen still draws ' . $needle, strpos($drawn['settings/media.php'], $needle) !== false);
}
check(
    'the media form keeps its name and action, and posts multipart so the watermark arrives',
    strpos($drawn['settings/media.php'], 'name="media_form" enctype="multipart/form-data"><input type="hidden" name="page" value="settings"/><input type="hidden" name="action" value="media_post"/>') !== false
);
check('the file control keeps the name the save reads', strpos($drawn['settings/media.php'], 'type="file" id="watermark_image_file" name="watermark_image"') !== false);
check('a declared form without the option posts as it always did', strpos($drawn['settings/sitemap.php'], 'enctype=') === false);
check(
    'and the blocks are hidden and shown by the shared attribute',
    strpos($drawn['settings/media.php'], 'id="watermark_text_box" class="table-backoffice-form" data-osc-depends="watermark_type"') !== false
    && strpos($drawn['settings/media.php'], 'id="watermark_image_box" data-osc-depends="watermark_type"') !== false
);

// The provider-preset script finds the provider by id, the three boxes it fills by name, and
// the public-URL hint it rewrites by id; the two switches keep the ids their labels point at.
foreach (array(
    'id="storage_provider" name="storage_s3_provider"',
    'name="storage_s3_endpoint"',
    'name="storage_s3_region"',
    'name="storage_s3_path_style"',
    'id="storage_public_url_hint"',
    'id="storage_s3_path_style"',
    'id="storage_s3_signed_urls"',
    'name="storage_s3_secret_key"',
) as $needle) {
    check('the storage screen still draws ' . $needle, strpos($drawn['settings/storage.php'], $needle) !== false);
}
check(
    'the storage form keeps its name and action',
    strpos($drawn['settings/storage.php'], 'name="storage_form"><input type="hidden" name="page" value="settings"/><input type="hidden" name="action" value="storage_post"/>') !== false
);
check('and the preset script is still on the page', strpos((string)file_get_contents(ABS_PATH . 'oc-admin/themes/modern/settings/storage.php'), 'regionField.readOnly = !!preset.region_locked') !== false);
check('the two URL boxes still ask the browser for an http(s) URL', substr_count($drawn['settings/storage.php'], 'inputmode="url" pattern="[Hh][Tt][Tt][Pp][Ss]?://.*"') === 2);
foreach (array('storage_test_post', 'storage_queue_run', 'storage_migrate_post') as $sibling) {
    check('the sibling action "' . $sibling . '" is still posted from the page', strpos($drawn['settings/storage.php'], 'value="' . $sibling . '"') !== false);
}

$missing = array();
foreach ($drawn as $view => $html) {
    $src = (string)file_get_contents(ABS_PATH . 'oc-admin/themes/modern/' . $view);
    preg_match_all("/getElementById\('([^']+)'\)/", $src, $ids);
    foreach (array_unique($ids[1]) as $id) {
        if (strpos($html, 'id="' . $id . '"') === false) {
            $missing[] = basename($view) . ': #' . $id;
        }
    }
    preg_match_all('/form\[name=([A-Za-z0-9_]+)\]/', $src, $names);
    foreach (array_unique($names[1]) as $name) {
        if (strpos($html, 'name="' . $name . '"') === false) {
            $missing[] = basename($view) . ': form ' . $name;
        }
    }
}
// The mail-server screen's test-email button and message box are declared as form
// actions now, so every id and form name a script names is drawn again.
pin(
    'every id and form name a script names is one the page draws',
    array(),
    $missing
);

harness_section('the view variables a replaced admin theme still reads');

// doView() resolves through osc_current_admin_theme_path(), so on an install with a forked
// admin theme it is that theme's copy of these views that runs, still reading the variables
// core exported before the forms were declared. View::_get() answers '' for a key nobody
// exported, and '' is falsy: the billing switch would draw unticked and the next save would
// turn billing off with a success message.
drive('CAdminSettingsMain', 'settings');
check('the general screen still exports its language list', is_array(__get('aLanguages')) && __get('aLanguages') !== array());
check('and its currency list', is_array(__get('aCurrencies')) && __get('aCurrencies') !== array());
drive('CAdminSettingsBilling', 'billing');
pin('the billing screen still exports whether billing is on', true, (bool)__get('billing_enabled'));
drive('CAdminSettingsSpamnBots', 'spamNbots');
pin('the spam screen still exports the Akismet key status', 3, (int)__get('akismet_status'));
seed_pref($admin, 'sitemap_number', '777', 'INTEGER');
seed_pref($admin, 'sitemap_categories', '1', 'BOOLEAN');
seed_pref($admin, 'sitemap_pages', '0', 'BOOLEAN');
seed_pref($admin, 'sitemap_cities', '1', 'BOOLEAN');
seed_pref($admin, 'custom_urls', '[{"url":"https://example.test/a","freq":"daily","lastmod":"2026-01-02"}]');
osc_reset_preferences();
file_put_contents($GLOBALS['fakeRoot'] . '/robots.txt', "User-agent: *\n");
drive('CAdminSettingsSitemap', 'sitemap');
pin(
    'the sitemap screen still exports its preferences, typed as they were',
    array(
        'sitemap_number'      => 777,
        'sitemap_categories'  => true,
        'sitemap_pages'       => false,
        'sitemap_cities'      => true,
        'sitemap_regions'     => false,
        'sitemap_countries'   => false,
        'sitemap_cat_regions' => false,
        'sitemap_cat_city'    => false,
    ),
    __get('prefs')
);
pin('the robots.txt body', "User-agent: *\n", __get('robots_content'));
pin('whether it can be written', true, __get('robots_writable'));
pin('whether it exists', true, __get('robots_exists'));
pin('the sitemap index address', 'https://example.test/sitemapindex.xml', __get('sitemap_index_url'));
pin(
    'and the custom URL list',
    array(array('url' => 'https://example.test/a', 'freq' => 'daily', 'lastmod' => '2026-01-02')),
    __get('custom_urls')
);
unlink($GLOBALS['fakeRoot'] . '/robots.txt');
drive('CAdminSettingsMedia', 'media');
pin('the media screen still exports the PHP upload limit, in kilobytes', MediaSettingsForm::uploadLimitKb(), __get('max_size_upload'));
foreach (array(
    array('storage_active', 's3'),
    array('storage_s3_provider', 'r2'),
    array('storage_s3_endpoint', 'https://acct.r2.cloudflarestorage.com'),
    array('storage_s3_region', 'auto'),
    array('storage_s3_bucket', 'shots'),
    array('storage_s3_access_key', 'AK'),
    array('storage_s3_secret_key', 'never-exported'),
    array('storage_s3_path_style', '1'),
    array('storage_s3_public_url', 'https://pub.example.test'),
    array('storage_s3_signed_urls', '0'),
    array('storage_s3_signed_ttl', '0'),
    array('storage_keep_local', ''),
) as $seed) {
    seed_pref($admin, $seed[0], $seed[1]);
}
osc_reset_preferences();
drive('CAdminSettingsStorage', 'storage');
pin(
    'the storage screen still exports its preferences, typed as they were',
    array(
        'storage_active'         => 's3',
        'storage_s3_provider'    => 'r2',
        'storage_s3_endpoint'    => 'https://acct.r2.cloudflarestorage.com',
        'storage_s3_region'      => 'auto',
        'storage_s3_bucket'      => 'shots',
        'storage_s3_access_key'  => 'AK',
        'storage_s3_path_style'  => true,
        'storage_s3_public_url'  => 'https://pub.example.test',
        'storage_s3_signed_urls' => false,
        'storage_s3_signed_ttl'  => 900,
        'storage_keep_local'     => 'all',
    ),
    __get('prefs')
);
pin('the provider presets', mindstellar\storage\ProviderPresets::PRESETS, __get('provider_presets'));
pin('the queue counts', array('pending', 'error', 'dead_letters'), array_keys((array)__get('queue_stats')));
pin('whether Better S3 is active', false, __get('better_s3_active'));
pin('and whether it is configured', false, __get('better_s3_configured'));
// Source scan, not proof: the keyword-block screen is a data table around its form and
// drawing one needs half the admin theme, so the export is held at source level here.
$keywordSrc = (string)file_get_contents(
    ABS_PATH . 'oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsKeywordBlock.php'
);
check(
    'and the keyword screen still exports its moderation switches',
    strpos($keywordSrc, "_exportVariableToView('moderation_prefs'") !== false
);
foreach (array(
    'keyword_spam_enabled',
    'keyword_spam_hard_block',
    'report_autoblock',
    'report_threshold',
    'enabled_recaptcha_reports',
) as $switch) {
    check('with "' . $switch . '" among them', strpos($keywordSrc, "'" . $switch . "'") !== false);
}

/* ---------------------------------------------------------------------------------------
 * Source scans. The cheap extra, never the proof: each says which kind it is.
 * ------------------------------------------------------------------------------------ */

harness_section('no regression to the hand-rolled path');

$screens = array(
    'settings/index.php'        => array('CAdminSettingsMain.php', array('pageTitle', 'contactEmail', 'auto_cron')),
    'settings/comments.php'     => array('CAdminSettingsComments.php', array('enabled_comments', 'comments_per_page')),
    'settings/mailserver.php'   => array('CAdminSettingsMailserver.php', array('mailserver_host', 'mailserver_password')),
    'settings/searches.php'     => array('CAdminSettingsLatestSearches.php', array('save_latest_searches', 'customPurge')),
    'settings/advanced.php'     => array('CAdminSettingsAdvanced.php', array('e_type', 's_host')),
    'settings/spamNbots.php'    => array('CAdminSettingsSpamnBots.php', array('akismetKey', 'login_throttle_window')),
    'settings/billing.php'      => array('CAdminSettingsBilling.php', array('billing_currency', 'billing_enabled')),
    'settings/keywordBlock.php' => array('CAdminSettingsKeywordBlock.php', array('report_threshold', 'report_autoblock')),
    'settings/permalinks.php'   => array(
        'CAdminSettingsPermalinks.php',
        array('rewrite_enabled', 'rewrite_item_url', 'seo_url_search_prefix'),
    ),
    'settings/sitemap.php'      => array(
        'CAdminSettingsSitemap.php',
        array('sitemap_number', 'sitemap_categories', 'sitemap_cat_city', 'sitemap_robots'),
        array('custom_urls'),
    ),
    'settings/media.php'        => array(
        'CAdminSettingsMedia.php',
        array('dimThumbnail', 'maxSizeKb', 'jpeg_quality', 'use_imagick', 'watermark_type', 'watermark_text', 'watermark_text_place', 'watermark_image_place', 'background_color', 'text_angle', 'watermark_image'),
    ),
    // The Better S3 adoption writes the same keys the form does, so its allowance is the case
    // it lives in, never a key name: a key-name allowance would hide the same write anywhere.
    'settings/storage.php'      => array(
        'CAdminSettingsStorage.php',
        array_keys(SettingsPageRegistry::instance()->fields(StorageSettingsForm::register())),
        array(),
        array('adopt_better_s3'),
    ),
);

/**
 * The source with one switch arm cut out: from its case label to the next label, default or
 * closing brace at the same depth. Null unless exactly one arm has that label.
 */
function without_case(string $src, string $label): ?array
{
    $lines = explode("\n", $src);
    $start = null;
    foreach ($lines as $i => $line) {
        if (preg_match("/^(\\s*)case\\s*\\(?\\s*'" . preg_quote($label, '/') . "'\\s*\\)?\\s*:/", $line, $m)) {
            if ($start !== null) {
                return null;
            }
            $start  = $i;
            $indent = strlen($m[1]);
        }
    }
    if ($start === null) {
        return null;
    }
    $end = count($lines);
    for ($i = $start + 1; $i < count($lines); $i++) {
        if (preg_match('/^(\\s*)(case\\b|default\\b|\\})/', $lines[$i], $m) && strlen($m[1]) <= $indent) {
            $end = $i;
            break;
        }
    }

    return array(
        'rest' => implode("\n", array_merge(array_slice($lines, 0, $start), array_slice($lines, $end))),
        'cut'  => implode("\n", array_slice($lines, $start, $end - $start)),
    );
}

foreach ($screens as $view => $screen) {
    [$controller, $fields] = $screen;
    $handWritten           = $screen[2] ?? array();
    $viewSrc = (string)file_get_contents(ABS_PATH . 'oc-admin/themes/modern/' . $view);
    $ctrlSrc = (string)file_get_contents(
        ABS_PATH . 'oc-includes/osclass/classes/controller/admin/settings/' . $controller
    );
    // A sibling action may write by hand; cut out the arm it lives in, and only that arm.
    foreach ($screen[3] ?? array() as $sibling) {
        $split = without_case($ctrlSrc, $sibling);
        check($controller . ' has exactly one "' . $sibling . '" arm to allow', $split !== null);
        if ($split === null) {
            continue;
        }
        check('the "' . $sibling . '" allowance holds no other action', preg_match_all('/\\bcase\\b/', $split['cut']) === 1);
        $ctrlSrc = $split['rest'];
    }

    check(basename($view) . ' draws its form from the declaration', strpos($viewSrc, 'osc_admin_settings_form(') !== false);
    foreach ($fields as $field) {
        check(
            $controller . ' no longer reads "' . $field . '" out of the request',
            !preg_match('/Params::getParam[A-Za-z]*\(\s*\047' . preg_quote($field, '/') . '\047/', $ctrlSrc)
        );
        check(
            basename($view) . ' no longer draws "' . $field . '" by hand',
            !preg_match("/'name'\\s*=>\\s*'" . preg_quote($field, '/') . "'/", $viewSrc)
        );
    }
    // A screen may keep a list that is not a settings form, and it may write that list and
    // nothing else by hand.
    preg_match_all("/osc_set_preference\\(\\s*('([^']*)')?/", $ctrlSrc, $writes);
    pin(
        $controller . ' writes no preference by hand' . ($handWritten === array() ? '' : ' but ' . implode(', ', $handWritten)),
        array(),
        array_values(array_diff($writes[2], $handWritten))
    );
}

// The save arm itself, read on its own: it holds nothing but the declared save.
$storageSrc = (string)file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsStorage.php');
$storagePost = without_case($storageSrc, 'storage_post');
check('CAdminSettingsStorage.php has one storage_post arm', $storagePost !== null);
$storagePost = (string)($storagePost['cut'] ?? '');
check('storage_post saves through the declaration', strpos($storagePost, 'CoreSettings::attempt(StorageSettingsForm::register())') !== false);
check('and writes no preference of its own', strpos($storagePost, 'osc_set_preference') === false);
check('and reads nothing out of the request', strpos($storagePost, 'Params::') === false);
check('the adoption still writes its keys by hand, where the allowance says', strpos((string)(without_case($storageSrc, 'adopt_better_s3')['cut'] ?? ''), "osc_set_preference('storage_s3_secret_key'") !== false);

$mediaView = (string)file_get_contents(ABS_PATH . 'oc-admin/themes/modern/settings/media.php');
check('media.php no longer validates by hand what the declaration refuses', strpos($mediaView, 'oscValidateForm') === false);
check('nor shows and hides the watermark blocks by hand', strpos($mediaView, 'style.display') === false);
check(
    'CAdminSettingsMedia.php reads no upload of its own',
    strpos((string)file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsMedia.php'), 'getFiles') === false
);

// The four keys that are not the field's own name are the ones a rename would break
// silently, so the declarations are held to spelling them.
foreach (array(
    'MainSettingsForm.php'         => array('maxLatestItems@home', 'defaultResultsPerPage@search', 'contact_attachment'),
    'LatestSearchSettingsForm.php' => array('purge_latest_searches'),
    'AdvancedSettingsForm.php'     => array('subdomain_type', 'subdomain_host'),
    'SpamSettingsForm.php'         => array('recaptcha_version'),
    'PermalinkSettingsForm.php'    => array('rewriteEnabled'),
    'MediaSettingsForm.php'        => array('watermark_place', 'watermark_text_options', "'/watermark.png'"),
) as $file => $keys) {
    $src = (string)file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/admin/form/' . $file);
    foreach ($keys as $key) {
        check($file . ' still names the "' . $key . '" preference', strpos($src, $key) !== false);
    }
}

// One place decides how a refused save is reported, or eight screens drift into reporting
// it eight ways.
$shared = (string)file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/admin/form/CoreSettings.php');
check('the shared helper is what flashes a refusal', strpos($shared, 'osc_add_flash_warning_message') !== false);
foreach (array_column($screens, 0) as $controller) {
    $src = (string)file_get_contents(
        ABS_PATH . 'oc-includes/osclass/classes/controller/admin/settings/' . $controller
    );
    check($controller . ' reports one through the shared helper', strpos($src, 'CoreSettings::attempt(') !== false);
}

exit(harness_result());
