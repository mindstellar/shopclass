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
use mindstellar\api\auth\UserRows;
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListingReader;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\api\write\ListingWriter;
use mindstellar\api\write\OwnedListing;
use mindstellar\api\write\PhotoBatch;
use mindstellar\api\write\PhotoIntake;
use mindstellar\listing\ListingStatus;
use mindstellar\listing\PhotoService;

/**
 * The seller's own listings: `POST /listings`, `PATCH` and `DELETE /listings/{id}`.
 *
 * Writes go through ListingService as the listing form's do, so its checks, moderation,
 * listing limit, posting wait, spam checks, e-mails and hooks all apply. There is no
 * captcha to show, so each user and address may post a limited number of listings an hour.
 *
 * A save runs in one database transaction, so a failure part way leaves nothing behind.
 * ListingService fires its hooks while it writes, so they run inside it; the e-mails they
 * send are held until the commit, and dropped with a rollback.
 */
final class ListingWritesController
{
    private ListingReader $reader;
    private ListingWriter $writer;
    private PhotoIntake $photos;
    private UserRows $users;

    public function __construct(private ApiServices $api)
    {
        $this->reader = $api->listingReader();
        $this->writer = $api->listingWriter();
        $this->photos = $api->photoIntake();
        $this->users = $api->users();
    }

    /**
     * POST /listings
     *
     * @param array<string,string> $args
     */
    public function create(Request $request, Credential $credential, array $args): Response
    {
        $userId = (int) $credential->userId();
        $actor  = $credential->actor($request->ip());
        $this->api->limiter()->enforceAll($this->api->ratePolicy()->newListing($userId, $request->ip()), 'Too many new listings in an hour. Try again later.');
        // Before the body is read and its photos fetched; the save asks no more.
        $this->api->listings()->mayPost($actor, (string) ($this->users->find($userId)['s_email'] ?? ''));
        $input = $request->input();
        $form  = $this->writer->newForm($input, $request, $credential);
        $batch = $this->photos->batch($input, $userId, PhotoService::cap($userId));
        $id    = (int) $this->withPhotos($batch, $userId, fn (): int => $this->writer->create($form, $batch->paths(), $actor));

        return $this->saved($request, $credential, $id, true, $batch, 0);
    }

    /**
     * PATCH /listings/{id}. Members not sent keep their stored values.
     *
     * @param array<string,string> $args
     */
    public function update(Request $request, Credential $credential, array $args): Response
    {
        $userId  = (int) $credential->userId();
        $listing = OwnedListing::own((int) $args['id'], $credential, true);
        $id      = $listing->id();
        $input   = $request->input();
        $form    = $this->writer->editForm($listing, $input, $request, $credential);
        $before  = 0;
        $room    = null;
        if (!empty($input['photo_tokens']) || !empty($input['photo_urls'])) {
            $before = (int) \ItemResource::newInstance()->countResources($id);
            $room   = PhotoService::room($id, $listing->userId());
        }
        $batch = $this->photos->batch($input, $userId, $room);
        $this->withPhotos($batch, $userId, fn () => $this->writer->update($listing, $form, $batch->paths(), $credential->actor($request->ip())));

        return $this->saved($request, $credential, $id, false, $batch, $before);
    }

    /**
     * DELETE /listings/{id}
     *
     * @param array<string,string> $args
     */
    public function delete(Request $request, Credential $credential, array $args): Response
    {
        $this->writer->delete(OwnedListing::own((int) $args['id'], $credential), $credential->actor($request->ip()));

        return Response::noContent();
    }

    /**
     * Run a save, then forget the photo tokens it used and remove the temp files either way.
     * A rolled-back save has already removed the files of photos it stored.
     *
     * @param callable(): mixed $save
     *
     * @return mixed what $save returns
     */
    private function withPhotos(PhotoBatch $batch, int $userId, callable $save): mixed
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
    private function saved(Request $request, Credential $credential, int $id, bool $created, PhotoBatch $batch, int $photosBefore): Response
    {
        $context = $this->api->context($request, $credential, 'listing', ListingSerializer::MEMBERS, ListingSerializer::INCLUDES);
        $data    = $this->reader->one($id, $context);
        if ($data === null) {
            throw ProblemException::of('server_error', 'The listing could not be read back.');
        }

        $warnings = [];
        if (($data['status'] ?? '') === ListingStatus::PENDING) {
            $warnings[] = ['code' => 'listing_pending', 'message' => 'The listing goes live once it is activated or approved.'];
        }
        if (!$batch->isEmpty()) {
            $added = (int) \ItemResource::newInstance()->countResources($id) - $photosBefore;
            if ($added < $batch->sent()) {
                $warnings[] = ['code' => 'photo_skipped', 'message' => ($batch->sent() - $added) . ' photo(s) were not added: the listing has as many as it may hold.'];
            }
        }
        $extra = $warnings === [] ? [] : ['warnings' => $warnings];

        return $created ? Response::created($data, $this->api->links()->api('listings/' . $id), $extra) : Response::ok($data, 200, $extra);
    }
}
