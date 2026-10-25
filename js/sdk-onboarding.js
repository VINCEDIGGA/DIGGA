(function () {
  "use strict";
  const caseId = new URLSearchParams(location.search).get("case") || "";
  const storageKey = "digga-sdk-preparation-v1:" + caseId;
  const libraryLink = document.getElementById("sdk-sources-link");
  if (libraryLink && caseId) libraryLink.href = "sdk-kallor.html?case=" + encodeURIComponent(caseId);
  const base = "https://www.digg.se/saker-digital-kommunikation/";
  const participant = base + "sdk-for-deltagarorganisationer/";
  const connection = participant + "anslut-som-deltagarorganisation/";
  const preparation = participant + "vagledningar-och-rekommendationer-infor-anslutning-till-sdk/forberedelser-infor-anslutning-till-sdk";
  const rules = participant + "anslutningsavtal-regelverk-samt-bilagor/regelverk-for-deltagarorganisationer-inom-sdk";
  const sources = {
    preparation: preparation,
    journey: connection + "anslutningsresa-for-deltagarorganisationer",
    instructions: connection + "instruktioner-for-anslutning-till-sdk",
    checklist: preparation + "/checklistor/checklista-for-teknisk-anslutning-till-sdk-med-helhetsleverantor",
    rules: rules,
    security: rules + "/bilaga-for-it-sakerhet-inom-sdk",
    tests: connection + "testfall-deltagarorganisation-for-federationsgodkannande-sdk",
    addressbook: connection + "sdk-adressbok",
    certificate: connection + "instruktioner-for-anslutning-till-sdk",
    faq: base + "fragor-och-svar-om-sdk/faq-for-deltagarorganisationer",
    glossary: base + "vad-ar-saker-digital-kommunikation/ordlista-for-saker-digital-kommunikation",
    interest: connection + "formular---anslutning-av-deltagarorganisationer-till-sdk"
  };
  const tests = [
    { id: "TF 2.0.1", key: "201", title: "Ta emot meddelande och skicka kvittens", description: "Kontrollera inkommande meddelande, innehåll och kvittens i meddelandetjänsten. Automatiserat test i SDK testklient." },
    { id: "TF 2.0.2", key: "202", title: "Skicka meddelande och ta emot kvittens", description: "Kontrollera utgående meddelande och kvittens. Använd testets ID i ämnesraden." },
    { id: "TF 2.1.2", key: "212", title: "Ta emot stort meddelande i meddelandetjänsten", description: "Kontrollera flera bilagor med en sammanlagd storlek strax under 30 MB och kvittensen." },
    { id: "TF 3.0.1", key: "301", title: "Läsa meddelande i den egna klienten", description: "Kontrollera text, formatering och att bilagor går att öppna i meddelandeklienten." },
    { id: "TF 3.0.2", key: "302", title: "Skicka från klienten via adressboken", description: "Välj er testfunktionsadress från SDK adressbok och kontrollera meddelandet i SDK testklient." },
    { id: "TF 3.1.2", key: "312", title: "Läsa stort meddelande i klienten", description: "Kontrollera innehåll och bilagor strax under 30 MB även i meddelandeklienten. Registreras separat från TF 2.1.2." }
  ];
  const phases = [
    { title: "Förbered organisationen", meta: "Behov, ansvar och vägval", summary: "Välj ett informationsutbyte att börja med. Kartlägg motpart, ansvar, informationssäkerhet och teknisk lösning.", checks: ["Beskriv informationsutbytet och vilka organisationer som deltar.", "Utse verksamhetsansvarig och teknisk kontakt.", "Gå igenom regelverk, informationsklassning och risker.", "Välj meddelandesystem och en godkänd accesspunktsoperatör."], resources: [["Förberedelser · version 1.5", sources.preparation], ["Checklista med helhetsleverantör", sources.checklist], ["Regelverk · version 1.7", sources.rules]] },
    { title: "Förbered SDK QA", meta: "Identitet, roller och certifikat", summary: "Samla anslutningsuppgifter tillsammans med leverantören. DIGG granskar ansökan och ger tillgång till QA. SMP-registrering och publicering av O2O-certifikat är olika aktiviteter.", checks: ["Förbered uppgifter och anmäl intresse till DIGG.", "Bekräfta QA-deltagaridentitet och SMP-registrering med operatören.", "Utse användar-, organisations- och adressadministratör.", "Dokumentera QA-besked från DIGG och tillgång till testklient.", "Bekräfta att publikt O2O-certifikat är publicerat av DIGG.", "Registrera funktionsadresser och koppla dem till testbrevlådor."], resources: [["Anslutningsresa · version 1.5.2", sources.journey], ["Anslutningsinstruktioner · 9 oktober 2026", sources.instructions], ["Adressbok och ansvar", sources.addressbook], ["IT-säkerhetsbilaga", sources.security]] },
    { title: "Dokumentera QA-tester", meta: "Sex testfall och bevis", summary: "Genomför testerna i DIGGs SDK QA med er anslutna lösning. Här dokumenterar ni resultaten. Resultat från en simulering ersätter inte QA-tester.", checks: ["Bekräfta förutsättningar för SDK QA och använd enbart testdata.", "Genomför de sex deltagartesterna och dokumentera resultat.", "Registrera manuella tester i SDK testklient.", "Kontrollera testdatum: resultat sparas i testklienten i sex månader."], resources: [["Officiella testfall · 9 oktober 2026", sources.tests], ["Testklient och anslutningsinstruktioner", sources.instructions]] },
    { title: "Förbered produktion", meta: "Extern ansökan och beslut", summary: "Samla ansökan, avtal och intygande om följsamhet. DIGG beslutar om federationsgodkännande. En färdig checklista i denna PoC är ett förberedelseunderlag.", checks: ["Dokumentera godkända QA-tester och följsamhet till regelverket.", "Fyll i DIGGs aktuella ansökan och avtal från DIGG.", "Ordna behörig företrädare för digital eller analog underskrift.", "Dokumentera DIGGs beslut, produktionsidentitet, SMP och certifikat.", "Validera verksamhetsflödet med en ansluten motpart."], resources: [["Aktuella instruktioner för ansökan och underskrift", sources.instructions], ["Regelverk och styrande bilagor", sources.rules]] },
    { title: "Planera förvaltningen", meta: "Rutiner, certifikat och support", summary: "Bestäm vem som håller adresser, behörigheter och certifikat aktuella. Prenumerera på driftinformation och planera support med leverantören.", checks: ["Utse ägare för funktionsadresser, behörigheter och certifikat.", "Planera certifikatförnyelse och granskning av behörigheter.", "Dokumentera supportvägar och prenumeration på driftinformation.", "Följ förändringar i regelverk och rapportera ändringar till DIGG."], resources: [["Anslutningsresa och löpande förvaltning", sources.journey], ["Frågor och svar", sources.faq], ["SDK ordlista", sources.glossary]] }
  ];
  let applying = false;
  let record = loadLocal();
  let latestContext = null;
  let loaded = false;
  const earlyEdits = new Map();
  const commonFields = {"organization-name":"organization_name","organization-number":"organization_number","contact-name":"contact_name","contact-email":"contact_email","contact-role":"contact_role","short-description":"short_description","need-text":"need_text"};
  let formContext = null;
  const environmentFields = ["participant_identity", "ap_identity", "smp_confirmed", "certificate_published"];
  function loadLocal() {
    try { return JSON.parse(localStorage.getItem(storageKey)) || { application: {}, base: {}, updatedAt: "" }; }
    catch (_) { return { application: {}, base: {}, updatedAt: "" }; }
  }
  function isSdk(application, need) {
    if (application && application.sdk_service) return application.sdk_service === "sdk";
    return /\bsdk\b|säker digital kommunikation/i.test(need || "");
  }
  function field(key, label, type, options, help) {
    const name = "sdk_" + key;
    let control;
    if (type === "select") {
      control = '<select data-application-field="' + name + '">' + options.map(function (option) { return '<option value="' + option[0] + '">' + option[1] + '</option>'; }).join("") + '</select>';
    } else if (type === "textarea") {
      control = '<textarea rows="2" maxlength="500" data-application-field="' + name + '"></textarea>';
    } else {
      control = '<input type="' + (type || "text") + '" maxlength="500" data-application-field="' + name + '"' + (type === "date" ? ' max="' + new Intl.DateTimeFormat("sv-SE", { timeZone: "Europe/Stockholm", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date()) + '"' : '') + ' />';
    }
    return '<label><span>' + label + '</span>' + control + (help ? '<small>' + help + '</small>' : '') + '</label>';
  }
  function check(key, label) { return '<label class="sdk-check"><input type="checkbox" data-application-field="sdk_' + key + '" /><span>' + label + '</span></label>'; }
  function sourceLink(label, url) { return '<a target="_blank" rel="noopener noreferrer" href="' + url + '">' + label + '</a>'; }
  function mountUnderlag() {
    const form = document.getElementById("context-form");
    if (!form) return;
    const panel = document.createElement("section");
    panel.className = "context-panel sdk-section";
    panel.id = "sdk-preparation";
    panel.innerHTML = '<div class="section-heading"><div><p class="eyebrow">Välj anslutningsspår</p><h2>SDK-underlag och testplan</h2></div></div>' +
      '<div class="context-grid">' + field("service", "Vilken tjänst gäller underlaget?", "select", [["ena", "Ena, digital post eller annat spår"], ["sdk", "Säker digital kommunikation (SDK)"]]) + '</div>' +
      '<div id="sdk-specific" hidden><p class="sdk-intro">Förbered kommunens SDK-anslutning och dokumentera tester. Uppgifterna hör till samma ärende. Använd testdata i denna lokala PoC.</p>' +
      '<p id="sdk-save-status" class="sdk-status" role="status"></p><div id="sdk-readiness" class="sdk-readiness" aria-live="polite"></div>' +
      '<details class="sdk-group" open><summary>1. Miljö och teknisk lösning</summary><div class="context-grid">' +
      field("environment", "Miljö för anslutningsuppgifterna", "select", [["qa", "SDK QA"], ["production", "SDK produktion"]]) +
      field("stage", "Var är ni i arbetet?", "select", [["preparation", "Förberedelser"], ["qa", "Anslutning till SDK QA"], ["testing", "QA-tester"], ["production", "Ansökan om produktion"], ["maintenance", "Löpande förvaltning"]]) +
      field("participant_identity", "Deltagaridentitet", "text", null, "Exempel för test: 0203:sdk-qa.testkommun.se. Ska stämma med SMP hos operatören.") +
      field("ap_operator", "Accesspunktsoperatör", "text", null, "Begär exakt organisationsnamn från operatörens AS4-certifikat, fält O.") +
      field("ap_identity", "Accesspunktsidentitet", "text", null, "Begär exakt identitet från AS4-certifikatets fält CN.") +
      field("message_system", "Meddelandesystem – namn") + field("message_version", "Meddelandesystem – version") + field("message_id", "Meddelandesystem-ID") +
      field("supplier", "Leverantör eller intern systemansvarig") +
      field("function_addresses", "Planerade funktionsadresser", "textarea", null, "Adressera verksamhetsfunktioner, till exempel socialtjänst, och ange ansvarig för bemanning.") +
      '</div><p class="sdk-source">' + sourceLink("Fält och instruktioner hos DIGG · 9 oktober 2026", sources.instructions) + '</p></details>' +
      '<details class="sdk-group"><summary>2. Kontakter, roller och underskrift</summary><div class="context-grid">' +
      field("technical_contact", "Teknisk kontakt – person eller funktion") + field("technical_email", "Teknisk kontakt – e-post", "email") +
      field("user_admin", "Användaradministratör – namn") + field("admin_email", "Användaradministratör – e-post", "email") +
      field("login_method", "Inloggningsmetod", "select", [["", "Ej valt"], ["BankID", "BankID"], ["Freja+", "Freja+"], ["SITHS", "SITHS"], ["Freja OrgID", "Freja OrgID"], ["EFOS", "EFOS"]], "Identitetsattribut lämnas i DIGGs riktiga blankett; lägg inte in personnummer här.") +
      field("organization_admin", "Organisationsadministratör") + field("address_admin", "Adressadministratör") + field("test_user", "Testklientanvändare") +
      field("signature_method", "Planerad underskrift", "select", [["", "Ej valt"], ["digital", "Digital – DIGG skickar underskriftsuppdrag"], ["analog", "Analog – post enligt aktuell instruktion"]]) +
      field("certificate_owner", "Ansvarig för O2O-certifikat och förnyelse") +
      '</div><p class="sdk-source">' + sourceLink("Roller, behörigheter och underskrift", sources.instructions) + '</p></details>' +
      '<details class="sdk-group"><summary>3. Förutsättningar och externa besked</summary><p>Markera bara bekräftade uppgifter. En tom ruta betyder att bekräftelse saknas.</p><div class="sdk-checklist">' +
      check("business_identified", "Verksamhetsflöde, motpart och ansvar är dokumenterade") +
      check("security_reviewed", "Informationsklassning, risker och informationssäkerhetskrav är genomgångna") +
      check("authentication_ready", "Stark autentisering och personliga behörigheter är planerade") +
      check("supplier_verified", "Leverantörens meddelandesystem har klarat relevanta OPEN-TEST-tester") +
      check("ap_qa_connected", "Operatören har bekräftat accesspunkt ansluten till SDK QA") +
      check("smp_confirmed", "Operatören har bekräftat registrering av deltagaridentiteten i SMP för vald miljö") +
      check("certificate_published", "DIGG har bekräftat att publikt O2O-certifikat är publicerat för vald miljö") +
      check("addresses_ready", "Testfunktionsadresser finns i QA-adressboken och är kopplade till brevlådor") +
      check("roles_ready", "Administratörsroller och testklientanvändare är tilldelade i SDK QA") +
      check("test_data_only", "Testdata innehåller inga skarpa personuppgifter") +
      check("qa_approved", "Vi har fått besked från DIGG om anslutning till SDK QA") +
      '</div><div class="context-grid">' + field("qa_decision_date", "Datum för DIGGs QA-besked", "date") + field("qa_decision_ref", "Referens till QA-besked") +
      '</div><div class="sdk-checklist">' + check("production_approved", "Vi har fått DIGGs federationsgodkännande och produktionsbesked") + '</div><div class="context-grid">' +
      field("production_decision_date", "Datum för DIGGs produktionsbesked", "date") + field("production_decision_ref", "Referens till produktionsbesked") + field("blockers", "Hinder, saknade besked eller avvikelser", "textarea") +
      '</div><p class="sdk-source">' + sourceLink("Regelverk v1.7", sources.rules) + ' · ' + sourceLink("Checklista med helhetsleverantör", sources.checklist) + '</p></details>' +
      '<details class="sdk-group"><summary>4. Testprotokoll för de sex QA-testfallen</summary><p>Denna lista dokumenterar ert arbete. Utför och registrera testerna i DIGGs SDK testklient. Resultat där sparas i sex månader. Simulering räknas inte som officiellt QA-resultat.</p><p class="sdk-source">' + sourceLink("Läs fullständiga teststeg och förväntade resultat hos DIGG", sources.tests) + '</p><div id="sdk-test-summary" role="status"></div>' +
      tests.map(function (test) { return '<details class="sdk-test"><summary>' + test.id + ' · ' + test.title + '</summary><p>' + test.description + '</p><div class="context-grid">' +
        field("test_" + test.key + "_mode", "Var utfördes testet?", "select", [["simulation", "Lokal simulering / övning"], ["qa", "DIGGs SDK QA med vår anslutna lösning"]]) +
        field("test_" + test.key + "_result", "Resultat", "select", [["not_started", "Ej genomfört"], ["passed", "Godkänt enligt testfallet"], ["failed", "Underkänt"], ["blocked", "Kan inte genomföras än"]]) +
        field("test_" + test.key + "_date", "Testdatum", "date") + field("test_" + test.key + "_owner", "Utfört av / ansvarig") +
        field("test_" + test.key + "_evidence", "Bevis, test-ID eller resultatfil", "textarea", null, "Spara referens till logg eller protokoll, upp till 500 tecken. Inga skarpa personuppgifter.") +
        '</div>' + check("test_" + test.key + "_registered", "Resultatet är också registrerat i SDK testklient") + '</details>'; }).join("") +
      '</details><div class="sdk-actions"><button type="button" class="btn-secondary" id="sdk-save">Spara SDK-underlag</button><button type="button" class="btn-secondary" id="sdk-export">Exportera förberedelse och testprotokoll</button><a class="btn-secondary" href="sdk-kallor.html?case=' + encodeURIComponent(caseId) + '">SDK-källor och material</a></div>' +
      '<p class="sdk-source">DIGGs anslutningsblanketter och avtal skickas efter kontakt och granskning. Exporten här är ett arbetsunderlag. ' + sourceLink("Öppna det riktiga intresseformuläret", sources.interest) + '.</p></div>';
    form.insertBefore(panel, form.querySelector(".context-panel"));
    ["qa", "production"].forEach(function (environment) {
      environmentFields.forEach(function (key) {
        const hidden = document.createElement("input"); hidden.type = "hidden";
        hidden.dataset.applicationField = "sdk_env_" + environment + "_" + key;
        panel.append(hidden);
      });
    });
    document.getElementById("sdk-save").addEventListener("click", save);
    document.getElementById("sdk-export").addEventListener("click", exportReport);
    form.addEventListener("input", onEdit);
    form.addEventListener("change", onEdit);
    document.addEventListener("digga:context-loaded", function (event) {
      formContext = event.detail || null;
      if (loaded) { fillSdk(record.application); fillCommonIfEmpty(); }
      updateVisibility();
    });
    fillSdk(record.application);
    updateVisibility();
  }
  function fields() { return Array.from(document.querySelectorAll('[data-application-field^="sdk_"]')); }
  function readSdk() {
    const values = fields().reduce(function (values, input) { values[input.dataset.applicationField] = input.type === "checkbox" ? input.checked : input.value.trim(); return values; }, {});
    const environment = values.sdk_environment || "qa";
    environmentFields.forEach(function (key) {
      values["sdk_env_" + environment + "_" + key] = values["sdk_" + key];
    });
    return values;
  }
  function readBase() {
    const values = Object.assign({}, record.base);
    [["organization_name", "organization-name"], ["organization_number", "organization-number"], ["contact_name", "contact-name"], ["contact_email", "contact-email"], ["contact_role", "contact-role"], ["short_description", "short-description"], ["need_text", "need-text"]].forEach(function (pair) {
      const input = document.getElementById(pair[1]);
      if (input) values[pair[0]] = input.value.trim();
    });
    return values;
  }
  function fillSdk(application) {
    applying = true;
    fields().forEach(function (input) {
      const value = application[input.dataset.applicationField];
      if (value !== undefined) {
        if (input.type === "checkbox") input.checked = value === true;
        else input.value = String(value);
      }
    });
    applying = false;
  }
  function fillCommonIfEmpty() {
    const newerSdkDraft = record.application.sdk_service === "sdk" && Date.parse(record.updatedAt || "1970-01-01") > Date.parse(formContext ? formContext.updated_at || formContext.created_at || "1970-01-01" : "1970-01-01");
    [["organization_name", "organization-name"], ["organization_number", "organization-number"], ["contact_name", "contact-name"], ["contact_email", "contact-email"], ["contact_role", "contact-role"], ["short_description", "short-description"], ["need_text", "need-text"]].forEach(function (pair) {
      const input = document.getElementById(pair[1]);
      if (input && (!input.value || newerSdkDraft) && record.base[pair[0]] !== undefined) input.value = record.base[pair[0]];
    });
  }
  function onEdit(event) {
    // Restoration is marked explicitly; autofill and UI integrations may emit synthetic events.
    if (applying || (event.detail && event.detail.restoringContext)) return;
    const target = event.target;
    const sdkKey = target.dataset && target.dataset.applicationField;
    const baseKey = commonFields[target.id];
    if (!(sdkKey && sdkKey.startsWith("sdk_")) && !baseKey) return;
    if (!loaded) {
      earlyEdits.set(sdkKey || target.id, target.type === "checkbox" ? target.checked : target.value);
      return;
    }
    if (event.target.dataset.applicationField === "sdk_environment") {
      const selected = event.target.value;
      const previous = record.application.sdk_environment || "qa";
      environmentFields.forEach(function (key) {
        const input = document.querySelector('[data-application-field="sdk_' + key + '"]');
        const oldStore = document.querySelector('[data-application-field="sdk_env_' + previous + '_' + key + '"]');
        if (oldStore) oldStore.value = input.type === "checkbox" ? String(input.checked) : input.value;
        const value = record.application["sdk_env_" + selected + "_" + key];
        if (input.type === "checkbox") input.checked = value === true || value === "true";
        else input.value = value || "";
      });
    }
    const editedBase = new Set(record.editedBase || []);
    if (baseKey) editedBase.add(baseKey);
    record = { application: readSdk(), base: readBase(), editedBase: Array.from(editedBase), updatedAt: new Date().toISOString() };
    try { if (caseId) localStorage.setItem(storageKey, JSON.stringify(record)); }
    catch (_) { setStatus("Webbläsaren kunde inte spara utkastet. Exportera eller använd Spara SDK-underlag."); }
    updateVisibility();
    setStatus("Utkastet sparas lokalt i det här ärendet. Använd Spara SDK-underlag för att spara till underlaget.");
    document.dispatchEvent(new CustomEvent("digga:sdk-changed", { detail: record.application }));
  }
  function setStatus(message) { const el = document.getElementById("sdk-save-status"); if (el) el.textContent = message; }
  function currentApplication() { return fields().length ? readSdk() : record.application; }
  function updateVisibility() {
    const section = document.getElementById("sdk-specific");
    if (!section) return;
    const sdk = currentApplication().sdk_service === "sdk";
    section.hidden = !sdk;
    const invoice = document.querySelector('[data-application-field="invoice_receiver"]');
    if (invoice) {
      const invoicePanel = invoice.closest(".context-panel");
      invoicePanel.hidden = sdk;
      invoicePanel.querySelectorAll("input, select, textarea, button").forEach(function (input) { input.disabled = sdk; });
    }
    fields().forEach(function (input) { if (input.dataset.applicationField !== "sdk_service") input.disabled = !sdk; });
    if (sdk) renderReadiness();
  }
  function monthsAgoToday() {
    const today = new Intl.DateTimeFormat("sv-SE", { timeZone: "Europe/Stockholm", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
    const parts = today.split("-").map(Number);
    const boundary = new Date(Date.UTC(parts[0], parts[1] - 1 - 6, 1));
    const days = new Date(Date.UTC(boundary.getUTCFullYear(), boundary.getUTCMonth() + 1, 0)).getUTCDate();
    boundary.setUTCDate(Math.min(parts[2], days));
    return { today: today, boundary: boundary.toISOString().slice(0, 10) };
  }
  function testState(application, test) {
    const prefix = "sdk_test_" + test.key + "_";
    if (application[prefix + "result"] !== "passed") return { ready: false, label: application[prefix + "result"] === "failed" ? "Underkänt" : application[prefix + "result"] === "blocked" ? "Hinder kvar" : "Ej genomfört" };
    if (application[prefix + "mode"] !== "qa") return { ready: false, label: "Simulering – räknas inte som QA" };
    const date = application[prefix + "date"] || "";
    const time = monthsAgoToday();
    const parsedDate = new Date(date + "T00:00:00Z");
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !Number.isFinite(parsedDate.getTime()) || parsedDate.toISOString().slice(0, 10) !== date || date > time.today) return { ready: false, label: "Giltigt testdatum saknas" };
    if (date < time.boundary) return { ready: false, label: "Äldre än sex månader – testa igen" };
    if (!application[prefix + "evidence"] || !application[prefix + "owner"]) return { ready: false, label: "Bevis eller ansvarig saknas" };
    if (application[prefix + "registered"] !== true) return { ready: false, label: "Registrering i testklient saknas" };
    return { ready: true, label: "QA-resultat dokumenterat" };
  }
  function readiness(application, organisation) {
    const missing = [];
    [["organization_name", "Organisationsnamn"], ["organization_number", "Organisationsnummer"], ["contact_name", "Ansvarig kontaktperson"], ["contact_email", "Kontaktpersonens e-post"]].forEach(function (entry) { if (!organisation[entry[0]]) missing.push(entry[1]); });
    [["participant_identity", "Deltagaridentitet"], ["ap_operator", "Accesspunktsoperatör"], ["ap_identity", "Accesspunktsidentitet"], ["message_system", "Meddelandesystem"], ["message_version", "Systemversion"], ["message_id", "Meddelandesystem-ID"], ["technical_contact", "Teknisk kontakt"], ["technical_email", "Teknisk e-post"], ["user_admin", "Användaradministratör"], ["admin_email", "Administratörens e-post"], ["login_method", "Inloggningsmetod"]].forEach(function (entry) { if (!application["sdk_" + entry[0]]) missing.push(entry[1]); });
    if (application.sdk_participant_identity && !/^0203:\S+$/.test(application.sdk_participant_identity)) missing.push("Deltagaridentitet behöver kontrolleras: förväntat 0203:identifierare");
    const readyForTests = ["ap_qa_connected", "smp_confirmed", "certificate_published", "addresses_ready", "roles_ready", "test_data_only", "qa_approved"].every(function (key) {
      const environmentSpecific = key === "smp_confirmed" || key === "certificate_published";
      const value = environmentSpecific && application.sdk_environment === "production" ? application["sdk_env_qa_" + key] : application["sdk_" + key];
      return value === true || (environmentSpecific && value === "true");
    }) && Boolean(application.sdk_qa_decision_date && application.sdk_qa_decision_ref);
    const passed = tests.filter(function (test) { return testState(application, test).ready; }).length;
    const productionConfirmed = application.sdk_production_approved === true && Boolean(application.sdk_production_decision_date && application.sdk_production_decision_ref);
    return { missing: missing, readyForTests: readyForTests, passed: passed, productionConfirmed: productionConfirmed, blockers: application.sdk_blockers || "" };
  }
  function renderReadiness() {
    const a = currentApplication();
    const status = readiness(a, readBase());
    const container = document.getElementById("sdk-readiness");
    if (!container) return;
    container.replaceChildren();
    const heading = document.createElement("strong");
    heading.textContent = status.missing.length ? status.missing.length + " uppgifter saknas i förberedelsen" : "Anslutningsuppgifter finns dokumenterade";
    const summary = document.createElement("p");
    summary.textContent = (status.readyForTests ? "QA-förutsättningar markerade som bekräftade. " : "QA-förutsättningar är inte fullt bekräftade. ") + status.passed + " av 6 QA-resultat dokumenterade. " + (status.productionConfirmed ? "Externt produktionsbesked dokumenterat av användaren." : "DIGGs produktionsbesked är inte dokumenterat.");
    container.append(heading, summary);
    const detail = document.createElement("details");
    const title = document.createElement("summary"); title.textContent = "Visa saknade uppgifter och besked";
    detail.append(title);
    const list = document.createElement("ul");
    status.missing.forEach(function (text) { const li = document.createElement("li"); li.textContent = text; list.append(li); });
    if (!status.readyForTests) { const li = document.createElement("li"); li.textContent = "Kontrollera QA-anslutning, SMP, certifikatspublicering, funktionsadresser, roller och testdata i avsnitt 3."; list.append(li); }
    if (status.blockers) { const li = document.createElement("li"); li.textContent = "Dokumenterat hinder: " + status.blockers; list.append(li); }
    detail.append(list); container.append(detail);
    const testSummary = document.getElementById("sdk-test-summary");
    testSummary.textContent = status.passed + " av 6 testfall har aktuellt QA-resultat, datum, ansvarig, bevis och registrering.";
    tests.forEach(function (test) {
      const input = document.querySelector('[data-application-field="sdk_test_' + test.key + '_result"]');
      if (input) {
        let badge = input.closest(".sdk-test").querySelector(".sdk-test-status");
        if (!badge) { badge = document.createElement("p"); badge.className = "sdk-test-status"; input.closest(".sdk-test").append(badge); }
        badge.textContent = testState(a, test).label;
      }
    });
  }
  async function load() {
    if (!caseId || typeof DiggApi === "undefined") { loaded = true; return record; }
    try {
      const contexts = await DiggApi.getStepContexts(caseId);
      const matching = contexts.filter(function (context) { return context.application && context.application.sdk_service; }).sort(function (a, b) { return Date.parse(b.updated_at || b.created_at || 0) - Date.parse(a.updated_at || a.created_at || 0); });
      latestContext = matching[0] || contexts[0] || null;
      const remote = matching[0];
      if (remote && Date.parse(remote.updated_at || remote.created_at || 0) > Date.parse(record.updatedAt || "1970-01-01")) {
        record = { application: Object.fromEntries(Object.entries(remote.application).filter(function (entry) { return entry[0].startsWith("sdk_"); })), base: remote, updatedAt: remote.updated_at || remote.created_at };
      } else if (latestContext && !record.updatedAt) record.base = latestContext;
      if (latestContext) {
        Object.values(commonFields).forEach(function(key) {
          if (!(record.editedBase || []).includes(key) && !record.base[key] && latestContext[key]) record.base[key] = latestContext[key];
        });
      }
      if (!record.application.sdk_service) {
        let need = (record.base.short_description || "") + " " + (record.base.need_text || "");
        try { const store = JSON.parse(localStorage.getItem("digg-temp-frontend-v3")) || {}; const item = (store.cases || []).find(function (candidate) { return candidate.id === caseId; }); need += " " + (item && item.intake ? item.intake.need || "" : ""); } catch (_) { /* no saved intake */ }
        record.application.sdk_service = isSdk({}, need) ? "sdk" : "ena";
      }
    } catch (_) { setStatus("Backend svarade inte. Lokalt SDK-utkast används."); }
    loaded = true;
    fillSdk(record.application);
    fillCommonIfEmpty();
    earlyEdits.forEach(function(value,key) {
      const input = document.querySelector('[data-application-field="' + key + '"]') || document.getElementById(key);
      if (!input) return;
      if (input.type === "checkbox") input.checked = value;
      else input.value = value;
      onEdit({target:input});
    });
    earlyEdits.clear();
    updateVisibility();
    document.dispatchEvent(new CustomEvent("digga:sdk-loaded", { detail: record.application }));
    if (latestContext) document.dispatchEvent(new CustomEvent("digga:context-saved", {detail:Object.assign({}, latestContext, record.base, {case_id:caseId, application:record.application})}));
    return record;
  }
  async function save() {
    if (!caseId) { setStatus("Öppna underlaget från ett ärende först."); return false; }
    const form = document.getElementById("context-form");
    if (form && !form.reportValidity()) return false;
    const button = document.getElementById("sdk-save");
    if (button) button.disabled = true;
    record = { application: readSdk(), base: readBase(), updatedAt: new Date().toISOString() };
    try {
      const payload = Object.assign({}, record.base, { case_id: caseId, step_index: 90, check_index: 0, step_title: "SDK förberedelser och QA", check_text: "SDK-underlag och testprotokoll", application: Object.assign({}, latestContext ? latestContext.application || {} : {}, record.application), files: latestContext ? latestContext.files || [] : [] });
      const result = await DiggApi.saveStepContext(payload);
      localStorage.setItem(storageKey, JSON.stringify(record));
      document.dispatchEvent(new CustomEvent("digga:context-saved", { detail: payload }));
      setStatus(result.local ? "SDK-underlaget har sparats lokalt. Backend är inte tillgänglig." : "SDK-underlaget har sparats för ärendet.");
      return true;
    } catch (error) { setStatus("Kunde inte spara: " + error.message); return false; }
    finally { if (button) button.disabled = false; }
  }
  function reportText(application, organisation) {
    const status = readiness(application, organisation);
    const lines = ["# SDK – förberedelser och testprotokoll", "", "Ärende: " + caseId, "Skapad: " + new Intl.DateTimeFormat("sv-SE", { timeZone: "Europe/Stockholm", dateStyle: "medium", timeStyle: "short" }).format(new Date()), "", "Detta är ett arbetsunderlag från DIGGA PoC. DIGG beslutar om federationsgodkännande.", "", "## Organisation och behov"];
    [["organization_name", "Organisation"], ["organization_number", "Organisationsnummer"], ["contact_name", "Kontaktperson"], ["contact_email", "E-post"], ["short_description", "Beskrivning"], ["need_text", "Behov och osäkerheter"]].forEach(function (pair) { lines.push(pair[1] + ": " + (organisation[pair[0]] || "Ej dokumenterat")); });
    lines.push("", "## Anslutningsuppgifter och bekräftelser");
    fields().filter(function (input) { return input.type !== "hidden" && !input.dataset.applicationField.startsWith("sdk_test_"); }).forEach(function (input) {
      const value = application[input.dataset.applicationField];
      const label = input.closest("label").querySelector("span").textContent;
      lines.push(label + ": " + (typeof value === "boolean" ? value ? "Bekräftat av användaren" : "Ej bekräftat" : value || "Ej dokumenterat"));
    });
    ["qa", "production"].forEach(function (environment) {
      lines.push("", "Miljöspecifika uppgifter: " + environment);
      environmentFields.forEach(function (key) {
        const value = application["sdk_env_" + environment + "_" + key];
        const label = { participant_identity: "Deltagaridentitet", ap_identity: "Accesspunktsidentitet", smp_confirmed: "SMP-registrering", certificate_published: "O2O-certifikat publicerat" }[key];
        lines.push(label + ": " + (value === true || value === "true" ? "Bekräftat av användaren" : value === false || value === "false" ? "Ej bekräftat" : value || "Ej dokumenterat"));
      });
    });
    lines.push("", "## Saknade uppgifter", status.missing.length ? status.missing.map(function (text) { return "- " + text; }).join("\n") : "Anslutningsuppgifter finns dokumenterade.", "QA-förutsättningar: " + (status.readyForTests ? "Markerade som bekräftade" : "Inte fullt bekräftade"), "Produktionsbesked: " + (status.productionConfirmed ? "Dokumenterat av användaren" : "Inte dokumenterat"), "", "## QA-testprotokoll");
    tests.forEach(function (test) {
      const prefix = "sdk_test_" + test.key + "_";
      lines.push("", "### " + test.id + " – " + test.title, "Status: " + testState(application, test).label, "Miljö: " + (application[prefix + "mode"] === "qa" ? "DIGGs SDK QA" : "Simulering"), "Datum: " + (application[prefix + "date"] || "Ej dokumenterat"), "Ansvarig: " + (application[prefix + "owner"] || "Ej dokumenterat"), "Bevis/referens: " + (application[prefix + "evidence"] || "Ej dokumenterat"), "Registrerat i SDK testklient: " + (application[prefix + "registered"] === true ? "Ja, bekräftat av användaren" : "Ej bekräftat"));
    });
    lines.push("", "## Källor", "Kontrollerade 14 oktober 2026. Använd DIGGs aktuella blanketter vid ansökan.");
    Object.entries(sources).forEach(function (entry) { lines.push("- " + entry[0] + ": " + entry[1]); });
    return lines.join("\n");
  }
  function exportReport() {
    const blob = new Blob([reportText(currentApplication(), readBase())], { type: "text/markdown;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a"); link.href = url; link.download = "sdk-underlag-" + (caseId.replace(/[^a-zA-Z0-9_-]/g, "").slice(0, 48) || "utkast") + ".md"; link.click();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }
  function setService(value) {
    record.application.sdk_service = value === "sdk" ? "sdk" : "ena";
    record.updatedAt = new Date().toISOString();
    try { if (caseId) localStorage.setItem(storageKey, JSON.stringify(record)); }
    catch (_) { /* The selected mode still works for this visit. */ }
    fillSdk(record.application);
    updateVisibility();
  }
  window.DiggaSdk = { phases: phases, tests: tests, sources: sources, isSdk: isSdk, readiness: readiness, testState: testState, getApplication: function () { return currentApplication(); }, setService: setService, reportText: reportText };
  mountUnderlag();
  window.DiggaSdk.ready = load();
})();
