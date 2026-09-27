(function () {
  const newCaseBtn = document.getElementById("new-case-btn");
  const ongoingViewBtn = document.getElementById("ongoing-view-btn");
  const archiveViewBtn = document.getElementById("archive-view-btn");
  const limitNotice = document.getElementById("limit-notice");
  const caseListEl = document.getElementById("case-list");
  const chatView = document.getElementById("chat-view");
  const chatMessages = document.getElementById("chat-messages");
  const chatForm = document.getElementById("chat-form");
  const messageInput = document.getElementById("message-input");
  const infoDialog = document.getElementById("info-dialog");
  const infoText = document.getElementById("info-text");

  let cases = [];
  let selectedId = null;
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

  function renderMessages(messages) {
    chatMessages.innerHTML = "";
    messages.forEach(function (msg) {
      const bubble = document.createElement("div");
      bubble.className = "message " + msg.role;
      bubble.textContent = msg.text;
      chatMessages.appendChild(bubble);
    });
    chatMessages.scrollTop = chatMessages.scrollHeight;
    chatView.classList.toggle("is-empty", messages.length === 0);
  }

  async function selectCase(id) {
    selectedId = id;
    const item = cases.find(function (c) {
      return c.id === id;
    });
    renderCaseList();
    if (!item) {
      selectedId = null;
      renderMessages([]);
      return;
    }
    const messages = await DiggApi.getMessages(id);
    renderMessages(messages);
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
    }
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
    const ongoing = await DiggApi.getCases();
    if (ongoing.length >= DiggApi.MAX_ONGOING) {
      limitNotice.hidden = false;
      return;
    }
    const result = await DiggApi.createCase();
    if (!result.ok) {
      limitNotice.hidden = false;
      return;
    }
    await loadCases();
    await selectCase(result.case.id);
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
    showInfo("Ansluta till hjälp är valt. Kopplas till backend senare.");
  }

  async function setView(next) {
    view = next;
    selectedId = null;
    await loadCases();
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
    if (!text) {
      return;
    }

    if (!selectedId || view === "archive") {
      view = "ongoing";
      const ongoing = await DiggApi.getCases();
      if (!selectedId && ongoing.length >= DiggApi.MAX_ONGOING) {
        limitNotice.hidden = false;
        return;
      }
      if (!selectedId) {
        const result = await DiggApi.createCase(text);
        if (!result.ok) {
          limitNotice.hidden = false;
          return;
        }
        selectedId = result.case.id;
      }
    }

    messageInput.value = "";
    limitNotice.hidden = true;
    await DiggApi.addUserMessage(selectedId, text);
    await loadCases();
    await selectCase(selectedId);
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

  loadCases();
})();
