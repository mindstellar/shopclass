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

namespace mindstellar\location;

/**
 * A map service that turns an address into coordinates. Plugins register one on the
 * `register_geocode_providers` hook; the site owner picks it in Settings -> General -> Maps.
 */
interface GeocodeProvider
{
    /** Stable id, e.g. `locationiq`. */
    public function id(): string;

    /** The name the admin sees. */
    public function label(): string;

    /** Whether it has what it needs to run, usually a key. */
    public function isConfigured(): bool;

    /**
     * The coordinates of an address, or null when the service finds no match.
     *
     * @param string      $address     one line, e.g. "12 Main St, Pune, Maharashtra, India"
     * @param string|null $countryCode two-letter code that narrows the search
     *
     * @return array{0:float,1:float}|null latitude and longitude
     * @throws \RuntimeException when the service cannot be reached or refuses the call; the job retries
     */
    public function geocode(string $address, ?string $countryCode = null): ?array;

    /** Seconds core may keep an answer, or null for as long as core allows (a year). Follow the service's terms. */
    public function cacheTtl(): ?int;

    /** Markup the service requires on pages that show its data, or ''. */
    public function attribution(): string;
}
