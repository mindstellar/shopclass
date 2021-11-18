<?php if ( ! defined( 'ABS_PATH' ) ) {
    exit( 'ABS_PATH is not loaded. Direct access is not allowed.' );
}
    /**
     * Created by TuffIndia.
     * User: navjottomer
     * Date: 09/11/17
     * Time: 6:07 PM
     * Shopclass Admin Content Page.
     */
    $currentRoute = Params::getParam( 'route' );
    osc_run_hook( 'tfc-' . $currentRoute );
    $admin_footer = function () {
        echo '<div > <strong>Theme:</strong> Shopclass ' . TFC_VER . '</div>';
    };
    osc_add_hook( 'admin_content_footer' , $admin_footer , 4 );