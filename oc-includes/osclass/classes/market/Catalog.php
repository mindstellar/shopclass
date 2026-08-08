<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\market;

use mindstellar\utility\FileSystem;

/**
 * Class Catalog
 *
 * Reads the static `v1/*.json` catalog published by the plugin/theme registry
 * (see docs/MARKET.md §5) — conditional GET with ETag/Last-Modified, a 24h
 * clock with a 1h retry on failure, and a raw.githubusercontent.com mirror
 * when GitHub Pages is unreachable. Every write goes to preferences and every
 * failure is non-fatal: a broken catalog degrades to stale-but-present data,
 * never a fatal error and never a reset "last checked" clock.
 *
 * This follows the same caching discipline as
 * `mindstellar\upgrade\Osclass::getPackageInfo()` /
 * `CAdminAjax::scheduleUpdateCheckRetry()` on purpose — one clock, one retry
 * rule, applied to a second surface.
 *
 * @package mindstellar\market
 */
final class Catalog
{
    private const TYPE_PLUGINS = 'plugins';
    private const TYPE_THEMES  = 'themes';

    private const RESOURCE_UPDATES = 'updates';
    private const RESOURCE_INDEX   = 'index';

    private const DAY_SECONDS   = 24 * 3600;
    private const RETRY_SECONDS = 3600;

    /** Connect + transfer budget for a single catalog request; these are small JSON files. */
    private const HTTP_TIMEOUT_SECONDS = 15;

    private string $type;

    private function __construct(string $type)
    {
        $this->type = $type;
    }

    public static function forPlugins(): self
    {
        return new self(self::TYPE_PLUGINS);
    }

    public static function forThemes(): self
    {
        return new self(self::TYPE_THEMES);
    }

    /**
     * `v1/updates.json` — every released version of every catalog package, newest-first.
     *
     * @param bool $force When true, always attempt a live check (conditional GET, so a
     *                     repeat call within the day is a cheap 304). When false, this
     *                     is a pure cache read unless no cache has ever been stored and
     *                     the day clock has elapsed — the same rule
     *                     `Osclass::getPackageInfo()` applies, so a caller that never
     *                     passes `true` never causes egress from a front-end request.
     *
     * @return array<string, array<int, array{version:string, requires:string,
     *              requires_php:string, tested:string, url:string, sha256:string,
     *              size:int}>>
     */
    public function updates(bool $force = false): array
    {
        return $this->fetch(self::RESOURCE_UPDATES, 'updates.json', $force);
    }

    /**
     * `v1/index.json` — slim browse rows, keyed by slug. The published file is a JSON
     * list (each row carries its own `slug`); this re-keys it so a caller never has to.
     *
     * @param bool $force see updates()
     *
     * @return array<string, array{slug:string, name:string, short_description:string,
     *              author:string, version:string, icon:?string, categories:array<int,string>,
     *              tags:array<int,string>, updated_at:string}>
     */
    public function index(bool $force = false): array
    {
        return $this->fetch(self::RESOURCE_INDEX, 'index.json', $force);
    }

    /**
     * `v1/packages/<slug>.json` — full detail for one package: rendered description,
     * screenshots, per-version changelog and compatibility, support links.
     *
     * Always attempts a conditional GET (this is only ever called for an explicit detail
     * view, never polled), falling back to the last-known-good cached copy on failure.
     *
     * @return array|null null when the slug has never been fetched successfully
     */
    public function detail(string $slug): ?array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $slug)) {
            return null;
        }

        $metaAll = $this->decodeMap(osc_get_preference($this->key('detail_meta')));
        $dataAll = $this->decodeMap(osc_get_preference($this->key('detail_data')));

        $meta    = $metaAll[$slug] ?? [];
        $source  = (string) ($meta['source'] ?? '');
        $etag    = (string) ($meta['etag'] ?? '');
        $lastMod = (string) ($meta['last_modified'] ?? '');

        $requestHeaders = [];
        if ($etag !== '') {
            $requestHeaders[] = 'If-None-Match: ' . $etag;
        }
        if ($lastMod !== '') {
            $requestHeaders[] = 'If-Modified-Since: ' . $lastMod;
        }

        $result = $this->requestFromSources('packages/' . $slug . '.json', $source, $requestHeaders);

        if ($result['ok'] && $result['status'] === 304) {
            $metaAll[$slug]['checked_at'] = time();
            osc_set_preference($this->key('detail_meta'), json_encode($metaAll));

            return $dataAll[$slug] ?? null;
        }

        if ($result['ok'] && $result['status'] === 200 && is_string($result['body']) && $result['body'] !== '') {
            $decoded = json_decode($result['body'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $sanitized = $this->sanitizeDetail($decoded, $slug);

                $metaAll[$slug] = [
                    'source'        => $result['source'],
                    'etag'          => $result['headers']['etag'] ?? '',
                    'last_modified' => $result['headers']['last-modified'] ?? '',
                    'checked_at'    => time(),
                ];
                $dataAll[$slug] = $sanitized;

                osc_set_preference($this->key('detail_meta'), json_encode($metaAll));
                osc_set_preference($this->key('detail_data'), json_encode($dataAll));

                return $sanitized;
            }
        }

        // Fetch failed (or returned junk): serve the last-known-good copy, if any —
        // never surface a transient outage as "this package doesn't exist".
        return $dataAll[$slug] ?? null;
    }

    /** Unix timestamp of the last updates.json check attempt (success or failure), 0 if never. */
    public function lastChecked(): int
    {
        $value = osc_get_preference($this->key(self::RESOURCE_UPDATES . '_checked_at'));

        return $value === '' ? 0 : (int) $value;
    }

    /** Message from the most recent failed updates.json check, or null when the last check was clean. */
    public function lastError(): ?string
    {
        $value = osc_get_preference($this->key(self::RESOURCE_UPDATES . '_error'));

        return $value === '' ? null : $value;
    }

    // ---- internals ----------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $resource, string $file, bool $force): array
    {
        $jsonKey = $this->key($resource . '_json');
        $cached  = $this->decodeMap(osc_get_preference($jsonKey));

        $hasCache    = osc_get_preference($jsonKey) !== '';
        $checkedAt   = (int) osc_get_preference($this->key($resource . '_checked_at'));
        $dueForCheck = (time() - $checkedAt) > self::DAY_SECONDS;

        // Cache-only read: this is the branch every front-end-reachable call must land
        // on. A live check only happens when the caller explicitly forces one, or on the
        // very first check this install has ever made (no cache yet and the day is up) —
        // exactly the condition `Osclass::getPackageInfo()` uses.
        if (!$force && !(!$hasCache && $dueForCheck)) {
            return $cached;
        }

        $storedSource  = osc_get_preference($this->key($resource . '_source'));
        $storedEtag    = osc_get_preference($this->key($resource . '_etag'));
        $storedLastMod = osc_get_preference($this->key($resource . '_last_modified'));

        $requestHeaders = [];
        if ($storedEtag !== '') {
            $requestHeaders[] = 'If-None-Match: ' . $storedEtag;
        }
        if ($storedLastMod !== '') {
            $requestHeaders[] = 'If-Modified-Since: ' . $storedLastMod;
        }

        $result = $this->requestFromSources($file, $storedSource, $requestHeaders);

        if ($result['ok'] && $result['status'] === 304) {
            osc_set_preference($this->key($resource . '_checked_at'), (string) time());
            osc_set_preference($this->key($resource . '_error'), '');

            return $cached;
        }

        if ($result['ok'] && $result['status'] === 200 && is_string($result['body']) && $result['body'] !== '') {
            $decoded = json_decode($result['body'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $sanitized = $resource === self::RESOURCE_UPDATES
                    ? $this->sanitizeUpdates($decoded)
                    : $this->sanitizeIndex($decoded);

                osc_set_preference($jsonKey, json_encode($sanitized));
                osc_set_preference($this->key($resource . '_etag'), $result['headers']['etag'] ?? '');
                osc_set_preference($this->key($resource . '_last_modified'), $result['headers']['last-modified'] ?? '');
                osc_set_preference($this->key($resource . '_source'), $result['source']);
                osc_set_preference($this->key($resource . '_checked_at'), (string) time());
                osc_set_preference($this->key($resource . '_error'), '');

                return $sanitized;
            }

            $this->fail($resource, 'Catalog returned invalid JSON.');

            return $cached;
        }

        $this->fail($resource, $result['error'] ?? 'Catalog unreachable.');

        return $cached;
    }

    /**
     * Records a failed check without touching the cached payload or its validators, and
     * schedules a retry about an hour out — the same back-dating trick
     * `CAdminAjax::scheduleUpdateCheckRetry()` uses, so a full 24h clock never hides a
     * release published during an outage.
     */
    private function fail(string $resource, string $message): void
    {
        osc_set_preference($this->key($resource . '_error'), $message);
        osc_set_preference(
            $this->key($resource . '_checked_at'),
            (string) (time() - (self::DAY_SECONDS - self::RETRY_SECONDS))
        );
    }

    /**
     * Tries the primary GitHub Pages host, then the raw.githubusercontent.com mirror.
     * Conditional headers are only sent to the source that produced them last time —
     * an ETag from Pages means nothing to the mirror's own cache.
     *
     * @return array{ok:bool, status:int, body:string|false, headers:array<string,string>,
     *               source:?string, error:?string}
     */
    private function requestFromSources(string $relativePath, string $storedSource, array $requestHeaders): array
    {
        $sources = [
            'primary' => $this->primaryBase() . $relativePath,
            'mirror'  => $this->mirrorBase() . $relativePath,
        ];

        $errors = [];
        foreach ($sources as $sourceName => $url) {
            $headers = $storedSource === $sourceName ? $requestHeaders : [];

            try {
                $responseInfo = null;
                $body         = (new FileSystem())->getContents(
                    $url,
                    null,
                    true,
                    self::HTTP_TIMEOUT_SECONDS,
                    $headers,
                    $responseInfo
                );
            } catch (\Throwable $e) {
                $errors[] = $sourceName . ': ' . $e->getMessage();
                continue;
            }

            $status = (int) ($responseInfo['status'] ?? 0);
            if ($body !== false && ($status === 200 || $status === 304)) {
                return [
                    'ok'      => true,
                    'status'  => $status,
                    'body'    => $body,
                    'headers' => $responseInfo['headers'] ?? [],
                    'source'  => $sourceName,
                    'error'   => null,
                ];
            }

            $errors[] = $sourceName . ': ' . ($body === false ? 'request failed' : ('HTTP ' . $status));
        }

        return [
            'ok'      => false,
            'status'  => 0,
            'body'    => false,
            'headers' => [],
            'source'  => null,
            'error'   => implode('; ', $errors),
        ];
    }

    private function primaryBase(): string
    {
        $default = $this->type === self::TYPE_PLUGINS
            ? 'https://mindstellar.github.io/shopclass-plugins/v1/'
            : 'https://mindstellar.github.io/shopclass-themes/v1/';

        return (string) osc_apply_filter('market_catalog_primary_base', $default, $this->type);
    }

    private function mirrorBase(): string
    {
        $default = $this->type === self::TYPE_PLUGINS
            ? 'https://raw.githubusercontent.com/mindstellar/shopclass-plugins/catalog/v1/'
            : 'https://raw.githubusercontent.com/mindstellar/shopclass-themes/catalog/v1/';

        return (string) osc_apply_filter('market_catalog_mirror_base', $default, $this->type);
    }

    private function key(string $suffix): string
    {
        return 'market_' . $this->type . '_' . $suffix;
    }

    /** @return array<string, mixed> */
    private function decodeMap(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Payload is untrusted: every field is type-checked before use and a version entry
     * whose `url` is not on the package-host allowlist is dropped rather than passed
     * through — a poisoned catalog must not be able to point an install anywhere.
     *
     * @return array<string, array<int, array{version:string, requires:string,
     *              requires_php:string, tested:string, url:string, sha256:string, size:int}>>
     */
    private function sanitizeUpdates($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $slug => $versions) {
            if (!is_string($slug) || $slug === '' || !is_array($versions)) {
                continue;
            }

            $clean = [];
            foreach ($versions as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $sanitized = $this->sanitizeVersionEntry($entry, $slug);
                if ($sanitized !== null) {
                    $clean[] = $sanitized;
                }
            }

            if ($clean === []) {
                continue;
            }

            // The catalog is expected to emit newest-first already; re-sort defensively
            // since the payload is untrusted and callers (Compatibility::pickBestVersion(),
            // update badges) rely on index 0 being the newest.
            usort($clean, static fn ($a, $b) => version_compare($b['version'], $a['version']));

            $result[$slug] = $clean;
        }

        return $result;
    }

    private function sanitizeVersionEntry(array $entry, string $slug): ?array
    {
        $version = $entry['version'] ?? null;
        if (!is_string($version) || $version === '' || !preg_match('/^\d+(\.\d+)*/', $version)) {
            return null;
        }

        $url = $entry['url'] ?? null;
        if (!is_string($url) || $url === '' || !FileSystem::isAllowedPackageHost($url)) {
            trigger_error(
                sprintf(
                    'Catalog: dropped %s %s — download url is missing or not on the allowed host list.',
                    $slug,
                    $version
                ),
                E_USER_WARNING
            );

            return null;
        }

        $sha256 = $entry['sha256'] ?? '';
        if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/i', $sha256)) {
            $sha256 = '';
        }

        $size = $entry['size'] ?? 0;
        $size = is_int($size) || is_float($size) ? max(0, (int) $size) : 0;

        return [
            'version'      => $version,
            'requires'     => is_string($entry['requires'] ?? null) ? $entry['requires'] : '',
            'requires_php' => is_string($entry['requires_php'] ?? null) ? $entry['requires_php'] : '',
            'tested'       => is_string($entry['tested'] ?? null) ? $entry['tested'] : '',
            'url'          => $url,
            'sha256'       => $sha256,
            'size'         => $size,
            'published_at' => is_string($entry['published_at'] ?? null) ? $entry['published_at'] : '',
        ];
    }

    /**
     * `index.json` is published as a JSON list (each row carries its own `slug`); this
     * re-keys it by slug and drops/blanks any field that fails validation.
     *
     * @return array<string, array{slug:string, name:string, short_description:string,
     *              author:string, version:string, icon:?string, categories:array<int,string>,
     *              tags:array<int,string>, updated_at:string}>
     */
    private function sanitizeIndex($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        // The published file is a JSON list; array_values() also tolerates an object
        // shape (string keys) since each row carries its own 'slug' field regardless.
        $rows = array_values($raw);

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = $row['slug'] ?? null;
            if (!is_string($slug) || !preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $slug)) {
                continue;
            }

            $icon = $row['icon'] ?? null;
            if (is_string($icon) && $icon !== '' && FileSystem::isAllowedPackageHost($icon)) {
                $iconValue = $icon;
            } else {
                if (is_string($icon) && $icon !== '') {
                    trigger_error(
                        sprintf('Catalog: dropped icon for "%s" — host not on the allowed list.', $slug),
                        E_USER_WARNING
                    );
                }
                $iconValue = null;
            }

            $result[$slug] = [
                'slug'              => $slug,
                'name'              => is_string($row['name'] ?? null) ? $row['name'] : $slug,
                'short_description' => is_string($row['short_description'] ?? null) ? $row['short_description'] : '',
                'author'            => is_string($row['author'] ?? null) ? $row['author'] : '',
                'version'           => is_string($row['version'] ?? null) ? $row['version'] : '',
                'icon'              => $iconValue,
                'categories'        => isset($row['categories']) && is_array($row['categories'])
                    ? array_values(array_filter($row['categories'], 'is_string'))
                    : [],
                'tags'              => isset($row['tags']) && is_array($row['tags'])
                    ? array_values(array_filter($row['tags'], 'is_string'))
                    : [],
                'updated_at'        => is_string($row['updated_at'] ?? null) ? $row['updated_at'] : '',
            ];
        }

        return $result;
    }

    /**
     * `v1/packages/<slug>.json` detail payload — same untrusted-input discipline as the
     * other two resources: image URLs go through the host allowlist, links are limited
     * to http(s), everything else is type-checked with a safe default.
     */
    private function sanitizeDetail(array $raw, string $slug): array
    {
        $icon = $raw['icon'] ?? null;
        $icon = (is_string($icon) && $icon !== '' && FileSystem::isAllowedPackageHost($icon)) ? $icon : null;

        $screenshots = [];
        if (isset($raw['screenshots']) && is_array($raw['screenshots'])) {
            foreach ($raw['screenshots'] as $shot) {
                if (!is_array($shot) || !is_string($shot['src'] ?? null)) {
                    continue;
                }
                if (!FileSystem::isAllowedPackageHost($shot['src'])) {
                    trigger_error(
                        sprintf('Catalog: dropped a screenshot for "%s" — host not on the allowed list.', $slug),
                        E_USER_WARNING
                    );
                    continue;
                }
                $screenshots[] = [
                    'src'     => $shot['src'],
                    'caption' => is_string($shot['caption'] ?? null) ? $shot['caption'] : '',
                ];
            }
        }

        $versions = [];
        if (isset($raw['versions']) && is_array($raw['versions'])) {
            foreach ($raw['versions'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $sanitized = $this->sanitizeVersionEntry($entry, $slug);
                if ($sanitized !== null) {
                    $sanitized['changelog_html'] = is_string($entry['changelog_html'] ?? null)
                        ? $entry['changelog_html']
                        : '';
                    $versions[] = $sanitized;
                }
            }
            usort($versions, static fn ($a, $b) => version_compare($b['version'], $a['version']));
        }

        // `support` carries issues/docs; homepage and the source repository are top-level and
        // were previously dropped, leaving the detail view with no link back to the project.
        $links = [];
        if (isset($raw['support']) && is_array($raw['support'])) {
            foreach ($raw['support'] as $name => $value) {
                if (is_string($name) && is_string($value) && preg_match('#^https?://#i', $value)) {
                    $links[$name] = $value;
                }
            }
        }
        if (is_string($raw['homepage'] ?? null) && preg_match('#^https?://#i', $raw['homepage'])) {
            $links['homepage'] = $raw['homepage'];
        }
        if (isset($raw['source']['repo']) && is_string($raw['source']['repo'])
            && preg_match('#^[\\w.-]+/[\\w.-]+$#', $raw['source']['repo'])
        ) {
            $links['repo'] = 'https://github.com/' . $raw['source']['repo'];
        }

        return [
            'slug'              => $slug,
            'name'              => is_string($raw['name'] ?? null) ? $raw['name'] : $slug,
            'author'            => is_string($raw['author'] ?? null) ? $raw['author'] : '',
            'short_description' => is_string($raw['short_description'] ?? null) ? $raw['short_description'] : '',
            'description_html'  => is_string($raw['description_html'] ?? null) ? $raw['description_html'] : '',
            'icon'              => $icon,
            'screenshots'       => $screenshots,
            'categories'        => isset($raw['categories']) && is_array($raw['categories'])
                ? array_values(array_filter($raw['categories'], 'is_string'))
                : [],
            'tags'              => isset($raw['tags']) && is_array($raw['tags'])
                ? array_values(array_filter($raw['tags'], 'is_string'))
                : [],
            'links'             => $links,
            'versions'          => $versions,
            'updated_at'        => is_string($raw['updated_at'] ?? null) ? $raw['updated_at'] : '',
        ];
    }
}
