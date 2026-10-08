<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\listing\UploadTmpStore;

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

    /**
     * Set data related to t_item_upload_tmp table
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_item_upload_tmp');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(array('pk_i_id', 's_token', 's_uuid', 's_file', 'dt_date'));
    }

    /**
     * Return the shared ItemTmpUpload model instance, creating it on first use.
     *
     * @return ItemTmpUpload
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Record a temp file just uploaded under a form's token.
     *
     * @param string $token
     * @param string $uuid client-side upload id
     * @param string $file temp filename (basename, under uploads/temp/)
     *
     * @return int rows written
     * @throws \mindstellar\database\DbException on a query failure
     */
    public function add($token, $uuid, $file)
    {
        UploadTmpStore::add((string)$token, (string)$uuid, (string)$file, date('Y-m-d H:i:s'));

        return 1;
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
     * @throws \mindstellar\database\DbException on a query failure
     */
    public function deleteByTokenFile($token, $file)
    {
        return UploadTmpStore::removeFile((string)$token, (string)$file);
    }

    /**
     * Whether this file was staged under this token.
     *
     * The authorisation the attach path needs: a name the poster did not upload is not one
     * they may attach, and the answer is looked up rather than derived from the name.
     *
     * @param string $token
     * @param string $file
     *
     * @return bool
     */
    public function belongsToToken($token, $file)
    {
        return UploadTmpStore::owns((string)$token, (string)$file);
    }

    /**
     * Forget every file staged under a token (e.g. on a fresh form or after a successful post).
     *
     * @param string $token
     *
     * @return int rows removed
     * @throws \mindstellar\database\DbException on a query failure
     */
    public function deleteByToken($token)
    {
        return UploadTmpStore::removeOwner((string)$token);
    }

    /**
     * Drop rows older than a moment. The temp files themselves are swept by the cron; this
     * clears the tracking rows abandoned uploads leave behind.
     *
     * @param string $before 'Y-m-d H:i:s'
     *
     * @return int rows removed
     * @throws \mindstellar\database\DbException on a query failure
     */
    public function pruneBefore($before)
    {
        return UploadTmpStore::pruneBefore((string)$before);
    }
}
