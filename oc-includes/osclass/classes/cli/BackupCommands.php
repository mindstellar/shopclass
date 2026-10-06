<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\cli;

use mindstellar\admin\DatabaseTools;
use mindstellar\backup\BackupBucket;
use mindstellar\backup\BackupJobs;
use mindstellar\backup\BackupService;
use mindstellar\backup\BackupStore;
use mindstellar\backup\Builder;
use mindstellar\backup\Restorer;
use mindstellar\job\Job;

/**
 * The backup:* commands. They run the same backup and restore jobs as the admin page,
 * step after step in this process, with no time limit.
 *
 * Shell access is the authority here: a restore needs no admin password and works when
 * OSC_DISABLE_WEB_RESTORE is on. Nothing printed names a key, a signed link or a password.
 */
final class BackupCommands
{
    /** Seconds between two progress lines of the same stage. */
    private const REPORT_EVERY = 2.0;

    /** @var callable fn(string $text): void */
    private $out;

    /** @var callable fn(string $text): void */
    private $err;

    /** @var array<string,mixed> */
    private $opts;

    /** @var string */
    private $lastLine = '';

    /** @var string */
    private $lastStage = '';

    /** @var float */
    private $lastAt = 0.0;

    /**
     * @param callable            $out  fn(string $text): void
     * @param callable            $err  fn(string $text): void
     * @param array<string,mixed> $opts site (the site folder), content, store; ask, fn(string $prompt): string,
     *                                  null when there is no terminal; busy, lock_free, facts; builder and
     *                                  restorer options; effects for BackupJobs
     */
    public function __construct(callable $out, callable $err, array $opts = array())
    {
        $this->out  = $out;
        $this->err  = $err;
        $this->opts = $opts + array(
            'site'      => ABS_PATH,
            'content'   => null,
            'store'     => null,
            'busy'      => array(BackupService::class, 'busy'),
            'lock_free' => array(BackupService::class, 'lockFree'),
            'facts'     => array(BackupService::class, 'siteFacts'),
            'builder'   => array(),
            'restorer'  => array(),
            'effects'   => array(),
        );
        if (!array_key_exists('ask', $opts)) {
            $this->opts['ask'] = self::terminal();
        }
    }

    /**
     * backup:create [--what=database|files|everything] [--to=server|s3|<folder>]
     *
     * @param array<string,mixed> $args
     *
     * @return int
     */
    public function create(array $args): int
    {
        $what = $args['what'] ?? 'everything';
        $to   = $args['to'] ?? 'server';
        if (!in_array($what, BackupService::WHAT, true)) {
            return $this->fail("--what must be database, files or everything.\n", 2);
        }
        if (!is_string($to) || $to === '') {
            return $this->fail("--to must be server, s3 or a folder.\n", 2);
        }
        if (($this->opts['busy'])()) {
            return $this->fail("A backup or restore is running. Wait for it to finish.\n");
        }
        $site   = $this->store();
        $store  = $site;
        $where  = 'server';
        $bucket = null;
        if ($to === 's3') {
            $bucket = BackupBucket::adapter();
            if ($bucket === null) {
                return $this->fail(BackupService::noBucket() . "\n");
            }
            if (BackupBucket::insecure()) {
                return $this->fail("Your S3 endpoint uses plain http. Use an https endpoint.\n");
            }
            if (BackupBucket::exposed()) {
                $this->say("Warning: your photo bucket is public. Anyone with the link could download a backup saved there.\n");
            }
            $where = 'bucket';
        } elseif ($to !== 'server') {
            $check = BackupStore::checkFolder($to, (string) $this->opts['site']);
            if ($check['error'] !== '') {
                return $this->fail($check['error'] . "\n", 2);
            }
            if (rtrim($check['dir'], '/\\') !== rtrim((string) realpath($site->dir()), '/\\')) {
                $store = new BackupStore($check['dir']);
            }
        }
        if (!$store->protect()) {
            return $this->fail(sprintf("The backup folder cannot be written: %s\n", $store->dir()));
        }
        if ($store === $site) {
            $store->sweep(false);
        }

        $p       = Builder::begin((string) $what, $where) + ($this->opts['facts'])();
        $builder = $this->builder($store);
        $this->say(sprintf("Making a backup: %s.\n", BackupJobs::whatWord((string) $what)));
        $upload = null;
        $this->run(BackupJobs::CREATE, $p, static function (Job $job) use ($store, $builder): void {
            BackupJobs::create($job, $store, $builder);
        }, array('upload' => static function (array $p) use (&$upload): bool {
            $upload = $p;

            return true;
        }));
        if ($upload !== null) {
            $this->run(BackupJobs::UPLOAD, $upload, static function (Job $job) use ($store, $bucket): void {
                BackupJobs::upload($job, $store, $bucket);
            });
        }

        $state = $store->state();
        if ($store !== $site) {
            $store->clearState();
        }
        if (($state['status'] ?? '') !== 'done') {
            return $this->ended($state);
        }
        $this->say(sprintf(
            "Saved %s (%s)\n  %s\n",
            $state['name'],
            DatabaseTools::bytes((int) $state['size']),
            $where === 'bucket' ? 'in the bucket: ' . BackupBucket::label() . $state['name'] : $store->dir() . $state['name']
        ));

        return 0;
    }

    /**
     * backup:list [--to=server|s3|<folder>]
     *
     * @param array<string,mixed> $args
     *
     * @return int
     */
    public function list(array $args): int
    {
        $to = $args['to'] ?? ($args['from'] ?? 'server');
        if (!is_string($to) || $to === '') {
            return $this->fail("--to must be server, s3 or a folder.\n", 2);
        }
        if ($to === 's3') {
            $bucket = BackupBucket::adapter();
            if ($bucket === null) {
                return $this->fail(BackupService::noBucket() . "\n");
            }
            $rows  = $this->store()->bucketAll($bucket, true);
            $place = 'the bucket ' . BackupBucket::label();
            if ($rows === null) {
                return $this->fail("The bucket could not be read.\n");
            }
        } elseif ($to === 'server') {
            $rows  = $this->store()->all();
            $place = $this->store()->dir();
        } else {
            $real = realpath($to);
            if ($real === false || !is_dir($real)) {
                return $this->fail("The backup folder does not exist.\n", 2);
            }
            $rows  = (new BackupStore($real))->all();
            $place = $real;
        }
        if ($rows === array()) {
            $this->say(sprintf("No backups in %s\n", $place));

            return 0;
        }
        $this->say(sprintf("Backups in %s\n\n", $place));
        $this->say(sprintf("%-16s  %-24s  %-6s  %9s  %s\n", 'Date', 'What', 'Where', 'Size', 'Name'));
        foreach ($rows as $row) {
            $ts   = strtotime((string) $row['created']);
            $what = BackupJobs::whatWord((string) $row['what']) . ($row['kind'] === 'safety' ? ', safety copy' : '');
            $this->say(sprintf(
                "%-16s  %-24s  %-6s  %9s  %s\n",
                $ts !== false ? date('Y-m-d H:i', $ts) : '',
                $what,
                $row['where'] === 'bucket' ? 's3' : ($to === 'server' ? 'server' : 'folder'),
                DatabaseTools::bytes((int) $row['size']),
                $row['name']
            ));
        }

        return 0;
    }

    /**
     * backup:restore <name|file> [--from=server|s3] [--only=database|files] [--yes]
     *
     * @param array<string,mixed> $args
     *
     * @return int
     */
    public function restore(array $args): int
    {
        $target = (string) ($args['_'][0] ?? '');
        $from   = $args['from'] ?? 'server';
        $only   = $args['only'] ?? null;
        if ($target === '') {
            return $this->fail("Name a backup (see backup:list) or give the path of a .zip or .sql file.\n", 2);
        }
        if (!in_array($from, array('server', 's3'), true)) {
            return $this->fail("--from must be server or s3.\n", 2);
        }
        if ($only !== null && !in_array($only, array('database', 'files'), true)) {
            return $this->fail("--only must be database or files.\n", 2);
        }
        if (($this->opts['busy'])()) {
            return $this->fail("A backup or restore is running. Wait for it to finish.\n");
        }

        $site   = $this->store();
        $bucket = null;
        $path   = null;
        if ($from === 's3') {
            if (!BackupStore::isName($target)) {
                return $this->fail("Give the name of a backup in the bucket, as backup:list --to=s3 shows it.\n", 2);
            }
            $bucket = BackupBucket::adapter();
            $check  = BackupService::checkBucket($target);
        } else {
            $name = $this->nameIn($site, $target);
            if ($name === null) {
                $path = realpath($target);
                if ($path === false || !is_file($path) || !is_readable($path)) {
                    return $this->fail(sprintf("No backup named %s, and no such file.\n", $target), 2);
                }
                if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), array('zip', 'sql'), true)) {
                    return $this->fail("A backup file is a .zip or a .sql file.\n", 2);
                }
                $file = $path;
            } else {
                $target = $name;
                $file   = (string) $site->path($name);
            }
            $check = BackupService::checkFile($file);
        }
        if ($check['reason'] !== '') {
            return $this->fail($check['reason'] . "\n");
        }
        $db    = $only !== 'files' && $check['database'];
        $files = $only !== 'database' && $check['files'] > 0;
        if (!$db && !$files) {
            return $this->fail($only !== null ? sprintf("This backup holds no %s.\n", $only) : "There is nothing to put back.\n");
        }
        if ($db && !($this->opts['lock_free'])()) {
            return $this->fail("A database update is running. Try again in a few minutes.\n");
        }

        $this->summary($target, $check, $db, $files);
        $refused = $this->refused($args, 'restore');
        if ($refused !== 0) {
            return $refused;
        }
        if (!$site->protect()) {
            return $this->fail(sprintf("The backup folder cannot be written: %s\n", $site->dir()));
        }

        if ($from === 's3') {
            $p = Restorer::beginFetch($target, $db, $files);
        } else {
            $p = Restorer::begin($path !== null ? basename($path) : $target, $db, $files);
        }
        $p['source_created'] = (string) ($check['manifest']['created'] ?? '');
        $restorer = BackupJobs::restorer($site, 0, array(
            'content'     => $this->content(),
            'site'        => $this->opts['site'],
            'builder'     => $this->opts['builder'],
            'source_path' => $path,
            'requeue'     => static function (): bool {
                return true;
            },
            'progress'    => $this->progressFor($site),
        ) + $this->opts['restorer']);
        $this->run(BackupJobs::RESTORE, $p, static function (Job $job) use ($site, $restorer, $bucket): void {
            BackupJobs::restore($job, $site, $restorer, $bucket);
        });

        $state = $site->state();
        if (($state['status'] ?? '') !== 'done') {
            return $this->ended($state);
        }
        $when = BackupJobs::when((string) ($state['source_created'] ?? ''));
        $this->say($when !== '' ? sprintf("Restored the backup from %s.\n", $when) : "Restored the backup.\n");
        if ((string) ($state['safety_name'] ?? '') !== '') {
            $this->say(sprintf("The safety copy made first: %s\n", $state['safety_name']));
        }

        return 0;
    }

    /**
     * backup:delete <name> [--from=server|s3] [--yes]
     *
     * @param array<string,mixed> $args
     *
     * @return int
     */
    public function delete(array $args): int
    {
        $name = (string) ($args['_'][0] ?? '');
        $from = $args['from'] ?? 'server';
        if (!BackupStore::isName($name)) {
            return $this->fail("Name a backup as backup:list shows it.\n", 2);
        }
        if (!in_array($from, array('server', 's3'), true)) {
            return $this->fail("--from must be server or s3.\n", 2);
        }
        $site = $this->store();
        if ($from === 's3') {
            $bucket = BackupBucket::adapter();
            if ($bucket === null) {
                return $this->fail(BackupService::noBucket() . "\n");
            }
            $rows = $site->bucketAll($bucket, true);
            if ($rows === null) {
                return $this->fail("The bucket could not be read.\n");
            }
            if (!in_array($name, array_column($rows, 'name'), true)) {
                return $this->fail("That backup is not in the bucket.\n");
            }
        } elseif ($site->path($name) === null) {
            return $this->fail("That backup is not on the server.\n");
        }
        $this->say(sprintf("Delete %s from the %s.\n", $name, $from === 's3' ? 'bucket' : 'server'));
        $refused = $this->refused($args, 'delete');
        if ($refused !== 0) {
            return $refused;
        }
        $ok = $from === 's3' ? $site->bucketDelete($bucket, $name) : $site->delete($name);
        if (!$ok) {
            return $this->fail("The backup could not be deleted.\n");
        }
        $this->say("Deleted.\n");

        return 0;
    }

    /**
     * A saved backup's name for what was typed: a name, or the path of one in the backup
     * folder. Null when it is neither.
     *
     * @param BackupStore $store
     * @param string      $target
     *
     * @return string|null
     */
    private function nameIn(BackupStore $store, string $target): ?string
    {
        if ($target === basename($target)) {
            return $store->path($target) !== null ? $target : null;
        }
        $real = realpath($target);
        $dir  = realpath($store->dir());
        if ($real === false || $dir === false || dirname($real) !== $dir) {
            return null;
        }

        return $store->path(basename($real)) !== null ? basename($real) : null;
    }

    /**
     * Say what a restore will do.
     *
     * @param string              $target
     * @param array<string,mixed> $check
     * @param bool                $db
     * @param bool                $files
     *
     * @return void
     */
    private function summary(string $target, array $check, bool $db, bool $files): void
    {
        $manifest = (array) ($check['manifest'] ?? array());
        $when     = BackupJobs::when((string) ($manifest['created'] ?? ''));
        $this->say(sprintf("Backup:    %s (%s)\n", $target, DatabaseTools::bytes((int) $check['size'])));
        if ($when !== '') {
            $this->say(sprintf("Made:      %s, Shopclass %s\n", $when, (string) ($manifest['shopclass_version'] ?? '?')));
        }
        $parts = $db && $files ? 'the database and the files' : ($db ? 'the database' : 'the files');
        $this->say(sprintf("Puts back: %s\n", $parts));
        if ((string) $check['note'] !== '') {
            $this->say($check['note'] . "\n");
        }
        $this->say("A safety copy is saved first. Visitors see the maintenance page until it is done.\n");
        if ($files) {
            $this->say("Files added since the backup are left in place.\n");
        }
    }

    /**
     * Whether to stop: 0 to go on (--yes, or the word typed at the terminal), else the
     * exit code.
     *
     * @param array<string,mixed> $args
     * @param string              $word
     *
     * @return int
     */
    private function refused(array $args, string $word): int
    {
        if (!empty($args['yes'])) {
            return 0;
        }
        $ask = $this->opts['ask'];
        if ($ask === null) {
            return $this->fail("Add --yes to go on without a prompt. Nothing was changed.\n", 2);
        }
        if (strtolower(trim((string) $ask(sprintf('Type "%s" to go on: ', $word)))) !== $word) {
            return $this->fail("Stopped. Nothing was changed.\n");
        }

        return 0;
    }

    /**
     * Run a job's steps here until it stops asking for more.
     *
     * @param string              $type
     * @param array<string,mixed> $p
     * @param callable            $step    fn(Job $job): void
     * @param array<string,callable> $effects
     *
     * @return void
     */
    private function run(string $type, array $p, callable $step, array $effects = array()): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        BackupJobs::effects($effects + $this->opts['effects']);
        try {
            while (true) {
                $job = new Job(array('pk_i_id' => '0', 's_type' => $type), $p);
                $step($job);
                $repeat = $job->repeatRequest();
                if ($repeat === null) {
                    return;
                }
                $p = $repeat['payload'];
                $this->report(BackupJobs::state($p, 'running'));
            }
        } finally {
            BackupJobs::effects(array());
        }
    }

    /**
     * A progress callback that saves the state for the admin page and prints a line.
     *
     * @param BackupStore $store
     *
     * @return callable
     */
    private function progressFor(BackupStore $store): callable
    {
        return function (array $p) use ($store): void {
            $state = BackupJobs::state($p, 'running');
            $store->saveState($state);
            $this->report($state);
        };
    }

    /**
     * A Builder for this site, printing progress.
     *
     * @param BackupStore $store
     *
     * @return Builder
     */
    private function builder(BackupStore $store): Builder
    {
        return BackupJobs::builder($store, array(
            'content'  => $this->content(),
            'progress' => $this->progressFor($store),
        ) + $this->opts['builder']);
    }

    /**
     * Print where a run stands, at most every few seconds within one stage.
     *
     * @param array<string,mixed> $state
     *
     * @return void
     */
    private function report(array $state): void
    {
        $words = BackupService::progress($state);
        $stage = (string) ($state['stage'] ?? '');
        $now   = microtime(true);
        if ($words['line'] === $this->lastLine
            || ($stage === $this->lastStage && $now - $this->lastAt < self::REPORT_EVERY)
        ) {
            return;
        }
        $this->lastLine  = $words['line'];
        $this->lastStage = $stage;
        $this->lastAt    = $now;
        $this->say(($words['percent'] !== null ? sprintf('%3d%%  ', $words['percent']) : '      ') . $words['line'] . "\n");
    }

    /**
     * Print why a run failed.
     *
     * @param array<string,mixed> $state
     *
     * @return int
     */
    private function ended(array $state): int
    {
        if (($state['status'] ?? '') === 'cancelled') {
            return $this->fail("The backup was cancelled. Nothing was saved.\n");
        }
        $failure = BackupService::failure($state);
        foreach ($failure['lines'] as $line) {
            ($this->err)($line . "\n");
        }
        if ($failure['reopen']) {
            ($this->err)("Open the site again from Tools > Backup and restore, or delete the .maintenance file.\n");
        }

        return 1;
    }

    /**
     * @return BackupStore
     */
    private function store(): BackupStore
    {
        if (!$this->opts['store'] instanceof BackupStore) {
            $this->opts['store'] = BackupStore::site();
        }

        return $this->opts['store'];
    }

    /**
     * @return string
     */
    private function content(): string
    {
        return (string) ($this->opts['content'] ?? rtrim((string) $this->opts['site'], '/') . '/oc-content');
    }

    /**
     * @param string $text
     *
     * @return void
     */
    private function say(string $text): void
    {
        ($this->out)($text);
    }

    /**
     * @param string $text
     * @param int    $code
     *
     * @return int
     */
    private function fail(string $text, int $code = 1): int
    {
        ($this->err)($text);

        return $code;
    }

    /**
     * A prompt on the terminal, or null when there is none to ask.
     *
     * @return callable|null
     */
    private static function terminal(): ?callable
    {
        if (!defined('STDIN') || !function_exists('stream_isatty') || !@stream_isatty(STDIN)) {
            return null;
        }

        return static function (string $prompt): string {
            fwrite(STDOUT, $prompt);

            return (string) fgets(STDIN);
        };
    }
}
