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

use mindstellar\billing\Document;
use mindstellar\billing\DocumentStore;
use mindstellar\event\Bridged;
use mindstellar\event\Event;

/**
 * An invoice or credit note was stored; fired once its write commits. Old callbacks on
 * `billing_document_issued` get ($documentId, $orderId, $type).
 */
final class DocumentIssued extends Event implements Bridged
{
    private ?Document $document = null;

    public function __construct(private int $documentId, private int $orderId, private string $type)
    {
    }

    public function documentId(): int
    {
        return $this->documentId;
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    /**
     * Document::INVOICE or Document::CREDIT_NOTE.
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * The stored document, or null when it cannot be read.
     */
    public function document(): ?Document
    {
        return $this->document ??= DocumentStore::find($this->documentId);
    }

    public static function legacyNames(): array
    {
        return ['billing_document_issued'];
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->documentId, $this->orderId, $this->type);
    }
}
