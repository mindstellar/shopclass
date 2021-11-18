<?php

    use shopclass\includes\classes\tfcAdsLoop;

    $adminoptions = true;
    osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="row">
    <div class="col-md-4">
        <?php include 'user-sidebar.php'; ?>
    </div>
    <div class="col-md-8">
        <div class="tfc-item">
            <div class="panel-heading">
                <div class="panel-title"><?php _e( 'Manage Your Listings' , 'shopclass' ); ?></div>
            </div>
            <div class="content user_account panel-body">
                <?php if ( osc_count_items() == 0 ) { ?>
                    <h3><?php _e( 'You don\'t have any items yet' , 'shopclass' ); ?></h3>
                <?php } else { ?>
                    <?php while ( osc_has_items() ) { ?>
                        <div class="item">
                            <?php tfcAdsLoop::newInstance()->renderItem( tfcAdsLoop::newInstance()->getItemProperty( 'item' ) , 'list' ); ?>
                        </div>
                    <?php } ?>
                <?php } ?>
                <div class="col-md-6 col-md-offset-5">
                    <?php echo tfc_pagination_items(); ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
</body>
</html>
