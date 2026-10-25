(function () {
  const params = new URLSearchParams(window.location.search);
  const caseId = params.get("case");
  let STORAGE_KEY =
    "digg-guided-onboarding-v4:" + (caseId ? caseId : "default");
  const CASE_STORAGE_KEY = "digg-temp-frontend-v3";
  const SELECTED_CASE_KEY = "digg-selected-case-v3";

  if (!caseId) {
    const recoveredCaseId = recoverCaseId();
    if (recoveredCaseId) {
      window.location.replace(
        "steg-for-steg.html?case=" + encodeURIComponent(recoveredCaseId)
      );
      return;
    }
    createBlankWorkflowCase();
    return;
  }

  const genericPhases = [
    {
      title: "Förstå behov och välj spår",
      meta: "Behov, underlag och anslutningsspår.",
      summary:
        "Samla kommunens behov, viktigaste underlag och preliminära anslutningsspår i ett gemensamt första steg.",
      checks: [
        "Beskriv verksamhetsflödet med egna ord.",
        "Utse ansvarig person.",
        "Bestäm första målgrupp, mottagare eller datamängd.",
        "Lägg till API-dokumentation för källsystemet.",
        "Lägg till OpenAPI-fil om den finns.",
        "Skriv ner kända fältmappningar.",
        "Välj om flödet är meddelande, post eller API-delning.",
        "Kontrollera vilket ansvar kommunen har.",
        "Kontrollera vad leverantören behöver göra.",
      ],
    },
    {
      title: "Mappa och kontrollera",
      meta: "Källfält, målformat och regler.",
      summary: "Gör ett granskningsbart första utkast innan test.",
      checks: [
        "Lista källfält som måste följa med.",
        "Markera osäkra fält för mänsklig granskning.",
        "Spara antaganden som måste bekräftas.",
      ],
    },
    {
      title: "Testa säkert",
      meta: "Dry-run, QA och spärr mot produktion.",
      summary: "Testa utan skarpa personuppgifter och utan livesändning.",
      checks: [
        "Välj testdata utan skarpa personuppgifter.",
        "Kör första dry-run.",
        "Dokumentera fel, beslut och godkännande.",
      ],
    },
    {
      title: "Förvalta och dela lärdomar",
      meta: "Ägarskap, förbättring och återanvändning.",
      summary: "Säkra att nästa anslutning börjar från bättre kunskap.",
      checks: [
        "Bestäm vem som äger rutinen efter test.",
        "Spara kommunens lärdomar i kunskapsbasen.",
        "Planera nästa flöde eller nästa mottagare.",
      ],
    },
  ];

  let phases = genericPhases;
  let guideMode = "ena";
  const guideTitle = document.getElementById("guide-title");
  const guideLede = document.getElementById("guide-lede");
  const guideContext = document.getElementById("guide-context");
  const guideNeed = document.getElementById("guide-need");
  const guideMaterials = document.getElementById("guide-materials");
  const guideIntake = document.getElementById("guide-intake");
  const guideIntakeForm = document.getElementById("guide-intake-form");
  const needInput = document.getElementById("need-input");
  const materialInput = document.getElementById("material-input");
  const materialList = document.getElementById("material-list");
  const guideLimitNotice = document.getElementById("guide-limit-notice");
  const intakeSubmit = guideIntakeForm.querySelector(".intake-submit");
  const guideInfoDialog = document.getElementById("guide-info-dialog");
  const guideInfoText = document.getElementById("guide-info-text");
  const phaseList = document.getElementById("phase-list");
  const phaseDetail = document.getElementById("phase-detail");
  const phaseCount = document.getElementById("phase-count");
  const readinessScore = document.getElementById("readiness-score");
  const readinessCopy = document.getElementById("readiness-copy");
  const prevStep = document.getElementById("prev-step");
  const nextStep = document.getElementById("next-step");
  const guideLink = document.getElementById("guide-link");
  const underlagLink = document.getElementById("underlag-link");
  const workshopLink = document.getElementById("workshop-link");

  if (new URLSearchParams(window.location.search).has("reset")) {
    localStorage.removeItem(STORAGE_KEY);
  }

  const caseContext = loadCaseContext();
  let savedStepContexts = [];
  let selectedMaterials = [];
  let state = loadState();

  const modeBar = document.createElement("div");
  modeBar.className = "sdk-guide-selector";
  const modeLabel = document.createElement("label");
  modeLabel.htmlFor = "guide-service";
  modeLabel.textContent = "Anslutningsspår";
  const modeSelect = document.createElement("select");
  modeSelect.id = "guide-service";
  [["ena", "Ena, digital post eller API-delning"], ["sdk", "Säker digital kommunikation (SDK)"]].forEach(function (pair) {
    const option = document.createElement("option"); option.value = pair[0]; option.textContent = pair[1]; modeSelect.append(option);
  });
  const sourceLibrary = document.createElement("a");
  sourceLibrary.className = "btn-secondary";
  sourceLibrary.textContent = "SDK-källor och material";
  sourceLibrary.href = "sdk-kallor.html?case=" + encodeURIComponent(caseId);
  modeBar.append(modeLabel, modeSelect, sourceLibrary);
  document.querySelector(".guide-grid").before(modeBar);
  modeSelect.addEventListener("change", function () {
    if (window.DiggaSdk) window.DiggaSdk.setService(modeSelect.value);
    selectMode(modeSelect.value);
  });
  function selectMode(value) {
    const mode = value === "sdk" && window.DiggaSdk ? "sdk" : "ena";
    if (mode !== guideMode) {
      guideMode = mode;
      STORAGE_KEY = (mode === "sdk" ? "digga-sdk-guide-v1:" : "digg-guided-onboarding-v4:") + caseId;
      phases = mode === "sdk" ? window.DiggaSdk.phases : genericPhases;
      state = loadState();
    }
    modeSelect.value = mode;
    sourceLibrary.hidden = mode !== "sdk";
    render();
  }
  if (window.DiggaSdk) {
    window.DiggaSdk.ready.then(function () {
      selectMode(window.DiggaSdk.getApplication().sdk_service);
    });
  }

  if (caseId && guideLink) {
    guideLink.href = "steg-for-steg.html?case=" + encodeURIComponent(caseId);
  }
  if (caseId && underlagLink) {
    underlagLink.href =
      "steg-underlag.html?case=" + encodeURIComponent(caseId);
    underlagLink.addEventListener("click", function (event) {
      event.preventDefault();
      window.location.href = underlagLink.href;
    });
  }

  function workshopUrl() {
    return "reformat-workspace.html?case=" + encodeURIComponent(caseId);
  }

  function setWorkshopEnabled(enabled) {
    enabled = enabled || Boolean(window.DiggaWorkshopAccess && window.DiggaWorkshopAccess.isUnlocked(caseId));
    if (!workshopLink) {
      return;
    }
    workshopLink.href = enabled && caseId ? workshopUrl() : "reformat-workspace.html";
    workshopLink.classList.toggle("is-disabled", !enabled);
    workshopLink.setAttribute("aria-disabled", String(!enabled));
    workshopLink.title = enabled
      ? "Öppna Underlagsverkstad"
      : "Fyll i och spara underlag först";
    workshopLink.tabIndex = enabled ? 0 : -1;
  }

  if (workshopLink) {
    setWorkshopEnabled(false);
    workshopLink.addEventListener("click", function (event) {
      if (workshopLink.getAttribute("aria-disabled") === "true") {
        event.preventDefault();
        showInfo("Fyll i och spara underlag innan du öppnar Underlagsverkstad.");
      }
    });
  }

  function loadCaseContext() {
    if (!caseId) {
      return null;
    }
    try {
      const stored = JSON.parse(localStorage.getItem(CASE_STORAGE_KEY));
      const found =
        stored &&
        stored.cases &&
        stored.cases.find(function (item) {
          return item.id === caseId;
        });
      return found && found.intake ? found.intake : null;
    } catch (error) {
      console.warn("Kunde inte läsa ärendets behov", error);
      return null;
    }
  }

  function recoverCaseId() {
    try {
      const stored = JSON.parse(localStorage.getItem(CASE_STORAGE_KEY));
      const cases = stored && Array.isArray(stored.cases) ? stored.cases : [];
      const ongoing = cases.filter(function (item) {
        return item && !item.archived;
      });
      const remembered = localStorage.getItem(SELECTED_CASE_KEY);
      if (
        remembered &&
        ongoing.some(function (item) {
          return item.id === remembered;
        })
      ) {
        return remembered;
      }
      if (ongoing.length > 0) {
        return ongoing[ongoing.length - 1].id;
      }
    } catch (error) {
      console.warn("Kunde inte återställa guideärende", error);
    }
    return "";
  }

  async function createBlankWorkflowCase() {
    try {
      const result = await DiggApi.createCase("Anslutning Workflow", {
        need: "",
        materials: [],
        createdAt: new Date().toISOString(),
      });
      if (!result.ok) {
        window.location.replace("../index.html");
        return;
      }
      localStorage.setItem(SELECTED_CASE_KEY, result.case.id);
      window.location.replace(
        "steg-for-steg.html?case=" + encodeURIComponent(result.case.id)
      );
    } catch (error) {
      console.warn("Kunde inte skapa anslutningsflöde", error);
      window.location.replace("../index.html");
    }
  }

  function cleanText(value) {
    return String(value || "").replace(/\s+/g, " ").trim();
  }

  function contextTime(context) {
    const value = context.updated_at || context.created_at || "";
    return Date.parse(String(value).replace(" ", "T")) || 0;
  }

  function contextHasContent(context) {
    return DiggApi.hasMeaningfulContext(context);
  }

  function hasSavedUnderlag() {
    return savedStepContexts.some(contextHasContent);
  }

  function contextsWithNeed() {
    return savedStepContexts
      .filter(function (context) {
        return cleanText(context.short_description) || cleanText(context.need_text);
      })
      .sort(function (left, right) {
        return contextTime(right) - contextTime(left);
      });
  }

  function dynamicCaseSummary() {
    const contexts = contextsWithNeed();
    const primary = contexts[0] || null;
    const main = primary
      ? cleanText(primary.short_description) || cleanText(primary.need_text)
      : "";

    const chips = [];
    if (primary) {
      const need = cleanText(primary.need_text);
      if (need && need !== main) {
        chips.push("Behov: " + need);
      }
      if (cleanText(primary.check_text)) {
        chips.push(primary.check_text);
      }
    }

    savedStepContexts.forEach(function (context) {
      (context.files || []).forEach(function (file) {
        if (chips.length < 4 && cleanText(file.name)) {
          chips.push(file.name);
        }
      });
    });

    return { main: main, chips: chips };
  }

  function currentNeedText() {
    return dynamicCaseSummary().main;
  }

  function renderCaseContext() {
    const summary = dynamicCaseSummary();
    if (caseId) {
      guideIntake.hidden = true;
      guideTitle.textContent = guideMode === "sdk" ? "SDK – guidad anslutning" : "Anslutning Workflow";
      guideLede.hidden = true;
    }

    if (!summary.main) {
      guideContext.hidden = true;
      guideNeed.textContent = "";
      guideMaterials.innerHTML = "";
      return;
    }

    guideNeed.textContent = summary.main;
    guideMaterials.innerHTML = "";
    summary.chips.forEach(function (label) {
      const item = document.createElement("li");
      item.textContent = label;
      item.title = label;
      guideMaterials.appendChild(item);
    });
    guideContext.hidden = false;
  }

  async function loadSavedStepContexts() {
    if (!caseId) {
      return;
    }
    try {
      savedStepContexts = await DiggApi.getStepContexts(caseId);
      renderCaseContext();
      setWorkshopEnabled(hasSavedUnderlag());
    } catch (error) {
      console.warn("Kunde inte läsa sparat underlag för guiden", error);
      setWorkshopEnabled(false);
    }
  }

  function showInfo(text) {
    guideInfoText.textContent = text;
    guideInfoDialog.showModal();
  }

  function formatFileSize(bytes) {
    if (bytes < 1024) {
      return bytes + " B";
    }
    if (bytes < 1024 * 1024) {
      return Math.round(bytes / 1024) + " kB";
    }
    return (bytes / (1024 * 1024)).toFixed(1) + " MB";
  }

  function renderSelectedMaterials() {
    materialList.innerHTML = "";
    selectedMaterials.forEach(function (file) {
      const item = document.createElement("li");
      item.textContent = file.name + " · " + formatFileSize(file.size);
      materialList.appendChild(item);
    });
  }

  function isTextMaterial(file) {
    return (
      /^text\//.test(file.type) ||
      /\.(json|ya?ml|txt|md|csv|xml)$/i.test(file.name)
    );
  }

  function readTextPreview(file) {
    return new Promise(function (resolve) {
      if (!isTextMaterial(file)) {
        resolve("");
        return;
      }
      const reader = new FileReader();
      reader.onload = function () {
        resolve(String(reader.result || "").slice(0, 2500));
      };
      reader.onerror = function () {
        resolve("");
      };
      reader.readAsText(file);
    });
  }

  async function materialSummaries() {
    const summaries = [];
    for (const file of selectedMaterials) {
      summaries.push({
        name: file.name,
        type: file.type || "okänd filtyp",
        size: file.size,
        preview: await readTextPreview(file),
      });
    }
    return summaries;
  }

  function buildGuidePrompt(need, materials) {
    const materialLines =
      materials.length === 0
        ? "- Inget extra underlag uppladdat i detta steg."
        : materials
            .map(function (item) {
              let line =
                "- " +
                item.name +
                " (" +
                (item.type || "okänd filtyp") +
                ", " +
                formatFileSize(item.size) +
                ")";
              if (item.preview) {
                line += "\n  Utdrag: " + item.preview.replace(/\s+/g, " ").trim();
              }
              return line;
            })
            .join("\n");

    return (
      "Skapa en specifik anslutningsguide för kommunen utifrån behovet och underlaget.\n\n" +
      "Kommunens behov:\n" +
      need +
      "\n\nUnderlag:\n" +
      materialLines +
      "\n\nSvara kort och praktiskt med:\n" +
      "- rekommenderat anslutningsspår,\n" +
      "- 4-6 konkreta steg,\n" +
      "- vad kommunen behöver samla in,\n" +
      "- vilka frågor som ska skickas till leverantör eller DIGG,\n" +
      "- risker som måste granskas av människa innan test eller produktion."
    );
  }

  function setIntakeSending(isSending) {
    needInput.disabled = isSending;
    materialInput.disabled = isSending;
    intakeSubmit.disabled = isSending;
    intakeSubmit.textContent = isSending ? "Skapar guide..." : "Skapa specifik guide";
  }

  async function startGuideFromNeed() {
    const need = needInput.value.trim();
    if (!need) {
      showInfo("Skriv först vad kommunen behöver ansluta eller lösa.");
      needInput.focus();
      return;
    }

    const ongoing = await DiggApi.getCases();
    if (ongoing.length >= DiggApi.MAX_ONGOING) {
      guideLimitNotice.hidden = false;
      return;
    }

    guideLimitNotice.hidden = true;
    setIntakeSending(true);
    try {
      const materials = await materialSummaries();
      const result = await DiggApi.createCase(need, {
        need: need,
        materials: materials.map(function (item) {
          return {
            name: item.name,
            type: item.type,
            size: item.size,
          };
        }),
        createdAt: new Date().toISOString(),
      });
      if (!result.ok) {
        guideLimitNotice.hidden = false;
        return;
      }

      const prompt = buildGuidePrompt(need, materials);
      await DiggApi.addUserMessage(result.case.id, prompt);
      await DiggApi.addAssistantReply(result.case.id, prompt);
      window.location.href =
        "steg-for-steg.html?case=" + encodeURIComponent(result.case.id);
    } finally {
      setIntakeSending(false);
    }
  }

  function loadState() {
    try {
      const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY));
      if (parsed && Number.isInteger(parsed.active)) {
        return {
          active: Math.max(0, Math.min(parsed.active, phases.length - 1)),
          checked: Object.fromEntries(phases.map(function (phase, index) {
            const items = parsed.checked && Array.isArray(parsed.checked[index]) ? parsed.checked[index] : [];
            return [index, Array.from(new Set(items.filter(function (item) { return Number.isInteger(item) && item >= 0 && item < phase.checks.length; })))];
          })),
        };
      }
    } catch (error) {
      console.warn("Kunde inte läsa guideprogress", error);
    }
    return { active: 0, checked: {} };
  }

  function saveState() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  }

  function checkedFor(index) {
    return state.checked[index] || [];
  }

  function isPhaseDone(index) {
    return checkedFor(index).length === phases[index].checks.length;
  }

  function readiness() {
    const totalChecks = phases.reduce(function (sum, phase) {
      return sum + phase.checks.length;
    }, 0);
    const doneChecks = Object.keys(state.checked).reduce(function (sum, key) {
      return sum + state.checked[key].length;
    }, 0);
    return Math.round((doneChecks / totalChecks) * 100);
  }

  function renderPhaseList() {
    phaseList.innerHTML = "";
    phases.forEach(function (phase, index) {
      const li = document.createElement("li");
      const button = document.createElement("button");
      button.type = "button";
      button.className =
        "phase-item" +
        (index === state.active ? " active" : "") +
        (isPhaseDone(index) ? " done" : "");
      button.innerHTML =
        '<span class="phase-number">' +
        (isPhaseDone(index) ? "✓" : index + 1) +
        '</span><span><span class="phase-title"></span><span class="phase-meta"></span></span>';
      button.querySelector(".phase-title").textContent = phase.title;
      button.querySelector(".phase-meta").textContent = phase.meta;
      button.addEventListener("click", function () {
        state.active = index;
        saveState();
        render();
      });
      li.appendChild(button);
      phaseList.appendChild(li);
    });
  }

  function renderDetail() {
    const phase = phases[state.active];
    const checked = checkedFor(state.active);
    phaseDetail.innerHTML = "";

    const kicker = document.createElement("p");
    kicker.className = "phase-kicker";
    kicker.textContent = "Steg " + (state.active + 1);

    const title = document.createElement("h2");
    title.textContent = phase.title;

    const summary = document.createElement("p");
    summary.className = "phase-summary";
    summary.textContent = phase.summary;

    const intro = document.createElement("div");
    intro.className = "step-intro";
    intro.append(kicker, title, summary);

    const checklistHeading = document.createElement("h3");
    checklistHeading.className = "detail-subheading";
    checklistHeading.textContent = "Klart innan nästa steg";

    const checklist = document.createElement("div");
    checklist.className = "checklist";
    phase.checks.forEach(function (text, index) {
      const row = document.createElement("div");
      row.className = "check-row";
      const input = document.createElement("input");
      const id = "step-" + state.active + "-check-" + index;
      input.id = id;
      input.type = "checkbox";
      input.checked = checked.includes(index);
      input.addEventListener("change", function () {
        const current = new Set(checkedFor(state.active));
        if (input.checked) {
          current.add(index);
        } else {
          current.delete(index);
        }
        state.checked[state.active] = Array.from(current).sort();
        saveState();
        render();
      });
      const label = document.createElement("label");
      label.setAttribute("for", id);
      label.textContent = text;
      const help = document.createElement("button");
      help.type = "button";
      help.className = "check-help";
      help.textContent = "?";
      help.setAttribute(
        "aria-label",
        "Fråga AI vad '" + text + "' betyder"
      );
      help.title = "Fråga AI vad detta betyder";
      help.addEventListener("click", function () {
        const params = new URLSearchParams();
        const currentNeed = currentNeedText();
        const context = currentNeed
          ? " Kommunens behov är: " + currentNeed
          : " Kommunens beskrivning och behov är inte ifyllda än. Förklara generellt och be användaren fylla i Beskrivning och behov innan du ger specifika råd.";
        const question =
          (guideMode === "sdk" ? 'I SDK-guiden: vad menas med checklistpunkten "' : 'I guiden för Ena/digital post/API-delning: vad menas med checklistpunkten "') +
          text +
          '" i steget "' +
          phase.title +
          '"?' +
          context +
          (guideMode === "sdk" ? " Håll svaret till Säker digital kommunikation (SDK) och ange aktuell DIGG-källa." : " Utgå från kommunens valda tjänst och behov.") + " Förklara kort och konkret vad jag ska göra.";
        params.set("guideQuestion", question);
        if (caseId) {
          params.set("case", caseId);
        }
        window.location.href = "../index.html?" + params.toString();
      });
      const span = document.createElement("span");
      span.className = "check-copy";
      span.appendChild(label);
      row.append(input, span, help);
      checklist.appendChild(row);
    });

    phaseDetail.append(intro, checklistHeading, checklist);
    if (guideMode === "sdk") {
      const materials = document.createElement("div"); materials.className = "sdk-phase-resources";
      const heading = document.createElement("h3"); heading.textContent = "Läs och använd i detta steg";
      const list = document.createElement("ul");
      (phase.resources || []).forEach(function (resource) {
        const li = document.createElement("li"); const a = document.createElement("a"); a.textContent = resource[0]; a.href = resource[1]; a.target = "_blank"; a.rel = "noopener noreferrer"; li.append(a); list.append(li);
      });
      const underlag = document.createElement("a"); underlag.className = "btn-secondary"; underlag.href = "steg-underlag.html?case=" + encodeURIComponent(caseId) + "#sdk-preparation";
      underlag.textContent = state.active === 2 ? "Öppna QA-testprotokollet" : "Öppna SDK-underlaget";
      const note = document.createElement("p"); note.className = "sdk-guide-note"; note.textContent = "Rutorna är din arbetschecklista. Verkliga tester och DIGGs beslut dokumenteras i underlaget. DIGG beslutar om federationsgodkännande.";
      materials.append(heading, list, underlag, note); phaseDetail.append(materials);
    }
  }

  function renderReadiness() {
    const score = readiness();
    readinessScore.textContent = score + "%";
    if (guideMode === "sdk") {
      readinessCopy.textContent = "Av din arbetschecklista är markerat klart. Detta är inget anslutningsbesked.";
      return;
    }
    if (score < 35) {
      readinessCopy.textContent = "Starta med första stegen.";
    } else if (score < 75) {
      readinessCopy.textContent = "Fortsätt genom checklistan.";
    } else {
      readinessCopy.textContent = "Nästan redo för användning.";
    }
  }

  function renderButtons() {
    phaseCount.textContent = state.active + 1 + " av " + phases.length;
    prevStep.disabled = state.active === 0;
    nextStep.textContent =
      state.active === phases.length - 1 ? "Till första steget" : "Nästa steg";
  }

  function render() {
    renderCaseContext();
    renderPhaseList();
    renderDetail();
    renderReadiness();
    renderButtons();
  }

  prevStep.addEventListener("click", function () {
    state.active = Math.max(0, state.active - 1);
    saveState();
    render();
  });

  nextStep.addEventListener("click", function () {
    state.active = state.active === phases.length - 1 ? 0 : state.active + 1;
    saveState();
    render();
  });

  materialInput.addEventListener("change", function () {
    selectedMaterials = Array.prototype.slice.call(materialInput.files || []);
    renderSelectedMaterials();
  });

  guideIntakeForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    await startGuideFromNeed();
  });

  render();
  loadSavedStepContexts();
})();
