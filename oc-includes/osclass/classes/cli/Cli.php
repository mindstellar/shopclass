<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\cli;

use Admin;
use Cron;
use mindstellar\database\Connection;
use Params;
use Sitemap;

/**
 * Command-line router for maintenance tasks (cron, DB upgrade, cache, sitemap).
 *
 * Reached only through oc-cli.php, which refuses any non-CLI SAPI, so these
 * verbs are never HTTP-addressable — unlike index.php's `page` switch, they do
 * not share the web request dispatcher.
 */
class Cli
{
    /**
     * command name => [handler method, one-line summary]
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private array $commands = [
        'cron'                => ['cmdCron', 'Run due scheduled tasks (--type=hourly|daily|weekly|all)'],
        'db:upgrade'          => ['cmdDbUpgrade', 'Reconcile schema and run pending migrations (--skip-db)'],
        'cache:flush'         => ['cmdCacheFlush', 'Flush the object cache'],
        'sitemap:warm'        => ['cmdSitemapWarm', 'Pre-generate the XML sitemap into the cache'],
        'user:create-admin'   => ['cmdUserCreateAdmin', 'Create an admin (--user= --email= [--password=] [--name=])'],
        'user:reset-password' => ['cmdUserResetPassword', 'Reset an admin password (--user=|--email= [--password=])'],
        'doctor'              => ['cmdDoctor', 'Run environment and health checks'],
        'version'             => ['cmdVersion', 'Print the installed Shopclass version'],
        'help'                => ['cmdHelp', 'Show this help'],
    ];

    /**
     * @param array<int, string> $argv arguments after the script name
     */
    public static function run(array $argv): int
    {
        return (new self())->dispatch($argv);
    }

    /**
     * @param array<int, string> $argv
     */
    public function dispatch(array $argv): int
    {
        $command = $argv[0] ?? 'help';
        if (in_array($command, ['-h', '--help', ''], true)) {
            $command = 'help';
        }

        if (!isset($this->commands[$command])) {
            $this->err(sprintf("Unknown command: %s\n\n", $command));
            $this->cmdHelp([]);

            return 2;
        }

        $args   = $this->parseOptions(array_slice($argv, 1));
        $method = $this->commands[$command][0];

        try {
            return (int) $this->$method($args);
        } catch (\Throwable $e) {
            $this->err('Error: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * Parse `--key=value` / `--flag` options; bare words collect under `_`.
     *
     * @param array<int, string> $args
     *
     * @return array<string, mixed>
     */
    private function parseOptions(array $args): array
    {
        $opts = [];
        foreach ($args as $arg) {
            if (strncmp($arg, '--', 2) === 0) {
                [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
                $opts[$key]    = $value;
            } else {
                $opts['_'][] = $arg;
            }
        }

        return $opts;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdCron(array $args): int
    {
        $type  = (string) ($args['type'] ?? 'all');
        $valid = ['hourly', 'daily', 'weekly'];

        if ($type !== 'all' && !in_array($type, $valid, true)) {
            $this->err("Invalid --type. Use hourly, daily, weekly, or all.\n");

            return 2;
        }

        // Mirrors index.php's cron dispatch: mark the run so nested code never
        // tries to schedule another auto-cron pass.
        if (!defined('__FROM_CRON__')) {
            define('__FROM_CRON__', true);
        }

        $types = $type === 'all' ? $valid : [$type];
        foreach ($types as $t) {
            Params::setParam('cron-type', $t);
            // cron.php is procedural and gates each block on the cron-type param,
            // so requiring it once per type runs exactly that schedule.
            require LIB_PATH . 'osclass/cron.php';
            $this->out(sprintf("Ran %s cron.\n", $t));
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdDbUpgrade(array $args): int
    {
        $result  = \mindstellar\upgrade\Osclass::upgradeDB(array_key_exists('skip-db', $args));
        $decoded = json_decode((string) $result, true);
        $error   = is_array($decoded) ? (int) ($decoded['error'] ?? 1) : 1;
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? $result) : (string) $result;

        // upgradeDB() builds messages for the admin screen, so strip the markup
        // and collapse whitespace for a terminal.
        $message = trim(preg_replace('/\s+/', ' ', strip_tags($message)));

        if ($error === 0) {
            $this->out($message . "\n");

            return 0;
        }

        $this->err($message . "\n");
        if ($error === 2) {
            $this->err("Re-run with --skip-db to continue past false-positive query errors.\n");
        }

        return 1;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdCacheFlush(array $args): int
    {
        // The default per-request driver has nothing to flush and returns false;
        // that is not a failure, so the exit code stays 0.
        $flushed = osc_cache_flush();
        $this->out($flushed ? "Object cache flushed.\n" : "Nothing to flush (no persistent cache backend active).\n");

        return 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdSitemapWarm(array $args): int
    {
        $map = Sitemap::newInstance()->warmCache();
        foreach ($map as $doc => $ok) {
            $this->out(sprintf("  %-12s %s\n", $doc, $ok ? 'ok' : 'FAILED'));
        }

        return in_array(false, $map, true) ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdUserCreateAdmin(array $args): int
    {
        $username = trim((string) ($args['user'] ?? ''));
        $email    = trim((string) ($args['email'] ?? ''));
        $name     = trim((string) ($args['name'] ?? 'Administrator'));

        if ($username === '' || $email === '') {
            $this->err("Usage: user:create-admin --user=<username> --email=<email> [--password=<pw>] [--name=<name>]\n");

            return 2;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->err("Invalid email address.\n");

            return 2;
        }
        if (Admin::newInstance()->findByUsername($username)) {
            $this->err(sprintf("An admin with username '%s' already exists.\n", $username));

            return 1;
        }
        if (Admin::newInstance()->findByEmail($email)) {
            $this->err(sprintf("An admin with email '%s' already exists.\n", $email));

            return 1;
        }

        [$password, $generated] = $this->resolvePassword($args);

        // Mirrors the installer's admin insert: s_secret and b_moderator are left
        // to their column defaults (empty secret, full admin).
        $inserted = Admin::newInstance()->insert([
            's_name'     => $name,
            's_username' => $username,
            's_password' => osc_hash_password($password),
            's_email'    => $email,
        ]);
        if (!$inserted) {
            $this->err("Could not create the admin account.\n");

            return 1;
        }

        $this->out(sprintf("Admin '%s' created.\n", $username));
        if ($generated) {
            $this->out(sprintf("Generated password: %s\n", $password));
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdUserResetPassword(array $args): int
    {
        $username = trim((string) ($args['user'] ?? ''));
        $email    = trim((string) ($args['email'] ?? ''));

        if ($username === '' && $email === '') {
            $this->err("Usage: user:reset-password --user=<username>|--email=<email> [--password=<pw>]\n");

            return 2;
        }

        $admin = $username !== ''
            ? Admin::newInstance()->findByUsername($username)
            : Admin::newInstance()->findByEmail($email);
        if (!$admin) {
            $this->err("No matching admin found.\n");

            return 1;
        }

        [$password, $generated] = $this->resolvePassword($args);

        $updated = Admin::newInstance()->update(
            ['s_password' => osc_hash_password($password)],
            ['pk_i_id' => $admin['pk_i_id']]
        );
        if ($updated === false) {
            $this->err("Could not update the password.\n");

            return 1;
        }

        $this->out(sprintf("Password reset for admin '%s'.\n", $admin['s_username']));
        if ($generated) {
            $this->out(sprintf("Generated password: %s\n", $password));
        }

        return 0;
    }

    /**
     * Resolve a password from --password, or generate one.
     *
     * @param array<string, mixed> $args
     *
     * @return array{0: string, 1: bool} [plain password, was generated]
     */
    private function resolvePassword(array $args): array
    {
        $supplied = $args['password'] ?? null;
        if (is_string($supplied) && $supplied !== '') {
            return [$supplied, false];
        }

        return [osc_genRandomPassword(12), true];
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdDoctor(array $args): int
    {
        $worst = 'ok';
        $check = function (string $level, string $label, string $detail = '') use (&$worst): void {
            $rank  = ['ok' => 0, 'warn' => 1, 'fail' => 2];
            $mark  = ['ok' => '[ OK ]', 'warn' => '[WARN]', 'fail' => '[FAIL]'];
            if ($rank[$level] > $rank[$worst]) {
                $worst = $level;
            }
            $line = sprintf('%s %s', $mark[$level], $label);
            if ($detail !== '') {
                $line .= ' — ' . $detail;
            }
            $this->out($line . "\n");
        };

        // PHP version — 8.0 is the floor; 7.x runs but is unsupported.
        if (version_compare(PHP_VERSION, '8.0', '>=')) {
            $check('ok', 'PHP version', PHP_VERSION);
        } elseif (version_compare(PHP_VERSION, '7.4', '>=')) {
            $check('warn', 'PHP version', PHP_VERSION . ' — past end of life; target is PHP 8.0+');
        } else {
            $check('fail', 'PHP version', PHP_VERSION . ' — too old, PHP 8.0+ required');
        }

        // Required extensions.
        foreach (['mysqli', 'curl', 'mbstring', 'fileinfo', 'zip', 'json', 'openssl', 'ctype'] as $ext) {
            extension_loaded($ext)
                ? $check('ok', 'Extension ' . $ext, 'installed')
                : $check('fail', 'Extension ' . $ext, 'missing');
        }

        // Image processing — Imagick preferred, GD acceptable.
        if (extension_loaded('imagick') || extension_loaded('gd')) {
            $check('ok', 'Image processing', extension_loaded('imagick') ? 'imagick' : 'gd');
        } else {
            $check('fail', 'Image processing', 'neither imagick nor gd is available');
        }

        // Database connectivity.
        try {
            $check('ok', 'Database', 'connected, server ' . Connection::instance()->serverInfo());
        } catch (\Throwable $e) {
            $check('fail', 'Database', $e->getMessage());
        }

        // Base URL resolution — CLI cannot fall back to the Host header.
        defined('WEB_PATH') && WEB_PATH
            ? $check('ok', 'WEB_PATH', (string) WEB_PATH)
            : $check('fail', 'WEB_PATH', 'undefined — set it in config.php or the environment');

        // Uploads directory must be writable for image/attachment handling.
        $uploads = osc_uploads_path();
        @is_writable($uploads)
            ? $check('ok', 'Uploads writable', $uploads)
            : $check('fail', 'Uploads writable', $uploads . ' is not writable');

        // Cron freshness — the daily schedule should have run within ~25h.
        $cronLast = 0;
        foreach (['HOURLY', 'DAILY', 'WEEKLY'] as $type) {
            $row = Cron::newInstance()->getCronByType($type);
            if (is_array($row) && !empty($row['d_last_exec'])) {
                $cronLast = max($cronLast, (int) strtotime($row['d_last_exec']));
            }
        }
        if ($cronLast === 0) {
            $check('warn', 'Cron', 'no run recorded yet');
        } elseif ((time() - $cronLast) > 25 * 3600) {
            $check('warn', 'Cron', 'last run ' . date('Y-m-d H:i', $cronLast) . ' — not firing regularly?');
        } else {
            $check('ok', 'Cron', 'last run ' . date('Y-m-d H:i', $cronLast));
        }

        // Object cache backend.
        $driver = defined('OSC_CACHE') ? (string) OSC_CACHE : 'default';
        $driver === 'default'
            ? $check('warn', 'Object cache', 'per-request only (no persistent backend)')
            : $check('ok', 'Object cache', $driver);

        $this->out(sprintf("\nShopclass %s — %s\n", osc_version(), strtoupper($worst) === 'OK' ? 'healthy' : 'issues found'));

        return $worst === 'fail' ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdVersion(array $args): int
    {
        $this->out(osc_version() . "\n");

        return 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdHelp(array $args): int
    {
        $this->out("Shopclass CLI\n\n");
        $this->out("Usage: php oc-cli.php <command> [options]\n\n");
        $this->out("Commands:\n");
        foreach ($this->commands as $name => [, $summary]) {
            $this->out(sprintf("  %-20s %s\n", $name, $summary));
        }

        return 0;
    }

    private function out(string $text): void
    {
        fwrite(STDOUT, $text);
    }

    private function err(string $text): void
    {
        fwrite(STDERR, $text);
    }
}
