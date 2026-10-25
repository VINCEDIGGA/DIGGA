(function () {
  "use strict";
  const root = new URL("../", document.currentScript.src);
  const workshop = new URL("poc/reformat-workspace.html", root);
  const key = "digga-workshop-console-unlock-v1";
  let caseResolver = null;

  function currentCaseId() {
    if (caseResolver) return String(caseResolver() || "");
    const selected = new URL(location.href).searchParams.get("case");
    if (selected) return selected;
    try { return localStorage.getItem("digg-selected-case-v3") || ""; }
    catch (_) { return ""; }
  }

  function isUnlocked(caseId) {
    if (!caseId) return false;
    try { return sessionStorage.getItem(key) === caseId; }
    catch (_) { return false; }
  }

  // A console-only shortcut for the preparation gate. It never creates saved
  // preparation, changes QA results, or bypasses file validation.
  function unlock() {
    let caseId = currentCaseId();
    if (!caseId) {
      const suffix = window.crypto && typeof window.crypto.randomUUID === "function"
        ? window.crypto.randomUUID() : Date.now() + "-" + Math.random().toString(16).slice(2);
      caseId = "workshop-test-" + suffix;
    }
    if (caseId.length > 120) throw new Error("Ärendets ID är för långt för verkstaden.");
    try { sessionStorage.setItem(key, caseId); }
    catch (_) { throw new Error("Testläget behöver sessionslagring i den här fliken."); }

    const target = new URL(workshop);
    const current = new URL(location.href);
    target.searchParams.set("case", caseId);
    for (const name of ["asset", "view"]) {
      if (current.searchParams.has(name)) target.searchParams.set(name, current.searchParams.get(name));
    }
    const content = document.getElementById("workspace-content");
    if (current.pathname !== target.pathname || current.searchParams.get("case") !== caseId || !content || content.hidden) {
      location.assign(target.href);
    }
    return "Underlagsverkstad är upplåst i testläge för detta ärende i den här fliken. Kör lock() för att återställa det vanliga underlagskravet.";
  }

  function lock() {
    sessionStorage.removeItem(key);
    location.reload();
  }

  window.DiggaWorkshopAccess = Object.freeze({
    isUnlocked,
    setCaseResolver: function (resolver) { if (typeof resolver === "function") caseResolver = resolver; }
  });
  window.unlock = unlock;
  window.lock = lock;
})();
