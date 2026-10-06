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

use mindstellar\api\Problem;
use mindstellar\api\ProblemException;
use mindstellar\apiaccess\ApiSettings;

/**
 * The site preferences the read endpoints follow, read once per request.
 */
final class SiteFacts
{
    /**
     * @param array<string,array{name:string,direction:string}> $locales enabled locales by code
     */
    public function __construct(
        private string $defaultLocale,
        private array $locales,
        private bool $usersEnabled = true,
        private bool $commentsEnabled = true,
        private int $commentsPerPage = 10,
        private int $defaultLimit = 12,
        private int $maxLimit = 50,
        private bool $keepOriginal = false,
        private bool $hidePhone = false,
        private bool $publicReads = false
    ) {
    }

    public static function fromSite(ApiSettings $settings): self
    {
        $locales = [];
        foreach ((array) osc_get_locales() as $locale) {
            $locales[(string) $locale['pk_c_code']] = [
                'name'      => (string) $locale['s_name'],
                'direction' => ($locale['s_direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr',
            ];
        }
        $max = max(1, (int) osc_max_results_per_page_at_search());

        return new self(
            (string) osc_language(),
            $locales,
            (bool) osc_users_enabled(),
            (bool) osc_comments_enabled(),
            max(1, (int) osc_comments_per_page()),
            min($max, max(1, (int) osc_default_results_per_page_at_search())),
            $max,
            (bool) osc_keep_original_image(),
            $settings->hidePhone(),
            $settings->publicReads()
        );
    }

    /**
     * The locale a request asked for, or the site's default when it asked for none.
     *
     * @throws ProblemException 422 for a locale the site does not have
     */
    public function locale(string $asked): string
    {
        if ($asked === '') {
            return $this->defaultLocale;
        }
        if (!isset($this->locales[$asked])) {
            throw ProblemException::from(Problem::validation([
                ['pointer' => '/locale', 'code' => 'enum', 'message' => 'must be one of: ' . implode(', ', array_keys($this->locales)), 'in' => 'query'],
            ]));
        }

        return $asked;
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /**
     * @return array<string,array{name:string,direction:string}>
     */
    public function locales(): array
    {
        return $this->locales;
    }

    public function usersEnabled(): bool
    {
        return $this->usersEnabled;
    }

    public function commentsEnabled(): bool
    {
        return $this->commentsEnabled;
    }

    public function commentsPerPage(): int
    {
        return $this->commentsPerPage;
    }

    public function defaultLimit(): int
    {
        return $this->defaultLimit;
    }

    public function maxLimit(): int
    {
        return $this->maxLimit;
    }

    public function keepOriginal(): bool
    {
        return $this->keepOriginal;
    }

    public function hidePhone(): bool
    {
        return $this->hidePhone;
    }

    public function publicReads(): bool
    {
        return $this->publicReads;
    }
}
