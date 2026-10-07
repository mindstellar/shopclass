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

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListingReader;
use mindstellar\api\read\ListingSearch;
use mindstellar\api\read\ListSpec;
use mindstellar\api\read\Pager;
use mindstellar\api\Response;
use mindstellar\api\serializer\CommentSerializer;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\api\serializer\ViewContext;
use mindstellar\comment\CommentQuery;
use mindstellar\listing\ListingPolicy;
use mindstellar\listing\ListingQuery;

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

    public function index(ApiCall $call): Response
    {
        return $this->search->run($call->request(), $call->credential(), null, 'listings');
    }

    public function show(ApiCall $call): Response
    {
        $request = $call->request();
        $credential = $call->credential();

        $context = $this->api->context($request, $credential, 'listing', ListingSerializer::MEMBERS, ListingSerializer::INCLUDES);
        $item    = $this->reader->row($call->intArg());
        if ($item === null || !ListingPolicy::canView($item, $credential->actor($request->ip(), ViewContext::LISTINGS_SCOPE))) {
            throw ProblemException::notFound('No such listing.');
        }

        return Response::ok($this->reader->view($item, $context));
    }

    public function photos(ApiCall $call): Response
    {
        $id = (int) $this->visibleRow($call, $call->intArg())['pk_i_id'];

        return Response::collection($this->reader->photos($id));
    }

    /**
     * GET /listings/{id}/photos/{photo}
     */
    public function photo(ApiCall $call): Response
    {
        $id      = (int) $this->visibleRow($call, $call->intArg())['pk_i_id'];
        $photoId = $call->intArg('photo');
        foreach ($this->reader->photos($id) as $photo) {
            if ($photo['id'] === $photoId) {
                return Response::ok($photo);
            }
        }

        throw ProblemException::notFound('No such photo on this listing.');
    }

    /**
     * Approved comments, oldest first, paged by id.
     */
    public function comments(ApiCall $call): Response
    {
        $request = $call->request();

        if (!$this->api->facts()->commentsEnabled()) {
            throw ProblemException::of('feature_disabled', 'Comments are switched off on this site.');
        }
        $id    = (int) $this->visibleRow($call, $call->intArg())['pk_i_id'];
        $facts = $this->api->facts();
        $pager = Pager::fromRequest($request, $this->api->cursor(), ListSpec::byId('asc', $facts->commentsPerPage(), $facts->maxLimit()), ['listing' => $id] + $request->query());

        $comments = new CommentQuery();

        return $pager->respond(
            fn (): array => $comments->approved($id, $pager->afterId() ?? 0, $pager->limit() + 1),
            fn (): int => $comments->countApproved($id),
            static fn (array $page): array => array_map([new CommentSerializer(), 'one'], $page),
            $this->api->links(),
            'listings/' . $id . '/comments',
            $request->query()
        );
    }

    /**
     * The bare listing row, when the caller may see it, expired or not.
     *
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function visibleRow(ApiCall $call, int $id): array
    {
        $row = (new ListingQuery($this->api->clock()))->statusRow($id);
        if ($row === null || !ListingPolicy::canView($row, $call->credential()->actor($call->request()->ip(), ViewContext::LISTINGS_SCOPE))) {
            throw ProblemException::notFound('No such listing.');
        }

        return $row;
    }
}
