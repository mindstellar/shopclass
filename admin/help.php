<h2 class="render-title ">ShopClass Help</h2>
<h3 class="render-title ">ShopClass Theme Requirements.</h3>
<div class="help-box">1. You have installed Php version 5.6.x or greater.</div>
<div class="help-box">2. Themes directory ( oc-content/themes ) must have more safer permission 755 for Osclass Editor
    to work.
</div>
<div class="help-box">3. You have enabled Permalinks.</div>
<div class="help-box">4. Settings Configured Correctly.</div>
<div class="help-box">5. Install free <a title="Free Rating Plugin"
                                         href="https://market.osclass.org/plugins/reviews-ratings/rating_35">Rating(Old
        Name:Voting) plugin</a> from Osclass Market. It will enable voting related features in our theme.
</div>

<div style="margin-top:1em;margin-bottom:1em;"></div>

<h3 class="render-title">Documentation</h3>

<p>Visit <a href="https://www.tuffindia.com/docs/shopclass-osclass-docs/">TuffIndia.COM</a> For Shopclass Documentation
</p>

<div style="margin-top:1em;margin-bottom:1em;"></div>

<h3 class="render-title ">Forum Support</h3>
<div class="help-box">Contact us through our theme repository.</div>
<div class="help-box">You can also mail us at navjottomer@gmail.com</div>
<div class="help-box">Only contact through email, if you do not have forum access.</div>
<div class="help-box">Make sure to describe your issue briefly and try to use English as language.</div>
<div style="margin-top:1em;margin-bottom:1em;"></div>

<h3 class="render-title danger">Note:</h3>
<div class="help-box">Make sure to clear theme cache from theme setting page after any setting change.</div>
<div class="help-box">See Changelog.txt for changes in latest version and changed files.</div>

<div class="help-box">Before giving us bad rating please ask for help.</div>
<div class="help-box">Please do rate our theme with good review if you like it in Osclass Market, It will help us.</div>
<h2>Some sample snippets to improve your osclass performance</h2>
<h3>To Enable Compression add these lines to your htaccess file</h3>
<style>

    .html4strict {
        font-family: monospace;
        color: #006;
        border: 1px solid #d0d0d0;
        background-color: #f0f0f0;
    }

    .html4strict a:link {
        color: #000060;
    }

    .html4strict a:hover {
        background-color: #f0f000;
    }

    .html4strict .sy0 {
        color: #66cc66;
    }

    .html4strict .sc2 {
        color: #009900;
    }
</style>
<p>
<div class="html4strict">&nbsp;<span class="sc2">&lt;IfModule mod_deflate.c&gt;</span><br/>

    # force deflate for mangled headers <br/>

    <span class="sc2">&lt;IfModule mod_setenvif.c&gt;</span><br/>
    &nbsp; <span class="sc2">&lt;IfModule mod_headers.c&gt;</span><br/>
    &nbsp; &nbsp; SetEnvIfNoCase ^(Accept-EncodXng|X-cept-Encoding|X{15}|~{15}|-{15})$
    ^((gzip|deflate)\s*,?\s*)+|[X~-]{4,13}$ HAVE_Accept-Encoding<br/>
    &nbsp; &nbsp; RequestHeader append Accept-Encoding &quot;gzip,deflate&quot; env=HAVE_Accept-Encoding<br/>
    &nbsp; <span class="sc2">&lt;<span class="sy0">/</span>IfModule&gt;</span><br/>
    <span class="sc2">&lt;<span class="sy0">/</span>IfModule&gt;</span><br/>
    <br/>
    # HTML, TXT, CSS, JavaScript, JSON, XML, HTC:<br/>
    <span class="sc2">&lt;IfModule filter_module&gt;</span><br/>
    &nbsp; FilterDeclare &nbsp; COMPRESS<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $text/html<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $text/css<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $text/plain<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $text/xml<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $text/x-component<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $application/javascript<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $application/json<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $application/xml<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $application/xhtml+xml<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $application/rss+xml<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $application/atom+xml<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $application/vnd.ms-fontobject<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $image/svg+xml<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $application/x-font-ttf<br/>
    &nbsp; FilterProvider &nbsp;COMPRESS &nbsp;DEFLATE resp=Content-Type $font/opentype<br/>
    &nbsp; FilterChain &nbsp; &nbsp; COMPRESS<br/>
    &nbsp; FilterProtocol &nbsp;COMPRESS &nbsp;DEFLATE change=yes;byteranges=no<br/>
    <span class="sc2">&lt;<span class="sy0">/</span>IfModule&gt;</span><br/>
    <br/>
    <span class="sc2">&lt;IfModule !mod_filter.c&gt;</span><br/>
    &nbsp; # Legacy versions of Apache<br/>
    &nbsp; AddOutputFilterByType DEFLATE text/html text/plain text/css application/json<br/>
    &nbsp; AddOutputFilterByType DEFLATE application/javascript<br/>
    &nbsp; AddOutputFilterByType DEFLATE text/xml application/xml text/x-component<br/>
    &nbsp; AddOutputFilterByType DEFLATE application/xhtml+xml application/rss+xml application/atom+xml<br/>
    &nbsp; AddOutputFilterByType DEFLATE image/svg+xml application/vnd.ms-fontobject application/x-font-ttf
    font/opentype<br/>
    <span class="sc2">&lt;<span class="sy0">/</span>IfModule&gt;</span><br/>
    <span class="sc2">&lt;<span class="sy0">/</span>IfModule&gt;</span></div></p>

<h3>To add far future expire header to your file add these lines</h3>
<div class="html4strict"
     style="font-family:monospace;color: #006; border: 1px solid #d0d0d0; background-color: #f0f0f0;"><span
            style="color: #009900;">&lt;IfModule mod_expires.c&gt;</span><br/>
    &nbsp; ExpiresActive on<br/>
    <br/>
    # Perhaps better to whitelist expires rules? Perhaps.<br/>
    &nbsp; ExpiresDefault &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;&quot;access
    plus 1 month&quot;<br/>
    <br/>
    # cache.appcache needs re-requests in FF 3.6 (thx Remy ~Introducing HTML5)<br/>
    &nbsp; ExpiresByType text/cache-manifest &nbsp; &nbsp; &nbsp; &quot;access plus 0 seconds&quot;<br/>
    <br/>
    <br/>
    <br/>
    # Your document html<br/>
    &nbsp; ExpiresByType text/html &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &quot;access plus 0 seconds&quot;<br/>
    <br/>
    # Data<br/>
    &nbsp; ExpiresByType text/xml &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;&quot;access plus 0
    seconds&quot;<br/>
    &nbsp; ExpiresByType application/xml &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &quot;access plus 0 seconds&quot;<br/>
    &nbsp; ExpiresByType application/json &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;&quot;access plus 0 seconds&quot;<br/>
    <br/>
    # RSS feed<br/>
    &nbsp; ExpiresByType application/rss+xml &nbsp; &nbsp; &nbsp; &quot;access plus 1 hour&quot;<br/>
    <br/>
    # Favicon (cannot be renamed)<br/>
    &nbsp; ExpiresByType image/x-icon &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;&quot;access plus 1 week&quot;
    <br/>
    <br/>
    # Media: images, video, audio<br/>
    &nbsp; ExpiresByType image/gif &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &quot;access plus 1 month&quot;<br/>
    &nbsp; ExpiresByType image/png &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &quot;access plus 1 month&quot;<br/>
    &nbsp; ExpiresByType image/jpg &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &quot;access plus 1 month&quot;<br/>
    &nbsp; ExpiresByType image/jpeg &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;&quot;access plus 1 month&quot;<br/>
    &nbsp; ExpiresByType video/ogg &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &quot;access plus 1 month&quot;<br/>
    &nbsp; ExpiresByType audio/ogg &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &quot;access plus 1 month&quot;<br/>
    &nbsp; ExpiresByType video/mp4 &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &quot;access plus 1 month&quot;<br/>
    &nbsp; ExpiresByType video/webm &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;&quot;access plus 1 month&quot;<br/>
    <br/>
    # HTC files &nbsp;(css3pie)<br/>
    &nbsp; ExpiresByType text/x-component &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;&quot;access plus 1 month&quot;<br/>
    <br/>
    # Webfonts<br/>
    &nbsp; ExpiresByType font/truetype &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &quot;access plus 1 month&quot;<br/>
    &nbsp; ExpiresByType font/opentype &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &quot;access plus 1 month&quot;<br/>
    &nbsp; ExpiresByType application/x-font-woff &nbsp; &quot;access plus 1 month&quot;<br/>
    &nbsp; ExpiresByType image/svg+xml &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &quot;access plus 1 month&quot;<br/>
    &nbsp; ExpiresByType application/vnd.ms-fontobject &quot;access plus 1 month&quot;<br/>
    <br/>
    # CSS and JavaScript<br/>
    &nbsp; ExpiresByType text/css &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;&quot;access plus 1 year&quot;<br/>
    &nbsp; ExpiresByType application/javascript &nbsp; &nbsp;&quot;access plus 1 year&quot;<br/>
    &nbsp; ExpiresByType text/javascript &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &quot;access plus 1 year&quot;<br/>
    <br/>
    &nbsp; <span style="color: #009900;">&lt;IfModule mod_headers.c&gt;</span><br/>
    &nbsp; &nbsp; Header append Cache-Control &quot;public&quot;<br/>
    &nbsp; <span style="color: #009900;">&lt;<span style="color: #66cc66;">/</span>IfModule&gt;</span><br/>
    <br/>
    <span style="color: #009900;">&lt;<span style="color: #66cc66;">/</span>IfModule&gt;</span></div>
<h2>Replace default Osclass rewrite rules by these lines to improve performance</h2>
<div class="apache" style="font-family:monospace;color: #006; border: 1px solid #d0d0d0; background-color: #f0f0f0;">
    <span style="color: #adadad; font-style: italic;">####Custom Rewrite Rules Start###</span><br/>
    <span style="color: #00007f;">RewriteEngine</span> <span style="color: #0000ff;">on</span><br/>
    <span style="color: #00007f;">RewriteBase</span> <?php echo REL_WEB_URL; ?><br/>
    <span style="color: #00007f;">RewriteCond</span> $<span style="color: #ff0000;">1</span> ^<span
            style="color: #339933;">&#40;</span>index\.php<span style="color: #339933;">&#41;</span>?$ <span
            style="color: #339933;">&#91;</span>OR<span style="color: #339933;">&#93;</span><br/>
    <span style="color: #00007f;">RewriteCond</span> $<span style="color: #ff0000;">1</span> \.<span
            style="color: #339933;">&#40;</span>gif|jpg|css|js|png|ico<span style="color: #339933;">&#41;</span>$ <span
            style="color: #339933;">&#91;</span>NC,OR<span style="color: #339933;">&#93;</span><br/>
    <span style="color: #00007f;">RewriteCond</span> %<span style="color: #339933;">&#123;</span>REQUEST_FILENAME<span
            style="color: #339933;">&#125;</span> <span style="color: #008000;">-</span>f <span style="color: #339933;">&#91;</span>OR<span
            style="color: #339933;">&#93;</span><br/>
    <span style="color: #00007f;">RewriteCond</span> %<span style="color: #339933;">&#123;</span>REQUEST_FILENAME<span
            style="color: #339933;">&#125;</span> <span style="color: #008000;">-</span>d<br/>
    <span style="color: #00007f;">RewriteRule</span> ^<span style="color: #339933;">&#40;</span>.*<span
            style="color: #339933;">&#41;</span>$ <span style="color: #008000;">-</span> <span style="color: #339933;">&#91;</span>S=<span
            style="color: #ff0000;">1</span><span style="color: #339933;">&#93;</span><br/>
    <span style="color: #00007f;">RewriteRule</span> . <?php echo REL_WEB_URL; ?>index.php <span
            style="color: #339933;">&#91;</span>L<span style="color: #339933;">&#93;</span><br/>
    <span style="color: #adadad; font-style: italic;">####Custom Rewrite Rules End####</span></div>

</div>