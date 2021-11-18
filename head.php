<!DOCTYPE html>
<html lang="<?php echo str_replace( '_' , '-' , osc_current_user_locale() ); ?>" <?php echo osc_current_user_locale() == 'ar_SY' ? 'dir="rtl"' : ''; ?>>
<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo meta_title(); ?></title>
    <meta name="description" content="<?php echo meta_description(); ?>"/>
    <link rel="shortcut icon" href="<?php echo osc_current_web_theme_url(); ?>favicon.ico" type="image/x-icon"/>
    <?php if ( osc_is_ad_page() ) { ?>
        <script type="application/ld+json">
            {
              "@context": "https://schema.org/",
              "@type": "Product",
              "name": "<?php echo osc_item_title(); ?>",
              <?php
                $img_count = osc_count_item_resources();
                if ( osc_images_enabled_at_items() && $img_count > 0 ) {
                    echo '"image": [';
                    $i = 0;
                    while ( osc_has_item_resources() ) {
                        $i ++;
                        echo '"' . osc_resource_url() . '"';
                        if ( $i != $img_count ) {
                            echo ',' . PHP_EOL;
                        }
                    }
                    echo '],' . PHP_EOL;
                }
                osc_reset_resources();
                unset( $i , $img_count );
            ?>
              "description": "<?php echo meta_description(); ?>",
              "brand": {
                "@type": "Thing",
                "name": "<?php echo osc_item_category(); ?>"
              },
            <?php if ( function_exists( 'tfc_voting_item_meta' ) ) {
                tfc_voting_item_meta();
            } ?>
              "offers": {
                "@type": "Offer",
                "url": "<?php echo osc_item_url(); ?>",
                <?php if ( osc_item_field( "i_price" ) != '' ) { ?>
                "priceCurrency": "<?php echo osc_item_currency(); ?>",
                <?php
                $price = osc_item_price();
                if ( $price > 0 ) {
                    $price = $price / 1000000;
                }
                ?>
                "price": "<?php echo $price; ?>",
                <?php } ?>
                "seller": {
                  "@type": "<?php if ( osc_user_is_company() ) {
                echo 'Organization';
            } else {
                echo 'Person';
            } ?>",
                  "name": "<?php echo osc_item_contact_name(); ?>"
                }
              }
            }



        </script>
    <?php if ( osc_item_is_inactive() || osc_item_is_spam() ){ ?>
    <meta name="robots" content="noindex">
    <?php } ?>
        <meta name="author" content="<?php echo osc_item_contact_name(); ?>"/>
    <?php if ( osc_images_enabled_at_items() ) {
        if ( osc_count_item_resources() > 0 ) { ?>
    <meta property="og:image" content="<?php echo osc_resource_url(); ?>"/>
    <meta property="og:site_name" content="<?php echo osc_get_preference( "pageTitle" , "osclass" ); ?>"/>
    <meta property="og:url" content="<?php echo osc_item_url(); ?>"/>
    <meta property="og:title" content="<?php echo meta_title(); ?>"/>
    <meta property="og:description" content="<?php echo meta_description(); ?>"/>
    <?php }
    }
    }; ?>
    <?php
        if ( tfc_getPref( 'google_tag' ) ) { ?>
            <meta name="google-site-verification" content="<?php echo tfc_getPref( 'google_tag' ) ?>"/>
        <?php }
        if ( osc_get_canonical() ) {
            ?>
            <link rel="canonical" href="<?php echo osc_get_canonical(); ?>"/>
        <?php }
        osc_run_hook( 'header' );
    ?>
    <!-- HTML5 shim and Respond.js, IE8 HTML5 elements and media queries support -->
    <!--[if lt IE 9]>
    <link href="//cdnjs.cloudflare.com/ajax/libs/respond.js/1.4.2/respond-proxy.html" id="respond-proxy"
          rel="respond-proxy"/>
    <script src="//cdnjs.cloudflare.com/ajax/libs/html5shiv/3.7/html5shiv.min.js"></script>
    <![endif]-->
</head>