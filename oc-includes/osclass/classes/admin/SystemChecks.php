<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin;

use mindstellar\backup\BackupJobs;
use mindstellar\backup\BackupStore;
use mindstellar\database\SchemaDoctor;

/**
 * The checks behind Tools > System info. Each tab gets the issues to act on and the
 * facts to show, worked out from a plain environment array, so no check needs a database.
 */
final class SystemChecks
{
    public const TABS = array('overview', 'database', 'server');

    /** How old the last saved backup may be before the Overview asks for a new one. */
    public const BACKUP_MAX_AGE = 30 * 86400;

    /** How long cron may be silent before it counts as stopped (about 25 hours). */
    public const CRON_MAX_AGE = 90000;

    public const MEMORY_FLOOR = 128 * 1024 * 1024;

    public const DISK_FLOOR = 512 * 1024 * 1024;

    /** The extensions Shopclass needs, in the order the page lists them. */
    public const EXTENSIONS = array('mysqli', 'curl', 'mbstring', 'fileinfo', 'zip', 'json', 'openssl', 'ctype');

    public const DOCS_CRON = 'https://mindstellar.com/docs/configure/cron/';

    public const DOCS_PROXY = 'https://mindstellar.com/docs/deploy/security/#login-throttling-and-the-real-client-ip';

    public const DOCS_BACKUPS = 'https://mindstellar.com/docs/deploy/security/#the-backups-folder';

    private const RANK = array('danger' => 0, 'warning' => 1, 'info' => 2);

    /**
     * The tab a request asks for. The old `info-type=php-info` link opens Server.
     *
     * @param string $tab
     * @param string $infoType
     *
     * @return string
     */
    public static function tab(string $tab, string $infoType = ''): string
    {
        if (in_array($tab, self::TABS, true)) {
            return $tab;
        }

        return $infoType === 'php-info' ? 'server' : 'overview';
    }

    /**
     * Issues with red lines first, then amber, then the rest, keeping their order within a tone.
     *
     * @param array<int,array<string,mixed>> $issues
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rank(array $issues): array
    {
        $issues = array_values($issues);
        $order  = array_keys($issues);
        usort($order, static function ($a, $b) use ($issues) {
            return (self::RANK[$issues[$a]['tone'] ?? 'info'] ?? 2) <=> (self::RANK[$issues[$b]['tone'] ?? 'info'] ?? 2)
                ?: $a <=> $b;
        });

        return array_map(static function ($key) use ($issues) {
            return $issues[$key];
        }, $order);
    }

    /**
     * The tone of the verdict box: the worst issue, or success when there is none.
     *
     * @param array<int,array<string,mixed>> $issues
     *
     * @return string
     */
    public static function tone(array $issues): string
    {
        if ($issues === array()) {
            return 'success';
        }
        $tone = self::rank($issues)[0]['tone'] ?? 'info';

        return isset(self::RANK[$tone]) ? $tone : 'info';
    }

    /**
     * The sentence a tab shows when nothing needs doing.
     *
     * @param string $tab
     *
     * @return string
     */
    public static function healthy(string $tab): string
    {
        switch ($tab) {
            case 'database':
                return __('Your database is up to date and healthy.');
            case 'server':
                return __('The server meets what Shopclass needs.');
            default:
                return __('Everything looks fine.');
        }
    }

    /**
     * A tab's issues and facts.
     *
     * @param string              $tab
     * @param array<string,mixed> $env see CAdminTools::systemEnvironment()
     *
     * @return array{issues:array<int,array<string,mixed>>,groups:array<int,array{title:string,rows:array}>}
     */
    public static function report(string $tab, array $env): array
    {
        switch ($tab) {
            case 'database':
                $report = array('issues' => self::databaseIssues($env, true), 'groups' => self::databaseFacts($env));
                break;
            case 'server':
                $report = array('issues' => self::serverIssues($env, true), 'groups' => self::serverFacts($env));
                break;
            default:
                $report = array(
                    'issues' => array_merge(self::databaseIssues($env, false), self::serverIssues($env, false), self::backupIssues($env)),
                    'groups' => self::overviewFacts($env),
                );
        }
        $report['issues'] = self::rank($report['issues']);

        return $report;
    }

    /**
     * The URL of a System info tab, with an optional fragment.
     *
     * @param array<string,mixed> $env
     * @param string              $tab
     * @param string              $fragment
     *
     * @return string
     */
    public static function url(array $env, string $tab, string $fragment = ''): string
    {
        return (string) ($env['admin_url'] ?? '') . '?page=tools&action=system-info'
            . ($tab === 'overview' ? '' : '&tab=' . $tab) . ($fragment !== '' ? '#' . $fragment : '');
    }

    /**
     * The database's issues: an unreadable structure, an old server, waiting updates, and
     * differences Repair can fix or that need a closer look.
     *
     * @param array<string,mixed> $env
     * @param bool                $here true on the Database tab, where the list is on the page
     *
     * @return array<int,array<string,mixed>>
     */
    public static function databaseIssues(array $env, bool $here): array
    {
        $findings = (array) ($env['findings'] ?? array());
        $pending  = (array) ($env['pending'] ?? array());
        $error    = (string) ($env['findings_error'] ?? '');
        $server   = DatabaseTools::server((string) ($env['db_server'] ?? ''));
        $list     = $here ? '#db-check' : self::url($env, 'database', 'db-check');
        $issues   = array();

        if ($error !== '') {
            $issues[] = self::issue('db_unreadable', 'danger', sprintf(__('Could not read the database structure: %s'), $error));
        }
        if (!$server['supported']) {
            $issues[] = self::issue('db_server_old', 'danger', sprintf(
                __('%1$s is older than Shopclass supports. Ask your host for MySQL %2$s or MariaDB %3$s or newer.'),
                $server['label'],
                DatabaseTools::SERVER_FLOOR['MySQL'],
                DatabaseTools::SERVER_FLOOR['MariaDB']
            ));
        }
        $repairable = DatabaseTools::repairable($findings);
        if ($pending !== array()) {
            $issues[] = self::issue(
                'db_pending',
                'warning',
                sprintf(_n('%d database update is waiting.', '%d database updates are waiting.', count($pending)), count($pending)),
                array('label' => __('Run it'), 'attrs' => array('data-osc-dialog-open' => '#db-update-dialog'))
            );
        } elseif ($repairable !== array()) {
            $issues[] = self::issue(
                'db_repairable',
                'warning',
                sprintf(_n('%d difference in the database that Repair can fix.', '%d differences in the database that Repair can fix.', count($repairable)), count($repairable)),
                array('label' => __('See the list'), 'url' => $list)
            );
        }
        $closer = array_filter($findings, static function ($f) {
            return in_array($f['kind'] ?? '', array(SchemaDoctor::INDEX_COLUMNS, SchemaDoctor::NULLABILITY), true);
        });
        if ($closer !== array()) {
            $issues[] = self::issue(
                'db_closer_look',
                'warning',
                sprintf(_n('%d difference in the database needs a closer look.', '%d differences in the database need a closer look.', count($closer)), count($closer)),
                array('label' => __('See the list'), 'url' => $list)
            );
        }

        return $issues;
    }

    /**
     * The server's issues: PHP, extensions, the image library, limits, folders, cron,
     * the object cache, debug mode, visitor addresses, maintenance mode and the backups folder.
     *
     * @param array<string,mixed> $env
     * @param bool                $here true on the Server tab, where the help is on the page
     *
     * @return array<int,array<string,mixed>>
     */
    public static function serverIssues(array $env, bool $here): array
    {
        $help    = array('label' => __('How to change it'), 'url' => $here ? '#server-help' : self::url($env, 'server', 'server-help'));
        $details = array('label' => __('Server details'), 'url' => self::url($env, 'server'));
        $issues  = array();

        $php = (string) ($env['php'] ?? PHP_VERSION);
        if (version_compare($php, '7.4', '<')) {
            $issues[] = self::issue('php_too_old', 'danger', sprintf(__('PHP %s is too old. Shopclass is built and tested against PHP 8.0 and up.'), $php), $help);
        } elseif (version_compare($php, '8.0', '<')) {
            $issues[] = self::issue('php_7', 'warning', sprintf(__('PHP %s is past end of life and gets no security fixes. Move to PHP 8.'), $php), $help);
        }

        $missing = self::missingExtensions($env);
        if ($missing !== array()) {
            $issues[] = self::issue('ext_missing', 'danger', sprintf(
                _n('A required PHP extension is missing: %s.', 'Required PHP extensions are missing: %s.', count($missing)),
                implode(', ', $missing)
            ), $help);
        }
        if (empty($env['imagick']) && empty($env['gd'])) {
            $issues[] = self::issue('no_image_library', 'danger', __('Neither Imagick nor GD is installed, so photos cannot be resized and image uploads fail.'), $help);
        }
        if (array_key_exists('uploads_writable', $env) && !$env['uploads_writable']) {
            $issues[] = self::issue('uploads_read_only', 'danger', __('The uploads folder cannot be written to. Photos cannot be saved.'), $here ? array() : $details);
        }
        if (!empty($env['proxy'])) {
            $issues[] = self::issue('proxy_mismatch', 'danger', sprintf(
                __('Visitor addresses look wrong: the site is behind a proxy (%1$s), so every visitor seems to come from %2$s. Sign-in protection and IP bans cannot tell visitors apart.'),
                (string) ($env['proxy']['header'] ?? ''),
                (string) ($env['proxy']['proxy'] ?? '')
            ), array('label' => __('Set up real-IP forwarding'), 'url' => self::DOCS_PROXY));
        }
        if (($env['backup_probe'] ?? null) === true) {
            $issues[] = self::issue('backups_open', 'danger', __('Your backups folder is open to the web. Anyone who guesses a file name could download a backup.'), array('label' => __('How to close it'), 'url' => self::DOCS_BACKUPS));
        }

        $memory = (int) ($env['memory'] ?? -1);
        if ($memory !== -1 && $memory > 0 && $memory < self::MEMORY_FLOOR) {
            $issues[] = self::issue('memory_low', 'warning', sprintf(__('The memory limit is %s. Resizing a large photo needs at least 128 MB.'), self::size($memory)), $help);
        }
        $upload = (int) ($env['upload'] ?? 0);
        $post   = (int) ($env['post'] ?? 0);
        if ($post > 0 && $upload > 0 && $post < $upload) {
            $issues[] = self::issue('post_below_upload', 'warning', sprintf(
                __('post_max_size (%1$s) is smaller than upload_max_filesize (%2$s), so uploads over %1$s fail with an empty form.'),
                self::size($post),
                self::size($upload)
            ), $help);
        }
        $photos   = (int) ($env['photos'] ?? 0);
        $maxFiles = (int) ($env['max_files'] ?? 0);
        if ($photos > 0 && $maxFiles > 0 && $maxFiles < $photos) {
            $issues[] = self::issue('uploads_below_photos', 'warning', sprintf(
                __('Listings allow %1$d photos, but PHP takes only %2$d files per request and drops the rest.'),
                $photos,
                $maxFiles
            ), array('label' => __('Settings'), 'url' => (string) ($env['admin_url'] ?? '') . '?page=items&action=settings'));
        }
        if (array_key_exists('opcache', $env) && !$env['opcache']) {
            $issues[] = self::issue('opcache_off', 'warning', __('OPcache is off, so PHP recompiles every file on every request.'), $help);
        }
        $issues = array_merge($issues, self::cronIssues($env));
        if (($env['cache_driver'] ?? 'default') !== 'default' && empty($env['cache_supported'])) {
            $issues[] = self::issue('cache_unsupported', 'warning', sprintf(
                __('config.php asks for the %s object cache, but that driver is not installed, so nothing is cached.'),
                (string) $env['cache_driver']
            ), $help);
        }
        if (!empty($env['debug'])) {
            $issues[] = self::issue('debug_on', 'warning', __('Debug mode is on. On a live site it shows internal details and slows pages.'), $help);
        }
        if (!empty($env['config_writable'])) {
            $issues[] = self::issue('config_writable', 'warning', __('config.php can be written by the web server. It holds your database password; make it read-only.'), $help);
        }
        $disk = $env['free_disk'] ?? null;
        if (is_numeric($disk) && $disk > 0 && $disk < self::DISK_FLOOR) {
            $issues[] = self::issue('disk_low', 'warning', sprintf(__('Only %s of disk space is left. Uploads and backups fail when it runs out.'), self::size((int) $disk)));
        }
        $maintenance = (string) ($env['maintenance'] ?? '');
        if ($maintenance !== '') {
            $issues[] = self::issue(
                'maintenance_on',
                'warning',
                $maintenance === 'locked'
                    ? __('Maintenance mode is on. Visitors see the maintenance page.')
                    : __('Maintenance mode is on, but visitors can still use the site and see a banner.'),
                array('label' => __('Maintenance mode'), 'url' => (string) ($env['admin_url'] ?? '') . '?page=tools&action=maintenance')
            );
        }

        return $issues;
    }

    /**
     * Cron that never ran, or has not run for more than a day.
     *
     * @param array<string,mixed> $env
     *
     * @return array<int,array<string,mixed>>
     */
    public static function cronIssues(array $env): array
    {
        $last   = (int) ($env['cron_last'] ?? 0);
        $action = array('label' => __('How to set up cron'), 'url' => self::DOCS_CRON);
        if ($last <= 0) {
            return array(self::issue('cron_never', 'warning', __('Cron has never run. Update checks, alert emails and cleanup wait for it.'), $action));
        }
        $age = self::now($env) - $last;
        if ($age > self::CRON_MAX_AGE) {
            return array(self::issue('cron_stale', 'warning', sprintf(__('Cron has not run for %s.'), osc_admin_duration($age)), $action));
        }

        return array();
    }

    /**
     * No backup saved on the server in the last 30 days.
     *
     * @param array<string,mixed> $env
     *
     * @return array<int,array<string,mixed>>
     */
    public static function backupIssues(array $env): array
    {
        $action = array('label' => __('Make a backup'), 'url' => (string) ($env['admin_url'] ?? '') . '?page=tools&action=backup');
        $when   = self::lastBackupTime($env);
        if ($when === null) {
            return array(self::issue('backup_none', 'warning', __('No backup has been saved on the server yet.'), $action));
        }
        if (self::now($env) - $when > self::BACKUP_MAX_AGE) {
            return array(self::issue('backup_old', 'warning', __('No backup in the last 30 days.'), $action));
        }

        return array();
    }

    /**
     * The required extensions that are not loaded.
     *
     * @param array<string,mixed> $env
     *
     * @return string[]
     */
    public static function missingExtensions(array $env): array
    {
        $loaded = array_map('strtolower', (array) ($env['extensions'] ?? array()));

        return array_values(array_filter(self::EXTENSIONS, static function ($ext) use ($loaded) {
            return !in_array($ext, $loaded, true);
        }));
    }

    /**
     * A PHP ini size ("128M") in bytes. -1 (no limit) stays -1.
     *
     * @param string $value
     *
     * @return int
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if ((int) $value === -1) {
            return -1;
        }
        $number = (int) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }

    /**
     * @param array<string,mixed> $env
     *
     * @return array<int,array{title:string,rows:array}>
     */
    private static function overviewFacts(array $env): array
    {
        $server = DatabaseTools::server((string) ($env['db_server'] ?? ''));

        return array(array('title' => '', 'rows' => array(
            array('label' => __('Shopclass'), 'value' => (string) ($env['version'] ?? '')),
            array('label' => __('PHP'), 'value' => (string) ($env['php'] ?? PHP_VERSION)),
            array('label' => __('Database'), 'value' => $server['label'] !== '' ? $server['label'] : __('unknown')),
            array('label' => __('Web server'), 'value' => (string) ($env['web_server'] ?? '') ?: __('unknown')),
            array('label' => __('Last backup'), 'value' => self::lastBackupWords($env)),
            array('label' => __('Cron'), 'value' => self::cronWords($env)),
            array('label' => __('Photo storage'), 'value' => self::storageWords($env)),
            array('label' => __('Website'), 'value' => (string) ($env['site_url'] ?? ''), 'mono' => true),
        )));
    }

    /**
     * @param array<string,mixed> $env
     *
     * @return array<int,array{title:string,rows:array}>
     */
    private static function databaseFacts(array $env): array
    {
        $server = DatabaseTools::server((string) ($env['db_server'] ?? ''));
        $rows   = array(
            array('label' => __('Shopclass version'), 'value' => (string) ($env['version'] ?? '')),
            array('label' => __('Database version'), 'value' => (string) ($env['db_version'] ?? '')),
        );
        if ($server['label'] !== '') {
            $rows[] = array('label' => __('Server'), 'value' => $server['label']);
        }
        $size = $env['db_size'] ?? null;
        if (is_array($size)) {
            $rows[] = array(
                'label' => __('Size'),
                'value' => sprintf(_n('%d table', '%d tables', (int) $size['tables']), (int) $size['tables']) . ' · ' . DatabaseTools::bytes((int) $size['bytes']),
            );
        }
        $rows[] = array('label' => __('Table prefix'), 'value' => (string) ($env['prefix'] ?? ''), 'mono' => true);

        return array(array('title' => '', 'rows' => $rows));
    }

    /**
     * @param array<string,mixed> $env
     *
     * @return array<int,array{title:string,rows:array}>
     */
    private static function serverFacts(array $env): array
    {
        $on       = __('on');
        $off      = __('off');
        $maxExec  = (int) ($env['max_exec'] ?? 0);
        $missing  = self::missingExtensions($env);
        $photos   = (int) ($env['photos'] ?? 0);
        $maxFiles = (int) ($env['max_files'] ?? 0);

        $php = array(
            array('label' => __('PHP version'), 'value' => (string) ($env['php'] ?? PHP_VERSION)),
            array('label' => __('Web server'), 'value' => (string) ($env['web_server'] ?? '') ?: __('unknown')),
            array('label' => __('Operating system'), 'value' => (string) ($env['os'] ?? '')),
            array('label' => __('Memory limit'), 'value' => self::size((int) ($env['memory'] ?? 0))),
            array('label' => __('Upload limits'), 'value' => sprintf(
                __('%1$s per file · %2$s per request'),
                self::size((int) ($env['upload'] ?? 0)),
                self::size((int) ($env['post'] ?? 0))
            )),
            array(
                'label' => __('Files per request'),
                'value' => $maxFiles > 0 ? (string) $maxFiles : __('not set'),
                'note'  => $photos > 0 ? sprintf(_n('Listings allow %d photo.', 'Listings allow %d photos.', $photos), $photos) : '',
            ),
            array('label' => __('Time limit'), 'value' => $maxExec === 0 ? __('unlimited') : sprintf(__('%d s'), $maxExec)),
            array('label' => __('Form fields per request'), 'value' => (string) ($env['max_input_vars'] ?? '') ?: __('not set')),
            array('label' => __('Timezone'), 'value' => (string) ($env['timezone'] ?? '') ?: __('not set')),
            array('label' => __('OPcache'), 'value' => !empty($env['opcache']) ? $on : $off),
            self::imageRow($env),
            array('label' => __('Extensions'), 'value' => $missing === array()
                ? sprintf(__('all %d required are installed'), count(self::EXTENSIONS))
                : sprintf(__('missing: %s'), implode(', ', $missing))),
            array('label' => __('Remote downloads (allow_url_fopen)'), 'value' => !empty($env['allow_url_fopen']) ? $on : $off),
        );

        $probe = $env['backup_probe'] ?? null;
        $files = array(
            array(
                'label' => __('Uploads folder'),
                'value' => (!empty($env['uploads_writable']) ? __('writable') : __('read-only')) . ' · ' . (string) ($env['uploads_path'] ?? ''),
            ),
            array('label' => __('Free space'), 'value' => self::size(is_numeric($env['free_disk'] ?? null) ? (int) $env['free_disk'] : 0)),
            array('label' => __('Photo storage'), 'value' => self::storageWords($env)),
            array('label' => __('Backups folder'), 'value' => BackupStore::FOLDER . ' · ' . ($probe === true
                ? __('reachable from the web')
                : ($probe === false ? __('not reachable from the web') : __('not checked yet')))),
            array('label' => __('config.php'), 'value' => !empty($env['config_writable']) ? __('writable') : __('read-only')),
        );

        $driver      = (string) ($env['cache_driver'] ?? 'default');
        $maintenance = (string) ($env['maintenance'] ?? '');
        $proxy       = $env['proxy'] ?? null;
        $tasks       = array(
            array('label' => __('Cron'), 'value' => self::cronWords($env)),
            array('label' => __('Object cache'), 'value' => $driver === 'default'
                ? __('none (worked out on every request)')
                : ($env['cache_supported'] ?? false ? $driver : sprintf(__('%s (not installed)'), $driver))),
            array('label' => __('Debug mode'), 'value' => !empty($env['debug']) ? $on : $off),
            array('label' => __('Visitor addresses'), 'value' => !empty($proxy)
                ? sprintf(__('all seen as %s (behind a proxy)'), (string) ($proxy['proxy'] ?? ''))
                : __('as reported')),
            array('label' => __('Maintenance mode'), 'value' => $maintenance === ''
                ? $off
                : ($maintenance === 'locked' ? __('on, site closed') : __('on, banner only'))),
        );

        $paths = array(
            array('label' => __('Website URL'), 'value' => (string) ($env['site_url'] ?? ''), 'mono' => true),
            array('label' => __('Content'), 'value' => (string) ($env['content_path'] ?? ''), 'mono' => true),
            array('label' => __('Uploads'), 'value' => (string) ($env['uploads_path'] ?? ''), 'mono' => true),
            array('label' => __('Plugins'), 'value' => (string) ($env['plugins_path'] ?? ''), 'mono' => true),
            array('label' => __('Themes'), 'value' => (string) ($env['themes_path'] ?? ''), 'mono' => true),
            array('label' => __('php.ini'), 'value' => (string) ($env['ini_file'] ?? '') ?: __('none loaded'), 'mono' => (string) ($env['ini_file'] ?? '') !== ''),
            array('label' => __('Preferences'), 'value' => sprintf(
                __('%1$d entries, %2$s'),
                (int) ($env['prefs_count'] ?? 0),
                DatabaseTools::bytes((int) ($env['prefs_bytes'] ?? 0))
            )),
        );

        return array(
            array('title' => __('PHP and web server'), 'rows' => $php),
            array('title' => __('Files and storage'), 'rows' => $files),
            array('title' => __('Scheduled tasks and cache'), 'rows' => $tasks),
            array('title' => __('Paths'), 'rows' => $paths),
        );
    }

    /**
     * The image library row: Imagick when selected, else GD, with a note when Imagick is there but unused.
     *
     * @param array<string,mixed> $env
     *
     * @return array<string,string>
     */
    private static function imageRow(array $env): array
    {
        $row = array('label' => __('Image library'));
        if (!empty($env['imagick']) && !empty($env['imagick_on'])) {
            return $row + array('value' => 'Imagick');
        }
        if (!empty($env['imagick'])) {
            return $row + array('value' => 'GD', 'note' => __('Imagick is installed but not selected. It resizes at higher quality: turn it on under Settings > Media.'));
        }
        if (!empty($env['gd'])) {
            return $row + array('value' => 'GD', 'note' => __('Imagick reads more formats and resizes at higher quality. It is worth installing on a photo-heavy site.'));
        }

        return $row + array('value' => __('none'));
    }

    /**
     * @param array<string,mixed> $env
     *
     * @return int|null
     */
    private static function lastBackupTime(array $env): ?int
    {
        $last = $env['backup_last'] ?? null;
        $time = is_array($last) ? strtotime((string) ($last['date'] ?? '')) : false;

        return $time === false ? null : $time;
    }

    /**
     * @param array<string,mixed> $env
     *
     * @return string
     */
    private static function lastBackupWords(array $env): string
    {
        $when = self::lastBackupTime($env);
        if ($when === null) {
            return __('none saved on the server');
        }

        return sprintf(__('%s ago'), osc_admin_duration(self::now($env) - $when))
            . ' · ' . BackupJobs::whatWord((string) ($env['backup_last']['what'] ?? ''))
            . ' · ' . __('on the server');
    }

    /**
     * @param array<string,mixed> $env
     *
     * @return string
     */
    private static function cronWords(array $env): string
    {
        $last = (int) ($env['cron_last'] ?? 0);

        return $last > 0 ? sprintf(__('ran %s ago'), osc_admin_duration(self::now($env) - $last)) : __('never ran');
    }

    /**
     * @param array<string,mixed> $env
     *
     * @return string
     */
    private static function storageWords(array $env): string
    {
        $storage = (array) ($env['storage'] ?? array());
        if (($storage['active'] ?? '') !== 's3') {
            return __('on this server');
        }

        return sprintf(
            ($storage['keep_local'] ?? 'all') === 'none' ? __('S3 bucket "%s"') : __('S3 bucket "%s" (local copies kept)'),
            (string) ($storage['bucket'] ?? '')
        );
    }

    /**
     * @param int $bytes
     *
     * @return string
     */
    private static function size(int $bytes): string
    {
        if ($bytes === -1) {
            return __('unlimited');
        }

        return $bytes > 0 ? DatabaseTools::bytes($bytes) : '—';
    }

    /**
     * @param array<string,mixed> $env
     *
     * @return int
     */
    private static function now(array $env): int
    {
        return (int) ($env['now'] ?? time());
    }

    /**
     * @param string              $id
     * @param string              $tone
     * @param string              $text
     * @param array<string,mixed> $action
     *
     * @return array<string,mixed>
     */
    private static function issue(string $id, string $tone, string $text, array $action = array()): array
    {
        return array('id' => $id, 'tone' => $tone, 'text' => $text, 'action' => $action === array() ? null : $action);
    }
}
