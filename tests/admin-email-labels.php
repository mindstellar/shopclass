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
 * The email templates list: a plain name for each core template and a subject without
 * the site-name prefix or upper-case placeholders.
 *
 * Usage: php tests/admin-email-labels.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/EmailVariables.php';

harness_section('template names');
pin('a core template', 'Question about a listing', EmailVariables::label('email_item_inquiry'));
pin('an alert', 'Search alert, hourly', EmailVariables::label('alert_email_hourly'));
pin('a plugin template shows its key as words', 'Email my plugin note', EmailVariables::label('email_my_plugin_note'));

harness_section('subjects');
pin('the site name prefix goes', 'Someone has a question about your listing', EmailVariables::plainSubject('{WEB_TITLE} - Someone has a question about your listing'));
pin('other placeholders read as words', 'Confirm {item title}', EmailVariables::plainSubject('Confirm {ITEM_TITLE}'));
pin('plain text stays', 'Welcome', EmailVariables::plainSubject('Welcome'));
exit(harness_result());
