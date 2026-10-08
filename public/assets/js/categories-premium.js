/* ═══════════════════════════════════════════════════════
   Categories Premium — Tab Switch
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

    function activate(parentId, animate) {
      /* کارت‌ها */
      parents.forEach(function (p) {
        p.classList.toggle("is-active", p.dataset.parentId === parentId);
      });

      /* پنل‌ها */
      panels.forEach(function (pan) {
        pan.classList.toggle("is-active", pan.dataset.parentId === parentId);
      });

      /* جابه‌جایی گوشه‌ی اشاره‌گر */
      /* جابه‌جایی گوشه‌ی اشاره‌گر — بر اساس موقعیت واقعی کارت فعال (RTL-safe) */
      if (panelWrap) {
        const activeCard = root.querySelector(
          '.rp-parent[data-parent-id="' + parentId + '"]',
        );

        if (activeCard) {
          const cardRect = activeCard.getBoundingClientRect();
          const panelRect = panelWrap.getBoundingClientRect();

          /* فاصله مرکز کارت از لبه‌ی راست پنل */
          const cardCenterFromRight =
            panelRect.right - (cardRect.left + cardRect.width / 2);

          const style = document.createElement("style");
          style.id = "rp-pointer-style";
          const old = document.getElementById("rp-pointer-style");
          if (old) old.remove();
          style.textContent = `
          .rp-panel-wrap::before {
          right: ${cardCenterFromRight - 10}px !important;
          left: auto !important;
          }
          `;

          document.head.appendChild(style);
        }
      }

      if (animate && panelWrap) {
        panelWrap.style.animation = "none";
        void panelWrap.offsetWidth;
        panelWrap.style.animation = "";
      }
    }

    parents.forEach(function (p) {
      p.addEventListener("click", function (e) {
        e.preventDefault();
        activate(p.dataset.parentId, true);
      });

      /* پشتیبانی از کیبورد */
      p.setAttribute("tabindex", "0");
      p.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          activate(p.dataset.parentId, true);
        }
      });
    });

    /* اولین کارت به صورت پیش‌فرض فعال */
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
