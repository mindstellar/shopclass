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
 * A Docker start refreshes the language files the image bundles, and leaves alone a file the
 * site changed and every file the image does not ship (a downloaded language, custom.po).
 *
 * DB-free.  Usage: php tests/package-reconcile-languages.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\market\PackageReconciler;

$root = sys_get_temp_dir() . '/osc-reconcile-lang-' . getmypid();
$img  = $root . '/image/';
$live = $root . '/live/';
register_shutdown_function(static function () use ($root) {
    exec('rm -rf ' . escapeshellarg($root));
});

$put = static function (string $path, string $body): void {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $body);
};

// First start: the volume was seeded from this image, so the files match.
$put($img . 'en_US/mail.json', 'mail v1');
$put($img . 'en_US/core.po', 'core v1');
$put($img . 'core.pot', 'pot v1');
$put($live . 'en_US/mail.json', 'mail v1');
$put($live . 'en_US/core.po', 'core v1');
$put($live . 'core.pot', 'pot v1');
$put($live . 'hi_IN/core.po', 'hindi download');
$put($live . 'en_US/custom.po', 'owner text');

pin('a start on a fresh volume writes nothing', array(), PackageReconciler::reconcileLanguages($img, $live));
check('...and records what the image shipped', is_file($live . '.bundled.json'));

// A newer image ships new e-mail templates and a new file.
$put($img . 'en_US/mail.json', 'mail v2');
$put($img . 'en_US/theme.po', 'theme v2');
// The owner changed core.po on this site; the next image also changes it.
$put($live . 'en_US/core.po', 'core edited here');
$put($img . 'en_US/core.po', 'core v2');

$log = PackageReconciler::reconcileLanguages($img, $live);
pin('a newer bundled file replaces the one the last image wrote', 'mail v2', file_get_contents($live . 'en_US/mail.json'));
pin('a file the image adds is installed', 'theme v2', file_get_contents($live . 'en_US/theme.po'));
pin('a file the site changed is kept', 'core edited here', file_get_contents($live . 'en_US/core.po'));
pin('a downloaded language is not touched', 'hindi download', file_get_contents($live . 'hi_IN/core.po'));
pin('the owner\'s custom file is not touched', 'owner text', file_get_contents($live . 'en_US/custom.po'));
sort($log);
pin('the log names each action', array(
    'installed language file en_US/theme.po',
    'kept language file en_US/core.po (changed on this site)',
    'refreshed language file en_US/mail.json',
), $log);

pin('a second start with the same image writes nothing new', array('kept language file en_US/core.po (changed on this site)'), PackageReconciler::reconcileLanguages($img, $live));

// A language update over a bundled file counts as a site change.
$put($live . 'en_US/mail.json', 'mail from Settings > Languages');
$put($img . 'en_US/mail.json', 'mail v3');
PackageReconciler::reconcileLanguages($img, $live);
pin('a language update the site downloaded is kept over the image', 'mail from Settings > Languages', file_get_contents($live . 'en_US/mail.json'));

pin('no image copy means nothing to do', array(), PackageReconciler::reconcileLanguages($root . '/missing', $live));

exit(harness_result());
