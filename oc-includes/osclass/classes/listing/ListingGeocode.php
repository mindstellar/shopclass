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

namespace mindstellar\listing;

use mindstellar\database\Db;

/**
 * Looks up a listing's coordinates from its address on the job queue, once its save has
 * committed, so a slow map service never holds the save's transaction open.
 */
final class ListingGeocode
{
    public const JOB = 'listing.geocode';

    /** Seconds the map service gets to answer. */
    private const TIMEOUT = 3;

    /** @var callable(string): (string|false) */
    private $fetch;

    /**
     * @param (callable(string): (string|false))|null $fetch fetches one URL; tests pass their own
     */
    public function __construct(?callable $fetch = null)
    {
        $this->fetch = $fetch ?? static fn (string $url): string|false => osc_file_get_contents($url, null, true, self::TIMEOUT);
    }

    /**
     * Whether the site shows a map and the location has no coordinates.
     *
     * @param array<string,mixed> $location a t_item_location row
     */
    public static function wanted(array $location): bool
    {
        return !self::hasCoordinates($location) && self::mapType() !== null;
    }

    /**
     * Queue the lookup once the open transaction commits; dropped when it rolls back.
     */
    public static function queueAfterCommit(int $itemId): void
    {
        Db::afterCommit(static function () use ($itemId): void {
            osc_job_enqueue(self::JOB, ['item' => $itemId], ['unique_key' => (string) $itemId]);
        });
    }

    public static function registerJobs(): void
    {
        osc_job_register_handler(self::JOB, static function ($job): void {
            (new self())->run((int) ($job->payload()['item'] ?? 0));
        });
        osc_job_describe(self::JOB, __('Listing map position'), static fn (array $payload): string => sprintf(__('Listing #%d'), (int) ($payload['item'] ?? 0)));
    }

    /**
     * Look the listing's address up and store the coordinates. A listing that is gone or
     * already has coordinates is left alone, as is an address the map service cannot place.
     *
     * @return bool whether coordinates were written
     */
    public function run(int $itemId): bool
    {
        $mapType  = self::mapType();
        $location = $itemId > 0 ? ListingStore::location($itemId) : null;
        if ($mapType === null || $location === null || self::hasCoordinates($location)) {
            return false;
        }
        $address = sprintf('%s, %s, %s, %s', $location['s_address'] ?? '', $location['s_city'] ?? '', $location['s_region'] ?? '', $location['s_country'] ?? '');
        $url     = $mapType === 'google' ? osc_google_maps_geocode_url($address) : osc_openstreet_geocode_url($address);
        $body    = ($this->fetch)($url);
        $coords  = is_string($body) ? self::parse($mapType, $body) : null;
        if ($coords === null) {
            return false;
        }

        return ListingStore::setCoordinates($itemId, $coords[0], $coords[1]) > 0;
    }

    /**
     * The latitude and longitude in a map service's answer, or null.
     *
     * @return array{0:float,1:float}|null
     */
    public static function parse(string $mapType, string $body): ?array
    {
        $res = json_decode($body);
        $at  = $mapType === 'google'
            ? ($res->results[0]->geometry->location ?? null)
            : ($res->results[0]->locations[0]->latLng ?? null);
        if (!is_object($at) || !is_numeric($at->lat ?? null) || !is_numeric($at->lng ?? null)) {
            return null;
        }

        return [(float) $at->lat, (float) $at->lng];
    }

    /**
     * @param array<string,mixed> $location
     */
    private static function hasCoordinates(array $location): bool
    {
        return (float) ($location['d_coord_lat'] ?? 0) != 0 && (float) ($location['d_coord_long'] ?? 0) != 0;
    }

    /**
     * 'google' or 'openstreet' when the site shows a map that can be geocoded, else null.
     */
    private static function mapType(): ?string
    {
        $type = function_exists('osc_item_map_type') ? (string) osc_item_map_type() : '';

        return in_array($type, ['google', 'openstreet'], true) ? $type : null;
    }
}
