<?php
/*
 * @author: Navjot Tomer
 *
 * OSClass – software for creating and publishing online classified advertising platforms
 *
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

use mindstellar\utility\SystemInfo;

if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}

// A settings value ("128M") is not the information — whether it is big enough is. So this
// page CHECKS rather than dumps: every row is a fact with a spelled-out verdict (never a
// colour alone), a note on why it matters, its value, and sometimes one way to act on it.

if (!function_exists('oscsi_bytes')) {
    /**
     * PHP ini shorthand ("128M") to a byte count. -1 (unlimited) stays -1.
     *
     * @param string $value
     *
     * @return int
     */
    function oscsi_bytes($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        if ((int)$value === -1) {
            return -1;
        }
        $unit   = strtolower(substr($value, -1));
        $number = (int)$value;
        switch ($unit) {
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
}

if (!function_exists('oscsi_size')) {
    /**
     * A human size for a byte count. -1 is unlimited.
     *
     * @param int $bytes
     *
     * @return string
     */
    function oscsi_size($bytes)
    {
        if ($bytes === -1) {
            return __('unlimited');
        }
        if (!is_numeric($bytes) || $bytes <= 0) {
            return '—';
        }
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $i     = (int)min(floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
    }
}

if (!function_exists('oscsi_ago')) {
    /**
     * A compact "how long ago" for a UNIX timestamp: "3 min", "5 hours", "2 days".
     *
     * @param int $seconds elapsed seconds
     *
     * @return string
     */
    function oscsi_ago($seconds)
    {
        $seconds = (int)$seconds;
        if ($seconds < 60) {
            return __('just now');
        }
        if ($seconds < 3600) {
            $n = (int)floor($seconds / 60);

            return sprintf(_n('%d minute', '%d minutes', $n), $n);
        }
        if ($seconds < 86400) {
            $n = (int)floor($seconds / 3600);

            return sprintf(_n('%d hour', '%d hours', $n), $n);
        }
        $n = (int)floor($seconds / 86400);

        return sprintf(_n('%d day', '%d days', $n), $n);
    }
}

if (!function_exists('oscsi_row')) {
    /**
     * One ledger row: a verdict word, the fact, a note (may hold <code>/<strong>), a value,
     * and at most one action.
     *
     * @param string $state  ok | warn | danger | off
     * @param string $word   the verdict, spelled out
     * @param string $name
     * @param string $note   developer-authored copy; callers escape any interpolated values
     * @param string $value
     * @param array  $action array{label:string, url:string} or empty
     */
    function oscsi_row($state, $word, $name, $note = '', $value = '', $action = array())
    {
        ?>
        <div class="sysinfo-row">
            <span class="sysinfo-state sysinfo-state-<?php echo osc_esc_html($state); ?>"><?php echo osc_esc_html($word); ?></span>
            <div class="sysinfo-fact">
                <div class="sysinfo-name"><?php echo osc_esc_html($name); ?></div>
                <?php if ($note !== '') { ?>
                    <p class="sysinfo-note"><?php echo $note; ?></p>
                <?php } ?>
            </div>
            <div class="sysinfo-trailing">
                <?php if ($value !== '') { ?>
                    <span class="sysinfo-value"><?php echo osc_esc_html($value); ?></span>
                <?php } ?>
                <?php if (!empty($action)) { ?>
                    <a class="btn btn-sm btn-dim sysinfo-action" href="<?php echo osc_esc_html($action['url']); ?>"><?php echo osc_esc_html($action['label']); ?></a>
                <?php } ?>
            </div>
        </div>
        <?php
    }
}

function addHelp()
{
    echo '<p>' . __('A checked view of the environment Osclass is running on: what it needs, what it can and cannot do, and where a quiet misconfiguration would bite.') . '</p>';
}


osc_add_hook('help_box', 'addHelp');
function customPageHeader()
{
    ?>
    <h1>
        <?php _e('System Info'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}


osc_add_hook('admin_page_header', 'customPageHeader');

function customPageTitle($string)
{
    return sprintf(__('System info &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');
osc_current_admin_theme_path('parts/header.php');

$infoType     = Params::getParam('info-type');
$overviewUrl  = osc_admin_base_url(true) . '?' . http_build_query(array('page' => 'tools', 'action' => 'system-info'));
$phpInfoUrl   = osc_admin_base_url(true) . '?' . http_build_query(array('page' => 'tools', 'action' => 'system-info', 'info-type' => 'php-info'));
$settingsUrl  = osc_admin_base_url(true) . '?page=settings';
?>
    <div id="system-info">
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link<?php echo $infoType === 'php-info' ? '' : ' active'; ?>" href="<?php echo osc_esc_html($overviewUrl); ?>"><?php _e('Overview'); ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link<?php echo $infoType === 'php-info' ? ' active' : ''; ?>" href="<?php echo osc_esc_html($phpInfoUrl); ?>"><?php _e('PHP Info'); ?></a>
            </li>
        </ul>
        <?php
        if ($infoType === 'php-info') {
            print((new SystemInfo())->getPHPInfoAllToStr());
        } else {
            $OK      = __('OK');
            $CHECK   = __('Check');
            $PROBLEM = __('Problem');
            $OFF     = __('Off');
            $INFO    = __('Info');

            // ---- Gather. Every read is defensive: this is the page you open when something
            // is already wrong, so a failed read becomes a value, never a fatal.
            $php80 = version_compare(PHP_VERSION, '8.0', '>=');
            $php74 = version_compare(PHP_VERSION, '7.4', '>=');

            $memory   = oscsi_bytes(ini_get('memory_limit'));
            $upload   = oscsi_bytes(ini_get('upload_max_filesize'));
            $post     = oscsi_bytes(ini_get('post_max_size'));
            $maxFiles = (int)ini_get('max_file_uploads');
            $maxExec  = (int)ini_get('max_execution_time');
            $photos   = (int)osc_max_images_per_item();

            $uploadsPath     = osc_uploads_path();
            $uploadsWritable = @is_writable($uploadsPath);
            $freeDisk        = function_exists('disk_free_space') ? @disk_free_space($uploadsPath) : false;

            $debug          = defined('OSC_DEBUG') && OSC_DEBUG;
            $maintenance    = file_exists(ABS_PATH . '.maintenance');
            $configPath     = ABS_PATH . 'config.php';
            $configWritable = @is_writable($configPath);
            $allowUrlFopen  = (bool)ini_get('allow_url_fopen');
            $opcache        = function_exists('opcache_get_status') && ini_get('opcache.enable');

            $cacheDriver    = defined('OSC_CACHE') ? (string)OSC_CACHE : 'default';
            $cacheClass     = 'Object_Cache_' . $cacheDriver;
            $cacheSupported = $cacheDriver === 'default'
                || (class_exists($cacheClass) && call_user_func(array($cacheClass, 'is_supported')));

            // Latest cron run across all schedules — the signal for "is cron.php actually firing".
            $cronLast = 0;
            try {
                foreach ((array)Cron::newInstance()->listAll() as $c) {
                    $t = isset($c['d_last_exec']) ? strtotime($c['d_last_exec']) : 0;
                    if ($t > $cronLast) {
                        $cronLast = $t;
                    }
                }
            } catch (Throwable $e) {
                $cronLast = 0;
            }
            $cronAge = $cronLast > 0 ? (time() - $cronLast) : -1;

            $dbServer = '';
            try {
                $dbServer = DBConnectionClass::newInstance()->getOsclassDb()->get_server_info();
            } catch (Throwable $e) {
                $dbServer = __('unavailable');
            }
            $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? __('unknown');
            ?>

            <div class="card mb-3">
                <div class="card-header"><h6 class="card-title mb-0"><?php _e('Requirements'); ?></h6></div>
                <div class="sysinfo-ledger">
                    <?php
                    if ($php80) {
                        oscsi_row('ok', $OK, __('PHP version'), __('Supported.'), PHP_VERSION);
                    } elseif ($php74) {
                        oscsi_row('warn', $CHECK, __('PHP version'), __('Osclass runs, but PHP 7.x is past end of life and no longer gets security fixes. Osclass targets PHP 8.0 and up — move to PHP 8.'), PHP_VERSION);
                    } else {
                        oscsi_row('danger', $PROBLEM, __('PHP version'), __('Too old. Osclass is built and tested against PHP 8.0 and up.'), PHP_VERSION);
                    }

                    $extensions = array(
                        array('ext' => 'mysqli', 'name' => __('mysqli'), 'note' => __('How Osclass talks to the database — the site cannot run without it.')),
                        array('ext' => 'gd', 'name' => __('GD (image processing)'), 'note' => __('Resizes and crops every uploaded photo. Without it, image uploads fail.')),
                        array('ext' => 'curl', 'name' => __('cURL'), 'note' => __('Fetches update checks and talks to the market and remote services. Without it, those downloads fail.')),
                        array('ext' => 'mbstring', 'name' => __('mbstring'), 'note' => __('Handles non-Latin text throughout the site.')),
                        array('ext' => 'fileinfo', 'name' => __('fileinfo'), 'note' => __('Detects the true type of an uploaded file. Without it, upload validation is weaker.')),
                        array('ext' => 'zip', 'name' => __('zip'), 'note' => __('Installs and updates plugins and themes, and builds backups.')),
                        array('ext' => 'json', 'name' => __('JSON'), 'note' => __('Every ajax response, and every stored preference.')),
                        array('ext' => 'openssl', 'name' => __('OpenSSL'), 'note' => __('Encrypts outgoing mail over TLS and secures tokens.')),
                        array('ext' => 'ctype', 'name' => __('ctype'), 'note' => __('Input validation across the core.')),
                    );
                    foreach ($extensions as $extension) {
                        $loaded = extension_loaded($extension['ext']);
                        oscsi_row(
                            $loaded ? 'ok' : 'danger',
                            $loaded ? $OK : $PROBLEM,
                            $extension['name'],
                            $loaded ? '' : $extension['note'],
                            $loaded ? __('installed') : __('missing')
                        );
                    }

                    oscsi_row(
                        $opcache ? 'ok' : 'warn',
                        $opcache ? $OK : $CHECK,
                        __('OPcache'),
                        $opcache ? '' : __('Not enabled. PHP recompiles every file on every request. It is the cheapest performance win available, and it is one ini line.'),
                        $opcache ? __('enabled') : __('off')
                    );
                    ?>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h6 class="card-title mb-0"><?php _e('Uploads &amp; storage'); ?></h6></div>
                <div class="sysinfo-ledger">
                    <?php
                    // The one check that decides whether photos work at all.
                    oscsi_row(
                        $uploadsWritable ? 'ok' : 'danger',
                        $uploadsWritable ? $OK : $PROBLEM,
                        __('Uploads folder is writable'),
                        $uploadsWritable
                            ? sprintf(__('Osclass can write to %s.'), '<code class="sysinfo-code-wrap">' . osc_esc_html($uploadsPath) . '</code>')
                            : sprintf(__('Osclass cannot write to %s, so no photo or file upload can be saved. Fix the folder permissions so the web server user can write to it.'), '<code class="sysinfo-code-wrap">' . osc_esc_html($uploadsPath) . '</code>'),
                        $uploadsWritable ? __('writable') : __('read-only')
                    );

                    // PHP rejects a request whose body exceeds post_max_size BEFORE it ever looks
                    // at upload_max_filesize — so if post is the smaller of the two, the larger is
                    // a lie and the upload fails with an empty $_FILES and no useful error.
                    if ($post > 0 && $upload > 0 && $post < $upload) {
                        oscsi_row(
                            'danger', $PROBLEM,
                            __('post_max_size is smaller than upload_max_filesize'),
                            sprintf(
                                __('PHP rejects the whole request before it looks at the file, so the %1$s you advertise is unreachable — an upload over %2$s fails with an empty form. Raise %3$s above %4$s.'),
                                '<code>upload_max_filesize</code>',
                                '<strong>' . osc_esc_html(oscsi_size($post)) . '</strong>',
                                '<code>post_max_size</code>',
                                '<code>upload_max_filesize</code>'
                            ),
                            oscsi_size($post) . ' < ' . oscsi_size($upload)
                        );
                    } else {
                        oscsi_row(
                            'off', $INFO,
                            __('Largest single upload'),
                            sprintf(__('%1$s per file, %2$s per request.'), '<code>upload_max_filesize</code>', '<code>post_max_size</code>'),
                            oscsi_size($upload) . ' / ' . oscsi_size($post)
                        );
                    }

                    // A listing takes several photos at once; if max_file_uploads is below the
                    // number the site offers, PHP silently truncates $_FILES and the extras vanish.
                    if ($photos > 0 && $maxFiles > 0 && $maxFiles < $photos) {
                        oscsi_row(
                            'danger', $PROBLEM,
                            __('Fewer uploads allowed than photos offered'),
                            sprintf(
                                __('Osclass lets a seller attach %1$s photos to a listing, but PHP accepts only %2$s files per request and drops the rest. Raise %3$s to at least %4$s.'),
                                '<strong>' . (int)$photos . '</strong>',
                                '<strong>' . (int)$maxFiles . '</strong>',
                                '<code>max_file_uploads</code>',
                                '<strong>' . (int)$photos . '</strong>'
                            ),
                            $maxFiles . ' < ' . $photos,
                            array('label' => __('Settings'), 'url' => $settingsUrl)
                        );
                    } elseif ($photos > 0 && $maxFiles > 0) {
                        oscsi_row(
                            'ok', $OK,
                            __('Photos per listing'),
                            sprintf(__('Osclass offers %1$s photos per listing; PHP accepts %2$s files per request.'), '<strong>' . (int)$photos . '</strong>', '<strong>' . (int)$maxFiles . '</strong>'),
                            $photos . ' / ' . $maxFiles
                        );
                    }

                    $memOk = ($memory === -1 || $memory >= 128 * 1024 * 1024);
                    oscsi_row(
                        $memOk ? 'ok' : 'warn',
                        $memOk ? $OK : $CHECK,
                        __('Memory limit'),
                        $memOk ? '' : __('Under 128M. Resizing a large photo is the most memory-hungry thing Osclass does, and this is where it fails first.'),
                        oscsi_size($memory)
                    );

                    $diskLow = is_numeric($freeDisk) && $freeDisk > 0 && $freeDisk < 512 * 1024 * 1024;
                    oscsi_row(
                        $diskLow ? 'warn' : 'off',
                        $diskLow ? $CHECK : $INFO,
                        __('Free disk space'),
                        $diskLow
                            ? __('Under 512MB left on the uploads volume. When it fills, uploads and backups start failing.')
                            : __('Space left on the uploads volume.'),
                        oscsi_size($freeDisk)
                    );

                    oscsi_row(
                        'off', $INFO,
                        __('Max execution time'),
                        $maxExec === 0 ? __('No limit — whatever runs long, runs.') : __('Long tasks such as backups and imports must finish inside this.'),
                        $maxExec === 0 ? __('unlimited') : $maxExec . 's'
                    );
                    ?>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h6 class="card-title mb-0"><?php _e('Osclass health'); ?></h6></div>
                <div class="sysinfo-ledger">
                    <?php
                    // Cron drives update checks, alert emails and cleanup. If it never runs, all of
                    // that silently stops — and the only trace is here.
                    if ($cronLast <= 0) {
                        oscsi_row(
                            'warn', $CHECK,
                            __('Scheduled tasks (cron)'),
                            sprintf(__('No cron run has been recorded. Update checks, alert emails and cleanup depend on it. Schedule %s to run regularly.'), '<code>oc-includes/cron.php</code>'),
                            __('never run')
                        );
                    } elseif ($cronAge > 90000) { // ~25h
                        oscsi_row(
                            'warn', $CHECK,
                            __('Scheduled tasks (cron)'),
                            sprintf(__('The last cron run was %s ago — longer than a day, so it is probably no longer scheduled. Update checks, alerts and cleanup have stopped until it runs again.'), '<strong>' . osc_esc_html(oscsi_ago($cronAge)) . '</strong>'),
                            sprintf(__('%s ago'), oscsi_ago($cronAge))
                        );
                    } else {
                        oscsi_row(
                            'ok', $OK,
                            __('Scheduled tasks (cron)'),
                            __('Cron has run recently — update checks, alerts and cleanup are firing.'),
                            sprintf(__('%s ago'), oscsi_ago($cronAge))
                        );
                    }

                    // Persistent object cache. Without one every request recomputes category trees,
                    // user data and search from scratch.
                    if ($cacheDriver === 'default') {
                        oscsi_row(
                            'off', $INFO,
                            __('Object cache'),
                            sprintf(__('No persistent cache configured, so per-request work is recomputed each time. Set %1$s in config.php to a driver such as %2$s or %3$s for a real speed-up on a busy site.'), '<code>OSC_CACHE</code>', '<code>apcu</code>', '<code>memcached</code>'),
                            __('per-request')
                        );
                    } elseif ($cacheSupported) {
                        oscsi_row(
                            'ok', $OK,
                            __('Object cache'),
                            sprintf(__('Caching through the %s driver.'), '<code>' . osc_esc_html($cacheDriver) . '</code>'),
                            osc_esc_html($cacheDriver)
                        );
                    } else {
                        oscsi_row(
                            'danger', $PROBLEM,
                            __('Object cache'),
                            sprintf(__('config.php sets %1$s, but that driver is not available — so the cache silently falls back to per-request and every page recomputes its data. Install the matching extension, or remove the setting.'), '<code>OSC_CACHE = ' . osc_esc_html($cacheDriver) . '</code>'),
                            __('unsupported')
                        );
                    }

                    // Debug on a live site leaks internals into pages and logs and slows everything.
                    oscsi_row(
                        $debug ? 'warn' : 'ok',
                        $debug ? $CHECK : $OK,
                        __('Debug mode'),
                        $debug ? sprintf(__('%s is on. That is for development — on a live site it exposes internal detail and slows requests. Turn it off in config.php.'), '<code>OSC_DEBUG</code>') : __('Off, as it should be on a live site.'),
                        $debug ? __('on') : __('off')
                    );

                    // config.php holds the database credentials; a web-writable copy is a needless
                    // exposure once the install is done.
                    oscsi_row(
                        $configWritable ? 'warn' : 'ok',
                        $configWritable ? $CHECK : $OK,
                        __('config.php permissions'),
                        $configWritable
                            ? __('config.php is writable by the web server. It holds your database credentials and does not need to be writable after install — make it read-only.')
                            : __('Read-only, as it should be after install.'),
                        $configWritable ? __('writable') : __('read-only')
                    );

                    if ($maintenance) {
                        oscsi_row(
                            'warn', $CHECK,
                            __('Maintenance mode'),
                            sprintf(__('The site is in maintenance mode — visitors see the maintenance page. Remove the %s file to bring it back.'), '<code>.maintenance</code>'),
                            __('on')
                        );
                    }

                    oscsi_row(
                        $allowUrlFopen ? 'ok' : 'off',
                        $allowUrlFopen ? $OK : $INFO,
                        __('allow_url_fopen'),
                        $allowUrlFopen ? '' : __('Off. Some remote fetches fall back to cURL; update and market features rely on one of the two being available.'),
                        $allowUrlFopen ? __('on') : __('off')
                    );
                    ?>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h6 class="card-title mb-0"><?php _e('Environment'); ?></h6></div>
                <div class="card-body p-0">
                    <table class="sysinfo-table">
                        <tbody>
                        <?php
                        $envRows = array(
                            array(__('Osclass version'), OSCLASS_VERSION),
                            array(__('PHP version'), PHP_VERSION),
                            array(__('Database server'), $dbServer),
                            array(__('Web server'), $serverSoftware),
                            array(__('Operating system'), PHP_OS . ' (' . php_uname('m') . ')'),
                        );
                        foreach ($envRows as $row) { ?>
                            <tr>
                                <td class="sysinfo-td-name"><?php echo osc_esc_html($row[0]); ?></td>
                                <td class="sysinfo-td-value"><?php echo osc_esc_html($row[1]); ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h6 class="card-title mb-0"><?php _e('Paths'); ?></h6></div>
                <div class="card-body p-0">
                    <table class="sysinfo-table">
                        <tbody>
                        <?php
                        $prefAll  = Preference::newInstance()->listAll();
                        $prefSize = round(mb_strlen(serialize($prefAll), '8bit') / 1024, 1) . ' KB';
                        $pathRows = array(
                            array(__('Website URL'), osc_base_url()),
                            array(__('Content path'), osc_content_path()),
                            array(__('Uploads path'), osc_uploads_path()),
                            array(__('Plugins path'), osc_plugins_path()),
                            array(__('Themes path'), osc_themes_path()),
                            array(__('Preferences'), sprintf(__('%1$s entries, %2$s'), count($prefAll), $prefSize)),
                        );
                        foreach ($pathRows as $row) { ?>
                            <tr>
                                <td class="sysinfo-td-name"><?php echo osc_esc_html($row[0]); ?></td>
                                <td class="sysinfo-td-value sysinfo-td-mono"><?php echo osc_esc_html($row[1]); ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php
        }
        unset($infoType);
        ?>
    </div>
<?php
osc_current_admin_theme_path('parts/footer.php');
