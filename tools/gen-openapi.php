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
 * Writes docs/site/developers/api/openapi.json: the REST API in OpenAPI 3.1, core routes
 * only, built from the route table. CI runs it with --check, so the file cannot drift from
 * what the kernel serves. A live site serves its own copy, with plugins, at /api/v1/openapi.json.
 *
 * Usage: php tools/gen-openapi.php [--check]
 */

declare(strict_types=1);

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';

const OPENAPI_FILE = ABS_PATH . 'docs/site/developers/api/openapi.json';

$json = json_encode(
    mindstellar\api\schema\OpenApi::core()->build(),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
) . "\n";

if (in_array('--check', $argv, true)) {
    if (!is_file(OPENAPI_FILE) || file_get_contents(OPENAPI_FILE) !== $json) {
        fwrite(STDERR, "docs/site/developers/api/openapi.json is stale. Run: php tools/gen-openapi.php\n");
        exit(1);
    }
    echo "openapi.json matches the route table.\n";
    exit(0);
}

file_put_contents(OPENAPI_FILE, $json);
echo "Wrote docs/site/developers/api/openapi.json\n";
