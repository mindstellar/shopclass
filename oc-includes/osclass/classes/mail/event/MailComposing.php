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

namespace mindstellar\mail\event;

use mindstellar\billing\Order;
use mindstellar\event\Bridged;
use mindstellar\event\Event;

/**
 * A shop mail is about to be sent. The subject and body are templates with {WORDS}; a listener may
 * edit either, change a word, or suppress the mail. Old callbacks on `email_<kind>_title`,
 * `_description`, `_title_after` and `_description_after` get the text and the mail's arguments:
 * (text, Order) for an order mail, (text, alert, item, kind) for a product alert.
 */
final class MailComposing extends Event implements Bridged
{
    public const KINDS = [
        'order_placed',
        'order_shipped',
        'order_delivered',
        'order_cancelled',
        'order_refunded',
        'admin_new_order',
        'product_alert',
    ];

    private bool $suppressed = false;

    /** @var list<array{0:string,1:callable}> after-callbacks on the rendered text: part, callback */
    private array $after = [];

    /** @var array<string,string> placeholder => text for the subject */
    private array $subjectWords;

    /** @var array<string,string> placeholder => HTML for the body */
    private array $bodyWords;

    /**
     * @param array<int,array<int,mixed>> $subjectWords osc_mailBeauty() words: [keys, values]
     * @param array<int,array<int,mixed>> $bodyWords    the same, for the body
     * @param array<string,mixed>|null    $alert        the t_alerts row of a product alert
     * @param array<string,mixed>|null    $item         the listing row of a product alert
     * @param list<mixed>                 $legacyArgs   what old callbacks get after the text
     */
    public function __construct(
        private string $kind,
        private string $to,
        private string $toName,
        private string $subject,
        private string $body,
        array $subjectWords,
        array $bodyWords,
        private ?Order $order = null,
        private ?array $alert = null,
        private ?array $item = null,
        private array $legacyArgs = []
    ) {
        $this->subjectWords = self::pairs($subjectWords);
        $this->bodyWords    = self::pairs($bodyWords);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function order(): ?Order
    {
        return $this->order;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function alert(): ?array
    {
        return $this->alert;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function item(): ?array
    {
        return $this->item;
    }

    public function recipient(): string
    {
        return $this->to;
    }

    public function recipientName(): string
    {
        return $this->toName;
    }

    /**
     * The subject template, before words are filled in.
     */
    public function subject(): string
    {
        return $this->subject;
    }

    /**
     * The body template (HTML), before words are filled in.
     */
    public function body(): string
    {
        return $this->body;
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    /**
     * The placeholders and their plain text, as the subject reads them.
     *
     * @return array<string,string>
     */
    public function words(): array
    {
        return $this->subjectWords;
    }

    /**
     * Add or replace a placeholder. $text is plain; $html is the body's HTML and defaults to $text escaped.
     */
    public function setWord(string $name, string $text, ?string $html = null): void
    {
        $this->subjectWords[$name] = $text;
        $this->bodyWords[$name]    = $html ?? nl2br(osc_esc_html($text));
    }

    /**
     * Do not send this mail.
     */
    public function suppress(): void
    {
        $this->suppressed = true;
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed;
    }

    /**
     * The subject and body with words filled in, as they stand now. Old `_after` callbacks do not run.
     *
     * @return array{subject:string,body:string}
     */
    public function preview(): array
    {
        return [
            'subject' => osc_mailBeauty(osc_apply_filter('email_title', $this->subject), $this->beautyWords($this->subjectWords)),
            'body'    => osc_mailBeauty(osc_apply_filter('email_description', $this->body), $this->beautyWords($this->bodyWords)),
        ];
    }

    /**
     * The mail as it is sent: the preview, then the old `_after` callbacks in the order they were added.
     *
     * @return array{subject:string,body:string}
     */
    public function render(): array
    {
        $mail = $this->preview();
        foreach ($this->after as [$part, $fn]) {
            $mail[$part] = (string) $fn($mail[$part], ...$this->legacyArgs);
        }

        return $mail;
    }

    public static function legacyNames(): array
    {
        $names = [];
        foreach (self::KINDS as $kind) {
            foreach (['title', 'description', 'title_after', 'description_after'] as $part) {
                $names[] = 'email_' . $kind . '_' . $part;
            }
        }

        return $names;
    }

    /**
     * Whether a callback added on $name is for this mail: only the names of its own kind.
     */
    public function accepts(string $name): bool
    {
        return strpos($name, 'email_' . $this->kind . '_') === 0;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $prefix = 'email_' . $this->kind . '_';
        if (strpos($name, $prefix) !== 0) {
            return;
        }
        $part  = substr($name, strlen($prefix));
        $after = substr($part, -6) === '_after';
        $part  = $after ? substr($part, 0, -6) : $part;
        if ($part !== 'title' && $part !== 'description') {
            return;
        }
        $key = $part === 'title' ? 'subject' : 'body';
        if ($after) {
            $this->after[] = [$key, $fn];
        } elseif ($key === 'subject') {
            $this->subject = (string) $fn($this->subject, ...$this->legacyArgs);
        } else {
            $this->body = (string) $fn($this->body, ...$this->legacyArgs);
        }
    }

    /**
     * @param array<int,array<int,mixed>> $words
     *
     * @return array<string,string>
     */
    private static function pairs(array $words): array
    {
        $keys   = array_values((array) ($words[0] ?? []));
        $values = array_values((array) ($words[1] ?? []));
        $map    = [];
        foreach ($keys as $i => $key) {
            $map[(string) $key] = (string) ($values[$i] ?? '');
        }

        return $map;
    }

    /**
     * @param array<string,string> $words
     *
     * @return array{0:list<string>,1:list<string>}
     */
    private function beautyWords(array $words): array
    {
        return [array_map('strval', array_keys($words)), array_values($words)];
    }
}
