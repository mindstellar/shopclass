<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * ItemTmpUpload DAO — photos uploaded to a listing form before the listing is saved.
 *
 * Replaces the $_SESSION['ajax_files'] list, so uploading a photo no longer starts a
 * session. Each row ties an uploaded temp file to the per-form upload token (an unguessable
 * cookie, see osc_upload_token()); the token is the capability that lets the "remove photo"
 * action delete only the files it uploaded. The final listing attaches its photos from the
 * submitted `ajax_photos` form field, not from here, so this table only backs the delete and
 * cleanup paths. Temp files are swept by the hourly cron; stale rows are pruned there too.
 */
class ItemTmpUpload extends DAO
{
    /** @var ItemTmpUpload */
    private static $instance;

    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_item_upload_tmp');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(array('pk_i_id', 's_token', 's_uuid', 's_file', 'dt_date'));
    }

    /**
     * @return ItemTmpUpload
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Record a temp file just uploaded under a form's token.
     *
     * @param string $token
     * @param string $uuid client-side upload id
     * @param string $file temp filename (basename, under uploads/temp/)
     *
     * @return int rows written
     */
    public function add($token, $uuid, $file)
    {
        return osc_db_execute(
            'INSERT INTO ' . $this->getTableName()
            . ' (s_token, s_uuid, s_file, dt_date) VALUES (?, ?, ?, ?)',
            array((string)$token, (string)$uuid, (string)$file, date('Y-m-d H:i:s'))
        );
    }

    /**
     * Delete one file's row, but only if it belongs to this token. The return value doubles
     * as authorisation: a positive count means the file really was uploaded under this token,
     * so the caller may unlink it; zero means it was not, so nothing is touched.
     *
     * @param string $token
     * @param string $file
     *
     * @return int rows removed
     */
    public function deleteByTokenFile($token, $file)
    {
        return osc_db_execute(
            'DELETE FROM ' . $this->getTableName() . ' WHERE s_token = ? AND s_file = ?',
            array((string)$token, (string)$file)
        );
    }

    /**
     * Forget every file staged under a token (e.g. on a fresh form or after a successful post).
     *
     * @param string $token
     *
     * @return int rows removed
     */
    public function deleteByToken($token)
    {
        return osc_db_execute(
            'DELETE FROM ' . $this->getTableName() . ' WHERE s_token = ?',
            array((string)$token)
        );
    }

    /**
     * Drop rows older than a moment. The temp files themselves are swept by the cron; this
     * clears the tracking rows abandoned uploads leave behind.
     *
     * @param string $before 'Y-m-d H:i:s'
     *
     * @return int rows removed
     */
    public function pruneBefore($before)
    {
        return osc_db_execute(
            'DELETE FROM ' . $this->getTableName() . ' WHERE dt_date <= ?',
            array($before)
        );
    }
}
