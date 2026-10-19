<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\security;

use mindstellar\auth\AdminStore;
use mindstellar\database\DbException;

/**
 * An administrator's two-step sign-in, kept as JSON in t_admin.s_2fa:
 * {"secret": base32, "backup": [sha256, ...], "step": last accepted time step}. NULL is off.
 * Read and written here, not through the Admin model, so a database not yet upgraded reads as off.
 */
final class AdminTwoFactor
{
    /** The cookie that carries a sign-in waiting for its code, on the admin path. */
    public const PENDING_COOKIE = 'oc_admin_2fa';

    /** Seconds the code step stays open after the password passed. */
    public const PENDING_TTL = 300;

    private const PENDING_PURPOSE = 'admin-2fa-pending';

    /**
     * @param array<string,mixed> $admin a t_admin row
     *
     * @return array{secret:string,backup:array<int,string>,step:int}|null null when off
     */
    public static function settings(array $admin): ?array
    {
        return self::decode(self::read((int)($admin['pk_i_id'] ?? 0)));
    }

    /**
     * @param string|null $json
     *
     * @return array{secret:string,backup:array<int,string>,step:int}|null
     */
    private static function decode(?string $json): ?array
    {
        $data = json_decode((string)$json, true);
        if (!is_array($data) || !is_string($data['secret'] ?? null) || $data['secret'] === '') {
            return null;
        }

        return array(
            'secret' => $data['secret'],
            'backup' => array_values(array_filter((array)($data['backup'] ?? array()), 'is_string')),
            'step'   => (int)($data['step'] ?? 0),
        );
    }

    /**
     * Every admin and whether two-step sign-in is on, for System info. No secret leaves here.
     *
     * @return array<int,array{id:int,name:string,username:string,moderator:bool,two_factor:bool}>
     */
    public static function admins(): array
    {
        try {
            $rows = AdminStore::listing(true);
        } catch (DbException $e) {
            if ($e->getCode() !== 1054) {
                return array();
            }
            // A database not upgraded yet has no s_2fa column: everyone is off.
            $rows = AdminStore::listing(false);
        }

        return array_map(static function (array $row): array {
            return array(
                'id'         => (int) $row['pk_i_id'],
                'name'       => (string) $row['s_name'],
                'username'   => (string) $row['s_username'],
                'moderator'  => (bool) $row['b_moderator'],
                'two_factor' => self::decode(isset($row['s_2fa']) ? (string) $row['s_2fa'] : null) !== null,
            );
        }, $rows);
    }

    /**
     * @param array<string,mixed> $admin
     *
     * @return bool
     */
    public static function enabled(array $admin): bool
    {
        return self::settings($admin) !== null;
    }

    /**
     * Accept an app code or an unused backup code, and record it as used.
     *
     * @param array<string,mixed> $admin
     * @param string              $code
     *
     * @return bool
     */
    public static function check(array $admin, string $code): bool
    {
        $id = (int)$admin['pk_i_id'];
        if (!self::allowTry($id)) {
            return false;
        }
        $stored   = self::read($id);
        $settings = self::decode($stored);
        if ($settings === null) {
            return false;
        }

        $step = Totp::verify($settings['secret'], $code, $settings['step']);
        if ($step !== null) {
            $settings['step'] = $step;
        } else {
            $index = array_search(self::backupHash($code), $settings['backup'], true);
            if ($index === false) {
                return false;
            }
            unset($settings['backup'][$index]);
            $settings['backup'] = array_values($settings['backup']);
        }

        // Only if nothing changed since the read, so one code cannot pass two requests at once.
        return AdminStore::swapTwoFactor($id, (string)json_encode($settings), $stored) === 1;
    }

    /**
     * Turn it on once the admin has typed a code from the new secret.
     *
     * @param int    $adminId
     * @param string $secret
     * @param string $code
     *
     * @return array<int,string>|null the backup codes to show once, or null when the code is wrong
     */
    public static function enable(int $adminId, string $secret, string $code): ?array
    {
        $step = self::allowTry($adminId) ? Totp::verify($secret, $code) : null;
        if ($step === null) {
            return null;
        }
        $codes = Totp::newBackupCodes();
        self::write($adminId, (string)json_encode(array(
            'secret' => $secret,
            'backup' => array_map(array(self::class, 'backupHash'), $codes),
            'step'   => $step,
        )));

        return $codes;
    }

    /**
     * Replace the backup codes.
     *
     * @param array<string,mixed> $admin
     *
     * @return array<int,string> the new codes to show once, empty when 2FA is off
     */
    public static function renewBackupCodes(array $admin): array
    {
        $settings = self::settings($admin);
        if ($settings === null) {
            return array();
        }
        $codes              = Totp::newBackupCodes();
        $settings['backup'] = array_map(array(self::class, 'backupHash'), $codes);
        self::write((int)$admin['pk_i_id'], (string)json_encode($settings));

        return $codes;
    }

    /**
     * @param int $adminId
     *
     * @return void
     */
    public static function disable(int $adminId): void
    {
        // A fresh value rather than NULL, so a remember-me cookie from before 2FA was
        // first turned on does not become valid again.
        self::write($adminId, (string)json_encode(array('off' => bin2hex(random_bytes(8)))));
    }

    /**
     * What a remember-me cookie is signed over, so turning 2FA on or off ends old cookies.
     *
     * @param array<string,mixed> $admin
     *
     * @return string
     */
    public static function rememberBinding(array $admin): string
    {
        $data = json_decode((string)self::read((int)($admin['pk_i_id'] ?? 0)), true);

        return (string)$admin['s_password'] . (is_array($data) ? (string)($data['secret'] ?? $data['off'] ?? '') : '');
    }

    /**
     * A signed token saying this admin passed the password step, kept in a cookie instead of
     * the session. It ends when the password or the 2FA data changes, so a passed code ends it.
     *
     * @param array<string,mixed> $admin a t_admin row
     * @param bool                $remember
     * @param string              $locale
     * @param string              $redirect
     * @param int|null            $now the current time; time() when null
     *
     * @return string
     */
    public static function pendingToken(array $admin, bool $remember, string $locale, string $redirect, ?int $now = null): string
    {
        return SignedPayload::pack(self::PENDING_PURPOSE, array(
            'id'       => (int)$admin['pk_i_id'],
            'remember' => $remember,
            'locale'   => $locale,
            'redirect' => $redirect,
            'bind'     => self::pendingBinding($admin),
        ), self::PENDING_TTL, 0, $now);
    }

    /**
     * The admin id a pending token names, or 0 when it is forged, expired or not one.
     *
     * @param string   $token
     * @param int|null $now
     *
     * @return int
     */
    public static function pendingId(string $token, ?int $now = null): int
    {
        return (int)(SignedPayload::unpack(self::PENDING_PURPOSE, $token, $now)['id'] ?? 0);
    }

    /**
     * Read a pending token for this admin.
     *
     * @param string              $token
     * @param array<string,mixed> $admin the row of the admin it names
     * @param int|null            $now
     *
     * @return array{remember:bool,locale:string,redirect:string}|null null when forged, expired,
     *                                                                 for another admin or used up
     */
    public static function pending(string $token, array $admin, ?int $now = null): ?array
    {
        $data = SignedPayload::unpack(self::PENDING_PURPOSE, $token, $now);
        if ($data === null || (int)($data['id'] ?? 0) !== (int)($admin['pk_i_id'] ?? -1)
            || !hash_equals(self::pendingBinding($admin), (string)($data['bind'] ?? ''))) {
            return null;
        }

        return array(
            'remember' => (bool)($data['remember'] ?? false),
            'locale'   => (string)($data['locale'] ?? ''),
            'redirect' => (string)($data['redirect'] ?? ''),
        );
    }

    /**
     * Clear the code-step cookie, when the browser has one.
     *
     * @return void
     */
    public static function clearPendingCookie(): void
    {
        if (isset($_COOKIE[self::PENDING_COOKIE])) {
            \Cookie::write(self::PENDING_COOKIE, '', time() - 3600, osc_admin_cookie_path());
            unset($_COOKIE[self::PENDING_COOKIE]);
        }
    }

    /**
     * @param array<string,mixed> $admin
     *
     * @return string
     */
    private static function pendingBinding(array $admin): string
    {
        $stored = (string)self::read((int)$admin['pk_i_id']);

        return hash_hmac('sha256', 'admin-2fa-bind|' . (string)($admin['s_password'] ?? '') . '|' . $stored, SigningKey::get());
    }

    /**
     * Count a wrong code at sign-in. Five in 15 minutes, or more than 10 in a day, means
     * someone has the password, so the admin is told by e-mail, at most once an hour.
     *
     * @param array<string,mixed> $admin
     *
     * @return void
     */
    public static function noteFailure(array $admin): void
    {
        $id    = (string)$admin['pk_i_id'];
        $short = RateLimit::hit('admin-2fa-fail', $id, 4, 900);
        $day   = RateLimit::hit('admin-2fa-fail-day', $id, 10, 86400);
        if (($short && $day) || empty($admin['s_email']) || !RateLimit::hit('admin-2fa-mail', $id, 1, 3600)) {
            return;
        }
        $body = '<p>' . osc_esc_html(sprintf(
            __('Someone typed your correct password on %1$s, then several wrong codes, from the address %2$s. They did not get in.'),
            osc_page_title(),
            get_ip()
        )) . '</p><p>' . osc_esc_html(__('If this was not you, change your password now. Until you do, the code step can make you wait.')) . '</p>';
        osc_sendMail(array(
            'from'    => _osc_from_email_aux(),
            'to'      => $admin['s_email'],
            'to_name' => $admin['s_name'] ?? '',
            'subject' => sprintf(__('[%s] Your admin password is known to someone else'), osc_page_title()),
            'body'    => $body,
        ));
    }

    /**
     * Why a code was refused, for every screen that asks for one.
     *
     * @return string
     */
    public static function refusedMessage(): string
    {
        return _m('That code is not right. Try the newest code from your app. After 10 tries, wait 15 minutes.');
    }

    /**
     * Ten tries per admin every 15 minutes, on its own counter: the password step clears the
     * login throttle, and this limit holds whether that throttle is on or not. A counter that
     * cannot be reached refuses the try.
     *
     * @param int $adminId
     *
     * @return bool
     */
    private static function allowTry(int $adminId): bool
    {
        return RateLimit::hit('admin-2fa', (string)$adminId, 10, 900, false);
    }

    /**
     * Keyed like every other one-time code, so a copied database cannot test guesses offline.
     *
     * @param string $code
     *
     * @return string
     */
    private static function backupHash(string $code): string
    {
        return ActionToken::hash(Totp::normaliseBackupCode($code));
    }

    /**
     * @param int $adminId
     *
     * @return string|null the stored JSON, or null when off or the column does not exist yet
     */
    private static function read(int $adminId): ?string
    {
        try {
            $row = AdminStore::twoFactorRow($adminId);
        } catch (DbException $e) {
            // Only a missing column (MySQL error 1054) reads as off; any other failure must not
            // let a password alone through.
            if ($e->getCode() === 1054) {
                return null;
            }
            throw $e;
        }

        return isset($row['s_2fa']) ? (string)$row['s_2fa'] : null;
    }

    /**
     * @param int         $adminId
     * @param string|null $value
     *
     * @return void
     */
    private static function write(int $adminId, ?string $value): void
    {
        AdminStore::setTwoFactor($adminId, $value);
    }
}
