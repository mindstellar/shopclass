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
 * Pins `plugin:install`: it installs and enables a plugin from its folder name, and a missing,
 * installed, throwing or noisy plugin gives exit code 1 with the reason.
 *
 * DB-free.  Usage:  php tests/cli-plugin-install.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSCLASS_VERSION', '9.9.9');

$base = sys_get_temp_dir() . '/osc-cli-plugin-install-' . getmypid() . '/';
foreach (array('good', 'throws', 'noisy', 'toonew', 'lowreq') as $dir) {
    @mkdir($base . $dir, 0777, true);
}
define('PLUGINS_PATH', $base);
$themes = sys_get_temp_dir() . '/osc-cli-themes-' . getmypid() . '/';
@mkdir($themes . 'oldtheme', 0777, true);
@mkdir($themes . 'toonewtheme', 0777, true);

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/cli/Cli.php';

$GLOBALS['prefs'] = array('active_plugins' => serialize(array()), 'installed_plugins' => serialize(array()));

function osc_active_plugins()
{
    return $GLOBALS['prefs']['active_plugins'];
}

function osc_installed_plugins()
{
    return $GLOBALS['prefs']['installed_plugins'];
}

function osc_set_preference($name, $value)
{
    $GLOBALS['prefs'][$name] = $value;
}

function osc_reset_preferences()
{
}

function osc_themes_path()
{
    return $GLOBALS['themes'];
}

function osc_theme()
{
    return 'oldtheme';
}

function osc_plugins_path()
{
    return PLUGINS_PATH;
}

file_put_contents($base . 'good/index.php', '<?php');
file_put_contents(
    $base . 'throws/index.php',
    '<?php Plugins::addHook("install_throws/index.php", function () { throw new Error("needs ext-foo"); });'
);
file_put_contents($base . 'toonew/index.php', "<?php\n/*\nPlugin Name: Too New\nRequires Shopclass: 99.0.0\n*/\n");
file_put_contents($base . 'lowreq/index.php', "<?php\n/*\nPlugin Name: Low Req\nRequires Shopclass: 6.0.0\nRequires PHP: 8.0\n*/\n");
file_put_contents($themes . 'oldtheme/index.php', "<?php\n/*\nTheme Name: Old\n*/\n");
file_put_contents($themes . 'toonewtheme/index.php', "<?php\n/*\nTheme Name: Too New\nRequires Shopclass: 99.0.0\n*/\n");
file_put_contents($base . 'noisy/index.php', '<?php echo "hello";');

/** Keeps what the CLI writes to STDOUT and STDERR, which an output buffer does not see. */
final class Capture extends php_user_filter
{
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $GLOBALS['__captured'] .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }
}
stream_filter_register('capture', Capture::class);

/** Forget the installed list Plugins cached, after a test edits the preference. */
function forget_installed(): void
{
    $prop = new ReflectionProperty('Plugins', 'installed');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

/** Run the CLI and hand back its exit code and what it printed. */
function cli(array $argv): array
{
    $GLOBALS['__captured'] = '';
    $filters = array(stream_filter_append(STDOUT, 'capture'), stream_filter_append(STDERR, 'capture'));
    $code    = \mindstellar\cli\Cli::run($argv);
    array_map('stream_filter_remove', $filters);

    return array($code, $GLOBALS['__captured']);
}

harness_section('a plugin in place');
[$code, $out] = cli(array('plugin:install', '--plugin=good'));
pin('exit code 0', 0, $code);
pin('says so', true, str_contains($out, "Plugin 'good' installed and enabled."));
pin('it is installed', array('good/index.php'), unserialize($GLOBALS['prefs']['installed_plugins']));
pin('and enabled', array('good/index.php'), unserialize($GLOBALS['prefs']['active_plugins']));

harness_section('what fails');
[$code, $out] = cli(array('plugin:install', '--plugin=good'));
pin('a second install: exit code 1', 1, $code);
pin('says it is installed', true, str_contains($out, 'already installed'));

[$code, $out] = cli(array('plugin:install', '--plugin=missing'));
pin('a missing folder: exit code 1', 1, $code);
pin('says it was not found', true, str_contains($out, 'was not found'));

[$code, $out] = cli(array('plugin:install', '--plugin=throws'));
pin('a throwing install hook: exit code 1', 1, $code);
pin('gives its message', true, str_contains($out, 'needs ext-foo'));

[$code, $out] = cli(array('plugin:install', '--plugin=noisy'));
pin('output during install: exit code 1', 1, $code);
pin('shows the output', true, str_contains($out, 'hello'));

[$code, $out] = cli(array('plugin:install', '--plugin=toonew'));
pin('a plugin that needs a newer Shopclass: exit code 1', 1, $code);
pin('says what it needs', true, str_contains($out, 'Requires Shopclass 99.0.0 or newer (you have 9.9.9)'));
pin('and it is not installed or enabled', false, in_array('toonew/index.php', unserialize($GLOBALS['prefs']['installed_plugins']), true) || in_array('toonew/index.php', unserialize($GLOBALS['prefs']['active_plugins']), true));
pin('enabling it directly is refused too', true, \Plugins::incompatibility('toonew/index.php') !== null);
[$code] = cli(array('plugin:install', '--plugin=lowreq'));
pin('a plugin whose Requires lines are met installs', 0, $code);
$GLOBALS['prefs']['installed_plugins'] = serialize(array('toonew/index.php'));
forget_installed();
[$code, $out] = cli(array('plugin:activate', '--plugin=toonew'));
pin('activate is refused', array(1, false), array($code, in_array('toonew/index.php', unserialize($GLOBALS['prefs']['active_plugins']), true)));
pin('...with the reason', true, str_contains($out, 'Requires Shopclass 99.0.0'));
$GLOBALS['prefs']['installed_plugins'] = serialize(array('good/index.php', 'noisy/index.php', 'lowreq/index.php'));
forget_installed();
[$code, $out] = cli(array('theme:activate', '--theme=toonewtheme'));
pin('theme:activate is refused', array(1, true, null), array($code, str_contains($out, 'Requires Shopclass 99.0.0'), $GLOBALS['prefs']['theme'] ?? null));

[$code] = cli(array('plugin:install'));
pin('no --plugin: exit code 2', 2, $code);

[$code] = cli(array('plugin:install', '--plugin=../../evil/index.php'));
pin('a path outside the plugins folder: exit code 2', 2, $code);
pin('and nothing else is installed', array('good/index.php', 'noisy/index.php', 'lowreq/index.php'), unserialize($GLOBALS['prefs']['installed_plugins']));

foreach (array('good', 'throws', 'noisy', 'toonew', 'lowreq') as $dir) {
    unlink($base . $dir . '/index.php');
    rmdir($base . $dir);
}
rmdir($base);
foreach (array('oldtheme', 'toonewtheme') as $dir) {
    unlink($themes . $dir . '/index.php');
    rmdir($themes . $dir);
}
rmdir($themes);

exit(harness_result());
