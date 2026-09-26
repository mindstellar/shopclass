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

use mindstellar\utility\FileSystem;
use Throwable;

/**
 * Installs a security release on its own, once a day from the cron, when the admin has
 * switched it on. Only a release on the site's channel, for the same X.Y it runs, with a
 * checksum, and marked security in its release.json. The contact address is e-mailed the result.
 */
final class AutoSecurityUpdate
{
    /**
     * Why a release must not be installed on its own, or null when it may be.
     *
     * @param array<string,mixed>|null $info      Osclass::getPackageInfo()
     * @param array<string,mixed>|null $manifest  the release's release.json
     * @param string                   $installed the version this site runs
     * @param string                   $lastTried the last version an automatic install tried
     *
     * @return string|null
     */
    public static function refusal(?array $info, ?array $manifest, string $installed, string $lastTried): ?string
    {
        $new = (string) ($info['s_new_version'] ?? '');
        if ($new === '' || !version_compare($new, $installed, '>')) {
            return 'no newer release';
        }
        if (self::line($new) !== self::line($installed)) {
            return 'a newer X.Y, which is never installed on its own';
        }
        if (empty($info['s_sha256'])) {
            return 'no checksum';
        }
        if ($lastTried === $new) {
            return 'already tried';
        }
        if (($manifest['version'] ?? null) !== $new || ($manifest['security'] ?? null) !== true) {
            return 'not a security release';
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
        $manifest  = self::manifest((string) ($info['s_manifest_url'] ?? ''));
        $installed = OSCLASS_VERSION;
        if (self::refusal($info, $manifest, $installed, (string) osc_get_preference('auto_update_tried')) !== null) {
            return;
        }

        $new = (string) $info['s_new_version'];
        // Recorded first, so a run that dies half-way is not repeated every day.
        osc_set_preference('auto_update_tried', $new);
        @set_time_limit(0);
        ignore_user_abort(true);

        try {
            (new Upgrade(new Osclass($info)))->doUpgrade();
            $db      = json_decode((string) Osclass::upgradeDB(), true);
            $ok      = (int) ($db['error'] ?? 1) === 0;
            $message = $ok ? '' : (string) ($db['message'] ?? '');
        } catch (Throwable $e) {
            $ok      = false;
            $message = $e->getMessage();
        }

        self::mail($ok, $installed, $new, $message);
    }

    /**
     * @param string $url the release.json download address
     *
     * @return array<string,mixed>|null
     */
    private static function manifest(string $url): ?array
    {
        if ($url === '' || !FileSystem::isAllowedPackageHost($url)) {
            return null;
        }
        $data = json_decode((string) (new FileSystem())->getContents($url), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param string $version
     *
     * @return string X.Y
     */
    private static function line(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    /**
     * @param bool   $ok
     * @param string $from
     * @param string $to
     * @param string $reason
     *
     * @return void
     */
    private static function mail(bool $ok, string $from, string $to, string $reason): void
    {
        $site    = osc_page_title();
        $subject = $ok
            ? sprintf(__('[%1$s] Security update %2$s installed'), $site, $to)
            : sprintf(__('[%1$s] Security update %2$s could not be installed'), $site, $to);
        $body    = $ok
            ? sprintf(__('Shopclass installed security update %2$s on %1$s, replacing %3$s. Nothing needs doing.'), $site, $to, $from)
            : sprintf(__('Shopclass tried to install security update %2$s on %1$s and stopped: %3$s. The site still runs %4$s. Install the update from the admin when you can.'), $site, $to, $reason, $from);

        osc_sendMail(array(
            'from'    => _osc_from_email_aux(),
            'to'      => osc_contact_email(),
            'subject' => $subject,
            'body'    => '<p>' . osc_esc_html($body) . '</p>',
        ));
    }
}
