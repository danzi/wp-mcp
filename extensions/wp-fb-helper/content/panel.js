(function () {
  const ROOT_ID = "wp-fb-helper-root";
  const NETWORKS = ["facebook"];

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

  function markCopied(btn) {
    if (!btn) return;
    const prev = btn.getAttribute("data-copy-label") || btn.textContent;
    btn.setAttribute("data-copy-label", prev);
    btn.textContent = "Copied";
    btn.classList.add("fh-btn-copied");
    clearTimeout(btn._copiedTimer);
    btn._copiedTimer = setTimeout(() => {
      btn.textContent = btn.getAttribute("data-copy-label") || prev;
      btn.classList.remove("fh-btn-copied");
    }, 1400);
    const ui = window.__WP_FB_HELPER_UI__;
    if (ui) setStatus(ui, "Copied", "ok");
  }

  function copyText(text, btn) {
    const value = text == null ? "" : String(text);
    return navigator.clipboard.writeText(value).then(() => {
      markCopied(btn);
    }).catch(() => {
      const ta = document.createElement("textarea");
      ta.value = value;
      ta.style.cssText = "position:fixed;left:-9999px";
      document.body.appendChild(ta);
      ta.select();
      document.execCommand("copy");
      ta.remove();
      markCopied(btn);
    });
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function statusColor(phase) {
    if (phase === "err") return "#ef9a9a";
    if (phase === "ok") return "#a5d6a7";
    if (phase === "wait") return "#ffd54f";
    return "#9ecbff";
  }

  const state = {
    filters: { languages: [], categories: [], networks: NETWORKS },
    query: {
      search: "",
      language: "",
      category: "",
      social: "any",
      order: "date_desc",
      page: 1,
      per_page: 20,
      network: "facebook",
    },
    posts: [],
    total: 0,
    selected: null,
    pickerOpen: true,
    view: "list",
    tab: "main",
    extraDraft: "",
    extraEditingId: null,
    extraHistory: [],
    captures: [],
    saveFbToSelected: true,
    refreshSec: 0,
    fieldsFp: "",
    listFp: "",
    loadToken: 0,
    pollTimer: null,
    pollInflight: false,
    lastPollAt: 0,
  };

  const EXTRA_KEY = "extraNotes";
  const QUERY_KEY = "listQuery";
  const CAPTURES_KEY = "socialCaptures";
  const HISTORY_LIMIT = 50;
  const SOCIAL_OPTS = ["any", "not_posted", "posted"];
  const ORDER_OPTS = ["date_desc", "date_asc"];

  function persistQuery() {
    chrome.storage.local.set({
      [QUERY_KEY]: {
        search: String(state.query.search || ""),
        language: String(state.query.language || ""),
        category: String(state.query.category || ""),
        social: String(state.query.social || "any"),
        order: String(state.query.order || "date_desc"),
      },
    }).catch(() => {});
  }

  function applyStoredQuery(raw) {
    if (!raw || typeof raw !== "object") return;
    state.query.search = typeof raw.search === "string" ? raw.search : "";
    state.query.language = typeof raw.language === "string" ? raw.language : "";
    state.query.category = raw.category == null || raw.category === "" ? "" : String(raw.category);
    state.query.social = SOCIAL_OPTS.indexOf(raw.social) >= 0 ? raw.social : "any";
    state.query.order = ORDER_OPTS.indexOf(raw.order) >= 0 ? raw.order : "date_desc";
  }

  function paintQueryControls(ui) {
    ui.search.value = state.query.search || "";
    ui.social.value = state.query.social || "any";
    ui.order.value = state.query.order || "date_desc";
  }

  async function loadExtra() {
    const d = await chrome.storage.local.get(["extraDraft", EXTRA_KEY]);
    state.extraDraft = typeof d.extraDraft === "string" ? d.extraDraft : "";
    state.extraHistory = Array.isArray(d[EXTRA_KEY]) ? d[EXTRA_KEY] : [];
  }

  async function persistExtra() {
    await chrome.storage.local.set({
      extraDraft: state.extraDraft,
      [EXTRA_KEY]: state.extraHistory.slice(0, HISTORY_LIMIT),
    });
  }

  function setStatus(ui, text, phase) {
    ui.status.textContent = text;
    ui.status.style.color = statusColor(phase);
  }

  function fieldBlock(label, text, copyable) {
    const wrap = el("div", "wfh-field");
    const head = el("div", "wfh-field-label", label);
    const body = el("div", "wfh-field-text", text || "—");
    if (copyable) {
      const btn = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Copy");
      btn.type = "button";
      btn.addEventListener("click", () => {
        const t = body.textContent || "";
        copyText(t === "—" ? "" : t, btn);
      });
      head.appendChild(btn);
    }
    wrap.appendChild(head);
    wrap.appendChild(body);
    return wrap;
  }

  function clampRefreshSec(n) {
    const v = Math.round(Number(n) || 0);
    if (v <= 0) return 0;
    return Math.max(1, Math.min(300, v));
  }

  function postRowFingerprint(p) {
    const posted = Array.isArray(p.posted_on) ? p.posted_on.join(",") : "";
    let social = "";
    try {
      social = JSON.stringify(p.social_posts || {});
    } catch (_) {
      social = "";
    }
    return [p.post_id, p.title || "", p.date || "", p.language || "", p.author || "", posted, p.thumbnail || "", p.thumbnail_full || "", social].join("\t");
  }

  function postsFingerprint(posts, total, page) {
    const rows = (posts || []).map(postRowFingerprint);
    return rows.join("\n") + "#" + String(total || 0) + "#" + String(page || 1);
  }

  function selectedFingerprint(post) {
    if (!post) return "";
    const titles = Array.isArray(post.social_titles) ? post.social_titles : [];
    let social = "";
    try {
      social = JSON.stringify(post.social_posts || {});
    } catch (_) {
      social = "";
    }
    const posted = Array.isArray(post.posted_on) ? post.posted_on.join(",") : "";
    return [post.post_id, post.title || "", post.excerpt || "", post.permalink || "", post.thumbnail || "", post.thumbnail_full || "", titles.join("\n"), social, posted].join("|");
  }

  function socialKeyList(post) {
    const keys = [];
    NETWORKS.forEach((n) => {
      if (keys.indexOf(n) < 0) keys.push(n);
    });
    Object.keys((post && post.social_posts) || {}).forEach((n) => {
      if (keys.indexOf(n) < 0) keys.push(n);
    });
    return keys;
  }

  function mergePost(card) {
    if (!card || !card.post_id) return;
    const id = Number(card.post_id);
    state.posts = state.posts.map((p) => (Number(p.post_id) === id ? Object.assign({}, p, card) : p));
    if (state.selected && Number(state.selected.post_id) === id) {
      state.selected = Object.assign({}, state.selected, card);
    }
  }

  function panelTyping(ui) {
    const ae = document.activeElement;
    if (!ae || !ui.root || !ui.root.contains(ae)) return false;
    const tag = String(ae.tagName || "").toLowerCase();
    return tag === "input" || tag === "textarea" || tag === "select" || !!ae.isContentEditable;
  }

  function fillSelect(select, items, valueKey, labelKey, allLabel, current) {
    if (document.activeElement === select) return;
    const next = (items || [])
      .map((item) => String(item[valueKey]) + "\t" + String(item[labelKey]))
      .join("\n");
    if (select.getAttribute("data-fp") === next) {
      if (current != null && select.value !== String(current)) select.value = current;
      return;
    }
    select.innerHTML = "";
    select.appendChild(new Option(allLabel, ""));
    (items || []).forEach((item) => {
      select.appendChild(new Option(item[labelKey], String(item[valueKey])));
    });
    select.setAttribute("data-fp", next);
    select.value = current || "";
    if (current && select.value !== current) select.value = "";
  }

  function visibleCategories() {
    const lang = String(state.query.language || "");
    const all = (state.filters && state.filters.categories) || [];
    const rows = [];
    const seen = {};
    all.forEach((c) => {
      const id = String(c.id);
      if (!id || seen[id]) return;
      const cl = String(c.language || "").toLowerCase();
      if (lang && cl && cl !== lang) return;
      seen[id] = true;
      const label = !lang && cl ? c.name + " · " + cl.toUpperCase() : c.name;
      rows.push({ id: c.id, name: label });
    });
    return rows;
  }

  function applyCategorySelect(ui, opts) {
    const silent = !!(opts && opts.silent);
    fillSelect(ui.cat, visibleCategories(), "id", "name", "All categories", state.query.category);
    if (!silent && state.query.category && ui.cat.value !== String(state.query.category)) {
      state.query.category = "";
    } else if (state.query.category) {
      ui.cat.value = String(state.query.category);
    }
  }

  function stopPoll() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
  }

  function startPoll(ui) {
    stopPoll();
    const sec = clampRefreshSec(state.refreshSec);
    state.refreshSec = sec;
    if (sec <= 0) return;
    const ms = Math.max(1000, sec * 1000);
    state.pollTimer = setInterval(() => {
      quietRefresh(ui).catch(() => {});
    }, ms);
  }

  async function quietRefresh(ui) {
    if (state.pollInflight) return;
    if (document.hidden) return;
    const now = Date.now();
    if (now - state.lastPollAt < 1000) return;
    state.pollInflight = true;
    state.lastPollAt = now;
    try {
      await loadFilters(ui, { silent: true });
      await loadPosts(ui, { silent: true });
      if (state.selected && state.selected.post_id) {
        const card = await send("GET_POST", { post_id: state.selected.post_id });
        mergePost(card || {});
        patchList(ui);
        patchFields(ui);
      }
    } catch (_) {
      // keep current UI
    } finally {
      state.pollInflight = false;
    }
  }

  const LANG_TONES = [
    ["#1565c0", "#fff"],
    ["#00838f", "#e0f7fa"],
    ["#2e7d32", "#e8f5e9"],
    ["#ef6c00", "#fff3e0"],
    ["#6a1b9a", "#f3e5f5"],
    ["#c62828", "#ffebee"],
    ["#455a64", "#eceff1"],
    ["#0277bd", "#e1f5fe"],
    ["#558b2f", "#f1f8e9"],
    ["#00695c", "#e0f2f1"],
  ];
  const AUTHOR_TONES = [
    ["#4a148c", "#e1bee7"],
    ["#004d40", "#b2dfdb"],
    ["#ad1457", "#f8bbd0"],
    ["#283593", "#c5cae9"],
    ["#4e342e", "#d7ccc8"],
    ["#1b5e20", "#c8e6c9"],
    ["#e65100", "#ffe0b2"],
    ["#01579b", "#b3e5fc"],
    ["#880e4f", "#fce4ec"],
    ["#37474f", "#cfd8dc"],
  ];

  function toneFor(str, palette) {
    let h = 0;
    const s = String(str || "").toLowerCase();
    for (let i = 0; i < s.length; i += 1) h = ((h << 5) - h + s.charCodeAt(i)) | 0;
    return palette[Math.abs(h) % palette.length];
  }

  function chip(text, kind, key) {
    const node = el("span", "wfh-chip wfh-chip-" + kind, text);
    if (kind === "lang" || kind === "author") {
      const tone = toneFor(key || text, kind === "lang" ? LANG_TONES : AUTHOR_TONES);
      node.style.background = tone[0];
      node.style.color = tone[1];
    }
    return node;
  }

  function postChips(post) {
    const tags = el("div", "fh-picker-item-tags");
    tags.setAttribute("data-role", "post-chips");
    if (post.language) {
      tags.appendChild(chip(String(post.language).toUpperCase(), "lang", post.language));
    }
    const posted = Array.isArray(post.posted_on) ? post.posted_on : [];
    const hasFb = posted.indexOf("facebook") >= 0;
    tags.appendChild(chip(hasFb ? "FB" : "no FB", hasFb ? "fb-on" : "fb-off"));
    if (post.author) {
      tags.appendChild(chip(post.author, "author", post.author));
    }
    if (post.date) {
      tags.appendChild(chip(String(post.date).slice(0, 10), "date"));
    }
    return tags;
  }

  function hideThumbZoom() {
    const node = document.getElementById("wfh-thumb-zoom");
    if (node) node.remove();
  }

  function showThumbZoom(src, fromBox, e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    hideThumbZoom();
    const layer = el("div", "wfh-thumb-zoom");
    layer.id = "wfh-thumb-zoom";
    const img = document.createElement("img");
    img.src = src;
    img.alt = "";
    img.referrerPolicy = "no-referrer";
    layer.appendChild(img);
    layer.addEventListener("click", hideThumbZoom);
    document.documentElement.appendChild(layer);
  }

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") hideThumbZoom();
  });

  function thumbEl(url, className, zoomUrl) {
    const box = el("div", className);
    const src = String(url || "").trim();
    const zoom = String(zoomUrl || url || "").trim();
    if (src) {
      const img = document.createElement("img");
      img.src = src;
      img.alt = "";
      img.referrerPolicy = "no-referrer";
      img.addEventListener("error", () => {
        box.classList.add("wfh-thumb-empty");
        box.style.cursor = "";
        img.remove();
      });
      box.appendChild(img);
      box.style.cursor = "zoom-in";
      box.title = "Click to view image";
      box.addEventListener("click", (e) => showThumbZoom(zoom || src, box, e));
    } else {
      box.classList.add("wfh-thumb-empty");
    }
    return box;
  }

  function syncView(ui) {
    const list = state.view !== "post";
    ui.picker.style.display = list ? "block" : "none";
    ui.fields.style.display = list ? "none" : "block";
  }

  function paintNavBtn(btn, mode, enabled) {
    btn.disabled = !enabled;
    btn.className = "wfh-nav-btn wfh-nav-btn-" + mode + (enabled ? "" : " is-disabled");
    btn.innerHTML = "";
    const ico = el("span", "wfh-nav-ico", mode === "select" ? "✓" : "←");
    const lab = el("span", "wfh-nav-lab", mode === "select" ? "Select" : "← Posts");
    btn.appendChild(ico);
    btn.appendChild(lab);
  }

  function backToList(ui) {
    hideThumbZoom();
    state.view = "list";
    syncView(ui);
    renderList(ui);
  }

  function openSelected(ui, post) {
    if (post) state.selected = post;
    if (!state.selected) return;
    chrome.storage.local.set({ selectedPostId: Number(state.selected.post_id) });
    state.view = "post";
    syncView(ui);
    renderFields(ui);
    setStatus(ui, "Post #" + state.selected.post_id, "ok");
  }

  async function saveSocial(ui, network, url) {
    if (!state.selected) return;
    try {
      setStatus(ui, "Saving…", "wait");
      const saved = await send("SET_SOCIAL", {
        post_id: state.selected.post_id,
        network: network,
        url: String(url || "").trim(),
      });
      state.selected = Object.assign({}, state.selected, saved || {});
      const map = Object.assign({}, state.selected.social_posts || {});
      const trimmed = String(url || "").trim();
      if (trimmed) map[network] = trimmed;
      else delete map[network];
      state.selected.social_posts = map;
      if (!Array.isArray(state.selected.posted_on)) state.selected.posted_on = [];
      if (trimmed && state.selected.posted_on.indexOf(network) < 0) {
        state.selected.posted_on = state.selected.posted_on.concat([network]);
      }
      if (!trimmed) {
        state.selected.posted_on = state.selected.posted_on.filter((n) => n !== network);
      }
      mergePost(state.selected);
      patchList(ui);
      patchFields(ui);
      setStatus(ui, "Saved " + network, "ok");
    } catch (e) {
      setStatus(ui, e.message, "err");
    }
  }

  function paintSocialTitles(wrap, titles) {
    const list = Array.isArray(titles) ? titles : [];
    const fp = list.join("\n");
    if (wrap.getAttribute("data-fp") === fp && wrap.childNodes.length > 0) return;
    wrap.setAttribute("data-fp", fp);
    wrap.innerHTML = "";
    const titlesHead = el("div", "wfh-field-label", "2. Social titles");
    if (list.length > 0) {
      const copyAll = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Copy all");
      copyAll.type = "button";
      copyAll.addEventListener("click", () => copyText(list.join("\n\n"), copyAll));
      titlesHead.appendChild(copyAll);
    }
    wrap.appendChild(titlesHead);
    if (list.length === 0) {
      wrap.appendChild(el("div", "fh-muted", "No social titles"));
    }
    list.forEach((line, i) => {
      const row = el("div", "fh-prompt-row");
      const meta = el("div", "fh-prompt-meta");
      meta.appendChild(el("span", "fh-prompt-idx", "#" + (i + 1)));
      const copyBtn = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Copy");
      copyBtn.type = "button";
      copyBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        copyText(line, copyBtn);
      });
      meta.appendChild(copyBtn);
      row.appendChild(meta);
      row.appendChild(el("div", "fh-prompt-text", line));
      wrap.appendChild(row);
    });
  }

  function renderFields(ui) {
    if (state.view !== "post") return;
    const top = ui.fields.scrollTop;
    state.fieldsFp = selectedFingerprint(state.selected);
    ui.fields.innerHTML = "";
    const post = state.selected;
    if (!post) {
      ui.fields.appendChild(el("div", "fh-muted", "Pick a post in the list."));
      return;
    }

    const head = el("div", "wfh-post-head");
    const media = el("div", "wfh-post-media");
    const backBtn = el("button", "wfh-nav-btn wfh-nav-btn-back");
    backBtn.type = "button";
    paintNavBtn(backBtn, "back", true);
    backBtn.addEventListener("click", () => backToList(ui));
    media.appendChild(backBtn);
    media.appendChild(thumbEl(post.thumbnail, "wfh-thumb wfh-thumb-lg", post.thumbnail_full));
    head.appendChild(media);
    const headMain = el("div", "wfh-post-head-main");
    const titleEl = el("div", "wfh-post-head-title", post.title || "#" + post.post_id);
    titleEl.setAttribute("data-role", "post-title");
    headMain.appendChild(titleEl);
    headMain.appendChild(postChips(post));
    const href = String(post.permalink || "").trim();
    const link = document.createElement("a");
    link.className = "wfh-post-link";
    link.setAttribute("data-role", "post-link");
    link.target = "_blank";
    link.rel = "noopener noreferrer";
    if (href) {
      link.href = href;
      link.textContent = href;
    } else {
      link.style.display = "none";
    }
    headMain.appendChild(link);
    head.appendChild(headMain);
    ui.fields.appendChild(head);

    const titleBlock = fieldBlock("1. Title", post.title || "", true);
    titleBlock.setAttribute("data-role", "title-block");
    ui.fields.appendChild(titleBlock);

    const excerpt = String(post.excerpt || "").trim();
    const excerptBlock = fieldBlock("Excerpt", excerpt || "—", true);
    excerptBlock.setAttribute("data-role", "excerpt-block");
    excerptBlock.style.display = excerpt ? "block" : "none";
    ui.fields.appendChild(excerptBlock);

    const titlesWrap = el("div", "wfh-field");
    titlesWrap.setAttribute("data-role", "social-titles");
    paintSocialTitles(titlesWrap, Array.isArray(post.social_titles) ? post.social_titles : []);
    ui.fields.appendChild(titlesWrap);

    const socialWrap = el("div", "wfh-field");
    socialWrap.setAttribute("data-role", "social-posts");
    socialWrap.setAttribute("data-networks", socialKeyList(post).join(","));
    const socialHead = el("div", "wfh-field-label", "3. Social posts");
    const refresh = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Refresh");
    refresh.type = "button";
    refresh.title = "Reload social URLs from WordPress";
    refresh.addEventListener("click", () => refreshSocial(ui));
    socialHead.appendChild(refresh);
    socialWrap.appendChild(socialHead);

    const postsMap = post.social_posts && typeof post.social_posts === "object" ? post.social_posts : {};
    const networks = [];
    NETWORKS.forEach((n) => {
      if (networks.indexOf(n) < 0) networks.push(n);
    });
    Object.keys(postsMap).forEach((n) => {
      if (networks.indexOf(n) < 0) networks.push(n);
    });
    networks.forEach((network) => {
      const url = postsMap[network] || "";
      const row = el("div", "wfh-social-row");
      const dot = el("span", "wfh-dot " + (url ? "wfh-dot-on" : "wfh-dot-off"));
      const name = el("span", "wfh-social-name", network);
      const input = document.createElement("input");
      input.type = "url";
      input.value = url;
      input.placeholder = network + " URL";
      input.setAttribute("data-network", network);
      const saveBtn = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Save");
      saveBtn.type = "button";
      saveBtn.addEventListener("click", () => saveSocial(ui, network, input.value));
      input.addEventListener("keydown", (e) => {
        if (e.key === "Enter") saveSocial(ui, network, input.value);
      });
      const clearBtn = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Clear");
      clearBtn.type = "button";
      clearBtn.setAttribute("data-role", "clear-social");
      clearBtn.title = "Remove social URL";
      clearBtn.disabled = !url;
      clearBtn.addEventListener("click", () => saveSocial(ui, network, ""));
      const copyBtn = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Copy");
      copyBtn.type = "button";
      copyBtn.setAttribute("data-role", "copy-social");
      copyBtn.disabled = !url;
      copyBtn.addEventListener("click", () => copyText(input.value || url, copyBtn));
      row.appendChild(dot);
      row.appendChild(name);
      row.appendChild(input);
      row.appendChild(saveBtn);
      row.appendChild(clearBtn);
      row.appendChild(copyBtn);
      socialWrap.appendChild(row);
    });
    ui.fields.appendChild(socialWrap);
    ui.fields.setAttribute("data-post-id", String(post.post_id));
    ui.fields.scrollTop = top;
  }

  function patchThumb(box, url, zoomUrl) {
    if (!box) return;
    const src = String(url || "").trim();
    const zoom = String(zoomUrl || url || "").trim();
    let img = box.querySelector("img");
    if (!src) {
      if (img) img.remove();
      box.classList.add("wfh-thumb-empty");
      box.style.cursor = "";
      return;
    }
    box.classList.remove("wfh-thumb-empty");
    box.style.cursor = "zoom-in";
    if (!img) {
      img = document.createElement("img");
      img.alt = "";
      img.referrerPolicy = "no-referrer";
      box.appendChild(img);
    }
    if (img.getAttribute("src") !== src) img.src = src;
    box.onclick = (e) => showThumbZoom(zoom || src, box, e);
  }

  function patchList(ui) {
    if (!ui || !ui.list) return;
    const ids = (state.posts || []).map((p) => String(p.post_id)).join(",");
    const same = ui.list.getAttribute("data-ids") === ids && state.posts.length > 0;
    if (!same) {
      renderList(ui);
      return;
    }
    const nodes = ui.list.querySelectorAll("[data-post-id]");
    state.posts.forEach((post, i) => {
      const item = nodes[i];
      if (!item) return;
      const fp = postRowFingerprint(post);
      item.classList.toggle(
        "fh-picker-item-sel",
        !!(state.selected && Number(state.selected.post_id) === Number(post.post_id))
      );
      if (item.getAttribute("data-fp") === fp) return;
      item.setAttribute("data-fp", fp);
      const name = item.querySelector(".fh-picker-item-name");
      if (name) name.textContent = post.title || "#" + post.post_id;
      const tags = item.querySelector("[data-role='post-chips']");
      if (tags) tags.replaceWith(postChips(post));
      patchThumb(item.querySelector(".wfh-thumb"), post.thumbnail, post.thumbnail_full);
    });
    state.listFp = postsFingerprint(state.posts, state.total, state.query.page);
    setPageLabels(ui);
  }

  function patchFields(ui) {
    if (!ui || state.view !== "post" || !state.selected) return;
    const post = state.selected;
    const root = ui.fields;
    if (root.getAttribute("data-post-id") !== String(post.post_id)) {
      renderFields(ui);
      return;
    }
    const keys = socialKeyList(post).join(",");
    const wrap = root.querySelector("[data-role='social-posts']");
    if (wrap && wrap.getAttribute("data-networks") !== keys) {
      renderFields(ui);
      return;
    }
    const fp = selectedFingerprint(post);
    if (fp === state.fieldsFp) return;
    const focused = document.activeElement && root.contains(document.activeElement);
    const titleEl = root.querySelector("[data-role='post-title']");
    if (titleEl) titleEl.textContent = post.title || "#" + post.post_id;
    const titleBody = root.querySelector("[data-role='title-block'] .wfh-field-text");
    if (titleBody) titleBody.textContent = post.title || "—";
    const chips = root.querySelector("[data-role='post-chips']");
    if (chips) chips.replaceWith(postChips(post));
    const link = root.querySelector("[data-role='post-link']");
    const href = String(post.permalink || "").trim();
    if (link) {
      if (href) {
        link.href = href;
        link.textContent = href;
        link.style.display = "block";
      } else {
        link.style.display = "none";
      }
    }
    const excerpt = String(post.excerpt || "").trim();
    const excerptBlock = root.querySelector("[data-role='excerpt-block']");
    if (excerptBlock) {
      excerptBlock.style.display = excerpt ? "block" : "none";
      const body = excerptBlock.querySelector(".wfh-field-text");
      if (body) body.textContent = excerpt || "—";
    }
    const titlesWrap = root.querySelector("[data-role='social-titles']");
    const titles = Array.isArray(post.social_titles) ? post.social_titles : [];
    if (titlesWrap) paintSocialTitles(titlesWrap, titles);
    root.querySelectorAll("input[data-network]").forEach((input) => {
      if (focused && document.activeElement === input) return;
      const network = input.getAttribute("data-network");
      const url = (post.social_posts && post.social_posts[network]) || "";
      if (input.value !== url) input.value = url;
      const row = input.parentElement;
      const dot = row ? row.querySelector(".wfh-dot") : null;
      if (dot) dot.className = "wfh-dot " + (url ? "wfh-dot-on" : "wfh-dot-off");
      const copyBtn = row ? row.querySelector("[data-role='copy-social']") : null;
      if (copyBtn) copyBtn.disabled = !url;
      const clearBtn = row ? row.querySelector("[data-role='clear-social']") : null;
      if (clearBtn) clearBtn.disabled = !url;
    });
    patchThumb(root.querySelector(".wfh-thumb-lg"), post.thumbnail, post.thumbnail_full);
    state.fieldsFp = fp;
    if (!focused) root.scrollTop = root.scrollTop;
  }

  function renderList(ui) {
    const top = ui.list.scrollTop;
    const ids = (state.posts || []).map((p) => String(p.post_id)).join(",");
    if (ui.list.getAttribute("data-ids") === ids && state.posts.length > 0) {
      patchList(ui);
      ui.list.scrollTop = top;
      return;
    }
    state.listFp = postsFingerprint(state.posts, state.total, state.query.page);
    ui.list.innerHTML = "";
    ui.list.setAttribute("data-ids", ids);
    if (state.posts.length === 0) {
      ui.list.appendChild(el("div", "fh-muted", "No posts"));
      return;
    }
    state.posts.forEach((post) => {
      const item = el("div", "fh-picker-item");
      item.setAttribute("data-post-id", String(post.post_id));
      item.setAttribute("data-fp", postRowFingerprint(post));
      if (state.selected && Number(state.selected.post_id) === Number(post.post_id)) {
        item.classList.add("fh-picker-item-sel");
      }
      item.appendChild(thumbEl(post.thumbnail, "wfh-thumb", post.thumbnail_full));
      const main = el("div", "fh-picker-item-main");
      main.appendChild(el("div", "fh-picker-item-name", post.title || "#" + post.post_id));
      main.appendChild(postChips(post));
      item.appendChild(main);
      item.addEventListener("click", () => {
        const id = Number(item.getAttribute("data-post-id"));
        const fresh = (state.posts || []).find((p) => Number(p.post_id) === id);
        openSelected(ui, fresh);
      });
      ui.list.appendChild(item);
    });
    setPageLabels(ui);
    ui.list.scrollTop = top;
  }

  function setPageLabels(ui) {
    const pages = Math.max(1, Math.ceil((state.total || 0) / state.query.per_page));
    const text = state.query.page + " / " + pages;
    (ui.pagers || []).forEach((p) => {
      p.label.textContent = text;
      p.prev.disabled = state.query.page <= 1;
      p.next.disabled = state.query.page >= pages;
    });
  }

  async function selectPost(ui, post) {
    openSelected(ui, post);
  }

  async function refreshSocial(ui) {
    if (!state.selected) return;
    try {
      setStatus(ui, "Refresh…", "wait");
      const card = await send("GET_POST", { post_id: state.selected.post_id });
      mergePost(card || {});
      patchList(ui);
      patchFields(ui);
      setStatus(ui, "Social URLs updated", "ok");
    } catch (e) {
      setStatus(ui, e.message, "err");
    }
  }

  async function loadPosts(ui, opts) {
    const silent = !!(opts && opts.silent);
    const token = ++state.loadToken;
    try {
      if (!silent) setStatus(ui, "Loading posts…", "wait");
      const res = await send("LIST_POSTS", { query: state.query });
      if (token !== state.loadToken) return;
      const posts = Array.isArray(res.posts) ? res.posts : [];
      const total = Number(res.total || 0);
      const fp = postsFingerprint(posts, total, state.query.page);
      state.posts = posts;
      state.total = total;
      if (state.selected) {
        const fresh = posts.find((p) => Number(p.post_id) === Number(state.selected.post_id));
        if (fresh) mergePost(fresh);
      }
      if (fp !== state.listFp) patchList(ui);
      else setPageLabels(ui);
      if (state.view === "post") patchFields(ui);
      if (!silent) setStatus(ui, state.posts.length + " posts", "ok");
    } catch (e) {
      if (token !== state.loadToken) return;
      if (!silent) setStatus(ui, e.message, "err");
    }
  }

  async function loadFilters(ui, opts) {
    const silent = !!(opts && opts.silent);
    try {
      const res = await send("LIST_FILTERS");
      state.filters = res;
      fillSelect(ui.lang, res.languages || [], "slug", "name", "All languages", state.query.language);
      applyCategorySelect(ui, { silent: silent });
    } catch (e) {
      if (!silent) setStatus(ui, e.message, "err");
    }
  }

  function ensureUi() {
    if (window.__WP_FB_HELPER_UI__) return window.__WP_FB_HELPER_UI__;

    const shell = window.__WFH_SHELL__;
    const body = shell && shell.body ? shell.body : el("div", "fh-body");
    const title = shell && shell.title ? shell.title : el("div", "fh-title", "WP FB Helper");
    const reconnectBtn = shell && shell.reconnectBtn ? shell.reconnectBtn : el("button", "fh-icon-btn", "↻");
    reconnectBtn.type = "button";
    reconnectBtn.title = "Reload";
    const status = el("div", "fh-status", "Boot…");

    const tabs = el("div", "wfh-tabs");
    const tabMain = el("button", "active", "Main");
    tabMain.type = "button";
    const tabExtra = el("button", "", "Extra");
    tabExtra.type = "button";
    const tabPosted = el("button", "", "Posted");
    tabPosted.type = "button";
    tabs.appendChild(tabMain);
    tabs.appendChild(tabExtra);
    tabs.appendChild(tabPosted);

    const mainPane = el("div", "wfh-pane-main");
    const extraPane = el("div", "wfh-pane-extra");
    extraPane.style.display = "none";
    const postedPane = el("div", "wfh-pane-posted");
    postedPane.style.display = "none";

    const extraArea = document.createElement("textarea");
    extraArea.className = "wfh-extra-area";
    extraArea.placeholder = "Snippet, CTA, greeting…";
    extraArea.rows = 5;
    const extraActions = el("div", "wfh-extra-actions");
    const extraSave = el("button", "fh-btn fh-btn-primary", "Save to history");
    extraSave.type = "button";
    const extraCopy = el("button", "fh-btn fh-btn-ghost", "Copy");
    extraCopy.type = "button";
    extraActions.appendChild(extraSave);
    extraActions.appendChild(extraCopy);
    const extraHist = el("div", "wfh-hist");
    extraPane.appendChild(el("div", "wfh-field-label", "Current text"));
    extraPane.appendChild(extraArea);
    extraPane.appendChild(extraActions);
    extraPane.appendChild(el("div", "wfh-field-label", "History"));
    extraPane.appendChild(extraHist);

    const postedAddRow = el("div", "wfh-posted-add");
    const postedInput = document.createElement("input");
    postedInput.className = "fh-input";
    postedInput.type = "url";
    postedInput.placeholder = "https://www.facebook.com/…";
    const postedAddBtn = el("button", "fh-btn fh-btn-primary fh-btn-xs", "Add");
    postedAddBtn.type = "button";
    postedAddRow.appendChild(postedInput);
    postedAddRow.appendChild(postedAddBtn);
    const postedActions = el("div", "wfh-extra-actions");
    const postedClear = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Delete all");
    postedClear.type = "button";
    postedActions.appendChild(postedClear);
    const postedList = el("div", "wfh-hist");
    postedPane.appendChild(el("div", "wfh-field-label", "Captured post URLs"));
    postedPane.appendChild(postedAddRow);
    postedPane.appendChild(postedActions);
    postedPane.appendChild(postedList);

    const picker = el("div", "fh-picker-dropdown");
    const filters = el("div", "wfh-filters");
    const search = document.createElement("input");
    search.className = "fh-input";
    search.type = "search";
    search.placeholder = "Search posts…";

    const row1 = el("div", "wfh-filters-row");
    const lang = document.createElement("select");
    lang.className = "fh-select";
    const cat = document.createElement("select");
    cat.className = "fh-select";
    row1.appendChild(lang);
    row1.appendChild(cat);

    const row2 = el("div", "wfh-filters-row");
    const social = document.createElement("select");
    social.className = "fh-select";
    social.appendChild(new Option("Social: any", "any"));
    social.appendChild(new Option("Facebook: not posted", "not_posted"));
    social.appendChild(new Option("Facebook: posted", "posted"));
    const order = document.createElement("select");
    order.className = "fh-select";
    order.appendChild(new Option("Date ↓", "date_desc"));
    order.appendChild(new Option("Date ↑", "date_asc"));
    row2.appendChild(social);
    row2.appendChild(order);

    const saveRow = el("label", "wfh-save-fb");
    const saveFb = document.createElement("input");
    saveFb.type = "checkbox";
    saveFb.checked = true;
    saveRow.appendChild(saveFb);
    saveRow.appendChild(document.createTextNode(" Update selected news Facebook URL"));

    filters.appendChild(search);
    filters.appendChild(row1);
    filters.appendChild(row2);
    filters.appendChild(saveRow);

    const list = el("div", "fh-picker-list");

    function makePager() {
      const row = el("div", "wfh-page");
      const prevBtn = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Prev");
      prevBtn.type = "button";
      const label = el("span", "fh-muted", "1 / 1");
      const nextBtn = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Next");
      nextBtn.type = "button";
      row.appendChild(prevBtn);
      row.appendChild(label);
      row.appendChild(nextBtn);
      return { row: row, prev: prevBtn, next: nextBtn, label: label };
    }
    const pagerTop = makePager();
    const pagerBottom = makePager();

    picker.appendChild(filters);
    picker.appendChild(pagerTop.row);
    picker.appendChild(list);
    picker.appendChild(pagerBottom.row);

    const fields = el("div", "wfh-fields");
    fields.style.display = "none";

    body.appendChild(status);
    body.appendChild(tabs);
    mainPane.appendChild(picker);
    mainPane.appendChild(fields);
    body.appendChild(mainPane);
    body.appendChild(extraPane);
    body.appendChild(postedPane);
    if (!shell) {
      const root = el("div", "fh-root size-m");
      root.id = ROOT_ID;
      document.documentElement.appendChild(root);
      root.appendChild(body);
    }

    const ui = {
      root: shell && shell.root ? shell.root : body.parentElement,
      title,
      status,
      reconnectBtn,
      search,
      lang,
      cat,
      social,
      order,
      list,
      pagers: [pagerTop, pagerBottom],
      fields,
      picker,
      tabMain,
      tabExtra,
      tabPosted,
      mainPane,
      extraPane,
      postedPane,
      postedInput,
      postedAddBtn,
      postedClear,
      postedList,
      saveFb,
      extraArea,
      extraSave,
      extraCopy,
      extraHist,
    };
    window.__WP_FB_HELPER_UI__ = ui;
    return ui;
  }

  function bind(ui) {
    let searchTimer = null;
    ui.search.addEventListener("input", () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        state.query.search = ui.search.value.trim();
        state.query.page = 1;
        persistQuery();
        loadPosts(ui);
      }, 300);
    });
    ui.lang.addEventListener("change", () => {
      state.query.language = ui.lang.value;
      applyCategorySelect(ui);
      state.query.page = 1;
      persistQuery();
      loadPosts(ui);
    });
    ui.cat.addEventListener("change", () => {
      state.query.category = ui.cat.value;
      state.query.page = 1;
      persistQuery();
      loadPosts(ui);
    });
    ui.social.addEventListener("change", () => {
      state.query.social = ui.social.value;
      state.query.page = 1;
      persistQuery();
      loadPosts(ui);
    });
    ui.order.addEventListener("change", () => {
      state.query.order = ui.order.value;
      state.query.page = 1;
      persistQuery();
      loadPosts(ui);
    });
    function goPage(delta) {
      const pages = Math.max(1, Math.ceil((state.total || 0) / state.query.per_page));
      const nextPage = state.query.page + delta;
      if (nextPage < 1 || nextPage > pages) return;
      state.query.page = nextPage;
      loadPosts(ui);
    }
    (ui.pagers || []).forEach((p) => {
      p.prev.addEventListener("click", () => goPage(-1));
      p.next.addEventListener("click", () => goPage(1));
    });
    ui.reconnectBtn.addEventListener("click", async () => {
      await loadFilters(ui);
      await loadPosts(ui);
    });
    ui.tabMain.addEventListener("click", () => setTab(ui, "main"));
    ui.tabExtra.addEventListener("click", () => setTab(ui, "extra"));
    ui.tabPosted.addEventListener("click", () => setTab(ui, "posted"));
    ui.saveFb.addEventListener("change", () => {
      state.saveFbToSelected = !!ui.saveFb.checked;
      chrome.storage.local.set({ saveFbToSelected: state.saveFbToSelected }).catch(() => {});
    });
    ui.postedAddBtn.addEventListener("click", () => addManualCapture(ui));
    ui.postedInput.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter") {
        ev.preventDefault();
        addManualCapture(ui);
      }
    });
    ui.postedClear.addEventListener("click", () => {
      send("CLEAR_CAPTURES")
        .then((res) => {
          state.captures = res.items || [];
          renderCaptures(ui);
          setStatus(ui, "Captured URLs cleared", "ok");
        })
        .catch((e) => setStatus(ui, e.message, "err"));
    });
    ui.extraArea.addEventListener("input", () => {
      state.extraDraft = ui.extraArea.value;
      persistExtra().catch(() => {});
    });
    ui.extraCopy.addEventListener("click", () => copyText(ui.extraArea.value || "", ui.extraCopy));
    ui.extraSave.addEventListener("click", () => saveExtraHistory(ui));
  }

  function setTab(ui, tab) {
    state.tab = tab;
    ui.tabMain.classList.toggle("active", tab === "main");
    ui.tabExtra.classList.toggle("active", tab === "extra");
    ui.tabPosted.classList.toggle("active", tab === "posted");
    ui.mainPane.style.display = tab === "main" ? "block" : "none";
    ui.extraPane.style.display = tab === "extra" ? "block" : "none";
    ui.postedPane.style.display = tab === "posted" ? "block" : "none";
    if (tab === "posted") renderCaptures(ui);
  }

  function renderCaptures(ui) {
    ui.postedList.innerHTML = "";
    if (!state.captures.length) {
      ui.postedList.appendChild(el("div", "fh-muted", "No captured URLs yet"));
      return;
    }
    state.captures.forEach((item) => {
      const row = el("div", "wfh-hist-item wfh-posted-item");
      const head = el("div", "wfh-posted-head");
      if (item.viaHelper) {
        const mark = el("span", "wfh-fb-mark", "f");
        mark.title = "Captured from Facebook";
        head.appendChild(mark);
      }
      const link = document.createElement("a");
      link.className = "wfh-posted-url";
      link.href = item.url;
      link.target = "_blank";
      link.rel = "noreferrer";
      link.textContent = item.url;
      head.appendChild(link);
      row.appendChild(head);
      const actions = el("div", "wfh-hist-actions");
      const copyBtn = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Copy");
      copyBtn.type = "button";
      copyBtn.addEventListener("click", () => copyText(item.url || "", copyBtn));
      const delBtn = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Delete");
      delBtn.type = "button";
      delBtn.addEventListener("click", () => {
        send("DELETE_CAPTURE", { id: item.id })
          .then((res) => {
            state.captures = res.items || [];
            renderCaptures(ui);
          })
          .catch((e) => setStatus(ui, e.message, "err"));
      });
      actions.appendChild(copyBtn);
      actions.appendChild(delBtn);
      row.appendChild(actions);
      ui.postedList.appendChild(row);
    });
  }

  function addManualCapture(ui) {
    const url = String(ui.postedInput.value || "").trim();
    if (!url) {
      setStatus(ui, "URL is empty", "err");
      return;
    }
    send("ADD_CAPTURE", { url: url, viaHelper: false })
      .then((res) => {
        state.captures = res.items || [];
        ui.postedInput.value = "";
        renderCaptures(ui);
        setStatus(ui, "URL added", "ok");
      })
      .catch((e) => setStatus(ui, e.message, "err"));
  }

  function renderExtraHistory(ui) {
    ui.extraHist.innerHTML = "";
    if (state.extraHistory.length === 0) {
      ui.extraHist.appendChild(el("div", "fh-muted", "Empty"));
      return;
    }
    state.extraHistory.forEach((item) => {
      const row = el("div", "wfh-hist-item");
      if (state.extraEditingId === item.id) row.style.borderColor = "#1565c0";
      row.appendChild(el("div", "wfh-hist-text", item.text || ""));
      const actions = el("div", "wfh-hist-actions");
      const copyBtn = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Copy");
      copyBtn.type = "button";
      copyBtn.addEventListener("click", () => copyText(item.text || "", copyBtn));
      const editBtn = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Edit");
      editBtn.type = "button";
      editBtn.addEventListener("click", () => {
        state.extraEditingId = item.id;
        state.extraDraft = item.text || "";
        ui.extraArea.value = state.extraDraft;
        ui.extraSave.textContent = "Update";
        persistExtra().catch(() => {});
        renderExtraHistory(ui);
      });
      const delBtn = el("button", "fh-btn fh-btn-ghost fh-btn-xs", "Delete");
      delBtn.type = "button";
      delBtn.addEventListener("click", () => {
        state.extraHistory = state.extraHistory.filter((h) => h.id !== item.id);
        if (state.extraEditingId === item.id) {
          state.extraEditingId = null;
          ui.extraSave.textContent = "Save to history";
        }
        persistExtra().catch(() => {});
        renderExtraHistory(ui);
      });
      actions.appendChild(copyBtn);
      actions.appendChild(editBtn);
      actions.appendChild(delBtn);
      row.appendChild(actions);
      ui.extraHist.appendChild(row);
    });
  }

  async function saveExtraHistory(ui) {
    const text = String(ui.extraArea.value || "").trim();
    if (!text) {
      setStatus(ui, "Extra text is empty", "err");
      return;
    }
    if (state.extraEditingId) {
      state.extraHistory = state.extraHistory.map((h) =>
        h.id === state.extraEditingId ? Object.assign({}, h, { text: text, updatedAt: Date.now() }) : h
      );
      state.extraEditingId = null;
      ui.extraSave.textContent = "Save to history";
    } else {
      state.extraHistory.unshift({
        id: Date.now() + "-" + Math.random().toString(16).slice(2),
        text: text,
        updatedAt: Date.now(),
      });
      state.extraHistory = state.extraHistory.slice(0, HISTORY_LIMIT);
    }
    state.extraDraft = text;
    await persistExtra();
    renderExtraHistory(ui);
    setStatus(ui, "Extra saved locally", "ok");
  }

  window.addEventListener("message", (ev) => {
    if (!ev.data || ev.data.source !== "wp-fb-helper" || ev.data.type !== "FB_HTTP") return;
    send("FB_HTTP", { url: ev.data.url, body: ev.data.body })
      .then((res) => {
        const ui = window.__WP_FB_HELPER_UI__;
        if (!ui) return;
        if (res && res.found) {
          send("LIST_CAPTURES")
            .then((cap) => {
              state.captures = cap.items || [];
              if (state.tab === "posted") renderCaptures(ui);
            })
            .catch(() => {});
          if (res.saved && state.selected) {
            state.selected.social_posts = Object.assign({}, state.selected.social_posts || {}, {
              facebook: res.found,
            });
            state.selected.posted_on = Array.from(
              new Set((state.selected.posted_on || []).concat(["facebook"]))
            );
            mergePost(state.selected);
            patchList(ui);
            patchFields(ui);
            setStatus(ui, "Facebook URL saved", "ok");
          } else if (res.skipped) {
            setStatus(ui, "Facebook URL already on post, not overwritten", "ok");
          } else if (res.captured) {
            setStatus(ui, "Facebook URL captured", "ok");
          }
        }
      })
      .catch(() => {});
  });

  async function boot() {
    const ui = ensureUi();
    bind(ui);
    syncView(ui);
    try {
      await loadExtra();
      ui.extraArea.value = state.extraDraft;
      renderExtraHistory(ui);
      const local = await chrome.storage.local.get([
        "selectedPostId",
        "panelRefreshSec",
        QUERY_KEY,
        "saveFbToSelected",
        CAPTURES_KEY,
      ]);
      state.refreshSec = clampRefreshSec(local.panelRefreshSec);
      state.saveFbToSelected = local.saveFbToSelected !== false;
      ui.saveFb.checked = state.saveFbToSelected;
      state.captures = Array.isArray(local[CAPTURES_KEY]) ? local[CAPTURES_KEY] : [];
      applyStoredQuery(local[QUERY_KEY]);
      paintQueryControls(ui);
      await loadFilters(ui);
      persistQuery();
      await loadPosts(ui);
      const id = Number(local.selectedPostId || 0);
      if (id) {
        const found = state.posts.find((p) => Number(p.post_id) === id);
        if (found) state.selected = found;
      }
      renderList(ui);
      setStatus(ui, "Ready", "ok");
      startPoll(ui);
      chrome.storage.onChanged.addListener((changes, area) => {
        if (area !== "local" || !changes.panelRefreshSec) return;
        state.refreshSec = clampRefreshSec(changes.panelRefreshSec.newValue);
        startPoll(ui);
      });
      document.addEventListener("visibilitychange", () => {
        if (document.hidden) return;
        quietRefresh(ui).catch(() => {});
      });
    } catch (e) {
      setStatus(ui, e.message, "err");
    }
  }

  boot();
})();
