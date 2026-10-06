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

namespace mindstellar\search;

/**
 * A user's own saved searches: listing, finding and unsubscribing the live ones.
 */
final class UserAlerts
{
    private \Alerts $alerts;

    public function __construct(?\Alerts $alerts = null)
    {
        $this->alerts = $alerts ?? \Alerts::getInstance();
    }

    /**
     * The user's live alerts.
     *
     * @return array<int,array<string,mixed>>
     */
    public function live(int $userId): array
    {
        return $this->alerts->findByUser($userId);
    }

    /**
     * The user's live alerts on this stored search.
     *
     * @return array<int,array<string,mixed>>
     */
    public function matching(string $search, int $userId): array
    {
        return $this->alerts->findBySearchAndUser($search, $userId);
    }

    /**
     * One of the user's live alerts, or null when it is someone else's, unsubscribed or gone.
     *
     * @return array<string,mixed>|null
     */
    public function own(int $id, int $userId): ?array
    {
        $alert = $this->alerts->findByPrimaryKey($id);
        if (!is_array($alert) || (int) ($alert['fk_i_user_id'] ?? 0) !== $userId || !empty($alert['dt_unsub_date'])) {
            return null;
        }

        return $alert;
    }

    public function unsubscribe(int $id): bool
    {
        return (bool) $this->alerts->unsub($id);
    }
}
