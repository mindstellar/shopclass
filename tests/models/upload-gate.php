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
 * is refused when only users may post, each address has an hourly limit, and one form's upload
 * token holds no more photos than a listing may have.
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

$token = str_repeat('ab', 16);
$_COOKIE['oc_upload']   = $token;
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
Params::init();

$refusal = static function (): string {
    $ajax   = (new ReflectionClass('CWebAjax'))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod('CWebAjax', 'uploadRefusal');
    $method->setAccessible(true);

    return $method->invoke($ajax);
};
$prefs = static function (array $values): void {
    foreach ($values as $key => $value) {
        osc_set_preference($key, $value, 'osclass', 'STRING');
    }
    osc_reset_preferences();
};
$stage = static function (int $n) use ($token): void {
    for ($i = 0; $i < $n; $i++) {
        ItemTmpUpload::newInstance()->add($token, 'uuid' . $i, 'auto_qqfile_' . $i . '.jpg');
    }
};

harness_section('counting staged files');
pin('nothing staged yet', 0, ItemTmpUpload::newInstance()->countByToken($token));
$stage(2);
pin('two staged under the token', 2, ItemTmpUpload::newInstance()->countByToken($token));
pin('another token has none', 0, ItemTmpUpload::newInstance()->countByToken(str_repeat('cd', 16)));
ItemTmpUpload::newInstance()->deleteByToken($token);

harness_section('who may stage a photo');
$prefs(array('reg_user_post' => '1', 'numImages@items' => '3'));
pin(
    'a guest is refused when only users may post',
    'Only registered users are allowed to post listings',
    $refusal()
);
$prefs(array('reg_user_post' => '0'));
pin('a guest may stage when anyone may post', '', $refusal());

harness_section('one form holds no more than the photo limit');
$stage(2);
pin('below the limit of 3', '', $refusal());
$stage(1);
pin('at the limit of 3 the next one is refused', 'You have reached the photo limit for this listing.', $refusal());
$prefs(array('numImages@items' => '0'));
pin('no photo limit lets the form go on', '', $refusal());
ItemTmpUpload::newInstance()->deleteByToken($token);

harness_section('an hourly limit per address');
pin('the first upload is allowed', '', $refusal());
for ($i = 0; $i < 100; $i++) {
    \mindstellar\security\ActionThrottle::record('ajax_upload');
}
pin('after 100 in the hour the next is refused', 'Too many tries from your connection. Please try again later.', $refusal());

harness_section('the endpoint is wired to it');
$src = file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebAjax.php');
check(
    'the refusal is checked before the file is handled',
    (bool)preg_match("/case 'ajax_upload':\s*\\\$refused = \\\$this->uploadRefusal\(\);.*?new AjaxUploader\(\)/s", $src)
);
check('a staged upload is counted', strpos($src, "ActionThrottle::record('ajax_upload');") !== false);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
