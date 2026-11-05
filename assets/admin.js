/* Clikgate settings screen: device sign-in, "Send a test", refresh. No keys pass through this script. */
(function () {
  "use strict";
  var cfg = window.clikgateAdmin;
  if (!cfg) return;
  var $ = function (id) { return document.getElementById(id); };
  var timer = null;

  function call(action) {
    var body = new URLSearchParams({ action: action, nonce: cfg.nonce });
    return fetch(cfg.ajaxUrl, { method: "POST", credentials: "same-origin", body: body })
      .then(function (r) { return r.json(); })
      .catch(function () { return { success: false, data: {} }; });
  }
  function say(id, text) { var el = $(id); if (el) el.textContent = text || ""; }

  function poll(interval) {
    timer = window.setTimeout(function () {
      call("clikgate_connect_poll").then(function (res) {
        var d = (res && res.data) || {};
        if (d.state === "pending") { say("clikgate-connect-status", cfg.i18n.waiting); poll(d.interval || interval); return; }
        if (d.state === "connected") { say("clikgate-connect-status", cfg.i18n.connected); window.location.reload(); return; }
        $("clikgate-device").hidden = true;
        $("clikgate-connect").disabled = false;
        say("clikgate-connect-status", d.message || cfg.i18n.failed);
      });
    }, Math.max(5, interval) * 1000);
  }

  var connect = $("clikgate-connect");
  if (connect) {
    connect.addEventListener("click", function () {
      connect.disabled = true;
      say("clikgate-connect-status", cfg.i18n.starting);
      call("clikgate_connect_start").then(function (res) {
        var d = (res && res.data) || {};
        if (!res.success) { connect.disabled = false; say("clikgate-connect-status", d.message || cfg.i18n.failed); return; }
        $("clikgate-user-code").textContent = d.user_code;
        $("clikgate-approve").href = d.verification_uri_complete || d.verification_uri;
        $("clikgate-device").hidden = false;
        say("clikgate-connect-status", cfg.i18n.waiting);
        window.open(d.verification_uri_complete || d.verification_uri, "_blank", "noopener");
        poll(d.interval || 5);
      });
    });
    $("clikgate-cancel").addEventListener("click", function () {
      if (timer) window.clearTimeout(timer);
      call("clikgate_connect_cancel");
      $("clikgate-device").hidden = true;
      connect.disabled = false;
      say("clikgate-connect-status", "");
    });
  }

  var test = $("clikgate-test");
  if (test) {
    test.addEventListener("click", function () {
      test.disabled = true;
      call("clikgate_send_test").then(function (res) {
        test.disabled = false;
        var d = (res && res.data) || {};
        say("clikgate-test-status", res.success ? cfg.i18n.testOk + " " + (d.received_at || "") : (d.message || cfg.i18n.failed) + (d.code ? " (" + d.code + ")" : ""));
      });
    });
  }
  var refresh = $("clikgate-refresh");
  if (refresh) {
    refresh.addEventListener("click", function () {
      refresh.disabled = true;
      call("clikgate_refresh").then(function () { window.location.reload(); });
    });
  }
})();
