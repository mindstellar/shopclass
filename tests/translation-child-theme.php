<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * A child theme's templates fall back to its parent's, so the parent's catalogue loads too.
 *
 * Usage: php tests/translation-child-theme.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

api_admin_boot('osc_translation_child_theme');

$themes = sys_get_temp_dir() . '/osc-translation-child-' . getmypid() . '/';
register_shutdown_function(static function () use ($themes) {
    exec('rm -rf ' . escapeshellarg($themes));
});
eval('function osc_themes_path() { return ' . var_export($themes, true) . '; }');
foreach (array(
    'osc_translations_path' => ABS_PATH . 'oc-content/languages/',
    'osc_plugins_path'      => ABS_PATH . 'oc-content/plugins/',
) as $fn => $path) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return ' . var_export($path, true) . '; }');
    }
}

$locale = osc_current_user_locale();
$theme  = static function (string $slug, string $header) use ($themes): void {
    @mkdir($themes . $slug, 0755, true);
    file_put_contents($themes . $slug . '/index.php', "<?php\n/*\nTheme Name: " . ucfirst($slug) . "\n" . $header . "*/\n");
};
$theme('kid', "Parent Theme: mum\n");
$theme('mum', '');
$theme('solo', '');
$theme('loop', "Parent Theme: loop\n");
$theme('stray', "Parent Theme: ../mum\n");
mkdir($themes . 'mum/languages/' . $locale, 0755, true);
$catalogue = new Gettext\Translations();
$catalogue->setDomain('mum');
$catalogue->insert('', 'Title')->setTranslation('Heading');
Gettext\Generators\Mo::toFile($catalogue, $themes . 'mum/languages/' . $locale . '/theme.mo');

$active = 'kid';
osc_add_filter('theme', static function () use (&$active) {
    return $active;
});
$text = static fn (): string => Translation::getInstance()->_get()->dgettext('mum', 'Title');

harness_section('parentOf');
$web = WebThemes::getInstance();
pin('a child names its parent', 'mum', $web->parentOf('kid'));
pin('a theme with no parent has none', '', $web->parentOf('solo'));
pin('a theme naming itself has none', '', $web->parentOf('loop'));
pin('a missing theme has none', '', $web->parentOf('nope'));
pin('a parent name with a path is refused', '', $web->parentOf('stray'));

harness_section('the parent catalogue');
Translation::init();
pin('a child theme loads its parent texts', 'Heading', $text());
$active = 'solo';
Translation::init();
pin('another theme does not', 'Title', $text());

exit(harness_result());
