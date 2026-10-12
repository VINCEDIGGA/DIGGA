(function () {
  const params = new URLSearchParams(window.location.search);
  const caseId = params.get("case") || "";
  const stepIndex = Number.parseInt(params.get("step") || "0", 10);
  const checkIndex = Number.parseInt(params.get("check") || "0", 10);
  const stepTitle = params.get("stepTitle") || "Anslutning Workflow";
  const checkText = params.get("checkText") || "Underlag";

  const guideLink = document.getElementById("guide-link");
  const underlagLink = document.getElementById("underlag-link");
  const workshopLink = document.getElementById("workshop-link");
  const backLink = document.getElementById("back-link");
  const contextTitle = document.getElementById("context-title");
  const contextSubtitle = document.getElementById("context-subtitle");
  const form = document.getElementById("context-form");
  const organizationName = document.getElementById("organization-name");
  const organizationNumber = document.getElementById("organization-number");
  const contactName = document.getElementById("contact-name");
  const contactEmail = document.getElementById("contact-email");
  const contactRole = document.getElementById("contact-role");
  const shortDescription = document.getElementById("short-description");
  const needText = document.getElementById("need-text");
  const fileInput = document.getElementById("context-files");
  const fileList = document.getElementById("context-file-list");
  const saveButton = document.getElementById("save-context-btn");
  const askAiButton = document.getElementById("ask-ai-btn");
  const dialog = document.getElementById("context-dialog");
  const dialogText = document.getElementById("context-dialog-text");
  const applicationFields = Array.prototype.slice.call(
    document.querySelectorAll("[data-application-field]")
  );
  const extraSignerKeys = [
    "signer2_name",
    "signer2_email",
    "signer3_name",
    "signer3_email",
  ];

  let selectedFiles = [];
  let storedFiles = [];

  function showInfo(text) {
    dialogText.textContent = text;
    dialog.showModal();
  }

  function guideUrl() {
    return "steg-for-steg.html?case=" + encodeURIComponent(caseId);
  }

  function workshopUrl() {
    return "reformat-workspace.html?case=" + encodeURIComponent(caseId);
  }

  function cleanText(value) {
    return String(value || "").replace(/\s+/g, " ").trim();
  }

  function contextHasContent(context) {
    if (!context) {
      return false;
    }
    const textFields = [
      context.organization_name,
      context.organization_number,
      context.contact_name,
      context.contact_email,
      context.contact_role,
      context.short_description,
      context.need_text,
    ];
    if (textFields.some(cleanText)) {
      return true;
    }
    const application = context.application || {};
    if (Object.keys(application).some(function (key) {
      const value = application[key];
      return value === true || cleanText(value);
    })) {
      return true;
    }
    return Array.isArray(context.files) && context.files.length > 0;
  }

  function setWorkshopEnabled(enabled) {
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

  function formatFileSize(bytes) {
    if (bytes < 1024) {
      return bytes + " B";
    }
    if (bytes < 1024 * 1024) {
      return Math.round(bytes / 1024) + " kB";
    }
    return (bytes / (1024 * 1024)).toFixed(1) + " MB";
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
        resolve(String(reader.result || "").slice(0, 3500));
      };
      reader.onerror = function () {
        resolve("");
      };
      reader.readAsText(file);
    });
  }

  function renderFileList(files) {
    fileList.innerHTML = "";
    files.forEach(function (file) {
      const item = document.createElement("li");
      item.textContent =
        file.name + (file.size ? " · " + formatFileSize(file.size) : "");
      fileList.appendChild(item);
    });
  }

  async function fileSummaries() {
    if (selectedFiles.length === 0) {
      return storedFiles;
    }

    const summaries = [];
    for (const file of selectedFiles) {
      summaries.push({
        name: file.name,
        type: file.type || "okänd filtyp",
        size: file.size,
        preview: await readTextPreview(file),
      });
    }
    return summaries;
  }

  function applicationPayload() {
    const payload = applicationFields.reduce(function (payload, field) {
      const key = field.getAttribute("data-application-field");
      if (!key) {
        return payload;
      }
      if (field.type === "checkbox") {
        payload[key] = field.checked;
      } else {
        payload[key] = field.value.trim();
      }
      return payload;
    }, {});
    if (payload.multiple_signers !== "ja") {
      extraSignerKeys.forEach(function (key) {
        payload[key] = "";
      });
    }
    return payload;
  }

  function formPayload(files) {
    return {
      case_id: caseId,
      step_index: stepIndex,
      check_index: checkIndex,
      step_title: stepTitle,
      check_text: checkText,
      organization_name: organizationName.value.trim(),
      organization_number: organizationNumber.value.trim(),
      contact_name: contactName.value.trim(),
      contact_email: contactEmail.value.trim(),
      contact_role: contactRole.value.trim(),
      short_description: shortDescription.value.trim(),
      need_text: needText.value.trim(),
      application: applicationPayload(),
      files: files,
    };
  }

  async function saveContext() {
    if (!caseId) {
      showInfo("Öppna underlag från en specifik anslutningsresa först.");
      return false;
    }
    saveButton.disabled = true;
    saveButton.textContent = "Sparar...";
    try {
      const payload = formPayload(await fileSummaries());
      await DiggApi.saveStepContext(payload);
      setWorkshopEnabled(contextHasContent(payload));
      showInfo("Underlaget är sparat för den här chatten.");
      return true;
    } catch (error) {
      showInfo(error.message);
      return false;
    } finally {
      saveButton.disabled = false;
      saveButton.textContent = "Spara underlag";
    }
  }

  function fillContext(context) {
    if (!context) {
      return;
    }
    organizationName.value = context.organization_name || "";
    organizationNumber.value = context.organization_number || "";
    contactName.value = context.contact_name || "";
    contactEmail.value = context.contact_email || "";
    contactRole.value = context.contact_role || "";
    shortDescription.value = context.short_description || "";
    needText.value = context.need_text || "";
    storedFiles = context.files || [];
    renderFileList(storedFiles);
    const application = context.application || {};
    applicationFields.forEach(function (field) {
      const key = field.getAttribute("data-application-field");
      if (!key || application[key] === undefined || application[key] === null) {
        return;
      }
      if (field.type === "checkbox") {
        field.checked = Boolean(application[key]);
      } else {
        field.value = application[key];
      }
      field.dispatchEvent(new Event("change", { bubbles: true }));
    });
    document.dispatchEvent(new CustomEvent("digga:context-loaded"));
    setWorkshopEnabled(contextHasContent(context));
  }

  async function loadContext() {
    if (!caseId) {
      return;
    }
    try {
      fillContext(await DiggApi.getStepContext(caseId, stepIndex, checkIndex));
    } catch (error) {
      console.warn("Kunde inte läsa tidigare underlag", error);
    }
  }

  async function askAiAboutContext() {
    const saved = await saveContext();
    if (!saved) {
      return;
    }
    const question =
      'Utvärdera sparat underlag för checklistpunkten "' +
      checkText +
      '" i steget "' +
      stepTitle +
      '". Vad saknas, vad bör kommunen göra härnäst, och vilka frågor ska skickas till leverantör eller DIGG?';
    const target =
      "../index.html?case=" +
      encodeURIComponent(caseId) +
      "&guideQuestion=" +
      encodeURIComponent(question);
    window.location.href = target;
  }

  contextTitle.textContent = checkText;
  contextSubtitle.textContent = stepTitle;
  if (caseId) {
    guideLink.href = guideUrl();
    backLink.href = guideUrl();
    underlagLink.href = "steg-underlag.html?case=" + encodeURIComponent(caseId);
    setWorkshopEnabled(false);
  }

  if (workshopLink) {
    workshopLink.addEventListener("click", function (event) {
      if (workshopLink.getAttribute("aria-disabled") === "true") {
        event.preventDefault();
        showInfo("Fyll i och spara underlag innan du öppnar Underlagsverkstad.");
      }
    });
  }

  fileInput.addEventListener("change", function () {
    selectedFiles = Array.prototype.slice.call(fileInput.files || []);
    storedFiles = [];
    renderFileList(selectedFiles);
  });

  form.addEventListener("submit", async function (event) {
    event.preventDefault();
    await saveContext();
  });

  askAiButton.addEventListener("click", askAiAboutContext);

  loadContext();
})();
