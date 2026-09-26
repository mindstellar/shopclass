<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\upgrade;

use Throwable;

/**
 * Installs a security release on its own from the daily cron when switched on, and e-mails the
 * contact address. A security release raises only the last number of the running version (6.4.0 to 6.4.1).
 */
final class AutoSecurityUpdate
{
    /**
     * Why a release must not be installed on its own, or null when it may be.
     *
     * @param array<string,mixed>|null $info      Osclass::getPackageInfo()
     * @param string                   $installed the version this site runs
     * @param string                   $lastTried the last version an automatic install tried
     *
     * @return string|null
     */
    public static function refusal(?array $info, string $installed, string $lastTried): ?string
    {
        $new = (string) ($info['s_new_version'] ?? '');
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $new, $to)
            || !preg_match('/^(\d+)\.(\d+)\.(\d+)/', $installed, $from)
        ) {
            return 'not a stable release';
        }
        if ($to[1] !== $from[1] || $to[2] !== $from[2] || (int) $to[3] <= (int) $from[3]) {
            return 'not a security release for this version';
        }
        if (!preg_match('/^[a-f0-9]{64}$/', (string) ($info['s_sha256'] ?? ''))) {
            return 'no checksum';
        }
        if ($lastTried === $new) {
            return 'already tried';
        }

        return null;
    }

    /**
     * The daily cron's run. Does nothing unless switched on.
     *
     * @return void
     */
    public static function run(): void
    {
        if (!osc_get_bool_preference('auto_security_updates') || defined('DEMO') || osc_self_update_disabled()) {
            return;
        }
        $info = Osclass::getPackageInfo(true, $fresh);
        if (!$fresh || !is_array($info)) {
            return;
        }
        $installed = OSCLASS_VERSION;
        $new       = (string) ($info['s_new_version'] ?? '');
        if (self::refusal($info, $installed, (string) osc_get_preference('auto_update_tried')) !== null
            || !self::claim($new)
        ) {
            return;
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        $stage = 'files';
        try {
            (new Upgrade(new Osclass($info)))->doUpgrade();
            if (Osclass::newVersionOnDisk() !== $new) {
                throw new \RuntimeException(__('The new files did not arrive.'));
            }
            $stage = 'database';
            $db    = json_decode((string) Osclass::upgradeDB(), true);
            if ((int) ($db['error'] ?? 1) !== 0) {
                throw new \RuntimeException((string) ($db['message'] ?? __('The database update failed.')));
            }
            $stage = 'done';
            $reason = '';
        } catch (Throwable $e) {
            $reason = $e->getMessage();
        }

        self::mail($stage, $installed, $new, $reason);
    }

    /**
     * Mark the version as tried, if no other run has. One row changes for exactly one run, so two
     * cron runs at once cannot both install.
     *
     * @param string $version
     *
     * @return bool
     */
    private static function claim(string $version): bool
    {
        $table = DB_TABLE_PREFIX . 't_preference';
        osc_db_execute(
            'INSERT IGNORE INTO ' . $table . " (s_section, s_name, s_value, e_type) VALUES ('osclass', 'auto_update_tried', '', 'STRING')"
        );

        return osc_db_execute(
            'UPDATE ' . $table . " SET s_value = ? WHERE s_section = 'osclass' AND s_name = 'auto_update_tried' AND s_value <> ?",
            array($version, $version)
        ) === 1;
    }

    /**
     * @param string $stage  done, or where it stopped: files or database
     * @param string $from
     * @param string $to
     * @param string $reason
     *
     * @return void
     */
    private static function mail(string $stage, string $from, string $to, string $reason): void
    {
        $site   = osc_page_title();
        $reason = rtrim($reason, '. ');
        if ($stage === 'done') {
            $subject = sprintf(__('[%1$s] Security update %2$s installed'), $site, $to);
            $body    = sprintf(__('Shopclass installed security update %2$s on %1$s, replacing %3$s. Nothing needs doing.'), $site, $to, $from);
        } else {
            $subject = sprintf(__('[%1$s] Security update %2$s could not be installed'), $site, $to);
            $body    = $stage === 'files'
                ? sprintf(__('Shopclass tried to install security update %2$s on %1$s and stopped: %3$s. Open Tools, then Update, in the admin to install it.'), $site, $to, $reason)
                : sprintf(__('Shopclass installed the files of security update %2$s on %1$s, but the database step failed: %3$s. Open Tools, then Update, in the admin to finish it.'), $site, $to, $reason);
        }

        osc_sendMail(array(
            'from'    => _osc_from_email_aux(),
            'to'      => osc_contact_email(),
            'subject' => $subject,
            'body'    => '<p>' . osc_esc_html($body) . '</p>',
        ));
    }
}
