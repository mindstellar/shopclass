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
use mindstellar\api\read\ListSpec;
use mindstellar\api\read\Page;
use mindstellar\api\read\Pager;
use mindstellar\api\Response;
use mindstellar\api\serializer\CommentSerializer;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\comment\CommentQuery;
use mindstellar\listing\ListingQuery;

/**
 * Listings: search, one listing, its photos and its comments. Search leaves out listings that
 * are not live. One listing answers as its page does: an expired one with status `expired`,
 * a hidden one (pending, disabled, spam) 404 except to its owner and to admin keys with
 * `admin:listings`. An API read never counts as a view. The seller's writes are ListingWritesController's.
 */
final class ListingsController
{
    public function __construct(private ApiServices $api)
    {
    }

    public function index(ApiCall $call): Response
    {
        return $this->api->listingSearch()->run($call->request(), $call->credential(), null, 'listings');
    }

    public function show(ApiCall $call): Response
    {
        $context = $this->api->context($call->request(), $call->credential(), 'listing', ListingSerializer::MEMBERS, ListingSerializer::INCLUDES);
        $reader  = $this->api->listingReader();
        $item    = ProblemException::found($reader->row($call->intArg(), [$call, 'visibleListing']), 'listing');

        return Response::ok($reader->view($item, $context));
    }

    public function photos(ApiCall $call): Response
    {
        $id = (int) $this->visibleRow($call, $call->intArg())['pk_i_id'];

        return Page::whole($this->api->listingReader()->photos($id), $this->api->links(), $call);
    }

    /**
     * GET /listings/{id}/photos/{photo}
     */
    public function photo(ApiCall $call): Response
    {
        $id      = (int) $this->visibleRow($call, $call->intArg())['pk_i_id'];
        $photoId = $call->intArg('photo');
        foreach ($this->api->listingReader()->photos($id) as $photo) {
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
        $pager = Pager::fromRequest($request, $this->api->cursor(), ListSpec::byId('asc', $facts->commentsPerPage(), $facts->maxLimit()), 'listings/' . $id . '/comments', ['listing' => $id] + $request->query());

        $comments = new CommentQuery();

        return $pager->respond(
            fn (): array => $comments->approved($id, $pager->afterId() ?? 0, $pager->limit() + 1),
            fn (): int => $comments->countApproved($id),
            static fn (array $page): array => array_map([new CommentSerializer(), 'one'], $page),
            $this->api->links()
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
        return ProblemException::found($call->visibleListing((new ListingQuery($this->api->clock()))->statusRow($id)), 'listing');
    }
}
