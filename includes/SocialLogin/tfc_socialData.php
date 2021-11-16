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

    namespace shopclass\includes\SocialLogin;

    use DAO;
    use WebThemes;

    /**
     * Class tfc_socialData
     */
    class tfc_socialData extends DAO
    {

        /**
         * Singleton.
         */
        private static $instance;

        /**
         * tfc_socialData constructor.
         */
        function __construct ()
        {
            parent::__construct ();
            $this->setTableName ( 't_tfc_social' );
            $this->setPrimaryKey ( 'pk_i_id' );
            $this->setFields (
                [
                    'pk_i_id' ,
                    'fk_i_user_id' ,
                    'fk_i_social_id' ,
                    'fk_s_authorizer_name' ,
                    'fk_s_social_url' ,
                    'fk_s_social_pic'
                ]
            );
        }

        /**
         * @return tfc_socialData
         */
        public static function newInstance ()
        {
            if ( !self::$instance instanceof self ) {
                self::$instance = new self;
            }
            return self::$instance;
        }

        /**
         * @return string
         */
        public function getTableSocialData ()
        {
            return DB_TABLE_PREFIX . 't_tfc_social';
        }

        /**
         * @param $userId
         * @param $authorizerName
         * @return bool
         */
        public function is_user_connected ($userId , $authorizerName)
        {

            $socialUser = array ( 'fk_i_user_id' => $userId , 'fk_s_authorizer_name' => $authorizerName );
            $this->dao->select ( 'fk_i_social_id' );
            $this->dao->from ( $this->getTableName () );
            $this->dao->where ( $socialUser );
            $result = $this->dao->get ();
            //echo $this->dao->_getSelect ();
            if ( $result ) {
                $row = $result->row ();
                if ( isset( $row[ 'fk_i_social_id' ] ) ) {
                    return $row[ 'fk_i_social_id' ];
                }

            }
            return false;

        }

        /**
         * @param $SocialId
         * @param $authorizerName
         * @return bool
         */
        public function is_other_user_connected ($SocialId , $authorizerName)
        {

            $this->dao->select ( 'fk_i_user_id' );
            $this->dao->from ( $this->getTableName () );
            $this->dao->where ( 'fk_i_social_id' , $SocialId );
            $this->dao->where ( 'fk_s_authorizer_name' , $authorizerName );
            $result = $this->dao->get ();
            if ( $result ) {
                $row = $result->row ();
                if ( isset( $row[ 'fk_i_user_id' ] ) ) {
                    return $row[ 'fk_i_user_id' ];
                }

            }
            return false;

        }

        /**
         * @param $socialId
         * @param $authoriserName
         * @return bool
         */
        public function get_social_url ($socialId , $authoriserName)
        {
            $this->dao->select ( 'fk_s_social_url' );
            $this->dao->from ( $this->getTableName () );
            $this->dao->where ( 'fk_i_social_id' , $socialId );
            $this->dao->where ( 'fk_s_authorizer_name' , $authoriserName );
            $result = $this->dao->get ();
            if ( $result ) {
                $row = $result->row ();
                if ( isset( $row[ 'fk_s_social_url' ] ) ) {
                    return $row[ 'fk_s_social_url' ];
                }

            }
            return false;
        }

        /**
         * @param $socialId
         * @param $authorizerName
         * @return mixed bool|url
         */
        public function get_social_pic ($socialId , $authorizerName)
        {
            $this->dao->select ( 'fk_s_social_pic' );
            $this->dao->from ( $this->getTableName () );
            $this->dao->where ( 'fk_i_social_id' , $socialId );
            $this->dao->where ( 'fk_s_authorizer_name' , $authorizerName );
            $result = $this->dao->get ();
            if ( $result ) {
                $row = $result->row ();
                if ( isset( $row[ 'fk_s_social_pic' ] ) ) {
                    return $row[ 'fk_s_social_pic' ];
                }

            }
            return false;
        }

        /**
         * @param $userId
         * @param $authorizer
         * @return mixed
         */
        public function delete_authorizer_from_user ($userId , $authorizer)
        {
            $where = array ( 'fk_i_user_id' => $userId , 'fk_s_authorizer_name' => $authorizer );
            return $this->delete ( $where );
        }

        /**
         * @param $user
         * @return mixed
         */
        public function delete_social_user ($user)
        {
            $where = array ( 'fk_i_user_id' => $user );
            return $this->delete ( $where );

        }

        /**
         * @return bool
         */
        public function importSQL(){
            $path = WebThemes::newInstance ()->getCurrentThemePath () . 'assets/sql/social_login.sql';
            $sql = file_get_contents ( $path );
            return $this->dao->importSQL ($sql);
        }


    }