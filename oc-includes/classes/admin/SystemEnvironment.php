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

namespace mindstellar\admin;

use mindstellar\backup\BackupService;
use mindstellar\upgrade\BuildInfo;
use Params;
use Preference;

/**
 * What System info and the notice feed check, read once. Every read is defensive: this is
 * the page an owner opens when something is already wrong.
 */
final class SystemEnvironment
{
    /**
     * @param bool $withDatabase also read the schema check, which the Server tab and the notice feed skip
     * @param bool $quick        skip the cache and backup probes, which can wait on another server
     *
     * @return array<string,mixed>
     */
    public static function read(bool $withDatabase, bool $quick = false): array
    {
        $server = DatabaseTools::serverInfo();
        $cacheDriver = defined('OSC_CACHE') ? (string) OSC_CACHE : 'default';
        $maintenance = '';
        if (file_exists(ABS_PATH . '.maintenance')) {
            $maintenance = osc_maintenance_lockout_enabled() ? 'locked' : 'banner';
        }
        $uploads = osc_uploads_path();
        $free    = function_exists('disk_free_space') ? @disk_free_space($uploads) : false;
        $queue   = \mindstellar\job\JobQueue::getInstance();
        \mindstellar\job\JobWorker::registerHandlers();
        $stats    = $queue->stats();
        $signins  = \mindstellar\security\LoginThrottle::activity();
        $drivers  = array();
        foreach (SystemChecks::CACHE_DRIVERS as $name) {
            $drivers[$name] = self::cacheSupported($name);
        }
        $cacheOn  = $cacheDriver === 'default' || self::cacheSupported($cacheDriver);
        $prefs   = Preference::getInstance()->listAll();
        $last    = json_decode((string) osc_get_preference('backup_last'), true);
        $htaccess     = osc_base_path() . '.htaccess';
        $htaccessAuth = osc_rewrite_enabled() && !osc_server_is_nginx() && is_file($htaccess)
            ? \mindstellar\routing\ServerRules::passesAuthorization($htaccess)
            : null;
        try {
            $reservedSlugs = \mindstellar\routing\ReservedSlugs::conflicts();
        } catch (\Throwable $e) {
            $reservedSlugs = array();
        }

        $env = array(
            'admin_url'        => osc_admin_base_url(true),
            'now'              => time(),
            'version'          => BuildInfo::label(OSCLASS_VERSION),
            'db_version'       => (string) osc_version(),
            'php'              => PHP_VERSION,
            'os'               => PHP_OS . (function_exists('php_uname') ? ' (' . php_uname('m') . ')' : ''),
            'web_server'       => (string) Params::getServerParam('SERVER_SOFTWARE'),
            'db_server'        => $server,
            'prefix'           => DB_TABLE_PREFIX,
            'site_url'         => osc_base_url(),
            'content_path'     => osc_content_path(),
            'uploads_path'     => $uploads,
            'plugins_path'     => osc_plugins_path(),
            'themes_path'      => osc_themes_path(),
            'ini_file'         => function_exists('php_ini_loaded_file') ? (string) php_ini_loaded_file() : '',
            'prefs_count'      => count($prefs),
            'prefs_bytes'      => strlen(serialize($prefs)),
            'memory'           => SystemChecks::iniBytes((string) ini_get('memory_limit')),
            'upload'           => SystemChecks::iniBytes((string) ini_get('upload_max_filesize')),
            'post'             => SystemChecks::iniBytes((string) ini_get('post_max_size')),
            'max_files'        => (int) ini_get('max_file_uploads'),
            'max_exec'         => (int) ini_get('max_execution_time'),
            'max_input_vars'   => (string) ini_get('max_input_vars'),
            'timezone'         => (string) ini_get('date.timezone'),
            'photos'           => (int) osc_max_images_per_item(),
            'extensions'       => get_loaded_extensions(),
            'imagick'          => extension_loaded('imagick'),
            'imagick_on'       => \mindstellar\image\ImagickEngine::enabled(),
            'gd'               => extension_loaded('gd'),
            'opcache'          => function_exists('opcache_get_status') && ini_get('opcache.enable'),
            'allow_url_fopen'  => (bool) ini_get('allow_url_fopen'),
            'uploads_writable' => @is_writable($uploads),
            'php_user'         => SystemChecks::userName(function_exists('posix_geteuid') ? posix_geteuid() : null),
            'file_owner'       => SystemChecks::userName(@fileowner(ABS_PATH . 'index.php') ?: null),
            'self_update_off'  => osc_self_update_disabled(),
            'read_only'        => array_keys(array_filter(array(
                'core'      => ABS_PATH . 'oc-includes',
                'downloads' => osc_content_path() . 'downloads',
                'plugins'   => osc_plugins_path(),
                'themes'    => osc_themes_path(),
                'languages' => osc_translations_path(),
            ), static fn ($dir) => is_dir($dir) && !@is_writable($dir))),
            'free_disk'        => is_numeric($free) ? (int) $free : null,
            'config_writable'  => @is_writable(ABS_PATH . 'config.php'),
            'debug'            => defined('OSC_DEBUG') && OSC_DEBUG,
            'secret_mail_blocked' => \mindstellar\mail\Mailer::secretLinksBlocked(),
            'maintenance'      => $maintenance,
            'htaccess_auth'    => $htaccessAuth,
            'reserved_slugs'   => $reservedSlugs,
            'cache_driver'     => $cacheDriver,
            'cache_supported'  => $cacheOn,
            'cache_working'    => !$quick && $cacheDriver !== 'default' && $cacheOn ? self::cacheAnswers() : null,
            'cache_stats'      => !$quick && $cacheOn ? osc_cache_stats() : null,
            'cache_drivers'    => $drivers,
            'jobs'             => array(
                'pending'   => (int) $stats['pending'],
                'running'   => (int) $stats['running'],
                'error'     => (int) $stats['error'],
                'oldest'    => $stats['oldest'],
                'due'       => (int) $stats['due'],
                'due_since' => $stats['due_since'],
                'orphans'   => array_values(array_diff($queue->queuedTypes(), \mindstellar\job\JobRegistry::types())),
            ) + $queue->health(),
            'live'             => array(
                'mode' => \mindstellar\container\Container::root()->get(\mindstellar\live\LivePublisher::class)->mode(),
                'last' => \mindstellar\container\Container::root()->get(\mindstellar\live\LiveStatus::class)->last(),
            ),
            'push'             => array(
                'enabled'       => osc_push_enabled(),
                'switch'        => (bool) osc_get_bool_preference('enabled_push'),
                'https'         => \mindstellar\push\WebPushSender::secureSite((string) osc_base_url()),
                'keys'          => (string) osc_get_preference(\mindstellar\push\Vapid::PUBLIC_PREF) !== '',
                'subscriptions' => \mindstellar\container\Container::root()->get(\mindstellar\push\PushSubscriptions::class)->count(),
                'last'          => \mindstellar\container\Container::root()->get(\mindstellar\push\WebPushSender::STATUS)->last(),
            ),
            'me'               => (int) osc_logged_admin_id(),
            'admins'           => \mindstellar\security\AdminTwoFactor::admins(),
            'throttle'         => array(
                'enabled'     => (bool) osc_login_throttle_enabled(),
                'window'      => (int) osc_login_throttle_window(),
                'max_ip'      => (int) osc_login_throttle_max_ip(),
                'max_account' => (int) osc_login_throttle_max_account(),
                'captcha'     => (bool) osc_captcha_enabled(),
                'blocked'     => count(array_filter($signins, static function ($row) {
                    return !empty($row['blocked']);
                })),
            ),
            'signins'          => $signins,
            'web_restore_off'  => (bool) osc_web_restore_disabled(),
            'package_installs_off' => (bool) osc_package_installs_disabled(),
            'cron_last'        => osc_cron_last_run(),
            // This request came through the same proxy every visitor does.
            'proxy'            => osc_proxy_ip_mismatch(),
            'admin_ip_allow'   => ($allow = \mindstellar\security\AdminIpAllowlist::fromConfig()) === null ? null : $allow->count(),
            'backup_last'      => is_array($last) ? $last : null,
            'backup_probe'     => $quick ? null : BackupService::probe(),
            'storage'          => array(
                'active'     => (string) osc_get_preference('storage_active'),
                'bucket'     => (string) osc_get_preference('storage_s3_bucket'),
                'keep_local' => (string) osc_get_preference('storage_keep_local'),
            ),
            'pending'          => array(),
            'findings'         => array(),
            'findings_error'   => '',
            'db_size'          => null,
        );
        if ($withDatabase) {
            $env['pending'] = DatabaseTools::pending();
            list($env['findings'], $env['findings_error']) = DatabaseTools::findings();
            $env['db_size'] = DatabaseTools::size();
        }

        return $env;
    }

    /**
     * Whether an object-cache driver has a class here and says this server can run it.
     *
     * @param string $driver
     *
     * @return bool
     */
    private static function cacheSupported(string $driver): bool
    {
        $class = \mindstellar\cache\CacheManager::driverClass($driver);

        return $class !== null && $class::is_supported();
    }

    /**
     * Whether the cache keeps a value: write a probe key, read it back, remove it. The
     * write's own answer counts, because a driver may serve the read from its in-request copy.
     *
     * @return bool
     */
    private static function cacheAnswers(): bool
    {
        $cache = \mindstellar\cache\CacheManager::getInstance();
        $key   = 'osc_sysinfo_probe';
        $value = bin2hex(random_bytes(8));
        try {
            if ($cache->set($key, $value, 60) === false) {
                return false;
            }
            $found = null;
            $read  = $cache->get($key, $found);
            $cache->delete($key);
        } catch (\Throwable $e) {
            return false;
        }

        return $found !== false && $read === $value;
    }
}
