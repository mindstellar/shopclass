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
 * A listing waiting for payment, edited by its seller, goes back to its pay step, and its page
 * says it waits for payment with a pay link instead of asking for the e-mail check.
 * DB-free. Usage: php tests/item-held-edit.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
require_once __DIR__ . '/lib/harness.php';

$file = ABS_PATH . 'oc-includes/osclass/classes/controller/CWebItem.php';

harness_section('edit');
$edit   = harness_method_source($file, 'itemEditPost');
$unpaid = strpos($edit, '$saved->unpaid()');
$moder  = strpos($edit, 'osc_moderate_admin_edit()');
check('a saved edit of a held listing is told apart', $unpaid !== false);
check('before the moderation and success messages', $unpaid !== false && $moder !== false && $unpaid < $moder);
check('and goes to the pay step', (bool) preg_match('/\$saved->unpaid\(\)\)\s*\{[^}]*redirectTo\(osc_billing_publish_url\(/s', $edit));

harness_section('the listing page');
$show = harness_method_source($file, 'flashStatusNotice');
$held = strpos($show, 'ListingStatus::isHeld($item)');
$val  = strpos($show, "hasn't been validated");
check('a held listing is checked before the validation notice', $held !== false && $val !== false && $held < $val);
check('its notice links to the pay step', str_contains($show, 'osc_billing_publish_url('));

exit(harness_result());
