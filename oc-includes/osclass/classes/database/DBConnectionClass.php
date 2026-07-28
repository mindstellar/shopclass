<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Backward-compatibility subclass of the connection manager.
 *
 * The connection logic now lives in mindstellar\database\ConnectionManager. This
 * global-namespace name is kept because plugins and themes on live sites call
 * DBConnectionClass::newInstance()->getOsclassDb() directly — renaming or removing
 * it would break them. It exists only to preserve that surface; it adds no
 * behaviour beyond the deprecated getOsclassDb() alias.
 *
 * New code must use mindstellar\database\ConnectionManager (connection),
 * mindstellar\database\Connection (queries), or mindstellar\database\Db
 * (transactions / query builder).
 *
 * @package    Shopclass
 * @subpackage Database
 * @since      2.3
 */
class DBConnectionClass extends \mindstellar\database\ConnectionManager
{
    /**
     * Return the raw mysqli handle, or false when no connection is established.
     *
     * @deprecated 5.3 Use mindstellar\database\ConnectionManager::getHandle() for the
     *             raw handle, or the mindstellar\database\Connection / Db wrappers for
     *             queries and transactions. Retained for existing plugins and themes.
     *
     * @return mysqli|false
     */
    public function getOsclassDb()
    {
        return $this->getHandle();
    }
}

/* file end: ./oc-includes/osclass/classes/database/DBConnectionClass.php */
