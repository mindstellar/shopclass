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
 * Writes oc-includes/classes/user/AddressFormatData.php from Google's address metadata
 * (the data behind libaddressinput, CC BY 4.0). Run it by hand when that data changes; sites never
 * fetch it.
 *
 * Usage: php tools/gen-address-formats.php --cache=DIR [--offline]
 *   --cache=DIR  where the downloaded country files are kept; missing ones are fetched
 *   --offline    use only what is in DIR
 */

declare(strict_types=1);

const SOURCE = 'https://chromium-i18n.appspot.com/ssl-address/data';
const OUT    = __DIR__ . '/../oc-includes/classes/user/AddressFormatData.php';

$opts = getopt('', array('cache:', 'offline'));
if (empty($opts['cache'])) {
    fwrite(STDERR, "Usage: php tools/gen-address-formats.php --cache=DIR [--offline]\n");
    exit(2);
}
$cache   = rtrim((string) $opts['cache'], '/');
$offline = isset($opts['offline']);
is_dir($cache) || mkdir($cache, 0775, true);

/** One JSON document from the source, kept in the cache. */
function fetchJson(string $key, string $cache, bool $offline): array
{
    $file = $cache . '/' . ($key === '' ? '_index' : $key) . '.json';
    if (!is_file($file)) {
        if ($offline) {
            throw new RuntimeException("not cached: $file");
        }
        $ctx  = stream_context_create(array('http' => array('timeout' => 20, 'follow_location' => 1)));
        $body = @file_get_contents(SOURCE . ($key === '' ? '' : '/' . $key), false, $ctx);
        if ($body === false) {
            throw new RuntimeException("could not fetch $key");
        }
        file_put_contents($file, $body);
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        throw new RuntimeException("not JSON: $file");
    }

    return $data;
}

$codes = explode('~', (string) (fetchJson('', $cache, $offline)['countries'] ?? ''));
$zz    = fetchJson('ZZ', $cache, $offline);
$map   = array();
foreach ($codes as $code) {
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        continue;
    }
    $d   = fetchJson($code, $cache, $offline) + $zz;
    $fields = array();
    foreach (array_unique(preg_replace('/^%/', '', (array) (preg_match_all('/%[ACSZ]/', (string) $d['fmt'], $m) ? $m[0] : array()))) as $token) {
        $fields = array_merge($fields, match ($token) {
            'A' => array('line1', 'line2'),
            'C' => array('city'),
            'S' => array('state'),
            'Z' => array('postcode'),
        });
    }
    // Shopclass places every address in a city (locations, delivery zones, search), so a
    // country whose post leaves the city out still gets one, optional: after the state when the
    // state comes first (JP), else after the street.
    if (!in_array('city', $fields, true)) {
        $state = array_search('state', $fields, true);
        $after = $state !== false && $state < array_search('line1', $fields, true) ? $state : array_search('line2', $fields, true);
        array_splice($fields, (int) $after + 1, 0, array('city'));
    }
    $letters  = array('A' => 'line1', 'C' => 'city', 'S' => 'state', 'Z' => 'postcode');
    $required = array();
    foreach (str_split((string) ($d['require'] ?? '')) as $letter) {
        if (isset($letters[$letter]) && in_array($letters[$letter], $fields, true)) {
            $required[] = $letters[$letter];
        }
    }
    $pattern = null;
    if (in_array('postcode', $fields, true) && !empty($d['zip'])) {
        $pattern = '^(?:' . str_replace('/', '\/', (string) $d['zip']) . ')$';
        if (@preg_match('/' . $pattern . '/iu', '') === false) {
            fwrite(STDERR, "skipped a postcode pattern PCRE refuses: $code\n");
            $pattern = null;
        }
    }
    $map[$code] = array(
        $fields,
        array_values(array_unique($required)),
        in_array('state', $fields, true) ? (string) $d['state_name_type'] : null,
        in_array('postcode', $fields, true) ? (string) $d['zip_name_type'] : null,
        $pattern,
        $pattern === null ? '' : explode(',', (string) ($d['zipex'] ?? ''))[0],
    );
}
ksort($map);

$q   = static fn (?string $s): string => $s === null ? 'null' : "'" . addcslashes($s, "'\\") . "'";
$arr = static fn (array $list): string => 'array(' . implode(', ', array_map($q, $list)) . ')';
$rows = '';
foreach ($map as $code => $row) {
    $rows .= "        '$code' => array(" . $arr($row[0]) . ', ' . $arr($row[1]) . ', ' . $q($row[2]) . ', '
        . $q($row[3]) . ', ' . $q($row[4]) . ', ' . $q($row[5]) . "),\n";
}

$php = <<<PHP
<?php

/*
 * This file is part of Shopclass (Mindstellar).
 *
 * The address formats below are Google's address metadata (the data behind libaddressinput,
 * https://github.com/google/libaddressinput, served at https://chromium-i18n.appspot.com/ssl-address),
 * copyright Google LLC, licensed under CC BY 4.0: https://creativecommons.org/licenses/by/4.0/
 * Changed: reduced to field order, required fields, label types, postcode pattern and one example,
 * and converted to PHP. The data is provided as is, without warranties.
 *
 * Generated by tools/gen-address-formats.php. Do not edit by hand; Shopclass's own changes go in
 * AddressFormat::OVERRIDES.
 */

declare(strict_types=1);

namespace mindstellar\user;

/**
 * Per country: fields in order, required fields, state label type, postcode label type,
 * postcode pattern (anchored, valid in PCRE and JavaScript) and an example postcode.
 */
final class AddressFormatData
{
    public const MAP = array(
$rows    );
}

PHP;
file_put_contents(OUT, $php);
fwrite(STDERR, count($map) . " countries written to oc-includes/classes/user/AddressFormatData.php\n");
