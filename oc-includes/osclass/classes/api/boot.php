<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Plugs the REST API into core, which never names an API class itself. Loaded by hApi.php.
 */

\mindstellar\apikey\ApiAccess::connect(
    static fn () => \mindstellar\api\Kernel::serve(),
    static fn () => \mindstellar\api\identity\WebIdentity::forget(),
    static fn () => \mindstellar\api\Problem::maintenance()->send(),
    static fn (string $method, string $path, array $query, array $body): array => \mindstellar\api\InternalClient::site()->call($method, $path, $query, $body)
);

// Core events (a listing posted, a user registered ...) become webhooks for the endpoints
// that subscribe to them; with no endpoint on, the listeners wait until one is.
\mindstellar\webhook\WebhookEndpointStore::whenOn(static fn () => \mindstellar\api\serializer\EventData::listen());
