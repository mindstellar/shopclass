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
 * the language falls back, saved menus replace main and add their own with children, the
 * api_menus filter adds a menu, and the schemas match.
 * DB-backed. Usage: php tests/api-pages-menus.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\api\serializer\PageSerializer;
use mindstellar\menu\MenuService;
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
$motors = seed_category($admin, 'Motors');
$cars   = seed_category($admin, 'Cars', $motors);
$ab     = seed_category($admin, 'A &amp; B');
seed_category($admin, 'Off', null, 'en_US', 0);
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_category SET i_position = 1 WHERE pk_i_id = ' . $ab);
$service  = new MenuService(Preference::getInstance(), 'en_US', ['en_US', 'de_DE'], static fn (int $id): string => 'https://s.test/c/' . $id, static fn (int $id, string $slug, string $title, string $locale): string => 'https://s.test/' . $locale . '/' . $slug);
$resolved = static function () use ($service): array {
    $out = [];
    foreach ($service->menuIds() as $id) {
        $out[$id] = $service->resolve($id, 'en_US');
    }

    return $out;
};
$menus = $ser->menus($resolved(), 'en_US');
pin('main and footer', ['main', 'footer'], array_column($menus, 'id'));
pin('nothing saved: main lists the enabled top categories', ['Motors', 'A & B'], array_column($menus[0]['items'], 'label'));
pin('nothing saved: footer lists the linked pages', ['Imprint', 'About us'], array_column($menus[1]['items'], 'label'));
check('nothing saved: no item has children', array_filter(array_column($menus[0]['items'], 'children')) === []);

pin('a saved main is accepted', [], $service->save('main', [
    ['type' => 'link', 'url' => 'https://s.test/sale', 'label' => ['en_US' => 'Sale']],
    ['type' => 'category', 'ref' => $motors, 'children' => [['type' => 'category', 'ref' => $cars]]],
]));
pin('a custom menu is accepted', [], $service->save('top', [['type' => 'page', 'ref' => $about]], 'Top bar'));
$menus = $ser->menus($resolved(), 'en_US');
pin('saved menus follow main and footer', ['main', 'footer', 'top'], array_column($menus, 'id'));
pin('a saved main replaces the automatic one, children kept', [
    ['label' => 'Sale', 'url' => 'https://s.test/sale'],
    ['label' => 'Motors', 'url' => 'https://s.test/c/' . $motors, 'children' => [['label' => 'Cars', 'url' => 'https://s.test/c/' . $cars]]],
], $menus[0]['items']);
pin('footer stays automatic', ['Imprint', 'About us'], array_column($menus[1]['items'], 'label'));
pin('a custom menu resolves its page', [['label' => 'About us', 'url' => 'https://s.test//about']], $menus[2]['items']);

$extra = api_with_filter('api_menus', static function (array $m): array {
    $m['account'] = [['label' => 'Cart', 'url' => 'https://s.test/cart', 'children' => [['label' => 'Saved', 'url' => '/s'], ['label' => 'no url']]], ['label' => 'no url'], 'junk'];
    $m['Bad Id!'] = [['label' => 'x', 'url' => 'y']];
    $m['main'][]  = ['label' => 'Help', 'url' => 'https://s.test/help'];

    return $m;
}, fn (): array => $ser->menus($resolved(), 'en_US'));
pin('a plugin menu is added, a bad id is dropped', ['main', 'footer', 'top', 'account'], array_column($extra, 'id'));
pin('items without a url are dropped, children too', [['label' => 'Cart', 'url' => 'https://s.test/cart', 'children' => [['label' => 'Saved', 'url' => '/s']]]], $extra[3]['items']);
pin('a plugin may edit a core menu', 'Help', $extra[0]['items'][2]['label']);
$service->delete('main');
$service->delete('top');
pin('deleting main brings the automatic one back', ['Motors', 'A & B'], array_column($ser->menus($resolved(), 'en_US')[0]['items'], 'label'));

harness_section('schemas');
$defs      = Schema::components();
$validator = new Validator($defs);
$json      = static fn ($v): array => json_decode((string) json_encode($v), true);
pin('summary valid', [], $validator->check($defs['PageSummary'], $json($list[0])));
pin('page valid', [], $validator->check($defs['Page'], $json($one)));
pin('menu valid', [], $validator->check($defs['Menu'], $json($menus[0])));
pin('menu with children valid', [], $validator->check($defs['Menu'], $json($extra[3])));
check('a child without a url is refused', $validator->check($defs['Menu'], ['id' => 'x', 'items' => [['label' => 'a', 'url' => 'b', 'children' => [['label' => 'c']]]]]) !== []);
check('page without a title is refused', $validator->check($defs['PageSummary'], ['id' => 1]) !== []);
check('site document carries mode and features.shop', isset($defs['Site']['properties']['mode'], $defs['Site']['properties']['features']['properties']['shop']));

exit(harness_result());
