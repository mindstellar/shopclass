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
use mindstellar\apiaccess\Credential;
use mindstellar\moderation\ListingModeration;
use mindstellar\search\query\CategoryFilter;
use mindstellar\user\UserQuery;

/**
 * `/admin/listings`: every listing whatever its status, the admin's edit (status included),
 * bump and delete.
 */
final class AdminListingsController
{
    /** The PATCH members that change the status, as the screen's actions do. */
    private const STATUS_MEMBERS = ['approved' => true, 'blocked' => true, 'spam' => true, 'premium' => true];

    private ListingReader $reader;
    private ListingWriter $writer;
    private ListingModeration $moderation;
    private ListingList $list;

    public function __construct(private ApiServices $api)
    {
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

        $users = array_map('intval', array_values(array_filter($request->queryList('user'), 'ctype_digit')));

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
        return Response::ok($this->view($call->request(), $call->credential(), (int) $call->arg('id')));
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

        $listing = OwnedListing::load((int) $call->arg('id'), true);
        $input   = $request->input();
        $status  = array_intersect_key($input, self::STATUS_MEMBERS);
        $edit    = array_diff_key($input, self::STATUS_MEMBERS);
        $owner   = (int) ($edit['owner_id'] ?? 0);
        if ($owner > 0 && !(new UserQuery())->exists($owner)) {
            throw ProblemException::field('/owner_id', 'unknown', 'is not a user');
        }
        if ($status === [] || $edit !== []) {
            $this->writer->adminUpdate($listing, $this->writer->editForm($listing, $edit, $request, $credential) + self::adminMembers($listing, $edit), $credential->actor($request->ip(), 'admin:listings'));
        }
        foreach (self::actions($status) as $action) {
            $this->moderate($action, $listing->id(), $credential);
        }

        return Response::ok($this->view($request, $credential, $listing->id()));
    }

    /**
     * DELETE /admin/listings/{id}
     */
    public function delete(ApiCall $call): Response
    {
        $this->writer->delete(OwnedListing::load((int) $call->arg('id')), $call->credential()->actor($call->request()->ip(), 'admin:listings'));

        return Response::noContent();
    }

    /**
     * POST /admin/listings/{id}/bump
     */
    public function bump(ApiCall $call): Response
    {
        $this->moderate('bump', (int) $call->arg('id'), $call->credential());

        return $this->show($call);
    }

    /**
     * The ListingModeration actions a PATCH's status members ask for. An unblock runs first and
     * a block last, so approving a blocked listing in the same call works.
     *
     * @param array<string,bool> $status
     *
     * @return string[]
     */
    private static function actions(array $status): array
    {
        $pairs   = ['approved' => ['activate', 'deactivate'], 'spam' => ['spam', 'unspam'], 'premium' => ['premium', 'unpremium']];
        $actions = ($status['blocked'] ?? null) === false ? ['enable'] : [];
        foreach ($pairs as $member => [$on, $off]) {
            if (isset($status[$member])) {
                $actions[] = $status[$member] ? $on : $off;
            }
        }
        if (($status['blocked'] ?? null) === true) {
            $actions[] = 'disable';
        }

        return $actions;
    }

    private function moderate(string $action, int $id, Credential $credential): void
    {
        $this->moderation->apply($action, $id, (int) $credential->adminId(), 'API key #' . (int) $credential->id());
    }

    /**
     * One listing in the admin view, whatever its status.
     *
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function view(Request $request, Credential $credential, int $id): array
    {
        $context = $this->api->context($request, $credential, 'listing', ListingSerializer::MEMBERS, ListingSerializer::INCLUDES);

        return $this->reader->one($id, $context) ?? throw ProblemException::of('not_found', 'No such listing.');
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
            $expiry = $input['expires_at'] === null ? '-1' : (string) $input['expires_at'];
        }

        return [
            'ownerId'       => (string) (array_key_exists('owner_id', $input) ? (int) $input['owner_id'] : $listing->userId()),
            'contactName'   => (string) ($input['contact_name'] ?? $row['s_contact_name'] ?? ''),
            'contactEmail'  => (string) ($input['contact_email'] ?? $row['s_contact_email'] ?? ''),
            'dt_expiration' => $expiry === '' ? '-1' : $expiry,
        ];
    }
}
