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
 * Page DAO
 */
class Page extends DAO
{
    /**
     *
     * @var Page
     */
    private static $instance;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_pages');
        $this->setPrimaryKey('pk_i_id');
        $array_fields = array(
            'pk_i_id',
            's_internal_name',
            'b_indelible',
            'b_link',
            'dt_pub_date',
            'dt_mod_date',
            'i_order',
            's_meta'
        );
        $this->setFields($array_fields);
    }

    /**
     * @return \Page
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Find a page by order.
     *
     * @access public
     *
     * @param      $order
     * @param null $locale
     *
     * @return array It returns page fields. If it has no results, it returns an empty array.
     * @since  unknown
     *
     */
    public function findByOrder($order, $locale = null)
    {
        try {
            $row = osc_db_table($this->getTableName())
                ->where('i_order', $order)
                ->where('b_indelible', 0)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if ($row === null) {
            return array();
        }

        return $this->extendDescription(osc_db_stringify_row($row), $locale);
    }

    /**
     * An array with data of some page, returns the title and description in every language available
     *
     * @access public
     *
     * @param array $aPage
     * @param null  $locale
     *
     * @return array Page information, title and description in every language available
     * @since  unknown
     */
    public function extendDescription($aPage, $locale = null)
    {
        $query = osc_db_table($this->getDescriptionTableName())
            ->where('fk_i_pages_id', $aPage['pk_i_id']);
        if (null !== $locale) {
            $query = $query->where('fk_c_locale_code', $locale);
        }

        try {
            $aDescriptions = osc_db_stringify_rows($query->get());
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if (count($aDescriptions) == 0) {
            return array();
        }

        $aPage['locale'] = array();
        foreach ($aDescriptions as $description) {
            if (!empty($description['s_title']) || !empty($description['s_text'])) {
                $aPage['locale'][$description['fk_c_locale_code']] = $description;
            }
        }

        return $aPage;
    }

    /**
     * @return string
     */
    public function getDescriptionTableName()
    {
        return $this->getTablePrefix() . 't_pages_description';
    }

    /**
     * Delete a page by internal name.
     *
     * @access public
     *
     * @param string $intName Page internal name which is going to be deleted
     *
     * @return bool True on successful removal, false on failure
     * @since  unknown
     */
    public function deleteByInternalName($intName)
    {
        $row = $this->findByInternalName($intName);

        return $this->deleteByPrimaryKey($row['pk_i_id']);
    }

    /**
     * Find a page by internal name.
     *
     * @access public
     *
     * @param string $intName Internal name of the page to find.
     * @param string $locale  Locale string.
     *
     * @return array It returns page fields. If it has no results, it returns an empty array.
     * @since  unknown
     */
    public function findByInternalName($intName, $locale = null)
    {
        try {
            $row = osc_db_table($this->getTableName())
                ->where('s_internal_name', $intName)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if ($row === null) {
            return array();
        }

        return $this->extendDescription(osc_db_stringify_row($row), $locale);
    }

    /**
     * Delete a page by id number.
     *
     * @access public
     *
     * @param int $id Page id which is going to be deleted
     *
     * @return bool|int @return mixed It return the number of affected rows if the delete has been
     *                correct or false if nothing has been modified
     * @since  unknown
     */
    public function deleteByPrimaryKey($id)
    {
        $row   = $this->findByPrimaryKey($id);
        $order = $row['i_order'];

        $this->reOrderPages($order);

        try {
            osc_db_table($this->getDescriptionTableName())->where('fk_i_pages_id', $id)->delete();
        } catch (\mindstellar\database\DbException $e) {
            // Discarded before this conversion too; the page delete below still runs.
        }

        try {
            return osc_db_table($this->tableName)->where('pk_i_id', $id)->delete();
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Find a page by page id.
     *
     * @access public
     *
     * @param int    $id     Page id.
     * @param string $locale By default is null but you can specify locale code.
     *
     * @return array Page information. If there's no information, return an empty array.
     * @since  unknown
     */
    public function findByPrimaryKey($id, $locale = null)
    {
        try {
            $row = osc_db_table($this->getTableName())
                ->where('pk_i_id', $id)
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if ($row === null) {
            return array();
        }

        $row = osc_db_stringify_row($row);

        // page_description
        $query = osc_db_table($this->getDescriptionTableName())
            ->where('fk_i_pages_id', $id);
        if (null !== $locale) {
            $query = $query->where('fk_c_locale_code', $locale);
        }
        $aRows = osc_db_stringify_rows($query->get());

        $row['locale'] = array();
        foreach ($aRows as $r) {
            $row['locale'][$r['fk_c_locale_code']] = $r;
        }

        return $row;
    }

    /**
     * Order pages from $order
     *
     * @access private
     *
     * @param int $order
     *
     * @return int|mixed
     * @since  unknown
     *
     */
    private function reOrderPages($order)
    {
        $aPages = $this->listAll(false);
        $arows  = 0;
        foreach ($aPages as $page) {
            if ($page['i_order'] > $order) {
                $new_order = $page['i_order'] - 1;
                try {
                    $arows += osc_db_table($this->tableName)
                        ->where('pk_i_id', $page['pk_i_id'])
                        ->update(array('i_order' => $new_order));
                } catch (\mindstellar\database\DbException $e) {
                    // Each row's outcome was already only summed, never checked,
                    // so one failing row must not stop the rest reordering.
                }
            }
        }

        return $arows;
    }

    /**
     * Get all the pages with the parameters you choose.
     *
     * @access public
     *
     * @param int   $indelible true if the page is indelible
     * @param null   $b_link
     * @param string $locale
     * @param int    $start
     * @param int    $limit
     *
     * @return array Return all the pages that have been found with the criteria selected. If there's no pages, the
     *                          result is an empty array.
     * @since  unknown
     *
     */
    public function listAll($indelible = null, $b_link = null, $locale = null, $start = null, $limit = null)
    {
        $query = osc_db_table($this->getTableName());
        if (null !== $indelible) {
            $query = $query->where('b_indelible', $indelible);
        }
        if ($b_link != null) {
            $query = $query->where('b_link', $b_link);
        }
        $query = $query->orderBy('i_order', 'ASC');
        if (null !== $limit) {
            // The legacy call emitted "LIMIT $limit, $start", and MySQL reads the
            // two-argument form as offset first and row count second -- so $limit
            // is the offset here and $start the number of rows, the reverse of
            // what the parameter names suggest. A zero offset collapses it to the
            // single-argument form. Preserved exactly; the admin pages table is
            // the only caller and reads these positions as they are.
            $query = $query->limit((int)$start);
            if ((int)$limit > 0) {
                $query = $query->offset((int)$limit);
            }
        }

        try {
            $aPages = osc_db_stringify_rows($query->get());
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        {
            if (count($aPages) == 0) {
                return array();
            }

            $resultPages = array();
            foreach ($aPages as $aPage) {
                $data = $this->extendDescription($aPage, $locale);
                if (count($data) > 0) {
                    $resultPages[] = $data;
                }
                unset($data);
            }

            return $resultPages;
        }

        return array();
    }

    /**
     * Return number of all pages, or only number of indelible pages
     *
     * @access public
     *
     * @param int $indelible
     *
     * @return int
     * @since  3.0
     */
    public function count($indelible = null)
    {
        $query = osc_db_table($this->getTableName());
        if (null !== $indelible) {
            $query = $query->where('b_indelible', $indelible);
        }

        try {
            // Cast back to a string: the aggregate reached callers as one before,
            // and the prepared path would hand them a native int.
            return (string)$query->count();
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }
    }

    /**
     * Insert a new page. You have to pass all the parameters
     *
     * @access public
     *
     * @param array $aFields            Fields to be inserted in pages table
     * @param array $aFieldsDescription An array with the titles and descriptions in every language.
     *
     * @return bool True if the insert has been done well and false if not.
     * @since  unknown
     */
    public function insert($aFields, $aFieldsDescription = null)
    {
        $order = osc_db_scalar('SELECT MAX(i_order) AS o FROM ' . $this->tableName);
        if (null === $order) {
            $order = -1;
        }

        if (!isset($aFields['b_link'])) {
            $aFields['b_link'] = 0;
        }

        if (($aFields['b_link'] == '') && $aFields['b_indelible'] == 1) {
            $aFields['b_link'] = 0;
        }

        // The id and the success check used to be read off the connection after
        // the write. The builder hands back the new id directly, and a write that
        // does not raise has inserted its row, which is what the affected-row
        // check was standing in for.
        try {
            $id = osc_db_table($this->tableName)->insert(array(
                's_internal_name' => $aFields['s_internal_name'],
                'b_indelible'     => $aFields['b_indelible'],
                'dt_pub_date'     => date('Y-m-d H:i:s'),
                'dt_mod_date'     => date('Y-m-d H:i:s'),
                'i_order'         => $order + 1,
                's_meta'          => $aFields['s_meta'] ?? null,
                'b_link'          => $aFields['b_link']
            ));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        foreach ($aFieldsDescription as $k => $v) {
            $affected_rows = $this->insertDescription($id, $k, $v['s_title'], $v['s_text']);
            if (!$affected_rows) {
                return false;
            }
        }

        return true;
    }

    /**
     * Insert the content (title and description) of a page.
     *
     * @access private
     *
     * @param int    $id     Id of the page, it would be the foreign key
     * @param string $locale Locale code of the language
     * @param string $title  Text to be inserted in s_title
     * @param string $text   Text to be inserted in s_text
     *
     * @return bool True if the insert has been done well and false if not.
     * @since  unknown
     */
    private function insertDescription($id, $locale, $title, $text)
    {

        try {
            osc_db_table($this->getDescriptionTableName())->insert(array(
                'fk_i_pages_id'    => $id,
                'fk_c_locale_code' => $locale,
                's_title'          => $title,
                's_text'           => $text
            ));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Find previous page
     *
     * @access public
     *
     * @param int $order
     *
     * @return array
     * @since  2.4
     *
     */
    public function findPrevPage($order)
    {
        try {
            $row = osc_db_table($this->tableName)
                ->where('b_indelible', 0)
                ->where('i_order', '<', (int)$order)
                ->orderBy('i_order', 'DESC')
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if ($row === null) {
            return array();
        }

        return osc_db_stringify_row($row);
    }

    /**
     * Find next page
     *
     * @access public
     *
     * @param int $order
     *
     * @return array
     * @since  2.4
     *
     */
    public function findNextPage($order)
    {
        try {
            $row = osc_db_table($this->tableName)
                ->where('b_indelible', 0)
                ->where('i_order', '>', (int)$order)
                ->orderBy('i_order', 'ASC')
                ->first();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if ($row === null) {
            return array();
        }

        return osc_db_stringify_row($row);
    }

    /**
     * Update the content (title and description) of a page
     *
     * @access public
     *
     * @param int    $id     Id of the page id is going to be modified
     * @param string $locale Locale code of the language
     * @param string $title  Text to be updated in s_title
     * @param string $text   Text to be updated in s_text
     *
     * @return int Number of affected rows.
     * @since  unknown
     */
    public function updateDescription($id, $locale, $title, $text)
    {
        $conditions = array('fk_c_locale_code' => $locale, 'fk_i_pages_id' => $id);
        $exist      = $this->existDescription($conditions);

        if (!$exist) {
            return $this->insertDescription($id, $locale, $title, $text);
        }

        try {
            return osc_db_table($this->getDescriptionTableName())
                ->where('fk_c_locale_code', $locale)
                ->where('fk_i_pages_id', $id)
                ->update(array('s_title' => $title, 's_text' => $text));
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Check if depending the conditions, the row exists in de DB.
     *
     * @access public
     *
     * @param array $conditions
     *
     * @return bool Return true if exists and false if not.
     * @since  unknown
     */
    public function existDescription($conditions)
    {
        $query = osc_db_table($this->getDescriptionTableName());
        foreach ($conditions as $key => $value) {
            $query = $query->where($key, $value);
        }

        return $query->count() > 0;
    }

    /**
     * It change the internal name of a page. Here you don't check if in indelible or not the page.
     *
     * @access public
     *
     * @param int    $id      The id of the page to be changed.
     * @param string $intName The new internal name.
     *
     * @return int Number of affected rows.
     * @since  unknown
     */
    public function updateInternalName($id, $intName)
    {
        $fields = array(
            's_internal_name' => $intName,
            'dt_mod_date'     => date('Y-m-d H:i:s')
        );
        try {
            return osc_db_table($this->tableName)
                ->where('pk_i_id', $id)
                ->update($fields);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * It changes the b_link of a page. Here you don't check if in indelible or not the page.
     *
     * @access public
     *
     * @param int    $id    The id of the page to be changed.
     * @param string $bLink The show link status.
     *
     * @return int Number of affected rows.
     * @since  unknown
     */
    public function updateLink($id, $bLink)
    {
        $fields = array(
            'b_link'      => $bLink,
            'dt_mod_date' => date('Y-m-d H:i:s')
        );
        try {
            return osc_db_table($this->tableName)
                ->where('pk_i_id', $id)
                ->update($fields);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * It change the meta field of a page.
     *
     * @access public
     *
     * @param int    $id   The id of the page to be changed.
     * @param string $meta The meta field
     *
     * @return int Number of affected rows.
     * @since  3.1
     */
    public function updateMeta($id, $meta)
    {
        $fields = array(
            's_meta'      => $meta,
            'dt_mod_date' => date('Y-m-d H:i:s')
        );
        try {
            return osc_db_table($this->tableName)
                ->where('pk_i_id', $id)
                ->update($fields);
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }
    }

    /**
     * Check if a page id is indelible
     *
     * @access public
     *
     * @param int $id Page id
     *
     * @return true if it's indelible, false in case not
     * @since  unknown
     */
    public function isIndelible($id)
    {
        $page = $this->findByPrimaryKey($id);

        return $page['b_indelible'] == 1;
    }

    /**
     * Check if Internal Name exists with another id
     *
     * @access public
     *
     * @param int    $id           page id
     * @param string $internalName page internal name
     *
     * @return true if internal name exists, false if not
     * @since  unknown
     */
    public function internalNameExists($id, $internalName)
    {
        return osc_db_table($this->tableName)
            ->where('s_internal_name', $internalName)
            ->where('pk_i_id', '<>', (int)$id)
            ->count() > 0;
    }

    /**
     * Public function to import email templates from json file
     * @param string JSON
     */
    public function importEmailJsonTemplates($json)
    {
        $json = json_decode($json, true);
        // check if the json is valid
        if (!$json) {
            return false;
        }
        // check if json has language code and templates array
        if (!isset($json['language'], $json['template'])) {
            return false;
        }

        $language = $json['language'];
        $templates = $json['template'];
        // check if templates array is not empty
        if (!$templates) {
            return false;
        }

        foreach ($templates as $template) {
            $result = $this->updateDescription($template['fk_i_page_id'], $language, $template['s_title'], $template['s_description']);
            if (!$result) {
                $errorPageIds [] = $template['fk_i_page_id'];
            }
        }
        return true;
    }


}

/* file end: ./oc-includes/osclass/model/Page.php */
