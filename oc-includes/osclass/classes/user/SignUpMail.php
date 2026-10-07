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

namespace mindstellar\user;

/**
 * Sends a sign-up's e-mails from the job queue, so an API sign-up with a new address takes as
 * long as one with a taken address. The e-mail hooks still run in the request, except the
 * activation e-mail: its job holds only the user id and makes the code when it runs.
 */
final class SignUpMail
{
    public const JOB = 'user.signup_mail';

    public const ACTIVATION_JOB = 'user.activation_mail';

    private function __construct()
    {
    }

    /**
     * Queue one composed e-mail, osc_sendMail()'s params.
     *
     * @param array<string,mixed> $params
     */
    public static function queue(array $params): void
    {
        osc_job_enqueue(self::JOB, $params);
    }

    /**
     * Queue the activation e-mail of an account not yet confirmed.
     */
    public static function queueActivation(int $userId): void
    {
        osc_job_enqueue(self::ACTIVATION_JOB, ['user' => $userId]);
    }

    /**
     * Store a fresh activation code and mail it, through `hook_email_user_validation`. An
     * account that is gone or already active gets nothing.
     *
     * @param (callable(array<string,mixed>): bool)|null $mailer sends one e-mail; osc_sendMail() by default
     *
     * @return bool whether a link went out
     * @throws \RuntimeException when the e-mail was not sent, so the job is tried again
     */
    public static function sendActivation(int $userId, ?callable $mailer = null): bool
    {
        $mailer ??= 'osc_sendMail';
        $failed   = false;
        $sent     = (new AccountService())->resendActivation($userId, false, static function (array $params) use ($mailer, &$failed): void {
            try {
                $failed = !$mailer($params) || $failed;
            } catch (\Throwable $e) {
                $failed = true;
            }
        });
        if ($failed) {
            throw new \RuntimeException('activation e-mail not sent');
        }

        return $sent;
    }

    public static function registerJobs(): void
    {
        osc_job_register_handler(self::JOB, static function ($job): void {
            if (!osc_sendMail($job->payload())) {
                throw new \RuntimeException('sign-up e-mail not sent');
            }
        });
        osc_job_describe(self::JOB, __('Sign-up e-mail'), static fn (array $payload): string => (string) ($payload['to'] ?? ''));
        osc_job_register_handler(self::ACTIVATION_JOB, static function ($job): void {
            self::sendActivation((int) ($job->payload()['user'] ?? 0));
        });
        osc_job_describe(self::ACTIVATION_JOB, __('Activation e-mail'), static fn (array $payload): string => sprintf(__('User #%d'), (int) ($payload['user'] ?? 0)));
    }
}
