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
 * Characterization pins for the search page controller (CWebSearch).
 *
 * Runs the real constructor and doModel() against a seeded site, one request per
 * scenario, and records every view export and every hook/filter in the order they
 * happen. The recorded lists are compared with tests/fixtures/websearch-events.php, so a
 * refactor that changes an exported value, a hook argument or the order of either fails.
 *
 * BaseModel is replaced by a recorder (redirects and 404s end the scenario instead of
 * the process); everything CWebSearch itself does runs for real.
 *
 * Usage:  php tests/models/websearch.php [--write]   (standalone, own scratch database)
 *         php tests/run-models.php websearch         (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';

// This file replaces BaseModel and loads page helpers that earlier files in the suite
// stub or load differently, so under the runner it runs in a process of its own.
if (defined('MODELS_RUNNER')) {
    $wsOut = array();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $wsOut, $wsCode);
    $wsOut = implode("\n", $wsOut);
    echo $wsOut, "\n";
    $wsFound = preg_match('/RESULT: (\d+) passed, (\d+) failed/', $wsOut, $wsM) === 1;
    $wsFail  = $wsFound ? (int)$wsM[2] : 0;
    if (!$wsFound || ($wsCode !== 0 && $wsFail === 0)) {
        $wsFail = max(1, $wsFail);
    }
    $GLOBALS['okCount']   += $wsFound ? (int)$wsM[1] : 0;
    $GLOBALS['failCount'] += $wsFail;
    if ($wsFail > 0) {
        $GLOBALS['failLabels'][] = 'websearch: ' . $wsFail . ' failed (exit ' . $wsCode . ')';
    }

    return;
}

require_once __DIR__ . '/../lib/scratchdb.php';

$admin = scratchdb_session('osc_models_websearch');

foreach (array(
    'OSC_CACHE_TTL' => 60,
    'WEB_PATH'      => 'http://localhost/',
    'REL_WEB_URL'   => '/',
    'PLUGINS_PATH'  => ABS_PATH . 'oc-content/plugins/',
    'OC_ADMIN'      => false,
    'DEMO'          => true,
    'OSC_DEBUG'     => false,
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/../lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';
require_once ABS_PATH . 'oc-includes/osclass/formatting.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSearch.php';

// Page-level helpers that live in files a model test cannot load (hDefines, hTheme,
// hHttpCache, hBilling). Recorded where the call itself is part of the contract.
$GLOBALS['ws_events'] = array();
if (!function_exists('osc_is_ssl')) {
    function osc_is_ssl()
    {
        return false;
    }
}
if (!function_exists('osc_subdomain_type')) {
    function osc_subdomain_type()
    {
        return '';
    }
}
if (!function_exists('osc_subdomain_host')) {
    function osc_subdomain_host()
    {
        return '';
    }
}
if (!function_exists('osc_is_subdomain')) {
    function osc_is_subdomain()
    {
        return false;
    }
}
if (!function_exists('osc_category_id')) {
    function osc_category_id()
    {
        return 0;
    }
}
if (!function_exists('osc_category_slug')) {
    function osc_category_slug()
    {
        return '';
    }
}
if (!function_exists('osc_remove_slash')) {
    function osc_remove_slash($var)
    {
        return is_string($var) ? stripslashes($var) : $var;
    }
}
if (!function_exists('osc_prune_array')) {
    function osc_prune_array(&$input)
    {
        \mindstellar\utility\Utils::pruneArray($input);
    }
}
if (!function_exists('osc_prime_item_upgrades')) {
    function osc_prime_item_upgrades(array $items)
    {
        $GLOBALS['ws_events'][] = 'call osc_prime_item_upgrades(' . ws_describe('items', $items) . ')';
    }
}
if (!function_exists('osc_mark_response_cacheable')) {
    function osc_mark_response_cacheable()
    {
        $GLOBALS['ws_events'][] = 'call osc_mark_response_cacheable';
    }
}
if (!function_exists('osc_locate_template')) {
    function osc_locate_template($candidates, $type = '')
    {
        return implode('|', (array)$candidates);
    }
}
if (!function_exists('osc_current_web_theme_path')) {
    function osc_current_web_theme_path($file = '')
    {
        $GLOBALS['ws_events'][] = 'render ' . $file;
    }
}
if (!function_exists('osc_item_url')) {
    function osc_item_url($locale = '')
    {
        return WEB_PATH . 'item/' . osc_item_id();
    }
}

/** Ends a scenario where the controller would have sent a redirect and exited. */
class WsRedirect extends Exception
{
}

/** Ends a scenario where the controller would have rendered the 404 page and exited. */
class WsNotFound extends Exception
{
}

/** Stand-in for the base controller: records exports, turns redirect/404 into exceptions. */
abstract class BaseModel
{
    protected $page;
    protected $action;
    protected $ajax;
    protected $time;

    public function __construct()
    {
        $this->page   = Params::getParam('page');
        $this->action = Params::getParam('action');
        $this->ajax   = false;
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['ws_events'][] = 'redirect ' . ($code === null ? '302' : $code) . ' ' . $url;
        throw new WsRedirect($url);
    }

    public function _exportVariableToView($key, $value)
    {
        $GLOBALS['ws_events'][] = 'export ' . $key . ' = ' . ws_describe($key, $value);
        View::newInstance()->_exportVariableToView($key, $value);
    }

    public function do404()
    {
        $GLOBALS['ws_events'][] = '404';
        throw new WsNotFound();
    }

    abstract protected function doModel();

    abstract protected function doView($file);
}

/**
 * A short, stable rendering of an exported value or hook argument.
 *
 * @param string $key
 * @param mixed  $value
 *
 * @return string
 */
function ws_describe(string $key, $value): string
{
    if ($key === 'items' && is_array($value)) {
        return 'items[' . implode(',', array_map(static fn ($r) => (int)($r['pk_i_id'] ?? 0), $value)) . ']';
    }
    if ($key === 'search_alert') {
        return is_string($value) && $value !== '' ? 'string(encrypted)' : describe($value);
    }
    if (is_object($value)) {
        return 'object(' . get_class($value) . ')';
    }

    return describe($value);
}

/**
 * The criteria, sort and paging a Search holds, from its toJson().
 *
 * @param \Search $search
 *
 * @return string
 */
function ws_search_state(\Search $search): string
{
    $j = json_decode($search->toJson(), true);

    return 'state(cats=' . implode(',', (array)$j['aCategories'])
        . ' pattern=' . ($j['withPattern'] ? $j['sPattern'] : '-')
        . ' conditions=' . count((array)$j['no_catched_conditions'])
        . ' order=' . $j['order_column'] . ' ' . $j['order_direction']
        . ' page=' . $j['limit_init'] . '+' . $j['results_per_page'] . ')';
}

/* ----------------------------------------------------------------------------
 * Hook spy: every name the search page fires, plus the ones its collaborators fire.
 * ------------------------------------------------------------------------- */
$GLOBALS['ws_backend'] = null;
foreach (array('before_search', 'search', 'after_search', 'feed', 'feed_atom', 'before_html', 'after_html', 'search_conditions') as $hookName) {
    osc_add_hook($hookName, static function (...$args) use ($hookName) {
        $parts = array();
        foreach ($args as $i => $arg) {
            if ($hookName === 'search_conditions' && $i === 0) {
                $arg = array_keys($arg);
            }
            $parts[] = ws_describe($hookName === 'feed_atom' ? 'items' : '', $arg);
        }
        if ($hookName === 'search_conditions') {
            // What the Search holds when listeners run: criteria, sort and page already set.
            $parts[] = ws_search_state($args[1]);
        }
        $GLOBALS['ws_events'][] = 'hook ' . $hookName . '(' . implode(', ', $parts) . ')';
    }, 1);
}
foreach (array('search_pattern', 'save_latest_searches_pattern', 'search_results', 'pre_show_items', 'rss_feed_item') as $filterName) {
    osc_add_filter($filterName, static function ($content, ...$args) use ($filterName) {
        $parts = array(ws_describe($filterName === 'pre_show_items' ? 'items' : '', $content));
        foreach ($args as $i => $arg) {
            if ($filterName === 'search_results' && $i === 1) {
                $arg = array_keys($arg);
            }
            if ($filterName === 'rss_feed_item' && is_array($arg)) {
                $arg = (int)$arg['pk_i_id'];
            }
            $parts[] = ws_describe('', $arg);
        }
        if ($filterName === 'rss_feed_item') {
            $parts[0] = describe($content['title'] ?? null);
        }
        $GLOBALS['ws_events'][] = 'filter ' . $filterName . '(' . implode(', ', $parts) . ')';
        if ($filterName === 'search_results' && $GLOBALS['ws_backend'] !== null) {
            return $GLOBALS['ws_backend'];
        }

        return $content;
    }, 1);
}

// One entry per SQL statement the search builds, so a result served from the cache shows
// up as a request with no queries.
osc_add_filter('sql_search_conditions', static function ($conditions) {
    $GLOBALS['ws_events'][] = 'sql query';

    return $conditions;
}, 1);

/* ----------------------------------------------------------------------------
 * Fixture: three categories (one empty), a region and city, four items.
 * ------------------------------------------------------------------------- */
$prefix   = DB_TABLE_PREFIX;
$locale   = seed_locale($admin);
seed_currency($admin);
$country  = seed_country($admin, 'US', 'United States');
$region   = seed_region($admin, $country, 'Alpha');
$city     = seed_city($admin, $region, 'Aville', $country);
$catCars  = seed_category($admin, 'Cars', null, $locale);
$catBikes = seed_category($admin, 'Bikes', null, $locale);
$catEmpty = seed_category($admin, 'Boats', null, $locale);
$user     = seed_user($admin, 'seller', 'seller@example.test');

$items = array(
    seed_item($admin, $catCars, $user, 'Vintage Roadster', 5000.0, 1, 1, $locale, $country),
    seed_item($admin, $catCars, $user, 'Family Sedan', 12000.0, 1, 1, $locale, $country),
    seed_item($admin, $catCars, $user, 'Vintage Coupe', 9000.0, 1, 1, $locale, $country),
    seed_item($admin, $catBikes, $user, 'Racing Bike', 800.0, 1, 1, $locale, $country),
);
foreach ($items as $n => $id) {
    // Distinct dates, so date sorts are deterministic.
    $admin->query("UPDATE {$prefix}t_item SET dt_pub_date = DATE_SUB(NOW(), INTERVAL " . (10 - $n)
        . " DAY) WHERE pk_i_id = " . (int)$id);
    $admin->query("UPDATE {$prefix}t_item_location SET fk_i_region_id = $region, s_region = 'Alpha',"
        . " fk_i_city_id = $city, s_city = 'Aville' WHERE fk_i_item_id = " . (int)$id);
}
$admin->query("INSERT INTO {$prefix}t_category_slug_history (fk_i_category_id, fk_c_locale_code, s_slug, dt_date)"
    . " VALUES ($catCars, '$locale', 'old-cars', NOW())");

if (class_exists('Object_Cache_Factory')) {
    Object_Cache_Factory::newInstance()->flush();
}
foreach (array('Category', 'Search') as $singleton) {
    $reset = new ReflectionProperty($singleton, 'instance');
    if (PHP_VERSION_ID < 80100) {
        $reset->setAccessible(true);
    }
    $reset->setValue(null, null);
}

// The pattern path filters on the visitor's locale; the fixture is en_US.
$_COOKIE['oc_userLocale'] = 'en_US';

$basePrefs = array(
    'rewrite_cat_url'              => '{CATEGORIES}',
    'rewrite_search_url'           => 'search',
    'rewrite_search_country'       => 'country',
    'rewrite_search_region'        => 'region',
    'rewrite_search_city'          => 'city',
    'rewrite_search_city_area'     => 'cityarea',
    'rewrite_search_category'      => 'category',
    'rewrite_search_user'          => 'user',
    'rewrite_search_pattern'       => 'pattern',
    'seo_url_search_prefix'        => '',
    'defaultResultsPerPage@search' => '12',
    'maxResultsPerPage@search'     => '50',
    'defaultShowAs@search'         => 'list',
    'defaultOrderField@search'     => 'dt_pub_date',
    'defaultOrderType@search'      => '1',
    'save_latest_searches'         => '0',
    'num_rss_items'                => '50',
    'pageTitle'                    => 'Test site',
);

/**
 * Run one request through CWebSearch and return what it did.
 *
 * @param string               $uri     REQUEST_URI without the leading slash
 * @param array<string,mixed>  $get     the request params the router would have set
 * @param array<string,string> $prefs   preference overrides
 *
 * @return string[]
 */
$run = static function (string $uri, array $get, array $prefs = array()) use ($basePrefs): array {
    foreach (array_merge($basePrefs, array('rewriteEnabled' => '0'), $prefs) as $k => $v) {
        Preference::newInstance()->set($k, $v);
    }
    $reset = new ReflectionProperty('Search', 'instance');
    if (PHP_VERSION_ID < 80100) {
        $reset->setAccessible(true);
    }
    $reset->setValue(null, null);
    $viewReset = new ReflectionProperty('View', 'instance');
    if (PHP_VERSION_ID < 80100) {
        $viewReset->setAccessible(true);
    }
    $viewReset->setValue(null, null);

    $_GET                    = $get;
    $_POST                   = array();
    $_SERVER['REQUEST_URI']  = '/' . $uri;
    $_SERVER['HTTP_HOST']    = 'localhost';
    Params::init();

    $GLOBALS['ws_events'] = array();
    ob_start();
    try {
        $controller = new CWebSearch();
        $controller->doModel();
        $GLOBALS['ws_events'][] = 'end';
    } catch (WsRedirect | WsNotFound $e) {
        // recorded by the stand-in
    } finally {
        $out = ob_get_clean();
    }
    if ($out !== '') {
        $GLOBALS['ws_events'][] = 'output ' . (strpos($out, '<rss') !== false
                ? 'rss items=' . substr_count($out, '<item>')
                : strlen($out) . ' bytes');
    }

    return $GLOBALS['ws_events'];
};

$scenarios = array(
    // rewrite off: the constructor leaves index.php URLs alone
    'category, rewrite off'              => array('index.php?page=search&sCategory=' . $catCars, array('page' => 'search', 'sCategory' => (string)$catCars)),
    'pattern, sort, page, show-as'       => array(
        'index.php?page=search&sPattern=vintage&sOrder=i_price&iOrderType=asc&iPage=1&iPagesize=1&sShowAs=gallery',
        array('page' => 'search', 'sPattern' => 'vintage', 'sOrder' => 'i_price', 'iOrderType' => 'asc', 'iPage' => '1', 'iPagesize' => '1', 'sShowAs' => 'gallery'),
    ),
    'page 2 of 2'                        => array(
        'index.php?page=search&sCategory=' . $catCars . '&iPage=2&iPagesize=2',
        array('page' => 'search', 'sCategory' => (string)$catCars, 'iPage' => '2', 'iPagesize' => '2'),
    ),
    'page size over the cap'             => array(
        'index.php?page=search&iPagesize=99',
        array('page' => 'search', 'iPagesize' => '99'),
    ),
    'page past the end'                  => array(
        'index.php?page=search&sCategory=' . $catCars . '&iPage=5',
        array('page' => 'search', 'sCategory' => (string)$catCars, 'iPage' => '5'),
    ),
    'relevance without a pattern'        => array(
        'index.php?page=search&sOrder=relevance&iOrderType=desc&sShowAs=bogus',
        array('page' => 'search', 'sOrder' => 'relevance', 'iOrderType' => 'desc', 'sShowAs' => 'bogus'),
    ),
    'bad order, bad order type'          => array(
        'index.php?page=search&sOrder=s_secret&iOrderType=sideways',
        array('page' => 'search', 'sOrder' => 's_secret', 'iOrderType' => 'sideways'),
    ),
    'location names, price, pic'         => array(
        'index.php?page=search&sCountry=US&sRegion=' . $region . '&sCity=' . $city . '&sPriceMin=100&sPriceMax=20000',
        array('page' => 'search', 'sCountry' => 'US', 'sRegion' => (string)$region, 'sCity' => (string)$city, 'sPriceMin' => '100', 'sPriceMax' => '20000'),
    ),
    'empty refined search'               => array('index.php?page=search&sPattern=zzznomatch', array('page' => 'search', 'sPattern' => 'zzznomatch')),
    'empty browse page'                  => array('index.php?page=search&sCategory=' . $catEmpty, array('page' => 'search', 'sCategory' => (string)$catEmpty)),
    'unknown category'                   => array('index.php?page=search&sCategory=nosuch', array('page' => 'search', 'sCategory' => 'nosuch')),
    'non-canonical URL redirects'        => array('index.php?page=search&sPattern=bike&sCategory=' . $catBikes, array('page' => 'search', 'sCategory' => (string)$catBikes, 'sPattern' => 'bike')),
    'rss feed'                           => array('index.php?page=search&sCategory=' . $catCars . '&sFeed=rss', array('page' => 'search', 'sCategory' => (string)$catCars, 'sFeed' => 'rss')),
    'named feed'                         => array('index.php?page=search&sFeed=atom', array('page' => 'search', 'sFeed' => 'atom')),
    'latest searches saved on page 1'    => array('index.php?page=search&sPattern=bike', array('page' => 'search', 'sPattern' => 'bike'), array('save_latest_searches' => '1')),
    // rewrite on: the constructor parses the friendly URI
    'friendly search params'             => array('search/pattern,vintage/category,' . $catCars, array('page' => 'search', 'sParams' => 'pattern,vintage/category,' . $catCars), array('rewriteEnabled' => '1')),
    'friendly custom-field params'       => array('search/meta7,red/meta8-from,3', array('page' => 'search', 'sParams' => 'meta7,red/meta8-from,3'), array('rewriteEnabled' => '1')),
    'friendly category'                  => array('cars', array('page' => 'search', 'sCategory' => 'cars'), array('rewriteEnabled' => '1')),
    'friendly category page 2'           => array('cars/2', array('page' => 'search', 'sCategory' => 'cars'), array('rewriteEnabled' => '1')),
    'friendly category page 1 redirects' => array('cars/1', array('page' => 'search', 'sCategory' => 'cars'), array('rewriteEnabled' => '1')),
    'friendly nested category path'      => array('vehicles/cars', array('page' => 'search', 'sCategory' => 'vehicles/cars'), array('rewriteEnabled' => '1')),
    'bare slug, no sCategory'            => array('bikes', array('page' => 'search'), array('rewriteEnabled' => '1')),
    'unknown slug'                       => array('nosuch', array('page' => 'search'), array('rewriteEnabled' => '1')),
    'old category slug'                  => array('old-cars', array('page' => 'search', 'sCategory' => 'old-cars'), array('rewriteEnabled' => '1')),
    'friendly region'                    => array('cars_alpha-r' . $region, array('page' => 'search'), array('rewriteEnabled' => '1')),
    'friendly city'                      => array('aville-c' . $city, array('page' => 'search', 'sCategory' => 'x'), array('rewriteEnabled' => '1')),
    'unknown region id'                  => array('alpha-r99999', array('page' => 'search'), array('rewriteEnabled' => '1')),
    'unknown city id'                    => array('aville-c99999', array('page' => 'search'), array('rewriteEnabled' => '1')),
    'numeric uri'                        => array('2', array('page' => 'search'), array('rewriteEnabled' => '1')),
    'feed uri'                           => array('feed', array('page' => 'search', 'sFeed' => ''), array('rewriteEnabled' => '1')),
    'bare search with query string'      => array('search?sPattern=x', array('page' => 'search', 'sPattern' => 'x'), array('rewriteEnabled' => '1')),
);

$actual = array();
foreach ($scenarios as $label => $s) {
    $actual[$label] = $run($s[0], $s[1], $s[2] ?? array());
}

// A backend answering search_results takes over: core's search and cache are skipped.
$GLOBALS['ws_backend'] = array('items' => array(array('pk_i_id' => $items[3])), 'total' => 7);
$actual['search_results backend answers'] = $run('index.php?page=search&sPattern=bike', array('page' => 'search', 'sPattern' => 'bike'));
$GLOBALS['ws_backend'] = null;

// The same search twice is answered from the cache; a bumped search generation recomputes it.
$cacheUri = 'index.php?page=search&sCategory=' . $catBikes . '&iPagesize=3';
$cacheGet = array('page' => 'search', 'sCategory' => (string)$catBikes, 'iPagesize' => '3');
$actual['cache: first run queries']        = $run($cacheUri, $cacheGet);
$actual['cache: repeat is served cached']  = $run($cacheUri, $cacheGet);
osc_invalidate_search_cache();
$actual['cache: new generation recomputes'] = $run($cacheUri, $cacheGet);

$latest = (int)$admin->query("SELECT COUNT(*) AS n FROM {$prefix}t_latest_searches")->fetch_assoc()['n'];

$fixture = __DIR__ . '/../fixtures/websearch-events.php';
$ids     = array('cars' => $catCars, 'bikes' => $catBikes, 'boats' => $catEmpty, 'region' => $region, 'city' => $city, 'items' => $items);

if (in_array('--write', $argv ?? array(), true)) {
    file_put_contents($fixture, "<?php\n\n// Generated by: php tests/models/websearch.php --write\nreturn "
        . var_export(array('ids' => $ids, 'events' => $actual, 'latest_searches' => $latest), true) . ";\n");
    echo "wrote tests/fixtures/websearch-events.php\n";
}

$expected = require $fixture;

harness_section('websearch: fixture ids');
pin('the seeded ids are the ones the fixture was written with', $expected['ids'], $ids);

harness_section('websearch: exports and hooks per request');
foreach ($actual as $label => $events) {
    pin($label, $expected['events'][$label] ?? null, $events);
    if (($expected['events'][$label] ?? null) !== $events) {
        $want = $expected['events'][$label] ?? array();
        foreach (array_keys($want + $events) as $i) {
            if (($want[$i] ?? null) !== ($events[$i] ?? null)) {
                echo '        first difference at #' . $i . ': expected ' . describe($want[$i] ?? null)
                    . "\n                                 got " . describe($events[$i] ?? null) . "\n";
                break;
            }
        }
    }
}
pin('no scenario was added or dropped', array_keys($expected['events']), array_keys($actual));

harness_section('websearch: result cache');
$queries = static fn (array $events): int => count(array_keys($events, 'sql query', true));
check('the first run queries the database', $queries($actual['cache: first run queries']) > 0);
pin('the repeat runs no query', 0, $queries($actual['cache: repeat is served cached']));
check(
    'the repeat exports the same items',
    in_array('export items = items[4]', $actual['cache: repeat is served cached'], true)
);
pin(
    'after osc_invalidate_search_cache() the search runs again',
    $queries($actual['cache: first run queries']),
    $queries($actual['cache: new generation recomputes'])
);

harness_section('websearch: side effects');
pin('latest searches: one row from the page-1 pattern search', $expected['latest_searches'], $latest);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/websearch.php */
