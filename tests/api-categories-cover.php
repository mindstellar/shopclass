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
 * GET /categories?include=cover: the first photo of the newest live listing with a photo in the
 * category or below it, null for an empty one, hidden listings skipped, flat and tree, and a
 * query count that does not grow with the number of categories.
 * Usage: php tests/api-categories-cover.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

$admin = api_admin_boot('osc_api_categories_cover');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$home  = seed_category($admin, 'Home', null, $locale);
$lamps = seed_category($admin, 'Lamps', $home, $locale);
$rugs  = seed_category($admin, 'Rugs', $home, $locale);
$empty = seed_category($admin, 'Empty', null, $locale);
$bare  = seed_category($admin, 'Bare', null, $locale);
$sue   = seed_user($admin, 'sue', 'sue@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'rewriteEnabled' => '0'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_COOKIE['oc_userLocale'] = 'en_US';

$at = static function (int $listing, string $when) use ($admin, $p): void {
    $admin->query("UPDATE {$p}t_item SET dt_pub_date = '$when' WHERE pk_i_id = $listing");
};
// Lamps: an old listing, then a newer one with two photos. Rugs: the newest of all, but hidden.
$oldLamp = seed_item($admin, $lamps, $sue, 'Old lamp');
$at($oldLamp, '2026-09-01 10:00:00');
$oldPhoto = seed_photo($admin, $oldLamp, ['s_name' => 'old']);
$newLamp = seed_item($admin, $lamps, $sue, 'New lamp');
$at($newLamp, '2026-09-10 10:00:00');
$firstPhoto = seed_photo($admin, $newLamp, ['s_name' => 'first']);
seed_photo($admin, $newLamp, ['s_name' => 'second']);
$hiddenRug = seed_item($admin, $rugs, $sue, 'Hidden rug', 5.0, 1, 0);
$at($hiddenRug, '2026-09-20 10:00:00');
seed_photo($admin, $hiddenRug, ['s_name' => 'hidden']);
$plainRug = seed_item($admin, $rugs, $sue, 'Rug without photo');
$at($plainRug, '2026-09-25 10:00:00');
$bareItem = seed_item($admin, $bare, $sue, 'No photo here');

$key  = (new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), new \mindstellar\apikey\Scopes(), new \mindstellar\utility\SystemClock()))
    ->create('key', 'sue', ['listings:read'], \mindstellar\apikey\KeyOwner::user($sue))->token();
$call = api_admin_caller();
$flat = static function (array $query = []) use ($call, $key): array {
    $out = [];
    foreach ($call('GET', 'categories', null, $key, [], $query + ['include' => 'cover'])->body()['data'] ?? [] as $c) {
        $out[$c['slug']] = $c;
    }

    return $out;
};

harness_section('cover');
$cats = $flat();
pin('a category with listings gets the newest listing\'s first photo', $firstPhoto, $cats['lamps']['cover']['id'] ?? null);
pin('a parent takes the newest photo below it', $firstPhoto, $cats['home']['cover']['id'] ?? null);
pin('a hidden listing is skipped, and a rug without a photo is no cover', null, $cats['rugs']['cover']);
pin('an empty category has null', null, $cats['empty']['cover']);
pin('a category whose listing has no photo has null', null, $cats['bare']['cover']);
check('the cover is shaped as a listing photo', isset($cats['lamps']['cover']['thumbnail'], $cats['lamps']['cover']['preview'], $cats['lamps']['cover']['normal']));
pin('the tree carries it on each node', [$firstPhoto, $firstPhoto], (static function () use ($call, $key): array {
    $tree = $call('GET', 'categories', null, $key, [], ['include' => 'cover', 'tree' => 'true'])->body()['data'];
    $home = array_values(array_filter($tree, static fn (array $c): bool => $c['slug'] === 'home'))[0];
    $lamp = array_values(array_filter($home['children'], static fn (array $c): bool => $c['slug'] === 'lamps'))[0];

    return [$home['cover']['id'] ?? null, $lamp['cover']['id'] ?? null];
})());
check('without include there is no cover member', !array_key_exists('cover', $call('GET', 'categories', null, $key)->body()['data'][0]));
pin('an unknown include is refused', 422, $call('GET', 'categories', null, $key, [], ['include' => 'banner'])->status());
pin('fields can leave it out', false, array_key_exists('cover', $call('GET', 'categories', null, $key, [], ['include' => 'cover', 'fields' => 'id,slug'])->body()['data'][0]));
pin('the response matches the schema', [], api_admin_schema_errors('CategoryList', $call('GET', 'categories', null, $key, [], ['include' => 'cover'])));

harness_section('a new listing moves the cover');
$newest = seed_item($admin, $rugs, $sue, 'Newest rug');
$at($newest, '2026-10-01 10:00:00');
$rugPhoto = seed_photo($admin, $newest, ['s_name' => 'rug']);
\mindstellar\cache\CacheGroup::invalidate('search');
$cats = $flat();
pin('the rug and its parent take the newest photo', [$rugPhoto, $rugPhoto, $firstPhoto], [$cats['rugs']['cover']['id'] ?? null, $cats['home']['cover']['id'] ?? null, $cats['lamps']['cover']['id'] ?? null]);

harness_section('queries');
// The API's own session: a global count also sees the tests the suite runs alongside.
$questions = static fn (): int => (int) (\mindstellar\database\Db::selectOne("SHOW SESSION STATUS LIKE 'Questions'")['Value'] ?? 0);
// Queries one call costs with the category tree warm and the cover cache cold.
$cost = static function (array $query) use ($call, $key, $questions): int {
    \mindstellar\cache\CacheGroup::invalidate('category');
    $call('GET', 'categories', null, $key);
    \mindstellar\cache\CacheGroup::invalidate('search');
    $before = $questions();
    $call('GET', 'categories', null, $key, [], $query);

    return $questions() - $before - 1;
};
$plain = $cost([]);
$with  = $cost(['include' => 'cover']);
for ($i = 0; $i < 8; $i++) {
    $c = seed_category($admin, 'Extra ' . $i, $home, $locale);
    $x = seed_item($admin, $c, $sue, 'Extra item ' . $i);
    seed_photo($admin, $x, ['s_name' => 'extra' . $i]);
}
$more = $cost(['include' => 'cover']);
check('cover adds two queries or fewer', $with - $plain <= 2, "plain $plain, cover $with");
pin('and the same with eight more categories', $with, $more);

exit(harness_result());
