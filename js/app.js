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
  const SELECTED_CASE_KEY = "digg-selected-case-v3";

  let cases = [];
  let selectedId = localStorage.getItem(SELECTED_CASE_KEY) || null;
  let view = "ongoing";

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
  }

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

  function appendInlineMarkdown(parent, text) {
    const pattern = /(\*\*[^*]+\*\*|\*[^*\n]+\*)/g;
    let index = 0;
    let match;
    while ((match = pattern.exec(text)) !== null) {
      if (match.index > index) {
        appendPlainOrLink(parent, text.slice(index, match.index));
      }
      const token = match[0];
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
        renderAssistantMessage(bubble, msg.text);
      } else {
        bubble.textContent = normalizeDisplayText(msg.text);
      }
      chatMessages.appendChild(bubble);
    });
    chatMessages.scrollTop = chatMessages.scrollHeight;
    chatView.classList.toggle("is-empty", messages.length === 0);
  }

  function setSending(isSending) {
    messageInput.disabled = isSending;
    sendButton.disabled = isSending;
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
    if (!text) {
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
      await DiggApi.addUserMessage(selectedId, text);
      await loadCases();
      await selectCase(selectedId);
      await DiggApi.addAssistantReply(selectedId, text);
      await loadCases();
      await selectCase(selectedId);
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

  document.addEventListener("click", function () {
    closeMenus();
  });

  window.addEventListener("resize", closeMenus);
  window.addEventListener("scroll", closeMenus, true);

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      closeMenus();
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
