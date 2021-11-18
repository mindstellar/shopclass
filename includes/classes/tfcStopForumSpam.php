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

    use BanRule;
    use ItemActions;

    /**
     * Created by Navjot Tomer.
     * User: navjottomer
     * Date: 07/11/17
     * Time: 1:25 PM
     * Class: tfcStopForumSpam
     */
    class tfcStopForumSpam extends BanRule {

        private static $instance;
        private $frequencyThreshold = 2;
        private $confidenceThreshold = 55;
        private $isEnabledSPF = false;
        private $apiURL = 'https://api.stopforumspam.org/api?';
        private $itemActions;

        /**
         * tfcStopForumSpam constructor.
         */
        public function __construct() {
            parent::__construct();
            if ( tfc_getPref( 'spf_frequency_threshold' ) ) {
                $this->frequencyThreshold = tfc_getPref( 'spf_frequency_threshold' );
            }
            if ( tfc_getPref( 'spf_confidence_threshold' ) ) {
                $this->confidenceThreshold = tfc_getPref( 'spf_confidence_threshold' );
            }
            if ( tfc_getPref( 'spfs_enabled' ) ) {
                $this->isEnabledSPF = true;
            }
            $this->itemActions = new ItemActions();
        }

        /**
         * @return tfcStopForumSpam
         */
        public static function newInstance() {
            if ( ! self::$instance instanceof self ) {
                self::$instance = new self;
            }

            return self::$instance;
        }

        /**
         * @param $aItem
         */
        public function processSPFRequest( $aItem ) {
            $userEmail = $aItem[ 's_contact_email' ];
            if ( $this->isEnabledSPF ) {
                if ( $this->isUserIsSpammer( $userEmail ) ) {
                    $this->insertUserBanRule( $userEmail );
                    if ( ! empty( $aItem[ 'pk_i_id' ] ) ) {
                        $this->itemActions->disable( $aItem[ 'pk_i_id' ] );
                    }
                }

            }

        }

        /**
         * @param $userEmail
         *
         * @return bool
         */
        public function isUserIsSpammer( $userEmail ) {
            $isSpammer = false;
            if ( $this->isEnabledSPF ) {
                $requestSPF = $this->requestToSPF( $userEmail );
                //return {"success":1,"email":{"lastseen":"2016-08-28 16:07:27","frequency":255,"appears":1,"confidence":99.95}}
                $resultSPF = json_decode( $requestSPF , true );
                if ( $resultSPF[ 'success' ] ) {
                    $emailSPF = $resultSPF[ 'email' ];
                    if ( isset( $emailSPF ) ) {
                        $appereance = $emailSPF[ 'appears' ];
                        if ( $appereance > 0 ) {
                            if ( isset( $emailSPF[ 'frequency' ] ) ) {
                                $frequency = $emailSPF[ 'frequency' ];
                                if ( $frequency > $this->frequencyThreshold ) {
                                    $isSpammer = true;
                                }
                            }
                            if ( isset( $emailSPF[ 'confidence' ] ) ) {
                                $confidence = floor( $emailSPF[ 'confidence' ] );
                                if ( $confidence > $this->confidenceThreshold ) {
                                    $isSpammer = true;
                                }
                            }

                        }
                    }

                }
            }

            return $isSpammer;
        }

        /**
         * $data example
         *
         * @param $userEmail
         *
         * @return array|bool|mixed|string
         */
        public function requestToSPF( $userEmail ) {
            $url = $this->apiURL . 'email=' . urlencode( $userEmail ) . '&json';

            $result = tfc_curl_get_content( $url );

            return $result;
        }

        /**
         * @param $userEmail
         */
        public function insertUserBanRule( $userEmail ) {
            if ( ! $this->isAlreadyExists( $userEmail ) ) {
                $this->insert( array ( 's_name' => 'StopForumSpam' , 's_email' => $userEmail ) );
            }
        }

        /**
         * @param $userEmail
         *
         * @return bool
         */
        public function isAlreadyExists( $userEmail ) {
            $this->dao->select( 's_email' );
            $this->dao->from( $this->getTableName() );
            $this->dao->where( 's_email' , $userEmail );
            $result = $this->dao->get();
            if ( $result ) {
                $row = $result->row();
                if ( isset( $row[ 's_email' ] ) ) {
                    return $row[ 's_email' ];
                }

            }

            return false;
        }

    }