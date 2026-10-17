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
 * Pins PackageManifest::read(): JSON, header, precedence, bad input and defaults.
 *
 * DB-free.  Usage:  php tests/package-manifest.php
 */

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\market\PackageManifest;

$dir = sys_get_temp_dir() . '/osc-manifest-' . bin2hex(random_bytes(4));
mkdir($dir);
$json = static function (?string $body) use ($dir): void {
    if ($body === null) {
        @unlink($dir . '/shopclass.json');
    } else {
        file_put_contents($dir . '/shopclass.json', $body);
    }
};
$none = ['required' => [], 'recommended' => []];

harness_section('defaults');
$json(null);
pin('absent gives classifieds and no plugins', ['modes' => ['classifieds'], 'plugins' => $none], PackageManifest::read($dir, ''));

harness_section('JSON only');
$json('{"modes":["shop"],"plugins":{"required":{"stripe":">=1.2.0"},"recommended":{"reviews":""}}}');
pin('modes and plugins', ['modes' => ['shop'], 'plugins' => ['required' => ['stripe' => '>=1.2.0'], 'recommended' => ['reviews' => '']]], PackageManifest::read($dir, ''));

harness_section('header only');
$json(null);
$h = "Modes: classifieds, shop\nRequires Plugins: stripe >=1.2.0, digital-goods\n";
pin('header form', ['modes' => ['classifieds', 'shop'], 'plugins' => ['required' => ['stripe' => '>=1.2.0', 'digital-goods' => ''], 'recommended' => []]], PackageManifest::read($dir, $h));
pin('both expands', ['classifieds', 'shop'], PackageManifest::read($dir, 'Modes: both')['modes']);

harness_section('both sources');
$json('{"modes":["shop"],"plugins":{"required":{"mollie":"1.0.0"}}}');
pin('JSON wins', ['modes' => ['shop'], 'plugins' => ['required' => ['mollie' => '1.0.0'], 'recommended' => []]], PackageManifest::read($dir, $h));

harness_section('bad input');
$json('{not json');
pin('bad JSON falls back to header', ['classifieds', 'shop'], PackageManifest::read($dir, $h)['modes']);
pin('bad JSON and no header is default', ['classifieds'], PackageManifest::read($dir, '')['modes']);
$json('{"modes":["nonsense"],"plugins":{"required":{"Bad Slug":"1.0","../x":"1","ok-one":"junk"}}}');
$r = PackageManifest::read($dir, '');
pin('unknown mode gives default', ['classifieds'], $r['modes']);
pin('bad slugs dropped, bad constraint becomes any', ['ok-one' => ''], $r['plugins']['required']);

@unlink($dir . '/shopclass.json');
rmdir($dir);

exit(harness_result());

/* file end: ./tests/package-manifest.php */
