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

namespace mindstellar\api\controller;

use mindstellar\api\ApiServices;
use mindstellar\api\auth\Credential;
use mindstellar\api\ProblemException;
use mindstellar\api\read\CommentStatus;
use mindstellar\api\read\ListingReader;
use mindstellar\api\read\ListingSearch;
use mindstellar\api\read\ListSpec;
use mindstellar\api\read\Page;
use mindstellar\api\read\Pager;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\CommentSerializer;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\api\serializer\ViewContext;
use mindstellar\listing\ListingPolicy;

/**
 * Listings: search, one listing, its photos and its comments. Search leaves out listings that
 * are not live. One listing answers as its page does: an expired one with status `expired`,
 * a hidden one (pending, disabled, spam) 404 except to its owner and to admin keys with
 * `admin:listings`. An API read never counts as a view. The seller's writes are ListingWritesController's.
 */
final class ListingsController
{
    private ListingSearch $search;
    private ListingReader $reader;

    public function __construct(private ApiServices $api)
    {
        $this->search = $api->listingSearch();
        $this->reader = $api->listingReader();
    }

    /**
     * @param array<string,string> $args
     */
    public function index(Request $request, Credential $credential, array $args): Response
    {
        return $this->search->run($request, $credential, null, 'listings');
    }

    /**
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        $context = $this->api->context($request, $credential, 'listing', ListingSerializer::MEMBERS, ListingSerializer::INCLUDES);
        $item    = $this->reader->row((int) $args['id']);
        if ($item === null || !ListingPolicy::canView($item, $credential->actor($request->ip(), ViewContext::LISTINGS_SCOPE))) {
            throw ProblemException::of('not_found', 'No such listing.');
        }

        return Response::ok($this->reader->view($item, $context));
    }

    /**
     * @param array<string,string> $args
     */
    public function photos(Request $request, Credential $credential, array $args): Response
    {
        $id = (int) $this->visibleRow((int) $args['id'], $request, $credential)['pk_i_id'];

        return Response::collection($this->reader->photos($id));
    }

    /**
     * GET /listings/{id}/photos/{photo}
     *
     * @param array<string,string> $args
     */
    public function photo(Request $request, Credential $credential, array $args): Response
    {
        $id      = (int) $this->visibleRow((int) $args['id'], $request, $credential)['pk_i_id'];
        $photoId = ctype_digit($args['photo']) ? (int) $args['photo'] : 0;
        foreach ($this->reader->photos($id) as $photo) {
            if ($photo['id'] === $photoId) {
                return Response::ok($photo);
            }
        }

        throw ProblemException::of('not_found', 'No such photo on this listing.');
    }

    /**
     * Approved comments, oldest first, paged by id.
     *
     * @param array<string,string> $args
     */
    public function comments(Request $request, Credential $credential, array $args): Response
    {
        if (!$this->api->facts()->commentsEnabled()) {
            throw ProblemException::of('not_found', 'Comments are switched off on this site.');
        }
        $id    = (int) $this->visibleRow((int) $args['id'], $request, $credential)['pk_i_id'];
        $facts = $this->api->facts();
        $pager = Pager::fromRequest($request, $this->api->cursor(), ListSpec::byId('asc', $facts->commentsPerPage(), $facts->maxLimit()), ['listing' => $id] + $request->query());

        $approved = CommentStatus::condition(osc_db_table(DB_TABLE_PREFIX . 't_item_comment')->where('fk_i_item_id', $id), [CommentStatus::ACTIVE]);
        $total = $pager->counts() ? $approved->count() : null;
        $rows  = osc_db_stringify_rows($approved->where('pk_i_id', '>', (int) ($pager->after()[0] ?? 0))->orderBy('pk_i_id')->limit($pager->limit() + 1)->get());
        $next = $pager->next($rows);
        $data = array_map([new CommentSerializer(), 'one'], $pager->page($rows));

        return (new Page($data, $total, $pager->limit(), $next))->response($this->api->links(), 'listings/' . $id . '/comments', $request->query());
    }

    /**
     * The bare listing row, when the caller may see it, expired or not.
     *
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function visibleRow(int $id, Request $request, Credential $credential): array
    {
        $row = osc_db_table(DB_TABLE_PREFIX . 't_item')
            ->select('pk_i_id', 'fk_i_user_id', 'b_enabled', 'b_active', 'b_spam', 'b_premium', 'dt_expiration')
            ->where('pk_i_id', $id)
            ->first();
        $row = $row === null ? null : osc_db_stringify_row($row);
        if ($row === null || !ListingPolicy::canView($row, $credential->actor($request->ip(), ViewContext::LISTINGS_SCOPE))) {
            throw ProblemException::of('not_found', 'No such listing.');
        }

        return $row;
    }
}
