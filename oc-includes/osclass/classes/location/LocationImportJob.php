<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\location;

use mindstellar\model\KeyValue;

/**
 * One country's import split over several web requests, so none runs into a host's gateway timeout.
 * start() downloads the country; each step() imports about BATCH_SECONDS of it and keeps its place in t_key_value.
 */
final class LocationImportJob
{
    /** Seconds of import work one request does; it stops at the next slice after this. */
    public const BATCH_SECONDS = 8.0;

    private const GROUP = 'location_import';

    /** An abandoned job is forgotten after a day. */
    private const KEEP = 86400;

    /** @var callable(array<string,mixed>, string): bool writes the country's ndjson to a path */
    private $fetch;

    private KeyValue $kv;

    public function __construct(
        private bool $dryRun = false,
        ?callable $fetch = null,
        ?KeyValue $kv = null
    ) {
        $this->kv    = $kv ?? new KeyValue();
        $this->fetch = $fetch ?? static fn (array $entry, string $path): bool => self::download($entry, $path);
    }

    /**
     * Download the country and store a fresh job, replacing any earlier one for it.
     *
     * @param array<string,mixed> $entry a row from LocationCatalog::status()
     *
     * @return array{ok:bool, done:bool, progress:float, error?:string}
     */
    public function start(array $entry): array
    {
        $code = strtoupper((string) $entry['code']);
        $this->forget($code);
        $path = osc_uploads_path() . 'locations-' . bin2hex(random_bytes(8)) . '.ndjson';
        if (!($this->fetch)($entry, $path)) {
            @unlink($path);

            return array('ok' => false, 'done' => true, 'progress' => 0.0, 'error' => 'could not download ' . (string) $entry['file']);
        }
        $this->save($code, array('entry' => $entry, 'path' => $path, 'batch' => array()));

        return array('ok' => true, 'done' => false, 'progress' => 0.0);
    }

    /**
     * Import the next batch of a started country.
     *
     * @param string $code    ISO2 code the job was started for
     * @param float  $seconds how long this batch may work
     *
     * @return array{ok:bool, done:bool, progress:float, report?:array<string,mixed>, entry?:array<string,mixed>, error?:string}
     */
    public function step(string $code, float $seconds = self::BATCH_SECONDS): array
    {
        $code = strtoupper($code);
        $job  = $this->load($code);
        if ($job === null) {
            return array('ok' => false, 'done' => true, 'progress' => 0.0, 'error' => 'no import was started for ' . $code);
        }
        // A cleaned-out upload folder only costs the download again.
        if (!is_file((string) $job['path']) && !($this->fetch)($job['entry'], (string) $job['path'])) {
            return array('ok' => false, 'done' => true, 'progress' => 0.0, 'error' => 'could not download ' . (string) $job['entry']['file']);
        }

        $batch = (new LocationImporter($this->dryRun))->importNdjsonBatch(
            (string) $job['path'],
            (array) $job['batch'],
            microtime(true) + $seconds,
            function (array $batch) use ($code, $job): void {
                $this->save($code, array('batch' => $batch) + $job);
            }
        );
        $report = (array) $batch['report'];
        if (isset($report['error']) || $batch['done']) {
            $this->forget($code);
        }
        if (isset($report['error'])) {
            return array('ok' => false, 'done' => true, 'progress' => 0.0, 'error' => (string) $report['error']);
        }
        if ($batch['done'] && !$this->dryRun && (string) ($job['entry']['sha'] ?? '') !== '') {
            (new LocationCatalog())->markInstalled($code, (string) $job['entry']['sha']);
        }

        return array('ok' => true, 'done' => (bool) $batch['done'], 'progress' => (float) $batch['progress'], 'report' => $report, 'entry' => (array) $job['entry']);
    }

    /**
     * Write one catalog country to $path as ndjson, from the whole-file form when the catalog
     * offers no ndjson or it fails its checksum.
     *
     * @param array<string,mixed> $entry a row from LocationCatalog::status()
     * @param string              $path
     *
     * @return bool
     */
    private static function download(array $entry, string $path): bool
    {
        $catalog = new LocationCatalog();
        if ((string) ($entry['ndjson'] ?? '') !== '') {
            $file = $catalog->countryNdjsonFile((string) $entry['ndjson'], (string) ($entry['ndjson_sha'] ?? ''));
            if ($file !== null) {
                return @rename($file, $path) || (@copy($file, $path) && @unlink($file));
            }
        }
        $data = $catalog->countryFile((string) $entry['file']);

        return $data !== null && LocationImporter::writeNdjson($data, $path);
    }

    private function key(string $code): string
    {
        return $code . ($this->dryRun ? '.preview' : '');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load(string $code): ?array
    {
        $row = $this->kv->get(self::GROUP, $this->key($code));
        $job = $row === null ? null : json_decode((string) $row['value'], true);

        return is_array($job) && isset($job['path'], $job['entry']) ? $job : null;
    }

    /**
     * @param array<string,mixed> $job
     */
    private function save(string $code, array $job): void
    {
        $this->kv->set(self::GROUP, $this->key($code), (string) json_encode($job, JSON_INVALID_UTF8_SUBSTITUTE), time() + self::KEEP);
    }

    private function forget(string $code): void
    {
        $job = $this->load($code);
        if ($job !== null) {
            @unlink((string) $job['path']);
        }
        $this->kv->delete(self::GROUP, $this->key($code));
    }
}
