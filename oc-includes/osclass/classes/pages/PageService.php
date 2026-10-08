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

namespace mindstellar\pages;

use mindstellar\model\Resource;
use mindstellar\storage\ResourceUploader;
use mindstellar\widgets\WidgetStore;
use Page;

/**
 * Static page writes shared by the admin screens.
 */
final class PageService
{
    public function __construct(private Page $pages)
    {
    }

    public static function make(): self
    {
        return new self(Page::getInstance());
    }

    /**
     * Delete a page with its locale rows, its page-builder widgets and its uploaded images.
     * Fires before_delete_page and after_delete_page.
     *
     * @return int|false rows removed (0 for an unknown id), or false when the delete failed
     */
    public function delete(int $id): int|false
    {
        $deleted = $this->pages->deleteByPrimaryKey($id);
        if ($deleted === false || $deleted < 1) {
            return $deleted;
        }

        try {
            WidgetStore::deleteByLocation('page.' . $id);
        } catch (\mindstellar\database\DbException $e) {
            // The page is gone; stray widgets only stay hidden, as before.
        }
        // Remove the files now rather than waiting for the daily orphan sweep.
        (new ResourceUploader())->deleteByOwner(Resource::OWNER_PAGE, $id);

        return $deleted;
    }
}
