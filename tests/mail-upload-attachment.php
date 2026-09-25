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
 * Pins osc_mail_upload_attachment(): nothing uploaded is null, and a path that PHP did not
 * receive as an upload is refused, so a mail can never attach a file already on the server.
 * The accepted case needs a real upload and is covered by hand.
 * Usage:  php tests/mail-upload-attachment.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Params.php';
require_once __DIR__ . '/../oc-includes/osclass/utils.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$_FILES = array();
check('no field is null', osc_mail_upload_attachment('attachment') === null);

$_FILES['attachment'] = array('name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE);
check('an empty file input is null', osc_mail_upload_attachment('attachment') === null);

$_FILES['attachment'] = array('name' => 'x.txt', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_PARTIAL);
check('a failed upload is refused', osc_mail_upload_attachment('attachment') === false);

$serverFile = tempnam(sys_get_temp_dir(), 'osc');
file_put_contents($serverFile, 'plain text already on the server');
$_FILES['attachment'] = array('name' => 'passwd.txt', 'tmp_name' => $serverFile, 'error' => UPLOAD_ERR_OK);
check('a server file posing as an upload is refused', osc_mail_upload_attachment('attachment') === false);

$_FILES['attachment'] = array('name' => array('a'), 'tmp_name' => array(__FILE__), 'error' => array(0));
check('an array-shaped field is refused', osc_mail_upload_attachment('attachment') === false);

unlink($serverFile);
exit(harness_result());
