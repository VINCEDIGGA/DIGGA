/**
 * Tillfällig frontend-lagring.
 * Byt innehållet i funktionerna mot fetch() när backend finns.
 */
const DiggApi = (function () {
  const STORAGE_KEY = "digg-temp-frontend-v2";
  const MAX_ONGOING = 2;

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

  return {
    MAX_ONGOING: MAX_ONGOING,

    getCases: async function () {
      return ongoing(load());
    },

    createCase: async function (title) {
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
        if (item) {
          item.title = titleFromChat(text);
        }
      }
      save(data);
      return message;
    },
  };
})();
