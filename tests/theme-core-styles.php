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
 * Pins the 'core-styles' theme support: core prints its fallback page sheet and the
 * comments sheet unless the theme opts out, and its standalone page stays styled.
 *
 * Both sheets print once per request, so each case runs in its own PHP process
 * against a scratch root whose content partials are fixtures.
 *
 * DB-free. Usage:  php tests/theme-core-styles.php
 */

if (($argv[1] ?? '') === '--case') {
    $root = (string) $argv[2];
    $case = (string) $argv[3];
    define('ABS_PATH', $root);
    require_once $root . 'oc-includes/osclass/classes/base/SharedInstance.php';
    require_once $root . 'oc-includes/osclass/classes/utility/Validate.php';
    require_once $root . 'oc-includes/osclass/classes/theme/ThemeSupports.php';

    /** Stands in for the active theme. */
    class WebThemes
    {
        public static string $path = '';

        public static function getInstance(): self
        {
            return new self();
        }

        public function getCurrentThemePath(): string
        {
            return self::$path;
        }

        public function getCurrentTheme(): string
        {
            return 'fixture';
        }

        /** @return array<string,string> */
        public function loadThemeInfo($theme): array
        {
            return array('template' => '');
        }
    }

    $GLOBALS['hooks'] = array();
    function osc_themes_path(): string
    {
        return ABS_PATH . 'themes/';
    }
    function osc_add_hook($hook, $fn): void
    {
        $GLOBALS['hooks'][$hook][] = $fn;
    }
    function osc_run_hook($hook): void
    {
        foreach ($GLOBALS['hooks'][$hook] ?? array() as $fn) {
            $fn();
        }
    }
    function osc_esc_html($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
    function osc_current_user_locale(): string
    {
        return 'en_US';
    }
    function osc_page_title(): string
    {
        return 'Site';
    }
    function osc_base_url(): string
    {
        return 'http://example.test/';
    }
    function osc_comments_enabled(): bool
    {
        return true;
    }

    require_once $root . 'oc-includes/osclass/helpers/hTheme.php';
    require_once $root . 'oc-includes/osclass/helpers/hItems.php';

    [$theme, $optOut, $what] = explode(':', $case);
    WebThemes::$path = ABS_PATH . 'themes/' . $theme . '/';
    if ($optOut === 'out') {
        osc_add_theme_support('core-styles', false);
    }
    if ($what === 'page') {
        osc_gui_view('', ABS_PATH . 'partial.php', array('heading' => 'Alerts'));
    } else {
        osc_show_item_comments();
    }
    exit(0);
}

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

// A scratch root: real core files linked in, fixtures for the content partials.
$scratch = sys_get_temp_dir() . '/osc-core-styles-' . getmypid() . '/';
$link    = static function (string $rel) use ($scratch): void {
    if (!is_dir(dirname($scratch . $rel))) {
        mkdir(dirname($scratch . $rel), 0777, true);
    }
    symlink(ABS_PATH . $rel, $scratch . $rel);
};
foreach (array(
    'oc-includes/osclass/classes/base/SharedInstance.php',
    'oc-includes/osclass/classes/utility/Validate.php',
    'oc-includes/osclass/classes/theme/ThemeSupports.php',
    'oc-includes/osclass/helpers/hTheme.php',
    'oc-includes/osclass/helpers/hItems.php',
    'oc-includes/osclass/gui/page.php',
    'oc-includes/osclass/gui/page-fn.php',
    'oc-includes/osclass/gui/page-style.php',
    'oc-includes/osclass/gui/item-comments-style.php',
) as $rel) {
    $link($rel);
}
file_put_contents($scratch . 'oc-includes/osclass/gui/item-comments-content.php', '<section class="oe-comments"></section>');
file_put_contents($scratch . 'partial.php', '<p>partial</p>');
mkdir($scratch . 'themes/chrome', 0777, true);
mkdir($scratch . 'themes/bare', 0777, true);
file_put_contents($scratch . 'themes/chrome/header.php', '<head><?php osc_run_hook("header"); ?></head><body>');
file_put_contents($scratch . 'themes/chrome/footer.php', '</body>');

$run = static function (string $case) use ($scratch): string {
    return (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --case '
        . escapeshellarg($scratch) . ' ' . escapeshellarg($case) . ' 2>&1'
    );
};
$pageSheet     = '@layer shopclass';
$commentsSheet = ':where(.oe-comments)';

harness_section('a theme that declares nothing keeps core styles');
$out = $run('chrome:default:page');
check('the fallback page renders inside the chrome', str_contains($out, '<p>partial</p>'), $out);
pin('the page sheet prints once', 1, substr_count($out, $pageSheet));
$out = $run('chrome:default:comments');
check('the comments sheet prints', str_contains($out, $commentsSheet), $out);
check('...before the block', strpos($out, $commentsSheet) < strpos($out, 'class="oe-comments"'));

harness_section('a theme that opts out gets neither sheet');
$out = $run('chrome:out:page');
check('the fallback page still renders', str_contains($out, '<p>partial</p>'), $out);
check('without the page sheet', !str_contains($out, $pageSheet));
$out = $run('chrome:out:comments');
check('the comments block still renders', str_contains($out, 'class="oe-comments"'), $out);
check('without the comments sheet', !str_contains($out, $commentsSheet));

harness_section('the standalone page keeps its styles');
$out = $run('bare:out:page');
check('a theme with no chrome gets core\'s own page', str_contains($out, '<!doctype html>'), $out);
check('styled even though the theme opted out', str_contains($out, $pageSheet));

harness_section('ThemeSupports::has()');
require_once ABS_PATH . 'oc-includes/osclass/classes/base/SharedInstance.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/ThemeSupports.php';
$supports = \mindstellar\theme\ThemeSupports::getInstance();
check('false for an undeclared feature', !$supports->has('core-styles'));
$supports->add('core-styles', false);
check('true for a feature declared false, which get() cannot tell apart', $supports->has('core-styles'));
$supports->reset();

exec('rm -rf ' . escapeshellarg($scratch));

exit(harness_result());
