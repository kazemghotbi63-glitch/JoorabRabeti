(function () {
  "use strict";

  const sidebar = document.getElementById("adminSidebar");
  const overlay = document.getElementById("adminOverlay");
  const burger = document.getElementById("adminBurger");

  if (!sidebar || !overlay || !burger) {
    return;
  }

  function openAdminSidebar() {
    sidebar.classList.add("open");
    overlay.classList.add("show");

    document.body.classList.add("admin-menu-open");

    burger.setAttribute("aria-expanded", "true");
  }

  function closeAdminSidebar() {
    sidebar.classList.remove("open");
    overlay.classList.remove("show");

    document.body.classList.remove("admin-menu-open");

    burger.setAttribute("aria-expanded", "false");
  }

  function toggleAdminSidebar() {
    if (sidebar.classList.contains("open")) {
      closeAdminSidebar();
    } else {
      openAdminSidebar();
    }
  }

  burger.addEventListener("click", toggleAdminSidebar);

  overlay.addEventListener("click", closeAdminSidebar);

  document.querySelectorAll(".admin-nav-link").forEach(function (link) {
    link.addEventListener("click", function () {
      closeAdminSidebar();
    });
  });

  document
    .querySelectorAll(".admin-sidebar-footer-link")
    .forEach(function (link) {
      link.addEventListener("click", function () {
        closeAdminSidebar();
      });
    });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      closeAdminSidebar();
    }
  });

  window.addEventListener("resize", function () {
    if (window.innerWidth > 900) {
      closeAdminSidebar();
    }
  });
})();
