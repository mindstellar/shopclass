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
 * TranslationLoaded (old name translation_load_after) fires once the core, theme and plugin
 * catalogues are in, and a catalogue loaded there replaces the texts loaded before it.
 *
 * Usage: php tests/translation-load-hook.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\language\event\TranslationLoaded;

api_admin_boot('osc_translation_load_hook');
foreach (array(
    'osc_translations_path' => ABS_PATH . 'oc-content/languages/',
    'osc_themes_path'       => ABS_PATH . 'oc-content/themes/',
    'osc_plugins_path'      => ABS_PATH . 'oc-content/plugins/',
) as $fn => $path) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return ' . var_export($path, true) . '; }');
    }
}

// An enabled plugin with its own catalogue, so the test shows the hook runs after plugin texts too.
$pluginMo = sys_get_temp_dir() . '/osc-translation-hook-' . getmypid() . '.mo';
register_shutdown_function(static function () use ($pluginMo) {
    @unlink($pluginMo);
});
$catalogue = new Gettext\Translations();
$catalogue->setDomain('texts_fixture');
$catalogue->insert('', 'Hello')->setTranslation('From the plugin');
Gettext\Generators\Mo::toFile($catalogue, $pluginMo);
(new ReflectionProperty('Plugins', 'enabled'))->setValue(null, array('texts_fixture/index.php'));
osc_add_filter('mo_plugin_path', static fn ($path, $locale, $domain) => $domain === 'texts_fixture' ? $pluginMo : $path);

$seen = array();
osc_add_hook('translation_load_after', static function ($translation, $locale) use (&$seen) {
    $seen[] = array($translation, $locale);
    $translation->_get()->loadTranslations(array(
        'domain'   => 'core',
        'messages' => array('' => array('Edit' => array('Change'))),
    ));
    $translation->_get()->loadTranslations(array(
        'domain'   => 'texts_fixture',
        'messages' => array('' => array('Hello' => array('From the hook'))),
    ));
});

$typed = array();
osc_add_hook('translation_load_after', static function (TranslationLoaded $event) use (&$typed) {
    $typed[] = array($event->translation(), $event->locale());
});

$t = Translation::init();
pin('the hook fires once per load', 1, count($seen));
check('...with the translation being built', ($seen[0][0] ?? null) === $t);
pin('...and its locale', osc_current_user_locale(), $seen[0][1] ?? null);
pin('a callback that asks for the event gets it', array(array($t, osc_current_user_locale())), $typed);
$text = static fn (string $key): string => Translation::getInstance()->_get()->dgettext('core', $key);
pin('a catalogue loaded in the hook replaces the core text', 'Change', $text('Edit'));
pin('other texts are unchanged', 'Delete', $text('Delete'));
pin('the hook runs after plugin texts, so its text wins', 'From the hook', Translation::getInstance()->_get()->dgettext('texts_fixture', 'Hello'));

Translation::init();
pin('a reload fires it again, so the layer is not lost', 2, count($seen));
pin('...and the text stays replaced', 'Change', $text('Edit'));

exit(harness_result());
