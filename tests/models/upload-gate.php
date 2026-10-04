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
 * Anyone could stage any number of photos through ?page=ajax&action=ajax_upload. Now a guest
 * is refused when only users may post, and guests have an hourly limit per address.
 *
 * Usage:  php tests/models/upload-gate.php          (standalone, own scratch database)
 *         php tests/run-models.php upload-gate      (as part of the suite)
 */

if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_upload_gate');

require_once __DIR__ . '/../lib/action-standins.php';
if (!function_exists('osc_register_render_target')) {
    function osc_register_render_target($id, $path)
    {
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';

if (!defined('OSC_DEBUG')) {
    define('OSC_DEBUG', false);
}

$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
Params::init();

$refusal = static function (): string {
    $ajax   = (new ReflectionClass('CWebAjax'))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod('CWebAjax', 'uploadRefusal');
    $method->setAccessible(true);

    return $method->invoke($ajax);
};
$endpoint = static function (): string {
    $ajax = (new ReflectionClass('CWebAjax'))->newInstanceWithoutConstructor();
    $action = new ReflectionProperty('BaseModel', 'action');
    $action->setAccessible(true);
    $action->setValue($ajax, 'ajax_upload');
    ob_start();
    $ajax->doModel();

    return (string) ob_get_clean();
};
$prefs = static function (array $values): void {
    foreach ($values as $key => $value) {
        osc_set_preference($key, $value, 'osclass', 'STRING');
    }
    osc_reset_preferences();
};
$signIn = static function (bool $in): void {
    if ($in) {
        View::newInstance()->_exportVariableToView('_loggedUser', array(
            'pk_i_id' => 7, 's_name' => 'U', 's_email' => 'u@example.com', 's_username' => 'u',
            'b_enabled' => 1, 'b_active' => 1, 'fk_c_country_code' => '', 'fk_i_region_id' => null,
        ));
    } else {
        View::newInstance()->_erase('_loggedUser');
    }
};

harness_section('who may stage a photo');
$prefs(array('reg_user_post' => '1'));
pin(
    'a guest is refused when only users may post',
    'Only registered users are allowed to post listings',
    $refusal()
);
check(
    '...and the endpoint answers with that refusal',
    strpos($endpoint(), 'Only registered users are allowed to post listings') !== false
);
$signIn(true);
pin('a signed-in user may stage', '', $refusal());
$signIn(false);
$prefs(array('reg_user_post' => '0'));
pin('a guest may stage when anyone may post', '', $refusal());

harness_section('an hourly limit per address for guests');
for ($i = 0; $i < 100; $i++) {
    \mindstellar\security\ActionThrottle::record('ajax_upload');
}
pin('after 100 in the hour the next guest upload is refused', 'Too many tries from your connection. Please try again later.', $refusal());
$signIn(true);
pin('a signed-in user on that address is not limited', '', $refusal());
$signIn(false);
osc_add_filter('action_throttle_limit', static function ($limit, $context) {
    return $context === 'ajax_upload' ? array('max' => 500, 'window' => 3600) : $limit;
});
pin('action_throttle_limit can raise it', '', $refusal());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
