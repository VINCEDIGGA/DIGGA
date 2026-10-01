/**
 * Frontend-lagring för ärenden och meddelanden.
 * Chattsvar hämtas från PoC-backendens RAG/LLM-endpoint.
 */
const DiggApi = (function () {
  const STORAGE_KEY = "digg-temp-frontend-v3";
  const MAX_ONGOING = 2;
  const config = window.DIGGA_CONFIG || {};
  const RAG_CHAT_ENDPOINT = config.ragChatEndpoint || "backend/api/chat.php";
  const STEP_CONTEXT_ENDPOINT =
    config.stepContextEndpoint || "backend/api/step_context.php";

  function load() {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return { cases: [], messages: {} };
    }
    try {
      return JSON.parse(raw);
    } catch (error) {
      console.warn("Kunde inte läsa tillfällig lagring", error);
      return { cases: [], messages: {} };
    }
  }

  function save(data) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
  }

  function nextId() {
    return "case-" + Date.now() + "-" + Math.floor(Math.random() * 1000);
  }

  function ongoing(data) {
    return data.cases.filter(function (item) {
      return !item.archived;
    });
  }

  function titleFromChat(text) {
    const line = text.split("\n")[0].trim();
    if (line.length <= 40) {
      return line;
    }
    return line.slice(0, 40).trim() + "…";
  }

  async function askRag(question, caseId) {
    const response = await fetch(RAG_CHAT_ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ question: question, case_id: caseId || "" }),
    });
    const data = await response.json().catch(function () {
      return {};
    });
    if (!response.ok) {
      throw new Error(data.error || "Backend svarade med HTTP " + response.status);
    }
    return data;
  }

  async function deleteRemoteStepContext(caseId) {
    try {
      await fetch(STEP_CONTEXT_ENDPOINT, {
        method: "DELETE",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ case_id: caseId }),
      });
    } catch (error) {
      console.warn("Kunde inte radera underlag i backend", error);
    }
  }

  function escapeMarkdownLabel(value) {
    return String(value || "")
      .replace(/\[/g, "(")
      .replace(/\]/g, ")")
      .replace(/\s+/g, " ")
      .trim();
  }

  function sourceLabel(source) {
    const title = escapeMarkdownLabel(source.title || "Källa");
    const service = escapeMarkdownLabel(source.service || "");
    if (!service || title.toLowerCase().includes(service.toLowerCase())) {
      return title;
    }
    return title + " (" + service + ")";
  }

  function formatSources(sources) {
    const rows = [];
    const seen = new Set();
    (Array.isArray(sources) ? sources : []).forEach(function (source) {
      const url = String(source.source_url || "").trim();
      const label = sourceLabel(source);
      const key = url || label;
      if (!label || seen.has(key)) {
        return;
      }
      seen.add(key);
      if (/^https?:\/\//i.test(url)) {
        rows.push("- [" + label + "](" + url + ")");
      } else if (url) {
        rows.push("- " + label + " (" + url + ")");
      } else {
        rows.push("- " + label);
      }
    });
    return rows.length ? "\n\n### Källor\n" + rows.join("\n") : "";
  }

  function formatAssistantText(data) {
    let text = data.answer || "Jag fick inget textsvar från RAG-backenden.";
    text += formatSources(data.sources);
    if (data.warning) {
      text += "\n\nObs: " + data.warning;
    }
    return text;
  }

  function assistantErrorText(error) {
    return (
      "AI-svaret är inte aktiverat i den här miljön ännu.\n\n" +
      "Guiden och checklistorna fungerar fortfarande. För att aktivera AI-svar behöver skolmiljön köra PHP-backenden, ha databasen importerad och ha Mistral-nyckeln i backendens konfiguration.\n\n" +
      "Öppna backend/api/health.php på samma server för att se exakt vad som saknas.\n\n" +
      "Endpoint som försöktes: " +
      RAG_CHAT_ENDPOINT +
      "\nTeknisk detalj: " +
      error.message
    );
  }

  return {
    MAX_ONGOING: MAX_ONGOING,

    getCases: async function () {
      return ongoing(load());
    },

    storageKey: STORAGE_KEY,

    getCase: async function (id) {
      return load().cases.find(function (item) {
        return item.id === id;
      }) || null;
    },

    createCase: async function (title, intake) {
      const data = load();
      const openCases = ongoing(data);
      if (openCases.length >= MAX_ONGOING) {
        return { ok: false, reason: "limit" };
      }
      const newCase = {
        id: nextId(),
        title: title ? titleFromChat(title) : "Ny konversation",
        archived: false,
        helpRequested: false,
        intake: intake || null,
      };
      data.cases.push(newCase);
      data.messages[newCase.id] = [];
      save(data);
      return { ok: true, case: newCase };
    },

    deleteCase: async function (id) {
      const data = load();
      data.cases = data.cases.filter(function (item) {
        return item.id !== id;
      });
      delete data.messages[id];
      save(data);
      await deleteRemoteStepContext(id);
    },

    archiveCase: async function (id) {
      const data = load();
      const item = data.cases.find(function (c) {
        return c.id === id;
      });
      if (!item) {
        return;
      }
      item.archived = true;
      save(data);
    },

    getArchived: async function () {
      const data = load();
      return data.cases.filter(function (item) {
        return item.archived;
      });
    },

    restoreCase: async function (id) {
      const data = load();
      if (ongoing(data).length >= MAX_ONGOING) {
        return { ok: false, reason: "limit" };
      }
      const item = data.cases.find(function (c) {
        return c.id === id;
      });
      if (!item) {
        return { ok: false };
      }
      item.archived = false;
      save(data);
      return { ok: true, case: item };
    },

    requestHelp: async function (id) {
      const data = load();
      const item = data.cases.find(function (c) {
        return c.id === id;
      });
      if (!item) {
        return null;
      }
      item.helpRequested = true;
      save(data);
      return item;
    },

    getMessages: async function (caseId) {
      const data = load();
      return data.messages[caseId] || [];
    },

    addUserMessage: async function (caseId, text) {
      const data = load();
      if (!data.messages[caseId]) {
        data.messages[caseId] = [];
      }
      const isFirst = data.messages[caseId].length === 0;
      const message = {
        id: "msg-" + Date.now(),
        role: "user",
        text: text,
      };
      data.messages[caseId].push(message);
      if (isFirst) {
        const item = data.cases.find(function (c) {
          return c.id === caseId;
        });
        if (item && !item.intake) {
          item.title = titleFromChat(text);
        }
      }
      save(data);
      return message;
    },

    addAssistantReply: async function (caseId, question) {
      const data = load();
      if (!data.messages[caseId]) {
        data.messages[caseId] = [];
      }

      let text;
      let sources = [];
      try {
        const response = await askRag(question, caseId);
        text = formatAssistantText(response);
        sources = Array.isArray(response.sources) ? response.sources : [];
      } catch (error) {
        console.warn("RAG/LLM-anrop misslyckades", error);
        text = assistantErrorText(error);
      }

      const message = {
        id: "msg-" + Date.now() + "-assistant",
        role: "assistant",
        text: text,
        sources: sources,
      };
      data.messages[caseId].push(message);
      save(data);
      return message;
    },

    getStepContext: async function (caseId, stepIndex, checkIndex) {
      const url =
        STEP_CONTEXT_ENDPOINT +
        "?case_id=" +
        encodeURIComponent(caseId) +
        "&step_index=" +
        encodeURIComponent(stepIndex) +
        "&check_index=" +
        encodeURIComponent(checkIndex);
      const response = await fetch(url);
      const data = await response.json().catch(function () {
        return {};
      });
      if (!response.ok) {
        throw new Error(data.error || "Kunde inte hämta underlag.");
      }
      return data.context;
    },

    getStepContexts: async function (caseId) {
      const url =
        STEP_CONTEXT_ENDPOINT + "?case_id=" + encodeURIComponent(caseId);
      const response = await fetch(url);
      const data = await response.json().catch(function () {
        return {};
      });
      if (!response.ok) {
        throw new Error(data.error || "Kunde inte hämta sparat underlag.");
      }
      return data.contexts || [];
    },

    saveStepContext: async function (payload) {
      const response = await fetch(STEP_CONTEXT_ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await response.json().catch(function () {
        return {};
      });
      if (!response.ok) {
        throw new Error(data.error || "Kunde inte spara underlag.");
      }
      return data;
    },
  };
})();
