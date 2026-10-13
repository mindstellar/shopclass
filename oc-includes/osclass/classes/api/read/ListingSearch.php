<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\api\read;

use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\RowId;
use mindstellar\api\serializer\Links;
use mindstellar\api\serializer\ViewContext;
use mindstellar\apikey\Credential;
use mindstellar\listing\ListingQuery;
use mindstellar\search\Distance;
use mindstellar\search\SearchBuilder;
use mindstellar\search\SearchCriteria;
use mindstellar\search\SearchExtras;
use mindstellar\search\SearchRunner;

/**
 * A page of listings. run() goes through the search page's own runner, so its filters, hooks,
 * result cache and any search backend apply; newest() lists any status by id.
 */
final class ListingSearch
{
    /** The largest radius, in km: the route's `radius` maximum and the reach of a nearest-first sort. */
    private const MAX_KM = 1000.0;

    /** API filter => search page parameter. */
    private const LISTS = ['category' => 'sCategory', 'country' => 'sCountry', 'region' => 'sRegion', 'city' => 'sCity', 'city_area' => 'sCityArea'];

    /**
     * @param \Closure(Request, Credential): ViewContext $context a request's listing view context
     */
    public function __construct(
        private ListingReader $reader,
        private ListingQuery $listings,
        private Cursor $cursor,
        private Links $links,
        private SiteFacts $facts,
        private \Closure $context
    ) {
    }

    /**
     * A page of listings in any status, newest first and paged by id, read through ListingQuery
     * rather than search. Behind `GET /admin/listings` and `GET /account/listings`.
     *
     * @param string   $path        the endpoint, below /api/v1/, for the page links and the cursor
     * @param string[] $statuses    names from ListingStatus::ALL; all when empty
     * @param int[]    $userIds     only these sellers; any when empty
     * @param int[]    $categoryIds only these categories; any when empty
     */
    public function newest(Request $request, Credential $credential, string $path, array $statuses, array $userIds, array $categoryIds = [], string $title = ''): Response
    {
        $context = ($this->context)($request, $credential);
        $pager   = Pager::fromRequest($request, $this->cursor, ListSpec::byId(), $path);

        return $pager->respond(
            fn (): array => $this->listings->newest($statuses, $userIds, $categoryIds, $title, $pager->afterId(), $pager->limit() + 1),
            fn (): int => $this->listings->count($statuses, $userIds, $categoryIds, $title),
            fn (array $items): array => $this->reader->many($this->reader->extend($items, $context), $context),
            $this->links
        );
    }

    /**
     * @param int|null $userId the seller the list is fixed to
     * @param string   $path   the endpoint, below /api/v1/, for the page links
     */
    public function run(Request $request, Credential $credential, ?int $userId, string $path): Response
    {
        $context = ($this->context)($request, $credential);
        $sort    = ListingSort::fromRequest($request);
        $near    = self::near($request, $sort);
        $filters = $request->query();
        if ($userId !== null) {
            $filters['user'] = (string) $userId;
        }
        $pager = Pager::fromRequest($request, $this->cursor, $sort->spec($this->facts->defaultLimit(), $this->facts->maxLimit()), $path, $filters);

        $params = self::params($request, $this->reader->categories(), $context->locale(), $userId) + [
            'sOrder'     => $sort->searchOrder(),
            'iOrderType' => $sort->direction(),
            'iPagesize'  => $pager->limit(),
        ];
        $result = SearchRunner::run(
            SearchCriteria::fromRequest($params, ['pageSize' => $pager->limit(), 'maxPageSize' => $pager->limit()]),
            new \Search(),
            'api',
            fn (\Search $search) => $this->shape($sort, $pager, $search, $near),
            $pager->counts(),
            $params,
            fn (array $rows): array => $this->reader->extend($rows, $context)
        );

        return $pager->respond(
            static fn (): array => $result->items(),
            static fn (): ?int => $result->total(),
            fn (array $page): array => $this->reader->many($page, $context),
            $this->links,
            self::extrasMeta($result->extras())
        );
    }

    /**
     * What a search engine plugin added, for `meta`; nothing when core searched.
     *
     * @return array<string,mixed>
     */
    private static function extrasMeta(SearchExtras $extras): array
    {
        $counts = $extras->categoryCounts();

        return ($counts === [] ? [] : ['facets' => ['category' => (object) $counts]])
            + ($extras->suggestion() === '' ? [] : ['suggestion' => $extras->suggestion()])
            + ($extras->relaxed() ? ['relaxed' => true] : []);
    }

    /**
     * Finish the search after SearchRunner set its sort and page: a total order (the id
     * breaks ties), the keyset condition, and one row more than the page.
     */
    private function shape(ListingSort $sort, Pager $pager, \Search $search, ?array $near): void
    {
        if ($near !== null) {
            $search->near(...$near);
            if ($sort->name() === 'distance') {
                $search->order('distance', 'asc');
            }
        }
        $columns = $sort->columns();
        if ($columns !== []) {
            $search->orderBy($columns);
        }
        $after = $pager->after();
        if ($after !== null) {
            $search->addCondition(...$sort->after(ListingQuery::tableName(), $after));
        }
        $search->limit($pager->offset(), $pager->limit() + 1);
    }

    /**
     * The point and radius in km of a distance search, or null. The point is rounded to two
     * decimals, as on the search page; with no radius, nearest first keeps to MAX_KM.
     *
     * @return array{0:float,1:float,2:float}|null
     * @throws ProblemException 422 when distance search is off, or a point or radius is half given
     */
    private static function near(Request $request, ListingSort $sort): ?array
    {
        $lat    = $request->queryString('lat');
        $lng    = $request->queryString('lng');
        $radius = $request->queryString('radius');
        if ($lat === '' && $lng === '' && $radius === '' && $sort->name() !== 'distance') {
            return null;
        }
        if (!osc_geo_search_enabled()) {
            throw ProblemException::field($lat !== '' ? '/lat' : ($radius !== '' ? '/radius' : '/sort'), 'rejected', 'is not available: distance search is switched off on this site', 'query');
        }
        $point = Distance::point($lat, $lng);
        if ($point === null) {
            throw ProblemException::field($lat === '' ? '/lat' : '/lng', 'required', 'lat and lng are both needed for a distance search', 'query');
        }

        return [$point[0], $point[1], $radius === '' ? self::MAX_KM : (float) $radius];
    }

    /**
     * The search page parameters for a request's filters.
     *
     * @param string   $locale the request's resolved locale, used for category slugs
     * @param int|null $userId a seller the list is fixed to (`/users/{id}/listings`)
     *
     * @return array<string,mixed>
     * @throws ProblemException 422 for an unknown category or a user that is not an id
     */
    public static function params(Request $request, CategoryCatalog $categories, string $locale, ?int $userId = null): array
    {
        $params = ['sPattern' => trim($request->queryString('q'))];
        foreach (self::LISTS as $name => $param) {
            $values = $request->queryList($name);
            if ($name === 'category') {
                $values = array_map('strval', $categories->resolve($values, $locale));
            }
            if ($values !== []) {
                $params[$param] = $values;
            }
        }
        $users = $userId !== null ? [(string) $userId] : $request->queryList('user');
        foreach ($users as $user) {
            if (RowId::parse($user) === null) {
                throw ProblemException::field('/user', 'format', 'must be a user id: ' . $user, 'query');
            }
        }
        if ($users !== []) {
            $params['sUser'] = $users;
        }
        if ($request->queryString('locale') !== '') {
            $params['sLocale'] = $locale;
        }
        foreach (['price_min' => 'sPriceMin', 'price_max' => 'sPriceMax'] as $name => $param) {
            if ($request->queryString($name) !== '') {
                $params[$param] = $request->queryInt($name);
            }
        }
        if ($request->queryBool('with_photos')) {
            $params['bPic'] = 1;
        }
        if ($request->queryBool('premium')) {
            $params['bPremium'] = 1;
        }
        $meta = $request->query()['custom_field'] ?? null;
        if (is_array($meta) && $meta !== []) {
            self::checkMeta($meta, (array) ($params['sCategory'] ?? []));
            $params['meta'] = $meta;
        }

        return $params;
    }

    /**
     * Each custom_field[<id>] must be a searchable field of the chosen categories, as on the
     * search page; the page ignores the others, the API refuses them.
     *
     * @param array<mixed>  $meta
     * @param array<mixed>  $categoryIds
     *
     * @throws ProblemException 422 for an id that is not a field, or one the categories do not search
     */
    private static function checkMeta(array $meta, array $categoryIds): void
    {
        $searchable = SearchBuilder::searchableFieldIds($categoryIds);
        foreach (array_keys($meta) as $key) {
            $pointer = '/custom_field/' . $key;
            if (RowId::parse((string) $key) === null) {
                throw ProblemException::field($pointer, 'format', 'must be a custom field id', 'query');
            }
            if ($categoryIds === []) {
                throw ProblemException::field($pointer, 'required', 'needs a category filter that has this field', 'query');
            }
            if (!in_array((int) $key, $searchable, true)) {
                throw ProblemException::field($pointer, 'invalid', 'is not a searchable custom field of the chosen category', 'query');
            }
        }
    }
}
