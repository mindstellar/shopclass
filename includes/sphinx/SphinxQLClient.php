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

    namespace shopclass\includes\sphinx;
    /**
     * Class SphinxQLClient
     */
    class SphinxQLClient {

        const FETCH_NUM = 1;
        const FETCH_ASSOC = 2;
        const FETCH_OBJ = 3;

        /**
         * @var string The address of the server this client is to connect to
         */
        protected $_server = null;

        /**
         * @var string port of the server this client is to connect to
         */

        protected $_port = null;

        /**
         * @var \mysqli _handle resource A reference to the mysql link that this client will be using
         */
        protected $_handle = null;

        /**
         * @var \mysqli_result resource A reference to the mysql result returned by a query that this client has performed
         */
        protected $_result = null;

        /**
         * SphinxQLClient constructor.
         *
         * @param null $server
         * @param null $port
         */
        public function __construct( $server = null , $port = null ) {

            $this->_server = $server;
            $this->_port   = $port;
        }

        /**
         * @param $server
         */
        public function setServer( $server ) {

            $this->_server = $server;
        }

        /**
         * @param $port
         */
        public function setPort( $port ) {

            $this->_port = $port;
        }

        /**
         * Perform a query
         *
         * @param string $query The query to perform
         *
         * @return SphinxQLClient This client object
         *
         *
         */
        public function query( $query ) {
            $this->_result = false;
            $this->connect();

            $this->_result = $this->_handle->query( (string) $query );

            return $this;
        }

        /**
         * @return bool
         */
        protected function connect() {
            if ( $this->_handle ) {
                return true;
            }

            $this->_handle = mysqli_init();

            $this->_handle->options( MYSQLI_OPT_CONNECT_TIMEOUT , 2 );
            $this->_handle->real_connect( $this->_server , '' , '' , '' , $this->_port );

            return true;
        }

        /**
         * @param int $fetchStyle
         * @param null $class_name
         * @param null $params
         *
         * @return array|bool
         */
        public function fetchAll( $fetchStyle = self::FETCH_ASSOC , $class_name = null , $params = null ) {
            if ( $this->_result === false ) {
                return false;
            }

            $return = array ();

            while ( $row = $this->fetch( $fetchStyle , $class_name , $params ) ) {
                $return[] = $row;
            }

            return $return;
        }

        /**
         * @param int $fetchStyle
         * @param null $class_name
         * @param array|null $params
         *
         * @return array|bool|null|object|\stdClass
         *
         */
        public function fetch( $fetchStyle = self::FETCH_ASSOC , $class_name = null , array $params = null ) {
            if ( $this->_result === false ) {
                return false;
            }

            switch ( $fetchStyle ) {
                case self::FETCH_ASSOC:
                    if ( $row = $this->_result->fetch_assoc() ) {
                        return $row;
                    }

                    return array ();
                case self::FETCH_NUM:
                    if ( $row = $this->_result->fetch_row() ) {
                        return $row;
                    }

                    return array ();
                case self::FETCH_OBJ:
                    if ( $row = $this->_result->fetch_object( $class_name , $params ) ) {
                        return $row;
                    }

                    return null;
            }

            return false;
        }

    }
