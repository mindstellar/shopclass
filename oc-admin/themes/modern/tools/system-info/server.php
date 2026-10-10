<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// System info > Server, below the facts: every loaded extension, and how to change the settings.
$extLoaded = (array) ($env['extensions'] ?? array());
natcasesort($extLoaded);
$iniLoaded  = (string) ($env['ini_file'] ?? '');
$configPath = ABS_PATH . 'config.php';

osc_add_hook('admin_footer', static function () { ?>
    <script>
        (function () {
            var open = function () {
                var help = document.getElementById('server-help');
                if (help && location.hash === '#server-help') {
                    help.open = true;
                }
            };
            open();
            window.addEventListener('hashchange', open);
        })();
    </script>
<?php });
?>
    <details class="sysinfo-details">
        <summary><?php printf(__('All loaded PHP extensions (%d)'), count($extLoaded)); ?></summary>
        <div class="sysinfo-chips">
            <?php foreach ($extLoaded as $ext) { ?><span class="sysinfo-chip"><?php echo osc_esc_html((string) $ext); ?></span><?php } ?>
        </div>
    </details>

    <details class="sysinfo-details" id="server-help">
        <summary><?php _e('How to change these settings'); ?></summary>
        <div class="sysinfo-help">
            <p class="sysinfo-help-intro">
                <?php printf(
                    __('Two files hold these settings. PHP\'s own limits live in %1$s%2$s; Shopclass\'s own behaviour lives in %3$s in the site root%4$s. Edit the relevant one — a php.ini change needs PHP restarted (or your host\'s control panel, e.g. cPanel\'s “MultiPHP INI Editor”), while a config.php change takes effect on the next request.'),
                    '<code>php.ini</code>',
                    $iniLoaded ? ' (<code class="sysinfo-code-wrap">' . osc_esc_html($iniLoaded) . '</code>)' : '',
                    '<code>config.php</code>',
                    ' (<code class="sysinfo-code-wrap">' . osc_esc_html($configPath) . '</code>)'
                ); ?>
            </p>

            <div class="sysinfo-help-group">
                <div class="sysinfo-help-group-title"><?php _e('In php.ini'); ?> <small><?php _e('— PHP limits'); ?></small></div>

                <div class="sysinfo-help-item">
                    <div class="sysinfo-help-title"><?php _e('Allow bigger photo uploads'); ?></div>
                    <p><?php printf(__('Raise %1$s and %2$s together, keeping post at least as large as upload:'), '<code>upload_max_filesize</code>', '<code>post_max_size</code>'); ?></p>
                    <pre>upload_max_filesize = 16M
post_max_size = 20M</pre>
                </div>

                <div class="sysinfo-help-item">
                    <div class="sysinfo-help-title"><?php _e('Let a listing carry more photos'); ?></div>
                    <p><?php printf(__('Raise %s to at least the number of photos per listing you allow in Settings.'), '<code>max_file_uploads</code>'); ?></p>
                    <pre>max_file_uploads = 30</pre>
                </div>

                <div class="sysinfo-help-item">
                    <div class="sysinfo-help-title"><?php _e('Let long jobs finish'); ?></div>
                    <p><?php printf(__('Backups, imports and upgrades run inside %s. Raise it if they time out.'), '<code>max_execution_time</code>'); ?></p>
                    <pre>max_execution_time = 120</pre>
                </div>

                <div class="sysinfo-help-item">
                    <div class="sysinfo-help-title"><?php _e('Turn on OPcache'); ?></div>
                    <p><?php _e('The cheapest speed-up there is — PHP stops recompiling every file on every request.'); ?></p>
                    <pre>opcache.enable = 1</pre>
                </div>

                <div class="sysinfo-help-item">
                    <div class="sysinfo-help-title"><?php _e('Install a missing extension'); ?></div>
                    <p><?php printf(__('Install the OS package (for example %1$s or %2$s), make sure an %3$s line is present, and restart PHP. This page then lists it as installed.'), '<code>php-gd</code>', '<code>php-imagick</code>', '<code>extension=</code>'); ?></p>
                </div>
            </div>

            <div class="sysinfo-help-group">
                <div class="sysinfo-help-group-title"><?php _e('In config.php'); ?> <small><?php _e('— Shopclass'); ?></small></div>
                <p class="sysinfo-help-groupnote"><?php printf(__('Add these near the top of %1$s, above its closing %2$s.'), '<code>config.php</code>', '<code>?&gt;</code>'); ?></p>

                <div class="sysinfo-help-item">
                    <div class="sysinfo-help-title"><?php _e('Raise memory without touching php.ini'); ?></div>
                    <p><?php printf(__('When you cannot edit php.ini, Shopclass will lift PHP\'s memory limit up to %s on its own at start-up.'), '<code>OSC_MEMORY_LIMIT</code>'); ?></p>
                    <pre>define('OSC_MEMORY_LIMIT', '256M');</pre>
                </div>

                <div class="sysinfo-help-item">
                    <div class="sysinfo-help-title"><?php _e('Turn on a persistent object cache'); ?></div>
                    <p><?php printf(__('Point %1$s at an installed driver (for example %2$s, %3$s or %5$s) so category trees, user data and search stop being recomputed on every request; %4$s sets how long, in seconds, a value is kept.'), '<code>OSC_CACHE</code>', '<code>apcu</code>', '<code>memcached</code>', '<code>OSC_CACHE_TTL</code>', '<code>redis</code>'); ?></p>
                    <pre>define('OSC_CACHE', 'apcu');
define('OSC_CACHE_TTL', 300);</pre>
                </div>

                <div class="sysinfo-help-item">
                    <div class="sysinfo-help-title"><?php _e('Switch debugging on'); ?></div>
                    <p><?php printf(__('%1$s surfaces errors while you diagnose a problem; %2$s sends them to a log file instead of the page. Both belong off on a live site.'), '<code>OSC_DEBUG</code>', '<code>OSC_DEBUG_LOG</code>'); ?></p>
                    <pre>define('OSC_DEBUG', true);
define('OSC_DEBUG_LOG', true);</pre>
                </div>
            </div>
        </div>
    </details>
