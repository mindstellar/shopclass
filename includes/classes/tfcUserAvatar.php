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
     * Class tfcUserAvatar
     */
    class tfcUserAvatar extends DAO {

        private static $instance;

        /**
         * tfUserAvatar constructor.
         */
        function __construct() {
            parent::__construct();
            $this->setTableName( 't_user_avatar' );
            $this->setPrimaryKey( 'user_id' );
            $this->setFields( array ( 'user_id' , 'avatar_ext' ) );
        }

        /**
         * @return tfcUserAvatar
         */
        public static function newInstance() {
            if ( ! self::$instance instanceof self ) {
                self::$instance = new self;
            }

            return self::$instance;
        }

        /**
         * @param $userId
         *
         * @return mixed
         */
        public function is_user_has_avatar( $userId ) {

            $result = $this->findByPrimaryKey( $userId );

            return $result;

        }

        /**
         * @param $userId
         * @param $ext
         *
         * @return bool
         */
        public function insert_user_avatar( $userId , $ext ) {

            $avatar = array ( 'user_id' => $userId , 'avatar_ext' => $ext );

            return $this->insert( $avatar );

        }

        /**
         * @param $userId
         * @param $ext
         *
         * @return mixed
         */
        public function update_user_avatar( $userId , $ext ) {

            $avatar = array ( 'user_id' => $userId , 'avatar_ext' => $ext );

            return $this->updateByPrimaryKey( $avatar , $userId );

        }

        /**
         * @param $userId
         *
         * @return mixed
         */
        public function delete_user_avatar( $userId ) {

            return $this->deleteByPrimaryKey( $userId );

        }

    }