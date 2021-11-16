<h2 class="render-title ">Sphinx configuration helper</h2>
<h3 class="render-title ">1.Sphinx Functionality Requirement</h3>
<div class="help-box">1. You have installed Sphinx in your server atleast version 2.2.9</div>
<div class="help-box">2. Setup your osclass config.php like mentioned below</div>
<div class="help-box">3. Create Sphinx configuration file like mentioned below</div>
<div class="help-box">4. Setup proper cron job for reindexing like mentioned below</div>
<div style="margin-top:1em;margin-bottom:1em;"></div>
<?php 
$main_index = 'main_'.substr(md5(WEB_PATH), 0, 12);
$delta_index= 'delta_'.substr(md5(WEB_PATH), 0, 12);
?>
<h3 class="render-title ">3.Osclass Config.php setup</h3>
<div class="help-box">Copy Paste Below text and add it to your osclass config.php file</div>
<pre>
/**Define sphinx search true */
define('SPHINX_SEARCH', true);
define('SPHINX_HOST','127.0.0.1');
define('SPHINX_HOST_PORT',9312);
define('SPHINX_MYSQL_PORT',9306);

define('SPHINX_MAIN_INDEX','<?php echo $main_index; ?>');
define('SPHINX_ALL_SEARCH_INDEX','<?php echo $main_index; ?> <?php echo $delta_index; ?>');
</pre>

<h3 class="render-title ">3.Sphinx configuration</h3>
<div class="help-box">Copy Paste Below text and save it as sphinx.conf in sphinx configuration directory</div>
<div class="help-box">For Ubuntu this directory is /etc/sphinxsearch</div>
<div class="help-box">Configuration below is customised for Ubuntu, you need to make little changes for other OS</div>
<?php 
$db_host= explode(':',DB_HOST); ?><pre>
source <?php echo $main_index; ?>_main_source
{
type = mysql
	sql_host = <?php echo $db_host[0] ; ?> 
	sql_user = <?php echo DB_USER ; ?> 
	sql_pass = <?php echo DB_PASSWORD ; ?> 
	sql_db = <?php echo DB_NAME ; ?> 
	sql_port = <?php echo isset($db_host[1])?$db_host[1]:''.PHP_EOL ; ?>
	sql_query_pre = SET NAMES utf8
    sql_query_pre = REPLACE INTO sph_counter SELECT 1, MAX(pk_i_id) FROM <?php echo DB_TABLE_PREFIX;?>t_item 
    sql_query = SELECT \
				pk_i_id, fk_i_user_id as fk_i_user_id, \
				s_contact_email as s_contact_email, \
				b_active as b_active,b_enabled as b_enabled, \
				b_spam as b_spam,b_premium as b_premium,\
				a.s_title,\
				a.s_description,\
				fk_i_category_id AS category,\
				i_price AS price,\
				if(r.s_content_type IS NOT NULL, '1','0') as hasPic,\
				UNIX_TIMESTAMP(dt_pub_date) AS date_pub, REPLACE(REPLACE(REPLACE( dt_expiration, ' ', ''),':',''),'-','') as dt_expiration, \
				CRC32(LOWER(c.fk_c_country_code)) AS country_id, \
				c.fk_i_region_id  AS region_id, \
				c.fk_i_city_id AS city_id \
				FROM <?php echo DB_TABLE_PREFIX;?>t_item \
				LEFT JOIN <?php echo DB_TABLE_PREFIX;?>t_item_description AS a ON pk_i_id = a.fk_i_item_id \
				LEFT JOIN <?php echo DB_TABLE_PREFIX;?>t_item_location as c ON pk_i_id = c.fk_i_item_id \
				LEFT JOIN (SELECT fk_i_item_id, s_content_type FROM <?php echo DB_TABLE_PREFIX;?>t_item_resource GROUP BY fk_i_item_id, s_content_type) as r On pk_i_id = r.fk_i_item_id\
				WHERE pk_i_id<=( SELECT max_doc_id FROM sph_counter WHERE counter_id=1 )\
				AND a.fk_c_locale_code = 'en_US'
    sql_attr_uint = category
    sql_attr_timestamp = date_pub
    sql_attr_float = dt_expiration
    sql_field_string = s_contact_email
    sql_attr_uint = country_id
    sql_attr_uint = region_id
    sql_attr_uint = city_id
    sql_attr_float = price
    sql_attr_bool = hasPic
    sql_attr_bool = b_active
    sql_attr_bool = b_enabled
    sql_attr_bool = b_spam
    sql_attr_bool = b_premium
    sql_field_string = s_title
}
source <?php echo $main_index; ?>_delta_source:<?php echo $main_index; ?>_main_source
{
    sql_query_pre = SET NAMES utf8
    sql_query = SELECT \
				pk_i_id, fk_i_user_id as fk_i_user_id, \
				s_contact_email as s_contact_email, \
				b_active as b_active,b_enabled as b_enabled, \
				b_spam as b_spam,b_premium as b_premium,\
				a.s_title,\
				a.s_description,\
				fk_i_category_id AS category,\
				i_price AS price,\
				if(r.s_content_type IS NOT NULL, '1','0') as hasPic,\
				UNIX_TIMESTAMP(dt_pub_date) AS date_pub, REPLACE(REPLACE(REPLACE( dt_expiration, ' ', ''),':',''),'-','') as dt_expiration, \
				CRC32(LOWER(c.fk_c_country_code)) AS country_id, \
				c.fk_i_region_id  AS region_id, \
				c.fk_i_city_id AS city_id \
				FROM <?php echo DB_TABLE_PREFIX;?>t_item \
				LEFT JOIN <?php echo DB_TABLE_PREFIX;?>t_item_description AS a ON pk_i_id = a.fk_i_item_id \
				LEFT JOIN <?php echo DB_TABLE_PREFIX;?>t_item_location as c ON pk_i_id = c.fk_i_item_id \
				LEFT JOIN (SELECT fk_i_item_id, s_content_type FROM <?php echo DB_TABLE_PREFIX;?>t_item_resource GROUP BY fk_i_item_id, s_content_type) as r On pk_i_id = r.fk_i_item_id\
				WHERE pk_i_id>( SELECT max_doc_id FROM sph_counter WHERE counter_id=1 )\
				AND a.fk_c_locale_code = 'en_US'    

}
index <?php echo $main_index; ?>
{
	source = <?php echo $main_index; ?>_main_source
	path = <?php echo osc_uploads_path () . 'sphinx_data/'.$main_index; ?> 
	docinfo = extern
	stopwords = <?php echo WebThemes::newInstance()->getCurrentThemePath().'tfcstopwords.txt'; ?> 
	type = plain
	morphology = stem_en
}

index <?php echo $delta_index; ?>:<?php echo $main_index; ?>
{
	source = <?php echo $main_index; ?>_delta_source
	path = <?php echo osc_uploads_path () . 'sphinx_data/'.$delta_index; ?>  
}
indexer
{
	mem_limit = 128M
	write_buffer = 10M
}
searchd
{
	listen = 127.0.0.1:9312
	listen = 127.0.0.1:9306:mysql41
	pid_file = <?php echo osc_uploads_path () . 'sphinx_data'?>/searchd.pid
	##log = <?php echo osc_uploads_path () . 'sphinx_data'?>/searchd.log
}
</pre>

<h3 class="render-title ">3.Cronjob Setup</h3>
<div class="help-box">Copy Paste Below text and add it to your cron job file by using command "crontab -e"</div>
<pre>
0 0 * * * /usr/bin/indexer --rotate <?php echo $main_index; ?> 
*/5 * * * * /usr/bin/indexer --rotate <?php echo $delta_index; ?>
</pre>
<pre>
To install Sphinx in ubuntu follow this page
http://sphinxsearch.com/docs/current/installing-debian.html
</pre>
<?php 
if (!(is_dir(osc_uploads_path().'sphinx_data'))){
            mkdir(osc_uploads_path().'sphinx_data');
        }

?>