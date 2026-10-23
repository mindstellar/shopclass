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

namespace mindstellar\messaging;

use mindstellar\auth\Actor;
use mindstellar\database\Db;
use mindstellar\database\DbException;
use mindstellar\event\DispatcherInterface;
use mindstellar\exception\BlockedException;
use mindstellar\exception\ConflictException;
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\InvalidException;
use mindstellar\exception\NotFoundException;
use mindstellar\listing\ListingPolicy;
use mindstellar\listing\ListingStatus;
use mindstellar\listing\ListingStore;
use mindstellar\messaging\event\ConversationBlocked;
use mindstellar\messaging\event\ConversationReported;
use mindstellar\messaging\event\ConversationStarted;
use mindstellar\messaging\event\MessageHeld;
use mindstellar\messaging\event\MessageRead;
use mindstellar\messaging\event\MessageSending;
use mindstellar\messaging\event\MessageSent;
use mindstellar\security\ActionThrottle;
use mindstellar\security\MessageGuard;
use mindstellar\security\RateLimit;
use mindstellar\user\UserStore;
use mindstellar\utility\Clock;

/**
 * On-site messages between a listing's buyer and its seller, for the pages, the API and
 * plugins alike. The hooks fire from here, after the write has committed.
 */
final class MessagingService
{
    /** ConflictException reasons. */
    public const NO_SELLER        = 'no_seller';
    public const OWN_LISTING      = 'own_listing';
    public const LISTING_NOT_LIVE = 'listing_not_live';
    public const CLOSED           = MessagePolicy::CLOSED;
    public const HELD             = 'held';
    public const REPORTED         = 'reported';

    /** ForbiddenException reason when either side blocked the thread. */
    public const BLOCKED = MessagePolicy::BLOCKED;

    /** MessageHeld reasons set here; listeners give their own. */
    public const HELD_AKISMET = 'akismet';
    public const HELD_THREAD  = 'thread';

    private const DUPLICATE_KEY = 1062;
    private const MAX_BODY_BYTES = 65535;
    private const MAX_REASON     = 20;

    public function __construct(
        private ConversationStore $conversations,
        private MessageStore $messages,
        private DispatcherInterface $events,
        private Clock $clock
    ) {
    }

    /**
     * The buyer writes to a listing's seller. The first message starts the thread; later ones
     * continue it, even once the listing is gone.
     *
     * @throws ForbiddenException messaging off, a guest, an account that is not live, a ban or a block
     * @throws NotFoundException  a listing the actor cannot see
     * @throws ConflictException  a guest listing, the actor's own listing, a listing no longer live, a closed thread
     * @throws InvalidException   an empty, too long or refused body
     * @throws BlockedException   past an hourly limit
     */
    public function start(int $itemId, string $body, Actor $actor): SentMessage
    {
        $user = $this->sender($actor);
        $item = ListingStore::find($itemId, array('*'));
        if ($item === null || !ListingPolicy::canView($item, $actor)) {
            throw new NotFoundException(_m("This listing doesn't exist"));
        }
        $sellerId = (int) ($item['fk_i_user_id'] ?? 0);
        if ($sellerId <= 0) {
            throw new ConflictException(_m('This listing has no account to message'), self::NO_SELLER);
        }
        $buyerId = (int) $actor->userId();
        if ($sellerId === $buyerId) {
            throw new ConflictException(_m('You cannot message yourself about your own listing'), self::OWN_LISTING);
        }
        $conversation = $this->conversations->findByItemAndBuyer($itemId, $buyerId);
        if ($conversation === null && !ListingStatus::isLive($item, $this->clock->now())) {
            throw new ConflictException(_m('This listing is no longer available'), self::LISTING_NOT_LIVE);
        }

        return $this->send($user, $actor, $conversation, $itemId, $buyerId, $sellerId, $body, $item);
    }

    /**
     * A party writes in a thread they are in. Admins read threads but do not write in them.
     *
     * @throws ForbiddenException messaging off, a guest, an account that is not live, an admin, a ban or a block
     * @throws NotFoundException  a thread the actor is not part of
     * @throws ConflictException  a closed thread
     * @throws InvalidException   an empty, too long or refused body
     * @throws BlockedException   past the hourly limit
     */
    public function reply(int $conversationId, string $body, Actor $actor): SentMessage
    {
        $user         = $this->sender($actor);
        $conversation = $this->conversations->find($conversationId);
        if ($conversation === null || !MessagePolicy::canView($conversation, $actor)) {
            throw new NotFoundException(_m('No such conversation'));
        }
        if (!MessagePolicy::isParty($conversation, $actor)) {
            throw new ForbiddenException(_m('Admins cannot write in a conversation'), ForbiddenException::NOT_OWNER);
        }
        $buyerId  = (int) $conversation['fk_i_buyer_id'];
        $sellerId = (int) $conversation['fk_i_seller_id'];

        return $this->send($user, $actor, $conversation, (int) $conversation['fk_i_item_id'], $buyerId, $sellerId, $body, null);
    }

    /**
     * The thread's row, when the actor is a party or an admin; null otherwise.
     *
     * @return array<string,mixed>|null
     * @throws ForbiddenException messaging off, a guest, an account that is not live
     */
    public function find(int $conversationId, Actor $actor): ?array
    {
        $this->viewer($actor);
        $conversation = $this->conversations->find($conversationId);

        return $conversation !== null && MessagePolicy::canView($conversation, $actor) ? $conversation : null;
    }

    /**
     * One page of a thread, oldest first. Page back with the first message's id as $beforeId.
     * The actor sees sent messages and their own held ones.
     *
     * @return array{conversation: array<string,mixed>, messages: array<int,array<string,mixed>>}
     * @throws ForbiddenException messaging off, a guest, an account that is not live
     * @throws NotFoundException  a thread the actor may not see
     */
    public function thread(int $conversationId, Actor $actor, int $limit = 50, ?int $beforeId = null): array
    {
        $conversation = $this->find($conversationId, $actor);
        if ($conversation === null) {
            throw new NotFoundException(_m('No such conversation'));
        }
        $messages = $this->messages->ofConversation($conversationId, (int) $actor->userId(), $limit, $beforeId);

        return array('conversation' => $conversation, 'messages' => array_reverse($messages));
    }

    /**
     * One page of the actor's threads, newest first. Page on from the last row's
     * dt_last_message and pk_i_id.
     *
     * @return array<int,array<string,mixed>>
     * @throws ForbiddenException messaging off, a guest, an account that is not live
     */
    public function inbox(Actor $actor, int $limit = 20, ?string $beforeDate = null, ?int $beforeId = null): array
    {
        $this->sender($actor);

        return $this->conversations->inbox((int) $actor->userId(), $limit, $beforeDate, $beforeId);
    }

    /**
     * Clear the actor's unread count on the thread and return how many it held. Fires
     * `message_read` when that was more than none.
     *
     * @throws ForbiddenException messaging off, a guest, an account that is not live
     * @throws NotFoundException  a thread the actor is not part of
     */
    public function markRead(int $conversationId, Actor $actor): int
    {
        $this->sender($actor);
        $userId = (int) $actor->userId();

        return (int) Db::transaction(function () use ($conversationId, $actor, $userId): int {
            $conversation = $this->party($conversationId, $actor);
            $count        = $this->conversations->clearUnread($conversationId, (string) MessagePolicy::sideOf($conversation, $actor));
            if ($count > 0) {
                Db::afterCommit(fn () => $this->events->dispatch(new MessageRead($conversationId, $userId, $count)));
            }

            return $count;
        });
    }

    /**
     * Unread messages across the user's threads; 0 while messaging is off.
     */
    public function unreadCount(int $userId): int
    {
        if ($userId <= 0 || !osc_messaging_enabled()) {
            return 0;
        }

        return $this->conversations->unread($userId);
    }

    /**
     * The actor blocks the thread: neither side can write until they unblock it. Fires
     * `conversation_blocked` when the flag changes.
     *
     * @throws ForbiddenException messaging off, a guest, an account that is not live
     * @throws NotFoundException  a thread the actor is not part of
     */
    public function block(int $conversationId, Actor $actor): void
    {
        $this->sender($actor);
        Db::transaction(function () use ($conversationId, $actor): void {
            $this->setBlocked($this->party($conversationId, $actor), $actor, true);
        });
    }

    /**
     * Lift the actor's own block. A block set by the other side stays.
     *
     * @throws ForbiddenException messaging off, a guest, an account that is not live, or the other side's block
     * @throws NotFoundException  a thread the actor is not part of
     */
    public function unblock(int $conversationId, Actor $actor): void
    {
        $this->sender($actor);
        Db::transaction(function () use ($conversationId, $actor): void {
            $conversation = $this->party($conversationId, $actor);
            $side         = (string) MessagePolicy::sideOf($conversation, $actor);
            $other        = $side === ConversationStore::SIDE_BUYER ? 'b_seller_blocked' : 'b_buyer_blocked';
            if ((int) $conversation['b_' . $side . '_blocked'] !== 1 && (int) $conversation[$other] === 1) {
                throw new ForbiddenException(_m('Only the person who blocked this conversation can unblock it'), ForbiddenException::NOT_OWNER);
            }
            $this->setBlocked($conversation, $actor, false);
        });
    }

    /**
     * Report the thread to the admins. It also blocks it for the reporter. Fires
     * `conversation_reported`, then `conversation_blocked` when the block is new.
     *
     * @param string $reason a short code such as 'spam', lowercase letters and underscores
     *
     * @throws ForbiddenException messaging off, a guest, an account that is not live
     * @throws NotFoundException  a thread the actor is not part of
     * @throws InvalidException   a reason that is not a short code
     * @throws ConflictException  a closed thread, one waiting for review, or one already reported
     */
    public function report(int $conversationId, string $reason, Actor $actor): void
    {
        $this->sender($actor);
        $reason = trim($reason);
        if (preg_match('/^[a-z_]{1,' . self::MAX_REASON . '}$/', $reason) !== 1) {
            throw new InvalidException('/reason', 'pattern', _m('Choose a reason for the report'));
        }
        $userId = (int) $actor->userId();
        Db::transaction(function () use ($conversationId, $reason, $actor, $userId): void {
            $conversation = $this->party($conversationId, $actor);
            if ((string) $conversation['s_state'] === 'closed') {
                throw new ConflictException(_m('This conversation is closed'), self::CLOSED);
            }
            if ((string) $conversation['s_state'] === 'held') {
                // A report would show the seller a thread still waiting for review.
                throw new ConflictException(_m('This conversation is waiting for review'), self::HELD);
            }
            if ((string) $conversation['s_state'] === 'reported') {
                // The first report stands, so the reported side cannot replace it with their own.
                throw new ConflictException(_m('This conversation was already reported'), self::REPORTED);
            }
            $this->conversations->setReport($conversationId, $userId, $reason);
            Db::afterCommit(fn () => $this->events->dispatch(new ConversationReported($conversationId, $userId, $reason)));
            $this->setBlocked($conversation, $actor, true);
        });
    }

    /**
     * Rules 1 and 2 for a writer: messaging on, signed in, a live account. Returns the user row.
     *
     * @return array<string,mixed>
     * @throws ForbiddenException
     */
    private function sender(Actor $actor): array
    {
        if (!osc_messaging_enabled()) {
            throw new ForbiddenException(_m('Messages are switched off on this site'), ForbiddenException::DISABLED);
        }
        if ($actor->userId() === null) {
            throw new ForbiddenException(_m('Sign in to use messages'), ForbiddenException::SIGN_IN);
        }
        $user = UserStore::find($actor->userId());
        if ($user === null || !UserStore::isLive($user)) {
            throw new ForbiddenException(_m('Your account cannot use messages'), ForbiddenException::DISABLED);
        }

        return $user;
    }

    /**
     * As sender(), but an admin with no user account may read.
     *
     * @throws ForbiddenException
     */
    private function viewer(Actor $actor): void
    {
        if (!$actor->isAdmin() || $actor->userId() !== null) {
            $this->sender($actor);
        }
    }

    /**
     * The thread's row, locked, when the actor is a party who can see it.
     *
     * @return array<string,mixed>
     * @throws NotFoundException
     */
    private function party(int $conversationId, Actor $actor): array
    {
        $conversation = $this->conversations->lock($conversationId);
        if ($conversation === null || !MessagePolicy::isParty($conversation, $actor) || !MessagePolicy::canView($conversation, $actor)) {
            throw new NotFoundException(_m('No such conversation'));
        }

        return $conversation;
    }

    /**
     * Write the actor's block flag inside the open transaction; the event fires after commit.
     *
     * @param array<string,mixed> $conversation the locked row
     */
    private function setBlocked(array $conversation, Actor $actor, bool $blocked): void
    {
        $side = (string) MessagePolicy::sideOf($conversation, $actor);
        if (((int) $conversation['b_' . $side . '_blocked'] === 1) === $blocked) {
            return;
        }
        $id     = (int) $conversation['pk_i_id'];
        $userId = (int) $actor->userId();
        $this->conversations->setBlocked($id, $side, $blocked);
        Db::afterCommit(fn () => $this->events->dispatch(new ConversationBlocked($id, $userId, $blocked)));
    }

    /**
     * Rules 4 to 11 for one message, from the sender's checks to the events after commit.
     *
     * @param array<string,mixed>      $user         the sender's row
     * @param array<string,mixed>|null $conversation null for a thread not started yet
     * @param array<string,mixed>|null $item         the listing, when start() loaded it
     */
    private function send(array $user, Actor $actor, ?array $conversation, int $itemId, int $buyerId, int $sellerId, string $body, ?array $item): SentMessage
    {
        $senderId    = (int) $actor->userId();
        $recipientId = $senderId === $buyerId ? $sellerId : $buyerId;

        if ((int) osc_is_banned((string) $user['s_email'], $actor->ip(), MessageGuard::SCOPE) !== 0) {
            throw new ForbiddenException(_m('You cannot send messages'), ForbiddenException::BANNED);
        }
        if ($conversation !== null) {
            $this->assertCanSend($conversation, $senderId);
        } elseif ($this->conversations->blockedBetween($buyerId, $sellerId)) {
            // A block on one listing's thread also stops new threads on the seller's other listings.
            throw new ForbiddenException(_m('This conversation is blocked'), self::BLOCKED);
        }
        $body = $this->checkBody($body);
        if ($this->tooMany($actor, MessagePolicy::SEND_CONTEXT)) {
            throw BlockedException::rateLimit(_m('Too many messages in an hour. Try again later.'), $this->retryAfter(MessagePolicy::SEND_CONTEXT));
        }
        if ($conversation === null && $this->tooMany($actor, MessagePolicy::START_CONTEXT)) {
            throw BlockedException::rateLimit(_m('Too many new conversations in an hour. Try again later.'), $this->retryAfter(MessagePolicy::START_CONTEXT));
        }

        $sending = new MessageSending($body, $itemId, $senderId, $recipientId, $conversation !== null ? (int) $conversation['pk_i_id'] : null);
        $this->events->dispatch($sending);
        if ($sending->isRefused()) {
            throw new InvalidException('/body', 'refused', (string) $sending->refusal());
        }
        $body       = $this->checkBody($sending->body());
        $holdReason = $sending->isHeld() ? (string) $sending->holdReason() : null;
        if ($holdReason === null && $this->isSpam($user, $body, $itemId, $item)) {
            $holdReason = self::HELD_AKISMET;
        }

        $now = date('Y-m-d H:i:s', $this->clock->now());

        return Db::transaction(function () use ($itemId, $buyerId, $sellerId, $senderId, $recipientId, $body, $holdReason, $now): SentMessage {
            // Two first messages from one buyer wait here, so the second finds the thread. Every
            // read below locks, so each sees the newest committed row.
            UserStore::lock($buyerId);
            $started      = false;
            $conversation = $this->conversations->lockByItemAndBuyer($itemId, $buyerId)
                ?? $this->createConversation($itemId, $buyerId, $sellerId, $holdReason !== null, $now, $started);
            $conversationId = (int) $conversation['pk_i_id'];
            $this->assertCanSend($conversation, $senderId);
            if ($holdReason === null && (string) $conversation['s_state'] === 'held') {
                // The seller does not see a held thread, so nothing more in it reaches them.
                $holdReason = self::HELD_THREAD;
            }
            $held      = $holdReason !== null;
            $messageId = $this->messages->create(array(
                'fk_i_conversation_id' => $conversationId,
                'fk_i_sender_id'       => $senderId,
                's_body'               => $body,
                's_state'              => $held ? 'held' : 'sent',
                'dt_created'           => $now,
            ));
            if (!$held) {
                $this->conversations->touch($conversationId, $recipientId === $sellerId ? ConversationStore::SIDE_SELLER : ConversationStore::SIDE_BUYER, $now);
            }

            Db::afterCommit(function () use ($started, $held, $conversationId, $messageId, $itemId, $buyerId, $sellerId, $senderId, $recipientId, $holdReason): void {
                if ($started && !$held) {
                    $this->events->dispatch(new ConversationStarted($conversationId, $itemId, $buyerId, $sellerId));
                }
                $this->events->dispatch($held
                    ? new MessageHeld($messageId, $conversationId, $senderId, $recipientId, (string) $holdReason)
                    : new MessageSent($messageId, $conversationId, $senderId, $recipientId));
            });

            return new SentMessage($conversationId, $messageId, $held, $started);
        });
    }

    /**
     * Insert the thread and return its locked row. A duplicate key means another request
     * started it first: use that one.
     *
     * @return array<string,mixed>
     * @throws DbException
     */
    private function createConversation(int $itemId, int $buyerId, int $sellerId, bool $held, string $now, bool &$started): array
    {
        try {
            $id = $this->conversations->create(array(
                'fk_i_item_id'    => $itemId,
                'fk_i_buyer_id'   => $buyerId,
                'fk_i_seller_id'  => $sellerId,
                's_state'         => $held ? 'held' : 'open',
                'dt_created'      => $now,
                'dt_last_message' => $now,
            ));
            $started = true;

            return (array) $this->conversations->lock($id);
        } catch (DbException $e) {
            $row = (int) $e->getCode() === self::DUPLICATE_KEY ? $this->conversations->lockByItemAndBuyer($itemId, $buyerId) : null;
            if ($row === null) {
                throw $e;
            }

            return $row;
        }
    }

    /**
     * @param array<string,mixed> $conversation
     *
     * @throws ForbiddenException|ConflictException|NotFoundException
     */
    private function assertCanSend(array $conversation, int $userId): void
    {
        switch (MessagePolicy::canSend($conversation, $userId)) {
            case MessagePolicy::NOT_PARTY:
                throw new NotFoundException(_m('No such conversation'));
            case MessagePolicy::BLOCKED:
                throw new ForbiddenException(_m('This conversation is blocked'), self::BLOCKED);
            case MessagePolicy::CLOSED:
                throw new ConflictException(_m('This conversation is closed'), self::CLOSED);
        }
    }

    /**
     * The trimmed body, or the reason it cannot be sent.
     *
     * @throws InvalidException
     */
    private function checkBody(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            throw new InvalidException('/body', 'minLength', _m('Please type a message'));
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new InvalidException('/body', 'maxLength', _m('The message is too long.'));
        }
        $error = MessageGuard::messageError($body);
        if ($error !== null) {
            throw new InvalidException('/body', 'refused', $error);
        }

        return $body;
    }

    /**
     * Whether Akismet, when the site has a key, calls the message spam. Runs before the write,
     * so no transaction waits on it.
     *
     * @param array<string,mixed>      $user
     * @param array<string,mixed>|null $item
     */
    private function isSpam(array $user, string $body, int $itemId, ?array $item): bool
    {
        if (!osc_akismet_key()) {
            return false;
        }
        $item ??= ListingStore::find($itemId, array('*'));
        $akismet = new \Akismet(osc_base_url(), osc_akismet_key());
        $akismet->setCommentType('message');
        $akismet->setCommentAuthor((string) $user['s_name']);
        $akismet->setCommentAuthorEmail((string) $user['s_email']);
        $akismet->setCommentContent($body);
        if ($item !== null) {
            $akismet->setPermalink(osc_item_url_from_item($item));
        }

        return (bool) $akismet->isCommentSpam();
    }

    /**
     * Count one message for the context and say whether it is over the hourly limit, per user.
     * Fails open, as RateLimit does.
     */
    private function tooMany(Actor $actor, string $context): bool
    {
        [$max, $window] = self::limit($context);
        if ($actor->userId() === null || $max <= 0) {
            return false;
        }

        return !RateLimit::hit($context, 'user:' . $actor->userId(), $max, $window, true, $this->clock->now());
    }

    /**
     * Seconds until the context's window starts again.
     */
    private function retryAfter(string $context): int
    {
        $window = max(1, self::limit($context)[1]);

        return $window - ($this->clock->now() % $window);
    }

    /**
     * @return array{0:int,1:int} max and window in seconds
     */
    private static function limit(string $context): array
    {
        $default = $context === MessagePolicy::START_CONTEXT ? MessagePolicy::PER_HOUR_START : MessagePolicy::PER_HOUR_SEND;

        return ActionThrottle::limitFor($context, $default, 3600);
    }
}
