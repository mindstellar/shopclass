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
    use AdminMenu;
    use AdminToolbar;
    use Params;
    use Rewrite;

    /**
     * Class tfcAdminDashboard
     */
    class tfcAdminDashboard
    {
        private static $instance;
        public $adminToolBar;
        public $adminMenu;
        public $dashboardOptions;
        public $parentMenuId = 'settings-shopclass';


        /**
         * tfcAdminDashboard constructor.
         */
        public function __construct ()
        {
            $this->adminToolBar = new AdminToolbar();
            $this->adminMenu = new AdminMenu();
            $options = array (
                array (
                    'route' => true ,
                    'id' => 'settings-shopclass' ,
                    'route_regex' => 'shopclass/admin/settings' ,
                    'route_url' => 'shopclass/admin/settings' ,

                    'admin_title' => 'ShopClass Theme Settings ' ,
                    //'admin_file' => tfc_path () . 'admin/settings.php' ,

                    'menu_page' => true ,
                    'menu_dash' => true ,
                    'menu_appearance' => true ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Theme Settings'
                ) ,
                array (
                    'route' => true ,
                    'id' => 'slider-shopclass' ,
                    'route_regex' => 'shopclass/admin/slider' ,
                    'route_url' => 'shopclass/admin/slider' ,
                    'admin_title' => 'ShopClass Slider Settings '  ,
                    'admin_file' => tfc_path () . 'admin/homepage-slider.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Slider Settings'
                ) ,
                array (
                    'route' => true ,
                    'id' => 'category-image-shopclass' ,
                    'route_regex' => 'shopclass/admin/categoryimage' ,
                    'route_url' => 'shopclass/admin/categoryimage' ,
                    'admin_title' => 'ShopClass Category Images settings '  ,
                    'admin_file' => tfc_path () . 'admin/category-image.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Category Images'
                ) ,
                array (
                    'route' => true ,
                    'id' => 'ads-manager-shopclass' ,
                    'route_regex' => 'shopclass/admin/adsmanager' ,
                    'route_url' => 'shopclass/admin/adsmanager' ,
                    'admin_title' => 'ShopClass Ads Manager' ,
                    //'admin_file' => tfc_path () . 'admin/adsense-banner.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Ads Manager'
                ) ,
                array (
                    'route' => true ,
                    'id' => 'logo-uploader-shopclass' ,
                    'route_regex' => 'shopclass/admin/logo' ,
                    'route_url' => 'shopclass/admin/logo' ,
                    'admin_title' => 'ShopClass Logo Settings ' ,
                    'admin_file' => tfc_path () . 'admin/header.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Logo Settings' ,
                ) ,
                array (
                    'route' => true ,
                    'id' => 'sitemap-shopclass' ,
                    'route_regex' => 'shopclass/admin/sitemap' ,
                    'route_url' => 'shopclass/admin/sitemap' ,
                    'admin_title' => 'ShopClass Sitemap Settings ' ,
                    'admin_file' => tfc_path () . 'admin/sitemap.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Sitemap Settings'
                ) ,
                array (
                    'route' => true ,
                    'id' => 'seo-shopclass' ,
                    'route_regex' => 'shopclass/admin/seo' ,
                    'route_url' => 'shopclass/admin/seo' ,
                    'admin_title' => 'ShopClass Seo Settings ' ,
                    //'admin_file' => tfc_path () . 'admin/seo-options.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Seo Settings'
                ) ,
                array (
                    'route' => true ,
                    'id' => 'osclass-editor-shopclass' ,
                    'route_regex' => 'shopclass/admin/editor/(.*)/(.*)' ,
                    'route_url' => 'shopclass/admin/editor/{editdirectory}/{editfilename}' ,
                    'admin_title' => 'ShopClass Osclass Editor ' ,
                    'admin_file' => tfc_path () . 'admin/theme-editor.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Osclass Editor'
                ) ,
                array (
                    'route' => true ,
                    'id' => 'spam-security-shopclass' ,
                    'route_regex' => 'shopclass/admin/spam' ,
                    'route_url' => 'shopclass/admin/spam' ,
                    'admin_title' => 'ShopClass Spam/Security Settings ' ,
                    //'admin_file' => tfc_path () . 'admin/spam-security.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Spam/Security'

                ) ,
                array (
                    'route' => true ,
                    'id' => 'carousel-shopclass' ,
                    'route_regex' => 'shopclass/admin/carousel' ,
                    'route_url' => 'shopclass/admin/carousel' ,
                    'admin_title' => 'ShopClass Carousel Settings ' ,
                    //'admin_file' => tfc_path () . 'admin/carousel-slider.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Carousel Settings'

                ) ,
                array (
                    'route' => true ,
                    'id' => 'related-ads-shopclass' ,
                    'route_regex' => 'shopclass/admin/relatedads' ,
                    'route_url' => 'shopclass/admin/relatedads' ,
                    'admin_title' => 'ShopClass Related Ads Settings ' ,
                    //'admin_file' => tfc_path () . 'admin/related-ads.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Related Ads'

                ) ,
                array (
                    'route' => true ,
                    'id' => 'widgetbox-shopclass' ,
                    'route_regex' => 'shopclass/admin/home-widgetbox' ,
                    'route_url' => 'shopclass/admin/home-widgetbox' ,
                    'admin_title' => 'ShopClass Widget Box Setting ' ,
                    //'admin_file' => tfc_path () . 'admin/home-widget-box.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Homepage WidgetBox'

                ) ,
                array (
                    'route' => true ,
                    'id' => 'social-login-shopclass' ,
                    'route_regex' => 'shopclass/admin/social-login' ,
                    'route_url' => 'shopclass/admin/social-login' ,
                    'admin_title' => 'ShopClass Social Login Setting ' ,
                    //'admin_file' => tfc_path () . 'admin/social-login.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Social Login'

                ) ,
                array (
                    'route' => true ,
                    'id' => 'sphinx-shopclass' ,
                    'route_regex' => 'shopclass/admin/sphinx' ,
                    'route_url' => 'shopclass/admin/sphinx' ,
                    'admin_title' => 'ShopClass Sphinx Helper ' ,
                    'admin_file' => tfc_path () . 'admin/sphinx-help.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Sphinx Helper'

                ) ,
	            array (
		            'route' => true ,
		            'id' => 'php-info-shopclass' ,
		            'route_regex' => 'shopclass/admin/phpinfo' ,
		            'route_url' => 'shopclass/admin/phpinfo' ,
		            'admin_title' => 'ShopClass Php Info ' ,
		            //'admin_file' => tfc_path () . 'admin/phpinfo.php' ,
		            'menu_subpage' => true ,
		            'menu_title' => 'PHP Info'
	            ) ,
                array (
                    'route' => true ,
                    'id' => 'help-shopclass' ,
                    'route_regex' => 'shopclass/admin/help' ,
                    'route_url' => 'shopclass/admin/help' ,
                    'admin_title' => 'Shopclass Theme Help' ,
                    'admin_file' => tfc_path () . 'admin/help.php' ,
                    'menu_subpage' => true ,
                    'menu_title' => 'Shopclass Help' ,
                ) ,
                array (
                    'id' => 'tfc-flush-cache' ,
                    'admin_title' => 'Flush All Cache' ,
                    'url' => osc_admin_base_url ( true ) . '?action_specific=tfc_flush_all_cache' ,
                    'meta' => array ( 'class' => 'action-btn action-btn-black float-right' ) ,
                    'menu_toolbar' => true
                )
            );
            $this->dashboardOptions = $options;
        }


        /**
         * @return tfcAdminDashboard
         */
        public static function newInstance ()
        {
            if ( !self::$instance instanceof self ) {
                self::$instance = new self;
            }
            return self::$instance;
        }

        public function createThemeDashboard ()
        {
            $options = $this->getDashboardOptions();
            foreach ($options as $option) {
                if ( array_key_exists ( 'route' , $option ) ) {

                    Rewrite::newInstance ()->addRoute ( $option[ 'id' ] , $option[ 'route_regex' ] , $option[ 'route_url' ] , 'oc-content/themes/shopclass/admin/admin-content.php' );

                    if(array_key_exists ( 'admin_file',$option)) {
                        $loadFile = function () use ($option) {
	                        /** @noinspection PhpIncludeInspection */
	                        require_once $option[ 'admin_file' ];
                        };
                        osc_add_hook ( 'tfc-'.$option[ 'id' ] , $loadFile , 10 );
                    }

                    $this->addThemeHeader ( $option[ 'admin_title' ] , $option[ 'id' ] );
                    $this->setThemeHeaderTitle ( $option[ 'admin_title' ] , $option[ 'id' ] );
                    $this->setThemeBodyClass ( $option[ 'id' ] );
                }
                if ( array_key_exists ( 'menu_page' , $option ) ) {

                    $this->addThemeAdminMenuPage ( $option[ 'id' ] );

                }
                if ( array_key_exists ( 'menu_subpage' , $option ) ) {

                    $this->addThemeAdminMenuSubPage ( $option[ 'menu_title' ] , $option[ 'id' ] );

                }
                if ( array_key_exists ( 'menu_appearance' , $option ) ) {

                    $this->addThemeAdminMenuAppearance ( __ ( 'ShopClass Theme' , 'shopclass' ) , $option[ 'id' ] );

                }
                if ( array_key_exists ( 'menu_dash' , $option ) ) {

                    $this->addThemeAdminMenuDash ( __ ( 'ShopClass Theme' , 'shopclass' ) , $option[ 'id' ] );

                }
                if ( array_key_exists ( 'menu_toolbar' , $option ) ) {

                    $this->addAdminToolbarMenu ( $option[ 'id' ] , $option[ 'admin_title' ] , $option[ 'url' ] , $option[ 'meta' ] );

                }

            }
            $this->addIconStyle ();
        }

        /**
         * @param $id
         * @param string $menuTitle
         */
        private function addThemeAdminMenuPage ($id , $menuTitle = '')
        {
            if ( empty( $menuTitle ) ) {
                $menuTitle = __ ( 'Shopclass' , 'shopclass' );
            }
            osc_add_admin_menu_page ( $menuTitle , $this->getRouteAdminURL ( $id ) , $this->parentMenuId , 'administrator' );
        }

        /**
         * @param $parentMenuId
         * @param $menuTitle
         * @param $id
         */
        private function addThemeAdminMenuSubPage ($menuTitle , $id , $parentMenuId = '')
        {
            if ( empty( $parentMenuId ) ) {
                $parentMenuId = $this->parentMenuId;
            }
            osc_add_admin_submenu_page ( $parentMenuId , $menuTitle , $this->getRouteAdminURL ( $id ) , $id );
        }

        /**
         * @param $id
         * @param array $args
         * @return string
         */
        public function getRouteAdminURL ($id , $args = array ())
        {
            $routes = Rewrite::newInstance ()->getRoutes ();
            if ( !isset( $routes[ $id ] ) ) {
                return '';
            };
            $params_url = '';
            foreach ($args as $k => $v) {
                $params_url .= '&' . $k . '=' . $v;
            }
            return osc_admin_base_url ( true ) . "?page=appearance&action=render&route=" . $id . $params_url;

        }

        /**
         * @param $menuTitle
         * @param $id
         */
        private function addThemeAdminMenuAppearance ($menuTitle , $id)
        {
            osc_admin_menu_appearance ( $menuTitle , $this->getRouteAdminURL ( $id ) , $id . '-appereance' , 'administrator' );
        }

        /**
         * @param $menuTitle
         * @param $id
         */
        private function addThemeAdminMenuDash ($menuTitle , $id)
        {
            osc_add_admin_submenu_page ( 'dash' , $menuTitle , $this->getRouteAdminURL ( $id ) , $id . '-dash' );

        }

        /**
         * @param $title
         * @param $id
         * @param string $headerTitle
         */
        private function addThemeHeader ($title , $id , $headerTitle = '')
        {
            //Set Shopclass Admin Header
            if ( Params::getParam ( 'route' ) == $id ) {
                if ( empty( $headerTitle ) ) {
                    $headerTitle = __ ( 'Manage all your shopclass settings from here.' , 'shopclass' );
                }
                $shopclassHeader = function () use ($id, $title , $headerTitle) {
                    osc_remove_hook ( 'admin_page_header' , 'customPageHeader' );
                    osc_add_hook (
                        'admin_page_header' , function () use ($title , $headerTitle) {
                        echo '<h1>' . $title . '</h1>';
                        self::printCss();
                        $this->addThemeHeaderSubTitle ( $headerTitle );
                    }
                    );
                    $nav = function( ) { $this->addThemeHeaderNav( ) ; };
                    osc_add_hook( 'tfc-'.$id , $nav,1 );
                };
                osc_add_hook ( 'admin_header' , $shopclassHeader );
                //osc_add_hook('admin_header',self::printCss());
            }
        }

        /**
         * @param string $headerTitle
         */
        private function addThemeHeaderSubTitle ($headerTitle)
        {
            echo '<div class="header-title-market"><h2> ' . $headerTitle . ' </h2></div>';
        }
        public static function printCss(){ ?>
            <style>

                body.market.shopclass #content-head {
                    background: #ff5252;
                    background: linear-gradient(30deg, #ff7171, #ff5252);
                    box-shadow: 0 12px 20px -10px rgba(255, 82, 82, 0.28), 0 4px 20px 0 rgba(0, 0, 0, 0.12), 0 7px 8px -5px rgba(255, 82, 82, 0.2);
                    color: rgba(255, 255, 255, 0.94);
                    text-shadow: 1px 1px 3px rgba(103, 101, 103, 0.6);
                    padding-bottom: 5px;
                    padding-top: 15px;
                    padding-left:15px;
                    height: 80px;
                    z-index:2;
                }
                body.market.shopclass #content-head h1{
                    float:left;
                    padding-right:15px;
                }
                body.market.shopclass #content-head h2{
                    font-weight: 300;
                }
                body.market.shopclass #content-page {
                    padding-right:0;
                }
                .shopclass .help-box{
                    color: #7d7d7c;
                    text-shadow: 1px 1px 1px rgba(0, 0, 0, 0.09);
                    margin-top:2px;
                }
                .shopclass .form-label{
                    font-size: 13px;
                    color: #8a8a8a;
                    font-weight: 700
                }
                .shopclasss .input-text, .shopclass input[type="text"],.shopclass .input-password,.shopclass input[type="password"]{
                    height:28px;
                    line-height:26px;
                    min-width:380px;
                }
                .shopclass .render-title{
                    color: #202020;
                    text-shadow: 1px 1px 5px #cdc3c5
                }
                .shopclass .ui-tabs {
                    margin: 15px 15px;
                }
                .tfc-header-nav:after {
                    content: "";
                    display: table;
                    clear: both;

                }
                .tfc-header-nav {
                    display: block;
                    margin-top: -22px;
                    margin-left: -30px;
                }
            </style>
            <link href="https://use.fontawesome.com/releases/v5.0.2/css/all.css" rel="stylesheet">
	        <?php
        }
	    private function addThemeHeaderNav ()
	    {
		    $navOptions = $this->getDashboardOptions();
		    echo '<div class="btn-group tfc-header-nav">';
		    foreach ($navOptions as $nav) {
			    if ( array_key_exists ( 'menu_subpage' , $nav ) ) {
				    if ( Params::getParam ( 'route' ) == $nav[ 'id' ] ) {
					    $active = 'active';
				    } else {
                        $active = '';
				    }
				    echo '<a class="btn btn-sm btn-danger '.$active.'" href="' . $this->getRouteAdminURL ( $nav[ 'id' ] ) . '">' . $nav[ 'menu_title' ] . '</a>';
			    }
		    }
		    echo '</div>'; ?>
            <?php
	    }
        /**
         * @param $title
         * @param $id
         */
        private function setThemeHeaderTitle ($title , $id)
        {
            //Set Shopclass Admin Header
            if ( Params::getParam ( 'route' ) == $id ) {

                osc_remove_filter ( 'admin_title' , 'customPageTitle' );
                osc_add_filter (
                    'admin_title' ,
                    function ($string) use ($title) {
                        return $title . $string;
                    }
                );
            }
        }

        /**
         * @param $id
         */
        private function setThemeBodyClass ($id)
        {
            if ( Params::getParam ( 'route' ) == $id ) {
                //Set Shopclass Body Class
                $shopclassBodyClass = function () {
                    $array[] = 'market shopclass';
                    return $array;
                };
                osc_add_filter ( 'admin_body_class' , $shopclassBodyClass );
            }
        }

        /**
         * @param $id
         * @param $title
         * @param $url
         * @param array $meta
         */
        private function addAdminToolbarMenu ($id , $title , $url , $meta = array ())
        {
            $toolbarMenu = function () use ($id , $title , $url , $meta) {
                AdminToolbar::newInstance ()->add_menu (
                    array (
                        'id' => $id ,
                        'title' => $title ,
                        'href' => $url ,
                        'meta' => $meta
                    )
                );
            };
            osc_add_hook ( 'add_admin_toolbar_menus' , $toolbarMenu , 1 );
        }

        private function addIconStyle ()
        {
            $iconStyle = function () { ?>
                <style>
                .ico-settings-shopclass {
                    background-image: url('<?php echo osc_base_url();?>oc-content/themes/shopclass/assets/images/admin_menu.png') !important;
                }
                body.compact .ico-settings-shopclass {
                    background-image: url('<?php echo osc_base_url();?>oc-content/themes/shopclass/assets/images/admin_menu.png') !important;
                    background-size: 35px, 35px;
                }
                .ico-settings-shopclass:hover {
                    background-image: url('<?php echo osc_base_url();?>oc-content/themes/shopclass/assets/images/admin_menu.png') !important;
                }
                body.compact .ico-settings-shopclass:hover {
                    background-image: url('<?php echo osc_base_url();?>oc-content/themes/shopclass/assets/images/admin_menu.png') !important;
                    background-size: 35px, 35px;
                }
                .ico-settings-shopclass:active {
                    background-image: url('<?php echo osc_base_url();?>oc-content/themes/shopclass/assets/images/admin_menu.png') !important;
                }
                body.compact .ico-settings-shopclass:active {
                    background-image: url('<?php echo osc_base_url();?>oc-content/themes/shopclass/assets/images/admin_menu.png') !important;
                    background-size: 35px, 35px;
                }
                </style><?php };
            osc_add_hook ( 'admin_footer' , $iconStyle );
        }

	    /**
	     * @return bool|mixed
	     */
	    public function getDashboardOptions() {
		    return $this->dashboardOptions;
	    }

	    /**
	     * @param bool|mixed $dashboardOptions
	     */
	    public function setDashboardOptions( $dashboardOptions ) {
		    $this->dashboardOptions[] = $dashboardOptions;
	    }
    }