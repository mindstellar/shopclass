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
 * Parity pins for the Search model: a broad matrix of searches run against one seeded
 * site, each pinned to the listings it returns, its total and the number of statements
 * it sends. The expected values live in tests/fixtures/search-parity.php (and
 * search-parity-admin.php for the admin side), written from the code before a refactor,
 * so any change in results or query count fails.
 *
 * Usage:  php tests/models/search-parity.php [--admin] [--write] [--perf N] [--dump FILE]
 *         php tests/run-models.php search-parity      (runs both sides)
 *
 * --admin runs it as the admin panel (OC_ADMIN), --write rewrites the fixture, --perf N
 * runs the matrix N more times and prints the time, --dump FILE writes every returned
 * row (dates left out) as JSON for a diff.
 */

require_once __DIR__ . '/../lib/harness.php';

// It stubs page helpers other model files load for real, so under the runner it runs alone.
if (defined('MODELS_RUNNER')) {
    foreach (array('', ' --admin') as $spSide) {
        $spOut = array();
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . $spSide . ' 2>&1', $spOut, $spCode);
        $spOut = implode("\n", $spOut);
        echo $spOut, "\n";
        $spFound = preg_match('/RESULT: (\d+) passed, (\d+) failed/', $spOut, $spM) === 1;
        $spFail  = $spFound ? (int)$spM[2] : 0;
        if (!$spFound || ($spCode !== 0 && $spFail === 0)) {
            $spFail = max(1, $spFail);
        }
        $GLOBALS['okCount']   += $spFound ? (int)$spM[1] : 0;
        $GLOBALS['failCount'] += $spFail;
        if ($spFail > 0) {
            $GLOBALS['failLabels'][] = 'search-parity' . $spSide . ': ' . $spFail . ' failed (exit ' . $spCode . ')';
        }
    }

    return;
}

require_once __DIR__ . '/../lib/scratchdb.php';

$adminSide = in_array('--admin', array_slice($argv, 1), true);
$admin     = scratchdb_session('osc_models_search_parity' . ($adminSide ? '_admin' : ''));

foreach (array(
    'OSC_CACHE_TTL' => 60,
    'WEB_PATH'      => 'http://localhost/',
    'REL_WEB_URL'   => '/',
    'PLUGINS_PATH'  => ABS_PATH . 'oc-content/plugins/',
    'OC_ADMIN'      => $adminSide,
    'DEMO'          => true,
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
if (!function_exists('osc_prime_item_upgrades')) {
    function osc_prime_item_upgrades(array $items)
    {
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/../lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSearch.php';

use mindstellar\search\AlertEnvelope;
use mindstellar\search\AlertReplay;
use mindstellar\search\SearchBuilder;
use mindstellar\search\SearchCriteria;
use mindstellar\search\SearchRunner;

$args    = array_slice($argv, 1);
$write   = in_array('--write', $args, true);
$perfAt  = array_search('--perf', $args, true);
$perfN   = $perfAt !== false ? max(1, (int)($args[$perfAt + 1] ?? 5)) : 0;
$dumpAt  = array_search('--dump', $args, true);
$dumpTo  = $dumpAt !== false ? (string)($args[$dumpAt + 1] ?? '') : '';
$fixture = dirname(__DIR__) . '/fixtures/search-parity' . ($adminSide ? '-admin' : '') . '.php';
$p       = DB_TABLE_PREFIX;

$_COOKIE['oc_userLocale'] = 'en_US';

/* ----------------------------------------------------------------------------
 * Fixture: two countries, three regions, three cities, two city areas; a category
 * tree (Cars > Sports Cars, Bikes, Home); three users; ten listings with distinct
 * dates and prices, some premium, some with photos, plus a disabled, an expired
 * and a spam one; custom fields of every type on Cars.
 * ------------------------------------------------------------------------- */
$locale = seed_locale($admin);
seed_locale($admin, 'es_ES', 'Spanish');
seed_currency($admin);
$us = seed_country($admin, 'US', 'United States');
$ca = seed_country($admin, 'CA', 'Canada');
$rAlpha = seed_region($admin, $us, 'Alpha');
$rBeta  = seed_region($admin, $us, 'Beta');
$rGamma = seed_region($admin, $ca, 'Gamma');
$cA = seed_city($admin, $rAlpha, 'Aville', $us);
$cB = seed_city($admin, $rBeta, 'Bville', $us);
$cG = seed_city($admin, $rGamma, 'Gtown', $ca);
$admin->query("INSERT INTO {$p}t_city_area (pk_i_id, fk_i_city_id, s_name) VALUES (1, $cA, 'Downtown'), (2, $cB, 'Uptown')");

$catCars  = seed_category($admin, 'Cars', null, $locale);
$catSport = seed_category($admin, 'Sports Cars', $catCars, $locale);
$catBikes = seed_category($admin, 'Bikes', null, $locale);
$catHome  = seed_category($admin, 'Home', null, $locale);

$u1 = seed_user($admin, 'seller1', 'seller1@example.test');
$u2 = seed_user($admin, 'seller2', 'seller2@example.test');
$u3 = seed_user($admin, 'seller3', 'seller3@example.test');

$fields = array();
foreach (array('TEXT', 'DROPDOWN', 'RADIO', 'CHECKBOX', 'DATE', 'DATEINTERVAL', 'NUMBER', 'TEXTAREA', 'URL') as $type) {
    $fields[$type] = seed_exec(
        $admin,
        "INSERT INTO {$p}t_meta_fields (s_name, s_slug, e_type, b_required, b_searchable, i_position) VALUES (?, ?, ?, 0, 1, 0)",
        'sss',
        array('F ' . $type, strtolower('f-' . $type), $type)
    );
    seed_exec($admin, "INSERT INTO {$p}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)", 'ii', array($catCars, $fields[$type]));
    seed_exec($admin, "INSERT INTO {$p}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)", 'ii', array($catSport, $fields[$type]));
}
$day = mktime(12, 0, 0, 6, 15, 2026);

$label = array();
$mk = static function (array $o) use ($admin, $p, $locale, &$label): int {
    $o += array(
        'user' => null, 'premium' => 0, 'pic' => false, 'area' => null, 'areaName' => null, 'enabled' => 1,
        'spam' => 0, 'expired' => false, 'email' => 'seller@example.test', 'desc' => null, 'es' => null,
    );
    $id = seed_exec(
        $admin,
        "INSERT INTO {$p}t_item
         (fk_i_user_id, fk_i_category_id, dt_pub_date, dt_mod_date, f_price, i_price, fk_c_currency_code,
          s_contact_name, s_contact_email, s_ip, b_premium, b_enabled, b_active, b_spam, s_secret, dt_expiration)
         VALUES (?, ?, DATE_SUB(NOW(), INTERVAL ? HOUR), DATE_SUB(NOW(), INTERVAL ? MINUTE), ?, ?, 'USD', 'Seller', ?, '127.0.0.1', ?, ?, 1, ?, ?,
                 IF(?, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY)))",
        'iiiidisiiisi',
        array($o['user'], $o['cat'], $o['hours'], 20 - $o['hours'], $o['price'], (int)round($o['price'] * 1000000), $o['email'],
              $o['premium'], $o['enabled'], $o['spam'], 'sec' . $o['key'], $o['expired'] ? 1 : 0)
    );
    seed_exec(
        $admin,
        "INSERT INTO {$p}t_item_description (fk_i_item_id, fk_c_locale_code, s_title, s_description) VALUES (?, ?, ?, ?)",
        'isss',
        array($id, $locale, $o['title'], $o['desc'] ?? ($o['title'] . ' body vintage collectible'))
    );
    if ($o['es'] !== null) {
        seed_exec(
            $admin,
            "INSERT INTO {$p}t_item_description (fk_i_item_id, fk_c_locale_code, s_title, s_description) VALUES (?, 'es_ES', ?, ?)",
            'iss',
            array($id, $o['es'], $o['es'] . ' cuerpo')
        );
    }
    seed_exec(
        $admin,
        "INSERT INTO {$p}t_item_location (fk_i_item_id, fk_c_country_code, s_country, fk_i_region_id, s_region,
          fk_i_city_id, s_city, fk_i_city_area_id, s_city_area) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        'issisisis',
        array($id, $o['loc'][0], $o['loc'][1], $o['loc'][2], $o['loc'][3], $o['loc'][4], $o['loc'][5], $o['area'], $o['areaName'])
    );
    seed_exec($admin, "INSERT INTO {$p}t_item_stats (fk_i_item_id, dt_date) VALUES (?, CURDATE())", 'i', array($id));
    if ($o['pic']) {
        seed_photo($admin, $id, array('s_path' => '/p.jpg'));
        seed_photo($admin, $id, array('s_name' => 'photo2', 's_path' => '/p2.jpg'));
    }
    $label[$id] = $o['key'];

    return $id;
};
$locA = array($us, 'United States', $rAlpha, 'Alpha', $cA, 'Aville');
$locB = array($us, 'United States', $rBeta, 'Beta', $cB, 'Bville');
$locG = array($ca, 'Canada', $rGamma, 'Gamma', $cG, 'Gtown');

$car1  = $mk(array('key' => 'car1', 'title' => 'Red Roadster', 'cat' => $catCars, 'price' => 5000, 'hours' => 1, 'user' => $u1, 'loc' => $locA, 'area' => 1, 'areaName' => 'Downtown', 'pic' => true));
$car2  = $mk(array('key' => 'car2', 'title' => 'Blue Sedan', 'cat' => $catCars, 'price' => 12000, 'hours' => 2, 'user' => $u1, 'loc' => $locA, 'premium' => 1));
$sport = $mk(array('key' => 'sport', 'title' => 'Yellow Racer', 'cat' => $catSport, 'price' => 30000, 'hours' => 3, 'user' => $u2, 'loc' => $locB, 'area' => 2, 'areaName' => 'Uptown', 'pic' => true, 'premium' => 1));
$bike1 = $mk(array('key' => 'bike1', 'title' => 'Mountain Bike', 'cat' => $catBikes, 'price' => 800, 'hours' => 4, 'user' => $u2, 'loc' => $locB, 'pic' => true, 'es' => 'Bicicleta montana'));
$bike2 = $mk(array('key' => 'bike2', 'title' => 'Racing Bike', 'cat' => $catBikes, 'price' => 2500, 'hours' => 5, 'user' => $u3, 'loc' => $locG, 'premium' => 1));
$car3  = $mk(array('key' => 'car3', 'title' => 'Green Coupe', 'cat' => $catCars, 'price' => 9000, 'hours' => 6, 'user' => $u3, 'loc' => $locG, 'pic' => true, 'desc' => "Green Coupe it's 100% original_paint"));
$sofa  = $mk(array('key' => 'sofa', 'title' => 'Free Sofa', 'cat' => $catHome, 'price' => 0, 'hours' => 7, 'loc' => $locA, 'email' => 'anon@example.test'));
$tv    = $mk(array('key' => 'tv', 'title' => 'Old TV set', 'cat' => $catHome, 'price' => 150, 'hours' => 8, 'user' => $u1, 'loc' => $locB, 'desc' => 'tv hp 15 small'));
$hid   = $mk(array('key' => 'hidden', 'title' => 'Hidden Wagon', 'cat' => $catCars, 'price' => 3000, 'hours' => 9, 'user' => $u1, 'loc' => $locA, 'enabled' => 0));
$exp   = $mk(array('key' => 'expired', 'title' => 'Old Truck', 'cat' => $catCars, 'price' => 4000, 'hours' => 10, 'user' => $u2, 'loc' => $locB, 'expired' => true));
$spam  = $mk(array('key' => 'spam', 'title' => 'Spam Van', 'cat' => $catCars, 'price' => 4500, 'hours' => 11, 'user' => $u3, 'loc' => $locG, 'spam' => 1));

$meta = static function (int $item, string $type, string $value, string $multi = '') use ($admin, $p, &$fields): void {
    seed_exec($admin, "INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES (?, ?, ?, ?)", 'iiss', array($item, $fields[$type], $value, $multi));
};
$meta($car1, 'TEXT', 'mint condition');
$meta($car2, 'TEXT', 'needs work');
$meta($car1, 'DROPDOWN', 'red');
$meta($car3, 'DROPDOWN', 'green');
$meta($car1, 'RADIO', 'used');
$meta($sport, 'RADIO', 'new');
$meta($car1, 'CHECKBOX', '1');
$meta($car2, 'CHECKBOX', '0');
$meta($car2, 'DATE', (string)$day);
$meta($car1, 'DATEINTERVAL', (string)($day - 86400), 'from');
$meta($car1, 'DATEINTERVAL', (string)($day + 86400), 'to');
$meta($car1, 'NUMBER', '120000');
$meta($car3, 'NUMBER', '45000');
$meta($sport, 'TEXTAREA', "it's fast");
$meta($car2, 'URL', 'http://example.test/sedan');

scratchdb_forget_cache();
$catReset = new ReflectionProperty('Category', 'instance');
if (PHP_VERSION_ID < 80100) {
    $catReset->setAccessible(true);
}
$catReset->setValue(null, null);

/* ----------------------------------------------------------------------------
 * The matrix. Each case builds a Search and returns what it found; $run() turns a
 * Search into labels (in result order, or sorted where the order is not defined)
 * and the total.
 * ------------------------------------------------------------------------- */
$names = static function (array $rows, bool $ordered = true) use (&$label): array {
    $out = array();
    foreach ($rows as $row) {
        $out[] = $label[(int)$row['pk_i_id']] ?? ('#' . $row['pk_i_id']);
    }
    if (!$ordered) {
        sort($out);
    }

    return $out;
};
$rowsSeen = array();
$run = static function (Search $s, bool $ordered = true, bool $count = true, bool $extended = true) use ($names, &$rowsSeen): array {
    $rows       = $s->doSearch($extended, $count);
    $rowsSeen[] = $rows;

    return array('ids' => $names($rows, $ordered), 'count' => $count ? (int)$s->count() : null);
};
$criteria = static function (array $request): SearchCriteria {
    return SearchCriteria::fromRequest($request);
};
$built = static function (array $request, bool $ordered = true) use ($criteria, $run): array {
    $s = new Search();
    $c = $criteria($request);
    SearchBuilder::apply($c, $s);
    $s->order($c->sortColumn(), $c->sortDirection());
    $s->page($c->page(), $c->pageSize());

    return $run($s, $ordered);
};
$json = static function (Search $s): string {
    return $s->toJson();
};

$cases = array();

// Unfiltered, counts and paging.
$cases['unfiltered']               = static fn () => $run(new Search());
$cases['unfiltered, no count']     = static fn () => $run(new Search(), true, false);
$cases['unfiltered, not extended'] = static fn () => $run(new Search(), true, true, false);
$cases['count() before doSearch']  = static fn () => array('count' => (int)(new Search())->count());
$cases['count after uncounted']    = static function () {
    $s = new Search();
    $s->doSearch(true, false);

    return array('count' => (int)$s->count());
};
$cases['page 1 of 3'] = static function () use ($run) {
    $s = new Search();
    $s->page(1, 3);

    return $run($s);
};
$cases['limit offset 2 size 3'] = static function () use ($run) {
    $s = new Search();
    $s->limit(2, 3);

    return $run($s);
};
$cases['set_rpp 2'] = static function () use ($run) {
    $s = new Search();
    $s->set_rpp(2);

    return $run($s);
};
$cases['page past the end'] = static function () use ($run) {
    $s = new Search();
    $s->page(5, 4);

    return $run($s);
};

// Categories.
foreach (array(
    'category root (subcategories included)' => array($catCars),
    'subcategory'                            => array($catSport),
    'category by slug'                       => array('bikes'),
    'category by path slug'                  => array('cars/sports-cars/'),
    'two categories'                         => array($catBikes, $catHome),
    'unknown slug'                           => array('no-such-category'),
    'category id as string'                  => array((string)$catHome),
) as $name => $cats) {
    $cases[$name] = static function () use ($run, $cats) {
        $s = new Search();
        foreach ($cats as $c) {
            $s->addCategory($c);
        }

        return $run($s);
    };
}

// Locations.
foreach (array(
    'region id'            => array('addRegion', $rAlpha),
    'region id as string'  => array('addRegion', (string)$rBeta),
    'region name'          => array('addRegion', 'Gamma'),
    'region name wildcard' => array('addRegion', 'Al%'),
    'regions array'        => array('addRegion', array($rAlpha, 'Gamma')),
    'region leading zero'  => array('addRegion', '0' . $rAlpha),
    'region name quote'    => array('addRegion', "Al'pha"),
    'city id'              => array('addCity', $cB),
    'city name'            => array('addCity', 'Aville'),
    'cities array'         => array('addCity', array('Aville', $cG)),
    'city area id'         => array('addCityArea', 1),
    'city area name'       => array('addCityArea', 'Uptown'),
    'city areas array'     => array('addCityArea', array(1, 'Uptown')),
    'country code'         => array('addCountry', 'CA'),
    'country code lower'   => array('addCountry', 'us'),
    'country name'         => array('addCountry', 'Canada'),
    'countries array'      => array('addCountry', array('CA', 'United States')),
    'empty region'         => array('addRegion', ''),
    'zero city'            => array('addCity', '0'),
) as $name => [$method, $value]) {
    $cases[$name] = static function () use ($run, $method, $value) {
        $s = new Search();
        $s->$method($value);

        return $run($s);
    };
}
$cases['region and city'] = static function () use ($run, $rBeta, $cB) {
    $s = new Search();
    $s->addRegion($rBeta);
    $s->addCity($cB);

    return $run($s);
};
$cases['country, region, category'] = static function () use ($run, $catCars) {
    $s = new Search();
    $s->addCountry('US');
    $s->addRegion('Alpha');
    $s->addCategory($catCars);

    return $run($s);
};

// Prices.
foreach (array(
    'price range'       => array(1000, 10000),
    'price min only'    => array(5000, null),
    'price max only'    => array(null, 1000),
    'price zero range'  => array(0, 0),
    'price string'      => array('2000', '9000'),
    'price float'       => array(799.9, 2500.5),
) as $name => [$min, $max]) {
    $cases[$name] = static function () use ($run, $min, $max) {
        $s = new Search();
        $s->priceRange($min, $max);

        return $run($s);
    };
}
$cases['priceMin()'] = static function () use ($run) {
    $s = new Search();
    $s->priceMin(9000);

    return $run($s);
};
$cases['priceMax()'] = static function () use ($run) {
    $s = new Search();
    $s->priceMax(800);

    return $run($s);
};

// Patterns.
foreach (array(
    'pattern word'          => array('collectible', false),
    'pattern two words'     => array('Mountain Bike', false),
    'pattern prefix'        => array('Roadst', false),
    'pattern exclusion'     => array('Bike -Racing', false),
    'pattern phrase'        => array('"Racing Bike"', false),
    'pattern short term'    => array('se', false),
    'pattern short terms'   => array('tv hp', false),
    'pattern quote'         => array("it's", false),
    'pattern wildcard'      => array('100%', false),
    'pattern underscore'    => array('l_p', false),
    'pattern operators'     => array('+(bike)* ~<racing>@', false),
    'pattern no match'      => array('zzzzqqq', false),
    'pattern only operator' => array('***', false),
    'pattern relevance'     => array('bike', true),
    'pattern short rel.'    => array('se', true),
) as $name => [$pattern, $relevance]) {
    $cases[$name] = static function () use ($run, $pattern, $relevance) {
        $s = new Search();
        $s->addPattern($pattern);
        if ($relevance) {
            $s->order('relevance', 'DESC');
        }

        return $run($s, !$relevance);
    };
}
$cases['pattern with es_ES locale'] = static function () use ($run) {
    $s = new Search();
    $s->addPattern('Bicicleta');
    $s->addLocale('es_ES');

    return $run($s);
};
$cases['pattern in user locale misses es_ES'] = static function () use ($run) {
    $s = new Search();
    $s->addPattern('Bicicleta');

    return $run($s);
};
$cases['pattern two locales'] = static function () use ($run) {
    $s = new Search();
    $s->addPattern('bike');
    $s->addLocale(array('en_US', 'es_ES'));

    return $run($s, false);
};
$cases['pattern, category, price, premium'] = static function () use ($run, $catBikes) {
    $s = new Search();
    $s->addPattern('bike');
    $s->addCategory($catBikes);
    $s->priceRange(100, 5000);
    $s->onlyPremium(true);

    return $run($s);
};
$cases['pattern and location'] = static function () use ($run) {
    $s = new Search();
    $s->addPattern('collectible');
    $s->addRegion('Alpha');

    return $run($s);
};

// Users.
foreach (array(
    'user id'             => $u1,
    'user id string'      => (string)$u2,
    'username'            => 'seller3',
    'unknown username'    => 'nobody',
    'user ids array'      => array($u1, $u3),
    'usernames array'     => array('seller1', 'nobody', (string)$u2),
) as $name => $user) {
    $cases[$name] = static function () use ($run, $user) {
        $s = new Search();
        $s->fromUser($user);

        return $run($s);
    };
}

// Flags.
$cases['with picture'] = static function () use ($run) {
    $s = new Search();
    $s->withPicture(true);

    return $run($s);
};
$cases['only premium'] = static function () use ($run) {
    $s = new Search();
    $s->onlyPremium(true);

    return $run($s);
};
$cases['picture and premium'] = static function () use ($run) {
    $s = new Search();
    $s->withPicture(true);
    $s->onlyPremium(true);

    return $run($s);
};
$cases['contact email'] = static function () use ($run) {
    $s = new Search();
    $s->addContactEmail('anon@example.test');

    return $run($s);
};
$cases['contact email quote'] = static function () use ($run) {
    $s = new Search();
    $s->addContactEmail("x' OR '1'='1");

    return $run($s);
};
$cases['not from user'] = static function () use ($run, $u1) {
    $s = new Search();
    $s->notFromUser($u1);

    return $run($s);
};
$cases['item id'] = static function () use ($run, $car3) {
    $s = new Search();
    $s->addItemId($car3);

    return $run($s);
};
$cases['item id of a hidden listing'] = static function () use ($run, $hid) {
    $s = new Search();
    $s->addItemId($hid);

    return $run($s);
};
$cases['include hidden'] = static function () use ($run) {
    $s = new Search();
    $s->includeHidden();

    return $run($s);
};
$cases['include hidden then not'] = static function () use ($run) {
    $s = new Search();
    $s->includeHidden();
    $s->includeHidden(false);

    return $run($s);
};
$cases['new Search(true)'] = static fn () => $run(new Search(true));
$cases['from primary keys'] = static function () use ($run, $car3, $bike1, $sofa, $hid) {
    $s = new Search();

    return $run($s->fromPrimaryKeys(array($car3, $bike1, 'x', $sofa, $hid)));
};
$cases['from primary keys unordered'] = static function () use ($run, $car3, $bike1, $sofa) {
    $s = new Search();

    return $run($s->fromPrimaryKeys(array($sofa, $car3, $bike1), false));
};
$cases['from primary keys empty'] = static function () use ($run) {
    $s = new Search();

    return $run($s->fromPrimaryKeys(array()));
};

// Ordering.
foreach (array(
    'order price asc'        => array('i_price', 'ASC', null),
    'order price desc'       => array('i_price', 'DESC', null),
    'order date asc'         => array('dt_pub_date', 'ASC', null),
    'order expiration'       => array('dt_expiration', 'DESC', null),
    'order lower-case dir'   => array('i_price', 'asc', null),
    'order bad direction'    => array('i_price', 'sideways', null),
    'order random'           => array('i_price', 'random', null),
    'order bad column'       => array('i_price; DROP', 'ASC', null),
    'order relevance no pat' => array('relevance', 'DESC', null),
    'order qualified'        => array('i_price', 'ASC', '%st_item'),
    'order mod date'         => array('dt_mod_date', 'DESC', $p . 't_item'),
) as $name => [$col, $dir, $table]) {
    $cases[$name] = static function () use ($run, $col, $dir, $table) {
        $s = new Search();
        $s->order($col, $dir, $table);

        return $run($s, !in_array($dir, array('random'), true) && $col !== 'relevance');
    };
}
$cases['order by t_user'] = static function () use ($run, $u1, $u2, $u3) {
    $s = new Search();
    $s->fromUser(array($u1, $u2, $u3));
    $s->order('s_name', 'ASC', '%st_user');

    return $run($s, false);
};
$cases['orderBy several columns'] = static function () use ($run) {
    $s = new Search();
    $s->orderBy(array(array('b_premium', 'DESC'), array('i_price', 'ASC')));

    return $run($s);
};

// Plugin-style fragments.
$cases['addConditions string'] = static function () use ($run, $p) {
    $s = new Search();
    $s->addConditions($p . 't_item.i_price > 1000000000');

    return $run($s);
};
$cases['addConditions array, duplicate'] = static function () use ($run, $p, $catHome) {
    $s = new Search();
    $s->addConditions(array($p . 't_item.fk_i_category_id <> ' . $catHome, $p . 't_item.fk_i_category_id <> ' . $catHome, ' '));

    return $run($s);
};
$cases['addConditions with literal question mark'] = static function () use ($run, $p) {
    $s = new Search();
    $s->addConditions($p . "t_item.s_contact_name <> 'who?'");
    $s->addRegion('Alpha');

    return $run($s);
};
$cases['addCondition with params'] = static function () use ($run, $p, $car1) {
    $s = new Search();
    $s->addCondition($p . 't_item.pk_i_id <> ? AND ' . $p . 't_item.s_contact_email = ?', array($car1, 'seller@example.test'));
    $s->addCity('Aville');

    return $run($s);
};
$cases['addItemConditions'] = static function () use ($run, $p) {
    $s = new Search();
    $s->addItemConditions($p . 't_item.b_premium = 1');

    return $run($s);
};
$cases['addTable and condition'] = static function () use ($run, $p) {
    $s = new Search();
    $s->addTable($p . 't_category c');
    $s->addConditions('c.pk_i_id = ' . $p . 't_item.fk_i_category_id AND c.fk_i_parent_id IS NOT NULL');

    return $run($s);
};
$cases['addJoinTable and field'] = static function () use ($run, $p) {
    $s = new Search();
    $s->addJoinTable('u', $p . 't_user u', 'u.pk_i_id = ' . $p . 't_item.fk_i_user_id', 'INNER');
    $s->addField('u.s_username as seller_name');
    $s->addConditions("u.s_username = 'seller2'");

    return $run($s);
};
$cases['addJoinTable bad type'] = static function () use ($run, $p) {
    $s = new Search();
    $s->addJoinTable('u', $p . 't_user u', 'u.pk_i_id = ' . $p . 't_item.fk_i_user_id', 'SIDEWAYS');

    return $run($s);
};
$cases['addField with comma'] = static function () use ($run, $p) {
    $s = new Search();
    $s->addField(array('CONCAT(' . $p . 't_item.pk_i_id, \'-\', ' . $p . 't_item.i_price) as tag', 'pk_i_id'));

    return $run($s);
};
$cases['addGroupBy and addHaving'] = static function () use ($run, $p) {
    $s = new Search();
    $s->addJoinTable('r', $p . "t_resource r", "r.s_owner_type = 'item' AND r.i_owner_id = " . $p . 't_item.pk_i_id', 'LEFT');
    $s->addField('COUNT(r.pk_i_id) as photos');
    $s->addGroupBy($p . 't_item.pk_i_id');
    $s->addHaving('photos > 1');

    return $run($s);
};
$cases['dao where, select, join'] = static function () use ($run, $p, $car1, $car2, $sport, $bike1) {
    $s = new Search();
    $s->dao->where(sprintf('%st_item.pk_i_id IN (%d, %d, %d, %d)', $p, $car1, $car2, $sport, $bike1));
    $s->dao->select('st.i_num_views');
    $s->dao->join($p . 't_item_stats st', 'st.fk_i_item_id = ' . $p . 't_item.pk_i_id', 'LEFT');
    $s->addCategory('cars');

    return $run($s);
};
$cases['dao orderBy and key/value where'] = static function () use ($run, $p, $car1, $car3, $bike2) {
    $s = new Search();
    $s->dao->where($p . 't_item.s_contact_email', 'seller@example.test');
    $s->dao->orderBy(sprintf("FIND_IN_SET(%st_item.pk_i_id, '%d,%d,%d')", $p, $bike2, $car3, $car1), 'DESC');

    return $run($s);
};
$cases['broken plugin condition'] = static function () use ($run) {
    $s = new Search();
    $s->addConditions('no_such_column = 1');

    return $run($s);
};
$cases['sql_search_conditions filter'] = static function () use ($run, $p) {
    $f = static fn ($c) => array_merge((array)$c, array($p . 't_item.b_premium = 1'));
    osc_add_filter('sql_search_conditions', $f);
    try {
        $s = new Search();
        $s->addRegion('Beta');

        return $run($s);
    } finally {
        osc_remove_filter('sql_search_conditions', $f);
    }
};
$cases['sql_search_item_conditions filter'] = static function () use ($run) {
    $f = static fn ($c) => array();
    osc_add_filter('sql_search_item_conditions', $f);
    try {
        return $run(new Search());
    } finally {
        osc_remove_filter('sql_search_item_conditions', $f);
    }
};

// Search page builder: criteria, custom fields.
$cases['builder: full request'] = static fn () => $built(array(
    'sCategory' => (string)$catCars, 'sRegion' => (string)$rAlpha, 'sPriceMin' => '1000', 'sPriceMax' => '20000',
    'sOrder' => 'i_price', 'iOrderType' => 'asc', 'bPic' => '1',
));
$cases['builder: pattern, user, premium'] = static fn () => $built(array('sPattern' => 'bike', 'sUser' => (string)$u3, 'bPremium' => '1'));
$cases['builder: comma lists'] = static fn () => $built(array('sCity' => 'Aville,Gtown', 'sCountry' => 'US'));
$cases['builder: page 2'] = static fn () => $built(array('iPage' => '2', 'sOrder' => 'dt_pub_date', 'iOrderType' => 'desc'));
foreach (array(
    'TEXT'         => 'mint',
    'TEXTAREA'     => "it's",
    'URL'          => 'example.test',
    'DROPDOWN'     => 'red',
    'RADIO'        => 'new',
    'CHECKBOX'     => '1',
    'DATE'         => (string)$day,
    'DATEINTERVAL' => array('from' => (string)$day, 'to' => (string)$day),
    'NUMBER'       => array('from' => '40000', 'to' => '130000'),
) as $type => $value) {
    $cases['builder: meta ' . $type] = static fn () => $built(array('sCategory' => (string)$catCars, 'meta' => array($fields[$type] => $value)));
}
$cases['builder: meta outside its category'] = static fn () => $built(array('sCategory' => (string)$catBikes, 'meta' => array($fields['DROPDOWN'] => 'red')));
$cases['builder: meta wildcard'] = static fn () => $built(array('sCategory' => (string)$catCars, 'meta' => array($fields['TEXT'] => '%')));

// Search runner (the search page and the API entry).
$cases['runner: request'] = static function () use ($criteria, $names, $catCars) {
    $r = SearchRunner::run($criteria(array('sCategory' => (string)$catCars, 'sOrder' => 'i_price', 'iOrderType' => 'desc')), new Search(), 'request');

    return array('ids' => $names($r->items()), 'count' => $r->total());
};
$cases['runner: uncounted with shape'] = static function () use ($criteria, $names) {
    $r = SearchRunner::run($criteria(array('sCountry' => 'US')), new Search(), 'api', static function (Search $s) {
        $s->orderBy(array(array('i_price', 'ASC'), array('pk_i_id', 'ASC')));
        $s->limit(1, 3);
    }, false);

    return array('ids' => $names($r->items()), 'count' => $r->total());
};

// Premiums, latest, helpers.
$cases['premiums'] = static fn () => array('ids' => $names((new Search())->getPremiums(10), false));
$cases['premiums limit 1'] = static fn () => array('ids' => array(count((new Search())->getPremiums(1))));
$cases['premiums in category'] = static function () use ($names, $catCars) {
    $s = new Search();
    $s->addCategory($catCars);

    return array('ids' => $names($s->getPremiums(10), false));
};
$cases['premiums in region'] = static function () use ($names) {
    $s = new Search();
    $s->addRegion('Gamma');

    return array('ids' => $names($s->getPremiums(10), false));
};
$cases['premiums with pattern'] = static function () use ($names) {
    $s = new Search();
    $s->addPattern('bike');

    return array('ids' => $names($s->getPremiums(10), false));
};
$cases['premiums with pattern and city'] = static function () use ($names, $cB) {
    $s = new Search();
    $s->addPattern('collectible');
    $s->addCity($cB);

    return array('ids' => $names($s->getPremiums(10), false));
};
foreach (array(
    'latest 3'           => array(3, array(), false),
    'latest by category' => array(10, array('sCategory' => $catCars), false),
    'latest by country'  => array(10, array('sCountry' => 'CA'), false),
    'latest by region'   => array(10, array('sRegion' => $rBeta), false),
    'latest by city'     => array(10, array('sCity' => 'Aville'), false),
    'latest by user'     => array(10, array('sUser' => $u2), false),
    'latest with photo'  => array(10, array(), true),
) as $name => [$n, $opts, $pic]) {
    $cases[$name] = static fn () => array('ids' => $names((new Search())->getLatestItems($n, $opts, $pic)));
}
$cases['countAll'] = static fn () => array('count' => (int)(new Search())->countAll());
$cases['listCityAreas'] = static fn () => array('ids' => array_map(static fn ($r) => $r['city_area_name'] . '=' . $r['items'], (new Search())->listCityAreas(null, '>=', 'city_area_name ASC')));
$cases['listCityAreas of a city'] = static fn () => array('ids' => array_column((new Search())->listCityAreas($cA, '>', 'items DESC'), 'city_area_name'));
foreach (array(
    'osc_query_item category and premium' => array('category' => (string)$catCars, 'premium' => '1'),
    'osc_query_item region and user'      => array('region_name' => 'Beta', 'user' => (string)$u2),
    'osc_query_item city area, page'      => array('city_area_name' => 'Downtown,Uptown', 'results_per_page' => '1', 'page' => '1'),
    'osc_query_item country, offset'      => array('country' => 'US', 'offset' => '2'),
    'osc_query_item id and pattern'       => array('id' => (string)$car1),
    'osc_query_item keyword string'       => 'country_name=Canada',
) as $name => $q) {
    $cases[$name] = static function () use ($names, $q) {
        View::getInstance()->_erase('customItems');
        osc_query_item($q);

        return array('ids' => $names((array)View::getInstance()->_get('customItems')));
    };
}

// toJson, the result cache key and what backends read; and its alert round trip.
$jsonCases = array(
    'empty'     => static fn () => new Search(),
    'filtered'  => static function () use ($catCars, $rAlpha, $u1) {
        $s = new Search();
        $s->addCategory($catCars);
        $s->addRegion($rAlpha);
        $s->addRegion('Be"ta');
        $s->addCity('Aville');
        $s->addCityArea(2);
        $s->addCountry('us');
        $s->addCountry('Canada');
        $s->fromUser(array($u1, 'seller2'));
        $s->priceRange(100, 9000);
        $s->addPattern("o'neil \"red car\"");
        $s->withPicture(true);
        $s->onlyPremium(true);
        $s->addLocale(array('es_ES', 'en_US'));
        $s->addConditions('1 = 1');
        $s->addTable('x_table');
        $s->addJoinTable('k', 'x_join', 'x_join.id = 1', 'LEFT');
        $s->order('i_price', 'ASC');
        $s->page(2, 5);

        return $s;
    },
    'user scalar' => static function () use ($u3) {
        $s = new Search();
        $s->fromUser($u3);

        return $s;
    },
    'username scalar' => static function () {
        $s = new Search();
        $s->fromUser('seller2');

        return $s;
    },
    'after doSearch with pattern' => static function () {
        $s = new Search();
        $s->addPattern('bike');
        $s->addRegion('Beta');
        $s->doSearch();

        return $s;
    },
);
foreach ($jsonCases as $name => $make) {
    $cases['toJson ' . $name] = static fn () => array('json' => $json($make()));
}
$cases['setJsonAlert round trip'] = static function () use ($run, $json, $catCars) {
    $src = new Search();
    $src->addCategory($catCars);
    $src->priceRange(1000, 20000);
    $src->addPattern('collectible');
    $src->withPicture(true);
    $s = new Search();
    $s->setJsonAlert(json_decode($json($src), true));

    return $run($s);
};
$cases['setJsonAlert onto a used Search'] = static function () use ($run, $json, $catBikes) {
    $src = new Search();
    $src->addCategory($catBikes);
    $s = new Search();
    $s->addPattern('collectible');
    $s->addCity('Aville');
    $s->fromUser(1);
    $s->setJsonAlert(json_decode($json($src), true));

    return $run($s);
};
$cases['setJsonAlert bad envelope'] = static function () use ($run) {
    $s = new Search();
    $s->setJsonAlert(array('v' => 2, 'params' => array('sCategory' => array('x' => array()))));

    return $run($s);
};
$cases['alert replay v2'] = static function () use ($run, $catCars, $rAlpha) {
    $request = array('sCategory' => (string)$catCars, 'sRegion' => (string)$rAlpha, 'sPriceMin' => '1000');
    $s       = AlertReplay::search(array('s_search' => AlertEnvelope::build(SearchCriteria::fromRequest($request), $request)), array('limit' => 5));

    return $run($s);
};
$cases['alert replay v2 pattern and meta'] = static function () use ($run, $catCars, $fields) {
    $request = array('sCategory' => (string)$catCars, 'sPattern' => 'collectible', 'meta' => array($fields['DROPDOWN'] => 'red'));
    $s       = AlertReplay::search(array('s_search' => AlertEnvelope::build(SearchCriteria::fromRequest($request), $request)));

    return $run($s);
};

// One shared instance reused, as the alert cron and the search page do.
$cases['shared instance reused'] = static function () use ($run, $catBikes) {
    Search::resetInstance();
    $s = Search::getInstance();
    $s->addCategory($catBikes);
    $first = $run($s);
    $s->addRegion('Beta');
    $second = $run($s);
    Search::resetInstance();

    return array('ids' => array_merge($first['ids'], array('|'), $second['ids']), 'count' => $second['count']);
};

/* ----------------------------------------------------------------------------
 * Run the matrix once for the pins, then again for timing when asked.
 * ------------------------------------------------------------------------- */
$results = array();
$started = microtime(true);
foreach ($cases as $name => $case) {
    scratchdb_forget_cache();
    $q              = 0;
    $result         = null;
    $q              = harness_query_count(static function () use ($case, &$result) {
        $result = $case();
    });
    $result['q']    = $q;
    $results[$name] = $result;
}
$elapsed = microtime(true) - $started;

if ($write) {
    $out = "<?php\n\n// Written by tests/models/search-parity.php --write. Labels, totals and statement counts per case.\n\nreturn "
        . preg_replace(array('/^(\s*)array \(/m', '/=> $/m', '/=> NULL,$/m'), array('$1array(', '=>', '=> null,'), var_export($results, true)) . ";\n";
    file_put_contents($fixture, $out);
    echo 'wrote ', count($results), " cases to $fixture\n";
}

$expected = is_file($fixture) ? require $fixture : array();

harness_section('Search parity: ' . count($cases) . ' cases');
pin('the matrix covers the same cases as the fixture', array_keys($expected), array_keys($results));
$totalQ = 0;
foreach ($results as $name => $result) {
    $want = $expected[$name] ?? array();
    $q    = $result['q'];
    unset($result['q']);
    $wantQ = $want['q'] ?? null;
    unset($want['q']);
    pin($name, $want, $result);
    check($name . ': statements ' . $q . ' <= ' . var_export($wantQ, true), $wantQ !== null && $q <= $wantQ);
    $totalQ += $q;
}
printf("matrix: %d cases, %d statements, %.1f ms\n", count($cases), $totalQ, $elapsed * 1000);

if ($perfN > 0) {
    $times = array();
    for ($i = 0; $i < $perfN; $i++) {
        $t = microtime(true);
        foreach ($cases as $case) {
            scratchdb_forget_cache();
            $case();
        }
        $times[] = (microtime(true) - $t) * 1000;
    }
    sort($times);
    printf("perf: %d runs, median %.1f ms, best %.1f ms\n", $perfN, $times[intdiv(count($times), 2)], $times[0]);
}

if ($dumpTo !== '') {
    $clean = array();
    foreach ($rowsSeen as $rows) {
        $set = array();
        foreach ((array)$rows as $row) {
            if (is_array($row)) {
                ksort($row);
                foreach ($row as $k => $v) {
                    if (str_starts_with((string)$k, 'dt_')) {
                        unset($row[$k]);
                    }
                }
            }
            $set[] = $row;
        }
        $clean[] = $set;
    }
    file_put_contents($dumpTo, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

exit(harness_result());

/* file end: ./tests/models/search-parity.php */
