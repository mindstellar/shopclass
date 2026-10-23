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

namespace mindstellar\live;

/**
 * Tells browsers that something changed. A note carries ids only, never message text.
 * Implementations never throw: a failed publish must not break the request that caused it.
 */
interface LivePublisher
{
    /**
     * Send a note to a channel (`user-<id>`).
     *
     * @param array<string,int|string> $note
     *
     * @return bool false on any failure
     */
    public function publish(string $channel, array $note): bool;

    /**
     * The URL a browser subscribes to for a channel, or null when live delivery is off.
     */
    public function subscribeUrl(string $channel): ?string;

    /**
     * 'off' or 'nchan'.
     */
    public function mode(): string;
}
