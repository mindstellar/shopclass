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
 * Categories, currencies, custom fields and locations through `/admin/...` end to end: who
 * may call, a category's slug history and cache flush, switching a top category off with
 * its subcategories, the currency rules, field slugs, and region, city and area writes with
 * the public location reads showing them at once.
 *
 * Usage:  php tests/models/api-admin-taxonomy.php        (standalone, own scratch database)
 *         php tests/run-models.php api-admin-taxonomy    (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\api\Response;

$admin = api_admin_boot('osc_models_api_admin_taxonomy');

$p        = DB_TABLE_PREFIX;
$locale   = seed_locale($admin);
seed_currency($admin);
seed_currency($admin, 'EUR', 'Euro');
$country  = seed_country($admin, 'US', 'United States');
$region   = seed_region($admin, $country, 'Alpha');
$city     = seed_city($admin, $region, 'Aville', $country);
$vehicles = seed_category($admin, 'Vehicles', null, $locale);
$cars     = seed_category($admin, 'Cars', $vehicles, $locale);
$sue      = seed_user($admin, 'sue', 'sue@example.test');
$car      = seed_item($admin, $cars, $sue, 'A car', 100.0);
foreach (['language' => 'en_US', 'currency' => 'USD'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.70';

$bossId = api_admin_seed_admin($admin, 'boss');
$modId  = api_admin_seed_admin($admin, 'mod', true);
$boss   = api_admin_key($bossId);
$mod    = api_admin_key($modId, true);
$call   = api_admin_caller();
$fired  = [];
foreach (['add_category', 'edited_category', 'delete_category'] as $hook) {
    osc_add_hook($hook, static function (...$args) use (&$fired, $hook): void {
        $fired[$hook] = ($fired[$hook] ?? 0) + 1;
    });
}
$pointer = static fn (Response $r): array => [$r->status(), $r->body()['errors'][0]['pointer'] ?? null];

harness_section('who may call');
pin('an admin key: 200', 200, $call('GET', 'admin/categories', null, $boss)->status());
pin('a moderator: 403', '403 insufficient_scope', api_admin_code($call('GET', 'admin/categories', null, $mod)));
pin('a moderator cannot add a region', '403 insufficient_scope', api_admin_code($call('POST', 'admin/regions', ['country' => 'US', 'name' => 'Beta'], $mod)));

harness_section('categories');
$r = $call('GET', 'admin/categories', null, $boss);
pin('every category with its texts', [[$vehicles, $cars], 'cars'], [array_column($r->body()['data'] ?? [], 'id'), $r->body()['data'][1]['translations']['en_US']['slug'] ?? null]);
pin('matches the schema', [], api_admin_schema_errors('AdminCategoryList', $r));
$fired = [];
$r     = $call('POST', 'admin/categories', ['parent_id' => $vehicles, 'translations' => ['en_US' => ['name' => 'Vans']], 'expiration_days' => 30], $boss);
$vans  = (int) ($r->body()['data']['id'] ?? 0);
pin('POST: 201 with a slug made from the name, added last', [201, 'vans', 1, 30, 1], [
    $r->status(), $r->body()['data']['translations']['en_US']['slug'] ?? null, $r->body()['data']['position'] ?? null, $r->body()['data']['expiration_days'] ?? null, $fired['add_category'] ?? 0,
]);
pin('Location names it', 'http://localhost/api/v1/admin/categories/' . $vans, $r->header('Location'));
pin('matches the schema', [], api_admin_schema_errors('AdminCategoryDocument', $r));
pin('an unknown parent is 422', [422, '/parent_id'], $pointer($call('POST', 'admin/categories', ['parent_id' => 99999, 'translations' => ['en_US' => ['name' => 'X']]], $boss)));
pin('a language the site does not have is 422', [422, '/translations/fr_FR'], $pointer($call('POST', 'admin/categories', ['translations' => ['fr_FR' => ['name' => 'X']]], $boss)));

$before = osc_cache_category_generation();
\Category::getInstance()->toTree();
$fired = [];
$r     = $call('PATCH', 'admin/categories/' . $cars, ['translations' => ['en_US' => ['slug' => 'automobiles']]], $boss);
pin('PATCH a slug: the name is kept', [200, 'automobiles', 'Cars'], [$r->status(), $r->body()['data']['translations']['en_US']['slug'] ?? null, $r->body()['data']['translations']['en_US']['name'] ?? null]);
pin('the old slug is in the history, so its links redirect', [(string) $cars], array_column($admin->query("SELECT fk_i_category_id FROM {$p}t_category_slug_history WHERE s_slug = 'cars'")->fetch_all(MYSQLI_ASSOC), 'fk_i_category_id'));
pin('edited_category fired once and the category cache moved on', [1, true], [$fired['edited_category'] ?? 0, osc_cache_category_generation() !== $before]);
pin('the slug api is refused: /api/ is the API\'s', [422, '/translations/en_US/slug'], $pointer($call('PATCH', 'admin/categories/' . $cars, ['translations' => ['en_US' => ['slug' => 'API']]], $boss)));
$r = $call('POST', 'admin/categories', ['translations' => ['en_US' => ['name' => 'API']]], $boss);
pin('a category named API gets another slug', [201, 'api_1'], [$r->status(), $r->body()['data']['translations']['en_US']['slug'] ?? null]);
$call('DELETE', 'admin/categories/' . (int) ($r->body()['data']['id'] ?? 0), null, $boss);
$r = $call('PATCH', 'admin/categories/' . $cars, ['translations' => ['en_US' => ['name' => 'Motor cars']]], $boss);
pin('a new name keeps the slug', ['automobiles', 'Motor cars'], [$r->body()['data']['translations']['en_US']['slug'] ?? null, $r->body()['data']['translations']['en_US']['name'] ?? null]);
$r = $call('PATCH', 'admin/categories/' . $vehicles, ['enabled' => false], $boss);
pin('switching a top category off takes its subcategories and listings with it', [200, '0', '0', '0'], [
    $r->status(),
    $admin->query("SELECT b_enabled FROM {$p}t_category WHERE pk_i_id = $cars")->fetch_row()[0],
    $admin->query("SELECT b_enabled FROM {$p}t_category WHERE pk_i_id = $vans")->fetch_row()[0],
    $admin->query("SELECT b_enabled FROM {$p}t_item WHERE pk_i_id = $car")->fetch_row()[0],
]);
pin('a subcategory cannot be switched on under a disabled parent', '409 conflict', api_admin_code($call('PATCH', 'admin/categories/' . $cars, ['enabled' => true], $boss)));
$call('PATCH', 'admin/categories/' . $vehicles, ['enabled' => true], $boss);
pin('switching it on again', ['1', '1'], [
    $admin->query("SELECT b_enabled FROM {$p}t_category WHERE pk_i_id = $cars")->fetch_row()[0],
    $admin->query("SELECT b_enabled FROM {$p}t_item WHERE pk_i_id = $car")->fetch_row()[0],
]);
pin('DELETE: 204', 204, $call('DELETE', 'admin/categories/' . $vans, null, $boss)->status());
pin('it is gone, and again it is 404', [0, 404], [
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_category WHERE pk_i_id = $vans")->fetch_row()[0], $call('DELETE', 'admin/categories/' . $vans, null, $boss)->status(),
]);

harness_section('currencies');
$r = $call('POST', 'admin/currencies', ['code' => 'GBP', 'name' => 'Pound', 'symbol' => '£'], $boss);
pin('POST: 201', [201, 'GBP', '£'], [$r->status(), $r->body()['data']['code'] ?? null, $r->body()['data']['symbol'] ?? null]);
pin('matches the schema', [], api_admin_schema_errors('CurrencyDocument', $r));
pin('Location names it, and a GET there reads it', ['http://localhost/api/v1/admin/currencies/GBP', 200, 'GBP', 404], [
    $r->header('Location'), $call('GET', 'admin/currencies/GBP', null, $boss)->status(), $call('GET', 'admin/currencies/GBP', null, $boss)->body()['data']['code'] ?? null, $call('GET', 'admin/currencies/ZZZ', null, $boss)->status(),
]);
pin('the same code again is 409', '409 conflict', api_admin_code($call('POST', 'admin/currencies', ['code' => 'GBP', 'name' => 'Pound two'], $boss)));
$racer = new class () extends \Currency {
    public function insert($values)
    {
        // Another request adds the same code between the controller's check and this insert.
        osc_db_table(DB_TABLE_PREFIX . 't_currency')->insert(['pk_c_code' => $values['pk_c_code'], 's_name' => 'First', 's_description' => '']);

        return parent::insert($values);
    }
};
$slot = new ReflectionProperty(\Currency::class, 'instance');
$slot->setAccessible(true);
$real = $slot->getValue();
$slot->setValue(null, $racer);
$raced = $call('POST', 'admin/currencies', ['code' => 'CHF', 'name' => 'Franc'], $boss);
$slot->setValue(null, $real);
pin('a code added by another request meanwhile is 409, not 201 for the other row', '409 conflict', api_admin_code($raced));
pin('a code in lower case is 422', 422, $call('POST', 'admin/currencies', ['code' => 'gbp', 'name' => 'x'], $boss)->status());
$r = $call('PATCH', 'admin/currencies/GBP', ['symbol' => 'GBP £'], $boss);
pin('PATCH keeps the name', [200, 'Pound', 'GBP £'], [$r->status(), $r->body()['data']['name'] ?? null, $r->body()['data']['symbol'] ?? null]);
pin('the public currency list shows it at once', true, in_array('GBP', array_column($call('GET', 'currencies', null, $boss)->body()['data'] ?? [], 'code'), true));
pin('the default currency cannot be deleted', '409 conflict', api_admin_code($call('DELETE', 'admin/currencies/USD', null, $boss)));
$admin->query("UPDATE {$p}t_item SET fk_c_currency_code = 'EUR' WHERE pk_i_id = $car");
pin('nor one a listing is priced in', '409 conflict', api_admin_code($call('DELETE', 'admin/currencies/EUR', null, $boss)));
pin('DELETE: 204, then 404', [204, 404], [$call('DELETE', 'admin/currencies/GBP', null, $boss)->status(), $call('DELETE', 'admin/currencies/GBP', null, $boss)->status()]);

harness_section('custom fields');
$r     = $call('POST', 'admin/custom-fields', ['name' => 'Body style', 'type' => 'dropdown', 'options' => ['Saloon', 'Estate'], 'categories' => [$cars], 'searchable' => true], $boss);
$field = (int) ($r->body()['data']['id'] ?? 0);
pin('POST: 201 with a slug, options and categories', [201, 'body-style', 'dropdown', ['Saloon', 'Estate'], [$cars], true], [
    $r->status(), $r->body()['data']['slug'] ?? null, $r->body()['data']['type'] ?? null, $r->body()['data']['options'] ?? null, $r->body()['data']['categories'] ?? null, $r->body()['data']['searchable'] ?? null,
]);
pin('matches the schema', [], api_admin_schema_errors('AdminCustomFieldDocument', $r));
pin('Location names it, and a GET there reads it', ['http://localhost/api/v1/admin/custom-fields/' . $field, 200, $field, 404], [
    $r->header('Location'), $call('GET', 'admin/custom-fields/' . $field, null, $boss)->status(), $call('GET', 'admin/custom-fields/' . $field, null, $boss)->body()['data']['id'] ?? null, $call('GET', 'admin/custom-fields/99999', null, $boss)->status(),
]);
$r = $call('POST', 'admin/custom-fields', ['name' => 'Body', 'slug' => 'body-style', 'type' => 'text'], $boss);
pin('a taken slug gets a number', 'body-style_1', $r->body()['data']['slug'] ?? null);
pin('a taken name is 422', [422, '/name'], $pointer($call('POST', 'admin/custom-fields', ['name' => 'Body style', 'type' => 'text'], $boss)));
pin('an unknown category is 422', [422, '/categories'], $pointer($call('POST', 'admin/custom-fields', ['name' => 'Doors', 'type' => 'number', 'categories' => [99999]], $boss)));
$r = $call('PATCH', 'admin/custom-fields/' . $field, ['required' => true, 'categories' => []], $boss);
pin('PATCH: members not sent keep their values; categories are replaced', [200, true, 'body-style', [], 'Body style'], [
    $r->status(), $r->body()['data']['required'] ?? null, $r->body()['data']['slug'] ?? null, $r->body()['data']['categories'] ?? null, $r->body()['data']['name'] ?? null,
]);
pin('the public field list shows it', true, in_array($field, array_column($call('GET', 'custom-fields', null, $boss)->body()['data'] ?? [], 'id'), true));
pin('DELETE: 204, then 404', [204, 404], [$call('DELETE', 'admin/custom-fields/' . $field, null, $boss)->status(), $call('DELETE', 'admin/custom-fields/' . $field, null, $boss)->status()]);

harness_section('locations');
$r    = $call('POST', 'admin/regions', ['country' => 'us', 'name' => 'Beta'], $boss);
$beta = (int) ($r->body()['data']['id'] ?? 0);
pin('POST /admin/regions: 201', [201, 'US', 'Beta'], [$r->status(), $r->body()['data']['country_code'] ?? null, $r->body()['data']['name'] ?? null]);
pin('matches the schema', [], api_admin_schema_errors('RegionDocument', $r));
pin('Location names it, and a GET there reads it', ['http://localhost/api/v1/admin/regions/' . $beta, 200, 'Beta', 404], [
    $r->header('Location'), $call('GET', 'admin/regions/' . $beta, null, $boss)->status(), $call('GET', 'admin/regions/' . $beta, null, $boss)->body()['data']['name'] ?? null, $call('GET', 'admin/regions/99999', null, $boss)->status(),
]);
pin('the public region list shows it at once', true, in_array($beta, array_column($call('GET', 'countries/US/regions', null, $boss)->body()['data'] ?? [], 'id'), true));
pin('the same name again is 422', [422, '/name'], $pointer($call('POST', 'admin/regions', ['country' => 'US', 'name' => 'Beta'], $boss)));
pin('an unknown country is 404', 404, $call('POST', 'admin/regions', ['country' => 'ZZ', 'name' => 'Gamma'], $boss)->status());
$r = $call('PATCH', 'admin/regions/' . $region, ['name' => 'Alpha North'], $boss);
pin('PATCH renames, listings\' stored names follow', [200, 'Alpha North'], [$r->status(), $r->body()['data']['name'] ?? null]);
$r     = $call('POST', 'admin/cities', ['region_id' => $beta, 'name' => 'Bville'], $boss);
$bcity = (int) ($r->body()['data']['id'] ?? 0);
pin('POST /admin/cities: 201 under the region\'s country', [201, $beta, 'US'], [$r->status(), $r->body()['data']['region_id'] ?? null, $r->body()['data']['country_code'] ?? null]);
pin('Location names the city, and a GET there reads it', ['http://localhost/api/v1/admin/cities/' . $bcity, 200, 'Bville', 404], [
    $r->header('Location'), $call('GET', 'admin/cities/' . $bcity, null, $boss)->status(), $call('GET', 'admin/cities/' . $bcity, null, $boss)->body()['data']['name'] ?? null, $call('GET', 'admin/cities/99999', null, $boss)->status(),
]);
pin('an unknown region is 404', 404, $call('POST', 'admin/cities', ['region_id' => 99999, 'name' => 'X'], $boss)->status());
$r = $call('PATCH', 'admin/cities/' . $bcity, ['slug' => 'b-ville'], $boss);
pin('PATCH a slug keeps the name', ['Bville', 'b-ville'], [$r->body()['data']['name'] ?? null, $r->body()['data']['slug'] ?? null]);
$r    = $call('POST', 'admin/areas', ['city_id' => $bcity, 'name' => 'Old Town'], $boss);
$area = (int) ($r->body()['data']['id'] ?? 0);
pin('POST /admin/areas: 201, listed at once', [201, true], [$r->status(), in_array($area, array_column($call('GET', 'cities/' . $bcity . '/areas', null, $boss)->body()['data'] ?? [], 'id'), true)]);
pin('Location names the area, and a GET there reads it', ['http://localhost/api/v1/admin/areas/' . $area, 200, 'Old Town', 404], [
    $r->header('Location'), $call('GET', 'admin/areas/' . $area, null, $boss)->status(), $call('GET', 'admin/areas/' . $area, null, $boss)->body()['data']['name'] ?? null, $call('GET', 'admin/areas/99999', null, $boss)->status(),
]);
pin('a moderator cannot read one', '403 insufficient_scope', api_admin_code($call('GET', 'admin/areas/' . $area, null, $mod)));
pin('an unknown query parameter is 422, naming it', [422, '/bogus', 'query'], [
    $call('GET', 'admin/areas/' . $area, null, $boss, [], ['bogus' => '1'])->status(),
    $call('GET', 'admin/categories/' . $cars, null, $boss, [], ['bogus' => '1'])->body()['errors'][0]['pointer'] ?? null,
    $call('GET', 'admin/categories/' . $cars, null, $boss, [], ['bogus' => '1'])->body()['errors'][0]['in'] ?? null,
]);
pin('PATCH an area', 'New Town', $call('PATCH', 'admin/areas/' . $area, ['name' => 'New Town'], $boss)->body()['data']['name'] ?? null);
pin('DELETE an area: 204', 204, $call('DELETE', 'admin/areas/' . $area, null, $boss)->status());
pin('DELETE a region takes its cities: 204', [204, 0], [
    $call('DELETE', 'admin/regions/' . $beta, null, $boss)->status(), (int) $admin->query("SELECT COUNT(*) FROM {$p}t_city WHERE pk_i_id = $bcity")->fetch_row()[0],
]);
pin('an unknown location is 404', 404, $call('DELETE', 'admin/cities/99999', null, $boss)->status());

harness_section('names and texts are cleaned as the admin screens clean them');
$text = static fn (string $sql): ?string => $admin->query($sql)->fetch_row()[0] ?? null;
$r    = $call('POST', 'admin/categories', ['translations' => ['en_US' => ['name' => '<script>alert(1)</script>Trucks', 'description' => '<img src=x onerror=alert(1)>Big & small']]], $boss);
$trucks = (int) ($r->body()['data']['id'] ?? 0);
pin('POST a category: tags out, as the category screen stores them', [201, 'Trucks', 'Big &amp; small'], [
    $r->status(), $text("SELECT s_name FROM {$p}t_category_description WHERE fk_i_category_id = $trucks"), $text("SELECT s_description FROM {$p}t_category_description WHERE fk_i_category_id = $trucks"),
]);
pin('a blank name is 422', [422, '/translations/en_US/name'], $pointer($call('POST', 'admin/categories', ['translations' => ['en_US' => ['name' => '   ']]], $boss)));
pin('so is one of tags only', [422, '/translations/en_US/name'], $pointer($call('POST', 'admin/categories', ['translations' => ['en_US' => ['name' => '<b></b>']]], $boss)));
$call('PATCH', 'admin/categories/' . $trucks, ['translations' => ['en_US' => ['name' => '<b onmouseover="x()">Lorries</b>', 'description' => '<script>x</script>']]], $boss);
pin('PATCH a category\'s texts: tags out', ['Lorries', ''], [
    $text("SELECT s_name FROM {$p}t_category_description WHERE fk_i_category_id = $trucks"), $text("SELECT s_description FROM {$p}t_category_description WHERE fk_i_category_id = $trucks"),
]);
pin('PATCH a blank name is 422', [422, '/translations/en_US/name'], $pointer($call('PATCH', 'admin/categories/' . $trucks, ['translations' => ['en_US' => ['name' => ' ']]], $boss)));
$r     = $call('POST', 'admin/custom-fields', ['name' => '<i>Size</i>', 'type' => 'dropdown', 'options' => ['<b>Small</b>', ' Large ']], $boss);
$size  = (int) ($r->body()['data']['id'] ?? 0);
pin('POST a field: its name and options cleaned', [201, 'Size', 'Small,Large'], [
    $r->status(), $text("SELECT s_name FROM {$p}t_meta_fields WHERE pk_i_id = $size"), $text("SELECT s_options FROM {$p}t_meta_fields WHERE pk_i_id = $size"),
]);
pin('an option with a comma is 422, as the field screen would split it', [422, '/options/1'], $pointer($call('POST', 'admin/custom-fields', ['name' => 'Fit', 'type' => 'dropdown', 'options' => ['Slim', 'Loose, relaxed']], $boss)));
pin('a blank option is 422', [422, '/options/0'], $pointer($call('PATCH', 'admin/custom-fields/' . $size, ['options' => ['<b></b>']], $boss)));
pin('a blank field name is 422', [422, '/name'], $pointer($call('POST', 'admin/custom-fields', ['name' => '  ', 'type' => 'text'], $boss)));
$call('PATCH', 'admin/custom-fields/' . $size, ['name' => '<script>x</script>Fit', 'options' => ['<u>S</u>', 'M']], $boss);
pin('PATCH a field: cleaned too', ['Fit', 'S,M'], [$text("SELECT s_name FROM {$p}t_meta_fields WHERE pk_i_id = $size"), $text("SELECT s_options FROM {$p}t_meta_fields WHERE pk_i_id = $size")]);
$r     = $call('POST', 'admin/regions', ['country' => 'US', 'name' => '<script>alert(1)</script>Gamma'], $boss);
$gamma = (int) ($r->body()['data']['id'] ?? 0);
pin('POST a region: tags out', [201, 'Gamma'], [$r->status(), $text("SELECT s_name FROM {$p}t_region WHERE pk_i_id = $gamma")]);
pin('a region named with tags only is 422', [422, '/name'], $pointer($call('POST', 'admin/regions', ['country' => 'US', 'name' => '<b></b>'], $boss)));
$call('PATCH', 'admin/regions/' . $gamma, ['name' => '<i>Gamma</i> East'], $boss);
pin('PATCH a region: tags out', 'Gamma East', $text("SELECT s_name FROM {$p}t_region WHERE pk_i_id = $gamma"));
$gcity = (int) ($call('POST', 'admin/cities', ['region_id' => $gamma, 'name' => '<img src=x onerror=alert(1)>Gville'], $boss)->body()['data']['id'] ?? 0);
$call('PATCH', 'admin/cities/' . $gcity, ['name' => '<b>Gtown</b>'], $boss);
$garea = (int) ($call('POST', 'admin/areas', ['city_id' => $gcity, 'name' => '<svg onload=alert(1)>Docks'], $boss)->body()['data']['id'] ?? 0);
pin('cities and areas: tags out', ['Gtown', 'Docks'], [$text("SELECT s_name FROM {$p}t_city WHERE pk_i_id = $gcity"), $text("SELECT s_name FROM {$p}t_city_area WHERE pk_i_id = $garea")]);
$call('PATCH', 'admin/areas/' . $garea, ['name' => '<i>Quay</i>'], $boss);
pin('PATCH an area: tags out', 'Quay', $text("SELECT s_name FROM {$p}t_city_area WHERE pk_i_id = $garea"));

harness_section('a category under a disabled parent');
$off = (int) ($call('POST', 'admin/categories', ['translations' => ['en_US' => ['name' => 'Archive']], 'enabled' => false], $boss)->body()['data']['id'] ?? 0);
pin('asking for an enabled child is 409, as switching one on is', '409 conflict', api_admin_code($call('POST', 'admin/categories', ['parent_id' => $off, 'translations' => ['en_US' => ['name' => 'Old vans']], 'enabled' => true], $boss)));
$r = $call('POST', 'admin/categories', ['parent_id' => $off, 'translations' => ['en_US' => ['name' => 'Old cars']]], $boss);
pin('without `enabled` the child follows its parent: off', [201, false, '0'], [
    $r->status(), $r->body()['data']['enabled'] ?? null, $text("SELECT b_enabled FROM {$p}t_category WHERE pk_i_id = " . (int) ($r->body()['data']['id'] ?? 0)),
]);

harness_section('expiry is written only when sent');
$admin->query("UPDATE {$p}t_category SET i_expiration_days = 0 WHERE pk_i_id = $cars");
$admin->query("UPDATE {$p}t_item SET dt_expiration = '2030-01-01 00:00:00' WHERE pk_i_id = $car");
$call('PATCH', 'admin/categories/' . $cars, ['translations' => ['en_US' => ['name' => 'Cars again']]], $boss);
pin('PATCH texts only: listings keep their expiry', '2030-01-01 00:00:00', $text("SELECT dt_expiration FROM {$p}t_item WHERE pk_i_id = $car"));
$call('PATCH', 'admin/categories/' . $cars, ['price_enabled' => false], $boss);
pin('PATCH prices only: the same', '2030-01-01 00:00:00', $text("SELECT dt_expiration FROM {$p}t_item WHERE pk_i_id = $car"));
$call('PATCH', 'admin/categories/' . $cars, ['expiration_days' => 0], $boss);
pin('sending expiration_days writes it to the listings', '9999-12-31 23:59:59', $text("SELECT dt_expiration FROM {$p}t_item WHERE pk_i_id = $car"));

harness_section('the admin identity is taken on for admin routes only');
$seen = [];
osc_add_hook('api_request_before', static function ($request, $route) use (&$seen): void {
    $seen[$route->path()] = (string) Session::getInstance()->_get('adminId');
});
$call('GET', 'categories', null, $boss);
$call('GET', 'admin/categories', null, $boss);
pin('an admin key on a public route acts as nobody; on an admin route as its admin', ['', (string) $bossId], [$seen['categories'] ?? null, $seen['admin/categories'] ?? null]);

harness_section('the categories screen goes through the same editor');
$seen = [];
osc_add_hook('add_category', static function ($id) use (&$seen): void {
    $seen[] = ['add_category', $id];
});
osc_add_hook('edited_category', static function ($id, $outcome) use (&$seen): void {
    $seen[] = ['edited_category', $id, $outcome];
});
$editor = \mindstellar\category\CategoryService::make();
$roots  = static fn (): array => array_column(osc_db_stringify_rows(osc_db_table($p . 't_category')->whereNull('fk_i_parent_id')->orderBy('i_position')->orderBy('pk_i_id')->get()), 'pk_i_id');
$first  = $editor->create(null, ['i_expiration_days' => 0, 'b_price_enabled' => 1], ['en_US' => ['s_name' => 'NEW CATEGORY, EDIT ME!']], true, true);
pin('"add a category" puts it before every other root and fires add_category once with its id', [(string) $first, [['add_category', $first]]], [$roots()[0], $seen]);
$seen = [];
$editor->update($first, ['b_price_enabled' => 1], ['en_US' => ['s_name' => 'Renamed', 's_description' => '', 's_slug' => 'renamed']], false, 1);
$editor->edited($first, 1);
pin('a save fires edited_category with the screen\'s outcome; an unsaved edit can still report one', [['edited_category', $first, 1], ['edited_category', $first, 1]], $seen);

exit(harness_result());
