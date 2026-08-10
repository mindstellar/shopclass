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
