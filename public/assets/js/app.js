/* ═══════════════════════════════════════════════════════════
   RABETI — app.js (v6 — فیکس منوی کشویی + بهینه‌سازی TBT — 2026-11-23)
   ═══════════════════════════════════════════════════════════
   • تمام متغیرها یکتا (بدون تداخل)
   • ساختار IIFE برای جلوگیری از آلودگی global scope
   • منو، هدر، توست، سبد خرید، ثبت‌نام، فراموشی رمز، شبکه‌های اجتماعی
   ═══════════════════════════════════════════════════════════ */

(function () {
  "use strict";

  /* ══════════ ۰. Helper ══════════ */
  function safeTarget(e) {
    return e.target instanceof Element ? e.target : null;
  }

  /* ══════════ CSRF ══════════ */
  function csrfHeaders(extra) {
    extra = extra || {};
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    return token ? Object.assign({}, extra, { "X-CSRF-Token": token }) : extra;
  }

  /* ══════════ بازیابی اسکرول (بعد از login) ══════════ */
  try {
    var r6ScrollY = sessionStorage.getItem("__restore_scroll");
    if (r6ScrollY) {
      sessionStorage.removeItem("__restore_scroll");
      window.addEventListener("load", function () {
        window.scrollTo(0, parseInt(r6ScrollY, 10) || 0);
      });
    }
  } catch (e) {}

  /* ══════════ اعداد فارسی ══════════ */
  function faToEn(v) {
    return String(v).replace(/[۰-۹]/g, function (d) {
      return "۰۱۲۳۴۵۶۷۸۹".indexOf(d);
    });
  }
  function enToFa(n) {
    return String(n).replace(/\d/g, function (d) {
      return "۰۱۲۳۴۵۶۷۸۹"[d];
    });
  }

  /* ══════════ آپدیت Badge سبد ══════════ */
  window.updateCartBadges = function (count) {
    if (count === undefined || count === null) return;
    ["cartBadge", "cartBadgeFloat", "cartBadgeBottom"].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.textContent = enToFa(count);
      el.style.removeProperty("display");
      if (Number(count) > 0) el.classList.add("has-count");
    });
  };

  /* ══════════ ۱. کشوی منو ══════════ */
  var appDrawer = document.getElementById("drawer");
  var appOverlay = document.getElementById("overlay");
  var appMenuBtn = document.getElementById("menuBtn");
  var appCloseBtn = document.getElementById("closeBtn");

  /* ⭐ FIX (باگ کلیک منو): کشو در HTML با aria-hidden="true" و inert رندر می‌شود.
     اگر هنگام باز کردن این دو آپدیت نشوند، کل زیردرخت منو «inert» می‌ماند و
     مرورگر همه‌ی کلیک‌ها/فوکوس‌های داخل منو را نادیده می‌گیرد — یعنی کاربر
     روی «محصولات» یا هر لینک دیگری می‌زند و اتفاقی نمی‌افتد.
     حالا باز/بسته کردن منو aria-hidden و inert را هم‌زمان همگام می‌کند
     (هماهنگ با اسکریپت syncInert در layout) و aria-expanded دکمه همبرگر
     هم برای اسکرین‌ریدر به‌روز می‌شود. */
  function openDrawer() {
    if (appDrawer) {
      appDrawer.classList.add("open");
      appDrawer.setAttribute("aria-hidden", "false");
      appDrawer.removeAttribute("inert");
    }
    if (appOverlay) appOverlay.classList.add("show");
    document.body.style.overflow = "hidden";
    document.body.classList.add("drawer-open");
    if (appMenuBtn) appMenuBtn.setAttribute("aria-expanded", "true");
    /* فوکوس اولین لینک منو — ناوبری کیبورد درست شروع شود */
    var firstLink = appDrawer && appDrawer.querySelector(".r6-drawer-link, #closeBtn");
    if (firstLink) {
      try { firstLink.focus({ preventScroll: true }); } catch (e) { firstLink.focus(); }
    }
  }
  function closeDrawer() {
    if (appDrawer) {
      appDrawer.classList.remove("open");
      appDrawer.setAttribute("aria-hidden", "true");
      appDrawer.setAttribute("inert", "");
    }
    if (appOverlay) appOverlay.classList.remove("show");
    document.body.style.overflow = "";
    document.body.classList.remove("drawer-open");
    if (appMenuBtn) appMenuBtn.setAttribute("aria-expanded", "false");
  }

  if (appMenuBtn) {
    appMenuBtn.setAttribute("aria-controls", "drawer");
    appMenuBtn.setAttribute("aria-expanded", "false");
    appMenuBtn.addEventListener("click", openDrawer);
  }
  if (appCloseBtn) appCloseBtn.addEventListener("click", closeDrawer);
  if (appOverlay) appOverlay.addEventListener("click", closeDrawer);

  document.querySelectorAll(".r6-drawer-link").forEach(function (l) {
    l.addEventListener("click", function () {
      closeDrawer();
      /* ⭐ نکته: اینجا closeDrawer کشو را inert می‌کند، اما چون لینک همچنان
         پیش‌فرض مرورگر (ناوبری به href) را اجرا می‌کند، رفتار کلیک سالم است. */
    });
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      closeDrawer();
      if (typeof window.closeAuthModal === "function") window.closeAuthModal();
    }
  });

  /* ══════════ ۲. هدر اسکرول ══════════ */
  var appSiteHeader = document.getElementById("siteHeader");
  var appBackToTop = document.getElementById("backToTop");

  window.addEventListener(
    "scroll",
    function () {
      if (appSiteHeader) {
        appSiteHeader.classList.toggle("is-scrolled", window.scrollY > 10);
      }
      if (appBackToTop) {
        appBackToTop.classList.toggle("show", window.scrollY > 500);
      }
    },
    { passive: true },
  );

  if (appBackToTop) {
    appBackToTop.addEventListener("click", function () {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }

  /* ══════════ ۳. توست ══════════ */
  var appToastTimer;

  window.showToast = function (message, duration) {
    duration = duration || 2800;
    var t = document.getElementById("toast");
    if (!t) return;
    t.textContent = message;
    t.classList.add("show");
    clearTimeout(appToastTimer);
    appToastTimer = setTimeout(function () {
      t.classList.remove("show");
    }, duration);
  };

  /* ══════════ ۴. نوار پایین — هایلایت ══════════ */
  document.querySelectorAll(".bottom-nav .bn-item").forEach(function (item) {
    var href = item.getAttribute("href");
    var path = window.location.pathname;
    var isHome = href === "/" && (path === "/" || path === "/index.php");
    if (isHome || (href && href !== "/" && path.indexOf(href) === 0)) {
      item.classList.add("active");
    }
  });

  /* ══════════ ۵. Stepper ══════════ */
  function stepperRead(numEl) {
    var raw = numEl.tagName === "INPUT" ? numEl.value : numEl.textContent;
    return parseInt(faToEn(raw), 10) || 1;
  }
  function stepperWrite(numEl, val) {
    if (numEl.tagName === "INPUT") numEl.value = enToFa(val);
    else numEl.textContent = enToFa(val);
  }

  document.addEventListener("click", function (e) {
    var t = safeTarget(e);
    if (!t) return;
    var btn = t.closest(".step, .r6-step");
    if (!btn) return;
    if (btn.closest(".cart-item")) return;
    if (btn.closest(".pd-stepper, .pd-order-block")) return;

    var st = btn.closest(".stepper, .r6-stepper");
    var num = st ? st.querySelector(".step-num, .r6-num") : null;
    if (!num) return;

    var min = parseInt(st.dataset.min || "1", 10) || 1;
    var max = parseInt(st.dataset.max || "0", 10) || 0;
    var val = stepperRead(num);

    if (btn.classList.contains("plus")) {
      val++;
      if (max > 0 && val > max) {
        val = max;
        if (window.showToast) window.showToast("⚠️ حداکثر موجودی: " + max);
      }
    }
    if (btn.classList.contains("minus")) val = Math.max(min, val - 1);
    stepperWrite(num, val);
  });

  document.addEventListener("input", function (e) {
    var t = safeTarget(e);
    if (!t) return;
    var inp = t.closest(".step-input");
    if (!inp) return;
    var v = faToEn(inp.value).replace(/[^\d]/g, "");
    inp.value = v ? enToFa(parseInt(v, 10)) : "";
  });

  document.addEventListener(
    "blur",
    function (e) {
      var t = safeTarget(e);
      if (!t) return;
      var inp = t.closest(".step-input");
      if (!inp) return;
      var st = inp.closest(".stepper, .r6-stepper");
      var min = parseInt(st?.dataset.min || "1", 10) || 1;
      var max = parseInt(st?.dataset.max || "0", 10) || 0;
      var v = parseInt(faToEn(inp.value), 10);
      if (!v || v < min) v = min;
      if (max > 0 && v > max) {
        v = max;
        if (window.showToast) window.showToast("⚠️ حداکثر موجودی: " + max);
      }
      stepperWrite(inp, v);
    },
    true,
  );

  /* ══════════ ۶. افزودن به سبد ══════════ */
  document.addEventListener("click", async function (e) {
    var t = safeTarget(e);
    if (!t) return;
    var btn = t.closest(".r6-add, .quick-add");
    if (!btn) return;
    if (btn.classList.contains("pd-order-btn")) return;

    var card = btn.closest("[data-product-id]");
    if (!card) return;

    var pid = card.dataset.productId;
    var numEl = card.querySelector(".step-num, .r6-num");
    var qty = numEl ? stepperRead(numEl) : 1;

    btn.disabled = true;
    try {
      var fd = new FormData();
      fd.append("product_id", pid);
      fd.append("qty", qty);
      var res = await fetch("/api/cart", {
        method: "POST",
        body: fd,
        headers: csrfHeaders(),
      });
      var data = await res.json();

      if (res.status === 401) {
        showToast("🔒 برای خرید ابتدا وارد شوید");
        document.getElementById("authModalOverlay")?.classList.add("show");
        document.body.style.overflow = "hidden";
        return;
      }
      if (!data.ok) {
        showToast("⚠️ " + data.error);
        return;
      }
      showToast(data.msg || "✓ به سبد اضافه شد");
      window.updateCartBadges(data.count);
    } catch (err) {
      console.error("CART_ERROR:", err);
      showToast("خطای ارتباط با سرور");
    } finally {
      btn.disabled = false;
    }
  });

  /* ══════════ ۷. Modal ورود / ثبت‌نام ══════════ */
  var authOverlay = document.getElementById("authModalOverlay");
  var authClose = document.getElementById("authModalClose");
  var authTabs = document.querySelectorAll(".auth-tab[data-tab]");
  var utypeTabs = document.querySelectorAll(".auth-tab[data-utype]");

  function switchAuthTab(tab) {
    var isRegister = tab === "register";
    document
      .getElementById("view-login")
      ?.classList.toggle("active", !isRegister);
    document
      .getElementById("view-register")
      ?.classList.toggle("active", isRegister);
    document.getElementById("view-forgot")?.classList.remove("active");

    var tabsBox = document.getElementById("authTabsBox");
    if (tabsBox) {
      tabsBox.style.display = "";
      tabsBox.classList.toggle("register-mode", isRegister);
    }
    authTabs.forEach(function (b) {
      b.classList.toggle("active", b.dataset.tab === tab);
    });
    if (isRegister) switchUserType("real");
  }

  window.openAuthModal = function (tab) {
    tab = tab || "login";
    if (authOverlay) authOverlay.classList.add("show");
    document.body.style.overflow = "hidden";
    switchAuthTab(tab);
  };

  window.closeAuthModal = function () {
    if (authOverlay) authOverlay.classList.remove("show");
    document.body.style.overflow = "";
  };

  document.querySelectorAll("[data-auth-open]").forEach(function (b) {
    b.addEventListener("click", function (ev) {
      ev.preventDefault();
      closeDrawer();
      window.openAuthModal(b.dataset.authOpen || "login");
    });
  });

  authTabs.forEach(function (b) {
    b.addEventListener("click", function () {
      switchAuthTab(b.dataset.tab || "login");
    });
  });

  if (authClose) authClose.addEventListener("click", window.closeAuthModal);
  if (authOverlay) {
    authOverlay.addEventListener("click", function (e) {
      if (e.target === authOverlay) window.closeAuthModal();
    });
  }

  /* ══════════ ۸. ثبت‌نام — حقیقی / حقوقی ══════════ */
  var currentUserType = "real";

  function switchUserType(type) {
    currentUserType = type;
    var isLegal = type === "legal";
    var pane = document.getElementById("pane-register");
    if (!pane) return;

    pane.classList.toggle("utype-legal-on", isLegal);
    var legalBox = pane.querySelector(".utype-legal");
    var realBox = pane.querySelector(".utype-real");
    if (legalBox) legalBox.style.display = isLegal ? "block" : "none";
    if (realBox) realBox.style.display = isLegal ? "none" : "block";

    var slider = document.getElementById("utypeSlider");
    if (slider) slider.style.transform = isLegal ? "translateX(-100%)" : "none";
    utypeTabs.forEach(function (b) {
      b.classList.toggle("active", b.dataset.utype === type);
    });
  }

  utypeTabs.forEach(function (b) {
    b.addEventListener("click", function () {
      switchUserType(b.dataset.utype);
    });
  });

  /* ══════════ helper خطا ══════════ */
  function showFormError(el, msg) {
    if (el) {
      el.textContent = msg;
      el.style.display = "block";
    }
  }
  function hideFormError(el) {
    if (el) {
      el.textContent = "";
      el.style.display = "none";
    }
  }

  /* ══════════ ۹. ورود ══════════ */
  var paneLogin = document.getElementById("pane-login");
  if (paneLogin) {
    paneLogin.addEventListener("submit", async function (e) {
      e.preventDefault();
      var btn = document.getElementById("loginBtn");
      var err = document.getElementById("loginError");
      var mobile = faToEn(document.getElementById("loginMobile")?.value.trim());
      var password = document.getElementById("loginPassword")?.value;

      hideFormError(err);
      if (btn) {
        btn.disabled = true;
        btn.textContent = "در حال ورود...";
      }

      try {
        var fd = new FormData();
        fd.append("mobile", mobile);
        fd.append("password", password);
        var res = await fetch("/api/login.php", {
          method: "POST",
          body: fd,
          headers: Object.assign({ Accept: "application/json" }, csrfHeaders()),
        });
        var data = await res.json();

        if (!res.ok || !data.ok) {
          showFormError(err, data.error || "ورود ناموفق بود.");
          if (btn) {
            btn.disabled = false;
            btn.textContent = "ورود";
          }
          return;
        }
        if (data.role === "admin") {
          window.location.href = data.redirect || "/admin";
          return;
        }
        try {
          sessionStorage.setItem("__restore_scroll", String(window.scrollY));
        } catch (e) {}
        window.location.reload();
      } catch (errObj) {
        console.error("LOGIN_ERROR:", errObj);
        showFormError(err, "ارتباط با سرویس ورود برقرار نشد.");
        if (btn) {
          btn.disabled = false;
          btn.textContent = "ورود";
        }
      }
    });
  }

  /* ══════════ ۱۰. ثبت‌نام ══════════ */
  var registerForm = document.getElementById("registerSubmitForm");
  if (registerForm) {
    registerForm.addEventListener("submit", async function (e) {
      e.preventDefault();
      var src = document.getElementById("pane-register");
      var btn = document.getElementById("registerBtn");
      var err = document.getElementById("registerError");
      if (!src) return;

      function get(n) {
        var el = src.querySelector('[name="' + n + '"]');
        return el ? el.value.trim() : "";
      }

      var fullName = get("full_name");
      var mobile = faToEn(get("mobile"));
      var email = get("email");
      var password = src.querySelector('[name="password"]')?.value || "";
      var password2 = src.querySelector('[name="password2"]')?.value || "";
      var isLegal = currentUserType === "legal";
      var companyName = get("company_name");
      var nationalCode = isLegal ? "" : faToEn(get("national_code"));
      var economicCode = isLegal ? faToEn(get("economic_code")) : "";
      var gender = isLegal
        ? ""
        : src.querySelector('[name="gender"]:checked')?.value || "";

      hideFormError(err);

      if (!fullName)
        return showFormError(err, "نام و نام خانوادگی را وارد کنید.");
      if (!/^09\d{9}$/.test(mobile))
        return showFormError(err, "شماره موبایل صحیح نیست.");
      if (password.length < 4)
        return showFormError(err, "رمز عبور باید حداقل ٤ کراکتر باشد.");
      if (password !== password2)
        return showFormError(err, "تکرار رمز عبور صحیح نیست.");
      if (!isLegal && !/^\d{10}$/.test(nationalCode))
        return showFormError(err, "کد ملی باید ۱۰ رقم باشد.");
      if (isLegal) {
        if (!companyName) return showFormError(err, "نام شرکت را وارد کنید.");
        if (!/^\d{10,12}$/.test(economicCode))
          return showFormError(err, "شناسه اقتصادی باید ۱۰ تا ۱۲ رقم باشد.");
      }

      if (btn) {
        btn.disabled = true;
        btn.textContent = "در حال ایجاد حساب...";
      }

      try {
        var fd = new FormData();
        fd.append("full_name", fullName);
        fd.append("company_name", companyName);
        fd.append("mobile", mobile);
        fd.append("email", email);
        fd.append("password", password);
        fd.append("password2", password2);
        fd.append("user_type", currentUserType);
        fd.append("national_code", nationalCode);
        fd.append("gender", gender);
        fd.append("economic_code", economicCode);

        var res = await fetch("/api/register.php", {
          method: "POST",
          body: fd,
          headers: Object.assign({ Accept: "application/json" }, csrfHeaders()),
        });
        var data = await res.json();

        if (!res.ok || !data.ok) {
          showFormError(err, data.error || "ثبت‌نام انجام نشد.");
          if (btn) {
            btn.disabled = false;
            btn.textContent = "ایجاد حساب";
          }
          return;
        }
        window.closeAuthModal();
        /* ⭐ خرید مهمان‌پسند: بعد از ثبت‌نام، اگر کاربر وسط فرآیند خرید بود
           به همان مرحلهٔ آخر برمی‌گردد (سرور ورود خودکار هم انجام می‌دهد) */
        var r6RegisterDest = "/?registered=1";
        try {
          if (sessionStorage.getItem("rabetiCheckout") === "1") {
            r6RegisterDest = "/checkout-review";
          }
        } catch (e) {}
        window.location.href = r6RegisterDest;
      } catch (errObj) {
        console.error("REGISTER_ERROR:", errObj);
        showFormError(err, "ارتباط با سرویس ثبت‌نام برقرار نشد.");
        if (btn) {
          btn.disabled = false;
          btn.textContent = "ایجاد حساب";
        }
      }
    });
  }

  /* ══════════ ۱۱. فراموشی رمز ══════════ */
  var forgotChannel = "sms";
  var forgotTargetValue = "";

  function showForgotStep(n) {
    [
      "forgot-step-1",
      "forgot-step-2",
      "forgot-step-3",
      "forgot-step-4",
    ].forEach(function (id, i) {
      var el = document.getElementById(id);
      if (el) el.style.display = i + 1 === n ? "block" : "none";
    });
  }

  var forgotLink = document.getElementById("forgotLink");
  if (forgotLink) {
    forgotLink.addEventListener("click", function () {
      document.getElementById("view-login")?.classList.remove("active");
      document.getElementById("view-register")?.classList.remove("active");
      document.getElementById("view-forgot")?.classList.add("active");
      var tabsBox = document.getElementById("authTabsBox");
      if (tabsBox) tabsBox.style.display = "none";

      forgotChannel = "sms";
      var label = document.getElementById("forgotInputLabel");
      if (label) label.textContent = "شماره موبایل";
      var targetInput = document.getElementById("forgotTarget");
      if (targetInput) {
        targetInput.value = "";
        targetInput.placeholder = "09121234567";
      }
      showForgotStep(2);
      if (authOverlay) authOverlay.classList.add("show");
      document.body.style.overflow = "hidden";
    });
  }

  document
    .getElementById("forgotBack1")
    ?.addEventListener("click", function () {
      showForgotStep(1);
    });
  document
    .getElementById("forgotBack2")
    ?.addEventListener("click", function () {
      showForgotStep(2);
    });

  document
    .getElementById("forgotDoneBtn")
    ?.addEventListener("click", function () {
      window.closeAuthModal();
      setTimeout(function () {
        document.getElementById("view-forgot")?.classList.remove("active");
        document.getElementById("view-login")?.classList.add("active");
        var tabsBox = document.getElementById("authTabsBox");
        if (tabsBox) tabsBox.style.display = "";
      }, 300);
    });

  document
    .getElementById("forgotSendBtn")
    ?.addEventListener("click", async function () {
      var btn = document.getElementById("forgotSendBtn");
      var target = document.getElementById("forgotTarget")?.value.trim() || "";
      if (forgotChannel === "sms" && !/^09\d{9}$/.test(faToEn(target))) {
        window.showToast("⚠️ شماره موبایل صحیح نیست");
        return;
      }
      forgotTargetValue = target;
      if (btn) {
        btn.disabled = true;
        btn.textContent = "در حال ارسال...";
      }
      try {
        var fd = new FormData();
        fd.append("step", "request");
        fd.append("channel", forgotChannel);
        fd.append("target", target);
        var res = await fetch("/api/forgot-password", {
          method: "POST",
          body: fd,
          headers: csrfHeaders(),
        });
        var data = await res.json();
        if (!data.ok) {
          window.showToast("⚠️ " + data.error);
          if (btn) {
            btn.disabled = false;
            btn.textContent = "ارسال کد بازیابی";
          }
          return;
        }
        window.showToast("✅ " + (data.msg || "کد ارسال شد"));
        showForgotStep(3);
        if (btn) {
          btn.disabled = false;
          btn.textContent = "ارسال کد بازیابی";
        }
      } catch (err) {
        console.error("FORGOT_ERROR:", err);
        window.showToast("خطای اتصال");
        if (btn) {
          btn.disabled = false;
          btn.textContent = "ارسال کد بازیابی";
        }
      }
    });

  document
    .getElementById("forgotResetBtn")
    ?.addEventListener("click", async function () {
      var btn = document.getElementById("forgotResetBtn");
      var code = document.getElementById("forgotCode")?.value.trim() || "";
      var p1 = document.getElementById("forgotNewPass")?.value || "";
      var p2 = document.getElementById("forgotNewPass2")?.value || "";

      if (code.length !== 6)
        return window.showToast("⚠️ کد ۶ رقمی را وارد کنید");
      if (p1.length < 4) return window.showToast("⚠️ رمز جدید حداقل ٤ کاراکتر");
      if (p1 !== p2) return window.showToast("⚠️ تکرار رمز صحیح نیست");

      if (btn) {
        btn.disabled = true;
        btn.textContent = "در حال ثبت...";
      }
      try {
        var fd = new FormData();
        fd.append("step", "reset");
        fd.append("channel", forgotChannel);
        fd.append("target", forgotTargetValue);
        fd.append("code", code);
        fd.append("new_password", p1);
        fd.append("new_password2", p2);
        var res = await fetch("/api/forgot-password", {
          method: "POST",
          body: fd,
          headers: csrfHeaders(),
        });
        var data = await res.json();
        if (!data.ok) {
          window.showToast("⚠️ " + data.error);
          if (btn) {
            btn.disabled = false;
            btn.textContent = "تغییر رمز عبور";
          }
          return;
        }
        showForgotStep(4);
      } catch (err) {
        console.error("RESET_ERROR:", err);
        window.showToast("خطای اتصال");
        if (btn) {
          btn.disabled = false;
          btn.textContent = "تغییر رمز عبور";
        }
      }
    });

  /* ══════════ ۱۲. شبکه‌های اجتماعی ══════════ */
  (function () {
    var toggle = document.getElementById("socialToggle");
    var popup = document.getElementById("socialPopup");
    if (!toggle || !popup) return;

    if ("inert" in popup) popup.inert = true;

    function setOpen(open) {
      popup.classList.toggle("is-open", open);
      if ("inert" in popup) popup.inert = !open;
      toggle.classList.toggle("is-active", open);
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      popup.setAttribute("aria-hidden", open ? "false" : "true");
    }

    toggle.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      setOpen(!popup.classList.contains("is-open"));
    });

    document.addEventListener("click", function (e) {
      if (
        !popup.contains(e.target) &&
        e.target !== toggle &&
        !toggle.contains(e.target)
      ) {
        setOpen(false);
      }
    });

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") setOpen(false);
    });
  })();

  /* ══════════ ۱۳. Fallback تصویر ══════════ */
  function r6RecoverImg(el, failedSrc) {
    if (!(el instanceof HTMLImageElement)) return;
    failedSrc = failedSrc || el.currentSrc || el.src || "";

    if (failedSrc.indexOf("/thumb.php?") !== -1) {
      var m = failedSrc.match(/[?&]f=([^&]+)/);
      if (m) {
        var pic = el.closest ? el.closest("picture") : null;
        if (pic)
          pic.querySelectorAll("source").forEach(function (s) {
            s.remove();
          });
        el.removeAttribute("srcset");
        el.removeAttribute("loading");
        el.src = "/" + decodeURIComponent(m[1]);
        return;
      }
    }

    if (!el.dataset.imgBust && failedSrc && failedSrc.indexOf("data:") !== 0) {
      el.dataset.imgBust = "1";
      el.removeAttribute("srcset");
      el.removeAttribute("loading");
      el.src =
        failedSrc +
        (failedSrc.indexOf("?") === -1 ? "?" : "&") +
        "r=" +
        Date.now();
      return;
    }

    if (el.dataset.imgFallback) return;
    el.dataset.imgFallback = "1";
    el.removeAttribute("srcset");
    el.removeAttribute("loading");
    el.src =
      "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 300 300'%3E%3Crect width='300' height='300' fill='%23faf6ee'/%3E%3Ctext x='150' y='176' font-size='96' text-anchor='middle'%3E%F0%9F%A7%A6%3C/text%3E%3C/svg%3E";
  }

  document.addEventListener(
    "error",
    function (e) {
      if (e.target instanceof HTMLImageElement) r6RecoverImg(e.target);
    },
    true,
  );

  function r6SweepBrokenImages() {
    document.querySelectorAll("img").forEach(function (el) {
      if (el.dataset.imgFallback) return;
      if (el.complete && el.naturalWidth === 0 && (el.currentSrc || el.src)) {
        r6RecoverImg(el, el.currentSrc || el.src);
      }
    });
  }
  /* ⭐ Performance: اسکن اولیه بلافاصله در زمان eval اسکریپت اجرا نمی‌شود؛
     خواندن naturalWidth روی همه‌ی تصاویر در آن لحظه forced-reflow تولید می‌کرد
     (سهم بزرگ Style & Layout در Trace). به idle موکول شد + یک‌بار بعد از load. */
  var sweepScheduled = false;
  function sweepWhenIdle() {
    if (sweepScheduled) return;
    sweepScheduled = true;
    var run = function () {
      sweepScheduled = false;
      r6SweepBrokenImages();
    };
    if (typeof requestIdleCallback === "function") {
      requestIdleCallback(run, { timeout: 2000 });
    } else {
      setTimeout(run, 300);
    }
  }
  sweepWhenIdle();
  window.addEventListener("load", sweepWhenIdle);

  /* ══════════ ۱۴. Export برای استفاده در جاهای دیگر ══════════ */
  window.rabetiFaToEn = faToEn;
  window.rabetiEnToFa = enToFa;
  window.rabetiStepperRead = stepperRead;
  window.rabetiStepperWrite = stepperWrite;
})();
