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
use mindstellar\market\Catalog;
use mindstellar\market\Compatibility;
use mindstellar\market\Installer;
use mindstellar\market\PackageIndex;
use mindstellar\market\PackageReconciler;
use Params;
use Plugins;
use Sitemap;
use WebThemes;

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
        'install'             => ['cmdInstall', 'Headless install from env/flags (--unattended)'],
        'cron'                => ['cmdCron', 'Run due scheduled tasks (--type=hourly|daily|weekly|all)'],
        'db:upgrade'          => ['cmdDbUpgrade', 'Run pending migrations, repairing a drifted schema first (--skip-db, --skip-reconcile)'],
        'package:reconcile'   => ['cmdPackageReconcile', 'Install/refresh bundled plugins & themes onto a persistent oc-content (no-op outside a container image)'],
        'cache:flush'         => ['cmdCacheFlush', 'Flush the object cache'],
        'sitemap:warm'        => ['cmdSitemapWarm', 'Pre-generate the XML sitemap into the cache'],
        'user:create-admin'   => ['cmdUserCreateAdmin', 'Create an admin (--user= --email= [--password=] [--name=])'],
        'user:reset-password' => ['cmdUserResetPassword', 'Reset an admin password (--user=|--email= [--password=])'],
        'plugin:list'         => ['cmdPluginList', 'List plugins and their status'],
        'plugin:activate'     => ['cmdPluginActivate', 'Enable an installed plugin (--plugin=<folder>)'],
        'plugin:deactivate'   => ['cmdPluginDeactivate', 'Disable an active plugin (--plugin=<folder>)'],
        'theme:list'          => ['cmdThemeList', 'List installed public themes'],
        'theme:activate'      => ['cmdThemeActivate', 'Set the active public theme (--theme=<name>)'],
        'market:refresh'      => ['cmdMarketRefresh', 'Refresh the package catalog (--type=plugin|theme)'],
        'market:search'       => ['cmdMarketSearch', 'Search the catalog (<query> [--type=plugin|theme])'],
        'market:info'         => ['cmdMarketInfo', 'Show catalog details for a package (<slug> [--type=plugin|theme])'],
        'market:install'      => ['cmdMarketInstall', 'Install a package from the catalog (<slug> [--type=plugin|theme])'],
        'market:update'       => ['cmdMarketUpdate', 'Update installed packages from the catalog (<slug>|--all [--type=plugin|theme])'],
        'location:status'     => ['cmdLocationStatus', 'Show installed location data against the published catalog'],
        'location:update'     => ['cmdLocationUpdate', 'Install or update country locations (--country=IN|--all) [--dry-run]'],
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
     * Headless install driven by the environment (or flags, which win). Reachable
     * before the app is installed because oc-cli.php loads the installer bootstrap
     * for this verb instead of oc-load.php. Idempotent: a no-op once installed.
     *
     * @param array<string, mixed> $args
     */
    private function cmdInstall(array $args): int
    {
        if (!array_key_exists('unattended', $args)) {
            $this->err(
                "Usage: install --unattended [options]\n"
                . "  Settings come from the environment; flags cover a no-config install and\n"
                . "  override env for site/admin. DB_*/WEB_PATH from env or config.php are authoritative.\n"
                . "  Database:  DB_NAME DB_USER [DB_PASSWORD] [DB_HOST=localhost] [DB_PORT] [DB_TABLE_PREFIX=oc_]\n"
                . "             --db-name= --db-user= --db-password= --db-host= --db-port= --db-prefix= [--create-db]\n"
                . "  Site:      WEB_PATH (e.g. https://example.com/)  [OSC_SITE_TITLE] [OSC_LOCALE=en_US]\n"
                . "             --web-url= --site-title= --locale=\n"
                . "  Admin:     OSC_ADMIN_EMAIL [OSC_ADMIN_USER=admin] [OSC_ADMIN_PASSWORD] [OSC_ADMIN_NAME]\n"
                . "             --admin-email= --admin-user= --admin-password= --admin-name=\n"
            );

            return 2;
        }

        // Idempotency: never re-run against a live install (struct.sql is not
        // re-runnable). Safe pre-config — returns false when nothing is configured.
        if (is_osclass_installed()) {
            $this->out("Shopclass is already installed; nothing to do.\n");

            return 0;
        }

        $env = static function (string $key): ?string {
            $value = getenv($key);

            return ($value !== false && $value !== '') ? $value : null;
        };
        $opt = static function (string $flag, ?string $envValue, ?string $default = null) use ($args) {
            if (array_key_exists($flag, $args) && is_string($args[$flag]) && $args[$flag] !== '') {
                return $args[$flag];
            }

            return $envValue ?? $default;
        };

        // DB settings and WEB_PATH: config-loader locks these into immutable
        // constants at bootstrap when the environment (or a config.php) provides
        // them, and oc_install() installs over those constants — so when defined,
        // mirror them (a flag can't override an already-defined constant, and the
        // probe must target the same database the install writes to). Otherwise
        // resolve from flags/env for a no-config install.
        if (defined('DB_NAME')) {
            $dbName   = DB_NAME;
            $dbHost   = defined('DB_HOST') ? DB_HOST : 'localhost';
            $dbUser   = defined('DB_USER') ? DB_USER : '';
            $dbPass   = defined('DB_PASSWORD') ? DB_PASSWORD : '';
            $dbPrefix = defined('DB_TABLE_PREFIX') ? DB_TABLE_PREFIX : 'oc_';
            if (defined('DB_PORT') && (string) DB_PORT !== '' && strpos((string) $dbHost, ':') === false) {
                $dbHost .= ':' . DB_PORT;
            }
        } else {
            $dbName   = $opt('db-name', $env('DB_NAME'));
            $dbHost   = $opt('db-host', $env('DB_HOST'), 'localhost');
            $dbUser   = $opt('db-user', $env('DB_USER'));
            $dbPass   = $opt('db-password', $env('DB_PASSWORD'), '');
            $dbPrefix = $opt('db-prefix', $env('DB_TABLE_PREFIX'), 'oc_');
            $dbPort   = $opt('db-port', $env('DB_PORT'));
            if ($dbPort !== null && strpos((string) $dbHost, ':') === false) {
                $dbHost .= ':' . $dbPort;
            }
        }
        $webUrl     = defined('WEB_PATH') ? WEB_PATH : $opt('web-url', $env('WEB_PATH'));
        $siteTitle  = $opt('site-title', $env('OSC_SITE_TITLE'), 'Shopclass');
        $locale     = $opt('locale', $env('OSC_LOCALE'), 'en_US');
        $adminUser  = $opt('admin-user', $env('OSC_ADMIN_USER'), 'admin');
        $adminEmail = $opt('admin-email', $env('OSC_ADMIN_EMAIL'));
        $adminName  = $opt('admin-name', $env('OSC_ADMIN_NAME'), 'Administrator');
        $createDb   = array_key_exists('create-db', $args);

        $missing = [];
        foreach (['DB_NAME/--db-name' => $dbName, 'DB_USER/--db-user' => $dbUser,
                     'WEB_PATH/--web-url' => $webUrl, 'OSC_ADMIN_EMAIL/--admin-email' => $adminEmail] as $label => $value) {
            if ($value === null || $value === '') {
                $missing[] = $label;
            }
        }
        if ($missing) {
            $this->err('Missing required settings: ' . implode(', ', $missing) . "\n");

            return 2;
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $this->err("Invalid admin email address.\n");

            return 2;
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $dbPrefix)) {
            $this->err("Invalid table prefix (letters, numbers and underscore only).\n");

            return 2;
        }

        // Lock in WEB_PATH/REL_WEB_URL before oc_install() reaches
        // define_install_constants(), whose HTTP-derived fallback can't run under CLI.
        $webUrl = rtrim((string) $webUrl, '/') . '/';
        if (!defined('WEB_PATH')) {
            define('WEB_PATH', $webUrl);
        }
        if (!defined('REL_WEB_URL')) {
            $path = parse_url($webUrl, PHP_URL_PATH);
            define('REL_WEB_URL', ($path === null || $path === '') ? '/' : $path);
        }

        // Locale for the seeded site language and admin, mirroring the GUI installer.
        \Session::newInstance()->_set('userLocale', $locale);
        \Session::newInstance()->_set('adminLocale', $locale);

        // Feed oc_install() the settings it reads from the request in the GUI.
        Params::setParam('dbhost', $dbHost);
        Params::setParam('dbname', $dbName);
        Params::setParam('username', $dbUser);
        Params::setParam('password', $dbPass);
        Params::setParam('tableprefix', $dbPrefix);
        if ($createDb) {
            Params::setParam('createdb', '1');
            Params::setParam('admin_username', $dbUser);
            Params::setParam('admin_password', $dbPass);
        }

        $this->out(sprintf("Installing Shopclass at %s\n", WEB_PATH));

        // Schema + seed data + baseline migrations. Returns false on success, an
        // {error[, field]} array on failure.
        $result = oc_install();
        if (is_array($result)) {
            $message = isset($result['error']) ? strip_tags((string) $result['error']) : 'installation failed';
            $this->err('Install failed: ' . $message . "\n");

            return 1;
        }

        // Admin account, mirroring the installer's own insert (osc_db_table, no
        // credential email). s_secret/b_moderator keep their column defaults.
        $adminPwArgs = [];
        if (array_key_exists('admin-password', $args) && is_string($args['admin-password']) && $args['admin-password'] !== '') {
            $adminPwArgs['password'] = $args['admin-password'];
        } elseif (($envAdminPw = $env('OSC_ADMIN_PASSWORD')) !== null) {
            $adminPwArgs['password'] = $envAdminPw;
        }
        [$adminPassword, $generated] = $this->resolvePassword($adminPwArgs);

        try {
            osc_db_table(DB_TABLE_PREFIX . 't_admin')->insert([
                's_name'     => $adminName,
                's_username' => $adminUser,
                's_password' => osc_hash_password($adminPassword),
                's_email'    => $adminEmail,
            ]);

            // Site identity, matching the GUI installer's basic_info().
            $prefTable = DB_TABLE_PREFIX . 't_preference';
            $replace   = "REPLACE INTO $prefTable (s_name, s_value, s_section, e_type) VALUES (?, ?, ?, ?)";
            osc_db_execute($replace, ['pageTitle', $siteTitle, 'osclass', 'STRING']);
            osc_db_execute($replace, ['contactEmail', $adminEmail, 'osclass', 'STRING']);
        } catch (\Throwable $e) {
            $this->err('Schema installed, but creating the admin account failed: ' . $e->getMessage() . "\n");

            return 1;
        }

        // Finalize: writes the osclass_installed sentinel LAST.
        finish_installation($adminPassword);

        $this->out("Shopclass installed successfully.\n");
        $this->out(sprintf("  Admin URL: %soc-admin/\n", WEB_PATH));
        $this->out(sprintf("  Username:  %s\n", $adminUser));
        if ($generated) {
            $this->out(sprintf("  Password:  %s\n", $adminPassword));
        }

        return 0;
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
        $skipReconcile = array_key_exists('skip-reconcile', $args);

        $result  = \mindstellar\upgrade\Osclass::upgradeDB(
            array_key_exists('skip-db', $args),
            $skipReconcile
        );
        $decoded = json_decode((string) $result, true);
        $error   = is_array($decoded) ? (int) ($decoded['error'] ?? 1) : 1;
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? $result) : (string) $result;
        $repairs = is_array($decoded) && isset($decoded['repairs']) ? (array) $decoded['repairs'] : [];

        // upgradeDB() builds messages for the admin screen, so strip the markup
        // and collapse whitespace for a terminal.
        $message = trim(preg_replace('/\s+/', ' ', strip_tags($message)));

        if ($error === 0) {
            // The repair pass is expected to find nothing: the migrations build the
            // schema and a release cannot ship unless they reproduce it on their own.
            // So anything here describes an install that had drifted by some other
            // route, and saying so is more use than applying it quietly.
            if ($repairs !== []) {
                $this->out(sprintf("Repaired %d schema difference(s):\n", count($repairs)));
                foreach ($repairs as $query) {
                    $this->out('  ' . trim(preg_replace('/\s+/', ' ', (string) $query)) . "\n");
                }
            } elseif (!$skipReconcile) {
                $this->out("Schema already matched — nothing to repair.\n");
            }

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
     * Container-only step: OSC_BUNDLED_CONTENT_PATH points at a pristine copy of
     * oc-content baked into the image, outside the persistent volume; when it
     * exists, install bundled plugins/themes missing from the live oc-content
     * and refresh ones this image ships a newer version of (PackageReconciler).
     * A no-op on every install that isn't running from that image layout.
     *
     * @param array<string, mixed> $args
     */
    private function cmdPackageReconcile(array $args): int
    {
        $pristineRoot = (string) (getenv('OSC_BUNDLED_CONTENT_PATH') ?: '');
        if ($pristineRoot === '' || !is_dir($pristineRoot)) {
            $this->out("No bundled content path configured for this install; nothing to reconcile.\n");

            return 0;
        }

        $actions = PackageReconciler::reconcile($pristineRoot, PLUGINS_PATH, THEMES_PATH);
        if ($actions === []) {
            $this->out("Bundled plugins/themes already up to date.\n");

            return 0;
        }
        foreach ($actions as $line) {
            $this->out($line . "\n");
        }

        return 0;
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
     * Normalise a plugin reference to the `folder/index.php` form the Plugins
     * registry stores, so the CLI can accept the bare folder name.
     */
    private function normalisePluginPath(string $plugin): string
    {
        $plugin = trim($plugin, "/ \t\n\r\0\x0B");

        return str_ends_with($plugin, '.php') ? $plugin : $plugin . '/index.php';
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdPluginList(array $args): int
    {
        $plugins = Plugins::listAll();
        if ($plugins === array()) {
            $this->out("No plugins found.\n");

            return 0;
        }

        $this->out(sprintf("  %-8s %-30s %-10s %s\n", 'STATUS', 'PLUGIN', 'VERSION', 'FOLDER'));
        foreach ($plugins as $path) {
            $info   = Plugins::getInfo($path);
            $status = Plugins::isEnabled($path) ? 'enabled'
                : (Plugins::isInstalled($path) ? 'disabled' : '-');
            $folder = str_replace('/index.php', '', $path);
            $this->out(sprintf(
                "  %-8s %-30s %-10s %s\n",
                $status,
                $info['plugin_name'],
                $info['version'] !== '' ? $info['version'] : '?',
                $folder
            ));
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdPluginActivate(array $args): int
    {
        $plugin = trim((string) ($args['plugin'] ?? ''));
        if ($plugin === '') {
            $this->err("Usage: plugin:activate --plugin=<folder>\n");

            return 2;
        }

        $path = $this->normalisePluginPath($plugin);
        if (!Plugins::isInstalled($path)) {
            $this->err(sprintf("Plugin '%s' is not installed. Install it from the admin first.\n", $plugin));

            return 1;
        }
        if (Plugins::isEnabled($path)) {
            $this->err(sprintf("Plugin '%s' is already enabled.\n", $plugin));

            return 1;
        }

        if (!Plugins::activate($path)) {
            $this->err(sprintf("Could not enable plugin '%s'.\n", $plugin));

            return 1;
        }

        $this->out(sprintf("Plugin '%s' enabled.\n", $plugin));

        return 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdPluginDeactivate(array $args): int
    {
        $plugin = trim((string) ($args['plugin'] ?? ''));
        if ($plugin === '') {
            $this->err("Usage: plugin:deactivate --plugin=<folder>\n");

            return 2;
        }

        $path = $this->normalisePluginPath($plugin);
        if (!Plugins::isEnabled($path)) {
            $this->err(sprintf("Plugin '%s' is not enabled.\n", $plugin));

            return 1;
        }

        if (!Plugins::deactivate($path)) {
            $this->err(sprintf("Could not disable plugin '%s'.\n", $plugin));

            return 1;
        }

        $this->out(sprintf("Plugin '%s' disabled.\n", $plugin));

        return 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdThemeList(array $args): int
    {
        $themes  = WebThemes::newInstance()->getListThemes();
        $current = osc_theme();
        if ($themes === array()) {
            $this->out("No themes found.\n");

            return 0;
        }

        foreach ($themes as $theme) {
            $this->out(sprintf("  %s %s\n", $theme === $current ? '*' : ' ', $theme));
        }
        $this->out("\n* = active theme\n");

        return 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdThemeActivate(array $args): int
    {
        $theme = trim((string) ($args['theme'] ?? ''));
        if ($theme === '') {
            $this->err("Usage: theme:activate --theme=<name>\n");

            return 2;
        }

        if (!in_array($theme, WebThemes::newInstance()->getListThemes(), true)) {
            $this->err(sprintf("Theme '%s' is not installed. Run theme:list to see available themes.\n", $theme));

            return 1;
        }
        if ($theme === osc_theme()) {
            $this->out(sprintf("Theme '%s' is already active.\n", $theme));

            return 0;
        }

        osc_set_preference('theme', $theme);
        // Mirror the admin activation hook so plugins/themes can react.
        osc_run_hook('theme_activate', $theme);

        $this->out(sprintf("Theme '%s' activated.\n", $theme));

        return 0;
    }

    /**
     * Resolve --type into the plugin/theme catalog namespace, erroring on
     * anything else since those are the only two registries.
     *
     * @param array<string, mixed> $args
     */
    private function marketType(array $args): ?string
    {
        $type = (string) ($args['type'] ?? 'plugin');
        if (!in_array($type, ['plugin', 'theme'], true)) {
            $this->err("Invalid --type. Use plugin or theme.\n");

            return null;
        }

        return $type;
    }

    /**
     * Refuses state-changing market operations under DEMO or when package
     * installs are disabled for this deployment.
     */
    private function marketWriteGuard(): int
    {
        if (defined('DEMO')) {
            $this->err("Disabled in demo mode.\n");

            return 1;
        }
        if (osc_package_installs_disabled()) {
            $this->err("Package installs are disabled for this install (OSC_DISABLE_PACKAGE_INSTALLS).\n");

            return 1;
        }

        return 0;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return 'unknown size';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $value, $units[$i]);
    }

    /**
     * Prints slug, target version, size, and source host before an install/update
     * actually touches disk, per the market's "say what you're about to do" contract.
     *
     * @param array<string, mixed> $target a versionEntry: version, url, size, ...
     */
    private function printInstallPlan(string $slug, array $target, bool $isUpdate = false): void
    {
        $host = parse_url((string) ($target['url'] ?? ''), PHP_URL_HOST);
        $this->out(sprintf(
            "%s %s -> %s  (%s, from %s)\n",
            $isUpdate ? 'Updating' : 'Installing',
            $slug,
            (string) ($target['version'] ?? '?'),
            $this->formatBytes((int) ($target['size'] ?? 0)),
            $host !== null && $host !== '' ? $host : 'unknown host'
        ));
    }

    /**
     * @param array<string, mixed> $result Installer::install()/update() return shape
     */
    private function reportInstallerResult(array $result): int
    {
        if (!empty($result['ok'])) {
            $message = (string) ($result['message'] ?? '');
            $this->out(sprintf(
                "  OK: %s %s installed.%s\n",
                (string) ($result['slug'] ?? ''),
                (string) ($result['version'] ?? ''),
                $message !== '' ? ' ' . $message : ''
            ));

            return 0;
        }

        $message = (string) ($result['message'] ?? 'unknown error');
        $this->err(sprintf(
            "  FAILED: %s%s\n",
            $message,
            !empty($result['rolled_back']) ? ' (rolled back)' : ''
        ));

        return 1;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdMarketRefresh(array $args): int
    {
        $type = $this->marketType($args);
        if ($type === null) {
            return 2;
        }

        $catalog = $type === 'plugin' ? Catalog::forPlugins() : Catalog::forThemes();

        $this->out(sprintf("Refreshing %s catalog...\n", $type));
        $index   = $catalog->index(true);
        $updates = $catalog->updates(true);

        // The catalog writes its check timestamp/error straight to the DB without
        // updating Preference's in-process cache; reload it so lastChecked()/lastError()
        // reflect the fetch that just happened instead of whatever was cached at boot.
        osc_reset_preferences();

        if ($catalog->lastError() !== null) {
            $this->err('Refresh failed: ' . $catalog->lastError() . "\n");

            return 1;
        }

        $this->out(sprintf(
            "  %d package(s) in the index, %d with published versions. Last checked: %s\n",
            count($index),
            count($updates),
            date('Y-m-d H:i:s', $catalog->lastChecked())
        ));

        return 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdMarketSearch(array $args): int
    {
        $type = $this->marketType($args);
        if ($type === null) {
            return 2;
        }

        $query = trim((string) ($args['_'][0] ?? ''));
        if ($query === '') {
            $this->err("Usage: market:search <query> [--type=plugin|theme]\n");

            return 2;
        }

        $catalog = $type === 'plugin' ? Catalog::forPlugins() : Catalog::forThemes();
        $needle  = mb_strtolower($query);

        $matches = array_filter($catalog->index(), static function (array $entry) use ($needle): bool {
            $haystack = mb_strtolower(implode(' ', [
                (string) ($entry['slug'] ?? ''),
                (string) ($entry['name'] ?? ''),
                (string) ($entry['short_description'] ?? ''),
                implode(' ', (array) ($entry['tags'] ?? [])),
            ]));

            return str_contains($haystack, $needle);
        });

        if ($matches === []) {
            $this->out("No matches.\n");

            return 0;
        }

        $this->out(sprintf("  %-24s %-10s %s\n", 'SLUG', 'VERSION', 'DESCRIPTION'));
        foreach ($matches as $entry) {
            $this->out(sprintf(
                "  %-24s %-10s %s\n",
                (string) ($entry['slug'] ?? ''),
                (string) ($entry['version'] ?? ($entry['latest_version'] ?? '?')),
                (string) ($entry['short_description'] ?? '')
            ));
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdMarketInfo(array $args): int
    {
        $type = $this->marketType($args);
        if ($type === null) {
            return 2;
        }

        $slug = trim((string) ($args['_'][0] ?? ''));
        if ($slug === '') {
            $this->err("Usage: market:info <slug> [--type=plugin|theme]\n");

            return 2;
        }

        $catalog = $type === 'plugin' ? Catalog::forPlugins() : Catalog::forThemes();
        $detail  = $catalog->detail($slug);
        if ($detail === null) {
            $this->err(sprintf("No such %s in the catalog: %s\n", $type, $slug));

            return 1;
        }

        $this->out(sprintf("%s (%s)\n", (string) ($detail['name'] ?? $slug), $slug));
        if (!empty($detail['short_description'])) {
            $this->out('  ' . $detail['short_description'] . "\n");
        }
        if (!empty($detail['author'])) {
            $this->out('  Author: ' . $detail['author'] . "\n");
        }

        $versions = $catalog->updates()[$slug] ?? ($detail['versions'] ?? []);
        $best     = Compatibility::pickBestVersion($versions);
        if ($best !== null) {
            $this->out(sprintf(
                "  Best compatible version: %s (requires Shopclass %s, PHP %s)\n",
                (string) $best['version'],
                (string) ($best['requires'] ?? 'any'),
                (string) ($best['requires_php'] ?? 'any')
            ));
        } else {
            $this->out("  No published version is compatible with this install.\n");
        }
        $this->out(sprintf("  %d version(s) published.\n", count($versions)));

        return 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdMarketInstall(array $args): int
    {
        $type = $this->marketType($args);
        if ($type === null) {
            return 2;
        }

        $slug = trim((string) ($args['_'][0] ?? ''));
        if ($slug === '') {
            $this->err("Usage: market:install <slug> [--type=plugin|theme]\n");

            return 2;
        }

        $guard = $this->marketWriteGuard();
        if ($guard !== 0) {
            return $guard;
        }

        $catalog  = $type === 'plugin' ? Catalog::forPlugins() : Catalog::forThemes();
        $versions = $catalog->updates()[$slug] ?? null;
        if ($versions === null) {
            $this->err(sprintf("No such %s in the catalog: %s\n", $type, $slug));

            return 1;
        }

        $target = Compatibility::pickBestVersion($versions);
        if ($target === null) {
            $this->err(sprintf("No version of '%s' is compatible with this Shopclass/PHP install.\n", $slug));

            return 1;
        }

        $this->printInstallPlan($slug, $target);

        $installer = $type === 'plugin' ? Installer::forPlugins() : Installer::forThemes();

        return $this->reportInstallerResult($installer->install($slug, $target));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function cmdMarketUpdate(array $args): int
    {
        $type = $this->marketType($args);
        if ($type === null) {
            return 2;
        }

        $all  = array_key_exists('all', $args);
        $slug = trim((string) ($args['_'][0] ?? ''));
        if (!$all && $slug === '') {
            $this->err("Usage: market:update <slug>|--all [--type=plugin|theme]\n");

            return 2;
        }

        $guard = $this->marketWriteGuard();
        if ($guard !== 0) {
            return $guard;
        }

        $index   = $type === 'plugin' ? PackageIndex::forPlugins() : PackageIndex::forThemes();
        $pending = $index->pendingUpdates();

        $slugs = $all ? array_keys($pending) : [$slug];
        if ($slugs === []) {
            $this->out("Nothing to update.\n");

            return 0;
        }

        $installer = $type === 'plugin' ? Installer::forPlugins() : Installer::forThemes();
        $worst     = 0;
        foreach ($slugs as $s) {
            $target = $pending[$s] ?? null;
            if ($target === null) {
                $this->err(sprintf("'%s' has no pending update.\n", $s));
                $worst = max($worst, 1);
                continue;
            }

            $this->printInstallPlan($s, $target, true);
            $worst = max($worst, $this->reportInstallerResult($installer->update($s, $target)));
        }

        return $worst;
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

    private function cmdLocationStatus(array $args): int
    {
        $catalog = new \mindstellar\location\LocationCatalog();
        $rows    = $catalog->status(array_key_exists('refresh', $args));
        if ($rows === array()) {
            $this->err("Could not read the location catalog.\n");

            return 1;
        }

        $installed = array_values(array_filter($rows, static function ($row) {
            return $row['installed'];
        }));

        $this->out(sprintf("%d countries in the catalog, %d installed here.\n\n", count($rows), count($installed)));
        if ($installed === array()) {
            $this->out("Install one with: location:update --country=<ISO2>\n");

            return 0;
        }

        $stale = 0;
        foreach ($installed as $row) {
            $state = $row['current'] ? 'current' : 'update available';
            $stale += $row['current'] ? 0 : 1;
            $this->out(sprintf("  %-4s %-34s %-16s %7d cities\n", $row['code'], $row['name'], $state, $row['rows']));
        }
        if ($stale > 0) {
            $this->out(sprintf(
                "\n%d with an update available. Preview one with:"
                . "\n  location:update --country=<ISO2> --dry-run\n",
                $stale
            ));
        }

        return 0;
    }

    private function cmdLocationUpdate(array $args): int
    {
        $all     = array_key_exists('all', $args);
        $country = isset($args['country']) && is_string($args['country']) ? strtoupper($args['country']) : '';
        if (!$all && $country === '') {
            $this->err("Usage: location:update (--country=IN | --all) [--dry-run]\n");

            return 2;
        }

        $dryRun  = array_key_exists('dry-run', $args);
        $catalog = new \mindstellar\location\LocationCatalog();
        $rows    = $catalog->status();
        if ($rows === array()) {
            $this->err("Could not read the location catalog.\n");

            return 1;
        }

        // --all means every country already installed here, never all 250 in the catalog:
        // this command updates what a site has, it does not decide what it should have.
        $targets = array();
        foreach ($rows as $row) {
            if ($all ? $row['installed'] : $row['code'] === $country) {
                $targets[] = $row;
            }
        }
        if ($targets === array()) {
            $this->err($all
                ? "No countries are installed yet.\n"
                : sprintf("The catalog has no country '%s'.\n", $country));

            return 1;
        }

        if ($dryRun) {
            $this->out("Dry run: every change is computed and then rolled back.\n\n");
        }

        $failed = 0;
        foreach ($targets as $row) {
            $this->out(sprintf("%s (%s)\n", $row['name'], $row['code']));
            $data = $catalog->countryFile($row['file']);
            if ($data === null) {
                $this->err(sprintf("  could not download %s\n", $row['file']));
                $failed++;
                continue;
            }

            $report = (new \mindstellar\location\LocationImporter($dryRun))->import($data);
            if (isset($report['error'])) {
                $this->err(sprintf("  %s\n", $report['error']));
                $failed++;
                continue;
            }

            $this->out('  regions  ' . $this->locationCounts($report['regions']) . "\n");
            $this->out('  cities   ' . $this->locationCounts($report['cities']) . "\n");
            foreach (array_slice($report['renames'], 0, 10) as $rename) {
                $this->out(sprintf("  renamed  %-6s %s -> %s\n", $rename['type'], $rename['from'], $rename['to']));
            }
            if (count($report['renames']) > 10) {
                $this->out(sprintf("  ... and %d more renames\n", count($report['renames']) - 10));
            }
            if ($report['regions']['kept_stale'] > 0 || $report['cities']['kept_stale'] > 0) {
                $this->out(sprintf(
                    "  kept live though dropped upstream (they hold listings): %d region(s), %d cities\n",
                    $report['regions']['kept_stale'],
                    $report['cities']['kept_stale']
                ));
            }

            if (!$dryRun && $row['sha'] !== '') {
                $catalog->markInstalled($row['code'], $row['sha']);
            }
        }

        if ($dryRun) {
            $this->out("\nNothing was written. Re-run without --dry-run to apply.\n");
        }

        return $failed > 0 ? 1 : 0;
    }

    /**
     * @param array<string, int> $counts
     */
    private function locationCounts(array $counts): string
    {
        $parts = array();
        foreach ($counts as $label => $value) {
            if ($value > 0) {
                $parts[] = $value . ' ' . str_replace('_', ' ', $label);
            }
        }

        return $parts === array() ? 'no changes' : implode(', ', $parts);
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
