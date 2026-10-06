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
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\Format;
use mindstellar\api\write\OwnedListing;
use mindstellar\api\write\PhotoIntake;
use mindstellar\apiaccess\Credential;
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

    public function __construct(private ApiServices $api)
    {
        $this->photos = $api->photoIntake();
    }

    /**
     * POST /photos
     *
     * @param array<string,string> $args
     */
    public function stage(Request $request, Credential $credential, array $args): Response
    {
        $staged = $this->photos->stage((int) $credential->userId(), $this->photos->upload($request));

        return Response::ok(['token' => $staged->token(), 'expires_at' => Format::timestamp($staged->expiresAt())]);
    }

    /**
     * POST /listings/{id}/photos
     *
     * @param array<string,string> $args
     */
    public function add(Request $request, Credential $credential, array $args): Response
    {
        $listing = OwnedListing::own((int) $args['id'], $credential);
        $id      = $listing->id();
        $cap     = PhotoService::cap($listing->userId());
        if (PhotoService::room($id, $listing->userId()) === 0) {
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
        $data = $this->api->listingSerializer()->photos([PhotoService::find($new[0])])[0];

        return Response::created($data, $this->api->links()->api('listings/' . $id . '/photos/' . $new[0]));
    }

    /**
     * DELETE /listings/{id}/photos/{photo}
     *
     * @param array<string,string> $args
     */
    public function remove(Request $request, Credential $credential, array $args): Response
    {
        $id      = OwnedListing::own((int) $args['id'], $credential)->id();
        $photoId = ctype_digit($args['photo']) ? (int) $args['photo'] : 0;
        if (!(new PhotoService())->delete($photoId, $id, $credential->actor($request->ip()))) {
            throw ProblemException::of('not_found', 'No such photo on this listing.');
        }

        return Response::noContent();
    }
}
