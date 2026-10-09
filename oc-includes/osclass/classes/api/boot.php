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
    static fn () => \mindstellar\api\Problem::maintenance()->send()
);

// Core events (a listing posted, a user registered ...) become webhooks for the endpoints
// that subscribe to them.
\mindstellar\api\serializer\EventData::listen();
