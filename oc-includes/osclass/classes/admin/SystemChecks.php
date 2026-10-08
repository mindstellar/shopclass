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
use mindstellar\database\StrictModeReadiness;
use mindstellar\routing\ServerRules;
use mindstellar\utility\Formatting;

/**
 * The checks behind Tools > System info. Each tab gets the issues to act on and the
 * facts to show, worked out from a plain environment array, so no check needs a database.
 */
final class SystemChecks
{
    public const TABS = array('overview', 'database', 'server', 'jobs', 'security', 'cache');

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

    /** How long a due job may wait while cron runs before the queue counts as stuck. */
    public const JOBS_OVERDUE = 3600;

    /** The object-cache drivers Shopclass ships, in the order the Cache tab lists them. */
    public const CACHE_DRIVERS = array('apcu', 'memcached', 'memcache');

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
     * Each tab's name, in the order the tab strip shows them.
     *
     * @return array<string,string>
     */
    public static function labels(): array
    {
        return array(
            'overview' => __('Overview'),
            'jobs'     => __('Jobs'),
            'security' => __('Security'),
            'cache'    => __('Cache'),
            'database' => __('Database'),
            'server'   => __('Server'),
        );
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
            case 'jobs':
                return __('Background jobs are running normally.');
            case 'security':
                return __('All the checks below pass.');
            case 'cache':
                return __('The cache is working.');
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
                $report = array(
                    'issues' => array_merge(self::databaseIssues($env, true), self::strictIssues($env, true)),
                    'groups' => array_merge(self::databaseFacts($env), self::strictFacts($env)),
                );
                break;
            case 'server':
                $report = array('issues' => self::serverIssues($env, true), 'groups' => self::serverFacts($env));
                break;
            case 'jobs':
                $report = array('issues' => self::jobsIssues($env, true), 'groups' => self::jobsFacts($env));
                break;
            case 'security':
                $report = array('issues' => self::securityIssues($env, true), 'groups' => self::securityFacts($env));
                break;
            case 'cache':
                $report = array('issues' => self::cacheIssues($env), 'groups' => self::cacheFacts($env));
                break;
            default:
                $report = array(
                    'issues' => array_merge(
                        self::databaseIssues($env, false),
                        self::summary($env, 'database', self::strictIssues($env, false)),
                        self::serverIssues($env, false),
                        self::backupIssues($env),
                        self::summary($env, 'jobs', self::jobsIssues($env, false)),
                        self::summary($env, 'security', self::securityIssues($env, false)),
                        self::summary($env, 'cache', self::cacheIssues($env))
                    ),
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
            return in_array($f['kind'] ?? '', DatabaseTools::CLOSER_LOOK, true);
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
     * What stands in the way of strict SQL mode: writes it refused in the last 7 days, zero
     * dates, and length settings larger than their columns.
     *
     * @param array<string,mixed> $env  'strict' is StrictModeReadiness::report()
     * @param bool                $here true on the Database tab, where the list is on the page
     *
     * @return array<int,array<string,mixed>>
     */
    public static function strictIssues(array $env, bool $here): array
    {
        $strict = $env['strict'] ?? null;
        if (!is_array($strict)) {
            return array();
        }
        $which  = array('label' => __('See which'), 'url' => $here ? '#db-strict' : self::url($env, 'database', 'db-strict'));
        $issues = array();

        if ((string) ($strict['error'] ?? '') !== '') {
            $issues[] = self::issue('strict_unreadable', 'info', sprintf(__('Could not check whether the site is ready for strict SQL mode: %s'), (string) $strict['error']));
        }
        if (empty($strict['log_enabled']) && array_key_exists('log_enabled', $strict)) {
            $issues[] = self::issue('strict_refused_unknown', 'info', __('Refused writes are not recorded while the activity log is off, so this cannot confirm there are none.'), $which);
        } else {
            $refused = array_sum(array_map('intval', array_column((array) ($strict['refused'] ?? array()), 'count')));
            if ($refused > 0) {
                $issues[] = self::issue('strict_refused', 'warning', sprintf(
                    _n('%d write was refused by strict SQL mode in the last 7 days.', '%d writes were refused by strict SQL mode in the last 7 days.', $refused),
                    $refused
                ), $which);
            }
        }
        $zero = count((array) ($strict['zero_dates'] ?? array()));
        if ($zero > 0) {
            $issues[] = self::issue('strict_zero_dates', 'warning', sprintf(
                _n('%d column holds zero dates, which strict SQL mode refuses when the row is saved again.', '%d columns hold zero dates, which strict SQL mode refuses when the row is saved again.', $zero),
                $zero
            ), $which);
        }
        $defaults = count((array) ($strict['zero_defaults'] ?? array()));
        if ($defaults > 0) {
            $issues[] = self::issue('strict_zero_defaults', 'warning', sprintf(
                _n('%d column defaults to a zero date, so strict SQL mode refuses changes to its table.', '%d columns default to a zero date, so strict SQL mode refuses changes to their tables.', $defaults),
                $defaults
            ), $which);
        }
        foreach ((array) ($strict['settings'] ?? array()) as $setting) {
            $issues[] = self::issue('strict_setting_' . (string) $setting['setting'], 'warning', sprintf(
                __('The %1$s setting is %2$d characters, but its column holds %3$d. Strict SQL mode refuses the longer values.'),
                self::lengthSettingName((string) $setting['setting']),
                (int) $setting['value'],
                (int) $setting['width']
            ), array('label' => __('Settings'), 'url' => (string) ($env['admin_url'] ?? '') . '?page=items&action=settings'));
        }

        return $issues;
    }

    /**
     * The server's issues: PHP, extensions, the image library, limits, folders, debug mode
     * and maintenance mode. Cron, the cache and the security checks have their own tabs.
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
        $readOnly = (array) ($env['read_only'] ?? array());
        if (empty($env['self_update_off']) && array_intersect(array('core', 'downloads'), $readOnly) !== array()) {
            $owner    = (string) ($env['file_owner'] ?? '') ?: __('the file owner');
            $issues[] = self::issue('core_read_only', 'warning', sprintf(
                __('PHP runs as %1$s and cannot write the Shopclass files, so updates from the admin fail. Run "php oc-cli.php core:update" as %2$s, or ask your host to run PHP as %2$s.'),
                (string) ($env['php_user'] ?? '') ?: __('unknown'),
                $owner
            ), $here ? array() : $details);
        }
        $packages = array_values(array_intersect(array('plugins', 'themes', 'languages'), $readOnly));
        if ($packages !== array()) {
            $issues[] = self::issue('packages_read_only', 'warning', sprintf(
                __('PHP cannot write to these folders, so installing or updating them from the admin fails: %s.'),
                implode(', ', array_map(array(self::class, 'folderName'), $packages))
            ), $here ? array() : $details);
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
        if (!empty($env['debug'])) {
            $issues[] = self::issue('debug_on', 'warning', __('Debug mode is on. On a live site it shows internal details and slows pages.'), $help);
        }
        $disk = $env['free_disk'] ?? null;
        if (is_numeric($disk) && $disk > 0 && $disk < self::DISK_FLOOR) {
            $issues[] = self::issue('disk_low', 'warning', sprintf(__('Only %s of disk space is left. Uploads and backups fail when it runs out.'), self::size((int) $disk)));
        }
        if (($env['htaccess_auth'] ?? null) === false) {
            $issues[] = self::issue('htaccess_no_authorization', 'warning', sprintf(
                __('The .htaccess file does not pass the Authorization header to PHP, so the API cannot see keys sent in it. Add this line after "RewriteEngine On": %s'),
                ServerRules::AUTHORIZATION
            ));
        }
        $reserved = array_merge((array) ($env['reserved_slugs']['pages'] ?? array()), (array) ($env['reserved_slugs']['categories'] ?? array()));
        if ($reserved !== array()) {
            $issues[] = self::issue('api_slug_taken', 'warning', sprintf(
                __('/api/ belongs to the site\'s API, so these static pages or categories cannot be reached. Give them another slug: %s'),
                implode(', ', $reserved)
            ));
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
     * The queue's issues: jobs that gave up, a queue that is not moving, work with no
     * handler, and cron.
     *
     * @param array<string,mixed> $env
     * @param bool                $here true on the Jobs tab, where the lists are on the page
     *
     * @return array<int,array<string,mixed>>
     */
    public static function jobsIssues(array $env, bool $here): array
    {
        $jobs   = (array) ($env['jobs'] ?? array());
        $issues = array();

        $failed = (int) ($jobs['error'] ?? 0);
        if ($failed > 0) {
            $issues[] = self::issue('jobs_failed', 'warning', sprintf(
                _n('%d background job stopped after failing again and again.', '%d background jobs stopped after failing again and again.', $failed),
                $failed
            ), array('label' => __('See why'), 'url' => $here ? '#jobs-failed' : self::url($env, 'jobs', 'jobs-failed')));
        }

        $cron = self::cronIssues($env);
        // With cron stopped, its line already says why nothing moves.
        if ($cron === array()) {
            $run     = $here
                ? array('label' => __('Run them now'), 'type' => 'submit', 'attrs' => array('form' => 'jobs-run-form'))
                : array('label' => __('See the queue'), 'url' => self::url($env, 'jobs', 'jobs-queue'));
            $overdue = strtotime((string) ($jobs['overdue'] ?? ''));
            if ((int) ($jobs['stuck'] ?? 0) > 0) {
                $issues[] = self::issue('jobs_stuck', 'warning', __('A job has been running for more than 15 minutes. The run that took it may have stopped partway.'), $run);
            } elseif ($overdue !== false && self::now($env) - $overdue > self::JOBS_OVERDUE) {
                $issues[] = self::issue('jobs_stuck', 'warning', sprintf(
                    __('Jobs have been waiting for %s although cron runs.'),
                    osc_admin_duration(self::now($env) - $overdue)
                ), $run);
            }
        }

        $orphans = array_values(array_map('strval', (array) ($jobs['orphans'] ?? array())));
        if ($orphans !== array()) {
            $issues[] = self::issue('jobs_orphans', 'warning', sprintf(
                __('Queued work has nothing to run it: %s. A plugin was probably turned off with jobs still waiting.'),
                implode(', ', $orphans)
            ));
        }

        return array_merge($issues, $cron);
    }

    /**
     * The security issues: visitor addresses behind a proxy, an open backups folder,
     * sign-in protection, admins without two-step sign-in, blocked sign-ins and config.php.
     *
     * @param array<string,mixed> $env
     * @param bool                $here true on the Security tab
     *
     * @return array<int,array<string,mixed>>
     */
    public static function securityIssues(array $env, bool $here): array
    {
        $admin    = (string) ($env['admin_url'] ?? '');
        $throttle = (array) ($env['throttle'] ?? array());
        $issues   = array();

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
        if (array_key_exists('enabled', $throttle) && !$throttle['enabled']) {
            $issues[] = self::issue('signin_protection_off', 'warning', __('Sign-in protection is off, so nothing slows down password guessing.'), array('label' => __('Settings'), 'url' => self::spamSettingsUrl($env)));
        }

        $without = array_values(array_filter((array) ($env['admins'] ?? array()), static function ($a) {
            return empty($a['two_factor']);
        }));
        if ($without !== array()) {
            $mine = in_array((int) ($env['me'] ?? 0), array_map('intval', array_column($without, 'id')), true);
            $issues[] = self::issue('admins_no_2fa', 'warning', sprintf(
                _n('%1$d admin signs in with a password only: %2$s.', '%1$d admins sign in with a password only: %2$s.', count($without)),
                count($without),
                implode(', ', array_map(static function ($a) {
                    return (string) ($a['username'] ?? '');
                }, $without))
            ), $mine
                ? array('label' => __('Turn on yours'), 'url' => $admin . '?page=admins&action=edit')
                : array('label' => __('Admins'), 'url' => $admin . '?page=admins'));
        }

        $blocked = (int) ($throttle['blocked'] ?? 0);
        if ($blocked > 0) {
            $issues[] = self::issue('signin_blocked', 'info', sprintf(
                _n('%d address or account is blocked from signing in right now.', '%d addresses or accounts are blocked from signing in right now.', $blocked),
                $blocked
            ), array('label' => __('See the list'), 'url' => $here ? '#signin-activity' : self::url($env, 'security', 'signin-activity')));
        }
        if (!empty($env['config_writable'])) {
            $issues[] = self::issue('config_writable', 'warning', __('config.php can be written by the web server. It holds your database password; make it read-only.'));
        }

        return $issues;
    }

    /**
     * An object cache that config.php asks for but that is not installed or does not answer.
     *
     * @param array<string,mixed> $env
     *
     * @return array<int,array<string,mixed>>
     */
    public static function cacheIssues(array $env): array
    {
        $driver = (string) ($env['cache_driver'] ?? 'default');
        if ($driver === 'default') {
            return array();
        }
        $help = array('label' => __('How to change it'), 'url' => self::url($env, 'server', 'server-help'));
        if (empty($env['cache_supported'])) {
            return array(self::issue('cache_unsupported', 'warning', sprintf(
                __('config.php asks for the %s object cache, but that driver is not installed, so nothing is cached.'),
                $driver
            ), $help));
        }
        if (($env['cache_working'] ?? null) === false) {
            return array(self::issue('cache_unreachable', 'warning', sprintf(
                __('The %s object cache is installed but did not answer, so nothing is cached. Check that its server is running.'),
                $driver
            )));
        }

        return array();
    }

    /**
     * One Overview line for a tab: its worst amber or red issue, pointing at the tab.
     *
     * @param array<string,mixed>            $env
     * @param string                         $tab
     * @param array<int,array<string,mixed>> $issues that tab's issues
     *
     * @return array<int,array<string,mixed>>
     */
    public static function summary(array $env, string $tab, array $issues): array
    {
        $issues = array_values(array_filter(self::rank($issues), static function ($i) {
            return in_array($i['tone'] ?? '', array('danger', 'warning'), true);
        }));
        if ($issues === array()) {
            return array();
        }
        $text = (string) $issues[0]['text'];
        if (count($issues) > 1) {
            $text .= ' ' . sprintf(_n('And %d more.', 'And %d more.', count($issues) - 1), count($issues) - 1);
        }

        return array(self::issue($tab . '_summary', (string) $issues[0]['tone'], $text, array(
            'label' => sprintf(__('Open %s'), self::labels()[$tab] ?? $tab),
            'url'   => self::url($env, $tab),
        )));
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
     * The name of the system user with this id; '#id' when it has none, '' with no id.
     */
    public static function userName(?int $uid): string
    {
        if ($uid === null) {
            return '';
        }
        $info = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;

        return is_array($info) ? (string) $info['name'] : '#' . $uid;
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
                'value' => sprintf(_n('%d table', '%d tables', (int) $size['tables']), (int) $size['tables']) . ' · ' . Formatting::bytes((int) $size['bytes']),
            );
        }
        $rows[] = array('label' => __('Table prefix'), 'value' => (string) ($env['prefix'] ?? ''), 'mono' => true);

        return array(array('title' => '', 'rows' => $rows));
    }

    /**
     * The strict SQL mode facts, and the refused writes when there are any.
     *
     * @param array<string,mixed> $env
     *
     * @return array<int,array<string,mixed>>
     */
    private static function strictFacts(array $env): array
    {
        $strict = $env['strict'] ?? null;
        if (!is_array($strict)) {
            return array();
        }
        $server     = (string) ($strict['server_mode'] ?? '');
        $session    = StrictModeReadiness::isStrict((string) ($strict['session_mode'] ?? ''));
        $constant   = !empty($strict['constant']);
        $logEnabled = !array_key_exists('log_enabled', $strict) || (bool) $strict['log_enabled'];
        $refused    = $logEnabled ? (array) ($strict['refused'] ?? array()) : array();
        $zero       = $strict['zero_dates'] ?? null;
        $defaults   = (array) ($strict['zero_defaults'] ?? array());
        $settings   = (array) ($strict['settings'] ?? array());
        $none       = __('none');

        if ($constant) {
            $note = $session ? __('Shopclass keeps the server\'s mode.') : __('Shopclass keeps the server\'s mode, but it is not strict, so over-long values are still cut short.');
        } else {
            $note = __('Shopclass turns the strict modes off, so over-long values are cut short without an error.');
        }
        $zeroList = array();
        foreach ((array) $zero as $column => $rows) {
            $zeroList[] = sprintf(_n('%1$s (%2$d row)', '%1$s (%2$d rows)', (int) $rows), $column, (int) $rows);
        }
        $settingList = array();
        foreach ($settings as $setting) {
            $settingList[] = sprintf(__('%1$s: %2$d, %3$s holds %4$d'), self::lengthSettingName((string) $setting['setting']), (int) $setting['value'], (string) $setting['column'], (int) $setting['width']);
        }
        $total = array_sum(array_map('intval', array_column($refused, 'count')));

        $rows = array(
            array('label' => __('Server mode'), 'value' => StrictModeReadiness::isStrict($server) ? __('strict') : __('not strict'), 'note' => $server !== '' ? str_replace(',', ', ', $server) : __('empty')),
            array('label' => 'OSC_DB_STRICT_MODE', 'value' => $constant ? __('set') : __('not set'), 'note' => $note),
            array(
                'label' => __('Refused writes, last 7 days'),
                'value' => $logEnabled ? ($total > 0 ? number_format($total) : $none) : __('unknown'),
                'note'  => $logEnabled ? '' : __('Refused writes are not recorded while the activity log is off.'),
            ),
            array(
                'label' => __('Zero dates'),
                'value' => $zero === null ? __('not checked here') : ($zeroList === array() ? $none : sprintf(_n('%d column', '%d columns', count($zeroList)), count($zeroList))),
                'note'  => implode(', ', $zeroList),
            ),
            array('label' => __('Zero-date defaults'), 'value' => $defaults === array() ? $none : implode(', ', $defaults)),
            array(
                'label' => __('Length settings'),
                'value' => $settingList === array() ? __('fit their columns') : sprintf(_n('%d is larger than its column', '%d are larger than their columns', count($settingList)), count($settingList)),
                'note'  => implode('; ', $settingList),
            ),
        );
        $groups = array(array('title' => __('Strict SQL mode'), 'id' => 'db-strict', 'rows' => $rows));

        if ($refused !== array()) {
            $list = array();
            foreach ($refused as $r) {
                $when   = strtotime((string) $r['last']);
                $list[] = array(
                    'label' => (string) $r['column'] !== '' ? (string) $r['column'] : __('unknown column'),
                    'value' => self::strictKindWord((string) $r['kind']),
                    'note'  => sprintf(_n('%d time', '%d times', (int) $r['count']), (int) $r['count'])
                        . ($when !== false ? ' · ' . sprintf(__('last %s ago'), osc_admin_duration(max(0, self::now($env) - $when))) : ''),
                );
            }
            $groups[] = array('title' => __('Writes strict SQL mode refused'), 'rows' => $list);
        }

        return $groups;
    }

    /**
     * What a refusal kind means, in the owner's words.
     *
     * @param string $kind StrictRefusals::KINDS value
     *
     * @return string
     */
    public static function strictKindWord(string $kind): string
    {
        $words = array(
            'data_too_long'   => __('value too long'),
            'data_truncated'  => __('value the column cannot hold'),
            'incorrect_value' => __('wrong kind of value'),
            'bad_date'        => __('invalid date or time'),
            'cannot_be_null'  => __('empty where a value is required'),
            'no_default'      => __('no value and no default'),
            'out_of_range'    => __('number out of range'),
        );

        return $words[$kind] ?? $kind;
    }

    /**
     * @param string $setting
     *
     * @return string
     */
    private static function lengthSettingName(string $setting): string
    {
        $names = array(
            'title_character_length'       => __('Title length'),
            'description_character_length' => __('Description length'),
        );

        return $names[$setting] ?? $setting;
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

        $files = array(
            array(
                'label' => __('Uploads folder'),
                'value' => (!empty($env['uploads_writable']) ? __('writable') : __('read-only')) . ' · ' . (string) ($env['uploads_path'] ?? ''),
            ),
            array(
                'label' => __('PHP runs as'),
                'value' => (string) ($env['php_user'] ?? '') ?: __('unknown'),
                'note'  => ($env['file_owner'] ?? '') !== '' && ($env['file_owner'] ?? '') !== ($env['php_user'] ?? '')
                    ? sprintf(__('The files belong to %s.'), (string) $env['file_owner']) : '',
            ),
            array('label' => __('Read-only folders'), 'value' => ($env['read_only'] ?? array()) === array()
                ? __('none')
                : implode(', ', array_map(array(self::class, 'folderName'), (array) $env['read_only']))),
            array('label' => __('Free space'), 'value' => self::size(is_numeric($env['free_disk'] ?? null) ? (int) $env['free_disk'] : 0)),
            array('label' => __('Photo storage'), 'value' => self::storageWords($env)),
        );

        $maintenance = (string) ($env['maintenance'] ?? '');
        $modes       = array(
            array('label' => __('Debug mode'), 'value' => !empty($env['debug']) ? $on : $off),
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
                Formatting::bytes((int) ($env['prefs_bytes'] ?? 0))
            )),
        );

        return array(
            array('title' => __('PHP and web server'), 'rows' => $php),
            array('title' => __('Files and storage'), 'rows' => $files),
            array('title' => __('Debug and maintenance'), 'rows' => $modes),
            array('title' => __('Paths'), 'rows' => $paths),
        );
    }

    /**
     * @param array<string,mixed> $env
     *
     * @return array<int,array{title:string,rows:array}>
     */
    private static function jobsFacts(array $env): array
    {
        $jobs    = (array) ($env['jobs'] ?? array());
        $pending = (int) ($jobs['pending'] ?? 0);
        $dueSince = strtotime((string) ($jobs['due_since'] ?? ''));
        $waiting  = number_format($pending);
        if ((int) ($jobs['due'] ?? 0) > 0 && $dueSince !== false) {
            $waiting .= ' · ' . sprintf(__('the oldest due for %s'), osc_admin_duration(max(0, self::now($env) - $dueSince)));
        }

        return array(array('title' => '', 'rows' => array(
            array('label' => __('Waiting'), 'value' => $waiting),
            array('label' => __('Running'), 'value' => number_format((int) ($jobs['running'] ?? 0))),
            array('label' => __('Gave up'), 'value' => number_format((int) ($jobs['error'] ?? 0))),
            array('label' => __('Cron'), 'value' => self::cronWords($env)),
        )));
    }

    /**
     * @param array<string,mixed> $env
     *
     * @return array<int,array<string,mixed>>
     */
    private static function securityFacts(array $env): array
    {
        $throttle = (array) ($env['throttle'] ?? array());
        $on       = !empty($throttle['enabled']);
        $blocked  = (int) ($throttle['blocked'] ?? 0);
        $signin   = array(array('label' => __('Status'), 'value' => $on ? __('on') : __('off')));
        if ($on) {
            $signin[] = array(
                'label' => __('Limits'),
                'value' => sprintf(__('%1$d failures per address in %2$d minutes'), (int) ($throttle['max_ip'] ?? 0), (int) ($throttle['window'] ?? 0)),
                'note'  => !empty($throttle['captcha'])
                    ? __('A captcha is set up, so there is no limit per account.')
                    : sprintf(__('And %d per account.'), (int) ($throttle['max_account'] ?? 0)),
            );
        }
        $signin[] = array('label' => __('Blocked right now'), 'value' => $blocked === 0
            ? __('nobody')
            : sprintf(_n('%d address or account', '%d addresses or accounts', $blocked), $blocked));

        $admins = array();
        foreach ((array) ($env['admins'] ?? array()) as $a) {
            $admins[] = array(
                'label' => (string) ($a['username'] ?? ''),
                'value' => !empty($a['two_factor']) ? __('two-step sign-in') : __('password only'),
                'note'  => trim((string) ($a['name'] ?? '') . (!empty($a['moderator']) ? ' · ' . __('moderator') : '')),
            );
        }

        $proxy = $env['proxy'] ?? null;
        $probe = $env['backup_probe'] ?? null;
        $site  = array(
            array('label' => __('Visitor addresses'), 'value' => !empty($proxy)
                ? sprintf(__('all seen as %s (behind a proxy)'), (string) ($proxy['proxy'] ?? ''))
                : __('as reported')),
            array('label' => __('Backups folder'), 'value' => BackupStore::FOLDER . ' · ' . ($probe === true
                ? __('reachable from the web')
                : ($probe === false ? __('not reachable from the web') : __('not checked yet')))),
            array('label' => __('Restore from the admin'), 'value' => !empty($env['web_restore_off'])
                ? __('off, command line only')
                : __('allowed, after a password check')),
            array('label' => __('Plugin and theme installs'), 'value' => !empty($env['package_installs_off'])
                ? __('off')
                : __('allowed from the admin')),
            array('label' => __('config.php'), 'value' => !empty($env['config_writable']) ? __('writable') : __('read-only')),
        );

        return array(
            array('title' => __('Sign-in protection'), 'rows' => $signin, 'link' => array(
                'label' => __('Change it in Settings > Spam and bots'),
                'url'   => self::spamSettingsUrl($env),
            )),
            array('title' => __('Admins'), 'rows' => $admins),
            array('title' => __('Site'), 'rows' => $site),
        );
    }

    /**
     * @param array<string,mixed> $env
     *
     * @return array<int,array{title:string,rows:array}>
     */
    private static function cacheFacts(array $env): array
    {
        $driver  = (string) ($env['cache_driver'] ?? 'default');
        $working = $env['cache_working'] ?? null;
        if ($driver === 'default') {
            $state = __('yes, for one request at a time');
        } elseif (empty($env['cache_supported'])) {
            $state = __('no, the driver is not installed');
        } else {
            $state = $working === false ? __('no, it did not answer') : __('yes');
        }
        $rows = array(
            array('label' => __('Driver'), 'value' => self::cacheName($driver)),
            array('label' => __('Keeps data between requests'), 'value' => $driver === 'default' ? __('no') : __('yes')),
            array('label' => __('Working'), 'value' => $state),
        );
        $rows = array_merge($rows, self::cacheStatRows((array) ($env['cache_stats'] ?? array())));

        $drivers   = array();
        $available = (array) ($env['cache_drivers'] ?? array());
        foreach (self::CACHE_DRIVERS as $name) {
            $drivers[] = array(
                'label' => self::cacheName($name),
                'value' => (!empty($available[$name]) ? __('installed') : __('not installed'))
                    . ($name === $driver ? ' · ' . __('in use') : ''),
            );
        }

        return array(
            array('title' => '', 'rows' => $rows),
            array('title' => __('Drivers on this server'), 'rows' => $drivers),
        );
    }

    /**
     * The readings a cache driver reports, as fact rows; a reading it does not give is left out.
     *
     * @param array<string,mixed> $stats osc_cache_stats()
     *
     * @return array<int,array<string,string>>
     */
    public static function cacheStatRows(array $stats): array
    {
        $number = static function ($v) {
            return $v === null ? null : number_format((int) $v);
        };
        $hits   = $stats['hits'] ?? null;
        $misses = $stats['misses'] ?? null;
        $total  = (int) $hits + (int) $misses;
        $memory = isset($stats['memory_used']) ? Formatting::bytes((int) $stats['memory_used']) : null;
        if ($memory !== null && isset($stats['memory_total'])) {
            $memory = sprintf(__('%1$s of %2$s'), $memory, Formatting::bytes((int) $stats['memory_total']));
        }
        $cells = array(
            __('Hit rate')  => $hits !== null && $misses !== null && $total > 0
                ? sprintf(__('%1$s%% (%2$s hits, %3$s misses)'), round($hits / $total * 100, 1), number_format((int) $hits), number_format((int) $misses))
                : null,
            __('Entries')   => $number($stats['entries'] ?? null),
            __('Memory')    => $memory,
            __('Evictions') => $number($stats['evictions'] ?? null),
            __('Uptime')    => isset($stats['uptime']) ? osc_admin_duration((int) $stats['uptime']) : null,
            __('Server')    => isset($stats['server']) && $stats['server'] !== '' ? (string) $stats['server'] : null,
        );
        $rows = array();
        foreach ($cells as $label => $value) {
            if ($value !== null) {
                $rows[] = array('label' => $label, 'value' => $value);
            }
        }

        return $rows;
    }

    /**
     * A cache driver's name as the owner reads it.
     *
     * @param string $driver
     *
     * @return string
     */
    public static function cacheName(string $driver): string
    {
        $names = array(
            'default'   => __('In-request only (default)'),
            'apcu'      => 'APCu',
            'memcached' => 'Memcached',
            'memcache'  => 'Memcache',
        );

        return $names[$driver] ?? $driver;
    }

    /**
     * @param array<string,mixed> $env
     *
     * @return string
     */
    private static function spamSettingsUrl(array $env): string
    {
        return (string) ($env['admin_url'] ?? '') . '?page=settings&action=spamNbots#login-throttle-settings';
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
            . ' · ' . ((string) ($env['backup_last']['where'] ?? '') === 'bucket' ? __('in the bucket') : __('on the server'));
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

        return $bytes > 0 ? Formatting::bytes($bytes) : '—';
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

    private static function folderName(string $key): string
    {
        $names = array(
            'core'      => __('Shopclass files'),
            'downloads' => __('downloads'),
            'plugins'   => __('plugins'),
            'themes'    => __('themes'),
            'languages' => __('languages'),
        );

        return $names[$key] ?? $key;
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
