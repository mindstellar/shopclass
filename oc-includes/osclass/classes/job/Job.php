<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\job;

/**
 * One claimed job, as a handler sees it.
 *
 * A handler is given this and nothing else: it reads the payload, does the work, and
 * returns. Returning means the job is done and the row goes away. Throwing means it
 * failed, and the queue retries it with a backoff.
 *
 * The third outcome is `repeat()`, which is what makes long work safe here. A handler
 * that cannot finish inside one tick -- deleting 39,000 listings, walking a catalogue --
 * does one batch, calls `repeat()` with where to carry on from, and returns. The queue
 * re-queues it instead of deleting it. Nothing is held open between batches, so no lock
 * outlives a single batch and no `max_execution_time` can strand the work half-done.
 */
final class Job
{
    /** @var array<string,string|null> the raw t_job_queue row */
    private $row;

    /** @var array<string,mixed> */
    private $payload;

    /** @var array{payload:array<string,mixed>,delay:int}|null set by repeat() */
    private $repeat = null;

    /**
     * @param array<string,string|null> $row     a t_job_queue row
     * @param array<string,mixed>       $payload the decoded s_payload
     */
    public function __construct(array $row, array $payload)
    {
        $this->row     = $row;
        $this->payload = $payload;
    }

    /**
     * @return int
     */
    public function id(): int
    {
        return (int) ($this->row['pk_i_id'] ?? 0);
    }

    /**
     * @return string the namespaced type, e.g. 'storage.offload'
     */
    public function type(): string
    {
        return (string) ($this->row['s_type'] ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * One payload key, or $default when it is absent.
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->payload[$key] ?? $default;
    }

    /**
     * How many times this job has already failed. Zero on the first run.
     *
     * @return int
     */
    public function attempts(): int
    {
        return (int) ($this->row['i_attempts'] ?? 0);
    }

    /**
     * The storage adapter id, for `storage.*` jobs. Null for everything else.
     *
     * @return string|null
     */
    public function storage(): ?string
    {
        $storage = $this->row['s_storage'] ?? null;

        return ($storage === null || $storage === '') ? null : (string) $storage;
    }

    /**
     * The raw queue row, for a handler that needs a column this class does not expose.
     *
     * @return array<string,string|null>
     */
    public function raw(): array
    {
        return $this->row;
    }

    /**
     * Carry on in a later tick instead of finishing.
     *
     * Call this from a handler, then return. The job is re-queued with $payload in place
     * of the one it ran with -- the cursor, offset or remaining-ids list the next batch
     * needs -- and its attempt count is left alone, because a batch that finished is not
     * a failure.
     *
     * @param array<string,mixed> $payload      the payload the next run receives
     * @param int                 $delaySeconds hold it back this long; 0 runs it next tick
     *
     * @return void
     */
    public function repeat(array $payload = array(), int $delaySeconds = 0): void
    {
        $this->repeat = array(
            'payload' => $payload,
            'delay'   => max(0, $delaySeconds),
        );
    }

    /**
     * Whether the handler asked for another run.
     *
     * @return array{payload:array<string,mixed>,delay:int}|null
     */
    public function repeatRequest(): ?array
    {
        return $this->repeat;
    }
}
