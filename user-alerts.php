<?php use shopclass\includes\classes\tfcAdsLoop;

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
                <div class="panel-title"><?php _e( 'Manage Your Alerts' , 'shopclass' ); ?></div>
            </div>
            <div class="content user_account panel-body">
                <div id="main">
                    <legend><?php _e( 'Your alerts' , 'shopclass' ); ?></legend>
                    <?php if ( osc_count_alerts() == 0 ) { ?>
                        <h3><?php _e( 'You do not have any alerts yet' , 'shopclass' ); ?>.</h3>
                    <?php } else { ?>

                        <?php while ( osc_has_alerts() ) { ?>
                            <div class="userItem">
                                <h4><?php _e( 'Alert' , 'shopclass' ); ?> | <a class="btn btn-danger btn-sm"
                                                                               onclick="javascript:return confirm('<?php _e( 'This action can\'t be undone. Are you sure you want to continue?' , 'shopclass' ); ?>');"
                                                                               href="<?php echo osc_user_unsubscribe_alert_url(); ?>"><?php _e( 'Delete this alert' , 'shopclass' ); ?></a>
                                </h4>

                                <?php while ( osc_has_items() ) {
                                    tfcAdsLoop::newInstance()->renderItem( tfcAdsLoop::newInstance()->getItemProperty( 'item' ) , 'list' );
                                } ?>

                            </div>

                        <?php } ?>

                    <?php } ?>
                </div>

            </div>

        </div>
    </div>
</div>
<?php tfc_path( 'footer.php' ); ?>
</body>
</html>
