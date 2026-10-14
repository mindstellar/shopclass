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
use mindstellar\location\GeocodeLimitReached;
use mindstellar\location\Geocoder;

/**
 * Looks up a listing's coordinates from its address on the job queue, once its save has
 * committed, so a slow map service never holds the save's transaction open.
 */
final class ListingGeocode
{
    public const JOB = 'listing.geocode';

    /** Queues the lookup of listings saved before a provider was set up, a batch at a time. */
    public const BACKFILL = 'listing.geocode_backfill';

    /** Listings one backfill run queues, and the most lookups it lets wait at once. */
    private const BATCH = 50;

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
     * Whether the location has no coordinates and a geocode provider or a map is set up.
     *
     * @param array<string,mixed> $location a t_item_location row
     */
    public static function wanted(array $location): bool
    {
        if (self::hasCoordinates($location)) {
            return false;
        }

        return self::mapType() !== null || (self::hasStreet($location) && Geocoder::active() !== null);
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
            try {
                (new self())->run((int) ($job->payload()['item'] ?? 0));
            } catch (GeocodeLimitReached $e) {
                $job->repeat($job->payload(), $e->retryAfter());
            }
        });
        osc_job_describe(self::JOB, __('Listing map position'), static fn (array $payload): string => sprintf(__('Listing #%d'), (int) ($payload['item'] ?? 0)));
        osc_job_register_handler(self::BACKFILL, static function ($job): void {
            $next = (new self())->backfill((int) ($job->payload()['after'] ?? 0));
            if ($next !== null) {
                $job->repeat(['after' => $next[0]], $next[1]);
            }
        });
        osc_job_describe(self::BACKFILL, __('Map positions of older listings'), static fn (array $payload): string => sprintf(__('After listing #%d'), (int) ($payload['after'] ?? 0)));
    }

    /**
     * Queue the lookup of every listing with a street address and no map position, when a
     * geocode provider is set up.
     *
     * @return bool whether the lookup is now queued or running
     */
    public static function queueExisting(): bool
    {
        return Geocoder::active() !== null && osc_job_ensure(self::BACKFILL, ['after' => 0]);
    }

    /**
     * Queue the lookups of the next listings after $afterId. Each lookup is its own job, so it
     * retries and waits for the provider's allowance like a new listing's; a run queues no more
     * while a batch still waits.
     *
     * @return array{0:int,1:int}|null where to go on and how many seconds to wait first, or null when done
     */
    public function backfill(int $afterId): ?array
    {
        if (Geocoder::active() === null) {
            return null;
        }
        if (osc_job_count('pending', self::JOB) >= self::BATCH) {
            return [$afterId, 300];
        }
        $ids = ListingStore::withoutPosition($afterId, self::BATCH);
        osc_job_enqueue_many(self::JOB, array_map(static fn (int $id): array => ['item' => $id], $ids), [
            'unique_key' => static fn (array $payload): string => (string) $payload['item'],
        ]);

        return count($ids) < self::BATCH ? null : [(int) end($ids), 60];
    }

    /**
     * Look the listing's address up and store the coordinates. A listing that is gone or
     * already has coordinates is left alone, as is an address the map service cannot place.
     * A picked geocode provider replaces the map-key lookup; it is not tried after it.
     *
     * @return bool whether coordinates were written
     */
    public function run(int $itemId): bool
    {
        $geocoder = Geocoder::active();
        $mapType  = self::mapType();
        $location = $itemId > 0 ? ListingStore::location($itemId) : null;
        if (($geocoder === null && $mapType === null) || $location === null || self::hasCoordinates($location)) {
            return false;
        }
        $address = sprintf('%s, %s, %s, %s', $location['s_address'] ?? '', $location['s_city'] ?? '', $location['s_region'] ?? '', $location['s_country'] ?? '');
        if ($geocoder !== null) {
            // Without a street the answer is the city centre, which core already has.
            if (!self::hasStreet($location)) {
                return false;
            }
            $country = (string) ($location['fk_c_country_code'] ?? '');
            $coords  = $geocoder->geocode($address, $country !== '' ? $country : null);

            return $coords !== null && ListingStore::setCoordinates($itemId, $coords[0], $coords[1]) > 0;
        }
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
    private static function hasStreet(array $location): bool
    {
        return trim((string) ($location['s_address'] ?? '')) !== '';
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
