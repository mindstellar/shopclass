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

namespace mindstellar\auth;

use mindstellar\security\LoginThrottle;

/**
 * The one decision on a user's sign-in with a password, for the web form and the API alike.
 *
 * In order: the `before_validating_login` action, the sign-in limit (before any lookup or hashing), the account by e-mail or
 * username, the account's budget over both its names, the password (an unknown account takes as long as a wrong password), whether
 * the account is confirmed (an unconfirmed one counts as a failure), a rehash at the current cost, the ban rules, the `before_login`
 * action, then whether the account is enabled. Once the caller has signed the user in, complete() fires `after_login`.
 */
final class SignIn
{
    public const OK       = 'ok';
    public const BLOCKED  = 'blocked';
    public const WRONG    = 'wrong';
    public const BANNED   = 'banned';
    public const INACTIVE = 'inactive';
    public const DISABLED = 'disabled';

    /**
     * @param array<string,mixed>|null $user
     */
    private function __construct(
        private string $status,
        private ?array $user = null,
        private int $retryAfter = 0,
        private int $banned = 0
    ) {
    }

    /**
     * @param string      $account       an e-mail address or username, as typed
     * @param bool        $captchaSolved whether this request passed a captcha
     * @param string|null $ip            the client's address; the request's when null
     */
    public static function attempt(string $account, string $password, bool $captchaSolved = false, ?string $ip = null): self
    {
        osc_run_hook('before_validating_login');
        $throttle = LoginThrottle::evaluate(Reauth::CONTEXT, $account, $captchaSolved);
        if ($throttle['status'] === LoginThrottle::BLOCKED) {
            return new self(self::BLOCKED, null, max(1, (int) $throttle['retry_after']));
        }

        $user = self::find($account);
        // Failures count under the name as typed; the account's budget is the sum over its username and e-mail.
        // A spent budget answers like a wrong password, so it cannot tell which e-mail belongs to a username.
        $names = $user === null ? [] : [$account, (string) ($user['s_username'] ?? ''), (string) ($user['s_email'] ?? '')];
        $spent = $names !== [] && LoginThrottle::evaluateAccount(Reauth::CONTEXT, $names, $captchaSolved)['status'] === LoginThrottle::BLOCKED;

        // An unknown account, a spent budget and a wrong password answer the same way, and take about as long.
        if ($user === null || $spent) {
            osc_dummy_password_verify($password);
            $ok = false;
        } else {
            $ok = osc_verify_password($password, (string) ($user['s_password'] ?? ''));
        }
        // An unconfirmed account counts like a wrong password, so its lockout cannot tell a taken e-mail apart.
        $inactive = $ok && $user !== null && (int) $user['b_active'] !== 1;
        if (!$ok || $user === null || $inactive) {
            LoginThrottle::recordFailure(Reauth::CONTEXT, $account);

            return $inactive ? new self(self::INACTIVE, $user) : new self(self::WRONG);
        }
        // The account's counters only: the address may have been guessing at other accounts.
        foreach (array_unique(array_map([LoginThrottle::class, 'normalise'], $names)) as $name) {
            LoginThrottle::clear(Reauth::CONTEXT, $name, false);
        }

        $user   = self::rehash($user, $password);
        $banned = (int) osc_is_banned((string) $user['s_email'], $ip);
        if ($banned !== 0) {
            return new self(self::BANNED, $user, 0, $banned);
        }

        osc_run_hook('before_login');
        if ((int) $user['b_enabled'] !== 1) {
            return new self(self::DISABLED, $user);
        }

        return new self(self::OK, $user);
    }

    /**
     * Fire `after_login` for a user this request has just signed in.
     *
     * @param array<string,mixed> $user
     * @param string              $redirect where the user goes next; '' when nowhere
     */
    public static function complete(array $user, string $redirect = ''): void
    {
        osc_run_hook('after_login', $user, $redirect);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function ok(): bool
    {
        return $this->status === self::OK;
    }

    /**
     * The account, for INACTIVE and from BANNED on; null for BLOCKED and WRONG.
     *
     * @return array<string,mixed>|null
     */
    public function user(): ?array
    {
        return $this->user;
    }

    /**
     * BLOCKED: seconds until another try is taken.
     */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    /**
     * BANNED: 1 the e-mail, 2 the address, as osc_is_banned() answers.
     */
    public function banned(): int
    {
        return $this->banned;
    }

    /**
     * Store a just-verified password again when it was hashed at another cost, and return the
     * row with the hash that is kept. A rehash is not a password change, so it signs no one out.
     *
     * @param array<string,mixed> $user     the user row, with s_password
     * @param string              $password the plain password that just matched it
     *
     * @return array<string,mixed>
     */
    public static function rehash(array $user, string $password): array
    {
        $old = (string) ($user['s_password'] ?? '');
        if ($old === '' || (preg_match('|\$2y\$([0-9]{2})\$|', $old, $cost) === 1 && (int) $cost[1] === BCRYPT_COST)) {
            return $user;
        }
        $user['s_password'] = osc_hash_password($password);
        \User::getInstance()->update(['s_password' => $user['s_password']], ['pk_i_id' => $user['pk_i_id']]);

        return $user;
    }

    /**
     * The account an e-mail address or username names.
     *
     * @return array<string,mixed>|null
     */
    private static function find(string $account): ?array
    {
        $user = osc_validate_email($account) ? \User::getInstance()->findByEmail($account) : null;
        if (empty($user)) {
            $user = \User::getInstance()->findByUsername($account);
        }

        return is_array($user) && isset($user['pk_i_id']) ? $user : null;
    }
}
