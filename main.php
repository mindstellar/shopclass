<?php osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="content home">
	<?php
		if ( Params::getParam( 'layout' ) ) {
			$page_layout = intval( Params::getParam( 'layout' ) );
		} else {
			$page_layout = tfc_getPref( 'page_layout' );
		}
		if ( ! $page_layout ) {
			$page_layout = 1;
		}
		osc_current_web_theme_path( 'home-layout/layout_' . $page_layout . '.php' );
	?>
</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
<?php if ( tfc_getPref( 'enable_slider' ) ) { ?>
    <script>
        $('select[name="sCountry"]').find('option[value="<?php echo tfc_getPref( 'default_country' )?>"]').attr("selected", true);
		<?php if (tfc_getPref( 'enable_slider' )){?>
        var now = 0;
        var int = self.setInterval("changeBG()", 4000);
        if ($('.hidden-xs').css('display').toLowerCase() == 'none') {
            clearInterval(int);
        }
        else {
            int = self.setInterval( "changeBG()", 4000);
        }
		<?php
		tfc_array_bk_image(); ?>

        function changeBG() {
            //array of backgrounds
            now = (now + 1) % array.length;
            $('.header-box').css('background-image', 'url("' + array[now] + '")');
        }
		<?php } ?>
    </script>
<?php } ?>
</body>
</html>