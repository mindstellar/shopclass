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
 * Regenerates the endpoint reference in docs/site/developers/api/reference.md from the same
 * OpenAPI document tools/gen-openapi.php writes. Only the region between the markers is
 * rewritten. CI runs it with --check.
 *
 * Usage: php tools/gen-api-doc.php [--check]
 */

declare(strict_types=1);

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require __DIR__ . '/lib/docgen.php';

const REFERENCE = ABS_PATH . 'docs/site/developers/api/reference.md';
const BEGIN     = '<!-- generated:api -->';
const END       = '<!-- /generated:api -->';

/**
 * A schema in a few words: its type, or the component it points at.
 *
 * @param array<string,mixed> $schema
 */
function api_doc_type(array $schema): string
{
    if (isset($schema['$ref'])) {
        return '`' . substr((string) $schema['$ref'], strlen(mindstellar\api\schema\Validator::REF_PREFIX)) . '`';
    }
    $type = implode(' or ', (array) ($schema['type'] ?? ['any']));
    if (isset($schema['enum'])) {
        $type .= ': ' . implode(', ', array_map(static fn ($v): string => '`' . $v . '`', (array) $schema['enum']));
    }

    return $type;
}

function api_doc_cell(string $text): string
{
    return str_replace(['|', "\n"], ['\|', ' '], $text);
}

$doc = mindstellar\api\schema\OpenApi::core()->build();

$byTag = [];
foreach ($doc['paths'] as $path => $operations) {
    foreach ($operations as $method => $op) {
        $byTag[$op['tags'][0] ?? 'Other'][] = [strtoupper($method), $path, $op];
    }
}
ksort($byTag);

$out = [BEGIN, ''];
foreach ($byTag as $tag => $operations) {
    $out[] = '## ' . $tag;
    $out[] = '';
    $out[] = '| Method | Path | Auth | Scope | Summary |';
    $out[] = '|---|---|---|---|---|';
    foreach ($operations as [$method, $path, $op]) {
        $out[] = '| ' . $method . ' | `' . $path . '` | ' . $op['x-auth'] . ' | ' . ($op['x-scope'] === null ? '-' : '`' . $op['x-scope'] . '`')
            . ' | ' . api_doc_cell($op['summary'] ?? '') . ' |';
    }
    $out[] = '';
    foreach ($operations as [$method, $path, $op]) {
        $out[] = '### ' . $method . ' `' . $path . '`';
        $out[] = '';
        if (($op['summary'] ?? '') !== '') {
            $out[] = $op['summary'] . (isset($op['deprecated']) ? ' **Deprecated.**' : '');
            $out[] = '';
        }
        if (!empty($op['parameters'])) {
            $out[] = '| Parameter | In | Type | Required | Notes |';
            $out[] = '|---|---|---|---|---|';
            foreach ($op['parameters'] as $p) {
                $out[] = '| `' . $p['name'] . '` | ' . $p['in'] . ' | ' . api_doc_cell(api_doc_type($p['schema'])) . ' | '
                    . ($p['required'] ? 'yes' : 'no') . ' | ' . api_doc_cell($p['description'] ?? '') . ' |';
            }
            $out[] = '';
        }
        if (isset($op['requestBody']['content']['application/json'])) {
            $out[] = 'Body: ' . api_doc_type($op['requestBody']['content']['application/json']['schema']) . ' as JSON' . ($op['requestBody']['required'] ? '' : ', optional') . '.';
            $out[] = '';
        } elseif (isset($op['requestBody'])) {
            $out[] = 'Body: one image, as multipart/form-data in the field `photo` or as the raw image.';
            $out[] = '';
        }
        $answers = [];
        foreach ($op['responses'] as $status => $response) {
            $schema    = isset($response['content']) ? reset($response['content'])['schema'] : null;
            $answers[] = $status . ' ' . $response['description'] . ($schema === null || $status >= 400 ? '' : ' (' . api_doc_type($schema) . ')');
        }
        $out[] = 'Answers: ' . implode('; ', $answers) . '.';
        $out[] = '';
    }
}
$out[] = END;
$block = implode("\n", $out);

if (!is_file(REFERENCE)) {
    file_put_contents(REFERENCE, "---\ntitle: API reference\ndescription: \"Every REST API endpoint: method, path, credential, scope, parameters and answers.\"\nsidebar:\n  order: 3\n---\n\n"
        . "Generated from the route table by `tools/gen-api-doc.php`. The same endpoints are in\n"
        . "[openapi.json](https://github.com/mindstellar/shopclass/blob/master/docs/site/developers/api/openapi.json)\n"
        . "for Swagger UI, Redoc or Postman. A site also serves its own copy, with its plugins'\n"
        . "endpoints, at `/api/v1/openapi.json`.\n\n" . BEGIN . "\n" . END . "\n");
}

exit(docgen_splice(REFERENCE, BEGIN, END, $block, 'tools/gen-api-doc.php', $argv, 'Wrote docs/site/developers/api/reference.md'));
