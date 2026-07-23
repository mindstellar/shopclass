<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Scratch-database lifecycle and raw fixture seeding for the model tests.
 *
 * Two modes:
 *  - standalone: `php tests/models/<model>.php` creates its own database,
 *    imports struct.sql, and drops the database on exit.
 *  - runner: tests/run-models.php defines MODELS_RUNNER, imports struct.sql
 *    ONCE into a shared database, and truncates between files. The import is
 *    the expensive step, so doing it once keeps a full run in the tens of
 *    seconds rather than repeating it per model.
 *
 * Every seed helper writes with RAW mysqli on the admin connection — never
 * through the code under test, which is the whole point of a characterization
 * fixture. Foreign-key checks are off on the ADMIN session only, so seeds need
 * no particular ordering; the singleton connection the models use keeps FK
 * enforcement intact.
 *
 * Env: DRIFT_DB_HOST / DRIFT_DB_PORT / DRIFT_DB_USER / DRIFT_DB_PASS
 * Defaults target the throwaway container on 127.0.0.1:33061 (root/root), NOT
 * the seeded dev harness on 3307.
 */

if (!function_exists('scratchdb_bootstrap')) {
    /**
     * Define the constants the DB classes need, load the autoloader and the
     * database helpers, and open the raw admin connection. Idempotent.
     *
     * @param string $scratch Database name to target
     *
     * @return mysqli Admin connection (no database selected yet)
     */
    function scratchdb_bootstrap(string $scratch): mysqli
    {
        static $admin = null;
        if ($admin instanceof mysqli) {
            return $admin;
        }

        error_reporting(E_ALL & ~E_DEPRECATED);
        ini_set('error_log', tempnam(sys_get_temp_dir(), 'models_')); // swallow error_log noise

        if (!defined('ABS_PATH')) {
            define('ABS_PATH', dirname(__DIR__, 2) . '/');
        }
        if (!defined('LIB_PATH')) {
            define('LIB_PATH', ABS_PATH . 'oc-includes/');
        }
        if (!defined('OSCLASS_VERSION')) {
            define('OSCLASS_VERSION', '5.3.0.dev');
        }
        if (!defined('OSC_DEBUG_DB')) {
            define('OSC_DEBUG_DB', false);
        }
        if (!defined('OSC_DEBUG_DB_EXPLAIN')) {
            define('OSC_DEBUG_DB_EXPLAIN', false);
        }
        if (!defined('OSC_DEBUG_DB_LOG')) {
            define('OSC_DEBUG_DB_LOG', false);
        }
        if (!defined('DB_TABLE_PREFIX')) {
            define('DB_TABLE_PREFIX', 'oc_');
        }

        $host = getenv('DRIFT_DB_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('DRIFT_DB_PORT') ?: 33061);
        $user = getenv('DRIFT_DB_USER') ?: 'root';
        $pass = getenv('DRIFT_DB_PASS');
        if ($pass === false) {
            $pass = 'root'; // container default; DRIFT_DB_PASS='' still means empty
        }

        // DBConnectionClass builds its mysqli() without a port argument, so the
        // default TCP port has to point at the container.
        ini_set('mysqli.default_port', (string)$port);

        if (!defined('DB_HOST')) {
            define('DB_HOST', $host);
        }
        if (!defined('DB_USER')) {
            define('DB_USER', $user);
        }
        if (!defined('DB_PASSWORD')) {
            define('DB_PASSWORD', $pass);
        }
        if (!defined('DB_NAME')) {
            define('DB_NAME', $scratch);
        }

        require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
        // Helpers are loaded by oc-load.php at runtime, not by composer, so a
        // standalone script has to require them explicitly.
        require_once ABS_PATH . 'oc-includes/osclass/helpers/hDatabase.php';

        // Anything reaching Params::getServerParam()/getParam() lands in purify(),
        // which builds an HTMLPurifier whose constructor wants a cache directory
        // from osc_uploads_path(). hDefines.php declares that helper WITHOUT a
        // function_exists guard, so this stand-in belongs here and nowhere else:
        // a second definition in a test file would fatal the moment any test
        // loads the real helper file. A test that needs genuine hDefines.php
        // behaviour must require it instead of relying on this.
        if (!defined('UPLOADS_PATH')) {
            define('UPLOADS_PATH', sys_get_temp_dir() . '/');
        }
        if (!function_exists('osc_uploads_path')) {
            function osc_uploads_path()
            {
                return UPLOADS_PATH;
            }
        }

        mysqli_report(MYSQLI_REPORT_OFF); // admin errors are handled by hand
        $admin = new mysqli($host, $user, $pass, '', $port);
        if ($admin->connect_errno) {
            fwrite(STDERR, 'admin connect failed: ' . $admin->connect_error . "\n");
            fwrite(STDERR, "Is the throwaway container up?\n");
            fwrite(STDERR, "  docker run -d --name shopclass-scratch -p 33061:3306 "
                . "-e MYSQL_ROOT_PASSWORD=root mysql:8.0\n");
            exit(2);
        }

        return $admin;
    }
}

if (!function_exists('scratchdb_create')) {
    /**
     * Drop and recreate $scratch, then import struct.sql into it.
     *
     * @param mysqli $admin
     * @param string $scratch
     */
    function scratchdb_create(mysqli $admin, string $scratch): void
    {
        $admin->query("DROP DATABASE IF EXISTS `$scratch`");
        if (!$admin->query("CREATE DATABASE `$scratch` DEFAULT CHARACTER SET utf8mb4")) {
            fwrite(STDERR, 'cannot create scratch db: ' . $admin->error . "\n");
            exit(2);
        }
        $admin->select_db($scratch);

        $struct = file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/struct.sql');
        $struct = str_replace(
            array('/*TABLE_PREFIX*/', '/*OSCLASS_VERSION*/'),
            array(DB_TABLE_PREFIX, OSCLASS_VERSION),
            $struct
        );
        $struct = preg_replace('#/\*(?:[^*]*(?:\*(?!/))*)*\*/#', '', $struct); // strip block comments

        foreach (explode(';', $struct) as $q) {
            $q = trim($q);
            if ($q !== '' && !$admin->query($q)) {
                fwrite(STDERR, "schema import failed: {$admin->error}\n  on: " . substr($q, 0, 120) . "\n");
                exit(2);
            }
        }

        $admin->query('SET FOREIGN_KEY_CHECKS = 0');
    }
}

if (!function_exists('scratchdb_truncate_all')) {
    /**
     * Empty every table, restoring AUTO_INCREMENT counters, so the next test
     * file starts from the same state a fresh import would give it.
     *
     * @param mysqli $admin
     */
    function scratchdb_truncate_all(mysqli $admin): void
    {
        $admin->query('SET FOREIGN_KEY_CHECKS = 0');
        $res = $admin->query('SHOW TABLES');
        if (!$res) {
            return;
        }
        while ($row = $res->fetch_array(MYSQLI_NUM)) {
            $admin->query('TRUNCATE TABLE `' . $row[0] . '`');
        }
        $res->free();
    }
}

if (!function_exists('scratchdb_drop')) {
    /**
     * Drop the scratch database and close the admin connection.
     *
     * @param mysqli $admin
     * @param string $scratch
     */
    function scratchdb_drop(mysqli $admin, string $scratch): void
    {
        $admin->query("DROP DATABASE IF EXISTS `$scratch`");
        $admin->close();
    }
}

if (!function_exists('scratchdb_session')) {
    /**
     * Entry point for a model test file.
     *
     * Under the runner the shared database is already imported, so this only
     * clears it. Standalone it creates and imports $scratch, and registers a
     * shutdown hook to drop it.
     *
     * @param string $scratch Database name to use when running standalone
     *
     * @return mysqli Admin connection with the database selected
     */
    function scratchdb_session(string $scratch): mysqli
    {
        if (defined('MODELS_RUNNER')) {
            $admin = scratchdb_bootstrap(MODELS_RUNNER_DB);
            $admin->select_db(MODELS_RUNNER_DB);
            scratchdb_truncate_all($admin);

            return $admin;
        }

        $admin = scratchdb_bootstrap($scratch);
        scratchdb_create($admin, $scratch);
        register_shutdown_function(static function () use ($admin, $scratch) {
            $admin->query("DROP DATABASE IF EXISTS `$scratch`");
        });

        return $admin;
    }
}

/* ----------------------------------------------------------------------------
 * Raw fixture seeding. Each helper returns the id (or code) it inserted so a
 * test can build a graph without re-querying.
 * ------------------------------------------------------------------------- */

if (!function_exists('seed_exec')) {
    /**
     * Run a prepared write on the admin connection and return the insert id.
     *
     * @param mysqli $admin
     * @param string $sql
     * @param string $types mysqli bind_param type string
     * @param array  $vals
     *
     * @return int
     */
    function seed_exec(mysqli $admin, string $sql, string $types, array $vals): int
    {
        $stmt = $admin->prepare($sql);
        if (!$stmt) {
            fwrite(STDERR, "seed prepare failed: {$admin->error}\n  on: $sql\n");
            exit(2);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$vals);
        }
        if (!$stmt->execute()) {
            fwrite(STDERR, "seed execute failed: {$stmt->error}\n  on: $sql\n");
            exit(2);
        }
        $id = $admin->insert_id;
        $stmt->close();

        return $id;
    }
}

if (!function_exists('seed_locale')) {
    /**
     * @return string The locale code
     */
    function seed_locale(mysqli $admin, string $code = 'en_US', string $name = 'English'): string
    {
        seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_locale
             (pk_c_code, s_name, s_short_name, s_description, s_version, s_author_name,
              s_author_url, s_currency_format, s_date_format, b_enabled, b_enabled_bo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)',
            'sssssssss',
            array($code, $name, $name, $name, '1.0', 'Mindstellar', 'https://example.test', '$ ', 'd/m/Y')
        );

        return $code;
    }
}

if (!function_exists('seed_country')) {
    /**
     * @return string The country code
     */
    function seed_country(mysqli $admin, string $code = 'US', string $name = 'United States'): string
    {
        seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_country (pk_c_code, s_name, s_slug) VALUES (?, ?, ?)',
            'sss',
            array($code, $name, strtolower($code))
        );

        return $code;
    }
}

if (!function_exists('seed_currency')) {
    /**
     * @return string The currency code
     */
    function seed_currency(mysqli $admin, string $code = 'USD', string $name = 'US Dollar'): string
    {
        seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_currency
             (pk_c_code, s_name, s_description, b_enabled) VALUES (?, ?, ?, 1)',
            'sss',
            array($code, $name, $name)
        );

        return $code;
    }
}

if (!function_exists('seed_region')) {
    function seed_region(
        mysqli $admin,
        string $country = 'US',
        string $name = 'Alpha',
        int $active = 1
    ): int {
        return seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_region
             (fk_c_country_code, s_name, s_slug, b_active) VALUES (?, ?, ?, ?)',
            'sssi',
            array($country, $name, strtolower($name), $active)
        );
    }
}

if (!function_exists('seed_city')) {
    function seed_city(
        mysqli $admin,
        int $regionId,
        string $name = 'Springfield',
        string $country = 'US',
        int $active = 1
    ): int {
        return seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_city
             (fk_i_region_id, s_name, s_slug, fk_c_country_code, b_active) VALUES (?, ?, ?, ?, ?)',
            'isssi',
            array($regionId, $name, strtolower($name), $country, $active)
        );
    }
}

if (!function_exists('seed_category')) {
    /**
     * Insert a category and its description row.
     *
     * @return int The category id
     */
    function seed_category(
        mysqli $admin,
        string $name = 'Motors',
        ?int $parentId = null,
        string $locale = 'en_US',
        int $enabled = 1,
        int $expirationDays = 0
    ): int {
        $id = seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_category
             (fk_i_parent_id, i_expiration_days, i_position, b_enabled, b_price_enabled)
             VALUES (?, ?, 0, ?, 1)',
            'iii',
            array($parentId, $expirationDays, $enabled)
        );

        seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_category_description
             (fk_i_category_id, fk_c_locale_code, s_name, s_description, s_slug)
             VALUES (?, ?, ?, ?, ?)',
            'issss',
            array($id, $locale, $name, $name . ' description', strtolower($name))
        );

        return $id;
    }
}

if (!function_exists('seed_user')) {
    function seed_user(
        mysqli $admin,
        string $username = 'tester',
        string $email = 'tester@example.test',
        int $active = 1,
        int $enabled = 1
    ): int {
        return seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_user
             (dt_reg_date, s_name, s_username, s_password, s_email, b_enabled, b_active,
              d_coord_lat, d_coord_long)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?)',
            'ssssiidd',
            array($username, $username, str_repeat('x', 60), $email, $enabled, $active, 40.712800, -74.006000)
        );
    }
}

if (!function_exists('seed_item')) {
    /**
     * Insert an item with its description, location and stats rows.
     *
     * f_price is a FLOAT and i_price a BIGINT, which is what makes this fixture
     * useful for the typed-row parity pins.
     *
     * @return int The item id
     */
    function seed_item(
        mysqli $admin,
        int $categoryId,
        ?int $userId = null,
        string $title = 'A listing',
        float $price = 19.50,
        int $active = 1,
        int $enabled = 1,
        string $locale = 'en_US',
        string $country = 'US'
    ): int {
        $id = seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_item
             (fk_i_user_id, fk_i_category_id, dt_pub_date, dt_mod_date, f_price, i_price,
              fk_c_currency_code, s_contact_name, s_contact_email, s_ip, b_premium,
              b_enabled, b_active, b_spam, s_secret, dt_expiration)
             VALUES (?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, 0, ?, ?, 0, ?,
                     DATE_ADD(NOW(), INTERVAL 30 DAY))',
            'iidissssiis',
            array(
                $userId,
                $categoryId,
                $price,
                (int)round($price * 1000000),
                'USD',
                'Contact',
                'contact@example.test',
                '127.0.0.1',
                $enabled,
                $active,
                'secret' . $categoryId,
            )
        );

        seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_item_description
             (fk_i_item_id, fk_c_locale_code, s_title, s_description) VALUES (?, ?, ?, ?)',
            'isss',
            array($id, $locale, $title, $title . ' body text')
        );

        seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_item_location
             (fk_i_item_id, fk_c_country_code, s_country) VALUES (?, ?, ?)',
            'iss',
            array($id, $country, 'United States')
        );

        seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_item_stats (fk_i_item_id, dt_date) VALUES (?, CURDATE())',
            'i',
            array($id)
        );

        return $id;
    }
}

if (!function_exists('seed_page')) {
    /**
     * Insert a static page and its description row.
     *
     * @return int The page id
     */
    function seed_page(
        mysqli $admin,
        string $internalName = 'about',
        string $title = 'About us',
        int $link = 1,
        int $indelible = 0,
        string $locale = 'en_US'
    ): int {
        $id = seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_pages
             (s_internal_name, b_indelible, b_link, dt_pub_date, dt_mod_date, i_order)
             VALUES (?, ?, ?, NOW(), NOW(), 0)',
            'sii',
            array($internalName, $indelible, $link)
        );

        seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_pages_description
             (fk_i_pages_id, fk_c_locale_code, s_title, s_text) VALUES (?, ?, ?, ?)',
            'isss',
            array($id, $locale, $title, '<p>' . $title . '</p>')
        );

        return $id;
    }
}

if (!function_exists('seed_widget')) {
    function seed_widget(
        mysqli $admin,
        string $location = 'header',
        string $description = 'A widget',
        string $content = '<p>hi</p>',
        int $order = 0
    ): int {
        return seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_widget
             (s_description, s_location, e_kind, s_content, i_order) VALUES (?, ?, ?, ?, ?)',
            'ssssi',
            array($description, $location, 'HTML', $content, $order)
        );
    }
}

if (!function_exists('seed_cron')) {
    /**
     * t_cron has no primary key, so the same e_type can legitimately appear more
     * than once; this helper does not deduplicate.
     *
     * @return int Always 0 — the table has no AUTO_INCREMENT column
     */
    function seed_cron(
        mysqli $admin,
        string $type = 'HOURLY',
        string $lastExec = '2026-01-01 00:00:00',
        string $nextExec = '2026-01-01 01:00:00'
    ): int {
        return seed_exec(
            $admin,
            'INSERT INTO ' . DB_TABLE_PREFIX . 't_cron (e_type, d_last_exec, d_next_exec) VALUES (?, ?, ?)',
            'sss',
            array($type, $lastExec, $nextExec)
        );
    }
}

/* file end: ./tests/lib/scratchdb.php */
