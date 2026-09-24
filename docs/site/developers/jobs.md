---
title: Background jobs
description: Queue slow work and let cron run it — osc_job_enqueue(), handler registration, retries, batching long work, and the admin queue screen.
sidebar:
  order: 25
---

Some work is too slow to do while somebody waits. Emptying a category with 39,000
listings takes about fifteen minutes; uploading a photo to remote storage takes as long as
the network does. Doing either inside a page load means a request that hangs, and then a
host's `max_execution_time` kills it half-finished.

Put it on the queue instead. Cron runs it.

## The shortest complete example

```php
// In your plugin. Register the handler on the register_jobs hook, so it exists in the
// cron request too -- not only where the job was queued.
osc_add_hook('register_jobs', function () {
    osc_job_register_handler('acme.send_digest', function ($job) {
        acme_send_digest((int) $job->get('user_id'));
    });
});

// Anywhere. Queue the work and return immediately.
osc_job_enqueue('acme.send_digest', array('user_id' => $userId));
```

That is the whole API for most plugins.

## Two rules you have to follow

**Put the facts in the payload, not a foreign key.** The job may run long after the row
that created it was deleted — that is often exactly why it was queued.

```php
// Wrong: the user may be gone by the time this runs.
osc_job_enqueue('acme.send_receipt', array('order_id' => $id));

// Right: carry what the handler needs.
osc_job_enqueue('acme.send_receipt', array(
    'email'  => $user['s_email'],
    'total'  => $order['f_total'],
    'lines'  => $lines,
));
```

**Make the handler safe to run twice.** A job is claimed, not removed. A worker killed
mid-job leaves its row behind and a later tick runs it again. Check before you act, or
choose operations that do not care: deleting a file that is already gone is fine,
charging a card twice is not.

## Naming a job type

A type is `namespace.name` — lower-case letters, digits, underscores and dots, up to 60
characters. The namespace is required. Without it the first plugin to claim `send` would
take the word from every other plugin.

Use your plugin's own prefix: `acme.send_digest`, `acme.mail.retry`. Core uses
`storage.*` and `category.*`.

An invalid type throws at the call site, not hours later in a cron run.

## Work that is too big for one run

A handler that cannot finish in one tick does one batch, says where to carry on from, and
returns. The job is re-queued instead of finishing, and **no attempt is counted against
it** — a batch that worked is not a failure.

```php
osc_job_register_handler('acme.rebuild', function ($job) {
    $offset = (int) $job->get('offset', 0);
    $rows   = acme_next_page($offset, 200);

    foreach ($rows as $row) {
        acme_rebuild($row);
    }

    if (count($rows) === 200) {
        $job->repeat(array('offset' => $offset + 200));
    }
});
```

Nothing is held between batches — no transaction, no lock, no PHP process. That is what
makes the work survivable on a shared host.

## When a job fails

Throw. The queue catches it, records the message, and retries with a growing delay: 1, 2,
4 minutes and so on up to an hour. After 8 attempts it stops retrying and the job sits in
`error` with its last message, where **Tools → Background jobs** shows it. Nothing is ever
dropped silently.

A job whose type nothing registered is treated the same way. The usual cause is a plugin
deactivated with work still queued, and reactivating it is enough to let the jobs run.

## What a handler receives

| Call | What it gives you |
|---|---|
| `$job->payload()` | the whole payload, as queued |
| `$job->get($key, $default)` | one payload key |
| `$job->id()` | the queue row id |
| `$job->type()` | the type, e.g. `acme.send_digest` |
| `$job->attempts()` | failures so far; 0 on the first run |
| `$job->storage()` | the storage adapter id, for `storage.*` jobs; otherwise null |
| `$job->repeat($payload, $delay)` | run again instead of finishing |

## The rest of the API

| Function | What it does |
|---|---|
| `osc_job_enqueue($type, $payload, $options)` | queue a job; `$options['delay']` holds it back that many seconds |
| `osc_job_register_handler($type, $handler)` | say which callable runs a type |
| `osc_job_has_handler($type)` | whether anything registered for a type |
| `osc_job_registered_types()` | every registered type, sorted |
| `osc_job_count($status, $type)` | how many jobs are `pending`, `running` or `error` |
| `osc_job_summary()` | all three counts at once |
| `osc_job_run($maxSeconds)` | drain the queue now, rather than waiting for cron |
| `osc_job_retry($id)` | put a job that gave up back on the queue |
| `osc_job_forget($id)` | throw a job that gave up away |
| `osc_job_dead_letters($limit)` | the jobs that gave up, each with its last error |

A payload is stored as JSON in a 64 KB column. `osc_job_enqueue()` throws an
`InvalidArgumentException` for one that is larger, and a `$job->repeat()` payload that
is larger fails the job. Queue an id and read the rest when the job runs.

## Running the queue

Every `cron` run drains it, whichever tier it runs, and an empty queue costs one query.
That is enough for most sites.

To pick work up sooner than cron runs, add the worker on its own. `jobs:work` drains the
queue and does nothing else, so it is safe to run every minute:

```
* * * * * php /path/to/oc-cli.php jobs:work --max-seconds=50
```

`php oc-cli.php jobs:status` reports what is waiting and names anything that gave up. Both
exit non-zero when a job has stopped retrying, so a cron log can notice.

## Seeing what is happening

**Tools → Background jobs** lists what is waiting, what is running and what gave up, with
the reason. It can run the queue now, retry a failed job, or throw it away. It also warns
when queued work has no handler.

## Where it is stored

One table, `t_job_queue`. Every job type shares it; there is no per-feature queue table
and you should not add one.
