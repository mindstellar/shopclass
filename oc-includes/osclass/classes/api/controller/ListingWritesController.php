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
use mindstellar\api\Problem;
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListingReader;
use mindstellar\api\Response;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\api\serializer\OrderSerializer;
use mindstellar\api\Warning;
use mindstellar\api\write\FetchedPhotos;
use mindstellar\api\write\ListingOutcome;
use mindstellar\api\write\ListingWriter;
use mindstellar\api\write\OwnedListings;
use mindstellar\api\write\PhotoBatch;
use mindstellar\api\write\PhotoIntake;
use mindstellar\billing\ListingCheckout;
use mindstellar\currency\CurrencyService;
use mindstellar\listing\PhotoRoom;

/**
 * The seller's own listings: `POST /listings`, `PATCH` and `DELETE /listings/{id}` and
 * `POST /listings/{id}/publish`, through ListingService and ListingCheckout as the web's writes are. A save is one transaction; e-mails wait for the commit.
 */
final class ListingWritesController
{
    private ListingReader $reader;
    private ListingWriter $writer;
    private PhotoIntake $photos;
    private UserRows $users;
    private PhotoRoom $room;
    private OwnedListings $owned;

    public function __construct(private ApiServices $api)
    {
        $this->room   = $api->photoRoom();
        $this->owned  = $api->ownedListings();
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
     * `POST /listings/{id}/publish`, owner only. An empty body spends the user's credits; a
     * `{package, gateway}` body starts an order for the credits it is short of and answers
     * `order` and `next`. A second call with the same `Idempotency-Key` replays the first;
     * without one it answers the listing as it is and spends nothing.
     *
     * @throws ProblemException 403, 404, 422 or 429
     */
    public function publish(ApiCall $call): Response
    {
        $id     = $this->owned->own($call->intArg(), $call->credential())->id();
        $userId = $call->userId();
        $input  = $call->input();
        foreach (['package' => 'gateway', 'gateway' => 'package'] as $sent => $needed) {
            if (isset($input[$sent]) && !isset($input[$needed])) {
                throw ProblemException::field('/' . $needed, 'required', 'Send ' . $needed . ' with ' . $sent . '.');
            }
        }

        if (!isset($input['package'])) {
            $result = ListingCheckout::publish($id, $call->actor());
            if ($result['reason'] === 'insufficient_credits') {
                $quote = $result['quote'];
                throw ProblemException::of(
                    'insufficient_credits',
                    'This listing needs ' . $quote['credits'] . ' credits; you have ' . $quote['balance'] . '.',
                    ['payment' => self::payment($id, $quote)]
                );
            }
            if ($result['reason'] === 'refused') {
                throw ProblemException::from(Problem::rejected('This site does not allow this listing to be published.'));
            }
            $pending = in_array($result['landing'], [ListingCheckout::LANDING_VALIDATION, ListingCheckout::LANDING_MODERATION], true);

            return $this->saved($call, new ListingOutcome($id, $pending, 0), false);
        }

        // A refused order does not spend the hour: check the bucket first, count once the order is written.
        $bucket = $this->api->ratePolicy()->checkout($userId);
        $this->api->limiter()->refuseIfFull($bucket, 'Too many payments started in an hour. Try again later.');
        $placed = ListingCheckout::startOrder($id, $userId, (int) $input['package'], (string) $input['gateway']);
        $this->api->limiter()->hit($bucket);
        $next = $placed['next'];
        $serializer = new OrderSerializer($this->api->links(), $this->api->listingSerializer(), CurrencyService::make());

        return $this->saved($call, new ListingOutcome($id, false, 0, true), false, [
            'order' => $serializer->orders([$placed['order']])[0],
            'next'  => ['kind' => $next->getKind(), 'payload' => $next->getPayload()],
        ]);
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
     *
     * @param array<string,mixed> $more members to answer after `payment`
     */
    private function saved(ApiCall $call, ListingOutcome $outcome, bool $created, array $more = []): Response
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
        $extra = [];
        if ($outcome->unpaid()) {
            $warnings[Warning::LISTING_UNPAID] = 'The listing is saved and waits for payment. Publish it with credits or at payment.publish_url.';
            $extra['payment']                  = self::payment($id, ListingCheckout::quote($id, $call->userId()));
        }
        $extra = Warning::member($warnings) + $extra + $more;

        return $created ? $this->api->created($call, $data, 'listings/' . $id, $extra) : Response::ok($data, 200, $extra);
    }

    /**
     * What a held listing costs, the user's credits and where to pay on the web.
     *
     * @param array{credits: int, balance: int, shortfall: int} $quote from ListingCheckout
     *
     * @return array{credits: int, balance: int, shortfall: int, publish_url: string}
     */
    private static function payment(int $id, array $quote): array
    {
        return [
            'credits'     => $quote['credits'],
            'balance'     => $quote['balance'],
            'shortfall'   => $quote['shortfall'],
            'publish_url' => osc_billing_publish_url($id),
        ];
    }
}
