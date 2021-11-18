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

    use RecursiveDirectoryIterator;
    use RecursiveIteratorIterator;

    /**
     * Created by TuffIndia.
     * User: navjottomer
     * Date: 14/11/17
     * Time: 12:36 PM
     */
    class tfcFilesClass {
        private static $instance;
        private $validFiles = array ();
        private $editDirectory;
        private $directoryScope = ABS_PATH;

        /**
         * tfcFilesClass constructor.
         */
        public function __construct() {
            if ( empty( $this->validFiles ) ) {
                $validFiles = array (
                    'php' ,
                    'htaccess' ,
                    'txt' ,
                    'conf' ,
                    'ini' ,
                    'sql' ,
                    'html' ,
                    'js' ,
                    'css' ,
                    'sh' ,
                    'md' ,
                    'xsl' ,
                    'xml'
                );
                $this->setValidFiles( $validFiles );
            }
        }


        /**
         * @return tfcFilesClass
         */
        public static function newInstance() {
            if ( ! self::$instance instanceof self ) {
                self::$instance = new self;
            }

            return self::$instance;
        }

        /**
         * @param $src
         * @param $dest
         */
        public static function copyFolder( $src , $dest ) {

            $path    = realpath( $src );
            $objects = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) , RecursiveIteratorIterator::SELF_FIRST );

            /** SplFileInfo $object*/
            foreach ( $objects as $name => $object ) {
                $startsAt = substr( dirname( $name ) , strlen( $src ) );

                self::mkDir( $dest . $startsAt );
                if ( is_writable( $dest . $startsAt ) && $object->isFile() ) {
                    copy( (string) $name , $dest . $startsAt . DIRECTORY_SEPARATOR . basename( $name ) );
                }
            }
        }

        /**
         * @param $folder
         * @param int $perm
         */
        private static function mkDir( $folder , $perm = 0755 ) {
            if ( ! is_dir( $folder ) && ! mkdir( $folder , $perm ) && ! is_dir( $folder ) ) {
                throw new \RuntimeException( sprintf( 'Directory "%s" was not created' , $folder ) );
            }
        }

        /**
         * @return array
         */
        public function scanDirectories() {
            $editdirectory = $this->getEditDirectory();

            return $this->scanDirList( $editdirectory );
        }

        /**
         * @return mixed
         */
        public function getEditDirectory() {
            return $this->editDirectory;
        }

        /**
         * @param mixed $editDirectory
         *
         * @return $this
         */
        public function setEditDirectory( $editDirectory ) {
            $this->editDirectory = $editDirectory;

            return $this;
        }

        /**
         * @param $dir
         * @param string $bool
         *
         * @return array
         */
        public function scanDirList( $dir , $bool = "dirs" ) {

            $truedir = rtrim( $dir , '/' );
            $dir     = scandir( $dir );
            if ( $bool === 'files' ) { // dynamic function based on second param
                $direct = 'is_dir';
            } elseif ( $bool === 'dirs' ) {
                $direct = 'is_file';
            }
            foreach ( $dir as $k => $v ) {
                if ( isset( $direct ) ) {
                    if ( $bool === 'dirs' ) {
                        //echo $truedir . $dir[ $k ];
                        if ( ! is_dir( $truedir . DIRECTORY_SEPARATOR . $dir[ $k ] ) ) {
                            unset( $dir[ $k ] );
                        } elseif ( $dir[ $k ] === '.' || $dir[ $k ] === '..' ) {
                            unset( $dir[ $k ] );
                        }
                    }
                    if ( $bool === 'files' ) {
                        $pathinfo = pathinfo( $truedir . DIRECTORY_SEPARATOR . $dir[ $k ] );
                        if ( isset( $pathinfo[ 'extension' ] ) ) {
                            if ( ! in_array( $pathinfo[ 'extension' ] , $this->getValidFiles() , false ) ) {
                                unset( $dir[ $k ] );
                            }
                        } else {
                            unset( $dir[ $k ] );
                        }
                    }
                }
            }
            $dir = array_values( $dir );

            return $dir;

        }

        /**
         * @return array
         */
        public function getValidFiles() {
            return $this->validFiles;
        }

        /**
         * @param array $validFiles
         *
         * @return tfcFilesClass
         */
        public function setValidFiles( $validFiles ) {
            $this->validFiles = $validFiles;

            return $this;
        }

        /**
         * @return array
         */
        public function scanFilenames() {
            $editdirectory = $this->getEditDirectory();

            return $this->scanDirList( $editdirectory , 'files' );

        }
    }