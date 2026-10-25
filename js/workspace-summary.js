(function () {
  "use strict";
  const OMITTED = "Innehåll uteslutet ur rapporten";
  const PRIVATE = /-----BEGIN\s+(?:(?:ENCRYPTED|RSA|EC|DSA|OPENSSH)\s+)*PRIVATE\s+KEY-----|private[\s_-]*key/i;
  const CERTIFICATE = /-----BEGIN\s+(?:X509\s+)?CERTIFICATE-----/i;
  const sensitiveKey = key => /private[\s_-]*(?:key|material)|secret|password|lösenord|passphrase|(?:^|_)pem(?:$|_)|certificate[\s_-]*(?:body|content|pem)|access[\s_-]*token/i.test(String(key));
  const escape = value => String(value == null ? "" : value).replace(/[&<>"']/g, character => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[character]));
  function text(value, limit) {
    if (value == null || value === "") return "Ej dokumenterat";
    if (typeof value === "boolean") return value ? "Ja" : "Nej";
    let result = String(value).replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/g, "");
    if (PRIVATE.test(result) || CERTIFICATE.test(result)) return OMITTED;
    const bound = limit || 1200;
    if (result.length > bound) result = result.slice(0, bound) + " … [texten har kortats]";
    return result;
  }
  const safeUrl = value => { try { const url = new URL(String(value)); return /^https?:$/.test(url.protocol) ? url.href : ""; } catch (_) { return ""; } };
  const dateValue = value => {
    if (!value) return null;
    const date = new Date(value);
    return Number.isFinite(date.getTime()) ? date : null;
  };
  const localDate = date => new Intl.DateTimeFormat("sv-SE", { timeZone: "Europe/Stockholm", year: "numeric", month: "2-digit", day: "2-digit" }).format(date);
  const yes = value => value === true;
  function sdkLabel(key, value) {
    const labels = {
      environment: { qa: "SDK QA", production: "SDK produktion" },
      stage: { preparation: "Förberedelser", qa: "Anslutning till SDK QA", testing: "QA-tester", production: "Ansökan om produktion", maintenance: "Löpande förvaltning" }
    };
    return labels[key] && labels[key][value] || value;
  }
  function section(title) { return { title: title, paragraphs: [], rows: [], items: [], trees: [], links: [] }; }
  function row(section, label, value) { section.rows.push({ label: label, value: text(value) }); }
  function boundedTree(label, value, budget, depth) {
    if (budget.left-- <= 0) return { label: text(label, 160), value: "Fler fält har utelämnats. Rapporten visar högst 350 fält." };
    if (sensitiveKey(label)) return { label: "Uteslutet fält", value: OMITTED };
    const node = { label: text(label, 160), value: "", children: [] };
    if (!value || typeof value !== "object") { node.value = text(value); return node; }
    const entries = Object.entries(value);
    node.value = Array.isArray(value) ? entries.length + " poster" : entries.length + " fält";
    if (depth >= 6) { node.notice = "Visningen har kortats vid sex nivåer. Kontrollera källfilen för hela innehållet."; return node; }
    const selected = entries.slice(0, 40);
    for (const entry of selected) {
      if (budget.left <= 0) break;
      node.children.push(boundedTree(Array.isArray(value) ? "Post " + (Number(entry[0]) + 1) : entry[0], entry[1], budget, depth + 1));
    }
    if (node.children.length < entries.length) node.notice = (entries.length - node.children.length) + " ytterligare fält eller poster visas inte. Kontrollera källfilen för hela innehållet.";
    return node;
  }
  function xmlTree(element, budget, depth) {
    if (sensitiveKey(element.localName || element.nodeName)) return { label: "Uteslutet XML-fält", value: OMITTED };
    if (budget.left-- <= 0) return { label: "XML", value: "Fler fält har utelämnats." };
    const node = { label: text(element.nodeName, 160), value: "", children: [] };
    if (depth >= 6) { node.notice = "XML-visningen har kortats vid sex nivåer."; return node; }
    const attributes = Array.from(element.attributes || []);
    attributes.slice(0, 20).forEach(attribute => node.children.push(sensitiveKey(attribute.name) ? { label: "Uteslutet attribut", value: OMITTED } : { label: "@" + text(attribute.name, 160), value: text(attribute.value) }));
    const children = Array.from(element.children || []);
    const directText = Array.from(element.childNodes || []).filter(child => child.nodeType === 3 || child.nodeType === 4).map(child => child.nodeValue).join(" ").trim();
    if (directText) node.value = text(directText);
    for (const child of children.slice(0, 40)) { if (budget.left <= 0) break; node.children.push(xmlTree(child, budget, depth + 1)); }
    if (children.length > node.children.filter(child => !String(child.label).startsWith("@")).length || attributes.length > 20) node.notice = "XML-visningen har kortats. Alla element eller attribut visas inte.";
    return node;
  }
  function sourceSection(source, validation, blocked) {
    const result = section("Aktuell källfil");
    row(result, "Filnamn", source.name || "Ingen fil öppnad");
    row(result, "Format", source.format || "Ej valt");
    if (blocked) { result.paragraphs.push("Källfilen är spärrad eftersom den kan innehålla privat nyckelmaterial. Filinnehåll, utkast och tidigare kontrollresultat visas inte i rapporten."); return result; }
    const content = String(source.content || "").trim();
    if (!content) { result.paragraphs.push("Ingen källfil är öppnad. Sammanställningen visar det sparade ärendeunderlaget."); return result; }
    if (content.length > 600000) { result.paragraphs.push("Källfilen är större än rapportens gräns på 600 000 tecken. Strukturen visas inte."); return result; }
    if (source.format === "certificate" || CERTIFICATE.test(content)) {
      result.paragraphs.push("Certifikatets PEM-innehåll tas inte med. Endast publik metadata från den senaste kontrollen visas.");
      const normalized = validation && validation.normalized;
      const allowed = ["subject", "issuer", "serial_number", "valid_from", "valid_to", "days_remaining", "sha256_fingerprint", "signature_algorithm", "public_key_bits", "key_usage", "stored", "trust_verified"];
      const labels = { subject: "Utfärdat till", issuer: "Utfärdare", serial_number: "Serienummer", valid_from: "Giltigt från", valid_to: "Giltigt till", days_remaining: "Dagar kvar", sha256_fingerprint: "SHA-256-fingeravtryck", signature_algorithm: "Signaturalgoritm", public_key_bits: "Publik nyckellängd", key_usage: "Nyckelanvändning", stored: "Sparat av kontrolltjänsten", trust_verified: "Tillitskedja verifierad" };
      if (normalized && typeof normalized === "object") allowed.forEach(key => { if (normalized[key] !== undefined) result.trees.push(boundedTree(labels[key], normalized[key], { left: 80 }, 0)); });
      else result.paragraphs.push("Kör Kontrollera fil för att läsa certifikatets publika metadata.");
      return result;
    }
    if (source.format === "json" || (source.format === "openapi" && /^[\[{]/.test(content))) {
      try { result.trees.push(boundedTree("Filens strukturerade innehåll", JSON.parse(content), { left: 350 }, 0)); result.paragraphs.push("Läsbar visning av den aktuella JSON-strukturen. Denna visning utför ingen SDK-validering eller fältmappning."); }
      catch (_) { result.paragraphs.push("Ogiltig JSON. Korrigera syntaxen i källfilen innan strukturen kan visas. Ingen giltig struktur har skapats."); }
      return result;
    }
    if (source.format === "xml" || /^</.test(content)) {
      if (/<!\s*(?:DOCTYPE|ENTITY)\b/i.test(content)) { result.paragraphs.push("XML med DTD eller entitetsdeklarationer visas inte. Ta bort deklarationerna innan filen granskas."); return result; }
      try {
        const xml = new DOMParser().parseFromString(content, "application/xml");
        if (!xml.documentElement || xml.getElementsByTagName("parsererror").length || xml.getElementsByTagNameNS("http://www.mozilla.org/newlayout/xml/parsererror.xml", "parsererror").length) throw new Error("XML");
        result.trees.push(xmlTree(xml.documentElement, { left: 350 }, 0));
        result.paragraphs.push("Läsbar XML-struktur. Välformad XML innebär inte att SDK:s XSD- eller Schematron-regler är uppfyllda.");
      } catch (_) { result.paragraphs.push("XML kunde inte läsas. Korrigera syntaxen och kontrollera filen på nytt."); }
      return result;
    }
    if (source.format === "openapi") {
      const version = content.match(/^\s*openapi\s*:\s*["']?([^\s"']+)/m);
      const title = content.match(/^\s+title\s*:\s*(.+)$/m);
      row(result, "OpenAPI-version", version ? version[1] : "Ej identifierad");
      if (title) row(result, "API-titel", title[1]);
      const paths = Array.from(content.matchAll(/^\s+(\/[^\s:]*):\s*$/gm)).map(match => text(match[1]));
      result.items = paths.slice(0, 30).map(value => "Sökväg: " + value);
      if (paths.length > 30) result.paragraphs.push("Sökvägslistan har kortats till 30 poster.");
      result.paragraphs.push("En översikt av YAML-dokumentet visas. Använd Kontrollera fil för den strukturella OpenAPI-kontrollen.");
      return result;
    }
    result.paragraphs.push("Formatet har ingen strukturerad rapportvy. Välj JSON, XML, OpenAPI eller publikt certifikat i verkstaden.");
    return result;
  }
  const testDefinitions = [
    ["201", "TF 2.0.1", "Inkommande meddelande och kvittens"], ["202", "TF 2.0.2", "Utgående meddelande och kvittens"], ["212", "TF 2.1.2", "Stort meddelande i meddelandetjänsten"],
    ["301", "TF 3.0.1", "Läsa i meddelandeklienten"], ["302", "TF 3.0.2", "Skicka från klienten via adressboken"], ["312", "TF 3.1.2", "Stort meddelande i klienten"]
  ];
  function qaState(application, key, now) {
    const prefix = "sdk_test_" + key + "_";
    const outcome = application[prefix + "result"];
    if (outcome !== "passed") return outcome === "failed" ? "Underkänt" : outcome === "blocked" ? "Kan inte genomföras än" : "Ej genomfört";
    if (application[prefix + "mode"] !== "qa") return "Simulering – inget officiellt QA-resultat";
    const date = application[prefix + "date"] || "";
    const parsed = dateValue(date + "T00:00:00Z");
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !parsed || parsed.toISOString().slice(0, 10) !== date || date > localDate(now)) return "Giltigt QA-testdatum saknas";
    const parts = localDate(now).split("-").map(Number);
    const boundary = new Date(Date.UTC(parts[0], parts[1] - 7, 1));
    const last = new Date(Date.UTC(boundary.getUTCFullYear(), boundary.getUTCMonth() + 1, 0)).getUTCDate();
    boundary.setUTCDate(Math.min(parts[2], last));
    if (date < boundary.toISOString().slice(0, 10)) return "Äldre än sex månader – testet behöver göras om";
    if (!application[prefix + "owner"] || !application[prefix + "evidence"]) return "Ansvarig eller bevis saknas";
    if (!yes(application[prefix + "registered"])) return "Registrering i SDK testklient saknas";
    return "QA-resultat dokumenterat av användaren";
  }
  function model(snapshot) {
    snapshot = snapshot || {};
    const now = dateValue(snapshot.now) || new Date();
    const source = snapshot.source || {};
    const blocked = Boolean(source.blocked) || PRIVATE.test(String(source.content || "")) || /\.(?:key|pfx|p12)$/i.test(String(source.name || ""));
    const validation = blocked ? null : snapshot.validation || null;
    const historical = snapshot.jobAssociation === "historical";
    const job = blocked || historical ? null : snapshot.job || null;
    const contexts = (Array.isArray(snapshot.contexts) ? snapshot.contexts : []).filter(context => context && typeof context === "object").slice(0, 100).sort((a, b) => (Date.parse(a.updated_at || a.created_at) || 0) - (Date.parse(b.updated_at || b.created_at) || 0));
    const common = {}, application = {};
    contexts.forEach(context => {
      ["organization_name", "organization_number", "contact_name", "contact_email", "contact_role", "short_description", "need_text"].forEach(key => { if (Object.prototype.hasOwnProperty.call(context, key) && context[key] != null) common[key] = context[key]; });
      if (context.application && typeof context.application === "object") Object.entries(context.application).forEach(entry => { if (!sensitiveKey(entry[0])) application[entry[0]] = entry[1]; });
    });
    const report = { title: "Sammanställning av underlag", caseId: text(snapshot.caseId || "Inget ärende valt", 120), date: new Intl.DateTimeFormat("sv-SE", { timeZone: "Europe/Stockholm", dateStyle: "medium", timeStyle: "short" }).format(now), sections: [] };
    const organisation = section("Ärende och organisation");
    [["organization_name", "Organisation"], ["organization_number", "Organisationsnummer"], ["contact_name", "Kontaktperson"], ["contact_email", "E-post"], ["contact_role", "Roll"], ["short_description", "Beskrivning"], ["need_text", "Behov och osäkerheter"]].forEach(pair => row(organisation, pair[1], common[pair[0]]));
    organisation.paragraphs.push(contexts.length ? contexts.length + " sparade underlag används för ärendet. Senaste dokumenterade uppgifter visas." : "Inga sparade ärendeunderlag finns i denna sammanställning.");
    report.sections.push(organisation);
    const sdk = application.sdk_service ? application.sdk_service === "sdk" : Object.keys(application).some(key => key.startsWith("sdk_test_") && application[key]);
    if (sdk) {
      const technical = section("SDK – tekniska uppgifter och ansvar");
      [["environment", "Miljö"], ["stage", "Skede"], ["participant_identity", "Deltagaridentitet"], ["ap_operator", "Accesspunktsoperatör"], ["ap_identity", "Accesspunktsidentitet"], ["message_system", "Meddelandesystem"], ["message_version", "Systemversion"], ["message_id", "Meddelandesystem-ID"], ["supplier", "Leverantör / intern systemansvarig"], ["function_addresses", "Planerade funktionsadresser"], ["technical_contact", "Teknisk kontakt"], ["technical_email", "Teknisk e-post"], ["user_admin", "Användaradministratör"], ["admin_email", "Administratörens e-post"], ["organization_admin", "Organisationsadministratör"], ["address_admin", "Adressadministratör"], ["test_user", "Testklientanvändare"], ["login_method", "Inloggningsmetod"], ["certificate_owner", "Certifikatansvarig"], ["blockers", "Dokumenterade hinder"]].forEach(pair => row(technical, pair[1], sdkLabel(pair[0], application["sdk_" + pair[0]])));
      [["business_identified", "Verksamhetsflöde och ansvar"], ["security_reviewed", "Informationssäkerhet genomgången"], ["authentication_ready", "Stark autentisering och behörigheter planerade"], ["supplier_verified", "Leverantörens OPEN-TEST bekräftat"], ["ap_qa_connected", "Accesspunkt ansluten till QA"], ["smp_confirmed", "SMP-registrering i vald miljö"], ["certificate_published", "O2O-certifikat publicerat i vald miljö"], ["addresses_ready", "QA-funktionsadresser klara"], ["roles_ready", "QA-roller tilldelade"], ["test_data_only", "Enbart testdata"]].forEach(pair => row(technical, pair[1], yes(application["sdk_" + pair[0]]) ? "Bekräftat av användaren" : "Ej bekräftat"));
      ["qa", "production"].forEach(environment => {
        const label = environment === "qa" ? "SDK QA" : "SDK produktion";
        const identity = application["sdk_env_" + environment + "_participant_identity"];
        if (identity) row(technical, label + " – sparad deltagaridentitet", identity);
      });
      const production = yes(application.sdk_production_approved) && application.sdk_production_decision_date && application.sdk_production_decision_ref;
      row(technical, "Externt produktionsbesked", production ? "Användaren har dokumenterat besked från DIGG: " + text(application.sdk_production_decision_date) + " / " + text(application.sdk_production_decision_ref) : "Besked från DIGG är inte dokumenterat med datum och referens");
      technical.paragraphs.push("Markeringarna återger kommunens sparade uppgifter. Sammanställningen ger inget federationsgodkännande.");
      report.sections.push(technical);
      const qa = section("SDK – de sex QA-testfallen");
      qa.paragraphs.push("Testerna genomförs och registreras i DIGGs SDK testklient. Simuleringar räknas inte som QA. Resultat i testklienten sparas i sex månader.");
      testDefinitions.forEach(definition => {
        const prefix = "sdk_test_" + definition[0] + "_";
        qa.trees.push({ label: definition[1] + " – " + definition[2], value: qaState(application, definition[0], now), children: [
          { label: "Miljö", value: application[prefix + "mode"] === "qa" ? "DIGGs SDK QA" : "Lokal simulering / ej angivet" },
          { label: "Testdatum", value: text(application[prefix + "date"]) }, { label: "Ansvarig", value: text(application[prefix + "owner"]) },
          { label: "Bevis / referens", value: text(application[prefix + "evidence"]) }, { label: "Registrerat i SDK testklient", value: yes(application[prefix + "registered"]) ? "Bekräftat av användaren" : "Ej bekräftat" }
        ] });
      });
      qa.links.push({ label: "DIGGs testfall för deltagarorganisationer", url: "https://www.digg.se/saker-digital-kommunikation/sdk-for-deltagarorganisationer/anslut-som-deltagarorganisation/testfall-deltagarorganisation-for-federationsgodkannande-sdk" });
      report.sections.push(qa);
    }
    report.sections.push(sourceSection(source, validation, blocked));
    const checks = section("Filkontroll av den aktuella källfilen");
    if (!validation) checks.paragraphs.push(blocked ? "Ingen filkontroll redovisas för spärrat innehåll." : "Inget aktuellt kontrollresultat finns. Källfilen kan ha ändrats, eller så har en kontroll ännu inte slutförts. Kör Kontrollera fil för den nuvarande versionen.");
    else {
      checks.paragraphs.push(validation.valid === true ? "Inga fel hittades i de kontroller som utfördes. Läs omfattningen nedan." : "Kontrollen hittade avvikelser eller kunde inte godkänna de utförda kontrollerna.");
      (Array.isArray(validation.checks) ? validation.checks : []).slice(0, 100).forEach(check => {
        const state = /^(pass|passed|ok|valid|success)$/i.test(check.status) ? "Godkänd kontroll" : /^(fail|failed|error|invalid)$/i.test(check.status) ? "Fel" : "Information / ej utförd";
        checks.items.push(state + " – " + text(check.label || check.id, 200) + ": " + text(check.message));
      });
      (Array.isArray(validation.issues) ? validation.issues : []).slice(0, 100).forEach(issue => checks.items.push((issue.severity === "error" ? "Fel" : "Kontrollera") + (issue.path ? " · " + text(issue.path, 300) : "") + ": " + text(typeof issue === "string" ? issue : issue.message) + (issue.rule ? " (regel: " + text(issue.rule, 300) + ")" : "")));
      const coverage = validation.coverage;
      if (coverage && typeof coverage === "object") checks.trees.push(boundedTree("Kontrollens omfattning och undantag", coverage, { left: 100 }, 0));
      else if (coverage) row(checks, "Omfattning", coverage);
      (Array.isArray(validation.source_urls) ? validation.source_urls : []).slice(0, 12).forEach(url => { if (safeUrl(url)) checks.links.push({ label: "Källa till filkontrollen", url: safeUrl(url) }); });
      if ((validation.checks || []).length > 100 || (validation.issues || []).length > 100) checks.paragraphs.push("Kontrollistan har kortats till 100 kontroller och 100 avvikelser.");
    }
    checks.paragraphs.push("Filkontrollen ersätter inte verksamhetstester, informationssäkerhetsgranskning eller DIGGs beslut om anslutning.");
    report.sections.push(checks);
    const draft = section("Aktuellt utkast och mänsklig granskning");
    if (!job) draft.paragraphs.push(blocked ? "Utkast redovisas inte när källfilen är spärrad." : historical ? "Ett sparat utkast har valts. Det ingår inte i rapporten om aktuell källfil. Den aktuella filkontrollen gäller inte det sparade utkastet." : "Inget aktuellt utkast är valt. Skapa eller öppna ett utkast för granskning.");
    else {
      row(draft, "Utkast", job.reformatted_name || job.original_name || job.id);
      row(draft, "Status", ({ draft: "Väntar på mänsklig granskning", approved: "Godkänt av användaren", rejected: "Avvisat av användaren" })[job.status] || "Status behöver kontrolleras");
      row(draft, "Lagring", job.local ? "Lokalt i denna flik" : "Sparat i ärendet");
      row(draft, "Skapat", job.created_at);
      (Array.isArray(job.issues) ? job.issues : []).slice(0, 30).forEach(issue => draft.items.push(text(typeof issue === "string" ? issue : issue.message || "Kontrollera utkastet")));
      draft.paragraphs.push("Godkännande av utkastet är användarens granskningsbeslut. Det ändrar inte källfilens kontrollresultat och innebär inget godkännande från DIGG. Rapporten återger inte utkastets råa filinnehåll.");
    }
    report.sections.push(draft);
    const outstanding = section("Återstår att göra");
    if (blocked) outstanding.items.push("Använd enbart publikt certifikat eller en källfil utan privat nyckelmaterial.");
    else if (!String(source.content || "").trim()) outstanding.items.push("Öppna en källfil för att sammanställa dess innehåll.");
    else if (!validation) outstanding.items.push("Kontrollera den aktuella källfilen. Tidigare resultat gäller inte efter en ändring.");
    else if (validation.valid !== true) outstanding.items.push("Åtgärda avvikelser i källfilen och kör kontrollen igen.");
    if (job && job.status === "draft") outstanding.items.push("Granska utkastets innehåll och jämförelse, och dokumentera ditt beslut.");
    if (job && job.status === "rejected") outstanding.items.push("Korrigera underlaget och skapa ett nytt utkast om arbetet ska fortsätta.");
    if (sdk) {
      const missing = testDefinitions.filter(definition => qaState(application, definition[0], now) !== "QA-resultat dokumenterat av användaren");
      if (missing.length) outstanding.items.push("Komplettera QA-testprotokollet: " + missing.map(definition => definition[1]).join(", ") + ".");
      if (!yes(application.sdk_smp_confirmed)) outstanding.items.push("Bekräfta SMP-registreringen med accesspunktsoperatören för vald miljö.");
      if (!yes(application.sdk_certificate_published)) outstanding.items.push("Bekräfta publicering av O2O-certifikatet med DIGG för vald miljö.");
      if (application.sdk_blockers) outstanding.items.push("Dokumenterade hinder: " + text(application.sdk_blockers));
    }
    if (!outstanding.items.length) outstanding.items.push("Inga ytterligare uppgifter har identifierats av rapportens begränsade sammanställning. Följ kommunens rutiner och de officiella SDK-anvisningarna.");
    report.sections.push(outstanding);
    const order = ["Ärende och organisation", "Aktuell källfil", "Filkontroll av den aktuella källfilen", "Aktuellt utkast och mänsklig granskning", "Återstår att göra", "SDK – tekniska uppgifter och ansvar", "SDK – de sex QA-testfallen"];
    report.sections.sort((left, right) => order.indexOf(left.title) - order.indexOf(right.title));
    return report;
  }
  function treeHtml(node, depth) {
    const detail = node.children && node.children.length;
    const body = (node.value ? '<p class="ws-report-value">' + escape(node.value) + '</p>' : "") + (detail ? '<div class="ws-report-children">' + node.children.map(child => treeHtml(child, depth + 1)).join("") + '</div>' : "") + (node.notice ? '<p class="ws-report-notice">' + escape(node.notice) + '</p>' : "");
    return detail ? '<details class="ws-report-tree"' + (depth < 1 ? " open" : "") + '><summary>' + escape(node.label) + '</summary>' + body + '</details>' : '<div class="ws-report-leaf"><strong>' + escape(node.label) + '</strong>' + body + '</div>';
  }
  function render(snapshot) {
    const report = model(snapshot);
    return '<article class="ws-report" aria-label="Sammanställning av aktuellt underlag"><header class="ws-report-header"><p class="ws-report-kicker">DIGGA · Arbetsunderlag</p><h2>' + escape(report.title) + '</h2><p>Ärende: ' + escape(report.caseId) + ' · ' + escape(report.date) + '</p><p class="ws-report-notice">Sammanställningen skiljer mellan sparat underlag, aktuell filkontroll och användarens granskning av utkast. DIGG beslutar om federationsgodkännande.</p></header>' + report.sections.map(item => '<section class="ws-report-section"><h3>' + escape(item.title) + '</h3>' + item.paragraphs.map(paragraph => '<p>' + escape(paragraph) + '</p>').join("") + (item.rows.length ? '<dl class="ws-report-fields">' + item.rows.map(entry => '<div><dt>' + escape(entry.label) + '</dt><dd>' + escape(entry.value) + '</dd></div>').join("") + '</dl>' : "") + (item.items.length ? '<ul class="ws-report-list">' + item.items.map(entry => '<li>' + escape(entry) + '</li>').join("") + '</ul>' : "") + item.trees.map(node => treeHtml(node, 0)).join("") + (item.links.length ? '<ul class="ws-report-links">' + item.links.map(entry => '<li><a href="' + escape(safeUrl(entry.url)) + '" target="_blank" rel="noopener noreferrer">' + escape(entry.label) + '</a></li>').join("") + '</ul>' : "") + '</section>').join("") + '</article>';
  }
  const winAnsiExtra = { 8364: 128, 8218: 130, 402: 131, 8222: 132, 8230: 133, 8224: 134, 8225: 135, 710: 136, 8240: 137, 352: 138, 8249: 139, 338: 140, 381: 142, 8216: 145, 8217: 146, 8220: 147, 8221: 148, 8226: 149, 8211: 150, 8212: 151, 732: 152, 8482: 153, 353: 154, 8250: 155, 339: 156, 382: 158, 376: 159 };
  function pdfEscape(value) {
    return Array.from(String(value)).map(character => {
      const code = character.codePointAt(0);
      const byte = code >= 32 && code <= 126 || code >= 160 && code <= 255 ? code : winAnsiExtra[code] || 63;
      if (byte < 32 || byte > 126) return "\\" + byte.toString(8).padStart(3, "0");
      const result = String.fromCharCode(byte);
      return /[\\()]/.test(result) ? "\\" + result : result;
    }).join("");
  }
  function width(value, size) {
    let sum = 0;
    for (const character of String(value)) {
      const c = character.normalize("NFD").charAt(0);
      sum += c === " " ? 278 : /[il.,:;'!|]/.test(c) ? 278 : c === "I" ? 278 : /[mw]/.test(c) ? 833 : c === "W" ? 944 : c === "M" ? 833 : /[A-ZÅÄÖ]/.test(c) ? 722 : /[frt()\[\]]/.test(c) ? 389 : 600;
    }
    return sum * size / 1000;
  }
  function wrap(value, size, available) {
    const result = [];
    String(value).split(/\r?\n/).forEach(paragraph => {
      let line = "";
      paragraph.split(/\s+/).filter(Boolean).forEach(word => {
        if (line && width(line + " " + word, size) > available) { result.push(line); line = ""; }
        if (width(word, size) > available) {
          for (const character of word) {
            if (line && width(line + character, size) > available) { result.push(line); line = ""; }
            line += character;
          }
        } else line = line ? line + " " + word : word;
      });
      result.push(line);
    });
    return result;
  }
  function pdfBytes(report) {
    const lines = [];
    function add(value, kind, indent) {
      const size = kind === "title" ? 18 : kind === "heading" ? 13 : kind === "label" ? 11 : 10;
      const left = Math.min(indent || 0, 6) * 10;
      wrap(value, size, 483 - left).forEach((line, index) => lines.push({ text: line, size: size, bold: kind === "title" || kind === "heading" || kind === "label", keepNext: kind === "title" || kind === "heading" || kind === "label", left: left, before: index === 0 && (kind === "title" || kind === "heading") ? 12 : 0 }));
    }
    function tree(node, depth) {
      add(node.label + (node.value ? ": " + node.value : ""), node.children && node.children.length ? "label" : "body", depth);
      (node.children || []).forEach(child => tree(child, depth + 1));
      if (node.notice) add(node.notice, "body", depth);
    }
    add(report.title, "title"); add("Ärende: " + report.caseId, "body"); add("Skapad: " + report.date, "body");
    add("Arbetsunderlag. DIGG beslutar om federationsgodkännande. Filkontroll och användarens granskningsbeslut redovisas separat.", "body");
    report.sections.forEach(item => {
      add(item.title, "heading");
      item.paragraphs.forEach(paragraph => add(paragraph, "body"));
      item.rows.forEach(entry => add(entry.label + ": " + entry.value, "body"));
      item.items.forEach(entry => add("• " + entry, "body"));
      item.trees.forEach(node => tree(node, 0));
      item.links.forEach(entry => { add(entry.label, "label"); add(entry.url, "body"); });
    });
    const pages = []; let page = [], y = 765;
    lines.forEach((line, index) => {
      const leading = line.size + 5;
      let reserve=0;
      if(line.keepNext)for(let next=index+1;next<lines.length&&reserve<120;next++){
        reserve+=lines[next].size+5+lines[next].before;
        if(!lines[next].keepNext)break;
      }
      if (y - leading - line.before - reserve < 65) { pages.push(page); page = []; y = 765; }
      y -= line.before;
      page.push("BT /" + (line.bold ? "F2" : "F1") + " " + line.size + " Tf 56 " + (y - line.size).toFixed(1) + " Td (" + pdfEscape(line.text) + ") Tj ET");
      if (line.left) page[page.length - 1] = page[page.length - 1].replace(" Tf 56 ", " Tf " + (56 + line.left) + " ");
      y -= leading;
    });
    if (page.length || !pages.length) pages.push(page);
    const objects = [];
    const object = value => { objects.push(value); return objects.length; };
    const regular = object("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
    const bold = object("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");
    const pageIds = [];
    pages.forEach((commands, index) => {
      const header = "BT /F2 9 Tf 56 800 Td (" + pdfEscape("DIGGA - Sammanställning") + ") Tj ET\n0.8 G 56 788 m 539 788 l S\n0 G\n";
      const footer = "\n0.8 G 56 52 m 539 52 l S\n0 G\nBT /F1 8 Tf 56 34 Td (" + pdfEscape("Arbetsunderlag · Sida " + (index + 1) + " av " + pages.length + " · " + report.date) + ") Tj ET";
      const stream = header + commands.join("\n") + footer;
      const streamId = object("<< /Length " + stream.length + " >>\nstream\n" + stream + "\nendstream");
      pageIds.push(object("<< /Type /Page /Parent PAGES_ID 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 " + regular + " 0 R /F2 " + bold + " 0 R >> >> /Contents " + streamId + " 0 R >>"));
    });
    const pagesId = object("<< /Type /Pages /Kids [" + pageIds.map(id => id + " 0 R").join(" ") + "] /Count " + pageIds.length + " >>");
    pageIds.forEach(id => { objects[id - 1] = objects[id - 1].replace("PAGES_ID", pagesId); });
    const catalog = object("<< /Type /Catalog /Pages " + pagesId + " 0 R >>");
    let output = "%PDF-1.4\n";
    const offsets = [0];
    objects.forEach((value, index) => { offsets.push(output.length); output += (index + 1) + " 0 obj\n" + value + "\nendobj\n"; });
    const xref = output.length;
    output += "xref\n0 " + (objects.length + 1) + "\n0000000000 65535 f \n" + offsets.slice(1).map(offset => String(offset).padStart(10, "0") + " 00000 n \n").join("") + "trailer\n<< /Size " + (objects.length + 1) + " /Root " + catalog + " 0 R >>\nstartxref\n" + xref + "\n%%EOF\n";
    return Uint8Array.from(output, character => character.charCodeAt(0));
  }
  function downloadPdf(snapshot) {
    const report = model(snapshot);
    const blob = new Blob([pdfBytes(report)], { type: "application/pdf" });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a"); anchor.href = url;
    anchor.download = "digga-sammanstallning-" + String(snapshot && snapshot.caseId || "utkast").replace(/[^a-zA-Z0-9_-]/g, "_").slice(0, 60) + ".pdf";
    document.body.appendChild(anchor); anchor.click(); anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    return blob;
  }
  window.WorkshopSummary = { render: render, downloadPdf: downloadPdf };
})();
