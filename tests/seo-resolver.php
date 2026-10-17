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
 * SeoResolver: each kind of page gets a canonical URL, title, description, robots, breadcrumbs,
 * hreflang and Open Graph data from the rows it is given. DB-free. Usage: php tests/seo-resolver.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\read\CategoryCatalog;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\seo\SeoResolver;

if (!function_exists('__')) {
    function __($text)
    {
        return $text;
    }
}

$url = static function (string $kind, array $args, string $locale): string {
    $lang = $locale !== '' ? $locale . '/' : '';

    return match ($kind) {
        'listing'  => 'https://s.test/' . $lang . 'item-' . $args['item']['pk_i_id'] . '-' . $args['item']['s_title'],
        'page'     => 'https://s.test/' . $lang . $args['slug'],
        'category' => 'https://s.test/c/' . $args['slug'],
        'search'   => 'https://s.test/search' . ($args['q'] !== '' ? '?q=' . $args['q'] : ''),
        default    => 'https://s.test/',
    };
};
$seo = new SeoResolver('en_US', ['en_US', 'de_DE'], 'Shop', 'The <b>best</b> shop.', $url);
$one = new SeoResolver('en_US', ['en_US'], 'Shop', '', $url);

$catalog = new CategoryCatalog([
    ['pk_i_id' => 1, 'fk_i_parent_id' => null, 's_name' => 'Motors', 's_slug' => 'motors', 's_description' => 'All motors', 'i_position' => 0],
    ['pk_i_id' => 2, 'fk_i_parent_id' => 1, 's_name' => 'Cars', 's_slug' => 'cars', 's_description' => '', 'i_position' => 0,
        'locale' => ['de_DE' => ['s_name' => 'Autos', 's_slug' => 'autos', 's_description' => 'Alle Autos']]],
    ['pk_i_id' => 3, 'fk_i_parent_id' => 2, 's_name' => 'Vans', 's_slug' => 'vans', 's_description' => '', 'i_position' => 0],
]);
$path = static fn (int $id): array => [...$catalog->ancestors($id), (array) $catalog->find($id)];
$item = static fn (string $status = 'live'): array => [
    'pk_i_id' => 9, 'fk_i_category_id' => 3, 's_title' => 'Red van',
    'b_enabled' => $status === 'hidden' ? 0 : 1, 'b_active' => 1, 'b_spam' => 0, 'b_premium' => 0,
    'dt_expiration' => $status === 'expired' ? '2000-01-01 00:00:00' : '2999-01-01 00:00:00',
    'locale' => ['en_US' => ['s_title' => 'Red van', 's_description' => '<p>Runs   well.</p>'], 'de_DE' => ['s_title' => 'Roter Van', 's_description' => 'Laeuft']],
];

harness_section('home');
$home = $seo->home('en_US');
pin('canonical', 'https://s.test/', $home['canonical']);
pin('title is the site title alone', 'Shop', $home['title']);
pin('description is plain text', 'The best shop.', $home['description']);
pin('robots', 'index, follow', $home['robots']);
pin('breadcrumbs', [['label' => 'Home', 'url' => 'https://s.test/']], $home['breadcrumbs']);
pin('no hreflang', [], $home['hreflang']);

harness_section('listing');
$l = $seo->listing($item(), $path(3), 'en_US', 'https://s.test/p.jpg');
pin('canonical', 'https://s.test/item-9-Red van', $l['canonical']);
pin('title', 'Red van - Shop', $l['title']);
pin('description is stripped and squeezed', 'Runs well.', $l['description']);
pin('breadcrumb chain for a nested category', ['Home', 'Motors', 'Cars', 'Vans', 'Red van'], array_column($l['breadcrumbs'], 'label'));
pin('category crumbs link to the category', 'https://s.test/c/cars', $l['breadcrumbs'][2]['url']);
pin('hreflang lists both languages and x-default', ['en-US', 'de-DE', 'x-default'], array_column($l['hreflang'], 'locale'));
pin('the German entry uses the German title and a language prefix', 'https://s.test/de_DE/item-9-Roter Van', $l['hreflang'][1]['url']);
pin('x-default is the default language', 'https://s.test/item-9-Red van', $l['hreflang'][2]['url']);
pin('og', ['product', 'Red van', 'Shop', 'https://s.test/p.jpg'], [$l['og']['type'], $l['og']['title'], $l['og']['site_name'], $l['og']['image']]);
$de = $seo->listing($item(), $path(3), 'de_DE', null);
pin('locale switches the title and the canonical', ['Roter Van - Shop', 'https://s.test/de_DE/item-9-Roter Van', 'de_DE'], [$de['title'], $de['canonical'], $de['locale']]);
pin('a listing with one language has no hreflang', [], $one->listing($item(), $path(3), 'en_US', null)['hreflang']);
pin('an expired listing is not indexed', 'noindex, follow', $seo->listing($item('expired'), $path(3), 'en_US', null)['robots']);
pin('a hidden listing has no answer', null, $seo->listing($item('hidden'), $path(3), 'en_US', null));
pin('an unknown language falls back to the one the listing has', 'en_US', $seo->listing(['locale' => ['en_US' => ['s_title' => 'Only']]] + $item(), $path(3), 'fr_FR', null)['locale']);

harness_section('category');
$c = $seo->category($path(3), 'en_US');
pin('breadcrumbs end at the category', ['Home', 'Motors', 'Cars', 'Vans'], array_column($c['breadcrumbs'], 'label'));
pin('title', 'Vans - Shop', $c['title']);
pin('empty description falls back to the site description', 'The best shop.', $c['description']);
$cd = $seo->category($path(2), 'de_DE');
pin('locale switches name, slug and description', ['Autos - Shop', 'https://s.test/c/autos', 'Alle Autos'], [$cd['title'], $cd['canonical'], $cd['description']]);

harness_section('page');
$pageDoc = ['id' => 4, 'slug' => 'about', 'title' => 'About us', 'url' => 'https://s.test/about', 'locale' => 'en_US', 'text' => '<p>We sell things.</p>', 'meta' => ['description' => 'Who we are']];
$texts   = ['en_US' => ['s_title' => 'About us'], 'de_DE' => ['s_title' => 'Ueber uns']];
$p       = $seo->page($pageDoc, $texts);
pin('meta description wins over the text', 'Who we are', $p['description']);
pin('breadcrumbs', ['Home', 'About us'], array_column($p['breadcrumbs'], 'label'));
pin('hreflang', ['en-US', 'de-DE', 'x-default'], array_column($p['hreflang'], 'locale'));
pin('without a meta description the text is used', 'We sell things.', $seo->page(['meta' => []] + $pageDoc, $texts)['description']);

harness_section('search');
$s = $seo->search('en_US', 'red van');
pin('search is not indexed', 'noindex, follow', $s['robots']);
pin('title carries the words', 'Search results: red van - Shop', $s['title']);
pin('canonical', 'https://s.test/search?q=red van', $s['canonical']);
pin('no words', 'Search results - Shop', $seo->search('en_US', '')['title']);

harness_section('long text and the schema');
pin('a long description is cut to 160 characters', 160, mb_strlen($seo->page(['meta' => ['description' => str_repeat('word ', 80)]] + $pageDoc, $texts)['description'], 'UTF-8'));
$defs      = Schema::components();
$validator = new Validator($defs);
$json      = static fn ($v): array => json_decode((string) json_encode($v), true);
foreach (['home' => $home, 'listing' => $l, 'category' => $c, 'page' => $p, 'search' => $s] as $name => $doc) {
    pin($name . ' answer matches the Seo schema', [], $validator->check($defs['Seo'], $json($doc)));
}

exit(harness_result());
