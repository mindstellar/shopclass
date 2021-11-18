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

    namespace shopclass\includes\cacheModal;

    /**
     * Simple Abstract Cache Modal
     * Class tfcAbstractCache
     */
    abstract class tfcAbstractCache {

        /**
         * @param $key
         *
         * @return mixed
         */
        abstract function tfcFetch( $key );

        /**
         * @param $key
         * @param $data
         * @param $ttl
         *
         * @return mixed
         */
        abstract function tfcStore( $key , $data , $ttl );

        /**
         * @param $key
         *
         * @return mixed
         */
        abstract function tfcDelete( $key );

        /**
         * @param $key
         *
         * @return bool
         */
        abstract function tfcExists( $key );

        /**
         * @return bool
         */
        abstract function flush();

    }