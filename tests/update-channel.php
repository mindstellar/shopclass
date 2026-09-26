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
 * Pins which core releases each update channel is offered, and every rule that stops a
 * release from installing on its own.
 *
 * Usage: php tests/update-channel.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\upgrade\AutoSecurityUpdate;
use mindstellar\upgrade\ReleaseChannel;

$tags = array('6.3.0', '6.4.0.beta2', '6.4.0.rc1', '6.4.0.dev', 'v6.2.1', 'nightly');
foreach (array(
    ReleaseChannel::STABLE => array('6.3.0', 'v6.2.1'),
    ReleaseChannel::RC     => array('6.3.0', '6.4.0.rc1', 'v6.2.1'),
    ReleaseChannel::BETA   => array('6.3.0', '6.4.0.beta2', '6.4.0.rc1', 'v6.2.1'),
) as $channel => $expected) {
    pin("$channel takes", $expected, array_values(array_filter($tags, static fn ($t) => ReleaseChannel::allows($channel, $t))));
}

$release  = static fn (string $tag, bool $draft = false) => array('tag_name' => $tag, 'draft' => $draft);
$releases = array($release('6.3.0'), $release('6.4.0.rc1'), $release('6.4.0.beta2'), $release('6.4.0', true), $release('6.2.0'), 'junk');
pin('stable picks the newest stable, skipping a draft', '6.3.0', ReleaseChannel::pick($releases, ReleaseChannel::STABLE)['tag_name'] ?? null);
pin('rc picks the release candidate', '6.4.0.rc1', ReleaseChannel::pick($releases, ReleaseChannel::RC)['tag_name'] ?? null);
pin('beta picks the newest of them all', '6.4.0.rc1', ReleaseChannel::pick($releases, ReleaseChannel::BETA)['tag_name'] ?? null);
pin('a GitHub error body picks nothing', null, ReleaseChannel::pick(array('message' => 'API rate limit exceeded'), ReleaseChannel::STABLE));

$info     = array('s_new_version' => '6.4.2', 's_sha256' => str_repeat('a', 64));
$manifest = array('version' => '6.4.2', 'security' => true);
pin('a security patch for this X.Y installs', null, AutoSecurityUpdate::refusal($info, $manifest, '6.4.1', ''));
pin('not when already on it', 'no newer release', AutoSecurityUpdate::refusal($info, $manifest, '6.4.2', ''));
pin('not a new X.Y', 'a newer X.Y, which is never installed on its own', AutoSecurityUpdate::refusal($info, $manifest, '6.3.9', ''));
pin('not without a checksum', 'no checksum', AutoSecurityUpdate::refusal(array('s_new_version' => '6.4.2'), $manifest, '6.4.1', ''));
pin('not twice', 'already tried', AutoSecurityUpdate::refusal($info, $manifest, '6.4.1', '6.4.2'));
pin('not a release that is not security', 'not a security release', AutoSecurityUpdate::refusal($info, array('version' => '6.4.2', 'security' => false), '6.4.1', ''));
pin('not without a release.json', 'not a security release', AutoSecurityUpdate::refusal($info, null, '6.4.1', ''));
pin('not when release.json names another version', 'not a security release', AutoSecurityUpdate::refusal($info, array('version' => '6.4.3', 'security' => true), '6.4.1', ''));
pin('not a "true" string', 'not a security release', AutoSecurityUpdate::refusal($info, array('version' => '6.4.2', 'security' => 'true'), '6.4.1', ''));
pin('a beta to its rc on the same X.Y can', null, AutoSecurityUpdate::refusal(array('s_new_version' => '6.4.0.rc1', 's_sha256' => 'x'), array('version' => '6.4.0.rc1', 'security' => true), '6.4.0.beta2', ''));

exit(harness_result());
