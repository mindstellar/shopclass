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
 * Live delivery is off: browsers poll.
 */
final class NullPublisher implements LivePublisher
{
    public function publish(string $channel, array $note): bool
    {
        return true;
    }

    public function subscribeUrl(string $channel): ?string
    {
        return null;
    }

    public function mode(): string
    {
        return 'off';
    }
}
