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

use mindstellar\api\ProblemException;
use mindstellar\apiaccess\Credential;
use mindstellar\database\Db;
use mindstellar\database\DbException;
use mindstellar\listing\ListingPolicy;
use mindstellar\listing\ListingQuery;

/**
 * Reads the listing a write is about. own() reads the caller's own listing (404 if they cannot see
 * it, 403 `not_owner` for someone else's live one), and admin writes use load().
 */
class OwnedListings
{
    private ListingQuery $query;

    public function __construct(?ListingQuery $query = null)
    {
        $this->query = $query ?? new ListingQuery();
    }

    /**
     * @param bool $withTexts also read its titles and descriptions
     *
     * @throws ProblemException 404 or 403 not_owner
     */
    public function own(int $id, Credential $credential, bool $withTexts = false): OwnedListing
    {
        $listing = $this->load($id, $withTexts);
        $row     = $listing->row();
        $actor   = $credential->actor('');
        if (!ListingPolicy::isOwner($row, $actor)) {
            throw ListingPolicy::canView($row, $actor)
                ? ProblemException::of('not_owner', 'Only the seller may change this listing.')
                : ProblemException::notFound('No such listing.');
        }

        return $listing;
    }

    /**
     * Any listing, whoever owns it.
     *
     * @param bool $withTexts also read its titles and descriptions
     *
     * @throws ProblemException 404
     */
    public function load(int $id, bool $withTexts = false): OwnedListing
    {
        try {
            $rows = Db::stringifyRows($this->query->editRows($id, $withTexts));
        } catch (DbException $e) {
            throw ProblemException::of('server_error', 'The listing could not be read.');
        }
        if ($rows === []) {
            throw ProblemException::notFound('No such listing.');
        }
        $row   = $rows[0];
        $texts = [];
        foreach ($withTexts ? $rows : [] as $text) {
            if (($text['fk_c_locale_code'] ?? null) !== null) {
                $texts[(string) $text['fk_c_locale_code']] = [(string) ($text['s_title'] ?? ''), (string) ($text['s_description'] ?? '')];
            }
        }
        unset($row['fk_c_locale_code'], $row['s_title'], $row['s_description']);

        return new OwnedListing($row, $texts);
    }
}
