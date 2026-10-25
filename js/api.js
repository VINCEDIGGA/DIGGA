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
  const CASE_ATTACHMENTS_ENDPOINT =
    config.caseAttachmentsEndpoint || "backend/api/case_attachments.php";
  const REFORMAT_ENDPOINT = config.reformatEndpoint || "backend/api/reformat_files.php";
  const STEP_CONTEXT_TIMEOUT_MS = 3500;

  function load() {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return normalizeStore({ cases: [], messages: {} });
    }
    try {
      return normalizeStore(JSON.parse(raw));
    } catch (error) {
      console.warn("Kunde inte läsa tillfällig lagring", error);
      return normalizeStore({ cases: [], messages: {} });
    }
  }

  function normalizeStore(data) {
    return {
      cases: Array.isArray(data.cases) ? data.cases : [],
      messages: data.messages && typeof data.messages === "object" ? data.messages : {},
      stepContexts:
        data.stepContexts && typeof data.stepContexts === "object"
          ? data.stepContexts
          : {},
    };
  }

  function save(data) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(normalizeStore(data)));
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

  async function fetchJsonWithTimeout(url, options, timeoutMs) {
    const controller = new AbortController();
    const timer = window.setTimeout(function () {
      controller.abort();
    }, timeoutMs);
    try {
      const response = await fetch(url, Object.assign({}, options || {}, {
        signal: controller.signal,
      }));
      const data = await response.json().catch(function () {
        return {};
      });
      return { response: response, data: data };
    } finally {
      window.clearTimeout(timer);
    }
  }

  function stepContextKey(caseId, stepIndex, checkIndex) {
    return [
      String(caseId || ""),
      String(Number(stepIndex) || 0),
      String(Number(checkIndex) || 0),
    ].join("::");
  }

  function normalizeStepContextPayload(payload) {
    const now = new Date().toISOString();
    return Object.assign({}, payload, {
      case_id: String(payload.case_id || ""),
      step_index: Number(payload.step_index) || 0,
      check_index: Number(payload.check_index) || 0,
      files: Array.isArray(payload.files) ? payload.files : [],
      application:
        payload.application && typeof payload.application === "object"
          ? payload.application
          : {},
      updated_at: payload.updated_at || now,
      created_at: payload.created_at || now,
    });
  }

  function getLocalStepContext(caseId, stepIndex, checkIndex) {
    const data = load();
    return (
      data.stepContexts[stepContextKey(caseId, stepIndex, checkIndex)] || null
    );
  }

  function getLocalStepContexts(caseId) {
    const data = load();
    return Object.keys(data.stepContexts)
      .map(function (key) {
        return data.stepContexts[key];
      })
      .filter(function (context) {
        return context && context.case_id === caseId;
      })
      .sort(function (left, right) {
        return (
          (Number(left.step_index) || 0) - (Number(right.step_index) || 0) ||
          (Number(left.check_index) || 0) - (Number(right.check_index) || 0)
        );
      });
  }

  function saveLocalStepContext(payload) {
    const data = load();
    const normalized = normalizeStepContextPayload(payload);
    data.stepContexts[
      stepContextKey(
        normalized.case_id,
        normalized.step_index,
        normalized.check_index
      )
    ] = normalized;
    save(data);
    return normalized;
  }

  async function deleteRemoteCaseData(caseId) {
    ["digga-sdk-preparation-v1:", "digga-sdk-guide-v1:", "digg-guided-onboarding-v4:", "digga-workshop-v1:", "digg-reformat-workspace-v1:"].forEach(function (prefix) {
      localStorage.removeItem(prefix + caseId);
    });
    try {
      await fetch(STEP_CONTEXT_ENDPOINT, {
        method: "DELETE",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ case_id: caseId }),
      });
    } catch (error) {
      console.warn("Kunde inte radera underlag i backend", error);
    }

    try {
      await fetch(CASE_ATTACHMENTS_ENDPOINT, {
        method: "DELETE",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ case_id: caseId }),
      });
    } catch (error) {
      console.warn("Kunde inte radera uppladdade filer i backend", error);
    }

    try {
      const response = await fetch(REFORMAT_ENDPOINT, {
        method: "DELETE",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ case_id: caseId }),
      });
      if (!response.ok) console.warn("Kunde inte radera verkstadsutkast i backend");
    } catch (error) {
      console.warn("Kunde inte radera verkstadsutkast i backend", error);
    }

    const data = load();
    Object.keys(data.stepContexts).forEach(function (key) {
      if (data.stepContexts[key] && data.stepContexts[key].case_id === caseId) {
        delete data.stepContexts[key];
      }
    });
    save(data);
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
    let text = data.answer || (Array.isArray(data.claims) ? data.claims.filter(function (claim) { return claim && typeof claim.text === "string"; }).map(function (claim) { return claim.text; }).join("\n\n") : "") || "Jag fick inget textsvar från RAG-backenden.";
    text += formatSources(data.sources);
    if (data.warning) {
      text += "\n\nObs: " + data.warning;
    }
    return text;
  }

  function structuredEvidence(data) {
    const claims = (Array.isArray(data.claims) ? data.claims : []).filter(function (claim) {
      return claim && typeof claim.text === "string" && claim.text.trim();
    }).slice(0, 60).map(function (claim) {
      return {
        id: typeof claim.id === "string" || typeof claim.id === "number" ? String(claim.id) : "",
        text: claim.text,
        kind: ["summary", "excerpt", "reviewed"].includes(claim.kind) ? claim.kind : "summary",
        support_check: ["model_supported", "exact_excerpt", "reviewed_fact"].includes(claim.support_check) ? claim.support_check : "",
        evidence: (Array.isArray(claim.evidence) ? claim.evidence : []).filter(function (source) { return source && typeof source === "object"; }).slice(0, 12).map(function (source) {
          const result = {};
          ["source_id", "chunk_id", "title", "source_url", "quote", "version", "last_checked", "environment", "role", "origin", "evidence_origin"].forEach(function (key) {
            result[key] = typeof source[key] === "string" || typeof source[key] === "number" && Number.isFinite(source[key]) ? String(source[key]) : "";
          });
          return result;
        }),
      };
    });
    let verification = null;
    if (data.verification && typeof data.verification === "object") {
      const value = data.verification;
      verification = {
        status: ["supported", "partial", "excerpts", "reviewed", "no_support"].includes(value.status) ? value.status : "no_support",
        checked_claims: Number.isFinite(value.checked_claims) ? Math.max(0, Math.floor(value.checked_claims)) : null,
        omitted_claims: Number.isFinite(value.omitted_claims) ? Math.max(0, Math.floor(value.omitted_claims)) : null,
        semantic_check: ["passed", "not_used", "unavailable"].includes(value.semantic_check) ? value.semantic_check : "unavailable",
        notice: typeof value.notice === "string" ? value.notice : "",
      };
    }
    return {
      mode: typeof data.mode === "string" ? data.mode : "",
      answer: typeof data.answer === "string" ? data.answer : claims.map(function (claim) { return claim.text; }).join("\n\n"),
      claims: claims,
      verification: verification,
      missing_information: (Array.isArray(data.missing_information) ? data.missing_information : []).filter(function (item) { return typeof item === "string" && item.trim(); }).slice(0, 30),
    };
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

  function hasMeaningfulContext(context) {
    if (!context) return false;
    if (["organization_name", "organization_number", "contact_name", "contact_email", "contact_role", "short_description", "need_text"].some(function (key) { return String(context[key] || "").trim(); })) return true;
    if (Array.isArray(context.files) && context.files.length) return true;
    return Object.entries(context.application || {}).some(function (entry) {
      const key = entry[0], value = entry[1];
      if (/^sdk_(service|environment|stage)$/.test(key) || key === "multiple_signers" || /^sdk_test_.+_(mode|result)$/.test(key)) return false;
      if (typeof value === "boolean") return value;
      if (typeof value !== "string") return false;
      return value.trim() !== "" && !["false", "nej", "not_started", "simulation", "qa", "production", "preparation"].includes(value.trim());
    });
  }

  return {
    MAX_ONGOING: MAX_ONGOING,
    hasMeaningfulContext: hasMeaningfulContext,

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
      await deleteRemoteCaseData(id);
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
      let evidence = {};
      try {
        const response = await askRag(question, caseId);
        text = formatAssistantText(response);
        sources = Array.isArray(response.sources) ? response.sources : [];
        evidence = structuredEvidence(response);
      } catch (error) {
        console.warn("RAG/LLM-anrop misslyckades", error);
        text = assistantErrorText(error);
      }

      const message = Object.assign({
        id: "msg-" + Date.now() + "-assistant",
        role: "assistant",
        text: text,
        sources: sources,
      }, evidence);
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
      try {
        const result = await fetchJsonWithTimeout(
          url,
          {},
          STEP_CONTEXT_TIMEOUT_MS
        );
        if (!result.response.ok) {
          throw new Error(result.data.error || "Kunde inte hämta underlag.");
        }
        return (
          result.data.context ||
          getLocalStepContext(caseId, stepIndex, checkIndex)
        );
      } catch (error) {
        console.warn("Använder lokalt underlag eftersom backend inte svarade", error);
        return getLocalStepContext(caseId, stepIndex, checkIndex);
      }
    },

    getStepContexts: async function (caseId) {
      const url =
        STEP_CONTEXT_ENDPOINT + "?case_id=" + encodeURIComponent(caseId);
      try {
        const result = await fetchJsonWithTimeout(
          url,
          {},
          STEP_CONTEXT_TIMEOUT_MS
        );
        if (!result.response.ok) {
          throw new Error(result.data.error || "Kunde inte hämta sparat underlag.");
        }
        const remoteContexts = Array.isArray(result.data.contexts)
          ? result.data.contexts
          : [];
        const localContexts = getLocalStepContexts(caseId);
        return remoteContexts.length > 0 ? remoteContexts : localContexts;
      } catch (error) {
        console.warn("Använder lokalt underlag eftersom backend inte svarade", error);
        return getLocalStepContexts(caseId);
      }
    },

    saveStepContext: async function (payload) {
      const localContext = saveLocalStepContext(payload);
      try {
        const result = await fetchJsonWithTimeout(
          STEP_CONTEXT_ENDPOINT,
          {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
          },
          STEP_CONTEXT_TIMEOUT_MS
        );
        if (!result.response.ok) {
          throw new Error(result.data.error || "Kunde inte spara underlag.");
        }
        return result.data;
      } catch (error) {
        console.warn("Underlag sparades lokalt eftersom backend inte svarade", error);
        return { ok: true, local: true, context: localContext };
      }
    },

    saveCaseAttachment: async function (caseId, attachment, messageText) {
      const response = await fetch(CASE_ATTACHMENTS_ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          case_id: caseId,
          message_text: messageText || "",
          attachment: attachment,
        }),
      });
      const data = await response.json().catch(function () {
        return {};
      });
      if (!response.ok) {
        throw new Error(data.error || "Kunde inte spara uppladdad fil.");
      }
      return data.attachment;
    },
  };
})();
