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
use mindstellar\api\auth\UserRows;
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListingReader;
use mindstellar\api\Response;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\api\Warning;
use mindstellar\api\write\FetchedPhotos;
use mindstellar\api\write\ListingOutcome;
use mindstellar\api\write\ListingWriter;
use mindstellar\api\write\OwnedListings;
use mindstellar\api\write\PhotoBatch;
use mindstellar\api\write\PhotoIntake;
use mindstellar\listing\PhotoRoom;

/**
 * The seller's own listings: `POST /listings`, `PATCH` and `DELETE /listings/{id}`, through
 * ListingService as the listing form's writes are. A save is one transaction; e-mails wait for the commit.
 */
final class ListingWritesController
{
    private ListingReader $reader;
    private ListingWriter $writer;
    private PhotoIntake $photos;
    private UserRows $users;
    private PhotoRoom $room;
    private OwnedListings $owned;

    public function __construct(private ApiServices $api, ?PhotoRoom $room = null, ?OwnedListings $owned = null)
    {
        $this->room   = $room ?? new PhotoRoom();
        $this->owned  = $owned ?? new OwnedListings();
        $this->reader = $api->listingReader();
        $this->writer = $api->listingWriter();
        $this->photos = $api->photoIntake();
        $this->users  = $api->users();
    }

    /**
     * The prepare step of `POST /listings`: the hourly cap and the posting checks, then the
     * photos named by URL, fetched before the save. Null when the body names no URL.
     *
     * @throws ProblemException 403, 422 or 429
     */
    public function prepareCreate(ApiCall $call): ?FetchedPhotos
    {
        $userId = $call->userId();
        $this->api->limiter()->enforceAll($this->api->ratePolicy()->newListing($userId, $call->request()->ip()), 'Too many new listings in an hour. Try again later.');
        $this->api->listings()->mayPost($call->actor(), (string) ($this->users->find($userId)['s_email'] ?? ''));

        return $this->fetch($call->input(), $userId, $this->room->cap($userId));
    }

    /**
     * The prepare step of `PATCH /listings/{id}`: the photos named by URL, fetched outside
     * the If-Match transaction. Null when the body names no URL.
     *
     * @throws ProblemException 403, 404, 422 or 429
     */
    public function prepareUpdate(ApiCall $call): ?FetchedPhotos
    {
        $input = $call->input();
        if (empty($input['photo_urls'])) {
            return null;
        }
        $listing = $this->owned->own($call->intArg(), $call->credential());

        return $this->fetch($input, $call->userId(), $this->room->room($listing->id(), $listing->userId()));
    }

    public function create(ApiCall $call): Response
    {
        $userId  = $call->userId();
        $input   = $call->input();
        $form    = $this->writer->newForm($input, $call->request(), $call->credential());
        $batch   = $this->batch($call, $input, $userId, $this->room->cap($userId));
        $outcome = $this->withPhotos($batch, $userId, fn (): ListingOutcome => $this->writer->create($form, $batch, $call->actor()));

        return $this->saved($call, $outcome, true);
    }

    public function update(ApiCall $call): Response
    {
        $userId  = $call->userId();
        $listing = $this->owned->own($call->intArg(), $call->credential(), true);
        $input   = $call->input();
        $form    = $this->writer->editForm($listing, $input, $call->request(), $call->credential());
        $room    = empty($input['photo_tokens']) && empty($input['photo_urls']) ? null : $this->room->room($listing->id(), $listing->userId());
        $batch   = $this->batch($call, $input, $userId, $room);
        $outcome = $this->withPhotos($batch, $userId, fn (): ListingOutcome => $this->writer->update($listing, $form, $batch, $call->actor()));

        return $this->saved($call, $outcome, false);
    }

    public function delete(ApiCall $call): Response
    {
        $this->writer->delete($this->owned->own($call->intArg(), $call->credential()), $call->actor());

        return Response::noContent();
    }

    /**
     * @param array<mixed> $input
     */
    private function fetch(array $input, int $userId, ?int $room): ?FetchedPhotos
    {
        return empty($input['photo_urls']) ? null : new FetchedPhotos($this->photos->batch($input, $userId, $room));
    }

    /**
     * The photos the prepare step fetched, else the body's photos read here.
     *
     * @param array<mixed> $input
     */
    private function batch(ApiCall $call, array $input, int $userId, ?int $room): PhotoBatch
    {
        $fetched = $call->prepared();

        return $fetched instanceof FetchedPhotos ? $fetched->take() : $this->photos->batch($input, $userId, $room);
    }

    /**
     * Run a save, then forget the photo tokens it used and remove the temp files either way.
     * A rolled-back save has already removed the files of photos it stored.
     *
     * @param callable(): ListingOutcome $save
     */
    private function withPhotos(PhotoBatch $batch, int $userId, callable $save): ListingOutcome
    {
        $saved = false;
        try {
            $result = $save();
            $saved  = true;

            return $result;
        } finally {
            $this->photos->finish($batch, $userId, $saved);
        }
    }

    /**
     * The saved listing in the owner's view, with warnings for what did not go as asked.
     */
    private function saved(ApiCall $call, ListingOutcome $outcome, bool $created): Response
    {
        $id      = $outcome->id();
        $context = $this->api->context($call->request(), $call->credential(), 'listing', ListingSerializer::MEMBERS, ListingSerializer::INCLUDES);
        $data    = $this->reader->one($id, $context);
        if ($data === null) {
            throw ProblemException::of('server_error', 'The listing could not be read back.');
        }

        $warnings = [];
        if ($outcome->pending()) {
            $warnings[Warning::LISTING_PENDING] = 'The listing goes live once it is activated or approved.';
        }
        if ($outcome->photosSkipped() > 0) {
            $warnings[Warning::PHOTO_SKIPPED] = $outcome->photosSkipped() . ' photo(s) were not added: the listing has as many as it may hold.';
        }
        $extra = Warning::member($warnings);

        return $created ? $this->api->created($call, $data, 'listings/' . $id, $extra) : Response::ok($data, 200, $extra);
    }
}
