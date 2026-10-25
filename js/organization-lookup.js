(function () {
  "use strict";
  const name = document.getElementById("organization-name");
  const number = document.getElementById("organization-number");
  const list = document.getElementById("organization-suggestions");
  if (!name || !number || !list) return;
  const root = name.closest(".organization-lookup");
  const status = document.getElementById("organization-lookup-status");
  const source = document.getElementById("organization-lookup-source");
  const replace = document.getElementById("organization-lookup-replace");
  const endpoint = new URL("../backend/api/sdk_addressbook.php", document.currentScript.src);
  const keys = ["id", "name", "number", "participant_identifier", "source_url", "fetched_at", "cached", "stale", "environment"];
  const fields = Object.fromEntries(keys.map(key => [key, document.querySelector('[data-application-field="sdk_directory_' + key + '"]')]));
  let revision = 0, timer, controller, options = [], active = -1, result = null;
  let applying = false, interacted = false, restorationReady = false, lastAutoNumber = null, pendingReplacement = null;
  const digits = value => String(value || "").replace(/[\s-]/g, "");
  const validNumber = value => /^\d{10}$/.test(digits(value));
  const formatNumber = value => digits(value).slice(0, 6) + "-" + digits(value).slice(6);
  const notify = field => { field.dispatchEvent(new Event("input", {bubbles: true})); field.dispatchEvent(new Event("change", {bubbles: true})); };
  function safeSource(value) {
    try { const url = new URL(value); return url.protocol === "https:" && url.hostname === "open-test.digg.se" && url.pathname.startsWith("/addressbook/api/") ? url.href : ""; }
    catch (_) { return ""; }
  }
  function close() {
    list.hidden = true; active = -1;
    name.setAttribute("aria-expanded", "false"); name.removeAttribute("aria-activedescendant");
    list.querySelectorAll('[role="option"]').forEach(item => { item.classList.remove("is-active"); item.setAttribute("aria-selected", "false"); });
  }
  function cancel() {
    const wasPending = name.getAttribute("aria-busy") === "true";
    ++revision; clearTimeout(timer); if (controller) controller.abort(); controller = null;
    name.removeAttribute("aria-busy"); close();
    if (wasPending) status.textContent = "";
  }
  function clearMetadata() {
    // Empty values also record the user's clear intent before saved data arrives.
    keys.forEach(key => { if (fields[key]) fields[key].value = ""; });
    keys.forEach(key => { if (fields[key]) notify(fields[key]); });
  }
  function metadata(org, data, orgNumber) {
    const a = org.attributes;
    const values = {id: org.id, name: a.name, number: orgNumber, participant_identifier: a.participantIdentifier || "", source_url: safeSource(data.source_url), fetched_at: data.fetched_at || "", cached: String(Boolean(data.cached)), stale: String(Boolean(data.stale)), environment: "open-test"};
    keys.forEach(key => { if (fields[key]) fields[key].value = String(values[key]).slice(0, 500); });
    // Each field emits an event so edits made before the SDK draft loads are queued too.
    keys.forEach(key => { if (fields[key]) notify(fields[key]); });
  }
  function provenance(data, participant) {
    source.replaceChildren();
    const url = safeSource(data.source_url);
    if (url) { const anchor = document.createElement("a"); anchor.href = url; anchor.target = "_blank"; anchor.rel = "noopener noreferrer"; anchor.textContent = "Källa: SDK adressbok OPEN-TEST"; source.append(anchor); }
    const when = new Date(data.fetched_at);
    const date = Number.isNaN(when.getTime()) ? "" : " · " + new Intl.DateTimeFormat("sv-SE", {dateStyle: "short", timeStyle: "short"}).format(when);
    const label = data.stale ? "Kunde inte uppdateras. Äldre sparat svar" : data.cached ? "Sparat svar" : "Hämtat från Digg";
    source.append(document.createTextNode((url ? " · " : "") + label + date + "."));
    if (participant) { const info = document.createElement("small"); info.textContent = "Deltagaridentitet i OPEN-TEST: " + participant + ". Kontrollera identiteten separat för QA eller produktion."; source.append(info); }
  }
  function commit(org, data, orgNumber) {
    applying = true;
    number.value = orgNumber; number.setCustomValidity(""); lastAutoNumber = orgNumber;
    metadata(org, data, orgNumber); notify(name); notify(number);
    applying = false;
    pendingReplacement = null; replace.hidden = true;
    status.textContent = "Namn och organisationsnummer har fyllts i från OPEN-TEST (testdata). Kontrollera uppgifterna och spara underlaget när du är klar.";
  }
  function choose(index) {
    const org = options[index], data = result;
    if (!org || !data) return;
    interacted = true; cancel();
    pendingReplacement = null; replace.hidden = true;
    applying = true;
    name.value = org.attributes.name; clearMetadata();
    applying = false;
    const incoming = org.attributes.organizationNumber;
    if (validNumber(incoming)) {
      const formatted = formatNumber(incoming);
      if (!number.value.trim() || number.value === lastAutoNumber || digits(number.value) === digits(formatted)) { commit(org, data, formatted); }
      else {
        applying = true; notify(name); applying = false;
        pendingReplacement = {org, data, number: formatted};
        replace.textContent = "Använd organisationsnummer " + formatted; replace.hidden = false;
        status.textContent = "Namnet har fyllts i. Ditt befintliga organisationsnummer är kvar. Träffens nummer är " + formatted + "; välj knappen om du vill använda det.";
      }
    } else {
      applying = true;
      if (number.value === lastAutoNumber) { number.value = ""; number.setCustomValidity(""); notify(number); }
      lastAutoNumber = null; notify(name); applying = false;
      status.textContent = "Namnet har fyllts i. Träffen saknar ett giltigt organisationsnummer. Kontrollera eller fyll i numret manuellt.";
    }
    provenance(data, org.attributes.participantIdentifier);
    name.focus();
  }
  function highlight(index) {
    if (!options.length || list.hidden) return;
    active = (index + options.length) % options.length;
    list.querySelectorAll('[role="option"]').forEach((item, i) => { item.classList.toggle("is-active", i === active); item.setAttribute("aria-selected", String(i === active)); });
    name.setAttribute("aria-activedescendant", "organization-option-" + active);
    const item = document.getElementById("organization-option-" + active);
    if (item && typeof item.scrollIntoView === "function") item.scrollIntoView({block: "nearest"});
  }
  async function search(q, ticket) {
    controller = new AbortController(); const current = controller;
    const timeout = setTimeout(() => current.abort(), 14000);
    name.setAttribute("aria-busy", "true"); status.textContent = "Söker organisationer i OPEN-TEST…";
    try {
      const url = new URL(endpoint); url.searchParams.set("environment", "open-test"); url.searchParams.set("type", "organizations"); url.searchParams.set("q", q);
      const response = await fetch(url.href, {signal: current.signal, headers: {Accept: "application/json"}});
      const data = await response.json();
      if (ticket !== revision) return;
      if (!response.ok) throw new Error(data.error || "Sökningen kunde inte genomföras.");
      if (data.environment !== "open-test" || !Array.isArray(data.data)) throw new Error("Sökningen gav ett oväntat svar.");
      result = data;
      options = data.data.filter(org => org && typeof org.id === "string" && org.attributes && typeof org.attributes.name === "string" && org.attributes.name.trim()).slice(0, 6);
      list.replaceChildren(); active = -1;
      options.forEach((org, index) => {
        const item = document.createElement("li"); item.id = "organization-option-" + index; item.setAttribute("role", "option"); item.setAttribute("aria-selected", "false");
        const title = document.createElement("strong"); title.textContent = org.attributes.name;
        const detail = document.createElement("span"); detail.textContent = validNumber(org.attributes.organizationNumber) ? formatNumber(org.attributes.organizationNumber) : "Organisationsnummer saknas";
        item.append(title, detail); item.addEventListener("pointerdown", event => event.preventDefault()); item.addEventListener("click", () => choose(index)); list.append(item);
      });
      if (options.length) {
        list.hidden = false; name.setAttribute("aria-expanded", "true");
        status.textContent = "Välj en organisation för att fylla i uppgifter. " + (data.data.length > 6 ? "Sex förslag visas; skriv fler tecken för att förfina sökningen." : options.length + " förslag hittades.");
      } else { close(); status.textContent = "Ingen träff i OPEN-TEST. Du kan fortsätta fylla i organisationen manuellt."; }
      provenance(data);
    } catch (error) {
      if (ticket !== revision) return;
      close(); source.replaceChildren();
      status.textContent = (error.name === "AbortError" ? "Sökningen svarade inte i tid." : error.message) + " Du kan fylla i uppgifterna manuellt.";
    } finally {
      clearTimeout(timeout);
      if (ticket === revision) { name.removeAttribute("aria-busy"); controller = null; }
    }
  }
  name.addEventListener("input", () => {
    if (applying) return;
    interacted = true; cancel(); clearMetadata(); pendingReplacement = null; replace.hidden = true; source.replaceChildren();
    options = []; result = null;
    const q = name.value.trim(), length = Array.from(q).length;
    if (length < 2) { status.textContent = q ? "Skriv minst två tecken för att söka, eller fyll i manuellt." : ""; return; }
    if (length > 100) { status.textContent = "Använd högst 100 tecken för sökförslag, eller fortsätt fylla i manuellt."; return; }
    status.textContent = ""; const ticket = revision; timer = setTimeout(() => search(q, ticket), 350);
  });
  number.addEventListener("input", () => {
    if (applying) return;
    interacted = true; cancel(); lastAutoNumber = null; clearMetadata(); pendingReplacement = null; replace.hidden = true; source.replaceChildren();
    status.textContent = "Uppgifterna har ändrats manuellt. Kontrollera dem innan du sparar.";
  });
  name.addEventListener("keydown", event => {
    if (event.isComposing) return;
    if (event.key === "Escape") { cancel(); status.textContent = "Förslagen har stängts. Du kan fylla i manuellt."; }
    else if (!list.hidden && (event.key === "ArrowDown" || event.key === "ArrowUp")) { event.preventDefault(); highlight(active < 0 ? (event.key === "ArrowDown" ? 0 : options.length - 1) : active + (event.key === "ArrowDown" ? 1 : -1)); }
    else if (!list.hidden && event.key === "Enter" && active >= 0) { event.preventDefault(); choose(active); }
    else if (event.key === "Tab") cancel();
  });
  replace.addEventListener("click", () => { if (!pendingReplacement) return; const pending = pendingReplacement; commit(pending.org, pending.data, pending.number); provenance(pending.data, pending.org.attributes.participantIdentifier); });
  document.addEventListener("pointerdown", event => { if (!root.contains(event.target)) cancel(); });
  name.addEventListener("blur", () => { if (!root.contains(document.activeElement)) cancel(); });
  function restore(beforeSave = false) {
    if (interacted) return;
    const values = Object.fromEntries(keys.map(key => [key, fields[key] ? fields[key].value : ""]));
    if (values.environment !== "open-test" || !values.id || values.name !== name.value || digits(values.number) !== digits(number.value) || !validNumber(values.number)) {
      lastAutoNumber = null; status.textContent = ""; source.replaceChildren();
      if ((restorationReady || beforeSave) && keys.some(key => values[key] !== "")) clearMetadata();
      return;
    }
    lastAutoNumber = number.value;
    status.textContent = "Sparade organisationsuppgifter från OPEN-TEST (testdata). Du kan ändra uppgifterna manuellt.";
    provenance({source_url: values.source_url, fetched_at: values.fetched_at, cached: values.cached === "true", stale: values.stale === "true"}, values.participant_identifier);
  }
  document.addEventListener("digga:context-loaded", () => restore());
  document.addEventListener("digga:sdk-loaded", () => restore());
  const form = document.getElementById("context-form"), sdkSave = document.getElementById("sdk-save");
  if (form) form.addEventListener("submit", () => restore(true), true);
  if (sdkSave) sdkSave.addEventListener("click", () => restore(true), true);
  Promise.allSettled([window.DiggaStepContextReady, window.DiggaSdk && window.DiggaSdk.ready]).then(() => { restorationReady = true; restore(); });
  restore();
})();
