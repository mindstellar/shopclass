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
use mindstellar\exception\InvalidException;
use mindstellar\location\LocationService;

/**
 * The place members of a body (`country`, `region_id`, `city_id`, `lat`, `lng`) laid over a
 * listing or profile form, and the check that the place exists.
 */
final class PlaceBody
{
    private function __construct()
    {
    }

    /**
     * $form with the sent place members. A sent id clears the stored name; a `region` or `city`
     * name in the body is kept when its id is not positive.
     *
     * @template T
     *
     * @param array<string,T> $form
     * @param array<mixed>    $body
     *
     * @return array<string,T|string>
     */
    public static function apply(array $form, array $body): array
    {
        if (array_key_exists('country', $body)) {
            $form['countryId'] = strtoupper((string) ($body['country'] ?? ''));
            $form['country']   = '';
        }
        foreach (['region_id' => ['regionId', 'region'], 'city_id' => ['cityId', 'city']] as $member => [$idField, $nameField]) {
            if (!array_key_exists($member, $body)) {
                continue;
            }
            $id             = (int) ($body[$member] ?? 0);
            $form[$idField] = $id > 0 ? (string) $id : '';
            if ($id > 0 || !array_key_exists($nameField, $body)) {
                $form[$nameField] = '';
            }
        }
        foreach (['lat' => 'd_coord_lat', 'lng' => 'd_coord_long'] as $member => $field) {
            if (array_key_exists($member, $body)) {
                $form[$field] = $body[$member] === null ? '' : (string) $body[$member];
            }
        }

        return $form;
    }

    /**
     * The form's country, region and city must exist and sit under the one above.
     *
     * @param array<string,mixed> $form
     *
     * @throws ProblemException 422 for a place that does not exist or has another parent
     */
    public static function check(array $form): void
    {
        try {
            LocationService::checkPlaces((string) ($form['countryId'] ?? ''), (string) ($form['regionId'] ?? ''), (string) ($form['cityId'] ?? ''));
        } catch (InvalidException $e) {
            throw ProblemException::from(Problem::fromRefusal($e));
        }
    }
}
