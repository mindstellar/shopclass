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

use mindstellar\security\ActionThrottle;

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
    public const THROTTLED = 'throttled';
    public const ACTIVATED = 'activated';
    public const HELD      = 'held';

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
     * switched on. A user who already keeps maxPerUser() live searches, or whose address is over
     * the alert_subscribe throttle, gets LIMIT.
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
        if (ActionThrottle::exceededFor('alert_subscribe')) {
            return ['status' => self::THROTTLED, 'alert' => null];
        }
        $id = $this->alerts->createAlert($userId, $email, $alert, osc_genRandomPassword());
        if (!$id) {
            return ['status' => self::FAILED, 'alert' => null];
        }
        ActionThrottle::record('alert_subscribe');
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

    /**
     * Switch on an alert from the e-mailed link, first giving it to the account with that e-mail.
     *
     * @return string ACTIVATED, HELD for a held alert (its link no longer works), or FAILED
     */
    public function activateByLink(int $id, string $email, string $secret): string
    {
        $alert = $this->alerts->findByPrimaryKey($id);
        if (!is_array($alert) || $alert === []) {
            return self::FAILED;
        }
        if (AlertEnvelope::heldReason((string) $alert['s_search']) !== null) {
            return self::HELD;
        }
        if (!$this->linkMatches($alert, $email, $secret)) {
            return self::FAILED;
        }
        $user = \User::getInstance()->findByEmail($alert['s_email']);
        if (isset($user['pk_i_id'])) {
            $this->alerts->update(['fk_i_user_id' => $user['pk_i_id']], ['pk_i_id' => $id]);
        }

        return $this->alerts->activate($id) === 1 ? self::ACTIVATED : self::FAILED;
    }

    /**
     * Unsubscribe an alert from the e-mailed link.
     */
    public function unsubscribeByLink(int $id, string $email, string $secret): bool
    {
        $alert = $this->alerts->findByPrimaryKey($id);

        return is_array($alert) && $alert !== [] && $this->linkMatches($alert, $email, $secret)
            && $this->alerts->unsub($id) === 1;
    }

    /**
     * Admin: delete an alert.
     */
    public function delete(int $id): bool
    {
        return (bool) $this->alerts->deleteByPrimaryKey($id);
    }

    /**
     * Admin: switch an alert on or off. A held alert is never switched on.
     */
    public function setActive(int $id, bool $active): bool
    {
        return (bool) ($active ? $this->alerts->activate($id) : $this->alerts->deactivate($id));
    }

    /**
     * @param array<string,mixed> $alert
     */
    private function linkMatches(array $alert, string $email, string $secret): bool
    {
        return hash_equals((string) $alert['s_email'], $email) && hash_equals((string) $alert['s_secret'], $secret);
    }
}
