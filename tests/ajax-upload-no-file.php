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
 * Pins that a photo upload request with no file is refused, not a crash.
 *
 * The ajax_upload action asks the uploader for the file's name before it uploads.
 * With no Content-Type header the uploader had no file object, so that call was
 * "Call to a member function getOriginalName() on false" and a 500. A multipart
 * post without a qqfile field read keys of an empty array. Both must give an empty
 * name and the "No files were uploaded." / "File is empty" refusal, with no warning.
 *
 * DB-free. Usage:  php tests/ajax-upload-no-file.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

$tmpDir = sys_get_temp_dir() . '/osc-upload-no-file-' . getmypid();
@mkdir($tmpDir);

/**
 * Run the ajax_upload action's uploader calls for one request shape.
 *
 * @return array{name: mixed, error: string, warnings: string[]}
 */
$request = static function (array $server, array $files, array $get = array()) use ($tmpDir): array {
    $_SERVER = $server;
    $_FILES  = $files;
    $_GET    = $get;
    $_POST   = array();
    $_REQUEST = $get;
    Params::init();

    $warnings = array();
    set_error_handler(static function ($no, $str) use (&$warnings) {
        $warnings[] = $str;

        return true;
    });
    $out = array('name' => null, 'error' => '', 'warnings' => array());
    try {
        $uploader    = new AjaxUploader(array('png', 'jpg'), 1024 * 1024);
        $out['name'] = $uploader->getOriginalName();
        $uploader->handleUpload($tmpDir . '/' . uniqid('qqfile_', true) . '.png');
        $out['error'] = 'none';
    } catch (Throwable $e) {
        $out['error'] = get_class($e) . ': ' . $e->getMessage();
    } finally {
        restore_error_handler();
    }
    $out['warnings'] = $warnings;

    return $out;
};

harness_section('No Content-Type header');
$bare = $request(array('REQUEST_METHOD' => 'POST'), array());
pin('the name is empty', '', $bare['name']);
pin('the upload is refused with "No files were uploaded."', 'Exception: No files were uploaded.', $bare['error']);
pin('no warning', array(), $bare['warnings']);

harness_section('Multipart post without a qqfile field');
$form = $request(
    array('REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x'),
    array()
);
pin('the name is empty', '', $form['name']);
pin('the upload is refused as an empty file', 'Exception: File is empty', $form['error']);
pin('no warning', array(), $form['warnings']);

harness_section('An array-shaped qqfile');
$xhr = $request(
    array('REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/octet-stream', 'CONTENT_LENGTH' => '10'),
    array(),
    array('qqfile' => array('a.png'))
);
pin('?qqfile[]= gives an empty name', '', $xhr['name']);
check('and is refused, not a crash', strpos($xhr['error'], 'Exception: File has an invalid extension') === 0, $xhr['error']);
$multi = $request(
    array('REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x'),
    array('qqfile' => array('name' => array('a.png'), 'type' => array('image/png'), 'tmp_name' => array('/tmp/x'), 'error' => array(0), 'size' => array(10)))
);
pin('a qqfile[] field gives an empty name', '', $multi['name']);
pin('and is refused as an empty file', 'Exception: File is empty', $multi['error']);

@rmdir($tmpDir);

exit(harness_result());
