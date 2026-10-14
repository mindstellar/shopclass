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
 * Pins that request params sent as arrays (key[]=x) do not crash the reader with a TypeError.
 *
 * ListingInput gives a scalar field the empty string when it arrives as an array, and still
 * takes the per-locale title and description arrays. Params::getParamString() and
 * getParamInt(), which the login, language, route and CSRF readers use, never return an array.
 *
 * DB-free.  Usage: php tests/request-array-params.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

harness_section('ListingInput reads scalar fields as scalars');

// build() needs the preferences table, so the reader is checked in the source.
$src = file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/listing/ListingInput.php');
pin('the $get closure refuses an array for a scalar field', 1, preg_match(
    '/\$get\s*=\s*static fn[^;]*is_scalar\(\$form\[\$key\]\)[^;]*\'title\', \'description\'/s',
    $src
));
pin('ajax_photos is read from the form, not through $get', 1, preg_match('/\$ajax_photos\s*=\s*isset\(\$form\[\'ajax_photos\'\]\)/', $src));

harness_section('Readers that took a scalar no longer take getParam');

$base  = ABS_PATH . 'oc-includes/osclass/classes/';
$sites = array(
    'security/Csrf.php'                       => array('CSRFName', 'CSRFToken'),
    'controller/CWebLogin.php'                => array('email', 'password', 'new_password', 'new_password2'),
    'controller/admin/CAdminLogin.php'        => array('user', 'password'),
    'controller/CWebCustom.php'               => array('route', 'file'),
    'controller/CWebAjax.php'                 => array('route', 'ajaxfile', 'country', 'region'),
    'controller/CWebLanguage.php'             => array('locale'),
    'controller/CWebPage.php'                 => array('lang'),
    'controller/admin/CAdminPlugins.php'      => array('route'),
);
foreach ($sites as $file => $keys) {
    $code = file_get_contents($base . $file);
    foreach ($keys as $key) {
        pin($file . ' reads ' . $key . ' as a string', 0, preg_match("/Params::getParam\\('" . $key . "'/", $code));
    }
}

harness_section('Params readers');

foreach (array('CSRFName', 'password', 'route', 'lang') as $key) {
    Params::setParam($key, array('x'));
}
Params::setParam('id', array('7'));
pin('getParamString on an array is empty', array('', '', '', ''), array(
    Params::getParamString('CSRFName'),
    Params::getParamString('password', false, false),
    Params::getParamString('route'),
    Params::getParamString('lang'),
));
pin('getParamInt on an array is 0', 0, Params::getParamInt('id'));
Params::setParam('route', 'my_route');
pin('a scalar route is read as before', 'my_route', Params::getParamString('route'));

exit(harness_result());

/* file end: ./tests/request-array-params.php */
