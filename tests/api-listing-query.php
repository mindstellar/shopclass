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
 * How list endpoints read their query: listing filters, the shared Pager, cursors and page links.
 * Usage: php tests/api-listing-query.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\ProblemException;
use mindstellar\api\read\CategoryCatalog;
use mindstellar\api\read\Cursor;
use mindstellar\api\read\CursorState;
use mindstellar\api\read\ListingSearch;
use mindstellar\api\read\ListingSort;
use mindstellar\api\read\Page;
use mindstellar\api\read\Pager;
use mindstellar\api\read\SiteFacts;
use mindstellar\api\Request;
use mindstellar\api\serializer\Links;

define('OSC_CSRF_SECRET', 'api-listing-query-test-secret');

$cursor  = new Cursor();
$catalog = new CategoryCatalog([
    ['pk_i_id' => '1', 'fk_i_parent_id' => null, 's_slug' => 'vehicles', 'locale' => ['en_US' => ['s_slug' => 'vehicles', 's_name' => 'Vehicles']]],
    ['pk_i_id' => '2', 'fk_i_parent_id' => '1', 's_slug' => 'cars', 'locale' => ['en_US' => ['s_slug' => 'cars', 's_name' => 'Cars']]],
]);
$request = static fn (array $q): Request => new Request('GET', 'v1/listings', $q);
$params  = static fn (array $q, ?int $user = null): array => ListingSearch::params($request($q), $catalog, 'de_DE', $user);
$pager   = static fn (array $q, string $sort = 'created', string $order = 'desc'): Pager => Pager::fromRequest(
    $request($q),
    $cursor,
    ListingSort::of($sort, $order)->spec(12, 50),
    'listings',
    $q
);
$problem = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (ProblemException $e) {
        $body = $e->response()->body();

        return $e->response()->status() . ' ' . $body['code'] . (isset($body['errors'][0]) ? ' ' . $body['errors'][0]['pointer'] : '');
    }

    return null;
};

harness_section('filters');
pin('no filters: only the empty pattern', ['sPattern' => ''], $params([]));
pin('API names become the search page\'s own', [
    'sPattern'  => 'red bike',
    'sCategory' => ['2', '1'],
    'sCountry'  => ['DE'],
    'sRegion'   => ['5', '6'],
    'sCity'     => ['9'],
    'sCityArea' => ['3'],
    'sUser'     => ['7'],
    'sLocale'   => 'de_DE',
    'sPriceMin' => 10,
    'sPriceMax' => 500,
    'bPic'      => 1,
    'bPremium'  => 1,
    'meta'      => ['12' => 'blue'],
], $params([
    'q' => 'red bike', 'category' => 'cars,1', 'country' => 'DE', 'region' => ['5', '6'], 'city' => '9', 'city_area' => '3',
    'user' => '7', 'locale' => 'de_DE', 'price_min' => 10, 'price_max' => 500, 'with_photos' => true, 'premium' => true,
    'custom_field' => ['12' => 'blue'],
]));
pin('a pattern sorts by relevance unless told otherwise', ['relevance', 'created', 'price'], [
    ListingSort::fromRequest($request(['q' => 'x']))->name(), ListingSort::fromRequest($request([]))->name(), ListingSort::fromRequest($request(['q' => 'x', 'sort' => 'price']))->name(),
]);
pin('a sort is made total by the id, in its direction', [[['dt_pub_date', 'DESC'], ['pk_i_id', 'DESC']], [['i_price', 'ASC'], ['pk_i_id', 'ASC']], []], [
    ListingSort::of('created')->columns(), ListingSort::of('price', 'asc')->columns(), ListingSort::of('relevance')->columns(),
]);
pin('the keyset condition for newest first', [
    '(t.dt_pub_date < ? OR (t.dt_pub_date = ? AND t.pk_i_id < ?))', ['2026-10-01 10:00:00', '2026-10-01 10:00:00', 5],
], ListingSort::of('created')->after('t', ['2026-10-01 10:00:00', 5]));
pin('the keyset condition for ids, oldest first', ['t.pk_i_id > ?', [5]], ListingSort::of('id', 'asc')->after('t', [5]));
pin('the keyset condition for price, highest first: NULL prices come last', [
    '(t.i_price < ? OR t.i_price IS NULL OR (t.i_price = ? AND t.pk_i_id < ?))', [900, 900, 5],
], ListingSort::of('price')->after('t', [900, 5]));
pin('after a NULL price, highest first, only NULL prices with a lower id follow', ['(t.i_price IS NULL AND t.pk_i_id < ?)', [5]], ListingSort::of('price')->after('t', [null, 5]));
pin('the keyset condition for price, lowest first', [
    '(t.i_price > ? OR (t.i_price = ? AND t.pk_i_id > ?))', [900, 900, 5],
], ListingSort::of('price', 'asc')->after('t', [900, 5]));
pin('after a NULL price, lowest first, every priced row follows', [
    '(t.i_price IS NOT NULL OR (t.i_price IS NULL AND t.pk_i_id > ?))', [5],
], ListingSort::of('price', 'asc')->after('t', [null, 5]));
pin('a price keyset is a price or null, then an id', [true, true, false, false, false], [
    ListingSort::of('price')->keysetFits([900, 5]), ListingSort::of('price')->keysetFits([null, 5]),
    ListingSort::of('price')->keysetFits(['900', 5]), ListingSort::of('price')->keysetFits([900]), ListingSort::of('relevance')->keysetFits([5]),
]);
pin('a row keeps a NULL price in its keyset', [[null, 7], [1500, 8]], [
    ListingSort::of('price')->keyset(['pk_i_id' => '7', 'i_price' => null]), ListingSort::of('price')->keyset(['pk_i_id' => '8', 'i_price' => '1500']),
]);
pin('/users/{id}/listings fixes the seller, whatever user= says', ['3'], $params(['user' => '7'], 3)['sUser']);
pin('an unknown category is refused, not ignored', '422 validation_failed /category', $problem(static fn () => $params(['category' => 'boats'])));
pin('a user that is not an id is refused, not ignored', '422 validation_failed /user', $problem(static fn () => $params(['user' => '7,x'])));
$facts = new SiteFacts('en_US', ['en_US' => ['name' => 'English', 'direction' => 'ltr'], 'de_DE' => ['name' => 'Deutsch', 'direction' => 'ltr']]);
pin('the locale is resolved once: asked, or the default', ['de_DE', 'en_US'], [$facts->locale('de_DE'), $facts->locale('')]);
pin('a locale the site lacks is refused', '422 validation_failed /locale', $problem(static fn () => $facts->locale('fr_FR')));

harness_section('pager');
pin('a limit past the site\'s page size is refused', '422 validation_failed /limit', $problem(static fn () => $pager(['limit' => 51])));
pin('as is a limit of 0', '422 validation_failed /limit', $problem(static fn () => $pager(['limit' => 0])));
$rows = [];
for ($i = 0; $i < 4; $i++) {
    $rows[] = ['pk_i_id' => (string) (20 - $i), 'dt_pub_date' => '2026-10-0' . (5 - $i) . ' 10:00:00'];
}
$first  = $pager(['category' => 'cars', 'limit' => 3]);
pin('a page drops the look-ahead row', ['20', '19', '18'], array_column($first->page($rows), 'pk_i_id'));
pin('the first page is not counted unless asked', [false, true], [$first->counts(), $pager(['limit' => 3, 'count' => 'true'])->counts()]);
$next = $first->next($rows);
check('a full page plus one makes a next cursor', is_string($next));
pin('no next cursor on the last page', null, $first->next(array_slice($rows, 0, 3)));
$second = $pager(['category' => 'cars', 'limit' => 3, 'cursor' => $next]);
pin('the next page starts after the last row', [['2026-10-03 10:00:00', 18], 0], [$second->after(), $second->offset()]);
check('a keyset page after the first is not counted, even when asked', !($pager(['category' => 'cars', 'limit' => 3, 'count' => 'true', 'cursor' => $next])->counts()));
pin('a cursor survives a changed limit', null, $problem(static fn () => $pager(['category' => 'cars', 'limit' => 5, 'cursor' => $next])));
pin('a cursor made for other filters is refused', '400 invalid_cursor', $problem(static fn () => $pager(['category' => 'vehicles', 'limit' => 3, 'cursor' => $next])));
pin('a cursor made for another order is refused', '400 invalid_cursor', $problem(static fn () => $pager(['category' => 'cars', 'cursor' => $next], 'created', 'asc')));
pin('a tampered cursor is refused', '400 invalid_cursor', $problem(static fn () => $pager(['category' => 'cars', 'cursor' => $next . 'x'])));
$hash  = Cursor::filterHash(['category' => 'cars', 'sort' => 'created', 'order' => 'desc']);
$badAt = $cursor->encode(CursorState::keyset('created', 'desc', $hash, ["2026-10-01' OR '1'='1", 5]));
pin('a keyset value that is not a plain datetime is refused, even signed', '400 invalid_cursor', $problem(static fn () => $pager(['category' => 'cars', 'cursor' => $badAt])));
$byScore = $pager(['sort' => 'relevance', 'limit' => 3], 'relevance');
$offset  = $byScore->next($rows);
$later   = $pager(['sort' => 'relevance', 'limit' => 3, 'count' => '1', 'cursor' => $offset], 'relevance');
pin('relevance pages by offset, and offset pages keep their total when asked', [3, null, true], [$later->offset(), $later->after(), $later->counts()]);
check('a page with a next one is not truncated', !($byScore->truncated($rows)));
$deep     = $cursor->encode(CursorState::offset('relevance', 'desc', Cursor::filterHash(['sort' => 'relevance', 'order' => 'desc']), 9998));
$deepPage = $pager(['sort' => 'relevance', 'limit' => 3, 'cursor' => $deep], 'relevance');
pin('past the deepest offset: no next cursor, and truncated', [null, true], [$deepPage->next($rows), $deepPage->truncated($rows)]);
check('the real end of the list is not truncated', !($deepPage->truncated(array_slice($rows, 0, 3))));
$priced    = [['pk_i_id' => '9', 'i_price' => '500'], ['pk_i_id' => '7', 'i_price' => null], ['pk_i_id' => '4', 'i_price' => null]];
$byPrice   = $pager(['sort' => 'price', 'limit' => 2], 'price');
$afterNull = $pager(['sort' => 'price', 'limit' => 2, 'count' => '1', 'cursor' => (string) $byPrice->next($priced)], 'price');
pin('price pages by keyset, through a NULL price, and skips the count', [[null, 7], 0, false], [$afterNull->after(), $afterNull->offset(), $afterNull->counts()]);

harness_section('page links');
$links = new class () implements Links {
    public function listing(array $item): string
    {
        return '';
    }

    public function photo(array $resource, string $variant): string
    {
        return '';
    }

    public function user(int $id, string $username): string
    {
        return '';
    }

    public function avatar(int $userId): string
    {
        return '';
    }

    public function api(string $path, ?string $version = null): string
    {
        return 'https://site.test/api/' . ($version ?? 'v1') . '/' . $path;
    }

    public function price(?int $micros, string $symbol): string
    {
        return '';
    }
};
$body = (new Page([['id' => 1]], null, 3, 'NEXT'))->response($links, 'listings', ['category' => 'cars', 'api_key' => 'scp_secret.abc', 'cursor' => 'OLD'])->body();
pin('self keeps the query, next swaps the cursor, neither carries the api_key', [
    'self' => 'https://site.test/api/v1/listings?category=cars&cursor=OLD',
    'next' => 'https://site.test/api/v1/listings?category=cars&cursor=NEXT',
], $body['links']);
pin('meta carries the total and limit', ['total' => null, 'limit' => 3], $body['meta']);
pin('a truncated page says so in meta', ['total' => null, 'limit' => 3, 'truncated' => true], (new Page([['id' => 1]], null, 3, null, true))->response($links, 'listings', [])->body()['meta']);

exit(harness_result());
