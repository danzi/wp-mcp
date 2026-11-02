const NETWORKS = ["facebook"];

function throwError(message, code) {
  const err = new Error(message);
  err.code = code;
  throw err;
}

function normalizeBase(url) {
  let u = String(url || "").trim();
  u = u.replace(/\/+$/, "");
  u = u.replace(/\/wp-admin$/i, "");
  u = u.replace(/\/wp-login\.php$/i, "");
  u = u.replace(/\/wp-json$/i, "");
  return u;
}

async function getSettings() {
  const d = await chrome.storage.local.get([
    "wpBase",
    "wpUser",
    "wpAppPassword",
    "fbListenEnabled",
    "fbListenUrlPart",
    "wpRestStyle",
  ]);
  return {
    wpBase: normalizeBase(d.wpBase),
    wpUser: String(d.wpUser || ""),
    wpAppPassword: String(d.wpAppPassword || ""),
    fbListenEnabled: d.fbListenEnabled !== false,
    fbListenUrlPart: String(d.fbListenUrlPart || "graph.facebook.com"),
    wpRestStyle: String(d.wpRestStyle || ""),
  };
}

function authHeader(user, password) {
  return "Basic " + btoa(unescape(encodeURIComponent(user + ":" + password)));
}

function appPassword(raw) {
  return String(raw || "").replace(/\s+/g, "");
}

const REST_STYLES = ["pretty", "pretty_slash", "query", "index"];

function buildRestUrl(base, route, style) {
  const qIndex = String(route).indexOf("?");
  const path = qIndex >= 0 ? route.slice(0, qIndex) : route;
  const qs = qIndex >= 0 ? route.slice(qIndex + 1) : "";
  if (style === "pretty") {
    return base + "/wp-json" + path + (qs ? "?" + qs : "");
  }
  if (style === "pretty_slash") {
    const withSlash = path.charAt(path.length - 1) === "/" ? path : path + "/";
    return base + "/wp-json" + withSlash + (qs ? "?" + qs : "");
  }
  const extra = qs ? "&" + qs : "";
  const encoded = encodeURIComponent(path);
  if (style === "query") {
    return base + "/?rest_route=" + encoded + extra;
  }
  return base + "/index.php?rest_route=" + encoded + extra;
}

function snippet(text) {
  return String(text || "")
    .replace(/\s+/g, " ")
    .trim()
    .slice(0, 160);
}

function parseRestBody(text, res) {
  const trimmed = String(text || "").trim();
  const ct = String(res.headers.get("content-type") || "").toLowerCase();
  const looksJson = trimmed.charAt(0) === "{" || trimmed.charAt(0) === "[";
  if (ct.indexOf("html") >= 0 || !looksJson) {
    const err = new Error(
      "Not REST JSON (" + res.status + "). Use the site home URL, not /wp-admin. " + snippet(trimmed)
    );
    err.code = "wp_not_json";
    throw err;
  }
  try {
    return JSON.parse(trimmed);
  } catch (_) {
    const err = new Error("Not REST JSON. " + snippet(trimmed));
    err.code = "wp_not_json";
    throw err;
  }
}

async function wpFetchUrl(url, options, headers) {
  const fetchOpts = Object.assign({}, options || {}, { headers: headers, redirect: "manual" });
  let res = await fetch(url, fetchOpts);
  if (res.status >= 300 && res.status < 400) {
    const loc = res.headers.get("Location");
    if (!loc) throwError("WordPress redirected and dropped the Location header. Use the final https URL.", "wp_redirect");
    res = await fetch(loc, Object.assign({}, fetchOpts, { redirect: "follow" }));
  }
  const text = await res.text();
  const body = parseRestBody(text, res);
  if (!res.ok) {
    let msg = (body && (body.error || body.message)) || "HTTP " + res.status;
    if (body && body.data && body.data.debug) {
      msg += " " + JSON.stringify(body.data.debug);
    }
    const err = new Error(String(msg));
    err.code = "wp_http_" + res.status;
    throw err;
  }
  if (body && body.success === false) {
    throwError(String(body.error || "WordPress request failed"), body.code || "wp_failed");
  }
  const verHeader = res.headers.get("X-EMPM-Version") || res.headers.get("x-empm-version");
  if (body && typeof body === "object" && !Array.isArray(body) && verHeader && !body.plugin_version) {
    body.plugin_version = verHeader;
  }
  return body;
}

async function wpFetch(route, options) {
  const s = await getSettings();
  if (!s.wpBase) throwError("WordPress URL is empty.", "wp_url_required");
  if (!s.wpUser || !s.wpAppPassword) throwError("Application Password is empty.", "wp_auth_required");
  const password = appPassword(s.wpAppPassword);
  const headers = Object.assign({}, (options && options.headers) || {}, {
    Authorization: authHeader(s.wpUser, password),
    "X-EMPM-User": s.wpUser,
    "X-EMPM-App-Password": password,
  });
  if (options && options.body && !headers["Content-Type"]) {
    headers["Content-Type"] = "application/json";
  }
  headers["Cache-Control"] = "no-cache";
  headers.Pragma = "no-cache";
  const styles = [];
  if (s.wpRestStyle && REST_STYLES.indexOf(s.wpRestStyle) >= 0) styles.push(s.wpRestStyle);
  REST_STYLES.forEach((st) => {
    if (styles.indexOf(st) < 0) styles.push(st);
  });
  let lastErr = null;
  for (let i = 0; i < styles.length; i += 1) {
    const style = styles[i];
    try {
      const body = await wpFetchUrl(buildRestUrl(s.wpBase, route, style), options, headers);
      if (style !== s.wpRestStyle) {
        await chrome.storage.local.set({ wpRestStyle: style });
      }
      return body;
    } catch (e) {
      lastErr = e;
      if (e && (e.code === "wp_http_401" || e.code === "wp_http_403")) throw e;
    }
  }
  throw lastErr || new Error("WordPress REST failed");
}

function asList(value) {
  if (Array.isArray(value)) return value;
  if (value && typeof value === "object") {
    return Object.keys(value).map((k) => value[k]);
  }
  return [];
}

function unwrapFilters(body) {
  const src = body && body.data && typeof body.data === "object" && !Array.isArray(body.data)
    ? Object.assign({}, body, body.data)
    : body && typeof body === "object"
      ? body
      : {};
  return {
    success: src.success !== false,
    languages: asList(src.languages),
    categories: asList(src.categories),
    networks: asList(src.networks),
    plugin_version: src.plugin_version || "",
    debug: src.debug || null,
    keys: body && typeof body === "object" ? Object.keys(body) : [],
  };
}

async function fetchFilters() {
  const qs = "?empm=" + Date.now();
  const path = "/easy-mcp-post-meta/v1/filters" + qs;
  try {
    return unwrapFilters(await wpFetch(path, { method: "GET" }));
  } catch (e) {
    return unwrapFilters(await wpFetch(path, { method: "POST", body: "{}" }));
  }
}

function extractFacebookUrl(url, bodyText) {
  const text = String(bodyText || "");
  let json = null;
  try {
    json = JSON.parse(text);
  } catch (_) {
    json = null;
  }
  if (json && typeof json === "object") {
    if (typeof json.permalink_url === "string" && json.permalink_url.indexOf("facebook.com") !== -1) {
      return json.permalink_url;
    }
    if (typeof json.post_url === "string" && json.post_url.indexOf("facebook.com") !== -1) {
      return json.post_url;
    }
    if (typeof json.id === "string" && json.id.indexOf("_") !== -1) {
      const parts = json.id.split("_");
      if (parts.length === 2 && parts[0] && parts[1]) {
        return "https://www.facebook.com/" + parts[0] + "/posts/" + parts[1];
      }
    }
  }
  const m = text.match(/https?:\/\/(?:www\.)?facebook\.com\/[^\s"'<>]+/i);
  if (m) return m[0];
  if (/facebook\.com/i.test(String(url || ""))) return null;
  return null;
}

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  const reply = (ok, payload) => sendResponse(ok ? { ok: true, result: payload } : { ok: false, error: payload });

  (async () => {
    if (!msg || !msg.type) throwError("Empty message.", "empty_message");

    if (msg.type === "GET_SETTINGS") {
      const s = await getSettings();
      return { wpBase: s.wpBase, wpUser: s.wpUser, hasPassword: !!s.wpAppPassword, fbListenEnabled: s.fbListenEnabled, fbListenUrlPart: s.fbListenUrlPart, networks: NETWORKS };
    }

    if (msg.type === "SAVE_SETTINGS") {
      const next = msg.settings || {};
      const patch = {
        wpBase: normalizeBase(next.wpBase),
        wpUser: String(next.wpUser || ""),
        fbListenEnabled: next.fbListenEnabled !== false,
        fbListenUrlPart: String(next.fbListenUrlPart || "graph.facebook.com"),
      };
      if (typeof next.wpAppPassword === "string" && next.wpAppPassword.trim()) {
        patch.wpAppPassword = next.wpAppPassword.trim();
      }
      await chrome.storage.local.set(patch);
      return { saved: true };
    }

    if (msg.type === "TEST_WP") {
      const ping = await wpFetch("/easy-mcp-post-meta/v1/ping?empm=" + Date.now(), { method: "GET" });
      const filters = await fetchFilters();
      return {
        languages: filters.languages,
        categories: filters.categories,
        networks: filters.networks,
        plugin_version: (ping && ping.plugin_version) || filters.plugin_version || "",
        ping: ping,
        keys: filters.keys,
        debug: filters.debug,
      };
    }

    if (msg.type === "LIST_FILTERS") {
      return fetchFilters();
    }

    if (msg.type === "LIST_POSTS") {
      return wpFetch("/easy-mcp-post-meta/v1/list-posts", {
        method: "POST",
        body: JSON.stringify(msg.query || {}),
      });
    }

    if (msg.type === "GET_POST") {
      const id = Number(msg.post_id || 0);
      if (id <= 0) throwError("post_id required.", "post_id_required");
      return wpFetch("/easy-mcp-post-meta/v1/fields/" + id, { method: "GET" });
    }

    if (msg.type === "SET_SOCIAL") {
      const id = Number(msg.post_id || 0);
      const network = String(msg.network || "facebook");
      const url = String(msg.url || "").trim();
      if (id <= 0) throwError("post_id required.", "post_id_required");
      const social_posts = {};
      social_posts[network] = url;
      return wpFetch("/easy-mcp-post-meta/v1/fields/" + id, {
        method: "POST",
        body: JSON.stringify({ social_posts: social_posts }),
      });
    }

    if (msg.type === "FB_HTTP") {
      const s = await getSettings();
      if (!s.fbListenEnabled) return { ignored: true };
      const part = s.fbListenUrlPart || "graph.facebook.com";
      const url = String(msg.url || "");
      if (part && url.indexOf(part) === -1) return { ignored: true };
      const found = extractFacebookUrl(url, msg.body);
      if (!found) return { ignored: true };
      const sel = await chrome.storage.local.get(["selectedPostId"]);
      const postId = Number(sel.selectedPostId || 0);
      if (postId <= 0) return { found: found, saved: false };
      const saved = await wpFetch("/easy-mcp-post-meta/v1/fields/" + postId, {
        method: "POST",
        body: JSON.stringify({ social_posts: { facebook: found } }),
      });
      return { found: found, saved: true, post: saved };
    }

    throwError("Unknown message: " + msg.type, "unknown_message");
  })()
    .then((result) => reply(true, result))
    .catch((e) => reply(false, e.message || String(e)));

  return true;
});
