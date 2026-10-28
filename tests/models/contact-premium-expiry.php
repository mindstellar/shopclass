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
 * The contact form refuses an expired listing but not a premium one past its date, which
 * stays live like everywhere else on the site.
 *
 * Usage:  php tests/models/contact-premium-expiry.php
 *         php tests/run-models.php contact-premium-expiry
 */

if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\exception\ConflictException;
use mindstellar\exception\InvalidException;
use mindstellar\listing\ListingMailService;

scratchdb_session('osc_models_contact_premium_expiry');
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
foreach (array('hPreference', 'hPlugins', 'hSecurity', 'hValidate', 'hUtils', 'hUsers', 'hLocale', 'hSanitize') as $helper) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/' . $helper . '.php';
}

$past = date('Y-m-d H:i:s', time() - 86400);
// A blank name stops the send at validation, after the expiry check has run.
$form = array('yourName' => '', 'yourEmail' => 'buyer@example.com', 'phoneNumber' => '', 'message' => 'Is it still for sale?');
$outcome = static function (array $item) use ($form): string {
    try {
        (new ListingMailService())->contactSeller($item, $form);
    } catch (ConflictException $e) {
        return 'expired';
    } catch (InvalidException $e) {
        return 'past the expiry check';
    }

    return 'sent';
};

pin('an expired listing is refused', 'expired', $outcome(array('pk_i_id' => 1, 'b_premium' => 0, 'dt_expiration' => $past)));
pin('a premium listing past its date is still live', 'past the expiry check', $outcome(array('pk_i_id' => 1, 'b_premium' => 1, 'dt_expiration' => $past)));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
