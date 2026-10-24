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
 * MenuService: save refuses a bad id, an unknown type, a missing category or page, a bad
 * link address, a link with no label, more than 3 levels, more than 200 items and a 21st
 * menu; labels are kept as plain text in the site's languages only. resolve skips hidden
 * and missing targets with their children and falls back to the target's own name.
 * DB-backed. Usage: php tests/menu-service.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/api-boot.php';
require_once __DIR__ . '/lib/stubs.php';

use mindstellar\menu\MenuService;

$admin = scratchdb_session('osc_menu_service');

seed_locale($admin, 'en_US', 'English');
seed_locale($admin, 'de_DE', 'Deutsch');

$motors = seed_category($admin, 'Motors');
$cars   = seed_category($admin, 'Cars', $motors);
$off    = seed_category($admin, 'Off', null, 'en_US', 0);
$under  = seed_category($admin, 'Under off', $off);
seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_category_description (fk_i_category_id, fk_c_locale_code, s_name, s_description, s_slug) VALUES (?, 'de_DE', 'Autos', '', 'autos')", 'i', [$cars]);
$about  = seed_page($admin, 'about', 'About us');
$system = seed_page($admin, 'email_x', 'System', 0, 1);

$menus = new MenuService(Preference::getInstance(), 'en_US', ['en_US', 'de_DE'], static fn (int $id): string => '/c/' . $id, static fn (int $id, string $slug, string $title, string $locale): string => '/' . $locale . '/' . $slug);
$link  = static fn (string $label = 'Home', string $url = '/'): array => ['type' => 'link', 'url' => $url, 'label' => ['en_US' => $label]];
$fails = static fn (array $errors, string $needle): bool => $errors !== [] && str_contains(implode(' ', $errors), $needle);

harness_section('ids');
foreach (['', 'Main', '1top', 'a b', str_repeat('a', 33), 'top!'] as $bad) {
    check('id ' . json_encode($bad) . ' is refused', $fails($menus->save($bad, [$link()]), 'menu id'));
}
pin('a 32-character id is fine', [], $menus->save('a' . str_repeat('b', 31), [$link()]));
$menus->delete('a' . str_repeat('b', 31));

harness_section('items');
check('not a list is refused', $fails($menus->save('top', ['a' => $link()]), 'could not be read'));
check('an unknown type is refused', $fails($menus->save('top', [['type' => 'tag', 'ref' => 1]]), 'unknown type'));
check('a missing category is refused', $fails($menus->save('top', [['type' => 'category', 'ref' => 9999]]), 'category'));
check('a category ref of 0 is refused', $fails($menus->save('top', [['type' => 'category', 'ref' => 0]]), 'category'));
check('a missing page is refused', $fails($menus->save('top', [['type' => 'page', 'ref' => 9999]]), 'page'));
check('a system page is refused', $fails($menus->save('top', [['type' => 'page', 'ref' => $system]]), 'page'));
foreach (['javascript:alert(1)', '//evil.test', 'ftp://x.test', 'about', '/a b', '/"x'] as $bad) {
    check('link ' . json_encode($bad) . ' is refused', $fails($menus->save('top', [$link('X', $bad)]), 'address'));
}
check('a link with no label is refused', $fails($menus->save('top', [['type' => 'link', 'url' => '/x', 'label' => ['en_US' => '  ']]]), 'label'));
check('a label in a language the site lacks does not count', $fails($menus->save('top', [['type' => 'link', 'url' => '/x', 'label' => ['fr_FR' => 'Oui']]]), 'label'));
check('nothing was stored by refused saves', $menus->load('top') === null);

$deep = [$link('1')];
$deep[0]['children'] = [$link('2')];
$deep[0]['children'][0]['children'] = [$link('3')];
pin('three levels are fine', [], $menus->save('top', $deep));
$deep[0]['children'][0]['children'][0]['children'] = [$link('4')];
check('a fourth level is refused', $fails($menus->save('top', $deep), 'levels'));

pin('200 items are fine', [], $menus->save('top', array_fill(0, 200, $link())));
check('201 items are refused', $fails($menus->save('top', array_fill(0, 201, $link())), '200 items'));
$nested = array_fill(0, 100, $link());
$nested[0]['children'] = array_fill(0, 101, $link());
check('children count towards the 200', $fails($menus->save('top', $nested), '200 items'));

pin('a mixed menu saves', [], $menus->save('top', [
    ['type' => 'link', 'url' => 'https://example.test/a?b=1', 'label' => ['en_US' => '<b>Sale</b>  now', 'de_DE' => 'Angebot', 'fr_FR' => 'Solde', 'xx' => ['x']]],
    ['type' => 'category', 'ref' => (string) $motors, 'label' => ['en_US' => '']],
], ' <i>Top</i> bar '));
$saved = $menus->load('top');
pin('the label is plain text', 'Top bar', $saved['label']);
pin('labels: tags out, spaces folded, other languages dropped', ['en_US' => 'Sale now', 'de_DE' => 'Angebot'], $saved['items'][0]['label']);
pin('a numeric string ref is stored as an int, an empty label left out', ['type' => 'category', 'ref' => $motors], $saved['items'][1]);
$menus->save('top', [$link(str_repeat('é', 150))]);
pin('a long label is cut to 100 characters', 100, mb_strlen($menus->load('top')['items'][0]['label']['en_US']));

harness_section('menu limit');
for ($i = 1; $i <= 19; $i++) {
    $menus->save('m' . $i, [$link()]);
}
pin('20 custom menus (top + 19)', 20, count(array_diff($menus->ids(), MenuService::CORE)));
check('a 21st is refused', $fails($menus->save('m20', [$link()]), 'at most 20 menus'));
pin('an existing one still saves', [], $menus->save('m1', [$link('Again')]));
pin('main does not count towards the limit', [], $menus->save('main', [$link()]));
pin('menuIds: main and footer first, then the saved ones', ['main', 'footer', 'm1'], array_slice($menus->menuIds(), 0, 3));
for ($i = 1; $i <= 19; $i++) {
    $menus->delete('m' . $i);
}
$menus->delete('main');
check('a deleted menu is gone in the same request', $menus->load('m1') === null && !in_array('m1', $menus->ids(), true));

harness_section('resolve');
pin('nothing saved: main is the enabled top categories', [['label' => 'Motors', 'url' => '/c/' . $motors]], $menus->resolve('main', 'en_US'));
pin('nothing saved: footer is the linked pages', [['label' => 'About us', 'url' => '//about']], $menus->resolve('footer', 'en_US'));
pin('an unknown menu is empty', [], $menus->resolve('nope', 'en_US'));
pin('a bad id is empty', [], $menus->resolve('Bad!', 'en_US'));

$menus->save('top', [
    ['type' => 'category', 'ref' => $motors, 'children' => [
        ['type' => 'category', 'ref' => $cars],
        ['type' => 'page', 'ref' => $about, 'label' => ['de_DE' => 'Über uns']],
    ]],
    ['type' => 'category', 'ref' => $under, 'children' => [$link('Kept?')]],
    ['type' => 'link', 'url' => '/x', 'label' => ['en_US' => 'Only English']],
]);
pin('nested, disabled branch skipped with its children', [
    ['label' => 'Motors', 'url' => '/c/' . $motors, 'children' => [
        ['label' => 'Cars', 'url' => '/c/' . $cars],
        ['label' => 'About us', 'url' => '//about'],
    ]],
    ['label' => 'Only English', 'url' => '/x'],
], $menus->resolve('top', 'en_US'));
$de = $menus->resolve('top', 'de_DE');
pin('German: a category falls back to its own translated name', 'Autos', $de[0]['children'][0]['label']);
pin('German: the owner\'s German label wins', 'Über uns', $de[0]['children'][1]['label']);
pin('German: a link falls back to the default language label', 'Only English', $de[1]['label']);

$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_category SET b_enabled = 0 WHERE pk_i_id = ' . $motors);
\mindstellar\cache\CacheGroup::changed('category');
pin('a category switched off later is skipped with its children', [['label' => 'Only English', 'url' => '/x']], $menus->resolve('top', 'en_US'));
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_category SET b_enabled = 1 WHERE pk_i_id = ' . $motors);
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_pages WHERE pk_i_id = ' . $about);
\mindstellar\cache\CacheGroup::changed('category');
\mindstellar\cache\CacheGroup::changed('page');
pin('a deleted page is skipped', ['Cars'], array_column($menus->resolve('top', 'en_US')[0]['children'], 'label'));

harness_section('theme places');
$support = \mindstellar\theme\ThemeSupports::getInstance();
$support->reset();
pin('no declaration: no places', [], $menus->themePlaces());
$support->add('menus', [
    'main'    => ['label' => ' Top bar ', 'depth' => 1],
    'footer'  => ['label' => 'Footer'],
    'Bad Id'  => ['label' => 'Nope'],
    'nolabel' => ['depth' => 2],
    'blank'   => ['label' => '  '],
    'zero'    => ['label' => 'Zero', 'depth' => 0],
    'four'    => ['label' => 'Four', 'depth' => 4],
    'text'    => ['label' => 'Text', 'depth' => '2'],
    'junk'    => 'Junk',
    'side'    => ['label' => 'Side', 'depth' => 2],
]);
pin('valid places are kept, bad ones skipped', ['main' => ['label' => 'Top bar', 'depth' => 1], 'footer' => ['label' => 'Footer', 'depth' => 3], 'side' => ['label' => 'Side', 'depth' => 2]], $menus->themePlaces());
$support->add('menus', 'not an array');
pin('a non-array declaration gives no places', [], $menus->themePlaces());
$support->add('menus', ['side' => ['label' => 'Side']]);
check('a declared unsaved menu is editable', in_array('side', $menus->editableIds(), true) && !in_array('side', $menus->menuIds(), true));

$tree = [$link('a')];
$tree[0]['children'] = [$link('b'), $link('c')];
$tree[0]['children'][0]['children'] = [$link('d')];
pin('items beyond level 1', 3, MenuService::itemsBeyond($tree, 1));
pin('items beyond level 2', 1, MenuService::itemsBeyond($tree, 2));
pin('items beyond level 3', 0, MenuService::itemsBeyond($tree, 3));
$support->reset();

exit(harness_result());
