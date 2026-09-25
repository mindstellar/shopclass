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
 * Pins the photo uploader's browser resize config: the box is the normal size, never smaller,
 * and keeping the full-size photo limits resizing to files over the maximum size.
 *
 * Usage: php tests/browser-resize-config.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\storage\BrowserResize;

$on = BrowserResize::resolve(true, false, '640x480');
pin('the box is the normal size', array(640, 480), array($on['maxWidth'] ?? null, $on['maxHeight'] ?? null));
pin('every photo over the box is shrunk', false, $on['onlyOversize'] ?? null);
pin('small files are left alone', BrowserResize::MIN_BYTES, $on['minBytes'] ?? null);
pin('switched off, there is no config', null, BrowserResize::resolve(false, false, '640x480'));
pin(
    'keeping the full size limits it to files over the maximum size',
    true,
    BrowserResize::resolve(true, true, '640x480')['onlyOversize'] ?? null
);
pin('a size written in capitals still reads', 1920, BrowserResize::resolve(true, false, ' 1920X1080 ')['maxWidth'] ?? null);
pin('a broken normal size turns it off', null, BrowserResize::resolve(true, false, '640'));
pin('a zero normal size turns it off', null, BrowserResize::resolve(true, false, '0x480'));
pin(
    'the config is plain JSON numbers',
    '{"maxWidth":640,"maxHeight":480,"minBytes":1500000,"quality":0.92,"onlyOversize":false}',
    json_encode($on)
);

exit(harness_result());
