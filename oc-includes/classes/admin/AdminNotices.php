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

namespace mindstellar\admin;

/**
 * The admin notices service: every rule on what a notice may hold and who sees it.
 * One-off notices are stored until cleared or expired, standing checks run on each admin page,
 * and a bad notice is logged and skipped, never thrown.
 */
final class AdminNotices
{
    public const HIDE_DAYS   = 7;
    public const MAX_ONE_OFF = 100;
    public const DEFAULT_TTL = 30 * 86400;

    private const TYPES    = ['error', 'warning', 'info'];
    private const MAX_TEXT = 500;
    private const AUTO_ID  = 'auto.';

    /** A repeated one-off is written again only once this fraction of its life (1/n) has gone. */
    private const REWRITE_AFTER = 10;

    private static ?self $instance = null;

    private AdminNoticeStore $store;
    private AdminNoticeFeed $feed;
    private \Closure $clock;
    private bool $inAdmin;

    /** @var array<string,array{0:array<string,mixed>, 1:callable}> */
    private array $checks = [];

    /** @var array<string,AdminNoticeView> */
    private array $views = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function __construct(?AdminNoticeStore $s = null, ?AdminNoticeFeed $f = null, ?callable $clock = null, ?bool $inAdmin = null)
    {
        $this->clock   = $clock === null ? time(...) : \Closure::fromCallable($clock);
        $this->store   = $s ?? new AdminNoticeStore(null, $this->clock);
        $this->feed    = $f ?? new AdminNoticeFeed();
        $this->inAdmin = $inAdmin ?? (defined('OC_ADMIN') && OC_ADMIN);
    }

    /**
     * Store a one-off notice. The same id replaces the earlier one; the same content only
     * extends its life and keeps its time, so a plugin may raise it on every admin page.
     *
     * @internal raise() is the entry point
     */
    public function add(AdminNotice $n, int $ttl = self::DEFAULT_TTL): void
    {
        try {
            $notice = $this->clean($n);
            if ($notice === null) {
                return;
            }
            $ttl = $ttl > 0 ? $ttl : self::DEFAULT_TTL;
            $this->store->load();
            $since = $this->repeatSince($notice, $ttl);
            if ($since === -1) {
                return;
            }
            $this->store->reload();
            $oneOffs = $this->store->oneOffs();
            unset($oneOffs[$notice->id]);
            uasort($oneOffs, self::evictionOrder(...));
            while (count($oneOffs) >= self::MAX_ONE_OFF) {
                $oldest = array_key_first($oneOffs);
                $this->forget((string) $oldest);
                unset($oneOffs[$oldest]);
            }
            $this->store->putOneOff($notice->withSince($since ?? ($this->clock)()), $ttl);
            $this->views = [];
        } catch (\Throwable $e) {
            self::log('not stored: ' . $e->getMessage());
        }
    }

    /**
     * The stored copy's since when this notice repeats it, so the time stays; null when it is new.
     *
     * @return int|null -1 when the stored copy is still fresh and nothing need be written
     */
    private function repeatSince(AdminNotice $notice, int $ttl): ?int
    {
        $stored = $this->store->oneOffs()[$notice->id] ?? null;
        if ($stored === null || $stored->withSince(0)->toArray() !== $notice->withSince(0)->toArray()) {
            return null;
        }
        $expires = $this->store->oneOffExpires($notice->id);

        return $expires !== null && $expires >= ($this->clock)() + $ttl - intdiv($ttl, self::REWRITE_AFTER) ? -1 : $stored->since;
    }

    /**
     * Rows without a given id go first, so a notice with changing text cannot push out a real one.
     */
    private static function evictionOrder(AdminNotice $a, AdminNotice $b): int
    {
        return [!str_starts_with($a->id, self::AUTO_ID), $a->since] <=> [!str_starts_with($b->id, self::AUTO_ID), $b->since];
    }

    /**
     * Remove a notice for everyone, with every admin's hide of it.
     */
    public function clear(string $id): void
    {
        try {
            if (!self::validId($id)) {
                self::log('bad id on clear');

                return;
            }
            $this->store->reload();
            $this->forget($id);
            $this->views = [];
        } catch (\Throwable $e) {
            self::log('not cleared: ' . $e->getMessage());
        }
    }

    /**
     * Register a standing check for this request. Ignored outside the admin.
     *
     * @param array<string,mixed> $spec the osc_add_admin_notice() options plus id, type and text
     */
    public function addCheck(array $spec, callable $when): void
    {
        if (!$this->inAdmin) {
            return;
        }
        $id = $spec['id'] ?? null;
        if (!is_string($id) || !self::validId($id) || !in_array($spec['type'] ?? null, self::TYPES, true)) {
            self::log('check skipped: bad id or type');

            return;
        }
        $this->checks[$id] = [$spec, $when];
        $this->views       = [];
    }

    /**
     * Store a one-off notice from osc_add_admin_notice()'s options. Standing checks use addCheck().
     *
     * @param array<string,mixed> $options
     */
    public function raise(string $type, string|callable $text, array $options = []): void
    {
        try {
            $spec     = ['type' => $type, 'text' => $text] + $options;
            $resolved = self::resolve($text);
            if (!isset($spec['id'])) {
                $spec['id'] = self::AUTO_ID . substr(sha1($type . "\n" . $resolved), 0, 16);
            }
            $this->add($this->fromSpec(['text' => $resolved] + $spec), (int) ($options['ttl'] ?? self::DEFAULT_TTL));
        } catch (\Throwable $e) {
            self::log('not raised: ' . $e->getMessage());
        }
    }

    /**
     * What one admin sees. Runs once per admin per request.
     */
    public function view(int $admin, bool $moderator): AdminNoticeView
    {
        if ($admin <= 0) {
            return new AdminNoticeView();
        }
        $key = $admin . ':' . (int) $moderator;
        if (!isset($this->views[$key])) {
            try {
                $this->views[$key] = $this->build($admin, $moderator);
            } catch (\Throwable $e) {
                self::log('view failed: ' . $e->getMessage());
                $this->views[$key] = new AdminNoticeView();
            }
        }

        return $this->views[$key];
    }

    /**
     * Hide a notice from this admin for HIDE_DAYS.
     *
     * @return bool false when the notice is not active for this admin
     */
    public function hide(int $admin, bool $moderator, string $id): bool
    {
        foreach ($this->view($admin, $moderator)->active as $n) {
            if ($n->id === $id) {
                try {
                    $this->store->snooze($admin, $id, ($this->clock)() + self::HIDE_DAYS * 86400);
                    $this->views = [];

                    return true;
                } catch (\Throwable $e) {
                    self::log('not hidden: ' . $e->getMessage());

                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Show a hidden notice to this admin again.
     *
     * @return bool false when this admin had not hidden it
     */
    public function show(int $admin, string $id): bool
    {
        try {
            if (!isset($this->store->snoozed($admin)[$id])) {
                return false;
            }
            $this->store->unsnooze($admin, $id);
            $this->views = [];

            return true;
        } catch (\Throwable $e) {
            self::log('not shown: ' . $e->getMessage());

            return false;
        }
    }

    private function build(int $admin, bool $moderator): AdminNoticeView
    {
        $now = ($this->clock)();
        $this->store->load();
        [$feedRows, $complete] = $this->feed->notices($this->store, $admin, $moderator, $now);

        $standing = [];
        foreach ($feedRows as $row) {
            $notice = $this->clean($row);
            if ($notice !== null) {
                $standing[$notice->id] = $notice;
            }
        }
        $failed   = [];
        $standing = $this->runChecks($failed) + $standing;
        $since    = $this->store->since();

        foreach ($standing as $id => $notice) {
            if (!isset($since[$id])) {
                $this->store->setSince($id, $now);
                $since[$id] = $now;
            }
            $standing[$id] = $notice->withSince($since[$id]);
        }

        // A moderator's list can be shorter than the site's, so only an admin's page may end a notice.
        if ($complete && !$moderator) {
            foreach (array_keys(array_diff_key($since, $standing, $failed)) as $id) {
                $this->store->deleteSince((string) $id);
                $this->store->deleteSnoozes((string) $id);
            }
        }

        $snoozed = $this->store->snoozed($admin);
        $active  = [];
        $hidden  = [];
        foreach ($standing + $this->store->oneOffs() as $id => $notice) {
            if ($moderator && !$notice->moderator) {
                continue;
            }
            $until = $snoozed[$id] ?? 0;
            if ($until > $now) {
                $hidden[] = $notice->withHiddenUntil($until);
            } else {
                $active[] = $notice;
            }
        }

        return new AdminNoticeView($active, $hidden);
    }

    /**
     * @param array<string,bool> $failed filled with the ids whose check threw
     *
     * @return array<string,AdminNotice> by id, the checks that fire
     */
    private function runChecks(array &$failed): array
    {
        $rows = [];
        foreach ($this->checks as $id => [$spec, $when]) {
            try {
                if ($when()) {
                    $notice = $this->clean($this->fromSpec($spec));
                    if ($notice !== null) {
                        $rows[$id] = $notice;
                    }
                }
            } catch (\Throwable $e) {
                $failed[$id] = true;
                self::log('check ' . $id . ' threw: ' . $e->getMessage());
            }
        }

        return $rows;
    }

    private function forget(string $id): void
    {
        $this->store->deleteOneOff($id);
        $this->store->deleteSince($id);
        $this->store->deleteSnoozes($id);
    }

    /**
     * @param array<string,mixed> $spec
     */
    private function fromSpec(array $spec): AdminNotice
    {
        $string = static fn (string $key): string => is_string($spec[$key] ?? null) ? $spec[$key] : '';

        return new AdminNotice(
            $string('id'),
            $string('type'),
            self::resolve($spec['text'] ?? ''),
            self::resolve($spec['detail'] ?? ''),
            $string('link'),
            self::resolve($spec['link_label'] ?? ''),
            $string('menu'),
            $string('submenu'),
            $string('source'),
            !empty($spec['moderator'])
        );
    }

    /**
     * @return AdminNotice|null null when the notice breaks a rule that cannot be repaired
     */
    private function clean(AdminNotice $n): ?AdminNotice
    {
        $text = self::plain($n->text);
        if (!self::validId($n->id) || !in_array($n->type, self::TYPES, true) || $text === '') {
            self::log('skipped: bad id, type or empty text');

            return null;
        }
        $link = self::allowedLink($n->link) ? $n->link : '';

        return new AdminNotice(
            $n->id,
            $n->type,
            $text,
            self::plain($n->detail),
            $link,
            $link === '' ? '' : self::plain($n->linkLabel),
            self::plain($n->menu) ?: 'dash',
            self::plain($n->submenu),
            self::plain($n->source),
            $n->moderator,
            $n->since,
            $n->hiddenUntil
        );
    }

    /**
     * A plain string is always text, so a notice that reads like a function name is never run.
     */
    private static function resolve(mixed $value): string
    {
        if (!is_string($value) && is_callable($value)) {
            $value = $value();
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private static function plain(string $value): string
    {
        return mb_substr(trim(\mindstellar\utility\Sanitize::stripTags(mb_scrub($value, 'UTF-8'))), 0, self::MAX_TEXT, 'UTF-8');
    }

    private static function validId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_.:-]{0,100}$/D', $id) === 1;
    }

    private static function allowedLink(string $link): bool
    {
        if (preg_match('/[\x00-\x20\x7F]/', $link) === 1) {
            return false;
        }
        if ($link === '' || str_starts_with($link, '?page=')) {
            return true;
        }
        $base = rtrim((string) \osc_base_url(), '/') . '/';

        return preg_match('#^https?://#', $base) === 1 && str_starts_with($link, $base);
    }

    private static function log(string $message): void
    {
        error_log('Admin notice ' . $message);
    }
}
