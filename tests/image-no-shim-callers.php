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
 * Pins that core never uses the deprecated ImageProcessing or ImageResizer shim, so it never
 * reports itself deprecated. Only the shim and the ImageResizer alias name them.
 *
 * Usage: php tests/image-no-shim-callers.php
 */

require_once __DIR__ . '/lib/harness.php';

$root    = dirname(__DIR__);
$allowed = array('oc-includes/osclass/classes/ImageProcessing.php', 'oc-includes/osclass/compatibility.php');
$found   = array();
foreach (array('oc-includes', 'oc-admin') as $dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $path = substr($file->getPathname(), strlen($root) + 1);
        if ($file->getExtension() !== 'php' || in_array($path, $allowed, true)
            || str_starts_with($path, 'oc-includes/vendor/') || str_starts_with($path, 'oc-includes/assets/')
        ) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if ($path === 'oc-includes/classes/core/storage/ResourceUploader.php') {
            // writeSizes() still takes a wrapper, as 7.0 passed, and unwraps it at once.
            $source = str_replace(array('Image|\\ImageProcessing', 'instanceof \\ImageProcessing'), '', $source);
        }
        if (preg_match('/\b(ImageProcessing|ImageResizer)\b/', $source)) {
            $found[] = $path;
        }
    }
}

pin('no core file uses ImageProcessing or ImageResizer', array(), $found);

exit(harness_result());
