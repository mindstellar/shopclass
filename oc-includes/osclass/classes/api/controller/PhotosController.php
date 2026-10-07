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
use mindstellar\api\Response;
use mindstellar\api\serializer\Format;
use mindstellar\api\write\OwnedListings;
use mindstellar\api\write\PhotoIntake;
use mindstellar\listing\PhotoRoom;
use mindstellar\listing\PhotoService;

/**
 * Listing photos: `POST /photos` keeps a photo for a listing not made yet and answers its
 * token (200 with no Location: a token is not a resource to read),
 * `POST /listings/{id}/photos` adds one to a listing, and
 * `DELETE /listings/{id}/photos/{photo}` removes one. Each photo is checked as the listing
 * form checks its own, and a listing never holds more than the site allows.
 */
final class PhotosController
{
    private PhotoIntake $photos;
    private PhotoRoom $room;
    private OwnedListings $owned;

    public function __construct(private ApiServices $api, ?PhotoRoom $room = null, ?OwnedListings $owned = null)
    {
        $this->room   = $room ?? new PhotoRoom();
        $this->owned  = $owned ?? new OwnedListings();
        $this->photos = $api->photoIntake();
    }

    /**
     * POST /photos
     */
    public function stage(ApiCall $call): Response
    {
        $staged = $this->photos->stage((int) $call->credential()->userId(), $this->photos->upload($call->request()));

        return Response::ok(['token' => $staged->token(), 'expires_at' => Format::timestamp($staged->expiresAt())]);
    }

    /**
     * POST /listings/{id}/photos
     */
    public function add(ApiCall $call): Response
    {
        $request = $call->request();
        $credential = $call->credential();

        $listing = $this->owned->own($call->intArg(), $credential);
        $id      = $listing->id();
        $cap     = $this->room->cap($listing->userId());
        if ($this->room->room($id, $listing->userId()) === 0) {
            throw ProblemException::field('/photo', 'limit', 'cannot be added: the listing already has ' . $cap . ' photos, as many as it may hold');
        }

        $photo = $this->photos->upload($request);
        $new   = (new PhotoService())->add($id, [
            'name'     => [basename($photo->path())],
            'type'     => ['image/*'],
            'tmp_name' => [$photo->path()],
            'error'    => [UPLOAD_ERR_OK],
            'size'     => [(int) filesize($photo->path())],
        ], $credential->actor($request->ip()));
        if ($new === []) {
            $photo->discard();

            throw ProblemException::of('server_error', 'The photo could not be saved.');
        }
        $data = null;
        foreach ($this->api->listingReader()->photos($id) as $stored) {
            $data = $stored['id'] === $new[0] ? $stored : $data;
        }
        if ($data === null) {
            throw ProblemException::of('server_error', 'The photo could not be read back.');
        }

        return Response::created($data, $this->api->links()->api('listings/' . $id . '/photos/' . $new[0], $call->request()->version()));
    }

    /**
     * DELETE /listings/{id}/photos/{photo}
     */
    public function remove(ApiCall $call): Response
    {
        $credential = $call->credential();
        $id      = $this->owned->own($call->intArg(), $credential)->id();
        $photoId = $call->intArg('photo');
        if (!(new PhotoService())->delete($photoId, $id, $credential->actor($call->request()->ip()))) {
            throw ProblemException::notFound('No such photo on this listing.');
        }

        return Response::noContent();
    }
}
