(function () {
  if (window.__WP_FB_HELPER_HOOK__) return;
  window.__WP_FB_HELPER_HOOK__ = true;

  function emit(url, body) {
    try {
      window.postMessage(
        {
          source: "wp-fb-helper",
          type: "FB_HTTP",
          url: String(url || ""),
          body: String(body || "").slice(0, 200000),
        },
        "*"
      );
    } catch (_) {}
  }

  const origFetch = window.fetch;
  if (typeof origFetch === "function") {
    window.fetch = function () {
      const req = arguments[0];
      const url = typeof req === "string" ? req : req && req.url ? req.url : "";
      return origFetch.apply(this, arguments).then(function (res) {
        try {
          res
            .clone()
            .text()
            .then(function (text) {
              emit(url || (res && res.url), text);
            })
            .catch(function () {});
        } catch (_) {}
        return res;
      });
    };
  }

  const OrigXHR = window.XMLHttpRequest;
  if (OrigXHR) {
    const open = OrigXHR.prototype.open;
    const send = OrigXHR.prototype.send;
    OrigXHR.prototype.open = function (method, url) {
      this.__wpFbUrl = url;
      return open.apply(this, arguments);
    };
    OrigXHR.prototype.send = function () {
      this.addEventListener("load", function () {
        try {
          emit(this.__wpFbUrl || "", this.responseText || "");
        } catch (_) {}
      });
      return send.apply(this, arguments);
    };
  }
})();
