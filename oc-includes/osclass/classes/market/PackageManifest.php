<?php

declare(strict_types=1);

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\market;

/**
 * Reads the `modes` and `plugins` a theme or plugin declares, from its `shopclass.json`
 * or, failing that, the `Modes:` and `Requires Plugins:` header lines.
 *
 * Bad input reads as absent and never throws.
 *
 * @package mindstellar\market
 */
final class PackageManifest
{
    public const MODES = ['classifieds', 'shop'];

    private const VERSION_PATTERN = '/^(?:>=|<=|>|<|=|\^|~)?\s*\d+(?:\.\d+){0,3}(?:-[0-9A-Za-z.]+)?$/';

    /**
     * @param string $dir    Package directory (with or without trailing slash).
     * @param string $header Text holding the package's header comment.
     *
     * @return array{modes: list<string>, plugins: array{required: array<string,string>, recommended: array<string,string>}}
     */
    public static function read(string $dir, string $header = ''): array
    {
        $json = self::readJson($dir);

        $modes = array_key_exists('modes', $json)
            ? self::cleanModes($json['modes'])
            : self::cleanModes(self::headerLine($header, 'Modes'));

        $plugins = ['required' => [], 'recommended' => []];
        if (isset($json['plugins']) && is_array($json['plugins'])) {
            $plugins['required']    = self::cleanPlugins($json['plugins']['required'] ?? []);
            $plugins['recommended'] = self::cleanPlugins($json['plugins']['recommended'] ?? []);
        } else {
            $plugins['required'] = self::cleanPlugins(self::headerLine($header, 'Requires Plugins'));
        }

        return ['modes' => $modes, 'plugins' => $plugins];
    }

    /**
     * @return array<string,mixed>
     */
    private static function readJson(string $dir): array
    {
        $file = rtrim($dir, '/') . '/shopclass.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    private static function headerLine(string $header, string $key): string
    {
        return preg_match('|' . $key . ':([^\r\n]*)|i', $header, $m) === 1 ? trim($m[1]) : '';
    }

    /**
     * @param mixed $value A list, or a comma separated string.
     *
     * @return list<string>
     */
    private static function cleanModes($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        $out = [];
        foreach (is_array($value) ? $value : [] as $mode) {
            $mode = is_string($mode) ? strtolower(trim($mode)) : '';
            if ($mode === 'both') {
                $out = array_merge($out, self::MODES);
            } elseif (in_array($mode, self::MODES, true)) {
                $out[] = $mode;
            }
        }
        $out = array_values(array_unique($out));

        return $out === [] ? ['classifieds'] : $out;
    }

    /**
     * @param mixed $value A slug => constraint map, or "slug >=1.2.0, other" string.
     *
     * @return array<string,string> slug => constraint ('' when any version will do)
     */
    private static function cleanPlugins($value): array
    {
        $pairs = [];
        if (is_string($value)) {
            foreach (explode(',', $value) as $part) {
                $bits = preg_split('/\s+/', trim($part), 2);
                if ($bits !== false && $bits[0] !== '') {
                    $pairs[$bits[0]] = $bits[1] ?? '';
                }
            }
        } elseif (is_array($value)) {
            $pairs = $value;
        }

        $out = [];
        foreach ($pairs as $slug => $constraint) {
            $slug = (string) $slug;
            if (preg_match(Installer::SLUG_PATTERN, $slug) !== 1) {
                continue;
            }
            $constraint = is_string($constraint) ? trim($constraint) : '';
            $out[$slug] = preg_match(self::VERSION_PATTERN, $constraint) === 1 ? $constraint : '';
        }

        return $out;
    }
}

/* file end: ./oc-includes/osclass/classes/market/PackageManifest.php */
