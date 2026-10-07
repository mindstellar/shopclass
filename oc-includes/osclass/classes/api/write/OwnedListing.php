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
use mindstellar\listing\ListingPolicy;

/**
 * A listing as a write needs it: the t_item columns with the location's, and when asked for,
 * its title and description in each language. own() reads the caller's own listing or
 * refuses: 404 when they cannot see it, 403 `not_owner` when it is someone else's live
 * listing. Admin writes read any listing with load().
 */
final class OwnedListing
{
    /**
     * @param array<string,mixed>                   $row
     * @param array<string,array{0:string,1:string}> $texts locale => [title, description]
     */
    public function __construct(private array $row, private array $texts = [])
    {
    }

    /**
     * @param bool $withTexts also read its titles and descriptions
     *
     * @throws ProblemException 404 or 403 not_owner
     */
    public static function own(int $id, Credential $credential, bool $withTexts = false): self
    {
        $listing = self::load($id, $withTexts);
        $row     = $listing->row();
        $actor   = $credential->actor('');
        if (!ListingPolicy::isOwner($row, $actor)) {
            throw ListingPolicy::canView($row, $actor)
                ? ProblemException::of('not_owner', 'Only the seller may change this listing.')
                : ProblemException::of('not_found', 'No such listing.');
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
    public static function load(int $id, bool $withTexts = false): self
    {
        try {
            $rows = Db::stringifyRows((new \mindstellar\listing\ListingQuery())->editRows($id, $withTexts));
        } catch (\mindstellar\database\DbException $e) {
            throw ProblemException::of('server_error', 'The listing could not be read.');
        }
        if ($rows === []) {
            throw ProblemException::of('not_found', 'No such listing.');
        }
        $row   = $rows[0];
        $texts = [];
        foreach ($withTexts ? $rows : [] as $text) {
            if (($text['fk_c_locale_code'] ?? null) !== null) {
                $texts[(string) $text['fk_c_locale_code']] = [(string) ($text['s_title'] ?? ''), (string) ($text['s_description'] ?? '')];
            }
        }
        unset($row['fk_c_locale_code'], $row['s_title'], $row['s_description']);

        return new self($row, $texts);
    }

    public function id(): int
    {
        return (int) $this->row['pk_i_id'];
    }

    public function userId(): int
    {
        return (int) ($this->row['fk_i_user_id'] ?? 0);
    }

    /**
     * The edit secret. It is never sent back to the caller.
     */
    public function secret(): string
    {
        return (string) ($this->row['s_secret'] ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    public function row(): array
    {
        return $this->row;
    }

    /**
     * @return array<string,array{0:string,1:string}> locale => [title, description]
     */
    public function texts(): array
    {
        return $this->texts;
    }
}
