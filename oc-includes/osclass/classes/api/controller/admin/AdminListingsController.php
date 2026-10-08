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

namespace mindstellar\api\controller\admin;

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListingList;
use mindstellar\api\read\ListingReader;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\api\write\ListingWriter;
use mindstellar\api\write\OwnedListing;
use mindstellar\api\write\OwnedListings;
use mindstellar\apiaccess\Credential;
use mindstellar\moderation\ListingModeration;
use mindstellar\search\query\CategoryFilter;
use mindstellar\utility\DateInput;
use mindstellar\utility\DeferredMail;

/**
 * `/admin/listings`: every listing whatever its status, the admin's edit (status included),
 * bump and delete.
 */
final class AdminListingsController
{
    /** The PATCH members that change the status => ListingModeration's flag. */
    private const STATUS_MEMBERS = ['approved' => 'active', 'blocked' => 'blocked', 'spam' => 'spam', 'premium' => 'premium'];

    private ListingReader $reader;
    private ListingWriter $writer;
    private ListingModeration $moderation;
    private ListingList $list;
    private OwnedListings $owned;

    public function __construct(private ApiServices $api, ?OwnedListings $owned = null)
    {
        $this->owned = $owned ?? new OwnedListings();
        $this->reader = $api->listingReader();
        $this->writer = $api->listingWriter();
        $this->moderation = $api->listingModeration();
        $this->list = new ListingList($api, $this->reader);
    }

    /**
     * GET /admin/listings: newest first, paged by id.
     */
    public function index(ApiCall $call): Response
    {
        $request = $call->request();

        $users = $request->queryIds('user');

        return $this->list->run($request, $call->credential(), 'admin/listings', $request->queryList('status'), $users, $this->categories($request), trim($request->queryString('q')));
    }

    /**
     * The `category` filter as ids, subcategories included, as search widens them. An id
     * may name a category that is switched off; a slug must name one that is on.
     *
     * @return int[]
     * @throws ProblemException 422 for an unknown slug
     */
    private function categories(Request $request): array
    {
        $filter = new CategoryFilter();
        foreach ($this->reader->categories()->resolve($request->queryList('category'), $this->api->locale($request), true) as $id) {
            $filter->add($id);
        }

        return array_map('intval', $filter->ids());
    }

    public function show(ApiCall $call): Response
    {
        return Response::ok($this->view($call, $call->intArg()));
    }

    /**
     * PATCH /admin/listings/{id}. Members not sent keep their stored values, the owner and
     * the expiry date included. `approved`, `blocked`, `spam` and `premium` change the
     * status as the screen's actions do.
     */
    public function update(ApiCall $call): Response
    {
        $request = $call->request();
        $credential = $call->credential();

        $listing = $this->owned->load($call->intArg(), true);
        $input   = $request->input();
        $status  = array_intersect_key($input, self::STATUS_MEMBERS);
        $edit    = array_diff_key($input, self::STATUS_MEMBERS);
        $flags = [];
        foreach ($status as $member => $value) {
            $flags[self::STATUS_MEMBERS[$member]] = (bool) $value;
        }
        // The edit and the status changes land together or not at all.
        DeferredMail::transaction(function () use ($listing, $edit, $flags, $request, $credential): void {
            if ($edit !== []) {
                $this->writer->adminUpdate($listing, $this->writer->editForm($listing, $edit, $request, $credential) + self::adminMembers($listing, $edit), $credential->actor($request->ip(), 'admin:listings'));
            }
            if ($flags !== []) {
                $this->moderation->applyFlags($listing->id(), $flags, (int) $credential->adminId(), self::note($credential));
            }
        });

        return Response::ok($this->view($call, $listing->id()));
    }

    /**
     * DELETE /admin/listings/{id}
     */
    public function delete(ApiCall $call): Response
    {
        $this->writer->delete($this->owned->load($call->intArg()), $call->credential()->actor($call->request()->ip(), 'admin:listings'));

        return Response::noContent();
    }

    /**
     * POST /admin/listings/{id}/bump
     */
    public function bump(ApiCall $call): Response
    {
        $this->moderation->apply('bump', $call->intArg(), (int) $call->credential()->adminId(), self::note($call->credential()));

        return $this->show($call);
    }

    /**
     * What the activity log says a change came through.
     */
    private static function note(Credential $credential): string
    {
        return 'API key #' . (int) $credential->id();
    }

    /**
     * One listing in the admin view, whatever its status.
     *
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function view(ApiCall $call, int $id): array
    {
        $context = $this->api->context($call->request(), $call->credential(), 'listing', ListingSerializer::MEMBERS, ListingSerializer::INCLUDES);

        return $this->reader->one($id, $context) ?? throw ProblemException::notFound('No such listing.');
    }

    /**
     * What only the admin's edit form posts: the owner (0 for none), the contact used when
     * there is no owner, and the expiry date (-1 for never). Each keeps its stored value
     * unless sent.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,string>
     */
    private static function adminMembers(OwnedListing $listing, array $input): array
    {
        $row    = $listing->row();
        $expiry = (string) ($row['dt_expiration'] ?? '');
        if (array_key_exists('expires_at', $input)) {
            // A date-time names its day: a listing shows to the end of the day it expires.
            $date   = $input['expires_at'] === null ? null : DateInput::parse((string) $input['expires_at']);
            if ($input['expires_at'] !== null && $date === null) {
                throw ProblemException::field('/expires_at', 'format', 'must be a day, as 2030-06-30, or an RFC 3339 date-time');
            }
            $expiry = $date === null ? '-1' : $date->format('Y-m-d');
        }

        return [
            'ownerId'       => (string) (array_key_exists('owner_id', $input) ? (int) $input['owner_id'] : $listing->userId()),
            'contactName'   => (string) ($input['contact_name'] ?? $row['s_contact_name'] ?? ''),
            'contactEmail'  => (string) ($input['contact_email'] ?? $row['s_contact_email'] ?? ''),
            'dt_expiration' => $expiry === '' ? '-1' : $expiry,
        ];
    }
}
