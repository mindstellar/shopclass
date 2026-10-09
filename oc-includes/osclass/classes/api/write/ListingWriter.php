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

namespace mindstellar\api\write;

use mindstellar\api\Problem;
use mindstellar\api\ProblemException;
use mindstellar\api\read\SiteFacts;
use mindstellar\api\Request;
use mindstellar\apiaccess\Credential;
use mindstellar\auth\Actor;
use mindstellar\database\Db;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\listing\PhotoRoom;
use mindstellar\listing\SavedListing;
use mindstellar\moderation\ListingModeration;
use mindstellar\validation\InvalidException;

/**
 * The API's side of a listing write, shared by the seller's writes and the admin's edit. It turns a
 * body into the listing form ListingInput reads, saves it through ListingService and turns a
 * refusal into a problem.
 */
final class ListingWriter
{
    private PhotoRoom $room;

    public function __construct(private CustomFieldValues $fields, private SiteFacts $facts, private ListingService $listings, ?PhotoRoom $room = null)
    {
        $this->room = $room ?? new PhotoRoom();
    }

    /**
     * The form for a new listing.
     *
     * @param array<string,mixed> $input the request body
     *
     * @return array<string,mixed>
     * @throws ProblemException 422 for a language the site does not have, or a place that does not exist or has another parent
     */
    public function newForm(array $input, Request $request, Credential $credential): array
    {
        $this->checkLocales($input);
        $form = $this->checked($this->form()->create($input), $request, $credential);
        PlaceBody::check($form);

        return $form;
    }

    /**
     * The whole form for an edit: the stored listing with the sent members over it.
     *
     * @param OwnedListing        $listing read with its texts
     * @param array<string,mixed> $input   the request body
     *
     * @return array<string,mixed>
     * @throws ProblemException 422 for a language the site does not have, or a sent place that does not exist or has another parent
     */
    public function editForm(OwnedListing $listing, array $input, Request $request, Credential $credential): array
    {
        $this->checkLocales($input);
        $body = $this->form();
        $form = $this->checked($body->patch($body->stored($listing, self::metaRows($listing->id())), $input), $request, $credential);
        if (array_intersect_key($input, ['country' => true, 'region_id' => true, 'city_id' => true]) !== []) {
            PlaceBody::check($form);
        }

        return $form;
    }

    /**
     * Post a listing as its owner.
     *
     * @param array<string,mixed> $form the form's fields, from newForm()
     *
     * @throws ProblemException 422 with the form's messages, or `listing_limit`
     */
    public function create(array $form, PhotoBatch $photos, Actor $actor): ListingOutcome
    {
        $data = ListingInput::fromArray(['photos' => $photos->paths()] + $form, $actor, true);
        try {
            $saved = $this->listings->create($data, $actor);
        } catch (InvalidException $e) {
            throw self::refusal($e);
        }

        return new ListingOutcome($saved->id(), $saved->needsValidation(), $this->skipped($saved->id(), $photos, 0));
    }

    /**
     * Edit a listing as its owner, adding $photos. It is pending when it still waits for activation
     * or the edit is held for the admin's approval.
     *
     * @param array<string,mixed> $form
     *
     * @throws ProblemException 422 with the form's messages
     */
    public function update(OwnedListing $listing, array $form, PhotoBatch $photos, Actor $actor): ListingOutcome
    {
        $id     = $listing->id();
        $before = $photos->isEmpty() ? 0 : $this->room->count($id);
        $saved  = $this->edit(['id' => $id, 'secret' => $listing->secret(), 'photos' => $photos->paths()] + $form, $actor);

        return new ListingOutcome($id, $saved->needsValidation(), $this->skipped($id, $photos, $before));
    }

    /**
     * Photos sent that the listing had no room for.
     */
    private function skipped(int $id, PhotoBatch $photos, int $before): int
    {
        return $photos->isEmpty() ? 0 : max(0, $photos->sent() - ($this->room->count($id) - $before));
    }

    /**
     * Edit any listing as an admin, the owner and expiry included, and set its status flags,
     * all or none.
     *
     * @param array<string,mixed>|null $form  null for no edit
     * @param array<string,bool>       $flags ListingModeration's flags
     *
     * @throws ProblemException 422 with the form's messages, 500 when the edit was not saved
     */
    public function adminUpdate(OwnedListing $listing, ?array $form, array $flags, Actor $actor, ListingModeration $moderation, int $adminId, string $note): void
    {
        $data = $form === null ? null : ListingInput::fromArray(['id' => $listing->id(), 'secret' => $listing->secret()] + $form, $actor, false);
        try {
            $saved = $moderation->edit($listing->id(), $data, $actor, $flags, $adminId, $note, $this->listings);
        } catch (InvalidException $e) {
            throw self::refusal($e);
        }
        if (!$saved) {
            throw ProblemException::of('server_error', 'The listing could not be saved.');
        }
    }

    /**
     * Delete a listing. The secret is read here, after the owner check, and never leaves the server.
     *
     * @throws ProblemException 500 when it could not be deleted
     */
    public function delete(OwnedListing $listing, Actor $actor): void
    {
        if ($this->listings->delete($listing->id(), $listing->secret(), $actor) === false) {
            throw ProblemException::of('server_error', 'The listing could not be deleted.');
        }
    }

    /**
     * @param array<string,mixed> $input what ListingInput::fromArray() reads
     */
    private function edit(array $input, Actor $actor): SavedListing
    {
        try {
            $saved = $this->listings->update(ListingInput::fromArray($input, $actor, false), $actor, false, true);
        } catch (InvalidException $e) {
            throw self::refusal($e);
        }
        if ($saved->rows() === false) {
            throw ProblemException::of('server_error', 'The listing could not be saved.');
        }

        return $saved;
    }

    /**
     * Why the save was refused: the listing limit has its own code, anything else is the
     * form's own errors.
     */
    private static function refusal(InvalidException $e): ProblemException
    {
        foreach ($e->errors() as $error) {
            if ($error['code'] === 'listing_limit') {
                return ProblemException::of('listing_limit', Problem::text($error['message']));
            }
        }

        return ProblemException::from(Problem::refused($e->errors()));
    }

    /**
     * The form after plugins had their say (`api_listing_input`), with only the category's
     * own custom fields, cleaned as the form's are.
     *
     * @param array<string,mixed> $input the listing form
     *
     * @return array<string,mixed>
     */
    private function checked(array $input, Request $request, Credential $credential): array
    {
        $filtered     = osc_apply_filter('api_listing_input', $input, $request, $credential);
        $form         = is_array($filtered) ? $filtered : $input;
        $form['meta'] = $this->fields->clean((int) ($form['catId'] ?? 0), is_array($form['meta'] ?? null) ? $form['meta'] : []);

        return $form;
    }

    /**
     * Text may only be in the site's languages.
     *
     * @param array<mixed> $input
     *
     * @throws ProblemException 422 for a language the site does not have
     */
    private function checkLocales(array $input): void
    {
        $locales = $this->facts->locales();
        $asked   = array_keys((array) ($input['translations'] ?? []));
        if (isset($input['locale'])) {
            $asked[] = (string) $input['locale'];
        }
        foreach ($asked as $code) {
            if (!isset($locales[(string) $code])) {
                throw ProblemException::field(isset($input['locale']) && $code === $input['locale'] ? '/locale' : '/translations/' . $code, 'invalid', 'is not a language of this site');
            }
        }
    }

    /**
     * A listing form in the site's price format and default locale.
     */
    private function form(): ListingBody
    {
        return new ListingBody((string) osc_locale_dec_point(), $this->facts->defaultLocale());
    }

    /**
     * A listing's custom field values, each with its field's type.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function metaRows(int $id): array
    {
        return Db::stringifyRows(\mindstellar\fields\FieldQuery::listingValues($id));
    }
}
