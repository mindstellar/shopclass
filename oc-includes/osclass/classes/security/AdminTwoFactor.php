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

use mindstellar\database\DbException;

/**
 * An administrator's two-step sign-in, kept as JSON in t_admin.s_2fa:
 * {"secret": base32, "backup": [sha256, ...], "step": last accepted time step}. NULL is off.
 * Read and written here, not through the Admin model, so a database not yet upgraded reads as off.
 */
final class AdminTwoFactor
{
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
        $id       = (int)$admin['pk_i_id'];
        $stored   = self::read($id);
        $settings = self::decode($stored);
        if ($settings === null) {
            return false;
        }

        $step = Totp::verify($settings['secret'], $code, $settings['step']);
        if ($step !== null) {
            $settings['step'] = $step;
        } else {
            $index = array_search(Totp::hashBackupCode($code), $settings['backup'], true);
            if ($index === false) {
                return false;
            }
            unset($settings['backup'][$index]);
            $settings['backup'] = array_values($settings['backup']);
        }

        // Only if nothing changed since the read, so one code cannot pass two requests at once.
        return osc_db_execute(
            'UPDATE ' . DB_TABLE_PREFIX . 't_admin SET s_2fa = ? WHERE pk_i_id = ? AND s_2fa = ?',
            array((string)json_encode($settings), $id, $stored)
        ) === 1;
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
        $step = Totp::verify($secret, $code);
        if ($step === null) {
            return null;
        }
        $codes = Totp::newBackupCodes();
        self::save($adminId, array(
            'secret' => $secret,
            'backup' => array_map(array(Totp::class, 'hashBackupCode'), $codes),
            'step'   => $step,
        ));

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
        $settings['backup'] = array_map(array(Totp::class, 'hashBackupCode'), $codes);
        self::save((int)$admin['pk_i_id'], $settings);

        return $codes;
    }

    /**
     * @param int $adminId
     *
     * @return void
     */
    public static function disable(int $adminId): void
    {
        self::write($adminId, null);
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
        $settings = self::settings($admin);

        return (string)$admin['s_password'] . ($settings === null ? '' : $settings['secret']);
    }

    /**
     * @param int                 $adminId
     * @param array<string,mixed> $settings
     *
     * @return void
     */
    private static function save(int $adminId, array $settings): void
    {
        self::write($adminId, (string)json_encode($settings));
    }

    /**
     * @param int $adminId
     *
     * @return string|null the stored JSON, or null when off or the column does not exist yet
     */
    private static function read(int $adminId): ?string
    {
        try {
            $row = osc_db_select_one('SELECT s_2fa FROM ' . DB_TABLE_PREFIX . 't_admin WHERE pk_i_id = ?', array($adminId));
        } catch (DbException $e) {
            return null;
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
        osc_db_execute('UPDATE ' . DB_TABLE_PREFIX . 't_admin SET s_2fa = ? WHERE pk_i_id = ?', array($value, $adminId));
    }
}
