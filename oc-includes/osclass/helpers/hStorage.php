<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\storage\StorageManager;

/**
 * Register a storage adapter with the storage manager.
 *
 * Plugins call this on the 'register_storage_adapters' hook, e.g.:
 *   osc_add_hook('register_storage_adapters', function () {
 *       osc_register_storage_adapter(new My_S3_Adapter());
 *   });
 *
 * @param \mindstellar\storage\StorageAdapter $adapter
 *
 * @return void
 */
function osc_register_storage_adapter($adapter)
{
    StorageManager::instance()->register($adapter);
}

StorageManager::instance()->boot();

// Plugins are loaded after this file but before 'init' fires (which happens
// once per request, from BaseModel::__construct), so this is the first safe
// point for a plugin-provided adapter to register itself.
osc_add_hook('init', static function () {
    osc_run_hook('register_storage_adapters', StorageManager::instance());
});
