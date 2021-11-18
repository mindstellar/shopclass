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

    /**
     * Class tfcFavouriteItem
     * @package includes\classes
     */
    class tfcFavouriteItem extends DAO {

        /**
         * Singleton.
         */
        private static $instance;

        /**
         * tfc_fav constructor.
         */
        function __construct() {
            parent::__construct();
            $this->setTableName( 't_item_favourite' );
            $this->setPrimaryKey( 'id' );
            $this->setFields( array ( 'id' , 'fk_i_item_id' , 'fk_i_user_id' ) );
        }

        /**
         * Singleton constructor.
         * @return tfcFavouriteItem .
         */
        public static function newInstance() {
            if ( ! self::$instance instanceof self ) {
                self::$instance = new self;
            }

            return self::$instance;
        }

        /**
         * @return string
         */
        public function getTable_fav() {
            return DB_TABLE_PREFIX . 't_item_favourite';
        }

        /**
         * @param $itemId
         * @param $userId
         *
         * @return bool
         */
        public function is_favourite_item( $itemId , $userId ) {

            $favItem = array ( 'fk_i_item_id' => $itemId , 'fk_i_user_id' => $userId );
            $this->dao->select( 'id' );
            $this->dao->from( $this->getTableName() );
            $this->dao->where( $favItem );
            $result = $this->dao->get();
            $return = $result->row();
            if ( $return ) {
                return true;
            } else {
                return false;
            }

        }

        /**
         * @param $itemId
         * @param $userId
         *
         * @return bool
         */
        public function add_favourite_item( $itemId , $userId ) {
            $favItem = array ( 'fk_i_item_id' => $itemId , 'fk_i_user_id' => $userId );

            return $this->insert( $favItem );
        }

        /**
         * @param $itemId
         * @param $userId
         *
         * @return mixed
         */
        public function delete_favourite_from_user( $itemId , $userId ) {
            $where = array ( 'fk_i_item_id' => $itemId , 'fk_i_user_id' => $userId );

            return $this->delete( $where );
        }

        /**
         * @param $itemId
         *
         * @return mixed
         */
        public function delete_favourite_item_from_all( $itemId ) {
            $where = array ( 'fk_i_item_id' => $itemId );

            return $this->delete( $where );

        }

        /**
         * @param $userId
         *
         * @return mixed
         */
        public function delete_favourite_user( $userId ) {
            if ( is_array( $userId ) ) {
                $userId = $userId[ 'pk_i_id' ];
            }
            $where = array ( 'fk_i_user_id' => $userId );

            return $this->delete( $where );

        }

        /**
         * @param $itemId
         *
         * @return int
         */
        public function count_total_fav_by_itemId( $itemId ) {

            $this->dao->select( 'COUNT(fk_i_item_id) AS total' );
            $this->dao->where( 'fk_i_item_id = ' . $itemId );
            $this->dao->from( $this->getTableName() );
            $result = $this->dao->get();

            if ( $result == false ) {
                return 0;
            }

            if ( $result->numRows() == 0 ) {
                return 0;
            }

            $row = $result->row();

            return $row[ 'total' ];
        }

        /**
         * @param $userId
         *
         * @return int
         */
        public function count_total_fav_by_userId( $userId ) {

            $this->dao->select( 'COUNT(fk_i_item_id) AS total' );
            $this->dao->where( 'fk_i_user_id = ' . $userId );
            $this->dao->from( $this->getTableName() );
            $result = $this->dao->get();

            if ( $result == false ) {
                return 0;
            }

            if ( $result->numRows() == 0 ) {
                return 0;
            }

            $row = $result->row();

            return $row[ 'total' ];
        }
    }