<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins the plugin path helpers for a plugin symlinked in from outside the plugins folder:
 * its real __FILE__ maps back through the link, so its hook names are the ones core fires.
 * Usage:  php tests/plugin-path-symlink.php
 */

$root = sys_get_temp_dir() . '/osc-plugin-link-' . getmypid() . '/';
define('ABS_PATH', $root);
define('PLUGINS_PATH', $root . 'oc-content/plugins/');
define('WEB_PATH', 'https://example.test/');

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/helpers/hDefines.php';
require_once __DIR__ . '/../oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/lib/harness.php';

mkdir(PLUGINS_PATH . 'plain', 0777, true);
mkdir($root . 'elsewhere/my-plugin/sub', 0777, true);
symlink($root . 'elsewhere/my-plugin', PLUGINS_PATH . 'linked');

harness_section('a plugin inside the plugins folder');

$plain = PLUGINS_PATH . 'plain/index.php';
pin('path', PLUGINS_PATH . 'plain/index.php', osc_plugin_path($plain));
pin('folder', 'plain/', osc_plugin_folder($plain));
pin('url', osc_base_url() . 'oc-content/plugins/plain/', osc_plugin_url($plain));

harness_section('a plugin symlinked in from outside');

$real = realpath($root . 'elsewhere/my-plugin') . '/index.php';
pin('its real path maps back through the link', PLUGINS_PATH . 'linked/index.php', osc_plugin_path($real));
pin('a file deeper in it too', 'linked/sub/', osc_plugin_folder(realpath($root . 'elsewhere/my-plugin/sub') . '/x.php'));
pin('and its url is under the plugins folder', osc_base_url() . 'oc-content/plugins/linked/', osc_plugin_url($real));

unlink(PLUGINS_PATH . 'linked');
exec('rm -rf ' . escapeshellarg($root));

exit(harness_result());
