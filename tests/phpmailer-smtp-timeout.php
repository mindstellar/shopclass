<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * PHPMailer's default Timeout is 300 seconds. A firewalled or mistyped SMTP
 * host then holds a php-fpm worker for five minutes per osc_sendMail() call.
 * The helper caps that wait; the filter may raise or lower it, never past 60.
 *
 * DB-free. Usage:  php tests/phpmailer-smtp-timeout.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OSCLASS_VERSION')) {
    define('OSCLASS_VERSION', '0');
}

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

if (!function_exists('osc_apply_filter')) {
    /**
     * @param string $tag
     * @param mixed  $value
     *
     * @return mixed
     */
    function osc_apply_filter($tag, $value)
    {
        if ($tag === 'phpmailer_smtp_timeout' && isset($GLOBALS['osc_test_smtp_timeout'])) {
            return $GLOBALS['osc_test_smtp_timeout'];
        }

        return $value;
    }
}

require_once __DIR__ . '/../oc-includes/osclass/utils.php';

use PHPMailer\PHPMailer\PHPMailer;

harness_section('default cap is 15 seconds');
unset($GLOBALS['osc_test_smtp_timeout']);
pin('default seconds', 15, osc_phpmailer_smtp_timeout_seconds());

$mail = new PHPMailer(false);
osc_phpmailer_limit_smtp_wait($mail);
pin('PHPMailer Timeout', 15, $mail->Timeout);
pin('SMTP Timeout', 15, $mail->getSMTPInstance()->Timeout);
check('SMTP Timelimit matches', $mail->getSMTPInstance()->Timelimit === 15);

harness_section('filter is clamped to 1–60');
$GLOBALS['osc_test_smtp_timeout'] = 0;
pin('zero becomes 1', 1, osc_phpmailer_smtp_timeout_seconds());
$GLOBALS['osc_test_smtp_timeout'] = 300;
pin('five minutes becomes 60', 60, osc_phpmailer_smtp_timeout_seconds());
$GLOBALS['osc_test_smtp_timeout'] = 25;
pin('in-range kept', 25, osc_phpmailer_smtp_timeout_seconds());

exit(harness_result());
