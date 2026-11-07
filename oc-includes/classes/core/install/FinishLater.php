<?php

declare(strict_types=1);

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\install;

/**
 * What the install could not do, as the "To finish later" list on the done step.
 *
 * @package mindstellar\install
 */
final class FinishLater
{
    /**
     * @param array<string,mixed>                   $flags      the install_* session flags
     * @param array<string,string>                  $names      plugin slug => name
     * @param array{ok:bool, text:string}|null      $shopNotice install_shop_notice()
     */
    public function __construct(
        private readonly array $flags,
        private readonly array $names,
        private readonly ?array $shopNotice
    ) {
    }

    /**
     * @return list<array{what:string, how:string}>
     */
    public function items(): array
    {
        $items = [];
        if ((string) ($this->flags['install_location_failed'] ?? '') !== '') {
            $items[] = ['what' => __('Places for your country'), 'how' => __('Add them under Settings → Locations.')];
        }
        if (!empty($this->flags['install_starter_failed'])) {
            $items[] = ['what' => __('Starter categories'), 'how' => __('Add categories under Listings → Categories.')];
        }
        if (!empty($this->flags['install_storefront_failed'])) {
            $items[] = ['what' => __('The Storefront theme'), 'how' => __('Install it under Appearance → Add theme.')];
        }
        if (!empty($this->flags['install_sample_photos_failed'])) {
            $items[] = ['what' => __('Example photos'), 'how' => __('The example listings have no photos.')];
        }
        if (!empty($this->flags['install_sample_warning'])) {
            $items[] = [
                'what' => __("Some example listings weren't added"),
                'how'  => __('Your site works without them.'),
            ];
        }

        $failed = $this->flags['install_shop_failed'] ?? [];
        foreach (is_array($failed) ? $failed : [] as $slug => $fail) {
            $slug = (string) $slug;
            if ($slug === ShopSetup::THEME) {
                continue;
            }
            $message = trim((string) (is_array($fail) ? ($fail['message'] ?? '') : ''));
            $items[] = [
                'what' => ($this->names[$slug] ?? '') !== '' ? $this->names[$slug] : $slug,
                'how'  => ($message !== '' ? $message . ' ' : '') . __('Add it under Plugins → Add plugin.'),
            ];
        }

        if ($this->shopNotice !== null && !$this->shopNotice['ok']) {
            $items[] = ['what' => __('Shop mode'), 'how' => $this->shopNotice['text']];
        }

        return $items;
    }
}
