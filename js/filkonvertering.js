(function () {
  const MIN_PANE = 260;
  const STEP_PX = 96;
  const jsonInput = document.getElementById("json-input");
  const jsonGutter = document.getElementById("json-gutter");
  const jsonHighlight = document.getElementById("json-highlight");
  const jsonStatus = document.getElementById("json-status");
  const jsonFileInput = document.getElementById("json-file-input");
  const visualPanel = document.getElementById("json-visual-panel");
  const codePanel = document.getElementById("json-code-panel");
  const tabCode = document.getElementById("tab-code");
  const tabVisual = document.getElementById("tab-visual");
  const diggDocument = document.getElementById("digg-document");
  const divider = document.getElementById("convert-divider");
  const diggPane = document.getElementById("digg-pane");
  const workspace = document.querySelector(".convert-workspace");

  const jsonFileBar = document.getElementById("json-file-bar");
  const jsonFileName = document.getElementById("json-file-name");
  const jsonFileDirty = document.getElementById("json-file-dirty");
  const jsonFileClose = document.getElementById("json-file-close");

  const history = { past: [], committed: jsonInput.value, future: [] };
  let historyTimer = 0;
  let mode = "code";
  let parsedState = { ok: false, empty: true, data: null, error: "" };
  let dragging = false;
  let openFileName = "";
  let savedSnapshot = "";

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

  function showInfo(text) {
    const dialog = document.getElementById("convert-info-dialog");
    const dialogText = document.getElementById("convert-info-text");
    if (!dialog || !dialogText || typeof dialog.showModal !== "function") {
      return;
    }
    dialogText.textContent = text;
    if (!dialog.open) {
      dialog.showModal();
    }
  }

  function wireNav() {
    const params = new URLSearchParams(window.location.search);
    const caseId = params.get("case") || "";
    const suffix = caseId ? "?case=" + encodeURIComponent(caseId) : "";
    const guideLink = document.getElementById("guide-link");
    const underlagLink = document.getElementById("underlag-link");
    const convertLink = document.getElementById("convert-link");
    const workshopLink = document.getElementById("workshop-link");
    if (guideLink) {
      guideLink.href = "steg-for-steg.html" + suffix;
    }
    if (underlagLink) {
      underlagLink.href = "steg-underlag.html" + suffix;
    }
    if (convertLink) {
      convertLink.href = "filkonvertering.html" + suffix;
    }
    if (!workshopLink) {
      return;
    }

    function setWorkshopEnabled(enabled) {
      workshopLink.href =
        enabled && caseId
          ? "reformat-workspace.html?case=" + encodeURIComponent(caseId)
          : "reformat-workspace.html";
      workshopLink.classList.toggle("is-disabled", !enabled);
      workshopLink.setAttribute("aria-disabled", String(!enabled));
      workshopLink.title = enabled
        ? "Öppna Underlagsverkstad"
        : "Fyll i och spara underlag först";
      workshopLink.tabIndex = enabled ? 0 : -1;
    }

    setWorkshopEnabled(false);
    workshopLink.addEventListener("click", function (event) {
      if (workshopLink.getAttribute("aria-disabled") === "true") {
        event.preventDefault();
        showInfo("Fyll i och spara underlag innan du öppnar Underlagsverkstad.");
      }
    });

    if (!caseId || typeof DiggApi === "undefined" || typeof DiggApi.getStepContexts !== "function") {
      return;
    }
    DiggApi.getStepContexts(caseId)
      .then(function (contexts) {
        const saved = Array.isArray(contexts) && contexts.some(contextHasContent);
        setWorkshopEnabled(saved);
      })
      .catch(function () {
        setWorkshopEnabled(false);
      });
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function highlightJson(text) {
    let out = "";
    let i = 0;
    const n = text.length;
    while (i < n) {
      const ch = text[i];
      if (ch === '"') {
        let j = i + 1;
        let escaped = false;
        while (j < n) {
          const c = text[j];
          if (escaped) {
            escaped = false;
          } else if (c === "\\") {
            escaped = true;
          } else if (c === '"') {
            j += 1;
            break;
          }
          j += 1;
        }
        const token = text.slice(i, j);
        let k = j;
        while (k < n && /\s/.test(text[k])) {
          k += 1;
        }
        const kind = text[k] === ":" ? "key" : "string";
        out +=
          '<span class="json-tok-' +
          kind +
          '">' +
          escapeHtml(token) +
          "</span>";
        i = j;
        continue;
      }
      if (/[-0-9]/.test(ch)) {
        const m = text.slice(i).match(/^-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/);
        if (m) {
          out += '<span class="json-tok-number">' + escapeHtml(m[0]) + "</span>";
          i += m[0].length;
          continue;
        }
      }
      if (text.startsWith("true", i) || text.startsWith("null", i)) {
        const word = text.startsWith("true", i) ? "true" : "null";
        const cls = word === "null" ? "null" : "bool";
        out += '<span class="json-tok-' + cls + '">' + word + "</span>";
        i += word.length;
        continue;
      }
      if (text.startsWith("false", i)) {
        out += '<span class="json-tok-bool">false</span>';
        i += 5;
        continue;
      }
      if ("{}[]:,".indexOf(ch) !== -1) {
        out += '<span class="json-tok-punct">' + escapeHtml(ch) + "</span>";
        i += 1;
        continue;
      }
      out += escapeHtml(ch);
      i += 1;
    }
    return out || " ";
  }

  function updateGutter(text) {
    const count = text.split("\n").length;
    const lines = [];
    for (let n = 1; n <= count; n += 1) {
      lines.push(String(n));
    }
    jsonGutter.textContent = lines.join("\n");
  }

  function syncEditorScroll() {
    jsonHighlight.scrollTop = jsonInput.scrollTop;
    jsonHighlight.scrollLeft = jsonInput.scrollLeft;
    jsonGutter.scrollTop = jsonInput.scrollTop;
  }

  function renderEditor(text) {
    jsonHighlight.innerHTML = highlightJson(text);
    jsonInput.style.color = text ? "transparent" : "var(--text)";
    updateGutter(text);
    syncEditorScroll();
  }

  function parseInput(text) {
    const trimmed = text.trim();
    if (!trimmed) {
      return { ok: false, empty: true, data: null, error: "" };
    }
    try {
      return {
        ok: true,
        empty: false,
        data: JSON.parse(trimmed),
        error: "",
      };
    } catch (error) {
      return {
        ok: false,
        empty: false,
        data: null,
        error: error.message || "Ogiltig JSON.",
      };
    }
  }

  function setStatus(parsed) {
    jsonStatus.classList.remove("is-error", "is-ok");
    if (parsed.empty) {
      jsonStatus.textContent = "Ingen JSON ännu. Klistra in eller ladda upp en fil.";
      return;
    }
    if (!parsed.ok) {
      jsonStatus.classList.add("is-error");
      jsonStatus.textContent = "JSON-fel: " + parsed.error;
      return;
    }
    jsonStatus.classList.add("is-ok");
    jsonStatus.textContent = "JSON är syntaktiskt giltig. DIGG-visningen är en prototyp.";
  }

  function prettyValue(value) {
    if (value === null) {
      return "null";
    }
    if (typeof value === "object") {
      return JSON.stringify(value);
    }
    return String(value);
  }

  function isPlainObject(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
  }

  function fieldRows(obj) {
    return Object.keys(obj).map(function (key) {
      const value = obj[key];
      if (isPlainObject(value) || Array.isArray(value)) {
        return { label: key, nested: value };
      }
      return { label: key, value: prettyValue(value) };
    });
  }

  /**
   * Prototype mapping only. Replace with official SDK → DIGG rules or API later.
   */
  function toDiggPreview(data) {
    if (data === null || data === undefined) {
      return { sections: [] };
    }
    if (Array.isArray(data)) {
      return {
        title: "Lista från Kommun SDK-fil",
        lede: "Innehållet är en JSON-array. Visas fältvis tills officiell mappning finns.",
        sections: data.map(function (item, index) {
          if (isPlainObject(item)) {
            return { heading: "Post " + (index + 1), rows: fieldRows(item) };
          }
          return {
            heading: "Post " + (index + 1),
            rows: [{ label: "Värde", value: prettyValue(item) }],
          };
        }),
      };
    }
    if (!isPlainObject(data)) {
      return {
        title: "Kommun SDK-fil",
        lede: "Värdet är inte ett JSON-objekt.",
        sections: [
          { heading: "Innehåll", rows: [{ label: "Värde", value: prettyValue(data) }] },
        ],
      };
    }

    const knownTitle =
      data.titel ||
      data.title ||
      data.namn ||
      data.name ||
      data.organisation ||
      data.organization ||
      "Kommun SDK-fil";
    const sections = [];
    const topFields = {};

    Object.keys(data).forEach(function (key) {
      const value = data[key];
      if (isPlainObject(value)) {
        sections.push({ heading: key, rows: fieldRows(value) });
        return;
      }
      if (Array.isArray(value)) {
        sections.push({
          heading: key,
          list: value.map(function (item) {
            return isPlainObject(item) ? fieldRows(item) : prettyValue(item);
          }),
        });
        return;
      }
      topFields[key] = value;
    });

    if (Object.keys(topFields).length) {
      sections.unshift({ heading: "Översikt", rows: fieldRows(topFields) });
    }

    return {
      title: String(knownTitle),
      lede: "Strukturerad förhandsvisning av JSON-fälten. Inte en officiellt validerad DIGG-fil.",
      sections: sections,
    };
  }

  function renderRows(rows) {
    let html = "<dl class=\"convert-fields\">";
    rows.forEach(function (row) {
      html += "<dt>" + escapeHtml(row.label) + "</dt>";
      if (row.nested !== undefined) {
        html +=
          "<dd><pre>" + escapeHtml(JSON.stringify(row.nested, null, 2)) + "</pre></dd>";
      } else {
        html += "<dd>" + escapeHtml(row.value) + "</dd>";
      }
    });
    html += "</dl>";
    return html;
  }

  function renderDigg(parsed) {
    if (parsed.empty) {
      diggDocument.innerHTML =
        '<p class="convert-doc-empty">Fylls när JSON i Kommun SDK-filen är giltig. Klistra in, redigera eller ladda upp en .json-fil till höger.</p>';
      return;
    }
    if (!parsed.ok) {
      diggDocument.innerHTML =
        '<p class="convert-doc-error">DIGG-dokumentet kan inte visas förrän JSON-syntaxen är giltig. ' +
        escapeHtml(parsed.error) +
        "</p>";
      return;
    }

    const preview = toDiggPreview(parsed.data);
    let html =
      '<p class="convert-doc-banner">Prototypvisning. Officiella DIGG-konverteringsregler är inte kopplade ännu.</p>';
    html += '<p class="convert-doc-kicker">DIGG-fil</p>';
    html += "<h3>" + escapeHtml(preview.title) + "</h3>";
    if (preview.lede) {
      html += "<p>" + escapeHtml(preview.lede) + "</p>";
    }
    preview.sections.forEach(function (section) {
      html += '<section class="convert-section">';
      html += "<h4>" + escapeHtml(section.heading) + "</h4>";
      if (section.rows) {
        html += renderRows(section.rows);
      }
      if (section.list) {
        html += '<ul class="convert-list">';
        section.list.forEach(function (item) {
          if (Array.isArray(item)) {
            html += "<li>" + renderRows(item) + "</li>";
          } else {
            html += "<li>" + escapeHtml(item) + "</li>";
          }
        });
        html += "</ul>";
      }
      html += "</section>";
    });
    diggDocument.innerHTML = html;
  }

  function renderVisualTree(value, parent) {
    if (value === null || typeof value !== "object") {
      const span = document.createElement("span");
      span.className = "convert-tree-value";
      span.textContent = prettyValue(value);
      parent.appendChild(span);
      return;
    }
    const keys = Array.isArray(value)
      ? value.map(function (_, i) {
          return String(i);
        })
      : Object.keys(value);
    keys.forEach(function (key) {
      const item = document.createElement("div");
      item.className = "convert-tree-item";
      const label = document.createElement("span");
      label.className = "convert-tree-key";
      label.textContent = key + ": ";
      item.appendChild(label);
      renderVisualTree(Array.isArray(value) ? value[Number(key)] : value[key], item);
      parent.appendChild(item);
    });
  }

  function renderVisual(parsed) {
    visualPanel.innerHTML = "";
    if (parsed.empty) {
      const p = document.createElement("p");
      p.className = "convert-doc-empty";
      p.textContent = "Visual-läget visar strukturen när JSON är giltig.";
      visualPanel.appendChild(p);
      return;
    }
    if (!parsed.ok) {
      const p = document.createElement("p");
      p.className = "convert-doc-error";
      p.textContent = "Kan inte visa Visual: " + parsed.error;
      visualPanel.appendChild(p);
      return;
    }
    const tree = document.createElement("div");
    tree.className = "convert-tree";
    renderVisualTree(parsed.data, tree);
    visualPanel.appendChild(tree);
  }

  function updateFileTab() {
    const hasText = jsonInput.value.trim().length > 0;
    if (!openFileName && hasText) {
      openFileName = "untitled.json";
    }
    if (!openFileName && !hasText) {
      jsonFileBar.hidden = true;
      jsonFileDirty.hidden = true;
      return;
    }
    jsonFileBar.hidden = false;
    jsonFileName.textContent = openFileName;
    jsonFileDirty.hidden = jsonInput.value === savedSnapshot;
  }

  function closeOpenFile() {
    jsonInput.value = "";
    openFileName = "";
    savedSnapshot = "";
    history.past = [];
    history.committed = "";
    history.future = [];
    if (jsonFileInput) {
      jsonFileInput.value = "";
    }
    refreshFromEditor();
    updateHistoryButtons();
    setMode("code");
  }

  function refreshFromEditor() {
    parsedState = parseInput(jsonInput.value);
    renderEditor(jsonInput.value);
    setStatus(parsedState);
    renderDigg(parsedState);
    updateFileTab();
    if (mode === "visual") {
      renderVisual(parsedState);
    }
  }

  function commitHistory() {
    const next = jsonInput.value;
    if (next === history.committed) {
      updateHistoryButtons();
      return;
    }
    history.past.push(history.committed);
    if (history.past.length > 80) {
      history.past.shift();
    }
    history.committed = next;
    history.future = [];
    updateHistoryButtons();
  }

  function updateHistoryButtons() {
    document.getElementById("undo-btn").disabled = history.past.length === 0;
    document.getElementById("redo-btn").disabled = history.future.length === 0;
  }

  function applyHistory(text) {
    jsonInput.value = text;
    history.committed = text;
    refreshFromEditor();
    updateHistoryButtons();
  }

  function undo() {
    commitHistory();
    if (!history.past.length) {
      return;
    }
    history.future.unshift(history.committed);
    applyHistory(history.past.pop());
  }

  function redo() {
    if (!history.future.length) {
      return;
    }
    history.past.push(history.committed);
    applyHistory(history.future.shift());
  }

  function setMode(next) {
    mode = next;
    const isCode = next === "code";
    tabCode.classList.toggle("is-active", isCode);
    tabVisual.classList.toggle("is-active", !isCode);
    tabCode.setAttribute("aria-selected", isCode ? "true" : "false");
    tabVisual.setAttribute("aria-selected", isCode ? "true" : "false");
    codePanel.hidden = !isCode;
    visualPanel.hidden = isCode;
    if (!isCode) {
      renderVisual(parsedState);
    } else {
      renderEditor(jsonInput.value);
    }
  }

  function workspaceBox() {
    const rect = workspace.getBoundingClientRect();
    const style = getComputedStyle(workspace);
    const padLeft = parseFloat(style.paddingLeft) || 0;
    const padRight = parseFloat(style.paddingRight) || 0;
    return {
      left: rect.left + padLeft,
      width: Math.max(0, rect.width - padLeft - padRight),
    };
  }

  function setLeftWidth(px) {
    const box = workspaceBox();
    const dividerW = divider.getBoundingClientRect().width;
    const max = box.width - dividerW - MIN_PANE;
    const width = Math.max(MIN_PANE, Math.min(max, px));
    diggPane.style.width = width + "px";
    divider.setAttribute(
      "aria-valuenow",
      String(Math.round((width / box.width) * 100))
    );
  }

  function nudge(direction) {
    const current = diggPane.getBoundingClientRect().width;
    setLeftWidth(current + direction * STEP_PX);
  }

  function toWinAnsi(text) {
    const map = {
      Å: "\xC5",
      Ä: "\xC4",
      Ö: "\xD6",
      å: "\xE5",
      ä: "\xE4",
      ö: "\xF6",
      É: "\xC9",
      é: "\xE9",
      Ü: "\xDC",
      ü: "\xFC",
      "–": "-",
      "—": "-",
      "’": "'",
      "“": '"',
      "”": '"',
    };
    return String(text).replace(/[^\x00-\x7E]/g, function (ch) {
      return map[ch] || "?";
    });
  }

  function pdfEscape(text) {
    return toWinAnsi(text)
      .replace(/\\/g, "\\\\")
      .replace(/\(/g, "\\(")
      .replace(/\)/g, "\\)");
  }

  function wrapText(text, max) {
    const words = String(text).split(/\s+/);
    const lines = [];
    let line = "";
    words.forEach(function (word) {
      const next = line ? line + " " + word : word;
      if (next.length > max) {
        if (line) {
          lines.push(line);
        }
        if (word.length > max) {
          for (let i = 0; i < word.length; i += max) {
            lines.push(word.slice(i, i + max));
          }
          line = "";
        } else {
          line = word;
        }
      } else {
        line = next;
      }
    });
    if (line) {
      lines.push(line);
    }
    return lines.length ? lines : [""];
  }

  function collectPdfLines(parsed) {
    const lines = [];
    if (parsed.empty) {
      lines.push({ text: "Ingen JSON att exportera.", size: 11 });
      return lines;
    }
    if (!parsed.ok) {
      lines.push({ text: "Ogiltig JSON: " + parsed.error, size: 11 });
      return lines;
    }
    const preview = toDiggPreview(parsed.data);
    lines.push({ text: "DIGG-fil", size: 11, kicker: true });
    wrapText(preview.title, 78).forEach(function (text) {
      lines.push({ text: text, size: 16, heading: true });
    });
    wrapText(
      "Prototypvisning. Officiella DIGG-konverteringsregler är inte kopplade.",
      86
    ).forEach(function (text) {
      lines.push({ text: text, size: 10 });
    });
    preview.sections.forEach(function (section) {
      lines.push({ text: "", size: 10 });
      wrapText(section.heading, 78).forEach(function (text) {
        lines.push({ text: text, size: 13, heading: true });
      });
      (section.rows || []).forEach(function (row) {
        const value =
          row.nested !== undefined ? JSON.stringify(row.nested) : row.value;
        wrapText(row.label + ": " + value, 86).forEach(function (text) {
          lines.push({ text: text, size: 11 });
        });
      });
      (section.list || []).forEach(function (item) {
        if (Array.isArray(item)) {
          item.forEach(function (row) {
            const value =
              row.nested !== undefined ? JSON.stringify(row.nested) : row.value;
            wrapText("- " + row.label + ": " + value, 86).forEach(function (text) {
              lines.push({ text: text, size: 11 });
            });
          });
        } else {
          wrapText("- " + item, 86).forEach(function (text) {
            lines.push({ text: text, size: 11 });
          });
        }
      });
    });
    return lines;
  }

  function buildPdf(lines) {
    const pageW = 595;
    const pageH = 842;
    const margin = 56;
    const content = [];
    let y = pageH - margin;
    let page = [];

    function flush() {
      content.push(page);
      page = [];
      y = pageH - margin;
    }

    lines.forEach(function (line) {
      const leading = line.size + 6;
      if (y - leading < margin) {
        flush();
      }
      page.push(
        "BT /F1 " +
          line.size +
          " Tf " +
          margin +
          " " +
          (y - line.size) +
          " Td (" +
          pdfEscape(line.text) +
          ") Tj ET"
      );
      y -= leading;
    });
    if (page.length) {
      flush();
    }
    if (!content.length) {
      page.push("BT /F1 11 Tf 56 780 Td (DIGG-fil) Tj ET");
      flush();
    }

    const objects = [];
    function add(raw) {
      objects.push(raw);
      return objects.length;
    }

    const fontId = add("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
    const pageIds = [];
    const streamIds = [];
    content.forEach(function (ops) {
      const stream = ops.join("\n");
      streamIds.push(
        add("<< /Length " + stream.length + " >>\nstream\n" + stream + "\nendstream")
      );
    });
    streamIds.forEach(function (streamId) {
      pageIds.push(
        add(
          "<< /Type /Page /Parent 0 0 R /MediaBox [0 0 " +
            pageW +
            " " +
            pageH +
            "] /Resources << /Font << /F1 " +
            fontId +
            " 0 R >> >> /Contents " +
            streamId +
            " 0 R >>"
        )
      );
    });
    const kids = pageIds
      .map(function (id) {
        return id + " 0 R";
      })
      .join(" ");
    const pagesId = add("<< /Type /Pages /Kids [" + kids + "] /Count " + pageIds.length + " >>");
    objects[pagesId - 1] = objects[pagesId - 1].replace("/Parent 0 0 R", "/Parent " + pagesId + " 0 R");
    pageIds.forEach(function (id) {
      objects[id - 1] = objects[id - 1].replace("/Parent 0 0 R", "/Parent " + pagesId + " 0 R");
    });
    const catalogId = add("<< /Type /Catalog /Pages " + pagesId + " 0 R >>");

    let pdf = "%PDF-1.4\n";
    const offsets = [0];
    objects.forEach(function (body, index) {
      offsets.push(pdf.length);
      pdf += index + 1 + " 0 obj\n" + body + "\nendobj\n";
    });
    const xrefAt = pdf.length;
    pdf += "xref\n0 " + (objects.length + 1) + "\n";
    pdf += "0000000000 65535 f \n";
    offsets.slice(1).forEach(function (off) {
      pdf += String(off).padStart(10, "0") + " 00000 n \n";
    });
    pdf +=
      "trailer\n<< /Size " +
      (objects.length + 1) +
      " /Root " +
      catalogId +
      " 0 R >>\nstartxref\n" +
      xrefAt +
      "\n%%EOF";
    return pdf;
  }

  function downloadPdf() {
    const pdf = buildPdf(collectPdfLines(parsedState));
    const bytes = new Uint8Array(pdf.length);
    for (let i = 0; i < pdf.length; i += 1) {
      bytes[i] = pdf.charCodeAt(i) & 0xff;
    }
    const blob = new Blob([bytes], { type: "application/pdf" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = "digg-fil.pdf";
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  jsonInput.addEventListener("input", function () {
    refreshFromEditor();
    window.clearTimeout(historyTimer);
    historyTimer = window.setTimeout(commitHistory, 350);
  });
  jsonInput.addEventListener("scroll", syncEditorScroll);

  jsonInput.addEventListener("keydown", function (event) {
    const key = event.key.toLowerCase();
    if ((event.ctrlKey || event.metaKey) && key === "z" && !event.shiftKey) {
      event.preventDefault();
      undo();
    } else if (
      (event.ctrlKey || event.metaKey) &&
      (key === "y" || (key === "z" && event.shiftKey))
    ) {
      event.preventDefault();
      redo();
    }
  });

  document.getElementById("undo-btn").addEventListener("click", undo);
  document.getElementById("redo-btn").addEventListener("click", redo);
  tabCode.addEventListener("click", function () {
    setMode("code");
  });
  tabVisual.addEventListener("click", function () {
    setMode("visual");
  });

  document.getElementById("json-reformat-btn").addEventListener("click", function () {
    const parsed = parseInput(jsonInput.value);
    if (!parsed.ok) {
      parsedState = parsed;
      setStatus(parsed);
      renderDigg(parsed);
      return;
    }
    commitHistory();
    jsonInput.value = JSON.stringify(parsed.data, null, 2);
    commitHistory();
    refreshFromEditor();
  });

  document.getElementById("digg-reformat-btn").addEventListener("click", function () {
    refreshFromEditor();
  });

  document.getElementById("digg-pdf-btn").addEventListener("click", downloadPdf);

  jsonFileClose.addEventListener("click", closeOpenFile);

  jsonFileInput.addEventListener("change", function () {
    const file = jsonFileInput.files && jsonFileInput.files[0];
    if (!file) {
      return;
    }
    const reader = new FileReader();
    reader.onload = function () {
      jsonInput.value = String(reader.result || "");
      openFileName = file.name || "untitled.json";
      savedSnapshot = jsonInput.value;
      commitHistory();
      refreshFromEditor();
      setMode("code");
    };
    reader.readAsText(file);
    jsonFileInput.value = "";
  });

  document.getElementById("divider-left-btn").addEventListener("click", function (event) {
    event.stopPropagation();
    nudge(-1);
  });
  document.getElementById("divider-right-btn").addEventListener("click", function (event) {
    event.stopPropagation();
    nudge(1);
  });

  divider.addEventListener("pointerdown", function (event) {
    if (event.target.closest(".convert-divider-btn")) {
      return;
    }
    dragging = true;
    divider.classList.add("is-dragging");
    divider.setPointerCapture(event.pointerId);
  });
  divider.addEventListener("pointermove", function (event) {
    if (!dragging) {
      return;
    }
    setLeftWidth(event.clientX - workspaceBox().left);
  });
  function stopDrag() {
    dragging = false;
    divider.classList.remove("is-dragging");
  }
  divider.addEventListener("pointerup", stopDrag);
  divider.addEventListener("pointercancel", stopDrag);
  divider.addEventListener("keydown", function (event) {
    if (event.key === "ArrowLeft") {
      event.preventDefault();
      nudge(-1);
    }
    if (event.key === "ArrowRight") {
      event.preventDefault();
      nudge(1);
    }
  });

  divider.setAttribute("aria-valuemin", "20");
  divider.setAttribute("aria-valuemax", "80");
  divider.setAttribute("aria-valuenow", "50");

  wireNav();
  updateHistoryButtons();
  refreshFromEditor();
})();
