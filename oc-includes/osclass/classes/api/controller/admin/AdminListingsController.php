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
use mindstellar\user\UserQuery;

/**
 * `/admin/listings`: every listing whatever its status, the admin's edit, the screen's
 * actions (one route each, all answered by act()) and delete.
 */
final class AdminListingsController
{
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
     *
     * @param array<string,string> $args
     */
    public function index(Request $request, Credential $credential, array $args): Response
    {
        $filter = static fn (string $name): ?int => $request->queryString($name) === '' ? null : $request->queryInt($name);

        return $this->list->run($request, $credential, 'admin/listings', $request->queryList('status'), $filter('user'), $filter('category'), trim($request->queryString('q')));
    }

    /**
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        return Response::ok($this->view($request, $credential, (int) $args['id']));
    }

    /**
     * PATCH /admin/listings/{id}. Members not sent keep their stored values, the owner and
     * the expiry date included.
     *
     * @param array<string,string> $args
     */
    public function update(Request $request, Credential $credential, array $args): Response
    {
        $listing = OwnedListing::load((int) $args['id'], true);
        $input   = $request->input();
        $owner   = (int) ($input['owner_id'] ?? 0);
        if ($owner > 0 && !(new UserQuery())->exists($owner)) {
            throw ProblemException::field('/owner_id', 'unknown', 'is not a user');
        }
        $this->writer->adminUpdate($listing, $this->writer->editForm($listing, $input, $request, $credential) + self::adminMembers($listing, $input), $credential->actor($request->ip(), 'admin:listings'));

        return Response::ok($this->view($request, $credential, $listing->id()));
    }

    /**
     * DELETE /admin/listings/{id}
     *
     * @param array<string,string> $args
     */
    public function delete(Request $request, Credential $credential, array $args): Response
    {
        $this->writer->delete(OwnedListing::load((int) $args['id']), $credential->actor($request->ip(), 'admin:listings'));

        return Response::noContent();
    }

    /**
     * POST /admin/listings/{id}/<action>, one of ListingModeration::ACTIONS, read from the
     * path's last segment.
     *
     * @param array<string,string> $args
     */
    public function act(Request $request, Credential $credential, array $args): Response
    {
        $this->moderation->apply(basename((string) $request->path()), (int) $args['id'], (int) $credential->adminId(), 'API key #' . (int) $credential->id());

        return $this->show($request, $credential, $args);
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
