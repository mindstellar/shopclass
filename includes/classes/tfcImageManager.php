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

    /**
     * Class tfcImageManager
     */
    class tfcImageManager
    {
        protected $imageData;
        protected $imageWidth;
        protected $imageHeight;
        protected $uploadPath;
        protected $maxSize;
        protected $uploadName;
	    private $source_gdim;


	    /**
         * @param mixed $maxSize
         */
        public function setMaxSize ($maxSize = null)
        {
            if ( empty( $maxSize ) ) {
                $maxSize = $this->return_bytes ( ini_get ( 'upload_max_filesize' ) );
            }
            else{
                $maxSize = $this->return_bytes ($maxSize);
            }
            $this->maxSize = $maxSize;
        }

        /**
         * @param $val
         * @return int|string
         */
        public function return_bytes ($val)
        {
            $val = trim($val);
            $last = strtolower($val[strlen($val)-1]);
            $val = osc_sanitize_int($val);
            switch($last) {
                // The 'G' modifier is available since PHP 5.1.0
                case 'g':
                    $val *= (1024 * 1024 * 1024); //1073741824
                    break;
                case 'm':
                    $val *= (1024 * 1024); //1048576
                    break;
                case 'k':
                    $val *= 1024;
                    break;
            }

            return $val;
        }

        /**
         * @param mixed $imageData
         */
        public function setImageData ($imageData)
        {
            $this->imageData = $imageData;
        }

        /**
         * @param mixed $imageWidth
         */
        public function setImageWidth ($imageWidth)
        {
            $this->imageWidth = $imageWidth;
        }

        /**
         * @param mixed $imageHeight
         */
        public function setImageHeight ($imageHeight)
        {
            $this->imageHeight = $imageHeight;
        }

        /**
         * @param mixed $uploadPath
         */
        public function setUploadPath ($uploadPath)
        {
            $this->uploadPath = $uploadPath;
        }

        /**
         * @param mixed $uploadName
         */
        public function setUploadName ($uploadName)
        {
            $this->uploadName = $uploadName;
        }

	    /**
	     * @param $file
	     *
	     * @param $source_type
	     *
	     * @return bool
	     */
	    private function checkImageIsValid($file,$source_type){
			switch ($source_type) {
				case IMAGETYPE_GIF:
					$this->source_gdim = imagecreatefromgif ( $file );
					return true;
					break;
				case IMAGETYPE_JPEG:
					$this->source_gdim = imagecreatefromjpeg ( $file );
					return true;
					break;
				case IMAGETYPE_PNG:
					$this->source_gdim = imagecreatefrompng ( $file );
					return true;
					break;
				default:
					return false;
					break;
			}
		}

	    /**
	     * @param $source_width
	     * @param $source_height
	     * @param $desired_image_width
	     * @param $desired_image_height
	     * @param $source_gdim
	     * @param $dst_image
	     */
	    private function createImage( $source_width , $source_height, $desired_image_width,$desired_image_height,$source_gdim,$dst_image ){
		    $source_aspect_ratio = $source_width / $source_height;
		    $desired_aspect_ratio = $desired_image_width / $desired_image_height;

		    if ( $source_aspect_ratio > $desired_aspect_ratio ) {
			    $temp_height = $desired_image_height;
			    $temp_width = ( int )($desired_image_height * $source_aspect_ratio);
		    } else {
			    $temp_width = $desired_image_width;
			    $temp_height = ( int )($desired_image_width / $source_aspect_ratio);
		    }

		    $temp_gdim = imagecreatetruecolor ( $temp_width , $temp_height );

		    imagecopyresampled ( $temp_gdim , $source_gdim , 0 , 0 , 0 , 0 , $temp_width , $temp_height , $source_width , $source_height );

		    $x0 = ($temp_width - $desired_image_width) / 2;
		    $y0 = ($temp_height - $desired_image_height) / 2;
		    $desired_gdim = imagecreatetruecolor ( $desired_image_width , $desired_image_height );
		    imagecopy ( $desired_gdim , $temp_gdim , 0 , 0 , $x0 , $y0 , $desired_image_width , $desired_image_height );

		    //Saving file
		    imagejpeg ( $desired_gdim , $dst_image , 70 );

		    //Clean-UP
		    imagedestroy ( $temp_gdim );
		    imagedestroy ( $desired_gdim );
		}

	    /**
	     * @param bool $create_thumb
	     *
	     * @return bool
	     */
        public function processUpload ($create_thumb = false)
        {
            if (OC_ADMIN){
                $section = 'admin';
            }
            else {
                $section = 'pubMessages';
            }
            $status = false ;
            $max_filesize = $this->maxSize;
            $upload_path = $this->uploadPath;
            $desired_name = $this->uploadName;
            $fileSize = filesize ( $this->imageData );
            $destination_file = $upload_path.$desired_name;

            // Now check the filesize.
            if ( $fileSize > $max_filesize ) {
                osc_add_flash_warning_message ( __ ( 'The file you attempted to upload is too large.' , 'shopclass' ).$max_filesize , $section);
                return false;
            }
            // Check Path is writable.
            if ( !is_writable ( $upload_path ) ) {
                osc_add_flash_warning_message ( __ ( 'Directory is not writable, Contact Administrator.' , 'shopclass' ), $section );
                return false;
            }
			//List image width,height,mime
	        list( $source_width , $source_height , $source_type ) = getimagesize ( $this->imageData );

            //Check Image Mime
            if(!$this->checkImageIsValid($this->imageData,$source_type)){
	            osc_add_flash_warning_message ( __ ( 'Invalid Image, only .jpg, .png, .gif are allowed.' , 'shopclass' ), $section );
            	return false;
            }
            $source_gdim = $this->source_gdim;

            //Create Image to destination
			$this->createImage($source_width,$source_height,$this->imageWidth,$this->imageHeight,$source_gdim,$destination_file);

            if (file_exists ($upload_path . $desired_name)){
            	if($create_thumb == true){
		            $this->createImage($source_width,$source_height,$this->imageWidth/2,$this->imageHeight/2,$source_gdim,str_replace('.jpg','_thumbnail.jpg',$destination_file));
	            }
                $status = true;
            	//clean source image
	            imagedestroy ( $source_gdim );
            }
            return $status;

        }
    }