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
use mindstellar\validation\InvalidException;

/**
 * The API's side of a listing write. A body becomes the listing form ListingInput reads:
 * languages checked, the stored values kept on an edit, plugins' `api_listing_input` applied,
 * and only the category's own custom fields, cleaned as the form's are. The form is saved
 * through ListingService and a refusal turned into a problem. The seller's writes and the
 * admin's edit share it.
 */
final class ListingWriter
{
    public function __construct(private CustomFieldValues $fields, private SiteFacts $facts, private ListingService $listings)
    {
    }

    /**
     * The form for a new listing.
     *
     * @param array<string,mixed> $input the request body
     *
     * @return array<string,mixed>
     * @throws ProblemException 422 for a language the site does not have
     */
    public function newForm(array $input, Request $request, Credential $credential): array
    {
        $this->checkLocales($input);

        return $this->checked($this->form()->create($input), $request, $credential);
    }

    /**
     * The whole form for an edit: the stored listing with the sent members over it.
     *
     * @param OwnedListing        $listing read with its texts
     * @param array<string,mixed> $input   the request body
     *
     * @return array<string,mixed>
     * @throws ProblemException 422 for a language the site does not have
     */
    public function editForm(OwnedListing $listing, array $input, Request $request, Credential $credential): array
    {
        $this->checkLocales($input);
        $form = $this->form();

        return $this->checked($form->patch($form->stored($listing, self::metaRows($listing->id())), $input), $request, $credential);
    }

    /**
     * Post a listing as its owner.
     *
     * @param array<string,mixed> $form   the form's fields, from newForm()
     * @param string[]            $photos files for the listing to take
     *
     * @return int the new listing's id
     * @throws ProblemException 422 with the form's messages, or `listing_limit`
     */
    public function create(array $form, array $photos, Actor $actor): int
    {
        $data = self::data(['photos' => $photos] + $form, $form, $actor, true);
        try {
            return $this->listings->create($data, $actor)->id();
        } catch (InvalidException $e) {
            throw self::refusal($e);
        }
    }

    /**
     * Edit a listing as its owner, adding $photos.
     *
     * @param array<string,mixed> $form
     * @param string[]            $photos
     *
     * @throws ProblemException 422 with the form's messages
     */
    public function update(OwnedListing $listing, array $form, array $photos, Actor $actor): void
    {
        $this->edit(['id' => $listing->id(), 'secret' => $listing->secret(), 'photos' => $photos] + $form, $form, $actor, false);
    }

    /**
     * Edit any listing as an admin, the owner and expiry included.
     *
     * @param array<string,mixed> $form
     *
     * @throws ProblemException 422 with the form's messages
     */
    public function adminUpdate(OwnedListing $listing, array $form, Actor $actor): void
    {
        $this->edit(['id' => $listing->id(), 'secret' => $listing->secret()] + $form, $form, $actor, true);
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
     * @param array<string,mixed> $form  the form's fields, for the place check
     */
    private function edit(array $input, array $form, Actor $actor, bool $admin): void
    {
        try {
            $saved = $this->listings->update(self::data($input, $form, $actor, false), $actor, false, !$admin);
        } catch (InvalidException $e) {
            throw self::refusal($e);
        }
        if ($saved->rows() === false) {
            throw ProblemException::of('server_error', 'The listing could not be saved.');
        }
    }

    /**
     * The body as the listing data the item form builds.
     *
     * @param array<string,mixed> $input what ListingInput::fromArray() reads
     * @param array<string,mixed> $form  the form's fields, for the place check
     *
     * @return array<string,mixed>
     * @throws ProblemException 422 for a place that does not match its parent
     */
    private static function data(array $input, array $form, Actor $actor, bool $isAdd): array
    {
        $data = ListingInput::fromArray($input, $actor, $isAdd);
        self::checkPlaces($form, $data);

        return $data;
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
     * A country, region or city that was asked for must be one ListingInput found. It looks
     * them up itself, so they are checked against what it found rather than read twice.
     *
     * @param array<string,mixed> $form what was asked
     * @param array<string,mixed> $data what ListingInput made of it
     *
     * @throws ProblemException 422 for a place that does not exist
     */
    private static function checkPlaces(array $form, array $data): void
    {
        foreach ([['countryId', '/country', 'is not a country of this site'], ['regionId', '/region_id', 'does not exist'], ['cityId', '/city_id', 'does not exist']] as [$field, $pointer, $message]) {
            if ((string) ($form[$field] ?? '') !== '' && (string) ($data[$field] ?? '') === '') {
                throw ProblemException::field($pointer, 'invalid', $message);
            }
        }
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
        return new ListingBody(function_exists('osc_locale_dec_point') ? (string) osc_locale_dec_point() : '.', $this->facts->defaultLocale());
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
