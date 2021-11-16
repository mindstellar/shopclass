<?php
/*
 * Shopclass - Free and open source theme for Osclass with premium features
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/shopclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
	namespace shopclass\includes\classes;
    use DAO;
    use ItemActions;
    use ItemStats;
    use WebThemes;

    /**
     * Class tfcDuplicateItem
     */
    class tfcDuplicateItem extends DAO
    {
        private static $instance;
        private $itemActions;
        private $duplicateEnabled = false;

        /**
         * tfcDuplicateItem constructor.
         */
        function __construct ()
        {
            parent::__construct ();
            $this->setTableName ( 't_item_dup' );
            $this->setPrimaryKey ( 'fk_i_item_id' );
            $this->setFields ( array ( 'fk_i_item_id' , 'dup_title' , 'dup_desc' ) );
            $this->itemActions = new ItemActions();
            if (tfc_getPref ('duplicate_helper')){
                $this->duplicateEnabled = true;
            }
        }

        /**
         * @return tfcDuplicateItem
         */
        public static function newInstance ()
        {
            if ( !self::$instance instanceof self ) {
                self::$instance = new self;
            }
            return self::$instance;
        }

        /**
         * @param $itemId
         * @param $itemTitle
         * @param $itemDescription
         * @return bool
         */
        public function insertDuplicateItem ($itemId , $itemTitle , $itemDescription)
        {
            $this->insert ( array ( 'fk_i_item_id' => $itemId , 'dup_title' => md5 ( $itemTitle ) , 'dup_desc' => md5 ( $itemDescription ) ) );
            return true;

        }

        /**
         * @param $itemId
         * @param $itemTitle
         * @param $itemDescription
         * @return bool
         */
        public function updateDuplicateItem ($itemId , $itemTitle , $itemDescription)
        {
            $this->updateByPrimaryKey ( array ( 'dup_title' => md5 ( $itemTitle ) , 'dup_desc' => md5 ( $itemDescription ) ) , $itemId );
            return true;
        }

        /**
         * @param $itemId
         * @return bool
         */
        public function deleteDuplicateItem ($itemId)
        {
            $this->deleteByPrimaryKey ( $itemId );
            return true;
        }

        /**
         * @param $itemTitle
         * @return int
         */
        public function isItemDuplicateByTitle ($itemTitle)
        {
            $this->dao->select ( 'COUNT(fk_i_item_id) AS count' );
            $this->dao->from ( $this->getTableName () );
            $this->dao->where ( array ( 'dup_title' => md5 ( $itemTitle ) ) );
            $result = $this->dao->get ();

            if ( $result == false ) {
                return 0;
            }

            if ( $result->numRows () == 0 ) {
                return 0;
            }

            $row = $result->row ();
            return $row[ 'count' ];

        }

        /**
         * @param $itemDescription
         * @return int
         */
        public function isItemDuplicateByDescription ($itemDescription)
        {
            $this->dao->select ( 'COUNT(fk_i_item_id) AS count' );
            $this->dao->from ( $this->getTableName () );
            $this->dao->where ( array ( 'dup_desc' => md5 ( $itemDescription ) ) );
            $result = $this->dao->get ();

            if ( $result == false ) {
                return 0;
            }

            if ( $result->numRows () == 0 ) {
                return 0;
            }

            $row = $result->row ();
            return $row[ 'count' ];
        }

        /**
         * @param $itemTitle
         * @param $itemDescription
         * @return int
         */
        public function isItemDuplicateByTitleAndDescription ($itemTitle , $itemDescription)
        {
            $this->dao->select ( 'COUNT(fk_i_item_id) AS count' );
            $this->dao->from ( $this->getTableName () );
            $this->dao->where ( array ( 'dub_title' => md5 ( $itemTitle ) , 'dup_desc' => md5 ( $itemDescription ) ) );
            $result = $this->dao->get ();

            if ( $result == false ) {
                return 0;
            }

            if ( $result->numRows () == 0 ) {
                return 0;
            }

            $row = $result->row ();
            return $row[ 'count' ];
        }

        /**
         * @param $itemTitle
         * @param $itemDescription
         * @return bool
         */
        public function isItemDuplicated ($itemTitle , $itemDescription)
        {

            $duplicateMethod = tfc_getPref ( 'duplicate_method' );
            switch ($duplicateMethod) {
                case '1':
                    $duplicateResult = $this->isItemDuplicateByTitle ( $itemTitle );
                    break;
                case '2':
                    $duplicateResult = $this->isItemDuplicateByDescription ( $itemDescription );
                    break;
                default:
                    $duplicateResult = $this->isItemDuplicateByTitleAndDescription ( $itemTitle , $itemDescription );
            }
            if ( $duplicateResult > 1 ) {
                return true;
            } else {
                return false;
            }

        }

        /**
         * @param $aItem
         */
        public function processDuplicateItem ($aItem)
        {
            if ($this->duplicateEnabled) {
                $itemId = $aItem[ 'pk_i_id' ];
                $itemTitle = $aItem[ 's_title' ];
                $itemDescription = $aItem[ 's_description' ];
                if ( $this->isItemDuplicated ( $itemTitle , $itemDescription ) ) {
                    $this->itemActions->disable ( $itemId );
                    $column = 'i_num_repeated';
                    ItemStats::newInstance ()->increase ( $column , $itemId );
                    osc_add_flash_info_message (__('Your listing is under review.','shopclass'));
                }
            }

        }

        /**
         * @return bool
         */
        public function importSql(){
            $path = WebThemes::newInstance ()->getCurrentThemePath () . 'assets/sql/duplicate-struct.sql';
            $sql = file_get_contents ( $path );
            $result = $this->dao->importSQL ($sql);
            return $result;
        }
    }