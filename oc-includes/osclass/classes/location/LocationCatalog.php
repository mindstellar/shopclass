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

        $body = osc_file_get_contents(osc_get_locations_json_url(), null, true, self::TIMEOUT);
        $data = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($data) || !isset($data['locations']) || !is_array($data['locations'])) {
            // Serve whatever was last cached rather than reporting "no countries
            // available" because GitHub was briefly unreachable.
            $decoded = $cached !== '' ? json_decode($cached, true) : null;

            return $this->manifest = is_array($decoded) ? $decoded : null;
        }

        osc_set_preference(self::PREF_CACHE, json_encode($data));
        osc_set_preference(self::PREF_CHECKED, (string) time());
        osc_set_preference(self::PREF_SOURCE, $source);

        return $this->manifest = $data;
    }

    /**
     * Download and decode one country file.
     *
     * @return array|null null when it cannot be fetched or is not the expected shape
     */
    public function countryFile(string $fileName): ?array
    {
        $base = str_replace('json-list.json', 'json/', osc_get_locations_json_url());
        $body = osc_file_get_contents($base . rawurlencode($fileName), null, true, self::TIMEOUT);
        $data = is_string($body) ? json_decode($body, true) : null;

        return (is_array($data) && isset($data['s_country_code'], $data['regions'])) ? $data : null;
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
        $base = str_replace('json-list.json', 'ndjson/', osc_get_locations_json_url());
        $path = osc_uploads_path() . 'locations-' . bin2hex(random_bytes(8)) . '.ndjson';

        $ok = (new \mindstellar\utility\FileSystem())->downloadFile(
            $base . rawurlencode($fileName),
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

        $out = array();
        foreach ($manifest['locations'] as $entry) {
            $code = (string) $entry['s_country_code'];
            $sha  = (string) ($entry['s_sha256'] ?? '');
            $have = $installed[strtoupper($code)] ?? null;
            // "Installed" means rows exist, not merely that a checksum was recorded: an
            // install that predates this bookkeeping has the data and no checksum, and
            // must still be offered the update rather than an install.
            $isInstalled = isset($present[strtolower($code)]);
            $out[]       = array(
                'code'      => $code,
                'name'      => (string) $entry['s_country_name'],
                'file'      => (string) $entry['s_file_name'],
                'sha'       => $sha,
                // The same country as one JSON object per line. Empty on a catalog that
                // publishes only the whole-file form; where it is offered the import
                // reads it a line at a time instead of decoding a whole country at once.
                'ndjson'     => (string) ($entry['s_file_ndjson'] ?? ''),
                'ndjson_sha' => (string) ($entry['s_sha256_ndjson'] ?? ''),
                'installed' => $isInstalled,
                'current'   => $isInstalled && $have !== null && $sha !== '' && $have === $sha,
                'rows'      => (int) ($entry['i_cities'] ?? 0),
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
