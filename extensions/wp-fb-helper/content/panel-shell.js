(function () {
  const SIZES = ["s", "m", "l", "xl"];
  const KEYS = [
    "popupSize",
    "panelFabMode",
    "panelFabEdge",
    "panelFabLook",
    "panelTopPct",
    "panelPosSetup",
    "panelEdgeHideDelayMs",
    "panelModalHideDelayMs",
    "panelOpenOnHover",
    "panelBodyHeight",
  ];

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function clampTopPct(n) {
    return Math.max(5, Math.min(95, Math.round(Number(n) || 50)));
  }

  const dock = {
    size: "m",
    fabMode: "fixed",
    fabEdge: "right",
    fabLook: "bookmark",
    topPct: 50,
    posSetup: false,
    edgeHideDelayMs: 1000,
    modalHideDelayMs: 0,
    openOnHover: false,
    bodyHeight: 0,
    modalOpen: false,
    fabSide: "right",
    fabNearEdge: false,
    fabEdgeSticky: false,
    fabHovering: false,
    hideTimer: null,
    edgeTimer: null,
  };

  function applySettings(s) {
    if (!s || typeof s !== "object") return;
    if (SIZES.indexOf(s.popupSize) >= 0) dock.size = s.popupSize;
    if (s.panelFabMode === "fixed" || s.panelFabMode === "edge" || s.panelFabMode === "hidden") {
      dock.fabMode = s.panelFabMode;
    }
    if (s.panelFabEdge === "left" || s.panelFabEdge === "right" || s.panelFabEdge === "both") {
      dock.fabEdge = s.panelFabEdge;
    }
    if (s.panelFabLook === "label" || s.panelFabLook === "label2" || s.panelFabLook === "bookmark") {
      dock.fabLook = s.panelFabLook;
    }
    if (s.panelTopPct != null) dock.topPct = clampTopPct(s.panelTopPct);
    dock.posSetup = !!s.panelPosSetup;
    if (s.panelEdgeHideDelayMs != null) dock.edgeHideDelayMs = Math.max(0, Number(s.panelEdgeHideDelayMs) || 0);
    if (s.panelModalHideDelayMs != null) dock.modalHideDelayMs = Math.max(0, Number(s.panelModalHideDelayMs) || 0);
    dock.openOnHover = !!s.panelOpenOnHover;
    if (s.panelBodyHeight != null) {
      const n = Number(s.panelBodyHeight);
      dock.bodyHeight = Number.isFinite(n) ? Math.max(0, Math.min(1200, n)) : 0;
    }
    if (dock.fabMode === "edge" && !dock.posSetup && !dock.modalOpen) {
      armEdgeHide();
    } else {
      clearEdgeTimer();
    }
  }

  function lookClass() {
    if (dock.fabLook === "label") return " fh-look-label";
    if (dock.fabLook === "label2") return " fh-look-label2";
    return " fh-look-bookmark";
  }

  function iconSide() {
    if (dock.fabEdge === "left") return "left";
    if (dock.fabEdge === "both") return dock.fabSide;
    return "right";
  }

  function showIcon() {
    if (dock.posSetup) return true;
    if (dock.modalOpen) return false;
    if (dock.fabMode === "fixed") return true;
    if (dock.fabMode === "hidden") return false;
    return !!(dock.fabNearEdge || dock.fabEdgeSticky || dock.fabHovering);
  }

  function place(node, side, asIcon) {
    if (asIcon) {
      node.style.top = clampTopPct(dock.topPct) + "%";
      node.style.transform = "translateY(-50%)";
    } else {
      node.style.top = "8px";
      node.style.transform = "none";
    }
    if (side === "left") {
      node.style.left = asIcon ? "0" : "8px";
      node.style.right = "auto";
    } else {
      node.style.right = asIcon ? "0" : "8px";
      node.style.left = "auto";
    }
  }

  function fitModalInView() {
    if (!dock.modalOpen || modal.style.display === "none") return;
    const vh = Math.max(1, window.innerHeight);
    const vw = Math.max(1, window.innerWidth);
    const pad = 8;
    const headH = Math.max(40, head.offsetHeight || 40);
    const maxBody = Math.max(120, vh - headH - 24);
    if (dock.bodyHeight > 80) {
      const h = Math.min(dock.bodyHeight, maxBody);
      body.style.maxHeight = h + "px";
      body.style.height = h + "px";
    } else {
      body.style.maxHeight = Math.min(vh * 0.7, maxBody) + "px";
      body.style.height = "";
    }
    modal.style.transform = "none";
    const r = modal.getBoundingClientRect();
    let top = userMoved ? r.top : pad;
    let left = r.left;
    if (top < pad) top = pad;
    if (top + r.height > vh - pad) top = Math.max(pad, vh - r.height - pad);
    if (left < pad) left = pad;
    if (left + r.width > vw - pad) left = Math.max(pad, vw - r.width - pad);
    modal.style.top = Math.round(top) + "px";
    modal.style.left = Math.round(left) + "px";
    modal.style.right = "auto";
  }

  function sync() {
    const side = iconSide();
    const look = lookClass();
    fab.className =
      "fh-root fh-fab-only size-s" +
      look +
      " fh-edge-peek fh-edge-" +
      side +
      (dock.posSetup ? " fh-pos-setup" : "") +
      (dock.fabMode === "fixed" ? " fh-fab-fixed" : "") +
      (showIcon() ? "" : " fh-hidden-dock");
    fab.style.display = showIcon() ? "block" : "none";
    place(fab, side, true);

    modal.className = "fh-root size-" + dock.size + look + (dock.posSetup ? " fh-pos-setup" : "");
    modal.style.display = dock.modalOpen ? "flex" : "none";
    modal.style.flexDirection = "column";
    if (dock.modalOpen) {
      if (!userMoved) place(modal, side, false);
      fitModalInView();
    }
    wrap.className = "fh-edge-wrap" + (dock.posSetup ? " fh-pos-setup" : "");
    SIZES.forEach((s) => {
      const btn = sizeBtns[s];
      if (btn) btn.classList.toggle("active", dock.size === s);
    });
  }

  function openModal() {
    if (dock.posSetup) return;
    dock.modalOpen = true;
    dragged = false;
    userMoved = false;
    sync();
  }

  function hideToSide(dir) {
    dock.fabSide = dir === "left" ? "left" : "right";
    dock.modalOpen = false;
    dock.fabEdgeSticky = true;
    sync();
    armEdgeHide();
  }

  function clearHide() {
    if (dock.hideTimer) {
      clearTimeout(dock.hideTimer);
      dock.hideTimer = null;
    }
  }

  function clearEdgeTimer() {
    if (dock.edgeTimer) {
      clearTimeout(dock.edgeTimer);
      dock.edgeTimer = null;
    }
  }

  function armEdgeHide() {
    if (dock.posSetup || dock.fabMode !== "edge" || dock.modalOpen) {
      clearEdgeTimer();
      return;
    }
    if (dock.fabHovering || dock.fabNearEdge) {
      clearEdgeTimer();
      return;
    }
    if (dock.edgeTimer) return;
    const delay = Math.max(0, Number(dock.edgeHideDelayMs) || 0);
    dock.edgeTimer = setTimeout(() => {
      dock.fabEdgeSticky = false;
      dock.edgeTimer = null;
      sync();
    }, delay);
  }

  const fab = el("div", "fh-root fh-fab-only");
  fab.style.display = "none";
  const wrap = el("div", "fh-edge-wrap");
  const up = el("button", "fh-fab-arrow fh-fab-up", "▲");
  up.type = "button";
  const tab = el("button", "fh-edge-tab", "WP");
  tab.type = "button";
  const down = el("button", "fh-fab-arrow fh-fab-down", "▼");
  down.type = "button";
  wrap.appendChild(up);
  wrap.appendChild(tab);
  wrap.appendChild(down);
  fab.appendChild(wrap);

  const modal = el("div", "fh-root size-m");
  modal.id = "wp-fb-helper-root";
  modal.style.display = "none";
  const head = el("div", "fh-head");
  const title = el("div", "fh-title", "WP FB Helper");
  const actions = el("div", "fh-head-actions");
  const sizeBox = el("div", "fh-size-btns");
  const sizeBtns = {};
  SIZES.forEach((s) => {
    const b = el("button", "fh-size-btn" + (s === "m" ? " active" : ""), s.toUpperCase());
    b.type = "button";
    b.addEventListener("click", (e) => {
      e.stopPropagation();
      dock.size = s;
      chrome.storage.local.set({ popupSize: s });
      sync();
    });
    sizeBtns[s] = b;
    sizeBox.appendChild(b);
  });
  const reconnectBtn = el("button", "fh-icon-btn", "↻");
  reconnectBtn.type = "button";
  reconnectBtn.title = "Reload";
  const hideBtn = el("button", "fh-icon-btn", "→");
  hideBtn.type = "button";
  hideBtn.title = "Hide to side";
  actions.appendChild(sizeBox);
  actions.appendChild(reconnectBtn);
  actions.appendChild(hideBtn);
  head.appendChild(title);
  head.appendChild(actions);
  const body = el("div", "fh-body");
  body.id = "wp-fb-helper-body";
  const resize = el("div", "fh-resize-y");
  modal.appendChild(head);
  modal.appendChild(body);
  modal.appendChild(resize);
  document.documentElement.appendChild(fab);
  document.documentElement.appendChild(modal);

  let dragged = false;
  let userMoved = false;
  let ox = 0;
  let oy = 0;
  head.addEventListener("mousedown", (e) => {
    if (e.target.closest("button")) return;
    if (dock.posSetup) {
      e.preventDefault();
      const onMove = (ev) => {
        dock.topPct = clampTopPct((ev.clientY / Math.max(1, window.innerHeight)) * 100);
        chrome.storage.local.set({ panelTopPct: dock.topPct });
        sync();
      };
      const onUp = () => {
        window.removeEventListener("mousemove", onMove);
        window.removeEventListener("mouseup", onUp);
      };
      window.addEventListener("mousemove", onMove);
      window.addEventListener("mouseup", onUp);
      return;
    }
    dragged = true;
    userMoved = true;
    const r = modal.getBoundingClientRect();
    ox = e.clientX - r.left;
    oy = e.clientY - r.top;
    modal.style.transform = "none";
    e.preventDefault();
  });
  window.addEventListener("mousemove", (e) => {
    if (!dragged) return;
    const vh = Math.max(1, window.innerHeight);
    const vw = Math.max(1, window.innerWidth);
    const headH = Math.max(40, head.offsetHeight || 40);
    modal.style.left = Math.max(8, Math.min(vw - 80, e.clientX - ox)) + "px";
    modal.style.top = Math.max(8, Math.min(vh - headH - 8, e.clientY - oy)) + "px";
    modal.style.right = "auto";
    modal.style.transform = "none";
  });
  window.addEventListener("mouseup", () => {
    dragged = false;
  });

  hideBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    const r = modal.getBoundingClientRect();
    const mid = (r.left + r.right) / 2;
    hideToSide(mid < window.innerWidth / 2 ? "left" : "right");
    hideBtn.textContent = mid < window.innerWidth / 2 ? "←" : "→";
  });

  tab.addEventListener("click", (e) => {
    if (dock.posSetup) {
      e.preventDefault();
      return;
    }
    openModal();
  });
  up.addEventListener("click", (e) => {
    e.stopPropagation();
    dock.topPct = clampTopPct(dock.topPct - 3);
    chrome.storage.local.set({ panelTopPct: dock.topPct });
    sync();
  });
  down.addEventListener("click", (e) => {
    e.stopPropagation();
    dock.topPct = clampTopPct(dock.topPct + 3);
    chrome.storage.local.set({ panelTopPct: dock.topPct });
    sync();
  });
  tab.addEventListener("mousedown", (e) => {
    if (!dock.posSetup) return;
    e.preventDefault();
    const onMove = (ev) => {
      dock.topPct = clampTopPct((ev.clientY / Math.max(1, window.innerHeight)) * 100);
      chrome.storage.local.set({ panelTopPct: dock.topPct });
      sync();
    };
    const onUp = () => {
      window.removeEventListener("mousemove", onMove);
      window.removeEventListener("mouseup", onUp);
    };
    window.addEventListener("mousemove", onMove);
    window.addEventListener("mouseup", onUp);
  });

  fab.addEventListener("mouseenter", () => {
    dock.fabHovering = true;
    dock.fabEdgeSticky = true;
    clearEdgeTimer();
    if (!dock.posSetup && dock.openOnHover) openModal();
    sync();
  });
  fab.addEventListener("mouseleave", () => {
    dock.fabHovering = false;
    armEdgeHide();
    sync();
  });

  modal.addEventListener("mouseenter", clearHide);
  modal.addEventListener("mouseleave", () => {
    if (dock.posSetup || !dock.modalHideDelayMs) return;
    dock.hideTimer = setTimeout(() => {
      hideToSide(iconSide());
    }, dock.modalHideDelayMs);
  });

  window.addEventListener(
    "mousemove",
    (e) => {
      const edge = 18;
      const left = e.clientX <= edge;
      const right = e.clientX >= window.innerWidth - edge;
      const wasNear = dock.fabNearEdge;
      if (dock.fabEdge === "left") dock.fabNearEdge = left;
      else if (dock.fabEdge === "both") {
        dock.fabNearEdge = left || right;
        if (left) dock.fabSide = "left";
        if (right) dock.fabSide = "right";
      } else dock.fabNearEdge = right;
      if (dock.fabMode !== "edge" || dock.modalOpen) return;
      if (dock.fabNearEdge) {
        dock.fabEdgeSticky = true;
        clearEdgeTimer();
        if (!wasNear) sync();
      } else if (wasNear) {
        armEdgeHide();
        sync();
      }
    },
    { passive: true }
  );

  let resizing = false;
  resize.addEventListener("mousedown", (e) => {
    e.preventDefault();
    e.stopPropagation();
    resizing = true;
    const startY = e.clientY;
    const startH = body.getBoundingClientRect().height;
    const onMove = (ev) => {
      const h = Math.max(120, Math.min(1200, startH + (ev.clientY - startY)));
      dock.bodyHeight = h;
      chrome.storage.local.set({ panelBodyHeight: h });
      sync();
    };
    const onUp = () => {
      resizing = false;
      window.removeEventListener("mousemove", onMove);
      window.removeEventListener("mouseup", onUp);
    };
    window.addEventListener("mousemove", onMove);
    window.addEventListener("mouseup", onUp);
  });

  window.addEventListener("resize", () => {
    if (dock.modalOpen) fitModalInView();
  });

  chrome.storage.local.get(KEYS, (s) => {
    applySettings(s || {});
    if (dock.fabMode === "hidden") dock.modalOpen = true;
    else dock.modalOpen = dock.fabMode === "fixed" ? false : false;
    sync();
  });
  chrome.storage.onChanged.addListener((changes, area) => {
    if (area !== "local") return;
    const next = {};
    KEYS.forEach((k) => {
      if (changes[k]) next[k] = changes[k].newValue;
    });
    if (Object.keys(next).length) {
      applySettings(Object.assign({}, dock, next, {
        popupSize: next.popupSize != null ? next.popupSize : dock.size,
        panelFabMode: next.panelFabMode != null ? next.panelFabMode : dock.fabMode,
        panelFabEdge: next.panelFabEdge != null ? next.panelFabEdge : dock.fabEdge,
        panelFabLook: next.panelFabLook != null ? next.panelFabLook : dock.fabLook,
        panelTopPct: next.panelTopPct != null ? next.panelTopPct : dock.topPct,
        panelPosSetup: next.panelPosSetup != null ? next.panelPosSetup : dock.posSetup,
        panelEdgeHideDelayMs: next.panelEdgeHideDelayMs != null ? next.panelEdgeHideDelayMs : dock.edgeHideDelayMs,
        panelModalHideDelayMs: next.panelModalHideDelayMs != null ? next.panelModalHideDelayMs : dock.modalHideDelayMs,
        panelOpenOnHover: next.panelOpenOnHover != null ? next.panelOpenOnHover : dock.openOnHover,
        panelBodyHeight: next.panelBodyHeight != null ? next.panelBodyHeight : dock.bodyHeight,
      }));
      sync();
    }
  });

  window.__WFH_SHELL__ = {
    body: body,
    root: modal,
    title: title,
    reconnectBtn: reconnectBtn,
    open: openModal,
    hide: hideToSide,
  };
})();
