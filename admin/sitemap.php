<div class="tfc-dashboard">
<h2 class="render-title">Shopclass Sitemap Settings</h2>
<div >
	<form action="<?php echo osc_admin_base_url(true); ?>" method="post">
		<input type="hidden" name="action_specific" value="tfc_sitemap" />
		<fieldset>
			<div class="form-horizontal">
				<div class="form-row">
					<div class="form-label">Sitemap URL number></div>
					<div class="form-controls">
						<input type="text" class="xlarge" name="sitemap_number" value="<?php echo osc_esc_html( osc_get_preference('sitemap_number', 'shopclass_theme') ); ?>">
						<div class="help-box">Please enter number of URLs you want to show in each xml sitemap</div>
						<div class="help-box">If you have more listing than above limit, it will create new sitemap for those listings.</div>
						<div class="help-box" style="color:#ff0000">Keep it low if you are getting memory errors or timeout errors.</div>
					</div>
				</div>
				<div class="form-row">
					<div class="form-label">Include Categories</div>
					<div class="form-controls">
						<input type="checkbox" name="sitemap_categories" value="1"<?php echo (osc_get_preference('sitemap_categories', 'shopclass_theme') ? 'checked' : ''); ?>>
						<div class="help-box">Please Check if you want to include Categories in Sitemap</div>
					</div>
				</div>
				<div class="form-row">
					<div class="form-label">Include Countries</div>
					<div class="form-controls">
						<input type="checkbox" name="sitemap_countries" value="1"<?php echo (osc_get_preference('sitemap_countries', 'shopclass_theme') ? 'checked' : ''); ?>>
						<div class="help-box">Please Check if you want to include Countries in Sitemap</div>
					</div>
				</div>
				<div class="form-row">
					<div class="form-label">Include Regions</div>
					<div class="form-controls">
						<input type="checkbox" name="sitemap_regions" value="1"<?php echo (osc_get_preference('sitemap_regions', 'shopclass_theme') ? 'checked' : ''); ?>>
						<div class="help-box">Please Check if you want to include Regions in Sitemap</div>
					</div>
				</div>
				<div class="form-row">
					<div class="form-label">Include Cities</div>
					<div class="form-controls">
						<input type="checkbox" name="sitemap_cities" value="1"<?php echo (osc_get_preference('sitemap_cities', 'shopclass_theme') ? 'checked' : ''); ?>>
						<div class="help-box">Please Check if you want to include Cities in Sitemap</div>
					</div>
				</div>
				<div class="form-row">
					<div class="form-label">Include Category Regions</div>
					<div class="form-controls">
						<input type="checkbox" name="sitemap_cat_regions" value="1"<?php echo (osc_get_preference('sitemap_cat_regions', 'shopclass_theme') ? 'checked' : ''); ?>>
						<div class="help-box">Please Check if you want to include Categories with Regions in Sitemap</div>
					</div>
				</div>
				<div class="form-row">
					<div class="form-label">Include Category Cities</div>
					<div class="form-controls">
						<input type="checkbox" name="sitemap_cat_city" value="1"<?php echo (osc_get_preference('sitemap_cat_city', 'shopclass_theme') ? 'checked' : ''); ?>>
						<div class="help-box">Please Check if you want to include Categories with Cities in Sitemap</div>
					</div>
				</div>
			</div>
			<div class="form-row">
				<input type="submit" value="Save changes" class="btn btn-submit" />
			</div>
		</fieldset>
	</form>
</div>
<div class="well">
	<h4>Add Custom URL to sitemap</h4>
	<form action="<?php echo osc_admin_base_url(true); ?>" method="post">
		<input type="hidden" name="action_specific" value="tfc_add_url_sitemap" />
		<fieldset>
			<input type="text" class="xlarge" name="sitemap_url" value="" placeholder="http://www.example.com">
			<select name="frequency">
				<option value="hourly">Hourly</option>
				<option value="daily">Daily</option>
				<option value="weekly">Weekly</option>
				<option value="monthly">Monthly</option>
				<option value="yearly">Yearly</option>
			</select>
			<input type="text" class="xlarge" name="lastmod" value="" placeholder="YYYY-MM-DD">
			<input type="submit" value="Add URL" class="btn btn-sm btn-default" />
		</fieldset>
	</form>
</div>
<?php
	if (osc_get_preference('custom_urls','shopclass_theme')){
	    $array_custom_url =json_decode(osc_get_preference('custom_urls','shopclass_theme'),true);
	    echo '<div id="sitemap_custom_url">';
	    echo '<table class="table"><tr><th>URL</th><th>Frequency</th><th>Last Modified</th><th>Remove</th></tr>';
	    foreach($array_custom_url as $key =>$custom_pages){
	        echo "<tr>";
	        foreach($custom_pages as $pages){
	            echo "<td>{$pages}</td>";
	        }
	        echo '<td><button data-id="'.$key.'"class="remove_url btn btn-mini">Remove</button></td>';
	        echo "<tr>";
	    }
	    echo "</table>";
	    echo "</div>";
	}
	
	?>
<script type="text/javascript">
	$(document).ready(function() {
	    // bind change event to click
	    $('button.remove_url').on('click', function () {
	        var getKey = $(this).attr("data-id");
	        var secure_data = {
	            remove_key:+getKey
	        };
	        $.ajax({
	            type: "POST",
	            url: '<?php echo osc_base_url(true)."?page=ajax&action=runhook&hook=tfc_sitemap"; ?>',//get response from this file
	            data: secure_data,
	            cache: false,
	            success: function(response){
	                
	                $("#sitemap_custom_url").html(response).fadeIn(1000);
	                
	            }
	            
	        });
	    });
	});
</script>
 
<?php
  $robotfile = osc_base_path() . 'robots.txt' ;
  if (file_exists($robotfile)){
  $robot = file_get_contents($robotfile);}
  else { $robot ='';}
?>
<div class="well">
<h4>Robots.txt file updater</h4>
<form action="<?php echo osc_admin_base_url(true); ?>" method="post">
    <input type="hidden" name="action_specific" value="robot_updater" />
    <fieldset>
        <div class="form-horizontal">
 			<div class="form-row input-description-wide">
                <div class="form-label">Robots.txt updater</div>
                <div class="form-controls"  ><textarea name="edit_robot" rows="10"><?php echo $robot; ?></textarea></div>
                <div class="help-box" style="color:#ff0000;">Make a backup before making any changes to your Robots.txt file</div>
            </div>
			<input type="submit" value="Save Robots.txt" class="btn btn-submit" />
            
        </div>
    </fieldset>
</form>
<?php if (file_exists(ABS_PATH.'/sitemapindex.xml')){?>
<p>Your can set your robots.txt file like example below:<p>
<pre>
Sitemap: <?php echo osc_base_url()."sitemapindex.xml"; ?>

User-agent: *
Disallow: /oc-admin/
</pre>
<?php }?>
</div>
</div>
<div class="well">
    <strong style="color:#ff0000;">Notice: Our sitemap functions generate dynamic and always updated sitemap and it doesn't need actual sitemap files in root directory. This will not work if you have any file named 'sitemap.xml', 'sitemapindex.xml' or 'sitemap-index.xml' in root directory. Please remove those files before contacting support.</strong>
</div>