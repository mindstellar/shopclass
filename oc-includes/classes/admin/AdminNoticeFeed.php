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
 * Core's standing notices: the System info checks and the payment methods in test mode.
 * The checks run on a cached copy of the environment, read again once stale, and the class is not
 * final so tests can stand in for it.
 */
class AdminNoticeFeed
{
    public const FRESH_SECS = 900;

    private bool $refreshing = false;

    /** Where each notice belongs in the sidebar: menu, then sub-menu. Anything else goes under System info. */
    private const PLACES = [
        'admins_no_2fa'             => ['users', ''],
        'signin_protection_off'     => ['users', ''],
        'backups_open'              => ['tools', 'tools_backup'],
        'backup_none'               => ['tools', 'tools_backup'],
        'backup_old'                => ['tools', 'tools_backup'],
        'maintenance_on'            => ['tools', 'tools_maintenance'],
    ];

    /**
     * @return array{0:AdminNotice[], 1:bool} the standing notices, and whether the list is complete
     */
    public function notices(AdminNoticeStore $s, int $admin, bool $moderator, int $now): array
    {
        $rows      = $this->testModeNotices();
        $keyRows   = $this->keysMissingNotices();
        $modesRead = $rows !== null;
        $rows    ??= [];
        array_push($rows, ...$keyRows);
        $snapshot  = $s->checks() ?? $this->refresh($s, $now, true);
        if ($snapshot === null) {
            return [$rows, false];
        }
        if ($now - $snapshot['at'] > self::FRESH_SECS) {
            $this->refreshLater($s, $now);
        }

        $env = ['now' => $now, 'me' => $admin] + $snapshot['env'];
        foreach (SystemChecks::noticeIssues($env) as $issue) {
            $notice = $this->fromIssue($issue, $env, $moderator);
            if ($notice !== null) {
                $rows[] = $notice;
            }
        }

        return [$rows, $modesRead && $snapshot['at'] > 0];
    }

    /**
     * Keep a fresh copy of the environment, as when System info is opened.
     *
     * @param array<string,mixed> $env
     */
    public function remember(AdminNoticeStore $s, array $env, int $now): void
    {
        $s->saveChecks(SystemChecks::noticeEnv($env), $now);
    }

    /**
     * Drop the cached environment, so a fix shows on the next page.
     */
    public static function forget(): void
    {
        try {
            (new AdminNoticeStore())->forgetChecks();
        } catch (\Throwable $e) {
            error_log('Admin notice checks not dropped: ' . $e->getMessage());
        }
    }

    /**
     * Drop the cached environment when it says cron had stopped, so the notice ends after a run.
     */
    public static function cronRan(): void
    {
        try {
            $store = new AdminNoticeStore();
            $copy  = $store->checks();
            if ($copy !== null && time() - (int) ($copy['env']['cron_last'] ?? 0) > SystemChecks::CRON_MAX_AGE) {
                $store->forgetChecks();
            }
        } catch (\Throwable $e) {
            error_log('Admin notice cron check failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function readEnvironment(bool $quick): array
    {
        return SystemEnvironment::read(false, $quick);
    }

    /**
     * Run once the admin has their page. A job would read the CLI's settings, not the web server's.
     */
    protected function afterResponse(callable $work): void
    {
        // Nested, because the CSRF guard holds the page in a buffer until its own shutdown function.
        register_shutdown_function(static function () use ($work): void {
            register_shutdown_function(static function () use ($work): void {
                foreach (['fastcgi_finish_request', 'litespeed_finish_request'] as $finish) {
                    if (function_exists($finish)) {
                        $finish();
                        break;
                    }
                }
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                ignore_user_abort(true);
                @set_time_limit(60);
                $work();
            });
        });
    }

    /**
     * @return array<string,string> payment method name => settings URL
     */
    protected function testModes(): array
    {
        return \mindstellar\boot\CoreServices::dispatcher()->dispatch(new \mindstellar\billing\event\PaymentTestModes())->all();
    }

    /**
     * @return array<string,string> payment method name => settings URL
     */
    protected function keysMissing(): array
    {
        return \mindstellar\boot\CoreServices::dispatcher()->dispatch(new \mindstellar\billing\event\PaymentKeysMissing())->all();
    }

    private function refreshLater(AdminNoticeStore $s, int $now): void
    {
        if ($this->refreshing) {
            return;
        }
        $this->refreshing = true;
        $this->afterResponse(function () use ($s, $now): void {
            $this->refresh($s, $now, false);
            $this->refreshing = false;
        });
    }

    /**
     * One request pays for the refresh, and a failed read keeps the lock so the next try waits for it
     * to run out. A quick read is saved as out of date, so the full read follows.
     *
     * @return array{at:int, env:array<string,mixed>}|null null on a lost claim or a failed read
     */
    private function refresh(AdminNoticeStore $s, int $now, bool $quick): ?array
    {
        try {
            if (!$s->claimRefresh($now)) {
                return null;
            }
            $env = SystemChecks::noticeEnv($this->readEnvironment($quick));
            $at  = $quick ? 0 : $now;
            $s->saveChecks($env, $at);
            $s->releaseRefresh();

            return ['at' => $at, 'env' => $env];
        } catch (\Throwable $e) {
            error_log('Admin notice checks not refreshed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @param array<string,mixed> $issue a SystemChecks issue
     * @param array<string,mixed> $env
     */
    private function fromIssue(array $issue, array $env, bool $moderator): ?AdminNotice
    {
        $id     = (string) $issue['id'];
        $action = is_array($issue['action'] ?? null) ? $issue['action'] : [];
        $text   = (string) $issue['text'];
        $label  = (string) ($action['label'] ?? '');
        $url    = (string) ($action['url'] ?? '');

        if ($id === 'admins_no_2fa' && $moderator) {
            $mine = array_map('intval', array_column(array_filter((array) ($env['admins'] ?? []), static fn ($a): bool => empty($a['two_factor'])), 'id'));
            if (!in_array((int) $env['me'], $mine, true)) {
                return null;
            }
            [$text, $detail] = [__('You sign in with a password only.'), ''];
            $label = __('Turn on yours');
            $url   = (string) ($env['admin_url'] ?? '') . '?page=admins&action=edit';
        } else {
            [$text, $detail] = SystemChecks::splitSentence($text);
        }
        // A link out of the admin cannot be kept, so System info holds it.
        if ($url === '' || !(str_starts_with($url, '?page=') || str_starts_with($url, rtrim((string) osc_base_url(), '/') . '/'))) {
            $url   = SystemChecks::url($env, 'overview');
            $label = __('See details');
        }
        [$menu, $submenu] = self::PLACES[$id] ?? ['tools', 'tools_system_info'];

        return new AdminNotice(
            $id,
            ($issue['tone'] ?? '') === 'danger' ? 'error' : (($issue['tone'] ?? '') === 'info' ? 'info' : 'warning'),
            $text,
            $detail,
            $url,
            $label !== '' ? $label : __('See details'),
            $menu,
            $submenu,
            __('System info'),
            $id === 'admins_no_2fa'
        );
    }

    /**
     * One notice for each payment method still in test mode.
     *
     * @return AdminNotice[]|null null when the list could not be read
     */
    private function testModeNotices(): ?array
    {
        try {
            $modes = $this->testModes();
        } catch (\Throwable $e) {
            error_log('Admin notice test modes not read: ' . $e->getMessage());

            return null;
        }
        $rows = [];
        foreach ($modes as $name => $url) {
            $name = (string) $name;
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
            if ($slug === '' || isset($rows['pay_test:' . $slug])) {
                $slug = ($slug === '' ? 'x' : $slug) . '-' . substr(sha1($name), 0, 6);
            }
            $rows['pay_test:' . $slug] = new AdminNotice(
                'pay_test:' . $slug,
                'warning',
                sprintf(__('%s is in test mode: no real money is taken.'), $name),
                __('Switch it to live mode before the site takes payments.'),
                (string) $url,
                sprintf(__('%s settings'), $name),
                'billing',
                'billing_settings',
                $name
            );
        }

        return array_values($rows);
    }

    /**
     * One notice for each enabled payment method that has no keys yet. A failed read adds none.
     *
     * @return AdminNotice[]
     */
    private function keysMissingNotices(): array
    {
        try {
            $methods = $this->keysMissing();
        } catch (\Throwable $e) {
            error_log('Admin notice missing keys not read: ' . $e->getMessage());

            return [];
        }
        $rows = [];
        foreach ($methods as $name => $url) {
            $name = (string) $name;
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
            if ($slug === '' || isset($rows['pay_keys:' . $slug])) {
                $slug = ($slug === '' ? 'x' : $slug) . '-' . substr(sha1($name), 0, 6);
            }
            $rows['pay_keys:' . $slug] = new AdminNotice(
                'pay_keys:' . $slug,
                'warning',
                sprintf(__('%s is on but has no keys, so checkout cannot take payments.'), $name),
                __('Add its keys in its settings.'),
                (string) $url,
                sprintf(__('%s settings'), $name),
                'billing',
                'billing_settings',
                $name
            );
        }

        return array_values($rows);
    }
}
