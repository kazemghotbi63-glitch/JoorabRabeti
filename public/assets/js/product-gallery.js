/* ═══════════════════════════════════════════════════════
   Product Gallery 3D — Rabati
   ═══════════════════════════════════════════════════════ */

(function () {
  "use strict";

  if (window.__pdgInit) return;
  window.__pdgInit = true;

  /* ─── المان‌ها ─── */
  var wrap = document.querySelector(".pdg-wrap");
  var main = document.querySelector(".pdg-main");
  var slides = document.querySelectorAll(".pdg-slide");
  var thumbs = document.querySelectorAll(".pdg-thumb");
  var dots = document.querySelectorAll(".pdg-dot");
  var navPrev = document.querySelector(".pdg-nav.prev");
  var navNext = document.querySelector(".pdg-nav.next");
  var lb = document.getElementById("pdgLb");
  var lbImg = document.getElementById("pdgLbImg");
  var lbClose = document.querySelector(".pdg-lb-close");
  var lbPrev = document.querySelector(".pdg-lb-nav.prev");
  var lbNext = document.querySelector(".pdg-lb-nav.next");
  var lbCounter = document.getElementById("pdgLbCounter");
  var counter = document.getElementById("pdgCounter");

  if (!main || !slides.length) return;

  var current = 0;
  var total = slides.length;
  var isTouch = window.matchMedia("(max-width: 900px)").matches;

  /* ─── لیست عکس‌ها برای Lightbox ─── */
  var images = [];
  slides.forEach(function (s) {
    images.push(s.dataset.src || "");
  });

  /* ═══════════════ تغییر عکس فعال ═══════════════ */

  function goTo(index) {
    if (index < 0) index = total - 1;
    if (index >= total) index = 0;

    current = index;

    slides.forEach(function (s, i) {
      s.classList.toggle("is-active", i === current);
    });

    thumbs.forEach(function (t, i) {
      t.classList.toggle("is-active", i === current);
    });

    dots.forEach(function (d, i) {
      d.classList.toggle("is-active", i === current);
    });

    if (counter) {
      counter.textContent = current + 1 + " / " + total;
    }
  }

  /* ═══════════════ تامنیل کلیک ═══════════════ */

  thumbs.forEach(function (thumb, i) {
    thumb.addEventListener("click", function () {
      goTo(i);
    });

    thumb.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        goTo(i);
      }
    });
  });

  /* ═══════════════ نقطه کلیک ═══════════════ */

  dots.forEach(function (dot, i) {
    dot.addEventListener("click", function () {
      goTo(i);
    });
  });

  /* ═══════════════ فلش‌های ناوبری ═══════════════ */

  if (navPrev)
    navPrev.addEventListener("click", function (e) {
      e.stopPropagation();
      goTo(current - 1);
    });

  if (navNext)
    navNext.addEventListener("click", function (e) {
      e.stopPropagation();
      goTo(current + 1);
    });

  /* ═══════════════ Tilt 3D روی دسکتاپ ═══════════════ */

  if (!isTouch && window.matchMedia("(hover: hover)").matches) {
    var MAX_TILT = 8;
    var rafId = null;

    main.addEventListener("mousemove", function (e) {
      var rect = main.getBoundingClientRect();
      var x = (e.clientX - rect.left) / rect.width;
      var y = (e.clientY - rect.top) / rect.height;

      var tiltY = (x - 0.5) * MAX_TILT * 2;
      var tiltX = (0.5 - y) * MAX_TILT * 2;

      /* روشنایی داینامیک */
      main.style.setProperty("--mx", x * 100 + "%");
      main.style.setProperty("--my", y * 100 + "%");

      if (rafId) cancelAnimationFrame(rafId);

      rafId = requestAnimationFrame(function () {
        main.style.transform =
          "perspective(1400px) " +
          "rotateX(" +
          tiltX.toFixed(2) +
          "deg) " +
          "rotateY(" +
          tiltY.toFixed(2) +
          "deg) " +
          "translateZ(0)";
      });
    });

    main.addEventListener("mouseenter", function () {
      main.classList.add("is-tilting");
    });

    main.addEventListener("mouseleave", function () {
      main.classList.remove("is-tilting");
      main.style.transform = "";

      if (rafId) cancelAnimationFrame(rafId);
    });
  }

  /* ═══════════════ Lightbox ═══════════════ */

  function openLb(index) {
    if (index === undefined) index = current;
    current = index;

    if (lbImg) lbImg.src = images[current];
    if (lbCounter) lbCounter.textContent = current + 1 + " / " + total;
    if (lb) lb.classList.add("is-open");
    document.body.style.overflow = "hidden";
  }

  function closeLb() {
    if (lb) lb.classList.remove("is-open");
    document.body.style.overflow = "";
  }

  function lbGo(dir) {
    current = (current + dir + total) % total;
    if (lbImg) lbImg.src = images[current];
    if (lbCounter) lbCounter.textContent = current + 1 + " / " + total;

    /* همزمان گالری اصلی رو هم به‌روز کن */
    slides.forEach(function (s, i) {
      s.classList.toggle("is-active", i === current);
    });
    thumbs.forEach(function (t, i) {
      t.classList.toggle("is-active", i === current);
    });
    dots.forEach(function (d, i) {
      d.classList.toggle("is-active", i === current);
    });
    if (counter) counter.textContent = current + 1 + " / " + total;
  }

  main.addEventListener("click", function (e) {
    /* اگه روی فلش‌های موبایل کلیک شد، Lightbox باز نشه */
    if (e.target.closest(".pdg-nav")) return;
    openLb(current);
  });

  /* لمس (موبایل) — swipe */
  var touchStartX = 0;
  var touchEndX = 0;

  main.addEventListener(
    "touchstart",
    function (e) {
      touchStartX = e.touches[0].clientX;
    },
    { passive: true },
  );

  main.addEventListener(
    "touchend",
    function (e) {
      touchEndX = e.changedTouches[0].clientX;
      var diff = touchStartX - touchEndX;

      if (Math.abs(diff) > 50) {
        if (diff > 0) goTo(current + 1);
        else goTo(current - 1);
      }
    },
    { passive: true },
  );

  /* بستن‌ها */
  if (lbClose) lbClose.addEventListener("click", closeLb);
  if (lbPrev)
    lbPrev.addEventListener("click", function (e) {
      e.stopPropagation();
      lbGo(-1);
    });
  if (lbNext)
    lbNext.addEventListener("click", function (e) {
      e.stopPropagation();
      lbGo(1);
    });

  if (lb) {
    lb.addEventListener("click", function (e) {
      if (e.target === lb) closeLb();
    });
  }

  /* کیبورد */
  document.addEventListener("keydown", function (e) {
    /* اگه Lightbox بازه */
    if (lb && lb.classList.contains("is-open")) {
      if (e.key === "Escape") closeLb();
      if (e.key === "ArrowLeft") lbGo(-1); /* RTL: چپ = بعدی */
      if (e.key === "ArrowRight") lbGo(1); /* RTL: راست = قبلی */
      return;
    }

    /* کیبورد روی گالری اصلی */
    if (e.target.closest(".pdg-wrap")) {
      if (e.key === "ArrowLeft") goTo(current + 1);
      if (e.key === "ArrowRight") goTo(current - 1);
    }
  });

  /* ═══════════════ مقدار اولیه ═══════════════ */

  goTo(0);
})();
