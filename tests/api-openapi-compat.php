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
 * The breaking-change gate: core's v1 OpenAPI document against the saved baseline. A removed
 * operation, success status, success header, member or enum value, a changed type or auth, or a
 * request member made required fails, as does a request constraint tightened or a response one
 * loosened; additions pass.
 * DB-free. Usage: php tests/api-openapi-compat.php [--write]   (--write saves a new baseline)
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\schema\OpenApi;

const OPENAPI_BASELINE = __DIR__ . '/fixtures/openapi-v1-baseline.json';

/** The schema keywords the gate reads; text such as descriptions is left out. */
const OA_KEYWORDS = [
    'type', 'enum', 'const', 'properties', 'required', 'items', 'additionalProperties', 'allOf', 'oneOf', 'anyOf',
    '$ref', 'format', 'minimum', 'maximum', 'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems',
];

/**
 * A schema with only OA_KEYWORDS, at every depth.
 *
 * @param mixed $schema
 *
 * @return mixed
 */
function oa_strip($schema)
{
    if (!is_array($schema)) {
        return $schema;
    }
    $out = [];
    foreach (OA_KEYWORDS as $k) {
        if (!array_key_exists($k, $schema)) {
            continue;
        }
        $v = $schema[$k];
        if ($k === 'properties') {
            $v = array_map('oa_strip', (array) $v);
        } elseif (in_array($k, ['allOf', 'oneOf', 'anyOf'], true)) {
            $v = array_map('oa_strip', (array) $v);
        } elseif ($k === 'items' || $k === 'additionalProperties') {
            $v = oa_strip($v);
        }
        $out[$k] = $v;
    }

    return $out;
}

/**
 * What the gate compares: operations, success answers, webhook payloads and component schemas.
 *
 * @param array<string,mixed> $doc
 *
 * @return array<string,mixed>
 */
function oa_shape(array $doc): array
{
    $ops = [];
    foreach ((array) $doc['paths'] as $path => $item) {
        foreach ((array) $item as $method => $op) {
            if (!is_array($op) || !isset($op['responses'])) {
                continue;
            }
            $params = [];
            foreach ($op['parameters'] ?? [] as $p) {
                $params[$p['in'] . ' ' . $p['name']] = ['required' => (bool) ($p['required'] ?? false), 'schema' => oa_strip($p['schema'] ?? [])];
            }
            $body = null;
            if (isset($op['requestBody'])) {
                $body = ['required' => (bool) ($op['requestBody']['required'] ?? false), 'content' => []];
                foreach ($op['requestBody']['content'] ?? [] as $type => $media) {
                    $body['content'][$type] = oa_strip($media['schema'] ?? []);
                }
            }
            $responses = [];
            $headers   = [];
            foreach ($op['responses'] as $status => $response) {
                if (((string) $status)[0] !== '2') {
                    continue;
                }
                $responses[(string) $status] = [];
                foreach ($response['content'] ?? [] as $type => $media) {
                    $responses[(string) $status][$type] = oa_strip($media['schema'] ?? []);
                }
                foreach ($response['headers'] ?? [] as $name => $header) {
                    $header = isset($header['$ref']) ? ($doc['components']['headers'][substr((string) $header['$ref'], strrpos((string) $header['$ref'], '/') + 1)] ?? []) : $header;

                    $headers[(string) $status][strtolower((string) $name)] = oa_strip($header['schema'] ?? []);
                }
            }
            $ops[strtoupper($method) . ' ' . $path] = [
                'auth' => $op['x-auth'] ?? null, 'scope' => $op['x-scope'] ?? null,
                'parameters' => $params, 'body' => $body, 'responses' => $responses, 'headers' => $headers,
            ];
        }
    }
    ksort($ops);
    $hooks = [];
    foreach ((array) ($doc['webhooks'] ?? []) as $event => $item) {
        $hooks[$event] = oa_strip($item['post']['requestBody']['content']['application/json']['schema'] ?? []);
    }
    ksort($hooks);

    return ['operations' => $ops, 'webhooks' => $hooks, 'schemas' => array_map('oa_strip', (array) ($doc['components']['schemas'] ?? []))];
}

/**
 * A schema with its $ref followed and its allOf parts merged, so a refactor into parts is no change.
 *
 * @param array<string,mixed> $schema
 * @param array<string,mixed> $schemas the document's component schemas
 *
 * @return array<string,mixed>
 */
function oa_flat(array $schema, array $schemas, int $depth = 0): array
{
    if ($depth > 20) {
        return $schema;
    }
    if (isset($schema['$ref'])) {
        $schema = (array) ($schemas[substr((string) $schema['$ref'], strrpos((string) $schema['$ref'], '/') + 1)] ?? []) + array_diff_key($schema, ['$ref' => 1]);
    }
    foreach ($schema['allOf'] ?? [] as $part) {
        $part                 = oa_flat((array) $part, $schemas, $depth + 1);
        $schema['properties'] = ($schema['properties'] ?? []) + ($part['properties'] ?? []);
        $schema['required']   = array_values(array_unique(array_merge($schema['required'] ?? [], $part['required'] ?? [])));
        $schema += array_diff_key($part, ['properties' => 1, 'required' => 1]);
    }
    unset($schema['allOf']);

    return $schema;
}

/**
 * @return string[]|null
 */
function oa_types(array $schema): ?array
{
    return isset($schema['type']) ? (array) $schema['type'] : null;
}

/**
 * Breaking differences between two schemas. $request: the client sends it, so narrowing breaks;
 * otherwise the client reads it, so widening a type or dropping a member breaks.
 *
 * @param array{0:array<string,mixed>,1:array<string,mixed>} $schemas old and new component schemas
 * @param array<string,true>                                 $seen    $ref pairs already compared
 *
 * @return string[]
 */
function oa_schema_breaks(array $old, array $new, bool $request, string $where, array $schemas, array &$seen = []): array
{
    $pair = ($request ? 'req' : 'res') . '|' . ($old['$ref'] ?? '') . '|' . ($new['$ref'] ?? '');
    if (isset($old['$ref']) || isset($new['$ref'])) {
        if (isset($seen[$pair])) {
            return [];
        }
        $seen[$pair] = true;
    }
    $old    = oa_flat($old, $schemas[0]);
    $new    = oa_flat($new, $schemas[1]);
    $breaks = [];

    [$ot, $nt] = [oa_types($old), oa_types($new)];
    if ($ot !== null && $nt !== null) {
        $added = array_diff($request ? $ot : $nt, $request ? $nt : $ot);
        $wider = $request ? $nt : $ot;
        $added = array_filter($added, static fn (string $t): bool => !($t === 'integer' && in_array('number', $wider, true)));
        if ($added !== []) {
            $breaks[] = $where . ': type ' . implode('|', $ot) . ' became ' . implode('|', $nt);
        }
    }
    $oe = $old['enum'] ?? (array_key_exists('const', $old) ? [$old['const']] : null);
    $ne = $new['enum'] ?? (array_key_exists('const', $new) ? [$new['const']] : null);
    if ($oe !== null && $ne !== null) {
        foreach ($oe as $value) {
            if (!in_array($value, $ne, true)) {
                $breaks[] = $where . ': value ' . json_encode($value) . ' was removed';
            }
        }
    }
    foreach ((array) ($old['properties'] ?? []) as $name => $schema) {
        if (!isset($new['properties'][$name])) {
            $breaks[] = $where . '.' . $name . ' was removed';
            continue;
        }
        array_push($breaks, ...oa_schema_breaks((array) $schema, (array) $new['properties'][$name], $request, $where . '.' . $name, $schemas, $seen));
    }
    if ($request) {
        foreach (array_diff($new['required'] ?? [], $old['required'] ?? []) as $name) {
            $breaks[] = $where . '.' . $name . ' is now required';
        }
    } else {
        foreach (array_diff($old['required'] ?? [], $new['required'] ?? []) as $name) {
            $breaks[] = $where . '.' . $name . ' is no longer always sent';
        }
    }
    if (is_array($old['items'] ?? null) && is_array($new['items'] ?? null)) {
        array_push($breaks, ...oa_schema_breaks($old['items'], $new['items'], $request, $where . '[]', $schemas, $seen));
    }
    if (is_array($old['additionalProperties'] ?? null) && is_array($new['additionalProperties'] ?? null)) {
        array_push($breaks, ...oa_schema_breaks($old['additionalProperties'], $new['additionalProperties'], $request, $where . '{}', $schemas, $seen));
    }
    array_push($breaks, ...oa_constraint_breaks($old, $new, $request, $where));
    // By content: each part the client may send (request) or must read (response) needs a match on the other side.
    foreach (['oneOf', 'anyOf'] as $k) {
        [$from, $to] = $request ? [(array) ($old[$k] ?? []), (array) ($new[$k] ?? [])] : [(array) ($new[$k] ?? []), (array) ($old[$k] ?? [])];
        if ($from === [] || $to === []) {
            continue;
        }
        foreach ($from as $i => $part) {
            $matched = false;
            foreach ($to as $other) {
                $trial = $seen;
                if (oa_schema_breaks($request ? (array) $part : (array) $other, $request ? (array) $other : (array) $part, $request, $where, $schemas, $trial) === []) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $breaks[] = $where . '.' . $k . ': ' . ($request ? 'part ' . $i . ' is no longer accepted' : 'new part ' . $i . ' matches no old one');
            }
        }
    }

    return $breaks;
}

/**
 * Bounds, patterns, enums, types and closed objects. A request may only widen and a response only narrow.
 *
 * @param array<string,mixed> $old
 * @param array<string,mixed> $new
 *
 * @return string[]
 */
function oa_constraint_breaks(array $old, array $new, bool $request, string $where): array
{
    // For a request the new side must not be stricter; for a response the old side.
    [$loose, $strict] = $request ? [$old, $new] : [$new, $old];
    $breaks           = [];
    foreach (['maxLength', 'maxItems', 'maximum'] as $k) {
        if (isset($strict[$k]) && (!isset($loose[$k]) || $strict[$k] < $loose[$k])) {
            $breaks[] = $where . ': ' . $k . ' ' . json_encode($old[$k] ?? null) . ' became ' . json_encode($new[$k] ?? null);
        }
    }
    foreach (['minLength', 'minItems', 'minimum'] as $k) {
        if (isset($strict[$k]) && (!isset($loose[$k]) || $strict[$k] > $loose[$k])) {
            $breaks[] = $where . ': ' . $k . ' ' . json_encode($old[$k] ?? null) . ' became ' . json_encode($new[$k] ?? null);
        }
    }
    if (isset($strict['pattern']) && ($loose['pattern'] ?? null) !== $strict['pattern']) {
        $breaks[] = $where . ': pattern ' . json_encode($old['pattern'] ?? null) . ' became ' . json_encode($new['pattern'] ?? null);
    }
    foreach (['enum', 'type'] as $k) {
        $has = static fn (array $s): bool => $k === 'enum' ? isset($s['enum']) || array_key_exists('const', $s) : isset($s[$k]);
        if ($has($strict) && !$has($loose)) {
            $breaks[] = $where . ': ' . ($request ? 'now has' : 'no longer has') . ($k === 'enum' ? ' an enum' : ' a type');
        }
    }
    if ($request && ($new['additionalProperties'] ?? null) === false && ($old['additionalProperties'] ?? null) !== false) {
        $breaks[] = $where . ': unknown members are now refused';
    }

    return $breaks;
}

/**
 * Every breaking change from the old shape to the new one, as short sentences. Empty when the new
 * one only adds.
 *
 * @param array<string,mixed> $old from oa_shape()
 * @param array<string,mixed> $new from oa_shape()
 *
 * @return string[]
 */
function oa_breaks(array $old, array $new): array
{
    $schemas = [$old['schemas'], $new['schemas']];
    $breaks  = [];
    foreach ($old['operations'] as $key => $op) {
        $now = $new['operations'][$key] ?? null;
        if ($now === null) {
            $breaks[] = $key . ' was removed';
            continue;
        }
        if ($op['auth'] !== $now['auth'] || $op['scope'] !== $now['scope']) {
            $breaks[] = $key . ': auth ' . $op['auth'] . ' ' . $op['scope'] . ' became ' . $now['auth'] . ' ' . $now['scope'];
        }
        foreach ($op['parameters'] as $name => $param) {
            if (!isset($now['parameters'][$name])) {
                $breaks[] = $key . ': parameter ' . $name . ' was removed';
                continue;
            }
            array_push($breaks, ...oa_schema_breaks($param['schema'], $now['parameters'][$name]['schema'], true, $key . ' ' . $name, $schemas));
        }
        foreach ($now['parameters'] as $name => $param) {
            if ($param['required'] && !($op['parameters'][$name]['required'] ?? false)) {
                $breaks[] = $key . ': parameter ' . $name . ' is now required';
            }
        }
        if ($op['body'] === null && ($now['body']['required'] ?? false)) {
            $breaks[] = $key . ': a request body is now required';
        } elseif ($op['body'] !== null) {
            if ($now['body'] === null) {
                $breaks[] = $key . ': the request body is no longer taken';
            } else {
                if ($now['body']['required'] && !$op['body']['required']) {
                    $breaks[] = $key . ': the request body is now required';
                }
                foreach ($op['body']['content'] as $type => $schema) {
                    if (!isset($now['body']['content'][$type])) {
                        $breaks[] = $key . ': a ' . $type . ' body is no longer taken';
                        continue;
                    }
                    array_push($breaks, ...oa_schema_breaks($schema, $now['body']['content'][$type], true, $key . ' body', $schemas));
                }
            }
        }
        foreach ($op['headers'] ?? [] as $status => $headers) {
            foreach ($headers as $name => $schema) {
                if (!isset($now['headers'][$status][$name])) {
                    $breaks[] = $key . ': ' . $status . ' no longer sends ' . $name;
                    continue;
                }
                array_push($breaks, ...oa_schema_breaks($schema, $now['headers'][$status][$name], false, $key . ' ' . $status . ' header ' . $name, $schemas));
            }
        }
        foreach ($op['responses'] as $status => $content) {
            if (!isset($now['responses'][$status])) {
                $breaks[] = $key . ': status ' . $status . ' was removed';
                continue;
            }
            foreach ($content as $type => $schema) {
                if (!isset($now['responses'][$status][$type])) {
                    $breaks[] = $key . ': ' . $status . ' no longer answers ' . $type;
                    continue;
                }
                array_push($breaks, ...oa_schema_breaks($schema, $now['responses'][$status][$type], false, $key . ' ' . $status, $schemas));
            }
        }
    }
    foreach ($old['webhooks'] as $event => $schema) {
        if (!isset($new['webhooks'][$event])) {
            $breaks[] = 'webhook ' . $event . ' was removed';
            continue;
        }
        array_push($breaks, ...oa_schema_breaks($schema, $new['webhooks'][$event], false, 'webhook ' . $event, $schemas));
    }

    return array_values(array_unique($breaks));
}

$current = oa_shape(OpenApi::core('v1')->build());
if (in_array('--write', $argv, true)) {
    // One entry per line, so a diff shows only the operations, webhooks and schemas that changed.
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    $parts = [];
    foreach ($current as $group => $entries) {
        ksort($entries);
        $lines   = array_map(static fn (string $key, $entry): string => '    ' . json_encode($key, $flags) . ': ' . json_encode($entry, $flags), array_keys($entries), $entries);
        $parts[] = '  ' . json_encode($group, $flags) . ": {\n" . implode(",\n", $lines) . "\n  }";
    }
    file_put_contents(OPENAPI_BASELINE, "{\n" . implode(",\n", $parts) . "\n}\n");
    echo 'Wrote ' . OPENAPI_BASELINE . "\n";
    exit(0);
}

harness_section('the gate');
$op   = static fn (array $schema, array $extra = []): array => ['operations' => ['GET x' => $extra + [
    'auth' => 'public', 'scope' => 'listings:read', 'parameters' => [], 'body' => null,
    'responses' => ['200' => ['application/json' => $schema]],
]], 'webhooks' => [], 'schemas' => ['Thing' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]]]];
$base = $op(['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => ['a', 'b']], 'thing' => ['$ref' => '#/components/schemas/Thing']]]);
$with = static function (array $doc, callable $change): array {
    $change($doc['operations']['GET x']);

    return $doc;
};
pin('the same document has no break', [], oa_breaks($base, $base));
$added = $with($base, static function (array &$o): void {
    $o['responses']['200']['application/json']['properties']['extra'] = ['type' => 'string'];
    $o['responses']['200']['application/json']['properties']['status']['enum'][] = 'c';
});
$added['operations']['GET y'] = $added['operations']['GET x'];
pin('an added member, value and operation pass', [], oa_breaks($base, $added));
pin('a removed member breaks', ['GET x 200.status was removed'], oa_breaks($base, $with($base, static function (array &$o): void {
    unset($o['responses']['200']['application/json']['properties']['status']);
})));
pin('a removed enum value breaks', ['GET x 200.status: value "b" was removed'], oa_breaks($base, $with($base, static function (array &$o): void {
    $o['responses']['200']['application/json']['properties']['status']['enum'] = ['a'];
})));
pin('a changed type breaks', ['GET x 200.id: type integer became string'], oa_breaks($base, $with($base, static function (array &$o): void {
    $o['responses']['200']['application/json']['properties']['id']['type'] = 'string';
})));
pin('a member removed inside a $ref breaks', ['GET x 200.thing.n was removed'], oa_breaks($base, ['schemas' => ['Thing' => ['type' => 'object']]] + $base));
pin('a changed status and a removed operation break', ['GET x: status 200 was removed', 'GET y was removed'], oa_breaks(
    ['operations' => $base['operations'] + ['GET y' => $base['operations']['GET x']]] + $base,
    $with($base, static function (array &$o): void {
        $o['responses'] = ['201' => $o['responses']['200']];
    })
));
$body = $with($base, static function (array &$o): void {
    $o['body'] = ['required' => true, 'content' => ['application/json' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]];
    $o['parameters']['query q'] = ['required' => false, 'schema' => ['type' => 'string']];
});
pin('a request member or parameter turned required breaks', ['GET x: parameter query q is now required', 'GET x body.a is now required'], oa_breaks($body, $with($body, static function (array &$o): void {
    $o['body']['content']['application/json']['required'] = ['a'];
    $o['parameters']['query q']['required'] = true;
})));
pin('a changed auth breaks', ['GET x: auth public listings:read became user listings:read'], oa_breaks($base, $with($base, static function (array &$o): void {
    $o['auth'] = 'user';
})));

harness_section('the gate: constraints, alternatives and headers');
$req  = static fn (callable $change): array => $with($body, static function (array &$o) use ($change): void {
    $change($o['body']['content']['application/json']['properties']['a']);
});
$res  = static fn (callable $change): array => $with($base, static function (array &$o) use ($change): void {
    $change($o['responses']['200']['application/json']['properties']['status']);
});
$bodyA = $req(static function (array &$a): void {
    $a += ['maxLength' => 10, 'minimum' => 1, 'pattern' => '^x', 'oneOf' => [['type' => 'string'], ['type' => 'integer']]];
});
pin('a request bound tightened breaks', ['GET x body.a: maxLength 10 became 5', 'GET x body.a: minimum 1 became 2'], oa_breaks($bodyA, $with($bodyA, static function (array &$o): void {
    $o['body']['content']['application/json']['properties']['a'] = ['maxLength' => 5, 'minimum' => 2] + $o['body']['content']['application/json']['properties']['a'];
})));
pin('a request bound loosened or dropped passes', [], oa_breaks($bodyA, $with($bodyA, static function (array &$o): void {
    $o['body']['content']['application/json']['properties']['a']['maxLength'] = 20;
    unset($o['body']['content']['application/json']['properties']['a']['minimum']);
})));
pin('a request bound, pattern, enum or type newly added breaks', [
    'GET x body.a: maxItems null became 3', 'GET x body.a: minLength null became 1', 'GET x body.a: pattern null became "^a"', 'GET x body.a: now has an enum',
], oa_breaks($body, $req(static function (array &$a): void {
    $a += ['maxItems' => 3, 'minLength' => 1, 'pattern' => '^a', 'enum' => ['x']];
})));
pin('a request type newly added breaks', ['GET x body: now has a type'], oa_breaks(
    $with($body, static function (array &$o): void {
        unset($o['body']['content']['application/json']['type']);
    }),
    $body
));
pin('a changed request pattern breaks', ['GET x body.a: pattern "^x" became "^y"'], oa_breaks($bodyA, $with($bodyA, static function (array &$o): void {
    $o['body']['content']['application/json']['properties']['a']['pattern'] = '^y';
})));
pin('a request maximum lowered or minItems raised breaks', ['GET x body.a: maximum 10 became 5', 'GET x body.a: minItems 1 became 2'], oa_breaks(
    $req(static function (array &$a): void {
        $a += ['maximum' => 10, 'minItems' => 1];
    }),
    $req(static function (array &$a): void {
        $a += ['maximum' => 5, 'minItems' => 2];
    })
));
pin('a request const newly added breaks', ['GET x body.a: now has an enum'], oa_breaks($body, $req(static function (array &$a): void {
    $a['const'] = 'x';
})));
pin('a request object newly closed breaks', ['GET x body: unknown members are now refused'], oa_breaks($body, $with($body, static function (array &$o): void {
    $o['body']['content']['application/json']['additionalProperties'] = false;
})));
pin('request alternatives are matched by content, not position', [], oa_breaks($bodyA, $with($bodyA, static function (array &$o): void {
    $o['body']['content']['application/json']['properties']['a']['oneOf'] = [['type' => 'integer'], ['type' => 'boolean'], ['type' => 'string']];
})));
pin('a request alternative dropped breaks', ['GET x body.a.oneOf: part 1 is no longer accepted'], oa_breaks($bodyA, $with($bodyA, static function (array &$o): void {
    $o['body']['content']['application/json']['properties']['a']['oneOf'] = [['type' => 'string']];
})));
$resA = $res(static function (array &$s): void {
    $s += ['maxLength' => 10, 'minLength' => 1, 'pattern' => '^[ab]$', 'oneOf' => [['type' => 'string'], ['type' => 'null']]];
});
pin('a response bound tightened passes', [], oa_breaks($resA, $with($resA, static function (array &$o): void {
    $o['responses']['200']['application/json']['properties']['status'] = ['maxLength' => 5, 'minLength' => 2] + $o['responses']['200']['application/json']['properties']['status'];
})));
pin('a response bound loosened or a restriction dropped breaks', [
    'GET x 200.status: maxLength 10 became 20', 'GET x 200.status: minLength 1 became null', 'GET x 200.status: pattern "^[ab]$" became null', 'GET x 200.status: no longer has an enum',
], oa_breaks($resA, $with($resA, static function (array &$o): void {
    $o['responses']['200']['application/json']['properties']['status']['maxLength'] = 20;
    unset($o['responses']['200']['application/json']['properties']['status']['minLength'], $o['responses']['200']['application/json']['properties']['status']['pattern'], $o['responses']['200']['application/json']['properties']['status']['enum']);
})));
pin('a response alternative added breaks; reordered passes', ['GET x 200.status.oneOf: new part 2 matches no old one'], oa_breaks($resA, $with($resA, static function (array &$o): void {
    $o['responses']['200']['application/json']['properties']['status']['oneOf'] = [['type' => 'null'], ['type' => 'string'], ['type' => 'integer']];
})));
pin('a response alternative dropped passes', [], oa_breaks($resA, $with($resA, static function (array &$o): void {
    $o['responses']['200']['application/json']['properties']['status']['oneOf'] = [['type' => 'string']];
})));
pin('a response const dropped breaks', ['GET x 200.status: no longer has an enum'], oa_breaks(
    $res(static function (array &$s): void {
        unset($s['enum']);
        $s['const'] = 'a';
    }),
    $res(static function (array &$s): void {
        unset($s['enum']);
    })
));
$headed = $with($base, static function (array &$o): void {
    $o['headers'] = ['200' => ['etag' => ['type' => 'string']]];
});
pin('a 2xx header dropped or retyped breaks; one added passes', ['GET x: 200 no longer sends etag', 'GET x 200 header etag: type string became integer'], [
    ...oa_breaks($headed, $base),
    ...oa_breaks($headed, $with($headed, static function (array &$o): void {
        $o['headers']['200'] = ['etag' => ['type' => 'integer'], 'location' => ['type' => 'string']];
    })),
]);
check('core 2xx answers have their headers in the shape', isset($current['operations']['GET /listings/{id}']['headers']['200']['etag']['type']));

harness_section('core v1 against the baseline');
$baseline = is_file(OPENAPI_BASELINE) ? json_decode((string) file_get_contents(OPENAPI_BASELINE), true) : null;
check('the baseline exists', is_array($baseline));
pin('no breaking change since the baseline; if v1 is meant to change, save a new one with php tests/api-openapi-compat.php --write', [], is_array($baseline) ? oa_breaks($baseline, $current) : ['no baseline']);

exit(harness_result());
