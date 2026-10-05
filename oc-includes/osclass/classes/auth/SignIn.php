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
 * In order: the sign-in limit (before any lookup or hashing), the account by e-mail or
 * username, the limit again under the account's e-mail, the password (an unknown account takes as long as a wrong password), a rehash
 * at the current cost, the ban rules, the `before_login` action, then whether the account is
 * confirmed and enabled. Callers fire `before_validating_login` before and `after_login`
 * after, as each has its own input and its own answer.
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
        $throttle = LoginThrottle::evaluate(Reauth::CONTEXT, $account, $captchaSolved);
        if ($throttle['status'] === LoginThrottle::BLOCKED) {
            return new self(self::BLOCKED, null, max(1, (int) $throttle['retry_after']));
        }

        $user = self::find($account);
        // The account's e-mail is a second name for its budget, so the username and the
        // e-mail (and the password re-check, which counts under the e-mail) share one.
        $email = $user === null ? '' : LoginThrottle::normalise((string) ($user['s_email'] ?? ''));
        if ($email === LoginThrottle::normalise($account)) {
            $email = '';
        }
        if ($email !== '') {
            $throttle = LoginThrottle::evaluate(Reauth::CONTEXT, $email, $captchaSolved);
            if ($throttle['status'] === LoginThrottle::BLOCKED) {
                return new self(self::BLOCKED, null, max(1, (int) $throttle['retry_after']));
            }
        }

        // An unknown account and a wrong password answer the same way, and take about as long.
        $ok = $user === null
            ? osc_dummy_password_verify($password)
            : osc_verify_password($password, (string) ($user['s_password'] ?? ''));
        if (!$ok || $user === null) {
            // Counted against the name as typed, so one nobody holds counts like a real one.
            LoginThrottle::recordFailure(Reauth::CONTEXT, $account);
            if ($email !== '') {
                LoginThrottle::recordFailure(Reauth::CONTEXT, $email, false);
            }

            return new self(self::WRONG);
        }
        // The account's counters only: the address may have been guessing at other accounts.
        LoginThrottle::clear(Reauth::CONTEXT, $account, false);
        if ($email !== '') {
            LoginThrottle::clear(Reauth::CONTEXT, $email, false);
        }

        $user   = self::rehash($user, $password);
        $banned = (int) osc_is_banned((string) $user['s_email'], $ip);
        if ($banned !== 0) {
            return new self(self::BANNED, $user, 0, $banned);
        }

        osc_run_hook('before_login');
        if ((int) $user['b_active'] !== 1) {
            return new self(self::INACTIVE, $user);
        }
        if ((int) $user['b_enabled'] !== 1) {
            return new self(self::DISABLED, $user);
        }

        return new self(self::OK, $user);
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
     * The account, from BANNED on; null for BLOCKED and WRONG.
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
        \User::newInstance()->update(['s_password' => $user['s_password']], ['pk_i_id' => $user['pk_i_id']]);

        return $user;
    }

    /**
     * The account an e-mail address or username names.
     *
     * @return array<string,mixed>|null
     */
    private static function find(string $account): ?array
    {
        $user = osc_validate_email($account) ? \User::newInstance()->findByEmail($account) : null;
        if (empty($user)) {
            $user = \User::newInstance()->findByUsername($account);
        }

        return is_array($user) && isset($user['pk_i_id']) ? $user : null;
    }
}
