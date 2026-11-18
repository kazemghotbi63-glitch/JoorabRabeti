(function() {
            'use strict';

            if (window.__r6IgInit) return;
            window.__r6IgInit = true;

            /* ═══════════ Lightbox ═══════════ */
            var lb = document.getElementById('r6IgLightbox');
            var lbImg = document.getElementById('r6IgLbImg');
            var lbVid = document.getElementById('r6IgLbVid');
            var lbCap = document.getElementById('r6IgLbCaption');

            function openLb(type, src, caption) {
                if (type === 'video') {
                    lbImg.style.display = 'none';
                    lbImg.removeAttribute('src');
                    lbVid.style.display = 'block';
                    lbVid.src = src;
                    try {
                        lbVid.play();
                    } catch (e) {}
                } else {
                    lbVid.style.display = 'none';
                    try {
                        lbVid.pause();
                    } catch (e) {}
                    lbVid.removeAttribute('src');
                    lbImg.style.display = 'block';
                    lbImg.src = src;
                }
                lbCap.textContent = caption || '';
                lb.classList.add('is-open');
                lb.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }

            function closeLb() {
                lb.classList.remove('is-open');
                lb.setAttribute('aria-hidden', 'true');
                lbImg.removeAttribute('src');
                try {
                    lbVid.pause();
                } catch (e) {}
                lbVid.removeAttribute('src');
                document.body.style.overflow = '';
            }

            document.addEventListener('click', function(e) {
                var el = e.target.closest('.r6-ig-item');
                if (!el) return;
                if (el.getAttribute('data-has-link') === '1') return;
                e.preventDefault();
                e.stopPropagation();
                openLb(el.dataset.type || 'image', el.dataset.src, el.dataset.caption || '');
            }, true);

            lb.addEventListener('click', function(e) {
                if (e.target === lb ||
                    e.target.classList.contains('r6-ig-lb-close') ||
                    e.target.closest('.r6-ig-lb-close') ||
                    e.target.classList.contains('r6-ig-lb-stage')) {
                    closeLb();
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && lb.classList.contains('is-open')) closeLb();
            });

            /* ═══════════ Carousel ═══════════ */
            var track = document.getElementById('r6IgTrack');
            var dotsEl = document.getElementById('r6IgDots');
            var btnPrev = document.getElementById('r6IgPrev');
            var btnNext = document.getElementById('r6IgNext');

            if (!track || !dotsEl) return;

            var currentPage = 0;

            function getPageCount() {
                var item = track.querySelector('.r6-ig-item');
                if (!item) return 1;

                var style = window.getComputedStyle(track);
                var gap = parseFloat(style.gap) || 16;
                var itemW = item.offsetWidth + gap;
                var colCount = Math.ceil(track.querySelectorAll('.r6-ig-item').length / 2);
                var visibleCols = Math.max(1, Math.floor((track.clientWidth + gap) / itemW));
                return Math.max(1, Math.ceil(colCount / visibleCols));
            }

            function renderDots() {
                var pages = getPageCount();
                dotsEl.innerHTML = '';
                if (pages <= 1) {
                    dotsEl.style.display = 'none';
                    return;
                }
                dotsEl.style.display = 'flex';
                for (var i = 0; i < pages; i++) {
                    var dot = document.createElement('button');
                    dot.type = 'button';
                    dot.className = 'r6-ig-dot' + (i === currentPage ? ' is-active' : '');
                    dot.setAttribute('aria-label', 'صفحه ' + (i + 1));
                    dot.dataset.page = i;
                    dotsEl.appendChild(dot);
                }
            }

            function goToPage(page) {
                var pages = getPageCount();
                page = Math.max(0, Math.min(pages - 1, page));
                currentPage = page;

                /* در RTL، scrollLeft منفی می‌شود */
                var target = -page * track.clientWidth;
                try {
                    track.scrollTo({
                        left: target,
                        behavior: 'smooth'
                    });
                } catch (err) {
                    track.scrollLeft = target;
                }

                updateUI();
            }

            function updateUI() {
                var pages = getPageCount();

                if (btnPrev) btnPrev.disabled = currentPage <= 0;
                if (btnNext) btnNext.disabled = currentPage >= pages - 1;

                dotsEl.querySelectorAll('.r6-ig-dot').forEach(function(d, i) {
                    d.classList.toggle('is-active', i === currentPage);
                });
            }

            /* کلیک دات‌ها */
            dotsEl.addEventListener('click', function(e) {
                var dot = e.target.closest('.r6-ig-dot');
                if (!dot) return;
                goToPage(parseInt(dot.dataset.page, 10) || 0);
            });

            /* کلیک فلش‌ها */
            if (btnPrev) btnPrev.addEventListener('click', function() {
                goToPage(currentPage - 1);
            });
            if (btnNext) btnNext.addEventListener('click', function() {
                goToPage(currentPage + 1);
            });

            /* بروزرسانی دات‌ها روی اسکرول دستی */
            var scrollTimer = null;
            track.addEventListener('scroll', function() {
                clearTimeout(scrollTimer);
                scrollTimer = setTimeout(function() {
                    var w = track.clientWidth;
                    if (!w) return;
                    var p = Math.round(Math.abs(track.scrollLeft) / w);
                    if (p !== currentPage) {
                        currentPage = p;
                        updateUI();
                    }
                }, 80);
            });

            /* Resize */
            var resizeTimer = null;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    renderDots();
                    goToPage(0);
                }, 150);
            });

            /* شروع */
            renderDots();
            updateUI();
        })();
