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

namespace mindstellar\api\read;

use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\Links;

/**
 * Paging for one list request: sort, order, limit and where the cursor left off. Every list
 * endpoint pages through this, by keyset or by offset as Cursor::modeFor() picks, and asks
 * for one row more than the page so the next page is known without another query.
 */
final class Pager
{
    private function __construct(private ListSpec $spec, private Cursor $cursor, private int $limit, private string $hash, private ?CursorState $state, private Request $request, private string $path, private ?int $page = null)
    {
    }

    /**
     * @param string                   $path    the endpoint, below /api/{version}/, for the page links
     * @param array<string,mixed>|null $filters what the list is filtered by; a cursor works only for these. Null means the path and the query.
     *
     * @param bool                     $pages   whether `page` numbers the pages of this list
     * @throws ProblemException 422 for a limit out of range, or a page past the deepest offset (`page_too_deep`), 400 for a cursor this request cannot use
     */
    public static function fromRequest(Request $request, Cursor $cursor, ListSpec $spec, string $path, ?array $filters = null, bool $pages = false): self
    {
        $filters ??= ['list' => $path] + $request->query();
        $limit = $request->queryInt('limit', $spec->defaultLimit());
        if ($limit < 1 || $limit > $spec->maxLimit()) {
            throw ProblemException::field('/limit', 'maximum', 'must be between 1 and ' . $spec->maxLimit(), 'query');
        }
        unset($filters['sort'], $filters['order'], $filters['cursor'], $filters['limit'], $filters['page']);
        $page = null;
        if ($pages && $request->queryString('page') !== '') {
            $page = $request->queryInt('page');
            if ($page < 1) {
                throw ProblemException::field('/page', 'minimum', 'must be 1 or more', 'query');
            }
            if ($request->queryString('cursor') !== '') {
                throw ProblemException::field('/page', 'invalid', 'cannot be used with a cursor', 'query');
            }
            if (($page - 1) * $limit > $spec->maxOffset()) {
                throw ProblemException::of('page_too_deep', 'Page ' . $page . ' is past the ' . $spec->maxOffset() . ' listings a list reaches by page number. Narrow the filters.');
            }
        }
        $hash  = Cursor::filterHash($filters + ['sort' => $spec->sort(), 'order' => $spec->direction()]);
        $state = null;
        $raw   = $request->queryString('cursor');
        if ($raw !== '') {
            $state = $cursor->decode($raw, $hash, $spec->sorts(), $spec->maxOffset());
            $fits  = $state !== null && $state->sort() === $spec->sort() && $state->direction() === $spec->direction()
                && ($state->kind() !== CursorState::KEYSET || $spec->keysetFits($state->after()));
            if (!$fits) {
                throw ProblemException::of('invalid_cursor', 'Follow links.next with the same filters it was made for.');
            }
        }

        return new self($spec, $cursor, $limit, $hash, $state, $request, $path, $page);
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * The row id a by-id cursor left off at, or null on the first page.
     */
    public function afterId(): ?int
    {
        $after = $this->after();

        return $after === null ? null : (int) $after[0];
    }

    /**
     * This page as the answer, with its next link and, when asked for, its total.
     *
     * @param callable(): array<int,array<string,mixed>>          $fetch reads up to limit() + 1 rows from where the cursor left off
     * @param (callable(): ?int)|null                             $total counts every match; called only when counts(), after $fetch
     * @param callable(array<int,array<string,mixed>>): array<int,mixed> $shape this page's rows as the answer's data
     */
    public function respond(callable $fetch, ?callable $total, callable $shape, Links $links, array $meta = []): Response
    {
        $rows  = $fetch();
        $count = $total !== null && $this->counts() ? $total() : null;

        if ($this->page !== null) {
            $meta = ['page' => $this->page] + ($count === null ? [] : ['pages' => (int) ceil($count / $this->limit)]) + $meta;
        }

        return (new Page($shape($this->page($rows)), $count, $this->limit, $this->next($rows), $this->truncated($rows), $meta, $this->nextPage($rows)))->response($links, $this->path, $this->request->query(), $this->request->version());
    }

    public function offset(): int
    {
        if ($this->page !== null) {
            return ($this->page - 1) * $this->limit;
        }

        return $this->state !== null && $this->state->kind() === CursorState::OFFSET ? $this->state->offsetValue() : 0;
    }

    /**
     * Where a keyset cursor left off, or null on the first page and for offset paging.
     *
     * @return array<int,int|string|null>|null
     */
    public function after(): ?array
    {
        return $this->state !== null && $this->state->kind() === CursorState::KEYSET ? $this->state->after() : null;
    }

    /**
     * Whether to count the total: only when the caller sent `count=true`, and not on a keyset
     * page after the first, which would count only what is left.
     */
    public function counts(): bool
    {
        return $this->request->queryBool('count') && $this->after() === null;
    }

    /**
     * The rows of this page, without the look-ahead row.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    public function page(array $rows): array
    {
        return array_slice(array_values($rows), 0, $this->limit);
    }

    /**
     * The cursor for the next page, or null on the last one.
     *
     * @param array<int,array<string,mixed>> $rows what the query returned, up to limit + 1
     */
    public function next(array $rows): ?string
    {
        if (count($rows) <= $this->limit || $this->page !== null) {
            return null;
        }
        $sort      = $this->spec->sort();
        $direction = $this->spec->direction();
        if (Cursor::modeFor($sort) === CursorState::KEYSET) {
            return $this->cursor->encode(CursorState::keyset($sort, $direction, $this->hash, $this->spec->keyset(array_values($rows)[$this->limit - 1])));
        }
        $offset = $this->offset() + $this->limit;

        return $offset > $this->spec->maxOffset() ? null : $this->cursor->encode(CursorState::offset($sort, $direction, $this->hash, $offset));
    }

    /**
     * The number of the next page when the request asked by page number, or null on the last page.
     *
     * @param array<int,array<string,mixed>> $rows what the query returned, up to limit + 1
     */
    private function nextPage(array $rows): ?int
    {
        return $this->page !== null && count($rows) > $this->limit && $this->page * $this->limit <= $this->spec->maxOffset() ? $this->page + 1 : null;
    }

    /**
     * Whether more rows exist but offset paging stops here, so there is no next page.
     *
     * @param array<int,array<string,mixed>> $rows what the query returned, up to limit + 1
     */
    public function truncated(array $rows): bool
    {
        if ($this->page !== null) {
            return count($rows) > $this->limit && $this->nextPage($rows) === null;
        }

        return count($rows) > $this->limit && Cursor::modeFor($this->spec->sort()) !== CursorState::KEYSET
            && $this->offset() + $this->limit > $this->spec->maxOffset();
    }
}
