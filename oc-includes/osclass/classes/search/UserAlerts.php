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
 * A user's own saved searches: saving one, listing, finding and unsubscribing the live ones.
 */
final class UserAlerts
{
    public const CREATED  = 'created';
    public const EXISTS   = 'exists';
    public const REFUSED  = 'refused';
    public const FAILED   = 'failed';
    public const LIMIT    = 'limit';

    /** Live saved searches a user may keep when the site has set no number. */
    public const DEFAULT_MAX_PER_USER = 20;

    private \Alerts $alerts;

    public function __construct(?\Alerts $alerts = null)
    {
        $this->alerts = $alerts ?? \Alerts::getInstance();
    }

    /**
     * Live saved searches a user may keep; 0 means no limit.
     */
    public static function maxPerUser(): int
    {
        $v = osc_get_preference('alerts_max_per_user');

        return $v === '' || $v === null ? self::DEFAULT_MAX_PER_USER : max(0, (int) $v);
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

    /**
     * Save a stored search for a user. The same live search is not saved twice; the one there
     * is answered. An alert of an account that is not confirmed and enabled is kept but not
     * switched on. A user who already keeps maxPerUser() live searches gets LIMIT.
     *
     * @param string $alert a stored search, as AlertEnvelope makes it
     *
     * @return array{status:string,alert:array<string,mixed>|null} status is one of the constants;
     *                                                            alert is the live row for CREATED and EXISTS
     */
    public function subscribe(int $userId, string $alert): array
    {
        $user  = $userId > 0 ? \User::getInstance()->findByPrimaryKey($userId) : false;
        $email = is_array($user) ? (string) ($user['s_email'] ?? '') : '';
        if ($alert === '' || $email === '') {
            return ['status' => self::FAILED, 'alert' => null];
        }
        $existing = $this->matching($alert, $userId);
        if ($existing !== []) {
            return ['status' => self::EXISTS, 'alert' => $existing[0]];
        }
        if (!osc_validate_email($email)) {
            return ['status' => self::REFUSED, 'alert' => null];
        }
        $max = self::maxPerUser();
        if ($max > 0 && count($this->live($userId)) >= $max) {
            return ['status' => self::LIMIT, 'alert' => null];
        }
        $id = $this->alerts->createAlert($userId, $email, $alert, osc_genRandomPassword());
        if (!$id) {
            return ['status' => self::FAILED, 'alert' => null];
        }
        if ((int) $user['b_active'] !== 1 || (int) $user['b_enabled'] !== 1) {
            return ['status' => self::REFUSED, 'alert' => null];
        }
        $this->alerts->activate($id);
        $saved = $this->matching($alert, $userId);

        return $saved === [] ? ['status' => self::FAILED, 'alert' => null] : ['status' => self::CREATED, 'alert' => $saved[0]];
    }

    public function unsubscribe(int $id): bool
    {
        return (bool) $this->alerts->unsub($id);
    }
}
