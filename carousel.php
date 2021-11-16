<?php 
if( tfc_getPref( 'enable_caraousel') || Params::getParam( 'carousel')){
	 tfc_carousel_start();
	 osc_add_hook('footer_scripts_loaded','main_tfc_carousel_js');
}