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

namespace mindstellar\billing\event;

use mindstellar\billing\Order;
use mindstellar\billing\Receipts;
use mindstellar\event\Bridged;
use mindstellar\event\Event;

/**
 * A goods receipt is being put together. A listener may set the title and footer and add rows under
 * the business details (extra) or under the lines (summary). Text is plain: control characters become
 * spaces and lengths are capped. Old callbacks on `billing_receipt_details` get ($details, $order) and
 * return the same array.
 */
final class ReceiptComposing extends Event implements Bridged
{
    private const MAX_ROWS = 30;

    private string $title;

    /** @var array<int,array{label:string,value:string}> */
    private array $extra = [];

    /** @var array<int,array{label:string,value:string}> */
    private array $summary = [];

    private string $footer = '';

    /**
     * @param array<string,mixed> $details the receipt's details, with a default 'title'
     */
    public function __construct(private Order $order, private array $details)
    {
        $this->title = (string) ($details['title'] ?? '');
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * Set the title. An empty one keeps the default.
     */
    public function setTitle(string $title): void
    {
        $title       = Receipts::plain($title, 100);
        $this->title = $title !== '' ? $title : (string) ($this->details['title'] ?? '');
    }

    public function addExtraRow(string $label, string $value): void
    {
        $this->addRow($this->extra, $label, $value);
    }

    public function addSummaryRow(string $label, string $value): void
    {
        $this->addRow($this->summary, $label, $value);
    }

    public function setFooter(string $footer): void
    {
        $this->footer = Receipts::plain($footer, 1000, true);
    }

    /**
     * @return array<int,array{label:string,value:string}>
     */
    public function extraRows(): array
    {
        return $this->extra;
    }

    /**
     * @return array<int,array{label:string,value:string}>
     */
    public function summaryRows(): array
    {
        return $this->summary;
    }

    public function footer(): string
    {
        return $this->footer;
    }

    /**
     * The details array as listeners have shaped it so far.
     *
     * @return array<string,mixed>
     */
    public function details(): array
    {
        return [
            'title'   => $this->title,
            'extra'   => $this->extra,
            'summary' => $this->summary,
            'footer'  => $this->footer,
        ] + $this->details;
    }

    public static function legacyNames(): array
    {
        return ['billing_receipt_details'];
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $asked = $fn($this->details(), $this->order);
        $asked = is_array($asked) ? $asked : [];
        $this->setTitle(Receipts::plain($asked['title'] ?? null, 100));
        $this->extra   = [];
        $this->summary = [];
        foreach ((array) ($asked['extra'] ?? null) as $row) {
            $this->addRow($this->extra, $row['label'] ?? null, $row['value'] ?? null);
        }
        foreach ((array) ($asked['summary'] ?? null) as $row) {
            $this->addRow($this->summary, $row['label'] ?? null, $row['value'] ?? null);
        }
        $this->footer = Receipts::plain($asked['footer'] ?? null, 1000, true);
    }

    /**
     * @param array<int,array{label:string,value:string}> $rows
     * @param mixed                                       $label
     * @param mixed                                       $value
     */
    private function addRow(array &$rows, $label, $value): void
    {
        $label = Receipts::plain($label, 200);
        $value = Receipts::plain($value, 500, true);
        if ($label !== '' && count($rows) < self::MAX_ROWS) {
            $rows[] = ['label' => $label, 'value' => $value];
        }
    }
}
