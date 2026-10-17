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
 * The page and menu reads behind GET /pages, /pages/{id|slug} and /menus, and the site
 * document's mode: unlinked and system pages stay out of the list, id and slug both resolve,
 * the language falls back, the api_menus filter adds a menu, and the schemas match.
 * DB-backed. Usage: php tests/api-pages-menus.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\read\CategoryCatalog;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\api\serializer\PageSerializer;
use mindstellar\pages\PageQuery;

$admin = scratchdb_session('osc_api_pages_menus');

require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';

seed_locale($admin, 'en_US', 'English');
seed_locale($admin, 'de_DE', 'Deutsch');

$about   = seed_page($admin, 'about', 'About us');
$hidden  = seed_page($admin, 'hidden', 'Hidden', 0);
$system  = seed_page($admin, 'email_test', 'System mail', 0, 1);
$german  = seed_page($admin, 'imprint', 'Imprint', 1, 0, 'de_DE');
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_pages SET i_order = 2 WHERE pk_i_id = ' . $about);
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_pages SET i_order = 1, s_meta = \'{"keywords":"a, b","empty":""}\' WHERE pk_i_id = ' . $german);

$query = new PageQuery();
$ser   = new PageSerializer('en_US', static fn (int $id, string $slug, string $title, string $locale): string => 'https://s.test/' . $locale . '/' . $slug);

$rows  = $query->linked();
$texts = $query->texts(array_map(static fn (array $r): int => (int) $r['pk_i_id'], $rows));
$list  = array_values(array_filter(array_map(fn (array $r): ?array => $ser->summary($r, $texts[(int) $r['pk_i_id']] ?? [], 'en_US'), $rows)));

harness_section('list');
pin('only linked public pages, in order', ['imprint', 'about'], array_column($list, 'slug'));
check('unlinked page is not listed', !in_array('hidden', array_column($list, 'slug'), true));

harness_section('one page');
check('unlinked page reads by id', $query->find((string) $hidden) !== null);
pin('id and slug find the same page', $query->find((string) $about), $query->find('about'));
check('system page is not readable', $query->find((string) $system) === null && $query->find('email_test') === null);
check('unknown slug is null', $query->find('nope') === null && $query->find('') === null);

$page = $query->find('imprint');
$one  = $ser->one($page, $query->texts([(int) $page['pk_i_id']])[(int) $page['pk_i_id']], 'en_US');
pin('falls back to the only language the page has', 'de_DE', $one['locale']);
pin('title', 'Imprint', $one['title']);
pin('url has the language when it is not the default', 'https://s.test/de_DE/imprint', $one['url']);
pin('meta keeps non-empty values', ['keywords' => 'a, b'], (array) $one['meta']);
check('text is HTML', str_contains($one['text'], '<p>Imprint</p>'));

$aboutPage = $query->find('about');
$aboutOne  = $ser->one($aboutPage, $query->texts([$about])[$about], 'de_DE');
pin('asked language missing: default language', 'en_US', $aboutOne['locale']);
pin('default language url has no prefix', 'https://s.test//about', $aboutOne['url']);

$filtered = api_with_filter('static_page_text', static fn (string $t): string => $t . '<!--f-->', fn (): array => $ser->one($aboutPage, $query->texts([$about])[$about], 'en_US'));
check('static_page_text filter applies', str_contains($filtered['text'], '<!--f-->'));

check('page with no text is skipped', $ser->summary($aboutPage, [], 'en_US') === null);

harness_section('menus');
$catalog = new CategoryCatalog([
    ['pk_i_id' => 1, 'fk_i_parent_id' => null, 's_name' => 'Motors', 's_slug' => 'motors', 'i_position' => 0],
    ['pk_i_id' => 2, 'fk_i_parent_id' => 1, 's_name' => 'Cars', 's_slug' => 'cars', 'i_position' => 0],
    ['pk_i_id' => 3, 'fk_i_parent_id' => null, 's_name' => 'A &amp; B', 's_slug' => 'ab', 'i_position' => 1],
]);
$catUrl = static fn (array $row): string => 'https://s.test/c/' . $row['s_slug'];
$menus  = $ser->menus($list, $catalog, 'en_US', $catUrl);
pin('main and footer', ['main', 'footer'], array_column($menus, 'id'));
pin('main lists top categories only', ['Motors', 'A & B'], array_column($menus[0]['items'], 'label'));
pin('footer lists the linked pages', ['Imprint', 'About us'], array_column($menus[1]['items'], 'label'));

$extra = api_with_filter('api_menus', static function (array $m): array {
    $m['account'] = [['label' => 'Cart', 'url' => 'https://s.test/cart'], ['label' => 'no url'], 'junk'];
    $m['Bad Id!'] = [['label' => 'x', 'url' => 'y']];
    $m['main'][]  = ['label' => 'Sale', 'url' => 'https://s.test/sale'];

    return $m;
}, fn (): array => $ser->menus($list, $catalog, 'en_US', $catUrl));
pin('a plugin menu is added, a bad id is dropped', ['main', 'footer', 'account'], array_column($extra, 'id'));
pin('an item without a url is dropped', [['label' => 'Cart', 'url' => 'https://s.test/cart']], $extra[2]['items']);
pin('a plugin may edit a core menu', 'Sale', $extra[0]['items'][2]['label']);

harness_section('schemas');
$defs      = Schema::components();
$validator = new Validator($defs);
$json      = static fn ($v): array => json_decode((string) json_encode($v), true);
pin('summary valid', [], $validator->check($defs['PageSummary'], $json($list[0])));
pin('page valid', [], $validator->check($defs['Page'], $json($one)));
pin('menu valid', [], $validator->check($defs['Menu'], $json($menus[0])));
check('page without a title is refused', $validator->check($defs['PageSummary'], ['id' => 1]) !== []);
check('site document carries mode and features.shop', isset($defs['Site']['properties']['mode'], $defs['Site']['properties']['features']['properties']['shop']));

exit(harness_result());
