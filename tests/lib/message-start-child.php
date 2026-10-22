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
 * One first message to a listing, for tests/models/messaging-service.php. Prints "started",
 * "continued" or "threw <message>".
 *
 * Usage:  php tests/lib/message-start-child.php <database> <buyer id> <listing id>
 */

require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

[, $database, $buyer, $listing] = $argv;
$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';

$c = \mindstellar\container\Container::root();
$c->get(\mindstellar\event\DispatcherInterface::class)->remove(
    \mindstellar\messaging\event\MessageSent::class,
    [\mindstellar\messaging\MessageNotifier::class, 'onMessageSent']
);
try {
    $sent = $c->get(\mindstellar\messaging\MessagingService::class)
        ->start((int) $listing, 'Is it still for sale?', \mindstellar\auth\Actor::user((int) $buyer, '10.0.0.9'));
    echo $sent->started() ? "started\n" : "continued\n";
} catch (\Throwable $e) {
    echo 'threw ' . $e->getMessage() . "\n";
}
