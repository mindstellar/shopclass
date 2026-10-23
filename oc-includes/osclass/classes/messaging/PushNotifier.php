<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\messaging;

use mindstellar\database\Db;
use mindstellar\job\Job;
use mindstellar\messaging\event\MessageSent;
use mindstellar\push\PushSubscriptions;
use mindstellar\push\WebPushSender;
use mindstellar\user\UserStore;

/**
 * Wakes the recipient's browsers when new messages arrive. A sent message queues one short
 * delayed job per recipient; messages that arrive before it runs share that one wake-up.
 * The wake-up carries nothing: the browser asks the site what is new.
 */
final class PushNotifier
{
    public const JOB        = 'message.push';
    public const DELAY      = 15;
    public const MAX_ROUNDS = 4;

    public function __construct(
        private PushSubscriptions $subscriptions,
        private WebPushSender $sender,
        private ConversationStore $conversations
    ) {
    }

    public function onMessageSent(MessageSent $event): void
    {
        if (!osc_push_enabled()) {
            return;
        }
        $recipientId = $event->recipientId();
        Db::afterCommit(static function () use ($recipientId): void {
            osc_job_enqueue(
                self::JOB,
                array('recipient' => $recipientId),
                array('unique_key' => 'push:' . $recipientId, 'keep_existing' => true, 'delay' => self::DELAY)
            );
        });
    }

    /**
     * Wake the recipient's browsers unless push was turned off, the messages were read or the
     * user is gone. Browsers whose push service asked for a later try are woken again, and only those.
     *
     * @throws \mindstellar\database\DbException
     */
    public function run(Job $job): void
    {
        $recipientId = (int) $job->get('recipient', 0);
        if ($recipientId <= 0 || !osc_push_enabled() || $this->conversations->unread($recipientId) === 0) {
            return;
        }
        $user = UserStore::find($recipientId);
        if ($user === null || !UserStore::isLive($user)) {
            return;
        }

        $only = $job->get('only');
        $retry = array();
        foreach ($this->subscriptions->forUser($recipientId) as $key => $browser) {
            if (is_array($only) && !in_array($key, $only, true)) {
                continue;
            }
            $result = $this->sender->send($browser['endpoint']);
            if ($result === WebPushSender::GONE) {
                $this->subscriptions->removeEndpoint($key);
            } elseif ($result === WebPushSender::RETRY) {
                $retry[] = $key;
            }
        }

        $round = (int) $job->get('round', 1);
        if ($retry !== array() && $round < self::MAX_ROUNDS) {
            $job->repeat(array('recipient' => $recipientId, 'only' => $retry, 'round' => $round + 1), 60 * $round);
        }
    }
}
