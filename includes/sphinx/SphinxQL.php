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
    use stdClass;

    /**
     * Class SphinxQL
     */
    class SphinxQL
    {

        protected static $_client;

        private static $server;
        private static $port;

        private static $instance = null;

        /**
         * SphinxQL constructor.
         * @param $server
         * @param $port
         */
        public function __construct ($server , $port)
        {
            self::$_client = new SphinxQLClient( $server , $port );
        }

        /**
         * @return null|SphinxQL
         */
        public static function getInstance ()
        {
            if ( is_null ( self::$instance ) ) {
                self::$instance = new self( self::$server , self::$port );
            }

            return self::$instance;
        }

        /**
         * @param $server
         * @param $port
         */
        public static function init ($server , $port)
        {
            self::$server = $server;
            self::$port = $port;
        }

        /**
         * @param $queryString
         * @return SphinxQLQuery
         */
        public static function fromString ($queryString)
        {
            return SphinxQLQuery::fromString ( $queryString );
        }

        /**
         * @param int $fetchStyle
         * @param null $class_name
         * @param array|null $params
         * @return array|bool|null|object|stdClass
         */
        public function fetch ($fetchStyle = SphinxQLClient::FETCH_ASSOC , $class_name = null , array $params = null)
        {
            return self::$_client->fetch ( $fetchStyle , $class_name , $params );
        }

        /**
         * @param null $params
         * @return array|bool|null|object|stdClass
         */
        public function fetchArray ($params = null)
        {
            return self::$_client->fetch ( SphinxQLClient::FETCH_NUM , null , $params );
        }

        /**
         * @param $class_name
         * @param null $params
         * @return array|bool|null|object|stdClass
         */
        public function fetchObject ($class_name , $params = null)
        {
            return self::$_client->fetch ( SphinxQLClient::FETCH_OBJ , $class_name , $params );
        }

        /**
         * @return array|bool
         */
        public function getMeta ()
        {
            $result = $this->query ( $this->getQuery ()->setTypeShow ( 'META' ) )->fetchAll ();
            if ( !$result ) return false;
            $meta = array ();
            foreach ($result as $key => $value) {
                $meta[ $value[ 'Variable_name' ] ] = $value[ 'Value' ];
            }
            return $meta;
        }

        /**
         * @return array|bool
         */
        public function fetchAll ()
        {
            return self::$_client->fetchAll ();
        }

        /**
         * @param SphinxQLQuery $query
         * @return $this
         */
        public function query (SphinxQLQuery $query)
        {
            self::$_client->query ( $query->toString () );
            return $this;
        }

        /**
         * @return SphinxQLQuery
         */
        public function getQuery ()
        {
            return new SphinxQLQuery();
        }
    }