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
 * osc_mode_text() picks the shop or the classifieds wording, and the admin captcha
 * help path is built from the menu names.
 * Usage: php tests/mode-text.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['testMode'] = 'classifieds';
class Preference
{
    public static function getInstance()
    {
        return new self();
    }

    public function get($key, $section = 'osclass')
    {
        return $GLOBALS['testMode'];
    }
}
function __($key, $domain = 'core')
{
    return $key;
}
require_once ABS_PATH . 'oc-includes/osclass/classes/site/SiteMode.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

harness_section('mode text');
check('classifieds wording by default', osc_mode_text('Manage listings', 'Manage products') === 'Manage listings');
$GLOBALS['testMode'] = 'shop';
check('shop wording in shop mode', osc_mode_text('Manage listings', 'Manage products') === 'Manage products');

harness_section('captcha help path');
check('built from the menu names', osc_admin_captcha_settings_path() === 'Settings › Spam and bots › Captcha');
exit(harness_result());
