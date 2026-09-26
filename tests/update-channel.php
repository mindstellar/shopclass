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
pin(
    'stable skips a release GitHub marks as a prerelease',
    '6.3.0',
    ReleaseChannel::pick(array($release('6.3.0'), array('tag_name' => '6.3.1', 'prerelease' => true)), ReleaseChannel::STABLE)['tag_name'] ?? null
);
pin('an object in the list is skipped', '6.3.0', ReleaseChannel::pick(array((object) array('tag_name' => '9.9.9'), $release('6.3.0')), ReleaseChannel::STABLE)['tag_name'] ?? null);
pin('a GitHub error body picks nothing', null, ReleaseChannel::pick(array('message' => 'API rate limit exceeded'), ReleaseChannel::STABLE));

$info = array('s_new_version' => '6.4.2', 's_sha256' => str_repeat('a', 64));
pin('a last-number rise installs', null, AutoSecurityUpdate::refusal($info, '6.4.1', ''));
pin('from a dev build of the same version too', null, AutoSecurityUpdate::refusal($info, '6.4.0.dev', ''));
pin('not when already on it', 'not a security release for this version', AutoSecurityUpdate::refusal($info, '6.4.2', ''));
pin('not from a newer build', 'not a security release for this version', AutoSecurityUpdate::refusal($info, '6.4.3', ''));
pin('not a new X.Y', 'not a security release for this version', AutoSecurityUpdate::refusal($info, '6.3.9', ''));
pin('not a new X', 'not a security release for this version', AutoSecurityUpdate::refusal(array('s_new_version' => '7.4.2') + $info, '6.4.1', ''));
pin('6.4.10 is a rise from 6.4.9', null, AutoSecurityUpdate::refusal(array('s_new_version' => '6.4.10') + $info, '6.4.9', ''));
pin('not a release candidate', 'not a stable release', AutoSecurityUpdate::refusal(array('s_new_version' => '6.4.2.rc1') + $info, '6.4.1', ''));
pin('not rc to its stable release', 'not a security release for this version', AutoSecurityUpdate::refusal(array('s_new_version' => '6.4.0') + $info, '6.4.0.rc2', ''));
pin('not without a checksum', 'no checksum', AutoSecurityUpdate::refusal(array('s_new_version' => '6.4.2'), '6.4.1', ''));
pin('not with a checksum that is not one', 'no checksum', AutoSecurityUpdate::refusal(array('s_sha256' => 'x') + $info, '6.4.1', ''));
pin('not twice', 'already tried', AutoSecurityUpdate::refusal($info, '6.4.1', '6.4.2'));

exit(harness_result());
