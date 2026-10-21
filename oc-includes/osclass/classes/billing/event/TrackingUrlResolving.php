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

namespace mindstellar\billing\event;

use mindstellar\billing\Receipts;
use mindstellar\event\Bridged;
use mindstellar\event\Event;

/**
 * A shipped order has a carrier and tracking number but no tracking page yet. A listener may set
 * one; only an https address is kept. Old callbacks on `billing_tracking_url` get ($url, $carrier, $tracking).
 */
final class TrackingUrlResolving extends Event implements Bridged
{
    private string $url = '';

    public function __construct(private string $carrier, private string $tracking)
    {
    }

    public function carrier(): string
    {
        return $this->carrier;
    }

    public function tracking(): string
    {
        return $this->tracking;
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * Set the tracking page. Anything that is not an https address leaves it empty.
     */
    public function setUrl(string $url): void
    {
        $this->url = Receipts::httpsUrl($url);
    }

    public static function legacyNames(): array
    {
        return ['billing_tracking_url'];
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $found = $fn($this->url, $this->carrier, $this->tracking);
        $this->setUrl(is_string($found) ? $found : '');
    }
}
