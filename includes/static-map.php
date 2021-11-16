<?php 
            $addr = array();
            if( ( $item['s_address'] != '' ) && ( $item['s_address'] != null ) ) { $addr[] = $item['s_address']; }
            if( ( $item['s_city'] != '' ) && ( $item['s_city'] != null ) ) { $addr[] = $item['s_city']; }
            if( ( $item['s_zip'] != '' ) && ( $item['s_zip'] != null ) ) { $addr[] = $item['s_zip']; }
            if( ( $item['s_region'] != '' ) && ( $item['s_region'] != null ) ) { $addr[] = $item['s_region']; }
            if( ( $item['s_country'] != '' ) && ( $item['s_country'] != null ) ) { $addr[] = $item['s_country']; }
            $address = implode(", ", $addr);
            
if($item['d_coord_lat'] != '' && $item['d_coord_long'] != '') {?> 
<!--suppress ALL -->
    
<a class="popup-gmaps" href="//maps.google.com/maps?q=<?php echo urlencode($address) ?>&amp;<?php echo $item['d_coord_lat']; ?>,<?php echo $item['d_coord_long'];?>" target="_blank" rel="nofollow"><img src="http://maps.googleapis.com/maps/api/staticmap?zoom=14&size=720x240&markers=color:red%7C<?php echo $item['d_coord_lat']; ?>,<?php echo $item['d_coord_long']; ?>%7C&sensor=false&format=jpg" width="730" class="img-rounded img-responsive" alt="Google map of location"/></a>


<?php } else { ?>

<a class="popup-gmaps" href="//maps.google.com/?q=<?php echo urlencode($address) ?>" target="_blank" rel="nofollow"><img src="http://maps.googleapis.com/maps/api/staticmap?zoom=14&size=720x240&markers=color:red%7C<?php echo urlencode($address) ?>%7C&sensor=false&format=jpg" width="730" alt="Google map of location" class="img-rounded img-responsive"/></a>

<?php } ?>
    <script>
      $(document).ready(function() {
        $('.popup-gmaps').magnificPopup({
          disableOn: 700,
          type: 'iframe',
          mainClass: 'mfp-fade',
          removalDelay: 160,
          preloader: false,

          fixedContentPos: false
        });
      });
    </script>