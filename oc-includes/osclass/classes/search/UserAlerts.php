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

    /** Live product alerts (back in stock, price drop) a user may keep. */
    public const MAX_PRODUCT_PER_USER = 50;

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
        return osc_get_int_preference('alerts_max_per_user', self::DEFAULT_MAX_PER_USER, 0);
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
     * is answered. A product alert (type \Alerts::PRODUCT, an AlertEnvelope::product()) counts as
     * the same when it waits for the same listing, variant and kind, whatever its price; the one there takes the new price. An alert
     * of an account that is not confirmed and enabled is kept but not switched on. A user who
     * already keeps the most live alerts of that family (maxPerUser() saved searches, or
     * MAX_PRODUCT_PER_USER product alerts), gets LIMIT; one over the alert_subscribe throttle
     * gets THROTTLED.
     *
     * @param string $alert a stored search, as AlertEnvelope makes it
     * @param string $type  DAILY or WEEKLY for a saved search, \Alerts::PRODUCT for a product alert
     *
     * @return array{status:string,alert:array<string,mixed>|null} status is one of the constants;
     *                                                            alert is the live row for CREATED and EXISTS
     */
    public function subscribe(int $userId, string $alert, string $type = 'DAILY'): array
    {
        $user    = $userId > 0 ? \User::getInstance()->findByPrimaryKey($userId) : false;
        $email   = is_array($user) ? (string) ($user['s_email'] ?? '') : '';
        $product = AlertEnvelope::productOf($alert);
        if ($alert === '' || $email === '' || ($product !== null) !== ($type === \Alerts::PRODUCT)) {
            return ['status' => self::FAILED, 'alert' => null];
        }
        $live = $this->live($userId);
        $same = $product === null ? $this->matching($alert, $userId) : array_values(array_filter(
            $live,
            static fn (array $row): bool => self::sameProduct(AlertEnvelope::productOf((string) $row['s_search']), $product)
        ));
        if ($same !== []) {
            if ($product !== null && $this->alerts->update(['s_search' => $alert, 'fk_i_item_id' => $product['listing']], ['pk_i_id' => (int) $same[0]['pk_i_id']]) !== false) {
                $same[0]['s_search'] = $alert;
            }

            return ['status' => self::EXISTS, 'alert' => $same[0]];
        }
        if (!osc_validate_email($email)) {
            return ['status' => self::REFUSED, 'alert' => null];
        }
        $max    = $product === null ? self::maxPerUser() : self::MAX_PRODUCT_PER_USER;
        $family = array_filter($live, static fn (array $row): bool => (AlertEnvelope::productOf((string) $row['s_search']) === null) === ($product === null));
        if ($max > 0 && count($family) >= $max) {
            return ['status' => self::LIMIT, 'alert' => null];
        }
        if (ActionThrottle::exceededFor('alert_subscribe')) {
            return ['status' => self::THROTTLED, 'alert' => null];
        }
        $id = $this->alerts->createAlert($userId, $email, $alert, osc_genRandomPassword(), $type);
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

        // A second click on the link finds the alert already on, which is the state asked for.
        return $this->alerts->activate($id) === 1 || !empty($alert['b_active']) ? self::ACTIVATED : self::FAILED;
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
     * Whether two product alerts wait for the same thing; the price they go under does not count.
     *
     * @param array<string,mixed>|null $a
     * @param array<string,mixed>      $b
     */
    private static function sameProduct(?array $a, array $b): bool
    {
        return $a !== null && $a['listing'] === $b['listing'] && $a['variant'] === $b['variant'] && $a['kind'] === $b['kind'];
    }

    /**
     * @param array<string,mixed> $alert
     */
    private function linkMatches(array $alert, string $email, string $secret): bool
    {
        return hash_equals((string) $alert['s_email'], $email) && hash_equals((string) $alert['s_secret'], $secret);
    }
}
