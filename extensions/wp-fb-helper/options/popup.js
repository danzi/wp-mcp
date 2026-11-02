function send(type, payload) {
  return new Promise((resolve, reject) => {
    chrome.runtime.sendMessage(Object.assign({ type: type }, payload || {}), (res) => {
      if (chrome.runtime.lastError) {
        reject(new Error(chrome.runtime.lastError.message));
        return;
      }
      if (!res || !res.ok) {
        reject(new Error((res && res.error) || "failed"));
        return;
      }
      resolve(res.result);
    });
  });
}

function $(id) {
  return document.getElementById(id);
}

function setStatus(text, ok) {
  const el = $("status");
  el.textContent = text;
  el.style.color = ok === false ? "#ef9a9a" : ok === true ? "#a5d6a7" : "#888";
}

function persist(patch) {
  return chrome.storage.local.set(patch);
}

function applyPopupSize(size) {
  const v = ["s", "m", "l", "xl"].indexOf(size) >= 0 ? size : "m";
  document.body.className = "popup size-" + v;
  document.querySelectorAll("#popupSizes .size-btn").forEach((b) => {
    b.classList.toggle("active", b.getAttribute("data-size") === v);
  });
}

function fabMode() {
  const el = document.querySelector('input[name="fabMode"]:checked');
  return el ? el.value : "fixed";
}

function syncEdgeWrap() {
  $("fabEdgeWrap").style.display = fabMode() === "edge" ? "block" : "none";
}

async function load() {
  const s = await send("GET_SETTINGS");
  $("wpBase").value = s.wpBase || "";
  $("wpUser").value = s.wpUser || "";
  $("wpAppPassword").placeholder = s.hasPassword ? "saved — type to replace" : "xxxx xxxx xxxx xxxx xxxx xxxx";
  $("fbListenEnabled").checked = s.fbListenEnabled !== false;
  $("fbListenUrlPart").value = s.fbListenUrlPart || "graph.facebook.com";

  const local = await chrome.storage.local.get([
    "popupSize",
    "panelFabMode",
    "panelFabEdge",
    "panelFabLook",
    "panelTopPct",
    "panelPosSetup",
    "panelEdgeHideDelayMs",
    "panelModalHideDelayMs",
    "panelOpenOnHover",
    "panelRefreshSec",
  ]);
  applyPopupSize(local.popupSize || "m");
  const mode = local.panelFabMode || "fixed";
  document.querySelectorAll('input[name="fabMode"]').forEach((r) => {
    r.checked = r.value === mode;
  });
  $("panelFabEdge").value = local.panelFabEdge || "right";
  $("panelFabLook").value = local.panelFabLook || "bookmark";
  $("panelPosSetup").checked = !!local.panelPosSetup;
  $("panelEdgeHideDelayMs").value = local.panelEdgeHideDelayMs != null ? local.panelEdgeHideDelayMs : 1000;
  $("panelModalHideDelayMs").value = local.panelModalHideDelayMs != null ? local.panelModalHideDelayMs : 0;
  $("panelOpenOnHover").checked = !!local.panelOpenOnHover;
  $("panelTopPct").value = local.panelTopPct != null ? local.panelTopPct : 50;
  $("panelRefreshSec").value = local.panelRefreshSec != null ? local.panelRefreshSec : 0;
  syncEdgeWrap();
}

const sizeHost = $("popupSizes");
["s", "m", "l", "xl"].forEach((s) => {
  const b = document.createElement("button");
  b.type = "button";
  b.className = "size-btn";
  b.setAttribute("data-size", s);
  b.textContent = s.toUpperCase();
  b.addEventListener("click", () => {
    persist({ popupSize: s });
    applyPopupSize(s);
  });
  sizeHost.appendChild(b);
});

document.querySelectorAll(".tabs button").forEach((btn) => {
  btn.addEventListener("click", () => {
    const id = btn.getAttribute("data-tab");
    document.querySelectorAll(".tabs button").forEach((b) => b.classList.toggle("active", b === btn));
    document.querySelectorAll(".tab").forEach((t) => t.classList.toggle("active", t.id === "tab-" + id));
  });
});

document.querySelectorAll('input[name="fabMode"]').forEach((r) => {
  r.addEventListener("change", () => {
    const mode = fabMode();
    const patch = { panelFabMode: mode };
    if (mode === "edge") {
      patch.panelPosSetup = false;
      $("panelPosSetup").checked = false;
    }
    persist(patch);
    syncEdgeWrap();
  });
});
$("panelFabEdge").addEventListener("change", () => persist({ panelFabEdge: $("panelFabEdge").value }));
$("panelFabLook").addEventListener("change", () => persist({ panelFabLook: $("panelFabLook").value }));
$("panelPosSetup").addEventListener("change", () => persist({ panelPosSetup: $("panelPosSetup").checked }));
$("panelOpenOnHover").addEventListener("change", () => persist({ panelOpenOnHover: $("panelOpenOnHover").checked }));
$("panelEdgeHideDelayMs").addEventListener("change", () =>
  persist({ panelEdgeHideDelayMs: Number($("panelEdgeHideDelayMs").value) || 0 })
);
$("panelEdgeHideDelayMs").addEventListener("input", () =>
  persist({ panelEdgeHideDelayMs: Number($("panelEdgeHideDelayMs").value) || 0 })
);
$("panelModalHideDelayMs").addEventListener("change", () =>
  persist({ panelModalHideDelayMs: Number($("panelModalHideDelayMs").value) || 0 })
);
$("panelTopPct").addEventListener("change", () => persist({ panelTopPct: Number($("panelTopPct").value) || 50 }));
function persistRefreshSec() {
  let n = Math.round(Number($("panelRefreshSec").value) || 0);
  if (n < 0) n = 0;
  if (n > 300) n = 300;
  if (n > 0 && n < 1) n = 1;
  $("panelRefreshSec").value = n;
  persist({ panelRefreshSec: n });
}
$("panelRefreshSec").addEventListener("change", persistRefreshSec);
$("panelRefreshSec").addEventListener("input", persistRefreshSec);

$("save").addEventListener("click", async () => {
  try {
    const password = $("wpAppPassword").value.trim();
    await send("SAVE_SETTINGS", {
      settings: {
        wpBase: $("wpBase").value.trim(),
        wpUser: $("wpUser").value.trim(),
        wpAppPassword: password || undefined,
        fbListenEnabled: $("fbListenEnabled").checked,
        fbListenUrlPart: $("fbListenUrlPart").value.trim(),
      },
    });
    if (password) await chrome.storage.local.set({ wpAppPassword: password });
    setStatus("Saved.", true);
  } catch (e) {
    setStatus(e.message, false);
  }
});

$("test").addEventListener("click", async () => {
  try {
    setStatus("Checking…");
    const password = $("wpAppPassword").value.trim();
    await send("SAVE_SETTINGS", {
      settings: {
        wpBase: $("wpBase").value.trim(),
        wpUser: $("wpUser").value.trim(),
        wpAppPassword: password || undefined,
        fbListenEnabled: $("fbListenEnabled").checked,
        fbListenUrlPart: $("fbListenUrlPart").value.trim(),
      },
    });
    if (password) await chrome.storage.local.set({ wpAppPassword: password });
    const r = await send("TEST_WP");
    const langs = Array.isArray(r.languages) ? r.languages : [];
    const cats = Array.isArray(r.categories) ? r.categories : [];
    const ping = r.ping || {};
    const ver = r.plugin_version || ping.plugin_version || "";
    let line = "OK. Extra Fields " + (ver || "not on this URL");
    line += ". Languages: " + langs.length + ", categories: " + cats.length;
    if (ping.category_terms != null) {
      line += ". DB categories: " + ping.category_terms + ", language terms: " + ping.language_terms;
    }
    if (ping.error) line += ". ping: " + ping.error;
    if (!ver) line += ". REST keys: " + (r.keys || []).join(",");
    setStatus(line, true);
  } catch (e) {
    setStatus(e.message, false);
  }
});

load().catch((e) => setStatus(e.message, false));
