<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * LanguageService and LocaleStore, the writes the languages screen makes.
 *
 * Usage:  php tests/models/languageservice.php      (standalone, own scratch database)
 *         php tests/run-models.php languageservice  (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
// Registering hooks and a real _m() both read the plugins path.
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return dirname(__DIR__, 2) . '/oc-content/plugins/';
    }
}
require_once dirname(__DIR__, 2) . '/oc-includes/osclass/helpers/hHttpCache.php';
require_once dirname(__DIR__, 2) . '/oc-includes/osclass/utils.php';

use mindstellar\language\LanguageService;
use mindstellar\language\LocaleStore;
use mindstellar\validation\ConflictException;

if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}

$admin = scratchdb_session('osc_models_languageservice');
// hDefines.php redeclares the runner's osc_uploads_path() stand-in, so this helper is stubbed.
if (!function_exists('osc_translations_path')) {
    function osc_translations_path()
    {
        return sys_get_temp_dir() . '/osc-languageservice-' . getmypid() . '/';
    }
}
$table = DB_TABLE_PREFIX . 't_locale';

foreach (array('aa_AA', 'bb_BB', 'cc_CC', 'ee_EE') as $code) {
    seed_exec(
        $admin,
        "INSERT INTO $table (pk_c_code, s_name, s_short_name, s_description, s_version, s_author_name,
         s_author_url, s_currency_format, s_date_format, b_enabled, b_enabled_bo)
         VALUES (?, ?, ?, ?, '1.0', 'M', 'https://example.test', '{NUMBER}', 'd/m/Y', 1, 1)",
        'ssss',
        array($code, $code, $code, $code)
    );
}
$admin->query("DELETE FROM " . DB_TABLE_PREFIX . "t_preference WHERE s_section = 'osclass' AND s_name = 'language'");
$admin->query("INSERT INTO " . DB_TABLE_PREFIX . "t_preference (s_section, s_name, s_value, e_type) VALUES ('osclass', 'language', 'aa_AA', 'STRING')");
osc_reset_preferences();

$row = static function (string $code) use ($admin, $table): ?array {
    $stmt = $admin->prepare("SELECT * FROM $table WHERE pk_c_code = ?");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $found;
};

$service = LanguageService::make();

harness_section('Edit');

pin('an edit changes one row', 1, LocaleStore::update('bb_BB', array('s_name' => 'Bee', 'b_enabled' => false)));
pin('...to the posted values', array('Bee', '0'), array($row('bb_BB')['s_name'], (string) $row('bb_BB')['b_enabled']));
pin('an unchanged edit changes nothing', 0, LocaleStore::update('bb_BB', array('s_name' => 'Bee')));
pin('the key and unknown columns are ignored', 0, LocaleStore::update('bb_BB', array('pk_c_code' => 'zz_ZZ', 'x' => 1)));
pin('...so the row keeps its code', 'Bee', $row('bb_BB')['s_name']);

harness_section('Disable');

pin('disabling for the website clears b_enabled', 1, $service->disable('cc_CC'));
pin('disabling for oc-admin clears b_enabled_bo', 1, $service->disable('cc_CC', true));
pin('...and both flags are off', array('0', '0'), array((string) $row('cc_CC')['b_enabled'], (string) $row('cc_CC')['b_enabled_bo']));
$refused = null;
try {
    $service->disable('aa_AA');
} catch (ConflictException $e) {
    $refused = $e->getMessage();
}
check('the default language cannot be disabled', $refused !== null && str_contains($refused, 'aa_AA'));
pin('...and stays on', '1', (string) $row('aa_AA')['b_enabled']);

harness_section('Delete');

pin('the language the admin is using is refused', LanguageService::IS_CURRENT, $service->delete('bb_BB', 'bb_BB'));
pin('the default language is refused', LanguageService::IS_DEFAULT, $service->delete('aa_AA', 'bb_BB'));
pin('an unknown code fails', LanguageService::FAILED, $service->delete('zz_ZZ', 'aa_AA'));
pin('a language with no folder is deleted, the folder reported kept', LanguageService::DIR_KEPT, $service->delete('cc_CC', 'aa_AA'));
pin('...and its row is gone', null, $row('cc_CC'));
@mkdir(osc_translations_path() . 'ee_EE', 0755, true);
pin('a language with a folder is deleted with it', LanguageService::DELETED, $service->delete('ee_EE', 'aa_AA'));
check('...and the folder is gone', !is_dir(osc_translations_path() . 'ee_EE'));
if (str_contains(osc_translations_path(), 'osc-languageservice-')) {
    @rmdir(osc_translations_path());
}
check('...while the refused ones stay', $row('aa_AA') !== null && $row('bb_BB') !== null);

harness_section('Install');

$manifest = array(
    'locale_code' => 'dd_DD', 'name' => 'Dee', 'short_name' => 'Dee', 'description' => 'Dee',
    'version' => '2.0', 'direction' => 'ltr', 'author_name' => 'M', 'author_url' => 'https://example.test',
    'currency_format' => '{NUMBER}', 'date_format' => 'd/m/Y',
);
check('an install with no mail.json succeeds', $service->install($manifest, 'dd_DD', false));
pin('...and adds the row, on for oc-admin only', array('0', '1'), array((string) $row('dd_DD')['b_enabled'], (string) $row('dd_DD')['b_enabled_bo']));
check('an unreadable mail.json is reported', !$service->install($manifest, 'dd_DD', 'not json'));

$controller = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminLanguages.php');
check(
    'the languages screen writes t_locale only through LanguageService',
    !str_contains($controller, 'localeManager->update(') && !str_contains($controller, 'deleteLocale(')
    && !str_contains($controller, 'insertLocaleInfo(') && !str_contains($controller, 'importEmailJsonTemplates(')
);

harness_section('Translation repository');

$asked = array();
$fetch = static function (string $url) use (&$asked) {
    $asked[] = $url;
    if (str_ends_with($url, 'locale_list.json') || !str_contains($url, '/src/translations/')) {
        return '[{"locale_code":"ff_FF","version":"1.2"},{"name":"no code"},"junk"]';
    }

    return str_ends_with($url, 'core.mo') ? false : 'body of ' . basename($url);
};
pin('the published list is keyed by code, entries without one dropped', array('ff_FF'), array_keys(LanguageService::published($fetch)));
pin('an unreadable list is null', null, LanguageService::published(static fn () => false));

$asked = array();
pin('one file that will not download is counted', 1, LanguageService::downloadFiles('ff_FF', $fetch));
pin('...each of the six files is asked for, under the code', array_map(static fn (string $f): string => 'src/translations/ff_FF/' . $f, array('theme.po', 'core.po', 'messages.po', 'theme.mo', 'core.mo', 'messages.mo')), array_map(static fn (string $u): string => substr($u, (int) strpos($u, 'src/translations/')), $asked));
pin('...and the others are written into the language folder', 'body of core.po', file_get_contents(osc_translations_path() . 'ff_FF/core.po'));
check('...the missing one is not', !is_file(osc_translations_path() . 'ff_FF/core.mo'));
osc_deleteDir(osc_translations_path() . 'ff_FF');
$asked = array();
pin('a code that is not a locale code is refused before any folder or fetch', array(null, null, null, array(), false), array(
    LanguageService::downloadFiles('../ff_FF', $fetch), LanguageService::downloadFiles('ff_FF/x', $fetch), LanguageService::downloadFiles("ff_FF\n", $fetch),
    $asked, is_dir(osc_translations_path() . '../ff_FF'),
));
touch(osc_translations_path() . 'gg_GG');
pin('a folder that cannot be made is null, with nothing fetched', array(null, array()), array(LanguageService::downloadFiles('gg_GG', $fetch), $asked));
@unlink(osc_translations_path() . 'gg_GG');
@rmdir(osc_translations_path());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
