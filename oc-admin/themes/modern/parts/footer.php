                        <?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
} ?>
                    </div>
                    <div class="clear"></div>
                </div>
            </div><!-- #content-page -->
            <footer id="footer-wrapper" class="row">
                <div class="col">
                    <div id="footer">
                        <?php osc_run_hook('admin_content_footer'); ?>
                    </div>
                </div>
            </footer>
        </main><!-- #content-render -->
    </div><!-- #content -->
    <?php osc_run_hook('admin_footer'); ?>
<script>
    // Admin-shell controls. Placed at the end of the document so the sidebar and
    // its footer (the theme toggle) already exist when this runs.
    (function () {
        var btn = document.getElementById('oscThemeToggle');
        if (btn) {
            var root = document.documentElement;
            var icon = btn.querySelector('i');
            // Flip the attribute first so the change is instant — the whole theme is CSS custom
            // properties keyed off data-bs-theme, nothing to reload — then persist it best-effort.
            // If the write fails the UI is still correct for this page; the next load reverts.
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-bs-theme', next);
                if (icon) {
                    icon.classList.toggle('bi-moon-stars-fill', next === 'light');
                    icon.classList.toggle('bi-sun-fill', next === 'dark');
                }
                var hint = next === 'dark' ? '<?php echo osc_esc_js(__('Switch to light mode')); ?>' : '<?php echo osc_esc_js(__('Switch to dark mode')); ?>';
                btn.setAttribute('title', hint);
                btn.setAttribute('aria-label', hint);
                fetch('<?php echo osc_admin_base_url(true) . '?page=ajax&action=save_admin_theme&' . osc_csrf_token_url(); ?>&theme=' + next, {
                    method: 'POST',
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                });
            });
        }
    })();
</script>
</body>
</html>