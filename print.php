<?php
    // Enable OSClass functions
    define( 'ABS_PATH' , dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/' );
    require_once ABS_PATH . 'oc-load.php';

    // Get posted data

    $printItemId     = Params::getParam( 'printItemId' );
    $printItemUserId = Params::getParam( 'printItemUserId' );

    View::newInstance()->_exportVariableToView( 'user' , User::newInstance()->findByPrimaryKey( $printItemUserId ) );
    $mSearch = new Search();
    $mSearch->dao->where( sprintf( "%st_item.pk_i_id = $printItemId" , DB_TABLE_PREFIX ) );
    $aItems = $mSearch->doSearch();
    View::newInstance()->_exportVariableToView( 'items' , $aItems ); //exporting our searched item array
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
        "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<!--suppress Annotator -->
<html xmlns="http://www.w3.org/1999/xhtml" dir="ltr" lang="en-US">
<head>
    <meta http-equiv="Content-type" content="text/html; charset=utf-8"/>
    <title><?php _e( 'Print Ad' , 'printad' ); ?></title>
    <meta name="robots" content="NOINDEX, NOFOLLOW">
    <script>function printpage() {
            window.print();
        }</script>
    <style type="text/css">
        body {
            background-color: #fff
        }

        .pics li {
            list-style: none;
            display: inline-table;
            position: relative;
            float: left;
            padding: 4px;
            margin: 4px;
            border: 1px solid lightgray;
            background-color: white;
        }

        .price {
            background-color: white;
            margin-left: 15px;
            font-size: 1.5em;
            font-weight: bold;
            border: 1px solid #ccc;
            padding: 5px;
        }

        #print {
            float: right;
        }

        #title {
            float: left;
            width: 100%;
            padding: 5px;
            border-bottom: 1px solid #ccc;
            background-color: #eee;
        }

        #pictures {
            float: left;
            width: 100%;
            padding: 5px;
            border-top: 1px solid #ccc;
        }

        #displayText {
            font-size: 10px;
            text-decoration: none;
            color: gray;
        }

        .info {
            float: left;
            width: 100%;
            min-width: 380px;
            border-right: 1px solid #ccc;
            padding: 5px;
        }

        #desc {
            float: left;
            width: 99%;
            border: 1px solid #ccc;
            padding: 5px;
        }

        #footer {
            float: left;
            width: 100%;
            border-top: 1px dotted #ccc;
        }
    </style>
</head>
<body>
<h2><?php echo osc_page_title(); ?></h2>
<div id="print"><a href="#" onclick="printpage();"><b><?php _e( 'Print' , 'printad' ); ?></b></a></div>

<?php while ( osc_has_items() ) { ?>
    <span class="price"><?php echo osc_item_formated_price(); ?></span>
    <div id="title">
        <?php echo osc_item_title(); ?>
    </div>
    <br>
    <div class="info">
    <b><?php _e( 'Location' , 'printad' ); ?>:</b><br><?php if ( osc_user_address() != '' ) {
        echo osc_user_address() . '<br>';
    } ?><?php echo osc_item_city() . ', ' . osc_item_region() . ' - ' . osc_item_country(); ?><br>
    <br>
    <b><?php _e( 'Published' , 'printad' ); ?>:</b><br><?php echo osc_item_pub_date(); ?><br>
    <br>
    <b><?php _e( 'Contact Info' , 'printad' ); ?>:</b><br>
    <?php if ( osc_item_contact_name() != '' ) {
        echo osc_item_contact_name() . '<br>';
    } ?>
    <?php if ( osc_user_phone() != '' ) {
        echo osc_user_phone() . '<br>';
    } ?>
    <?php if ( osc_item_contact_email() != '' && osc_item_show_email() ) {
        echo osc_item_contact_email() . '<br>';
    } ?>
    <?php if ( osc_user_address() != '' ) {
        echo osc_user_address() . '<br>';
    } ?>
    <?php if ( osc_count_item_meta() >= 1 ) { ?>

        <?php while ( osc_has_item_meta() ) { ?>
            <?php if ( osc_item_meta_value() != '' ) { ?>
                <br>
                <strong><?php echo osc_item_meta_name(); ?>: </strong>
                <?php echo osc_item_meta_value();
                ?>
                </br>
            <?php } ?>
        <?php } ?>
        </div>
    <?php } ?>
    <br>
    <div id="desc">
        <b><?php _e( 'Description' , 'printad' ); ?>:</b> <?php echo osc_item_description(); ?>
    </div>
    <br>
    <?php if ( osc_images_enabled_at_items() ) { ?>
        <?php if ( osc_count_item_resources() ) { ?>
            <div id="pictures">
                <div class="pics">
                    <?php while ( osc_has_item_resources() ) { ?>
                        <li><img src="<?php echo osc_resource_preview_url(); ?>" width="230"/></li>
                    <?php } ?>
                </div>
            </div>
            <p><?php }
    }
    echo osc_item_url(); ?></p>
<?php } ?>
<div id="footer">
    <?php _e( 'This ad was generated by' , 'shopclass' ); ?>:
    <br><?php echo osc_page_title() . '</br> - <i>' . osc_base_url(); ?></i>
</div>
</body>
<script>window.onload = function () {
        window.print();
    }</script>
</html>
