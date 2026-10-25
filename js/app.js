(function () {
  const newCaseBtn = document.getElementById("new-case-btn");
  const ongoingViewBtn = document.getElementById("ongoing-view-btn");
  const archiveViewBtn = document.getElementById("archive-view-btn");
  const limitNotice = document.getElementById("limit-notice");
  const caseListEl = document.getElementById("case-list");
  const guideLink = document.querySelector(".guide-link");
  const chatView = document.getElementById("chat-view");
  const chatMessages = document.getElementById("chat-messages");
  const chatForm = document.getElementById("chat-form");
  const messageInput = document.getElementById("message-input");
  const sendButton = chatForm.querySelector(".btn-send");
  const infoDialog = document.getElementById("info-dialog");
  const infoText = document.getElementById("info-text");
  const attachBtn = document.getElementById("attach-btn");
  const fileInput = document.getElementById("file-input");
  const attachBar = document.getElementById("attach-bar");
  const attachFileName = document.getElementById("attach-file-name");
  const attachFileSize = document.getElementById("attach-file-size");
  const attachReplaceBtn = document.getElementById("attach-replace-btn");
  const attachRemoveBtn = document.getElementById("attach-remove-btn");
  const SELECTED_CASE_KEY = "digg-selected-case-v3";
  const ATTACHMENT_PREVIEW_LIMIT = 6000;
  const MAX_TEXT_ATTACHMENT_BYTES = 1024 * 1024;

  let cases = [];
  let selectedId = new URLSearchParams(window.location.search).get("case") || localStorage.getItem(SELECTED_CASE_KEY) || null;
  let view = "ongoing";
  let pendingFile = null;

  function resetMenuPosition(menu) {
    menu.style.position = "";
    menu.style.top = "";
    menu.style.left = "";
    menu.style.right = "";
    menu.style.bottom = "";
  }

  function placeMenu(menu, toggle) {
    const gap = 4;
    const pad = 8;
    const toggleRect = toggle.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    let left = toggleRect.right - menuRect.width;
    let top = toggleRect.bottom + gap;
    const maxLeft = window.innerWidth - menuRect.width - pad;
    if (left < pad) {
      left = pad;
    }
    if (left > maxLeft) {
      left = Math.max(pad, maxLeft);
    }
    if (top + menuRect.height > window.innerHeight - pad) {
      top = toggleRect.top - menuRect.height - gap;
    }
    if (top < pad) {
      top = pad;
    }
    menu.style.position = "fixed";
    menu.style.right = "auto";
    menu.style.bottom = "auto";
    menu.style.left = left + "px";
    menu.style.top = top + "px";
  }

  function closeMenus() {
    document.querySelectorAll(".menu.open").forEach(function (menu) {
      menu.classList.remove("open");
      resetMenuPosition(menu);
    });
    document.querySelectorAll(".case-menu-wrap.open").forEach(function (wrap) {
      wrap.classList.remove("open");
    });
    document.querySelectorAll(".menu-toggle").forEach(function (btn) {
      btn.setAttribute("aria-expanded", "false");
    });
  }

  function menuItems(menu) {
    return Array.prototype.slice.call(menu.querySelectorAll("[role='menuitem']"));
  }

  function openCaseMenu(wrap, menu, toggle, itemIndex) {
    closeMenus();
    menu.classList.add("open");
    wrap.classList.add("open");
    toggle.setAttribute("aria-expanded", "true");
    placeMenu(menu, toggle);
    const items = menuItems(menu);
    const index = itemIndex < 0 ? items.length - 1 : itemIndex || 0;
    if (items[index]) {
      items[index].focus();
    }
  }

  function bindMenuKeyboard(toggle, menu, wrap) {
    toggle.addEventListener("keydown", function (event) {
      if (event.key === "ArrowDown") {
        event.preventDefault();
        openCaseMenu(wrap, menu, toggle, 0);
      } else if (event.key === "ArrowUp") {
        event.preventDefault();
        openCaseMenu(wrap, menu, toggle, -1);
      } else if (event.key === "Escape") {
        closeMenus();
      }
    });

    menu.addEventListener("keydown", function (event) {
      const items = menuItems(menu);
      const current = items.indexOf(document.activeElement);
      if (event.key === "Escape") {
        event.preventDefault();
        closeMenus();
        toggle.focus();
      } else if (event.key === "ArrowDown") {
        event.preventDefault();
        items[(current + 1) % items.length].focus();
      } else if (event.key === "ArrowUp") {
        event.preventDefault();
        items[(current - 1 + items.length) % items.length].focus();
      } else if (event.key === "Home") {
        event.preventDefault();
        items[0].focus();
      } else if (event.key === "End") {
        event.preventDefault();
        items[items.length - 1].focus();
      } else if (event.key === "Tab") {
        closeMenus();
      }
    });
  }

  function showInfo(text) {
    infoText.textContent = text;
    infoDialog.showModal();
  }

  function guideHref(caseId) {
    if (!caseId) {
      return "poc/steg-for-steg.html";
    }
    return "poc/steg-for-steg.html?case=" + encodeURIComponent(caseId);
  }

  function ongoingCasesFromStorage() {
    try {
      const stored = JSON.parse(localStorage.getItem(DiggApi.storageKey));
      const storedCases =
        stored && Array.isArray(stored.cases) ? stored.cases : [];
      return storedCases.filter(function (item) {
        return item && !item.archived;
      });
    } catch (error) {
      console.warn("Kunde inte läsa ärenden för guide-länk", error);
      return [];
    }
  }

  function guideCaseId() {
    const ongoing = ongoingCasesFromStorage();
    if (
      selectedId &&
      ongoing.some(function (item) {
        return item.id === selectedId;
      })
    ) {
      return selectedId;
    }

    const remembered = localStorage.getItem(SELECTED_CASE_KEY);
    if (
      remembered &&
      ongoing.some(function (item) {
        return item.id === remembered;
      })
    ) {
      return remembered;
    }

    return ongoing.length > 0 ? ongoing[ongoing.length - 1].id : "";
  }

  function updateGuideLink() {
    if (guideLink) {
      guideLink.href = guideHref(guideCaseId());
    }
    const sourceLink = document.getElementById("sdk-sources-link");
    if (sourceLink) {
      const caseId = guideCaseId();
      sourceLink.href = "poc/sdk-kallor.html" + (caseId ? "?case=" + encodeURIComponent(caseId) : "");
    }
  }

  if (window.DiggaWorkshopAccess) window.DiggaWorkshopAccess.setCaseResolver(guideCaseId);

  function rememberSelectedCase() {
    if (selectedId) {
      localStorage.setItem(SELECTED_CASE_KEY, selectedId);
    } else {
      localStorage.removeItem(SELECTED_CASE_KEY);
    }
  }

  function addMenuItem(label, iconSvg, onClick) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.setAttribute("role", "menuitem");
    btn.innerHTML = iconSvg + '<span class="menu-label"></span>';
    btn.querySelector(".menu-label").textContent = label;
    btn.addEventListener("click", function () {
      closeMenus();
      onClick();
    });
    return btn;
  }

  const icons = {
    delete:
      '<svg class="menu-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M10 7V5h4v2m-6 3v8m4-8v8M7 7l1 12a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2l1-12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    archive:
      '<svg class="menu-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h16v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8zm0 0 1.5-3h13L20 8M10 12h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    help:
      '<svg class="menu-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.6 2.2c-.8.4-1.1.9-1.1 1.8V14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="17" r="0.9" fill="currentColor"/></svg>',
    restore:
      '<svg class="menu-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v5h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
  };

  function updateNav() {
    archiveViewBtn.classList.toggle("active", view === "archive");
    ongoingViewBtn.classList.toggle("active", view === "ongoing");
  }

  function renderCaseList() {
    updateGuideLink();
    caseListEl.innerHTML = "";
    const visible = cases;
    if (visible.length === 0) {
      const empty = document.createElement("li");
      empty.className = "empty-list";
      empty.textContent =
        view === "archive" ? "Inget i arkivet." : "Inga pågående ärenden.";
      caseListEl.appendChild(empty);
      return;
    }

    visible.forEach(function (item) {
      const li = document.createElement("li");
      li.className = "case-item" + (item.id === selectedId ? " active" : "");

      const openBtn = document.createElement("button");
      openBtn.type = "button";
      openBtn.className = "case-item-btn";
      const title = document.createElement("span");
      title.className = "title";
      title.textContent = item.title;
      openBtn.appendChild(title);
      openBtn.addEventListener("click", function () {
        selectCase(item.id);
      });

      const wrap = document.createElement("div");
      wrap.className = "case-menu-wrap";

      const toggle = document.createElement("button");
      toggle.type = "button";
      toggle.className = "menu-toggle";
      toggle.setAttribute("aria-label", "Meny för " + item.title);
      toggle.setAttribute("aria-haspopup", "menu");
      toggle.setAttribute("aria-expanded", "false");
      toggle.setAttribute("aria-controls", "case-menu-" + item.id);
      toggle.textContent = "⋯";

      const menu = document.createElement("div");
      menu.className = "menu";
      menu.id = "case-menu-" + item.id;
      menu.setAttribute("role", "menu");
      menu.setAttribute("aria-label", "Åtgärder för " + item.title);

      if (view === "archive") {
        menu.append(
          addMenuItem("Återställ", icons.restore, function () {
            restoreCase(item.id);
          }),
          addMenuItem("Radera", icons.delete, function () {
            deleteCase(item.id);
          })
        );
      } else {
        menu.append(
          addMenuItem("Radera", icons.delete, function () {
            deleteCase(item.id);
          }),
          addMenuItem("Arkivera", icons.archive, function () {
            archiveCase(item.id);
          }),
          addMenuItem("Ansluta till hjälp", icons.help, function () {
            connectHelp(item.id);
          })
        );
      }

      toggle.addEventListener("click", function (event) {
        event.stopPropagation();
        const wasOpen = menu.classList.contains("open");
        closeMenus();
        if (!wasOpen) {
          openCaseMenu(wrap, menu, toggle, 0);
        }
      });
      bindMenuKeyboard(toggle, menu, wrap);
      wrap.addEventListener("click", function (event) {
        event.stopPropagation();
      });

      wrap.append(toggle, menu);
      li.append(openBtn, wrap);
      caseListEl.appendChild(li);
    });
  }

  function normalizeDisplayText(text) {
    return String(text || "")
      .replace(/Ã¥/g, "å")
      .replace(/Ã¤/g, "ä")
      .replace(/Ã¶/g, "ö")
      .replace(/Ã…/g, "Å")
      .replace(/Ã„/g, "Ä")
      .replace(/Ã–/g, "Ö")
      .replace(/Ã©/g, "é")
      .replace(/Â /g, " ")
      .replace(/â€“/g, "-")
      .replace(/â€”/g, "-")
      .replace(/â€™/g, "'")
      .replace(/â€œ/g, '"')
      .replace(/â€/g, '"')
      .replace(/├Ñ/g, "å")
      .replace(/├ñ/g, "ä")
      .replace(/├Â/g, "ö")
      .replace(/├à/g, "Å")
      .replace(/├ä/g, "Ä")
      .replace(/├û/g, "Ö")
      .replace(/f├År/g, "för")
      .replace(/F├År/g, "För")
      .replace(/f\\|År/g, "för")
      .replace(/F\\|År/g, "För")
      .replace(/tv├Ñ/g, "två")
      .replace(/Tv├Ñ/g, "Två")
      .replace(/tv\\|Ñ/g, "två")
      .replace(/Tv\\|Ñ/g, "Två");
  }

  function appendPlainOrLink(parent, text) {
    const pattern = /(https?:\/\/[^\s)]+)/g;
    let index = 0;
    let match;
    while ((match = pattern.exec(text)) !== null) {
      if (match.index > index) {
        parent.appendChild(document.createTextNode(text.slice(index, match.index)));
      }
      const link = document.createElement("a");
      link.href = match[0];
      link.target = "_blank";
      link.rel = "noreferrer";
      link.textContent = match[0];
      parent.appendChild(link);
      index = match.index + match[0].length;
    }
    if (index < text.length) {
      parent.appendChild(document.createTextNode(text.slice(index)));
    }
  }

  function appendMarkdownLink(parent, label, url) {
    if (!/^https?:\/\//i.test(url)) {
      parent.appendChild(document.createTextNode(label + " (" + url + ")"));
      return;
    }
    const link = document.createElement("a");
    link.href = url;
    link.target = "_blank";
    link.rel = "noreferrer";
    link.textContent = label;
    parent.appendChild(link);
  }

  function appendInlineMarkdown(parent, text) {
    const pattern = /(\[[^\]\n]+\]\(https?:\/\/[^)\s]+\)|\*\*[^*]+\*\*|\*[^*\n]+\*)/g;
    let index = 0;
    let match;
    while ((match = pattern.exec(text)) !== null) {
      if (match.index > index) {
        appendPlainOrLink(parent, text.slice(index, match.index));
      }
      const token = match[0];
      const linkMatch = /^\[([^\]\n]+)\]\((https?:\/\/[^)\s]+)\)$/.exec(token);
      if (linkMatch) {
        appendMarkdownLink(parent, linkMatch[1], linkMatch[2]);
        index = match.index + token.length;
        continue;
      }
      const markerLength = token.startsWith("**") ? 2 : 1;
      const element = document.createElement(markerLength === 2 ? "strong" : "em");
      appendPlainOrLink(
        element,
        token.slice(markerLength, token.length - markerLength)
      );
      parent.appendChild(element);
      index = match.index + token.length;
    }
    if (index < text.length) {
      appendPlainOrLink(parent, text.slice(index));
    }
  }

  function appendParagraph(parent, text) {
    const paragraph = document.createElement("p");
    appendInlineMarkdown(paragraph, text);
    parent.appendChild(paragraph);
  }

  function renderAssistantMessage(parent, text) {
    const lines = normalizeDisplayText(text).replace(/\r\n/g, "\n").split("\n");
    let list = null;

    lines.forEach(function (line) {
      const trimmed = line.trim();
      if (!trimmed) {
        list = null;
        return;
      }
      if (/^-{3,}$/.test(trimmed)) {
        list = null;
        return;
      }

      const heading = /^(#{1,6})\s+(.+)$/.exec(trimmed);
      if (heading) {
        list = null;
        const level = Math.min(4, heading[1].length + 1);
        const element = document.createElement("h" + level);
        appendInlineMarkdown(element, heading[2]);
        parent.appendChild(element);
        return;
      }

      const bullet = /^[-*]\s+(.+)$/.exec(trimmed);
      if (bullet) {
        if (!list) {
          list = document.createElement("ul");
          parent.appendChild(list);
        }
        const item = document.createElement("li");
        appendInlineMarkdown(item, bullet[1]);
        list.appendChild(item);
        return;
      }

      list = null;
      appendParagraph(parent, trimmed);
    });
  }

  function renderMessages(messages) {
    chatMessages.innerHTML = "";
    messages.forEach(function (msg) {
      const bubble = document.createElement("div");
      bubble.className = "message " + msg.role;
      if (msg.role === "assistant") {
        const hasClaims = renderEvidenceResponse(bubble, msg);
        if (!hasClaims) renderAssistantMessage(bubble, msg.text);
      } else {
        bubble.textContent = normalizeDisplayText(msg.text);
      }
      chatMessages.appendChild(bubble);
    });
    chatMessages.scrollTop = chatMessages.scrollHeight;
    chatView.classList.toggle("is-empty", messages.length === 0);
    const examples = document.getElementById("rag-example-prompts");
    if (examples) examples.open = messages.length === 0;
  }

  function evidenceUrl(value) {
    try {
      const url = new URL(String(value || ""));
      return /^https?:$/.test(url.protocol) ? url.href : "";
    } catch (_) { return ""; }
  }

  function evidenceOrigin(source) {
    return source.origin || source.evidence_origin || "";
  }

  function appendEvidenceLink(parent, source) {
    const local = evidenceOrigin(source) === "case_context";
    const label = local ? "Sparat kommununderlag" : source.title || "Källa";
    const url = local ? "" : evidenceUrl(source.source_url);
    const element = document.createElement(url ? "a" : "span");
    element.textContent = label;
    if (url) { element.href = url; element.target = "_blank"; element.rel = "noopener noreferrer"; }
    parent.appendChild(element);
  }

  function renderEvidenceResponse(parent, message) {
    const claims = (Array.isArray(message.claims) ? message.claims : []).filter(function (claim) { return claim && typeof claim.text === "string" && claim.text.trim(); });
    const hasStoredSummaries = claims.some(function (claim) { return claim.support_check === "stored_summary" || (Array.isArray(claim.evidence) ? claim.evidence : []).some(function (source) { return source && evidenceOrigin(source) === "reviewed_manifest_key_point"; }); });
    const verification = message.verification;
    if (verification && typeof verification === "object") {
      const notice = document.createElement("div");
      notice.className = "rag-verification rag-verification-" + (["supported", "partial", "excerpts", "reviewed", "no_support"].includes(verification.status) ? verification.status : "no_support");
      const heading = document.createElement("strong");
      const labels = { supported: verification.semantic_check === "passed" ? "Källstöd kontrollerat" : "Källor redovisas", partial: "Delvis källstött svar", excerpts: hasStoredSummaries ? "Sparat källstöd" : "Källutdrag", reviewed: "Granskade uppgifter med källor", no_support: "Tillräckligt källstöd saknas" };
      heading.textContent = labels[verification.status] || labels.no_support;
      const explanation = document.createElement("p");
      explanation.textContent = verification.semantic_check === "passed"
        ? "Svaret har jämförts med källutdragen med modellstöd och fasta regler. Kontrollen kan missa fel. Öppna källan när uppgiften är viktig för ett beslut."
        : verification.status === "excerpts"
          ? hasStoredSummaries
            ? "Här visas sparade utdrag och märkta granskade sammanfattningar. Sammanfattningar är inte ordagranna utdrag ur originalkällan. Ingen modellkontroll av tolkningen har använts."
            : "Källutdrag visas utan en ny sammanfattning. Ingen modellkontroll av tolkningen har använts."
          : verification.status === "reviewed"
            ? "Granskade uppgifter visas tillsammans med sitt källstöd. Kontrollera att de gäller er situation och aktuell version."
            : verification.status === "no_support"
              ? "Underlaget räcker inte för att belägga ett svar på frågan. Ett krav kan inte bekräftas bara för att det nämns i frågan."
              : "Alla delar av frågan kunde inte beläggas. Läs källstödet och komplettera det som saknas.";
      notice.append(heading, explanation);
      if (verification.notice) {
        const detail = document.createElement("p"); detail.className = "rag-verification-detail"; detail.textContent = verification.notice; notice.append(detail);
      }
      if (Number.isFinite(verification.omitted_claims) && verification.omitted_claims > 0) {
        const omitted = document.createElement("p"); omitted.className = "rag-verification-detail"; omitted.textContent = verification.omitted_claims + " påståenden utelämnades vid kontrollen av källstöd."; notice.append(omitted);
      }
      parent.appendChild(notice);
    }
    if (claims.length) {
      const list = document.createElement(claims.length > 1 ? "ol" : "div");
      list.className = "rag-claims";
      claims.forEach(function (claim) {
        const item = document.createElement(claims.length > 1 ? "li" : "section"); item.className = "rag-claim";
        const kind = document.createElement("span"); kind.className = "rag-claim-kind";
        kind.textContent = claim.support_check === "stored_summary" ? "Sparad granskad sammanfattning" : ({ summary: "Sammanfattning", excerpt: "Källutdrag", reviewed: "Granskad uppgift" })[claim.kind] || "Sammanfattning";
        const paragraph = document.createElement("p"); paragraph.className = "rag-claim-text"; paragraph.textContent = claim.text;
        item.append(kind, paragraph);
        const evidence = (Array.isArray(claim.evidence) ? claim.evidence : []).filter(function (source) { return source && typeof source === "object"; });
        if (evidence.length) {
          const citations = document.createElement("div"); citations.className = "rag-claim-sources";
          const prefix = document.createElement("span"); prefix.textContent = "Källstöd: "; citations.append(prefix);
          const seen = new Set();
          evidence.forEach(function (source) {
            const key = evidenceOrigin(source) === "case_context" ? "case_context" : source.source_url || source.title;
            if (seen.has(key)) return;
            if (seen.size) citations.append(document.createTextNode(" · "));
            seen.add(key); appendEvidenceLink(citations, source);
          });
          const details = document.createElement("details"); details.className = "rag-evidence";
          const summary = document.createElement("summary");
          summary.textContent = evidence.every(function (source) { return evidenceOrigin(source) === "case_context"; }) ? "Visa sparat underlag" : evidence.some(function (source) { return evidenceOrigin(source) === "reviewed_manifest_key_point"; }) ? "Visa källstöd" : "Visa källutdrag";
          details.append(summary);
          evidence.forEach(function (source) {
            const entry = document.createElement("figure"); entry.className = "rag-evidence-source";
            const caption = document.createElement("figcaption"); appendEvidenceLink(caption, source);
            const origin = evidenceOrigin(source);
            const metadata = [];
            if (origin === "case_context") metadata.push("Lokal uppgift från kommunen · ingen officiell DIGG-källa");
            if (origin === "reviewed_manifest_key_point") metadata.push("Granskad sammanfattning · inte ett ordagrant källutdrag");
            if (source.version) metadata.push("Version: " + source.version);
            if (source.last_checked) metadata.push("Källa kontrollerad: " + source.last_checked);
            if (source.environment) metadata.push("Miljö: " + source.environment);
            if (source.role) metadata.push("Roll: " + source.role);
            if (metadata.length) { const meta = document.createElement("p"); meta.className = "rag-source-meta"; meta.textContent = metadata.join(" · "); caption.append(meta); }
            const quote = document.createElement(origin === "reviewed_manifest_key_point" || origin === "case_context" ? "p" : "blockquote");
            quote.className = "rag-source-quote"; quote.textContent = typeof source.quote === "string" && source.quote ? source.quote : "Inget källutdrag är sparat för den här uppgiften.";
            entry.append(caption, quote); details.append(entry);
          });
          item.append(citations, details);
        } else {
          const missing = document.createElement("p"); missing.className = "rag-source-meta"; missing.textContent = "Källutdrag saknas för denna uppgift."; item.append(missing);
        }
        list.append(item);
      });
      parent.append(list);
    }
    const missing = (Array.isArray(message.missing_information) ? message.missing_information : []).filter(function (item) { return typeof item === "string" && item.trim(); });
    if (missing.length) {
      const panel = document.createElement("div"); panel.className = "rag-missing-information";
      const title = document.createElement("strong"); title.textContent = "Det som behöver kompletteras";
      const list = document.createElement("ul"); missing.forEach(function (text) { const item = document.createElement("li"); item.textContent = text; list.append(item); }); panel.append(title, list); parent.append(panel);
    }
    return claims.length > 0;
  }

  function setSending(isSending) {
    messageInput.disabled = isSending;
    sendButton.disabled = isSending;
    if (attachBtn) {
      attachBtn.classList.toggle("is-disabled", isSending);
      attachBtn.setAttribute("aria-disabled", isSending ? "true" : "false");
    }
    if (fileInput) {
      fileInput.disabled = isSending;
    }
    if (attachReplaceBtn) {
      attachReplaceBtn.disabled = isSending;
    }
    if (attachRemoveBtn) {
      attachRemoveBtn.disabled = isSending;
    }
  }

  function isTextAttachment(file) {
    return (
      /^text\//.test(file.type) ||
      /\.(json|ya?ml|txt|md|csv|xml)$/i.test(file.name)
    );
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

  function readAttachmentPreview(file) {
    return new Promise(function (resolve) {
      if (!isTextAttachment(file) || file.size > MAX_TEXT_ATTACHMENT_BYTES) {
        resolve("");
        return;
      }
      const reader = new FileReader();
      reader.onload = function () {
        resolve(String(reader.result || "").slice(0, ATTACHMENT_PREVIEW_LIMIT));
      };
      reader.onerror = function () {
        resolve("");
      };
      reader.readAsText(file);
    });
  }

  function attachmentFallbackPreview(file) {
    if (/\.pdf$/i.test(file.name) || file.type === "application/pdf") {
      return "PDF uppladdad i chatten. Webbläsaren sparade filnamn, typ och storlek men kunde inte extrahera PDF-text här. Be användaren skriva eller klistra in relevant utdrag om AI ska bedöma innehållet.";
    }
    return "Fil uppladdad i chatten. Webbläsaren sparade filnamn, typ och storlek men inget textutdrag kunde extraheras.";
  }

  async function attachmentSummary(file) {
    const preview = await readAttachmentPreview(file);
    return {
      name: file.name,
      type: file.type || "okänd filtyp",
      size: file.size,
      preview: preview || attachmentFallbackPreview(file),
    };
  }

  function renderPendingAttachment() {
    if (!attachBar || !attachFileName || !attachFileSize) {
      return;
    }
    if (pendingFile) {
      attachBar.hidden = false;
      attachBar.classList.add("is-open");
      attachFileName.textContent = pendingFile.name;
      attachFileSize.textContent = formatFileSize(pendingFile.size);
      if (attachBtn) {
        attachBtn.setAttribute("aria-label", "Bifogad fil: " + pendingFile.name);
      }
    } else {
      attachBar.hidden = true;
      attachBar.classList.remove("is-open");
      attachFileName.textContent = "";
      attachFileSize.textContent = "";
      if (attachBtn) {
        attachBtn.setAttribute("aria-label", "Bifoga fil");
      }
    }
  }

  function clearPendingAttachment() {
    pendingFile = null;
    if (fileInput) {
      fileInput.value = "";
    }
    renderPendingAttachment();
  }

  async function saveAttachmentForQuestion(caseId, question, file) {
    const saved = await DiggApi.saveCaseAttachment(
      caseId,
      await attachmentSummary(file),
      question
    );
    return saved || { name: file.name };
  }

  async function selectCase(id) {
    selectedId = id;
    rememberSelectedCase();
    const item = cases.find(function (c) {
      return c.id === id;
    });
    renderCaseList();
    if (!item) {
      selectedId = null;
      rememberSelectedCase();
      updateGuideLink();
      renderMessages([]);
      return;
    }
    const messages = await DiggApi.getMessages(id);
    renderMessages(messages);
    updateGuideLink();
    messageInput.focus();
  }

  async function loadCases() {
    cases =
      view === "archive" ? await DiggApi.getArchived() : await DiggApi.getCases();
    const stillThere = cases.some(function (c) {
      return c.id === selectedId;
    });
    if (!stillThere) {
      selectedId = null;
      rememberSelectedCase();
    }
    updateGuideLink();
    const ongoing = await DiggApi.getCases();
    if (ongoing.length < DiggApi.MAX_ONGOING) {
      limitNotice.hidden = true;
    }
    updateNav();
    if (selectedId) {
      await selectCase(selectedId);
    } else {
      renderCaseList();
      renderMessages([]);
    }
  }

  async function startConversation() {
    view = "ongoing";
    limitNotice.hidden = true;
    selectedId = null;
    rememberSelectedCase();
    await loadCases();
    messageInput.focus();
  }

  async function deleteCase(id) {
    await DiggApi.deleteCase(id);
    await loadCases();
  }

  async function archiveCase(id) {
    await DiggApi.archiveCase(id);
    await loadCases();
  }

  async function restoreCase(id) {
    const result = await DiggApi.restoreCase(id);
    if (!result.ok) {
      limitNotice.hidden = false;
      return;
    }
    view = "ongoing";
    await loadCases();
    await selectCase(result.case.id);
  }

  async function connectHelp(id) {
    await DiggApi.requestHelp(id);
    window.location.href = guideHref(id);
  }

  async function setView(next) {
    view = next;
    selectedId = null;
    rememberSelectedCase();
    await loadCases();
  }

  async function sendQuestion(text, preferredCaseId) {
    const attachment = pendingFile;
    if (!text) {
      if (attachment) {
        showInfo("Skriv en fråga tillsammans med filen, så sparas den som kontext för AI-svaret.");
      }
      return;
    }

    view = "ongoing";
    const ongoing = await DiggApi.getCases();
    if (preferredCaseId) {
      if (
        ongoing.some(function (item) {
          return item.id === preferredCaseId;
        })
      ) {
        selectedId = preferredCaseId;
        rememberSelectedCase();
      }
    } else if (
      selectedId &&
      !ongoing.some(function (item) {
        return item.id === selectedId;
      })
    ) {
      selectedId = null;
      rememberSelectedCase();
    }

    if (!selectedId) {
      if (ongoing.length >= DiggApi.MAX_ONGOING) {
        limitNotice.hidden = false;
        return;
      }
      const result = await DiggApi.createCase(text);
      if (!result.ok) {
        limitNotice.hidden = false;
        return;
      }
      selectedId = result.case.id;
      rememberSelectedCase();
    }

    limitNotice.hidden = true;
    setSending(true);
    try {
      let displayText = text;
      if (attachment) {
        const savedAttachment = await saveAttachmentForQuestion(
          selectedId,
          text,
          attachment
        );
        displayText += "\n\nBifogad fil: " + savedAttachment.name;
        clearPendingAttachment();
      }
      await DiggApi.addUserMessage(selectedId, displayText);
      await loadCases();
      await selectCase(selectedId);
      await DiggApi.addAssistantReply(selectedId, text);
      await loadCases();
      await selectCase(selectedId);
    } catch (error) {
      showInfo(error.message || "Kunde inte skicka frågan.");
    } finally {
      setSending(false);
      messageInput.focus();
    }
  }

  async function handleGuideQuestionFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const guideQuestion = params.get("guideQuestion");
    if (!guideQuestion) {
      return;
    }
    const caseId = params.get("case");
    window.history.replaceState(null, "", window.location.pathname);
    await sendQuestion(guideQuestion, caseId);
  }

  newCaseBtn.addEventListener("click", startConversation);
  ongoingViewBtn.addEventListener("click", function () {
    setView("ongoing");
  });
  archiveViewBtn.addEventListener("click", function () {
    setView("archive");
  });

  chatForm.addEventListener("submit", async function (event) {
    event.preventDefault();
    const text = messageInput.value.trim();
    messageInput.value = "";
    await sendQuestion(text);
  });

  document.querySelectorAll("[data-example-prompt]").forEach(function (button) {
    button.addEventListener("click", function () {
      if (messageInput.disabled) return;
      messageInput.value = button.getAttribute("data-example-prompt") || "";
      messageInput.focus();
      messageInput.dispatchEvent(new Event("input", { bubbles: true }));
    });
  });

  if (fileInput) {
    fileInput.addEventListener("change", function () {
      const chosen = fileInput.files && fileInput.files[0];
      if (!chosen) {
        return;
      }
      pendingFile = chosen;
      renderPendingAttachment();
    });
  }
  if (attachReplaceBtn && fileInput) {
    attachReplaceBtn.addEventListener("click", function () {
      fileInput.value = "";
      fileInput.click();
    });
  }
  if (attachRemoveBtn) {
    attachRemoveBtn.addEventListener("click", function () {
      clearPendingAttachment();
    });
  }

  document.addEventListener("click", function () {
    closeMenus();
  });

  window.addEventListener("resize", closeMenus);
  window.addEventListener("scroll", closeMenus, true);

  const sidebar = document.getElementById("sidebar");
  const sidebarToggle = document.getElementById("sidebar-toggle");

  function setSidebarOpen(open) {
    if (!sidebar || !sidebarToggle) {
      return;
    }
    sidebar.classList.toggle("is-open", open);
    sidebarToggle.setAttribute("aria-expanded", String(open));
  }

  if (sidebarToggle) {
    sidebarToggle.addEventListener("click", function () {
      setSidebarOpen(sidebarToggle.getAttribute("aria-expanded") !== "true");
    });
  }

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      closeMenus();
      setSidebarOpen(false);
    }
  });

  if (guideLink) {
    guideLink.addEventListener("mouseenter", function () {
      updateGuideLink();
    });
    guideLink.addEventListener("focus", updateGuideLink);
    guideLink.addEventListener("click", updateGuideLink);
  }

  (async function init() {
    await loadCases();
    await handleGuideQuestionFromUrl();
  })();
})();
