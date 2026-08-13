<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\location;

/**
 * Reads the published location catalog and tracks which version of each country this
 * install currently holds.
 *
 * The catalog manifest carries a content-derived version and a per-country checksum, so
 * "is my data current?" is answered by one small request and a string comparison — no
 * country file is downloaded until an admin asks for it. The version only moves when the
 * data actually changes, so a routine upstream rebuild that finds nothing new never
 * produces an update prompt.
 */
final class LocationCatalog
{
    /** Where installed checksums live: one JSON object, not 250 preference rows. */
    private const PREF_INSTALLED = 'location_data_installed';

    /** Cached manifest and when it was fetched, so an admin screen is not a network call. */
    private const PREF_CACHE     = 'location_catalog_cache';
    private const PREF_CHECKED   = 'location_catalog_checked';
    /** Which catalog URL the cached manifest came from. */
    private const PREF_SOURCE    = 'location_catalog_source';
    /** The manifest URL a pointer resolved to, and the data release it names. */
    private const PREF_MANIFEST  = 'location_catalog_manifest_url';
    private const PREF_RELEASE   = 'location_catalog_release';

    private const CACHE_TTL = 21600;

    /** Outbound calls are capped: a slow catalog must not hang an admin request. */
    private const TIMEOUT = 20;

    /** @var array|null */
    private $manifest;

    /**
     * The published manifest, from cache unless it is stale or $refresh is given.
     *
     * @return array|null null when it cannot be fetched and nothing is cached
     */
    public function manifest(bool $refresh = false): ?array
    {
        if ($this->manifest !== null && !$refresh) {
            return $this->manifest;
        }

        $checked = (int) osc_get_preference(self::PREF_CHECKED);
        $cached  = osc_get_preference(self::PREF_CACHE);

        // The cache belongs to the catalog it came from. Pointing the install at another
        // one — a staging catalog, a local mirror, a pinned release — otherwise keeps
        // serving the previous catalog's manifest until the six hours are up, which
        // reads as the new catalog offering the old one's countries.
        $source = md5(osc_get_locations_json_url());
        if (osc_get_preference(self::PREF_SOURCE) !== $source) {
            $cached = '';
        }

        if (!$refresh && $cached !== '' && (time() - $checked) < self::CACHE_TTL) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $this->manifest = $decoded;
            }
        }

        $configured  = osc_get_locations_json_url();
        $manifestUrl = $configured;
        $release     = '';

        $body = osc_file_get_contents($configured, null, true, self::TIMEOUT);
        $data = is_string($body) ? json_decode($body, true) : null;

        // The configured URL may be a pointer rather than the manifest itself: a small
        // document naming the current release. Following it is what lets a data release
        // reach installs on its own — pinning the manifest instead would mean shipping a
        // core release to correct a place name.
        if (is_array($data) && self::isPointerDocument($data)) {
            $release     = (string) ($data['version'] ?? '');
            $manifestUrl = $this->resolveAgainstOrigin($configured, (string) $data['manifest']);

            $body = $manifestUrl === null
                ? false
                : osc_file_get_contents($manifestUrl, null, true, self::TIMEOUT);
            $data = is_string($body) ? json_decode($body, true) : null;
        }

        // A manifest lists its countries under `countries`; the older one said
        // `locations`. Either makes this a manifest rather than a failed fetch.
        $listed = $data['countries'] ?? $data['locations'] ?? null;
        if (!is_array($data) || !is_array($listed)) {
            // Serve whatever was last cached rather than reporting "no countries
            // available" because the catalog host was briefly unreachable.
            $decoded = $cached !== '' ? json_decode($cached, true) : null;

            return $this->manifest = is_array($decoded) ? $decoded : null;
        }

        osc_set_preference(self::PREF_CACHE, json_encode($data));
        osc_set_preference(self::PREF_CHECKED, (string) time());
        osc_set_preference(self::PREF_SOURCE, $source);
        // Country files are addressed relative to the manifest, not to the pointer, so
        // the resolved URL is remembered — otherwise a cache hit would have nothing to
        // build those addresses from.
        osc_set_preference(self::PREF_MANIFEST, (string) $manifestUrl);
        osc_set_preference(self::PREF_RELEASE, $release);

        return $this->manifest = $data;
    }

    /**
     * Whether a fetched document names a manifest rather than being one.
     *
     * Decided on types, not on which keys are present. Both documents carry a `countries`
     * key: the manifest's is the list of them, the pointer's is how many there are. Asking
     * only whether the key exists reads the pointer as a manifest and then finds an
     * integer where the list should be — which is exactly the failure this had twice, both
     * times hidden by a cached manifest until the cache went cold.
     *
     * A pointer names a path; a manifest carries a list.
     *
     * @param array $data a decoded catalog document
     */
    public static function isPointerDocument(array $data): bool
    {
        return isset($data['manifest'])
            && is_string($data['manifest'])
            && !is_array($data['countries'] ?? null)
            && !is_array($data['locations'] ?? null);
    }

    /**
     * The URL country files are addressed relative to: the manifest itself, which is the
     * configured URL unless that turned out to be a pointer to one.
     */
    private function manifestUrl(): string
    {
        $this->manifest(); // resolves and caches it if this is the first call
        $resolved = (string) osc_get_preference(self::PREF_MANIFEST);

        return $resolved !== '' ? $resolved : osc_get_locations_json_url();
    }

    /**
     * The data release the catalog currently offers, empty when it does not say.
     */
    public function release(): string
    {
        $this->manifest();

        return (string) osc_get_preference(self::PREF_RELEASE);
    }

    /**
     * Resolve a manifest path from a pointer document.
     *
     * The path is relative to the host root rather than to the pointer's own directory —
     * `releases/latest.json` names `releases/<version>/json-list.json`, so resolving it
     * the usual way would ask for `releases/releases/...` and get a 404.
     *
     * Refuses to leave the origin the pointer came from: following an absolute URL out of
     * it would let whoever serves the pointer redirect an install anywhere.
     */
    private function resolveAgainstOrigin(string $pointerUrl, string $path): ?string
    {
        $parts = parse_url($pointerUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (preg_match('#^https?://#i', $path)) {
            return strpos($path, $origin . '/') === 0 ? $path : null;
        }

        // "//host/path" names another host to everything that parses URLs properly.
        // Trimming the slashes and treating it as relative would keep the request on
        // this origin, but only by turning it into a path that cannot exist — refusing
        // says what is meant and cannot quietly become a hole if that trim is ever
        // tidied up.
        if (strpos($path, '//') === 0) {
            return null;
        }

        // A ".." segment cannot reach anything here — this is a URL path, and the host
        // resolves it — but no manifest path has a legitimate reason to contain one, and
        // a pointer that offers one is not describing what it claims to.
        if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return null;
        }

        return $origin . '/' . ltrim($path, '/');
    }

    /**
     * Download and decode one country file.
     *
     * @return array|null null when it cannot be fetched or is not the expected shape
     */
    public function countryFile(string $fileName): ?array
    {
        $body = osc_file_get_contents($this->fileUrl($fileName), null, true, self::TIMEOUT);
        $data = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($data) || !isset($data['regions'])) {
            return null;
        }

        // Accepted in either shape: the catalog names a country `code` where the older
        // one said `s_country_code`, and its regions carry `settlements` where the older
        // one said `cities`. LocationImporter normalises the rest.
        return (isset($data['code']) || isset($data['s_country_code'])) ? $data : null;
    }

    /**
     * The absolute URL of a file the manifest names.
     *
     * Manifest entries give a path relative to the manifest itself (`data/MT.ndjson`),
     * so it resolves against the manifest's directory — unlike the pointer's manifest
     * path, which is relative to the host root. An older catalog names a bare file that
     * lives in a sibling directory, which is what the second branch reconstructs.
     */
    private function fileUrl(string $fileNameOrPath): string
    {
        $manifest = $this->manifestUrl();

        if (strpos($fileNameOrPath, '/') !== false) {
            $dir = substr($manifest, 0, strrpos($manifest, '/') + 1);

            return $dir . implode('/', array_map('rawurlencode', explode('/', $fileNameOrPath)));
        }

        // Old catalog: json-list.json beside json/ and ndjson/.
        $sub = substr($fileNameOrPath, -7) === '.ndjson' ? 'ndjson/' : 'json/';

        return str_replace('json-list.json', $sub, $manifest) . rawurlencode($fileNameOrPath);
    }

    /**
     * Download one country's ndjson to a local file and return its path.
     *
     * Written to disk rather than returned as a string, and read back a line at a time,
     * because the point of this format is that no step ever holds a whole country. The
     * largest country decodes to roughly ten times its file size as PHP arrays -- about
     * a gigabyte for Mexico -- which the default 128M memory limit cannot survive.
     *
     * The caller owns the returned file and should delete it.
     *
     * @param string $fileName as published in the manifest's s_file_ndjson
     * @param string $sha256   s_sha256_ndjson; checked when given, so a truncated
     *                         download fails here rather than importing part of a country
     *
     * @return string|null null when it cannot be fetched or fails its checksum
     */
    public function countryNdjsonFile(string $fileName, string $sha256 = ''): ?string
    {
        $path = osc_uploads_path() . 'locations-' . bin2hex(random_bytes(8)) . '.ndjson';

        $ok = (new \mindstellar\utility\FileSystem())->downloadFile(
            $this->fileUrl($fileName),
            $path,
            null,
            true,
            $sha256 !== '' ? $sha256 : null
        );

        return $ok === false ? null : $path;
    }

    /**
     * One row per country the catalog offers, annotated with what this install holds.
     *
     * @return array<int, array{code:string,name:string,file:string,installed:bool,current:bool,rows:int}>
     */
    public function status(bool $refresh = false): array
    {
        $manifest = $this->manifest($refresh);
        if ($manifest === null) {
            return array();
        }

        $installed = $this->installed();
        $present   = $this->countriesInDatabase();

        // The catalog lists its countries under `countries`; the older one said
        // `locations`. Every per-country field moved too, so each is read through a
        // fallback rather than the file being read twice.
        $entries = $manifest['countries'] ?? $manifest['locations'] ?? array();

        $out = array();
        foreach ($entries as $entry) {
            $code = (string) ($entry['code'] ?? $entry['s_country_code'] ?? '');
            if ($code === '') {
                continue;
            }

            $files  = is_array($entry['files'] ?? null) ? $entry['files'] : array();
            $hashes = is_array($entry['sha256'] ?? null) ? $entry['sha256'] : array();

            // The streaming file's checksum stands for "this country's data", because it
            // is the copy an import actually reads. It moves whenever the data moves.
            $sha  = (string) ($hashes['data'] ?? $entry['s_sha256'] ?? '');
            $have = $installed[strtoupper($code)] ?? null;
            // "Installed" means rows exist, not merely that a checksum was recorded: an
            // install that predates this bookkeeping has the data and no checksum, and
            // must still be offered the update rather than an install.
            $isInstalled = isset($present[strtolower($code)]);
            $out[]       = array(
                'code'      => $code,
                'name'      => (string) ($entry['name'] ?? $entry['s_country_name'] ?? $code),
                'file'      => (string) ($files['json'] ?? $entry['s_file_name'] ?? ''),
                'sha'       => $sha,
                // The same country as one JSON object per line. Empty on a catalog that
                // publishes only the whole-file form; where it is offered the import
                // reads it a line at a time instead of decoding a whole country at once.
                'ndjson'     => (string) ($files['data'] ?? $entry['s_file_ndjson'] ?? ''),
                'ndjson_sha' => (string) ($hashes['data'] ?? $entry['s_sha256_ndjson'] ?? ''),
                'installed' => $isInstalled,
                'current'   => $isInstalled && $have !== null && $sha !== '' && $have === $sha,
                'rows'      => (int) ($entry['settlements'] ?? $entry['i_cities'] ?? 0),
            );
        }

        return $out;
    }

    /**
     * Record that $code now holds the catalog's current version.
     */
    public function markInstalled(string $code, string $sha256): void
    {
        $installed                     = $this->installed();
        $installed[strtoupper($code)]  = $sha256;
        osc_set_preference(self::PREF_INSTALLED, json_encode($installed));
    }

    /**
     * @return array<string, string> ISO2 (upper) => sha256 recorded at install time
     */
    public function installed(): array
    {
        $raw     = osc_get_preference(self::PREF_INSTALLED);
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Catalog entry for one ISO2 code, or null when the catalog does not offer it.
     *
     * @return array|null
     */
    public function entry(string $code): ?array
    {
        foreach ($this->status() as $row) {
            if (strcasecmp($row['code'], $code) === 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, bool> lowercase country codes that actually have region rows
     */
    private function countriesInDatabase(): array
    {
        $rows = osc_db_select(
            'SELECT DISTINCT fk_c_country_code AS code FROM ' . DB_TABLE_PREFIX . 't_region'
        );
        $out = array();
        foreach ($rows as $row) {
            $out[strtolower((string) $row['code'])] = true;
        }

        return $out;
    }
}
