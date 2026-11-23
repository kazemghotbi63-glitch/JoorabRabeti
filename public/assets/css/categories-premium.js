/* ═══════════════════════════════════════════════════════
   Categories Premium — Tab Switch  (optimized: no forced reflow)
   ⭐ [Lighthouse fix] خواندن چیدمان (getBoundingClientRect) و تزریق
      <style> به requestAnimationFrame موکوف شده تا در تسکِ هم‌زمانِ
      DOMContentLoaded блок‌کننده نباشد (رفع forced-reflow / کاهش TBT).
   ═══════════════════════════════════════════════════════ */

(function () {
  "use strict";

  function initCategoriesPremium() {
    const root = document.querySelector(".r-cats-premium");
    if (!root) return;

    const parents = root.querySelectorAll(".rp-parent");
    const panels = root.querySelectorAll(".rp-panel");
    const panelWrap = root.querySelector(".rp-panel-wrap");

    if (!parents.length || !panels.length) return;

    /* یک عنصر <style> ثابت ساخته و دوباره استفاده می‌کنیم (به‌جای ساخت/حذف
       مکرر که هر بار style recalc کل سند را تحریک می‌کرد). */
    let pointerStyle = document.getElementById("rp-pointer-style");
    if (!pointerStyle) {
      pointerStyle = document.createElement("style");
      pointerStyle.id = "rp-pointer-style";
      document.head.appendChild(pointerStyle);
    }

    /* نمایش‌دهنده‌ی اشاره‌گر را در فریم بعدی به‌روز می‌کند (خارج از تسک جاری).
       خواندن getBoundingClientRect اینجا دیگر روی مسیر بحرانی اجرا نمی‌شود. */
    function updatePointer(parentId) {
      if (!panelWrap) return;
      requestAnimationFrame(function () {
        const activeCard = root.querySelector(
          '.rp-parent[data-parent-id="' + parentId + '"]',
        );
        if (!activeCard) return;

        const cardRect = activeCard.getBoundingClientRect();
        const panelRect = panelWrap.getBoundingClientRect();

        const cardCenterFromRight =
          panelRect.right - (cardRect.left + cardRect.width / 2);

        pointerStyle.textContent =
          ".rp-panel-wrap::before{right:" +
          (cardCenterFromRight - 10) +
          "px!important;left:auto!important}";
      });
    }

    function activate(parentId, animate) {
      /* کارت‌ها */
      parents.forEach(function (p) {
        p.classList.toggle("is-active", p.dataset.parentId === parentId);
      });

      /* پنل‌ها */
      panels.forEach(function (pan) {
        pan.classList.toggle("is-active", pan.dataset.parentId === parentId);
      });

      /* موقعیت اشاره‌گر — به rAF موکوف (بدون forced reflow) */
      updatePointer(parentId);

      /* ری‌استارت انیمیشن بدون خواندن offsetWidth (که forced reflow می‌کرد):
         کلاس را در یک فریم حذف و در فریم بعد برمی‌گردانیم. */
      if (animate && panelWrap) {
        panelWrap.classList.add("rp-noanim");
        requestAnimationFrame(function () {
          panelWrap.classList.remove("rp-noanim");
        });
      }
    }

    parents.forEach(function (p) {
      p.setAttribute("tabindex", "0");
      p.addEventListener("click", function (e) {
        e.preventDefault();
        activate(p.dataset.parentId, true);
      });
      p.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          activate(p.dataset.parentId, true);
        }
      });
    });

    /* اولین کارت به‌صورت پیش‌فرض فعال */
    const firstActive =
      root.querySelector(".rp-parent.is-active") || parents[0];

    if (firstActive) {
      activate(firstActive.dataset.parentId, false);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initCategoriesPremium);
  } else {
    initCategoriesPremium();
  }
})();
