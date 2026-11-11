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
 * The language strips and the market and location tabs go through the shared helpers.
 * The language strip markup must stay byte-identical to what the forms printed by hand.
 *
 * Usage: php tests/admin-tabs.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';

$GLOBALS['locales'] = array(
    array('pk_c_code' => 'en_US', 's_name' => 'English'),
    array('pk_c_code' => 'es_ES', 's_name' => 'Español'),
);

class Params
{
    public static function getParam($key, $a = false, $b = true)
    {
        return '';
    }
}

class OSCLocale
{
    public static function getInstance()
    {
        return new self();
    }

    public static function newInstance()
    {
        return self::getInstance();
    }

    public function listAllEnabled($isBo = false, $indexedByPk = false)
    {
        return $GLOBALS['locales'];
    }
}

function osc_add_hook($hook, $fn, $priority = 5)
{
}

function osc_apply_filter($hook, $content = '', ...$args)
{
    return $content;
}

function osc_current_admin_locale()
{
    return 'en_US';
}

function osc_language()
{
    return 'en_US';
}

function osc_get_locales()
{
    return $GLOBALS['locales'];
}

require_once __DIR__ . '/lib/stubs.php';

$field = \mindstellar\admin\ui\Field::class;
$root  = dirname(__DIR__);

harness_section('language strips print the markup the forms printed by hand');

$old = static function (array $tabs, string $class, string $panels = '') {
    return '<div id="language-tab" class="' . $class . '"><ul>' . implode('', $tabs) . '</ul>' . $panels . '</div>';
};

$locales = array_map(static fn ($l) => $l, $GLOBALS['locales']);
$tabsFor = static function (string $prefix) use ($field, $locales) {
    return array_map(
        static fn ($l) => $field::localeTab($prefix . $l['pk_c_code'], $l['s_name'], $l['pk_c_code'], 'en_US'),
        $locales
    );
};

ob_start();
PageForm::printMultiLangTab();
pin('page strip', $old($tabsFor(''), 'ui-osc-tabs osc-tab mt-3'), (string) ob_get_clean());

ob_start();
FieldForm::multiLangTitle(null);
$fieldOut = (string) ob_get_clean();
$fieldHead = '<div id="language-tab" class="ui-osc-tabs osc-tab mt-3"><ul>' . implode('', $tabsFor('meta_')) . '</ul>';
check('custom field strip opens with the same list', str_starts_with($fieldOut, $fieldHead), $fieldOut);
check('custom field strip holds one panel per locale', substr_count($fieldOut, 'role="tabpanel"') === 2 && str_ends_with($fieldOut, '</div></div>'), $fieldOut);

ob_start();
UserForm::multilanguage_info($locales, null);
$userOut = (string) ob_get_clean();
check('user strip opens with the same list', str_starts_with($userOut, '<div id="language-tab" class="ui-osc-tabs osc-tab mt-3"><ul>' . implode('', $tabsFor('')) . '</ul><div id="en_US" role="tabpanel">'), $userOut);
check('user strip closes the wrapper', str_ends_with($userOut, '</div></div>'), $userOut);

harness_section('helper wrappers');
pin(
    'empty strip prints no list',
    '<div id="language-tab" class="x"></div>',
    $field::localeStrip(array(), array('id' => 'language-tab', 'class' => 'x'))
);

exit(harness_result());
