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
    window.onresize = resetSidebar;
    function resetSidebar() {
        var eT = document.querySelector("#header .toggle-icon");
        var sC;
        var eH;
        var eS;
        if (window.matchMedia("(max-width: 1024px)").matches) {
            eH = document.getElementById("header");
            eS = document.getElementById("sidebar-wrapper");
            eS.classList.add('offcanvas');
            eS.classList.add('offcanvas-start');
            // The header now lives inside the content column, so the off-canvas
            // sidebar spans the full height from the top — no header offset.
            eS.style.marginTop = "0px";
            eS.addEventListener('show.bs.offcanvas', function () {
                eT.classList.add('open');
            })
            eS.addEventListener('hide.bs.offcanvas', function () {
                eT.classList.remove('open');
            })
            /* The viewport is less than, or equal to, 768 pixels wide */
        } else {
            eS = document.querySelector("#sidebar-wrapper.offcanvas");
            if (eS !== null) {
                sC = bootstrap.Offcanvas.getOrCreateInstance(eS);
                if (sC) {
                    sC.dispose();
                    eS.classList.remove('offcanvas');
                    eS.classList.remove('offcanvas-start');
                    eS.classList.remove('show');
                    eS.removeAttribute('style');
                    eS.removeAttribute('aria-hidden');
                    document.querySelector('body').removeAttribute('style');
                    eT.classList.remove('open')
                }
            }
            /* The viewport is greater than 1024 pixels wide */
        }
    }
    resetSidebar();
</script>
<script>
    // Admin-shell controls. Placed at the end of the document so the sidebar and
    // its footer (theme toggle, collapse toggle) already exist when this runs.
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
    (function () {
        var toggle = document.getElementById('oscSidebarToggle');
        var nav = document.getElementById('sidebar-wrapper');
        if (!toggle || !nav) {
            return;
        }
        var root = document.documentElement;
        var desktop = window.matchMedia('(min-width: 1025px)');
        var items = nav.querySelectorAll('#dashboard-menu > li.nav-item');

        // Each collapsed row needs the label the rail can't show inline. A parent
        // reuses its own submenu as the flyout, headed by an injected title; a leaf
        // gets a small flyout element built here. Both are real DOM children of the
        // <li>, so the CSS :hover/:focus-within reveal keeps working while the
        // pointer is over the flyout (hover propagates up the DOM, not the visual
        // tree) even though the flyout is positioned outside the scroll frame. The
        // anchor's own label text stays in the a11y tree (font-size:0, not
        // display:none), so this only adds the visible affordance, never the name.
        for (var i = 0; i < items.length; i++) {
            var link = items[i].querySelector('.nav-link');
            var anchor = link ? link.querySelector('a') : null;
            if (!anchor) {
                continue;
            }
            var label = anchor.textContent.trim();
            var submenu = items[i].querySelector('.sidebar-submenu');
            if (submenu) {
                if (!submenu.querySelector('.rail-flyout-title')) {
                    var head = document.createElement('li');
                    head.className = 'rail-flyout-title';
                    head.setAttribute('aria-hidden', 'true');
                    head.textContent = label;
                    submenu.insertBefore(head, submenu.firstChild);
                }
            } else if (!items[i].querySelector('.sidebar-flyout')) {
                var chip = document.createElement('span');
                chip.className = 'sidebar-flyout';
                chip.setAttribute('aria-hidden', 'true');
                chip.textContent = label;
                items[i].appendChild(chip);
            }
        }

        // Collapsed flyouts escape the scroll frame with position:fixed, so their
        // viewport coordinates are computed here from the row's live rect. The CSS
        // owns the reveal (opacity/visibility on hover/focus); JS owns only where.
        var open = null;
        function collapsed() {
            return root.getAttribute('data-osc-sidebar') === 'collapsed' && desktop.matches;
        }
        function place(item) {
            var link = item.querySelector('.nav-link');
            var fly = item.querySelector('.sidebar-submenu') || item.querySelector('.sidebar-flyout');
            if (!link || !fly) {
                return;
            }
            var r = link.getBoundingClientRect();
            var top = r.top;
            var maxTop = window.innerHeight - fly.offsetHeight - 8;
            if (top > maxTop) {
                top = Math.max(8, maxTop);
            }
            fly.style.top = top + 'px';
            if (getComputedStyle(root).direction === 'rtl') {
                fly.style.right = (window.innerWidth - r.left + 6) + 'px';
                fly.style.left = 'auto';
            } else {
                fly.style.left = (r.right + 6) + 'px';
                fly.style.right = 'auto';
            }
        }
        for (var j = 0; j < items.length; j++) {
            (function (item) {
                function enter() { if (collapsed()) { open = item; place(item); } }
                function leave() { if (open === item) { open = null; } }
                item.addEventListener('mouseenter', enter);
                item.addEventListener('mouseleave', leave);
                item.addEventListener('focusin', enter);
                item.addEventListener('focusout', leave);
            })(items[j]);
        }
        var scroller = nav.querySelector('.sidebar-scroll');
        if (scroller) {
            scroller.addEventListener('scroll', function () { if (open && collapsed()) { place(open); } }, {passive: true});
        }
        window.addEventListener('resize', function () { if (open && collapsed()) { place(open); } });

        toggle.addEventListener('click', function () {
            var next = root.getAttribute('data-osc-sidebar') === 'collapsed' ? 'expanded' : 'collapsed';
            root.setAttribute('data-osc-sidebar', next);
            toggle.setAttribute('aria-expanded', next === 'collapsed' ? 'false' : 'true');
            fetch('<?php echo osc_admin_base_url(true) . '?page=ajax&action=save_sidebar_state&' . osc_csrf_token_url(); ?>&state=' + next, {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
        });
    })();
    (function () {
        // A ghost scrollbar for the menu: the native bar is hidden and this thin
        // overlay tracks it, appearing on scroll or hover and fading when idle, so
        // the sidebar reads the same on Windows/Linux (which reserve gutter space
        // for native bars) as it does with macOS overlay bars. The thumb is
        // draggable. On small screens the menu isn't a scroll container, so
        // scrollHeight matches clientHeight and the bar simply never shows.
        var frame = document.querySelector('.sidebar-scroll-frame');
        var scroller = frame ? frame.querySelector('.sidebar-scroll') : null;
        if (!frame || !scroller) {
            return;
        }
        var bar = document.createElement('div');
        bar.className = 'ghost-scrollbar';
        var thumb = document.createElement('div');
        thumb.className = 'ghost-scrollbar-thumb';
        bar.appendChild(thumb);
        frame.appendChild(bar);

        var hideTimer;
        function measure() {
            var ch = scroller.clientHeight;
            var sh = scroller.scrollHeight;
            if (sh - ch <= 1) {
                bar.classList.remove('is-active');
                bar.style.display = 'none';
                return null;
            }
            bar.style.display = '';
            var thumbH = Math.max(28, Math.round(ch * ch / sh));
            var maxThumb = ch - thumbH;
            var top = maxThumb > 0 ? Math.round(scroller.scrollTop / (sh - ch) * maxThumb) : 0;
            thumb.style.height = thumbH + 'px';
            thumb.style.transform = 'translateY(' + top + 'px)';
            return {ch: ch, sh: sh, thumbH: thumbH, maxThumb: maxThumb};
        }
        function flash() {
            if (measure()) {
                bar.classList.add('is-active');
                clearTimeout(hideTimer);
                hideTimer = setTimeout(function () {
                    if (!dragging && !bar.matches(':hover')) { bar.classList.remove('is-active'); }
                }, 1000);
            }
        }
        scroller.addEventListener('scroll', flash, {passive: true});
        frame.addEventListener('mouseenter', flash);
        window.addEventListener('resize', measure);

        var dragging = false, startY = 0, startScroll = 0;
        thumb.addEventListener('mousedown', function (e) {
            var m = measure();
            if (!m) { return; }
            dragging = true;
            startY = e.clientY;
            startScroll = scroller.scrollTop;
            document.body.style.userSelect = 'none';
            e.preventDefault();
        });
        window.addEventListener('mousemove', function (e) {
            if (!dragging) { return; }
            var m = measure();
            if (!m || m.maxThumb <= 0) { return; }
            scroller.scrollTop = startScroll + (e.clientY - startY) / m.maxThumb * (m.sh - m.ch);
        });
        window.addEventListener('mouseup', function () {
            if (dragging) { dragging = false; document.body.style.userSelect = ''; flash(); }
        });
        bar.addEventListener('mouseenter', function () { clearTimeout(hideTimer); if (measure()) { bar.classList.add('is-active'); } });
        bar.addEventListener('mouseleave', flash);
        measure();
    })();
</script>
</body>
</html>