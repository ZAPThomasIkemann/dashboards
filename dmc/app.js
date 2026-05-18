(() => {
  const STORAGE_KEY = "dmc_content_dashboard_v7";
  const LEGACY_STORAGE_KEYS = ["dmc_content_dashboard_v6"];
  const WORKFLOW_PROXY_API_URL = "/dashboards/dmc/api/workflow_proxy.php";
  const SYSTEM_PROMPT_API_URL = "/dashboards/dmc/api/system_prompts.php";
  const CONTENT_HISTORY_API_URL = "/dashboards/dmc/api/content_history.php";
  const CONTENT_DRAFT_API_URL = "/dashboards/dmc/api/content_draft.php";
  const AUTOSAVE_DELAY_MS = 5 * 60 * 1000;
  const THREAD_REFRESH_INTERVAL_MS = 10000;
  const DRAFT_SAVE_DELAY_MS = 700;
  const DEFAULT_SYSTEM_PROMPT =
    "Du bist der Content-Assistent von DMC. Berücksichtige Unternehmensprofil, Zielgruppen, Leistungsangebot, Tonalität und Qualitätsstandards.";
  const COUNTRY_OPTIONS = [
    { key: "at", label: "Österreich", display: "Österreich" },
    { key: "cz", label: "Tschechien", display: "Tschechien" },
    { key: "ch", label: "Schweiz", display: "Schweiz" },
    { key: "si", label: "Slowenien", display: "Slowenien" },
    { key: "ro", label: "Rumänien", display: "Rumänien" },
    { key: "hu", label: "Ungarn", display: "Ungarn" },
    { key: "hr", label: "Kroatien", display: "Kroatien" },
    { key: "bg", label: "Bulgarien", display: "Bulgarien" },
    { key: "sk", label: "Slowakei", display: "Slowakei" },
  ];
  const COUNTRY_KEY_BY_LABEL = Object.fromEntries(
    COUNTRY_OPTIONS.flatMap((country) => [
      [country.label, country.key],
      [country.display, country.key],
    ])
  );
  const DMC_DOMAINS = [
    "digitale-vignette-online.at",
    "digitale-vignette-online.cz",
    "digitale-vignette-schweiz.de",
    "digitale-vignette-slowenien.de",
    "digitale-vignette-ro.online",
    "digitale-vignette-ungarn.de",
    "digitale-vignette-slowakei.de",
    "digitale-vignette-kroatien.de",
    "digitale-vignette-bulgarien.de",
    "europamaut.com",
  ];

  const els = {
    form: document.getElementById("contentForm"),
    systemPrompt: document.getElementById("contentSystemPrompt"),
    systemPromptCountry: document.getElementById("systemPromptCountry"),
    systemPromptMeta: document.getElementById("systemPromptMeta"),
    systemPromptStatus: document.getElementById("systemPromptStatus"),
    systemPromptVersions: document.getElementById("systemPromptVersions"),
    systemPromptVersionCount: document.getElementById("systemPromptVersionCount"),
    saveSystemPromptButton: document.getElementById("btnSaveSystemPrompt"),
    assignee: document.getElementById("contentAssignee"),
    country: document.getElementById("contentCountry"),
    title: document.getElementById("contentTitle"),
    prompt: document.getElementById("contentPrompt"),
    contentType: document.getElementById("contentType"),
    submitButton: document.getElementById("submitButton"),
    clearButton: document.getElementById("btnClearForm"),
    requestStatus: document.getElementById("requestStatus"),
    historyList: document.getElementById("historyList"),
    historyCount: document.getElementById("historyCount"),
    threadFilterAssignee: document.getElementById("threadFilterAssignee"),
    threadFilterCountry: document.getElementById("threadFilterCountry"),
    threadFilterDateFrom: document.getElementById("threadFilterDateFrom"),
    threadFilterDateTo: document.getElementById("threadFilterDateTo"),
    resetThreadFiltersButton: document.getElementById("btnResetThreadFilters"),
    avgDurationValue: document.getElementById("avgDurationValue"),
    activeThreadsValue: document.getElementById("activeThreadsValue"),
    completedThreadsValue: document.getElementById("completedThreadsValue"),
    topbarTime: document.getElementById("topbarTime"),
    pageTitle: document.getElementById("pageTitle"),
    pageSubtitle: document.getElementById("pageSubtitle"),
    breadcrumbLabel: document.getElementById("breadcrumbLabel"),
    themeToggle: document.getElementById("themeToggle"),
    themeToggleIcon: document.getElementById("themeToggleIcon"),
    themeToggleLabel: document.getElementById("themeToggleLabel"),
    sidebar: document.getElementById("sidebar"),
    sidebarOverlay: document.getElementById("sidebarOverlay"),
    sidebarToggle: document.getElementById("sidebarToggle"),
    mobileMenuBtn: document.getElementById("mobileMenuBtn"),
    backlinksFrame: document.getElementById("backlinksFrame"),
    rankingsFrame: document.getElementById("rankingsFrame"),
    backlinksDetailBanner: document.getElementById("backlinksDetailBanner"),
    askQuestionsCheckbox: document.getElementById("askQuestionsMode"),
    questionCard: document.getElementById("questionCard"),
    btnShowAllBacklinks: document.getElementById("btnShowAllBacklinks"),
    btnFocusEuropamaut: document.getElementById("btnFocusEuropamaut"),
    viewTriggers: Array.from(document.querySelectorAll("[data-view-trigger]")),
    views: Array.from(document.querySelectorAll("[data-view]")),
  };

  let clockTimer = null;
  let promptAutosaveTimer = null;
  let threadRefreshTimer = null;
  let draftSaveTimer = null;
  const VERSION_PREVIEW_LENGTH = 220;

  const emptyPromptMeta = () => ({
    latestSavedPrompt: DEFAULT_SYSTEM_PROMPT,
    lastSavedAt: null,
    latestVersionId: null,
    hasUnsavedChanges: false,
  });

  let state = {
    activeView: "content",
    theme: document.documentElement.dataset.theme === "dark" ? "dark" : "light",
    systemPromptCountry: COUNTRY_OPTIONS[0].key,
    systemPrompts: Object.fromEntries(COUNTRY_OPTIONS.map((country) => [country.key, DEFAULT_SYSTEM_PROMPT])),
    promptVersions: Object.fromEntries(COUNTRY_OPTIONS.map((country) => [country.key, []])),
    promptMeta: Object.fromEntries(COUNTRY_OPTIONS.map((country) => [country.key, emptyPromptMeta()])),
    draft: {
      assignee: "",
      country: "",
      title: "",
      prompt: "",
      contentType: "",
      source: "empty",
    },
    history: [],
    expandedThreadIds: [],
    threadFilters: {
      assignee: "",
      country: "",
      dateFrom: "",
      dateTo: "",
    },
    threadStats: {
      totalThreads: 0,
      activeThreads: 0,
      completedThreads: 0,
      avgDurationSeconds: null,
    },
    backlinks: {
      loaded: false,
      rows: [],
      selectedDomain: "",
    },
    questionMode: null,
  };

  function uid() {
    if (typeof crypto !== "undefined" && crypto.randomUUID) return crypto.randomUUID();
    return `content_${Date.now()}_${Math.random().toString(16).slice(2)}`;
  }

  function getCountryDisplay(countryKey) {
    return COUNTRY_OPTIONS.find((country) => country.key === countryKey)?.display || countryKey;
  }

  function getSelectedDraftCountryKey() {
    return COUNTRY_KEY_BY_LABEL[state.draft.country] || state.systemPromptCountry || COUNTRY_OPTIONS[0].key;
  }

  function normalizeLookupValue(value) {
    return String(value || "")
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/\s+/g, " ")
      .trim();
  }

  function resolveCountryFromQuery(value) {
    const normalizedValue = normalizeLookupValue(value);
    if (!normalizedValue) return null;

    return (
      COUNTRY_OPTIONS.find((country) =>
        [country.key, country.label, country.display].some(
          (candidate) => normalizeLookupValue(candidate) === normalizedValue
        )
      ) || null
    );
  }

  function applyDraftFromQueryParams() {
    const params = new URLSearchParams(window.location.search);
    if (![...params.keys()].length) return;

    const assignee = params.get("assignee");
    const country = params.get("country");
    const title = params.get("title");
    const prompt = params.get("prompt");

    const hasDraftParam = [assignee, country, title, prompt].some(
      (value) => typeof value === "string" && value.trim()
    );
    if (!hasDraftParam) return;

    if (typeof assignee === "string" && assignee.trim()) {
      state.draft.assignee = assignee.trim();
    }

    if (typeof title === "string" && title.trim()) {
      state.draft.title = title.trim();
    }

    if (typeof prompt === "string" && prompt.trim()) {
      state.draft.prompt = prompt.trim();
    }

    const resolvedCountry = resolveCountryFromQuery(country);
    if (resolvedCountry) {
      state.draft.country = resolvedCountry.display;
      state.systemPromptCountry = resolvedCountry.key;
    } else if (typeof country === "string" && country.trim()) {
      state.draft.country = country.trim();
    }

    state.draft.source = "url";
  }

  function loadState() {
    const parseStoredState = (rawValue) => {
      if (!rawValue) return null;
      try {
        const parsed = JSON.parse(rawValue);
        return parsed && typeof parsed === "object" ? parsed : null;
      } catch (error) {
        console.error(error);
        return null;
      }
    };

    try {
      const currentState = parseStoredState(localStorage.getItem(STORAGE_KEY));
      const legacyState = currentState
        ? null
        : LEGACY_STORAGE_KEYS.map((key) => parseStoredState(localStorage.getItem(key))).find(Boolean) || null;
      const data = currentState || legacyState;
      if (!data) return;
      const systemPromptCountry =
        typeof data?.systemPromptCountry === "string" &&
        COUNTRY_OPTIONS.some((country) => country.key === data.systemPromptCountry)
          ? data.systemPromptCountry
          : COUNTRY_OPTIONS[0].key;

      state = {
        activeView: ["content", "content-threads", "content-systemprompt", "seo-backlinks", "seo-rankings"].includes(data?.activeView)
          ? data.activeView
          : "content",
        theme: data?.theme === "dark" ? "dark" : "light",
        systemPromptCountry,
        systemPrompts: Object.fromEntries(COUNTRY_OPTIONS.map((country) => [country.key, DEFAULT_SYSTEM_PROMPT])),
        promptVersions: Object.fromEntries(COUNTRY_OPTIONS.map((country) => [country.key, []])),
        promptMeta: Object.fromEntries(
          COUNTRY_OPTIONS.map((country) => [
            country.key,
            {
              latestSavedPrompt: DEFAULT_SYSTEM_PROMPT,
              lastSavedAt: null,
              latestVersionId: null,
              hasUnsavedChanges: false,
            },
          ])
        ),
        draft: {
          assignee: "",
          country: "",
          title: "",
          prompt: "",
          contentType: "",
          source: "empty",
        },
        history: [],
        expandedThreadIds: Array.isArray(data?.expandedThreadIds)
          ? data.expandedThreadIds.map((value) => String(value))
          : [],
        threadStats: {
          totalThreads: 0,
          activeThreads: 0,
          completedThreads: 0,
          avgDurationSeconds: null,
        },
        threadFilters: {
          assignee: typeof data?.threadFilters?.assignee === "string" ? data.threadFilters.assignee : "",
          country: typeof data?.threadFilters?.country === "string" ? data.threadFilters.country : "",
          dateFrom: typeof data?.threadFilters?.dateFrom === "string" ? data.threadFilters.dateFrom : "",
          dateTo: typeof data?.threadFilters?.dateTo === "string" ? data.threadFilters.dateTo : "",
        },
        backlinks: {
          loaded: false,
          rows: [],
          selectedDomain: typeof data?.backlinks?.selectedDomain === "string" ? data.backlinks.selectedDomain : "",
        },
      };

      if (!currentState && legacyState) {
        saveState();
        for (const key of LEGACY_STORAGE_KEYS) {
          localStorage.removeItem(key);
        }
      }
    } catch (error) {
      console.error(error);
    }
  }

  function isHistoryItem(item) {
    return (
      item &&
      (typeof item.id === "string" || typeof item.id === "number") &&
      typeof item.title === "string" &&
      typeof item.prompt === "string" &&
      typeof item.status === "string"
    );
  }

  function isThreadMessage(item) {
    return (
      item &&
      (typeof item.id === "string" || typeof item.id === "number") &&
      typeof item.messageType === "string" &&
      typeof item.body === "string"
    );
  }

  function saveState() {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        activeView: state.activeView,
        theme: state.theme,
        systemPromptCountry: state.systemPromptCountry,
        expandedThreadIds: state.expandedThreadIds,
        threadFilters: state.threadFilters,
        backlinks: {
          selectedDomain: state.backlinks.selectedDomain,
        },
      })
    );
  }

  function populateSystemPromptCountrySelect() {
    if (!els.systemPromptCountry || els.systemPromptCountry.childElementCount > 0) return;
    for (const country of COUNTRY_OPTIONS) {
      const option = document.createElement("option");
      option.value = country.key;
      option.textContent = country.display;
      els.systemPromptCountry.appendChild(option);
    }
  }

  function applyTheme() {
    document.documentElement.dataset.theme = state.theme;
    if (!els.themeToggle || !els.themeToggleIcon || !els.themeToggleLabel) return;
    const isDark = state.theme === "dark";
    els.themeToggleIcon.className = isDark ? "fas fa-sun" : "fas fa-moon";
    els.themeToggleLabel.textContent = isDark ? "Light Mode" : "Dark Mode";
    els.themeToggle.setAttribute(
      "aria-label",
      isDark ? "Zum hellen Farbschema wechseln" : "Zum dunklen Farbschema wechseln"
    );
  }

  function buildPromptExcerpt(text) {
    const normalized = String(text || "").replace(/\s+/g, " ").trim();
    if (normalized.length <= VERSION_PREVIEW_LENGTH) {
      return {
        text: normalized,
        truncated: false,
      };
    }

    return {
      text: `${normalized.slice(0, VERSION_PREVIEW_LENGTH).trimEnd()}...`,
      truncated: true,
    };
  }

  function renderSystemPromptMeta() {
    if (!els.systemPromptMeta) return;
    const meta = state.promptMeta[state.systemPromptCountry] || emptyPromptMeta();
    const parts = [`Land: ${getCountryDisplay(state.systemPromptCountry)}`];
    if (meta.lastSavedAt) {
      parts.push(`Zuletzt gespeichert: ${new Date(meta.lastSavedAt).toLocaleString("de-DE")}`);
    } else {
      parts.push("Noch keine gespeicherte Version");
    }
    if (meta.hasUnsavedChanges) {
      parts.push("Ungespeicherte Änderungen");
    }
    els.systemPromptMeta.textContent = parts.join(" | ");
  }

  function renderSystemPromptVersions() {
    if (!els.systemPromptVersions || !els.systemPromptVersionCount) return;

    const versions = state.promptVersions[state.systemPromptCountry] || [];
    els.systemPromptVersions.innerHTML = "";
    els.systemPromptVersionCount.textContent = String(versions.length);

    if (versions.length === 0) {
      const empty = document.createElement("div");
      empty.className = "empty-state";
      empty.innerHTML = "<i class='fas fa-timeline'></i><p>Noch keine gespeicherten Prompt-Versionen.</p>";
      els.systemPromptVersions.appendChild(empty);
      return;
    }

    for (const version of versions) {
      const article = document.createElement("article");
      article.className = "history-item";

      const head = document.createElement("div");
      head.className = "history-item__head";

      const titleWrap = document.createElement("div");
      const title = document.createElement("h3");
      title.textContent = `${getCountryDisplay(version.country_key)} · Version #${version.id}`;
      titleWrap.appendChild(title);

      const time = document.createElement("time");
      time.dateTime = version.created_at;
      time.textContent = new Date(version.created_at).toLocaleString("de-DE");

      head.appendChild(titleWrap);
      head.appendChild(time);

      const meta = document.createElement("div");
      meta.className = "history-item__meta";
      const modeChip = document.createElement("span");
      modeChip.className = "history-chip";
      modeChip.innerHTML = `<i class='fas fa-tag'></i><span>${version.save_mode}</span>`;
      meta.appendChild(modeChip);

      const preview = document.createElement("p");
      preview.className = "history-preview";
      const excerpt = buildPromptExcerpt(version.prompt_text);
      preview.textContent = excerpt.text;

      const actions = document.createElement("div");
      actions.className = "history-actions";

      const loadButton = document.createElement("button");
      loadButton.type = "button";
      loadButton.className = "btn btn--ghost btn--small";
      loadButton.textContent = "In Editor laden";
      loadButton.addEventListener("click", () => {
        state.systemPromptCountry = version.country_key;
        state.systemPrompts[version.country_key] = version.prompt_text;
        state.promptMeta[version.country_key].hasUnsavedChanges =
          version.prompt_text !== (state.promptMeta[version.country_key].latestSavedPrompt || DEFAULT_SYSTEM_PROMPT);
        syncInputsFromState();
        saveState();
        queuePromptAutosave();
        setSystemPromptStatus(`Version #${version.id} wurde in den Editor geladen.`, null);
      });

      const restoreButton = document.createElement("button");
      restoreButton.type = "button";
      restoreButton.className = "btn btn-secondary btn--small";
      restoreButton.textContent = "Wiederherstellen";
      restoreButton.addEventListener("click", async () => {
        state.systemPromptCountry = version.country_key;
        state.systemPrompts[version.country_key] = version.prompt_text;
        syncInputsFromState();
        await persistSystemPrompt("restore");
      });

      const deleteButton = document.createElement("button");
      deleteButton.type = "button";
      deleteButton.className = "btn btn--danger btn--small";
      deleteButton.textContent = "Loeschen";
      deleteButton.addEventListener("click", async () => {
        const confirmed = window.confirm("Bist du sicher, dass du diese Version loeschen moechtest?");
        if (!confirmed) return;
        await deleteSystemPromptVersion(version);
      });

      if (excerpt.truncated) {
        const toggleButton = document.createElement("button");
        toggleButton.type = "button";
        toggleButton.className = "btn btn-secondary btn--small";
        toggleButton.textContent = "Ausklappen";
        toggleButton.addEventListener("click", () => {
          const expanded = article.classList.toggle("is-expanded");
          preview.textContent = expanded ? version.prompt_text : excerpt.text;
          toggleButton.textContent = expanded ? "Einklappen" : "Ausklappen";
        });
        actions.appendChild(toggleButton);
      }

      actions.appendChild(loadButton);
      actions.appendChild(restoreButton);
      actions.appendChild(deleteButton);

      article.appendChild(head);
      article.appendChild(meta);
      article.appendChild(preview);
      article.appendChild(actions);
      els.systemPromptVersions.appendChild(article);
    }
  }

  function syncInputsFromState() {
    populateSystemPromptCountrySelect();
    if (els.systemPromptCountry) {
      els.systemPromptCountry.value = state.systemPromptCountry;
    }
    if (els.systemPrompt) {
      els.systemPrompt.value = state.systemPrompts[state.systemPromptCountry] || DEFAULT_SYSTEM_PROMPT;
    }
    els.assignee.value = state.draft.assignee;
    els.country.value = state.draft.country;
    els.title.value = state.draft.title;
    els.prompt.value = state.draft.prompt;
    if (els.contentType) els.contentType.value = state.draft.contentType || "";
    renderSystemPromptMeta();
    renderSystemPromptVersions();
  }

  function syncStateFromInputs() {
    const currentPrompt = els.systemPrompt ? els.systemPrompt.value.trim() || DEFAULT_SYSTEM_PROMPT : DEFAULT_SYSTEM_PROMPT;
    state.systemPrompts[state.systemPromptCountry] = currentPrompt;
    state.promptMeta[state.systemPromptCountry].hasUnsavedChanges =
      currentPrompt !== (state.promptMeta[state.systemPromptCountry].latestSavedPrompt || DEFAULT_SYSTEM_PROMPT);
    state.draft.assignee = els.assignee.value;
    state.draft.country = els.country.value;
    state.draft.title = els.title.value.trim();
    state.draft.prompt = els.prompt.value.trim();
    state.draft.contentType = els.contentType ? els.contentType.value : "";
    if (state.draft.source !== "history") {
      state.draft.source = "empty";
    }
  }

  function setRequestStatus(message, kind) {
    els.requestStatus.textContent = message;
    els.requestStatus.classList.remove("is-success", "is-error");
    if (kind === "success") els.requestStatus.classList.add("is-success");
    if (kind === "error") els.requestStatus.classList.add("is-error");
  }

  function toggleTheme() {
    state.theme = state.theme === "dark" ? "light" : "dark";
    applyTheme();
    saveState();
  }

  function updateThreadFiltersFromInputs() {
    state.threadFilters.assignee = els.threadFilterAssignee?.value || "";
    state.threadFilters.country = els.threadFilterCountry?.value || "";
    state.threadFilters.dateFrom = els.threadFilterDateFrom?.value || "";
    state.threadFilters.dateTo = els.threadFilterDateTo?.value || "";
    saveState();
    renderHistory();
  }

  function setSystemPromptStatus(message, kind) {
    if (!els.systemPromptStatus) return;
    els.systemPromptStatus.textContent = message;
    els.systemPromptStatus.classList.remove("is-success", "is-error");
    if (kind === "success") els.systemPromptStatus.classList.add("is-success");
    if (kind === "error") els.systemPromptStatus.classList.add("is-error");
  }

  function buildPayload() {
    const promptCountryKey = getSelectedDraftCountryKey();
    return {
      title: state.draft.title,
      prompt: state.draft.prompt,
      assignee: state.draft.assignee,
      country: state.draft.country,
      contentType: state.draft.contentType || "",
      systemPrompt: state.systemPrompts[promptCountryKey] || DEFAULT_SYSTEM_PROMPT,
      meta: {
        source: "dmc-content-dashboard",
        submittedAt: new Date().toISOString(),
      },
    };
  }

  function formatDuration(seconds) {
    if (typeof seconds !== "number" || Number.isNaN(seconds) || seconds < 0) return "-";
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    if (minutes < 60) return `${minutes}m ${remainingSeconds}s`;
    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;
    return `${hours}h ${remainingMinutes}m`;
  }

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function getThreadMessages(thread, messageType) {
    const messages = Array.isArray(thread?.messages) ? thread.messages.filter(isThreadMessage) : [];
    if (!messageType) return messages;
    return messages.filter((message) => message.messageType === messageType);
  }

  function getLatestThreadMessage(thread, messageType) {
    const messages = getThreadMessages(thread, messageType);
    return messages.length ? messages[messages.length - 1] : null;
  }

  function getLatestThreadContent(thread) {
    const latestRevision = getLatestThreadMessage(thread, "revision");
    if (latestRevision?.body) return latestRevision.body;
    return thread?.generatedContent || "";
  }

  function getLatestThreadInsight(thread, messageType) {
    const latestMessage = getLatestThreadMessage(thread, messageType);
    return latestMessage?.body || "";
  }

  function createThreadMessageCard(message, options = {}) {
    const {
      title = message.authorName || "Nachricht",
      chipLabel = message.status || "completed",
      extraClass = "",
      icon = "",
      anchorPrefix = "",
      collectTocEntries = null,
    } = options;

    const card = document.createElement("article");
    card.className = `thread-message ${extraClass}`.trim();

    const head = document.createElement("div");
    head.className = "thread-message__head";

    const titleStrong = document.createElement("strong");
    titleStrong.innerHTML = `${icon ? `${icon} ` : ""}${escapeHtml(title)}`;

    const timestamp = document.createElement("span");
    timestamp.textContent = new Date(message.createdAt || Date.now()).toLocaleString("de-DE");

    head.appendChild(titleStrong);
    head.appendChild(timestamp);
    card.appendChild(head);

    const status = document.createElement("div");
    status.className = "thread-message__status";
    status.innerHTML = `
      <span class="history-chip">${escapeHtml(chipLabel)}</span>
      <span class="thread-message__duration">${escapeHtml(formatDuration(message.durationSeconds))}</span>
    `;
    card.appendChild(status);

    const contentText = String(message.body || "");
    const headings = anchorPrefix ? extractMarkdownHeadings(contentText, anchorPrefix) : [];
    if (collectTocEntries && headings.length) {
      for (const heading of headings) {
        collectTocEntries.push({
          href: `#${heading.id}`,
          label: heading.text,
          className: `thread-toc__link--heading thread-toc__link--heading-h${heading.level}`,
        });
      }
    }

    if (headings.length) {
      card.appendChild(buildThreadMarkdownContent(contentText, anchorPrefix, headings));
    } else {
      const pre = document.createElement("pre");
      pre.className = "thread-pre thread-pre--compact";
      pre.textContent = contentText;
      card.appendChild(pre);
    }

    if (message.errorMessage) {
      const error = document.createElement("p");
      error.className = "thread-message__error";
      error.textContent = message.errorMessage;
      card.appendChild(error);
    }

    return card;
  }

  function renderThreadStats() {
    if (!els.avgDurationValue || !els.activeThreadsValue || !els.completedThreadsValue) return;
    els.avgDurationValue.textContent = formatDuration(state.threadStats.avgDurationSeconds);
    els.activeThreadsValue.textContent = String(state.threadStats.activeThreads || 0);
    els.completedThreadsValue.textContent = String(state.threadStats.completedThreads || 0);
  }

  function mapThreadStatusLabel(thread) {
    if (thread.statusLabel) return thread.statusLabel;
    const labels = {
      queued: "Wartet auf Workflow",
      running: "Workflow gestartet",
      research: "Recherche laeuft",
      writing: "Content wird geschrieben",
      publishing: "Dokument wird erstellt",
      completed: "Content fertig generiert",
      failed: "Workflow fehlgeschlagen",
    };
    return labels[thread.status] || thread.status || "Unbekannt";
  }

  function isThreadActive(thread) {
    return !["completed", "failed"].includes(String(thread?.status || "").toLowerCase());
  }

  function getThreadTimestamp(thread) {
    return thread?.createdAt || thread?.startedAt || null;
  }

  function formatThreadDateTime(thread) {
    const timestamp = getThreadTimestamp(thread);
    if (!timestamp) return "-";
    return new Date(timestamp).toLocaleString("de-DE");
  }

  function formatThreadDateTimeWithWeekday(thread) {
    const timestamp = getThreadTimestamp(thread);
    if (!timestamp) return "-";
    return new Date(timestamp).toLocaleString("de-DE", {
      weekday: "short",
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
    });
  }

  async function copyTextToClipboard(text) {
    const value = String(text || "").trim();
    if (!value) return false;

    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(value);
      return true;
    }

    const helper = document.createElement("textarea");
    helper.value = value;
    helper.setAttribute("readonly", "");
    helper.style.position = "absolute";
    helper.style.left = "-9999px";
    document.body.appendChild(helper);
    helper.select();
    const successful = document.execCommand("copy");
    document.body.removeChild(helper);
    return successful;
  }

  function getThreadDateValue(thread) {
    const timestamp = getThreadTimestamp(thread);
    if (!timestamp) return "";
    const date = new Date(timestamp);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
  }

  function getThreadPreviewText(thread) {
    return String(thread?.prompt || "").trim();
  }

  function extractMarkdownHeadings(text, anchorPrefix) {
    const headings = [];
    const lines = String(text || "").split(/\r?\n/);
    let headingIndex = 0;

    for (const rawLine of lines) {
      const line = String(rawLine || "");
      const h1Match = line.match(/^#\s+(.+?)\s*$/);
      const h2Match = line.match(/^##\s+(.+?)\s*$/);
      const match = h1Match || h2Match;
      if (!match) continue;

      headingIndex += 1;
      headings.push({
        id: `${anchorPrefix}-heading-${headingIndex}`,
        level: h1Match ? 1 : 2,
        text: match[1].trim(),
        raw: line,
      });
    }

    return headings;
  }

  function buildThreadMarkdownContent(text, anchorPrefix, headings = extractMarkdownHeadings(text, anchorPrefix)) {
    const wrapper = document.createElement("div");
    wrapper.className = "thread-markdown";

    const headingByRaw = new Map();
    for (const heading of headings) {
      const bucket = headingByRaw.get(heading.raw) || [];
      bucket.push(heading);
      headingByRaw.set(heading.raw, bucket);
    }

    for (const rawLine of String(text || "").split(/\r?\n/)) {
      const line = String(rawLine || "");
      const bucket = headingByRaw.get(line) || [];
      const heading = bucket.shift();
      headingByRaw.set(line, bucket);

      const lineElement = document.createElement("div");
      lineElement.className = "thread-markdown__line";

      if (heading) {
        lineElement.classList.add(
          "thread-markdown__heading",
          heading.level === 1 ? "thread-markdown__heading--h1" : "thread-markdown__heading--h2"
        );
        lineElement.id = heading.id;
      } else if (!line.trim()) {
        lineElement.classList.add("thread-markdown__line--empty");
      }

      lineElement.textContent = line;
      wrapper.appendChild(lineElement);
    }

    return wrapper;
  }

  function updateThreadFilterInputs() {
    if (els.threadFilterAssignee) els.threadFilterAssignee.value = state.threadFilters.assignee || "";
    if (els.threadFilterCountry) els.threadFilterCountry.value = state.threadFilters.country || "";
    if (els.threadFilterDateFrom) els.threadFilterDateFrom.value = state.threadFilters.dateFrom || "";
    if (els.threadFilterDateTo) els.threadFilterDateTo.value = state.threadFilters.dateTo || "";
  }

  function populateThreadFilterOptions() {
    if (!els.threadFilterAssignee || !els.threadFilterCountry) return;

    const currentAssignee = state.threadFilters.assignee || "";
    const currentCountry = state.threadFilters.country || "";

    const assignees = [...new Set(state.history.map((item) => String(item.assignee || "").trim()).filter(Boolean))].sort(
      (a, b) => a.localeCompare(b, "de")
    );
    const countries = [...new Set(state.history.map((item) => String(item.country || "").trim()).filter(Boolean))].sort(
      (a, b) => a.localeCompare(b, "de")
    );

    els.threadFilterAssignee.innerHTML = "<option value=''>Alle Bearbeiter</option>";
    for (const assignee of assignees) {
      const option = document.createElement("option");
      option.value = assignee;
      option.textContent = assignee;
      els.threadFilterAssignee.appendChild(option);
    }

    els.threadFilterCountry.innerHTML = "<option value=''>Alle Laender</option>";
    for (const country of countries) {
      const option = document.createElement("option");
      option.value = country;
      option.textContent = country;
      els.threadFilterCountry.appendChild(option);
    }

    if (assignees.includes(currentAssignee)) {
      els.threadFilterAssignee.value = currentAssignee;
    } else {
      state.threadFilters.assignee = "";
    }

    if (countries.includes(currentCountry)) {
      els.threadFilterCountry.value = currentCountry;
    } else {
      state.threadFilters.country = "";
    }
  }

  function getFilteredHistory() {
    return state.history.filter((item) => {
      const assigneeMatches =
        !state.threadFilters.assignee || String(item.assignee || "").trim() === state.threadFilters.assignee;
      const countryMatches =
        !state.threadFilters.country || String(item.country || "").trim() === state.threadFilters.country;
      const threadDate = getThreadDateValue(item);
      const fromMatches = !state.threadFilters.dateFrom || (threadDate && threadDate >= state.threadFilters.dateFrom);
      const toMatches = !state.threadFilters.dateTo || (threadDate && threadDate <= state.threadFilters.dateTo);
      return assigneeMatches && countryMatches && fromMatches && toMatches;
    });
  }

  function resetThreadFilters() {
    state.threadFilters.assignee = "";
    state.threadFilters.country = "";
    state.threadFilters.dateFrom = "";
    state.threadFilters.dateTo = "";
    updateThreadFilterInputs();
    saveState();
    renderHistory();
  }

  function isThreadExpanded(threadId) {
    return state.expandedThreadIds.includes(String(threadId));
  }

  function setThreadExpanded(threadId, expanded) {
    const normalizedThreadId = String(threadId);
    const current = new Set(state.expandedThreadIds.map((value) => String(value)));
    if (expanded) {
      current.add(normalizedThreadId);
    } else {
      current.delete(normalizedThreadId);
    }
    state.expandedThreadIds = Array.from(current);
    saveState();
  }

  function renderHistory() {
    populateThreadFilterOptions();
    updateThreadFilterInputs();
    const filteredHistory = getFilteredHistory();

    // Save any in-progress comment inputs before wiping the DOM
    const _savedInputs = {};
    els.historyList.querySelectorAll("[data-thread-comment-text]").forEach((el) => {
      if (el.value) _savedInputs[el.dataset.threadCommentText] = _savedInputs[el.dataset.threadCommentText] || {};
      if (el.value) _savedInputs[el.dataset.threadCommentText].text = el.value;
    });
    els.historyList.querySelectorAll("[data-thread-comment-author]").forEach((el) => {
      if (el.value) _savedInputs[el.dataset.threadCommentAuthor] = _savedInputs[el.dataset.threadCommentAuthor] || {};
      if (el.value) _savedInputs[el.dataset.threadCommentAuthor].author = el.value;
    });

    els.historyList.innerHTML = "";
    els.historyCount.textContent = String(filteredHistory.length);
    renderThreadStats();

    if (state.history.length === 0) {
      const empty = document.createElement("div");
      empty.className = "empty-state";
      empty.innerHTML = "<i class='fas fa-inbox'></i><p>Noch keine Content-Threads vorhanden.</p>";
      els.historyList.appendChild(empty);
      return;
    }

    if (filteredHistory.length === 0) {
      const empty = document.createElement("div");
      empty.className = "empty-state";
      empty.innerHTML = "<i class='fas fa-filter'></i><p>Keine Threads passen zu den gesetzten Filtern.</p>";
      els.historyList.appendChild(empty);
      return;
    }

    for (const item of filteredHistory) {
      els.historyList.appendChild(buildHistoryItem(item));
    }

    // Restore comment inputs that were in progress
    for (const [threadId, vals] of Object.entries(_savedInputs)) {
      if (vals.text) {
        const el = els.historyList.querySelector(`[data-thread-comment-text="${threadId}"]`);
        if (el) el.value = vals.text;
      }
      if (vals.author) {
        const el = els.historyList.querySelector(`[data-thread-comment-author="${threadId}"]`);
        if (el) el.value = vals.author;
      }
    }
  }

  function buildHistoryItem(item) {
    const article = document.createElement("article");
    const expanded = isThreadExpanded(item.id);
    article.className = `history-item history-item--thread is-status-${item.status || "queued"}${expanded ? "" : " is-collapsed"}`;
    if (isThreadActive(item)) {
      article.classList.add("is-thread-active");
    }
    article.dataset.threadId = String(item.id);
    const sectionBaseId = `thread-${item.id}`;

    const head = document.createElement("div");
    head.className = "history-item__head";

    const titleWrap = document.createElement("div");
    const kicker = document.createElement("div");
    kicker.className = "thread-kicker";
    kicker.innerHTML = `
      <span class="thread-kicker__item"><i class="fas fa-user"></i><span>${escapeHtml(item.assignee || "Nicht zugewiesen")}</span></span>
      <span class="thread-kicker__item"><i class="fas fa-calendar-day"></i><span>${escapeHtml(formatThreadDateTimeWithWeekday(item))}</span></span>
      <span class="thread-kicker__item"><i class="fas fa-location-dot"></i><span>${escapeHtml(item.country || "-")}</span></span>
      ${item.contentType ? `<span class="thread-kicker__item"><i class="fas fa-${item.contentType === "ratgeber" ? "book-open" : "newspaper"}"></i><span>${escapeHtml(item.contentType === "ratgeber" ? "Ratgeber" : "News")}</span></span>` : ""}
    `;
    titleWrap.appendChild(kicker);

    const title = document.createElement("h3");
    title.textContent = item.title || "Ohne Titel";
    titleWrap.appendChild(title);

    const subline = document.createElement("p");
    subline.className = "thread-subline";
    subline.textContent = getThreadPreviewText(item);
    titleWrap.appendChild(subline);

    const headActions = document.createElement("div");
    headActions.className = "history-item__head-actions";

    const time = document.createElement("time");
    time.dateTime = getThreadTimestamp(item) || "";
    time.textContent = formatThreadDateTime(item);

    const toggleButton = document.createElement("button");
    toggleButton.type = "button";
    toggleButton.className = "btn btn-secondary btn--small";
    toggleButton.dataset.action = "toggle-thread";
    toggleButton.dataset.threadId = String(item.id);
    toggleButton.setAttribute("aria-expanded", String(expanded));
    toggleButton.innerHTML = expanded
      ? "<i class='fas fa-chevron-up'></i> Einklappen"
      : "<i class='fas fa-chevron-down'></i> Ausklappen";

    const toggleGroup = document.createElement("div");
    toggleGroup.className = "thread-toggle-group";
    toggleGroup.appendChild(toggleButton);

    if (item.generatedContent || item.documentUrl) {
      const docActions = document.createElement("div");
      docActions.className = "thread-doc-actions";

      if (item.documentUrl) {
        const openLink = document.createElement("a");
        openLink.className = "btn btn--ghost btn--small";
        openLink.href = item.documentUrl;
        openLink.target = "_blank";
        openLink.rel = "noreferrer";
        openLink.innerHTML = "<i class='fas fa-up-right-from-square'></i> Link";
        docActions.appendChild(openLink);
      } else if (item.generatedContent) {
        const contentAnchor = document.createElement("a");
        contentAnchor.className = "btn btn--ghost btn--small";
        contentAnchor.href = `#${sectionBaseId}-result`;
        contentAnchor.innerHTML = "<i class='fas fa-file-lines'></i> Zum Content";
        docActions.appendChild(contentAnchor);
      }

      const copyBtn = document.createElement("button");
      copyBtn.type = "button";
      copyBtn.className = "btn btn-secondary btn--small";
      copyBtn.dataset.threadId = String(item.id);
      if (item.documentUrl && !item.generatedContent) {
        copyBtn.dataset.action = "copy-document-link";
        copyBtn.innerHTML = "<i class='fas fa-copy'></i> Link kopieren";
      } else {
        copyBtn.dataset.action = "copy-content";
        copyBtn.innerHTML = "<i class='fas fa-copy'></i> Content kopieren";
      }
      docActions.appendChild(copyBtn);
      toggleGroup.appendChild(docActions);
    }

    headActions.appendChild(time);
    headActions.appendChild(toggleGroup);

    head.appendChild(titleWrap);
    head.appendChild(headActions);

    const meta = document.createElement("div");
    meta.className = "history-item__meta";

    const statusChip = document.createElement("span");
    statusChip.className = "history-chip history-chip--status";
    statusChip.innerHTML = `<i class='fas fa-bolt'></i><span>${mapThreadStatusLabel(item)}</span>`;
    meta.appendChild(statusChip);

    const progressWrap = document.createElement("div");
    progressWrap.className = "thread-progress";
    progressWrap.innerHTML = `
      <div class="thread-progress__bar">
        <div class="thread-progress__fill" style="width:${Math.max(0, Math.min(100, item.progressPercent || 0))}%"></div>
      </div>
      <div class="thread-progress__meta">
        <span>${mapThreadStatusLabel(item)}</span>
        <strong>${Math.max(0, Math.min(100, item.progressPercent || 0))}%</strong>
      </div>
    `;

    const infoGrid = document.createElement("div");
    infoGrid.className = "thread-info-grid";
    infoGrid.innerHTML = `
      <div class="thread-info-pill"><span>Dauer</span><strong>${escapeHtml(formatDuration(item.durationSeconds))}</strong></div>
      <div class="thread-info-pill"><span>Status</span><strong>${escapeHtml(item.status || "-")}</strong></div>
      <div class="thread-info-pill"><span>Workflow</span><strong>${escapeHtml(item.workflowId || "-")}</strong></div>
      <div class="thread-info-pill"><span>Execution</span><strong>${escapeHtml(item.executionId || "-")}</strong></div>
    `;

    const threadBody = document.createElement("div");
    threadBody.className = "thread-body";

    const threadDetails = document.createElement("div");
    threadDetails.className = "thread-details";

    const threadMain = document.createElement("div");
    threadMain.className = "thread-main";

    const contentSections = document.createElement("div");
    contentSections.className = "thread-content-sections";
    const contentHeadingEntries = [];

    const promptCard = document.createElement("section");
    promptCard.className = "thread-section";
    promptCard.id = `${sectionBaseId}-prompt`;
    promptCard.innerHTML = `
      <div class="thread-section__head">
        <h4>Prompt</h4>
        <button type="button" class="btn btn--ghost btn--small" data-action="load-thread" data-thread-id="${escapeHtml(item.id)}">Ins Formular laden</button>
      </div>
      <pre class="thread-pre">${escapeHtml(item.prompt || "")}</pre>
    `;
    contentSections.appendChild(promptCard);

    if (item.generatedContent) {
      const resultHeadingEntries = extractMarkdownHeadings(item.generatedContent, `${sectionBaseId}-result`);
      for (const heading of resultHeadingEntries) {
        contentHeadingEntries.push({
          href: `#${heading.id}`,
          label: heading.text,
          className: `thread-toc__link--heading thread-toc__link--heading-h${heading.level}`,
        });
      }

      const resultCard = document.createElement("section");
      resultCard.className = "thread-section thread-section--result";
      resultCard.id = `${sectionBaseId}-result`;
      resultCard.innerHTML = `
        <div class="thread-section__head">
          <h4>Generierter Content</h4>
          ${item.documentUrl ? `<a class="btn btn-secondary btn--small" href="${escapeHtml(item.documentUrl)}" target="_blank" rel="noreferrer">Google Doc</a>` : ""}
        </div>
      `;
      resultCard.appendChild(buildThreadMarkdownContent(item.generatedContent, `${sectionBaseId}-result`, resultHeadingEntries));
      contentSections.appendChild(resultCard);
    }

    if (item.errorMessage) {
      const errorCard = document.createElement("section");
      errorCard.className = "thread-section thread-section--error";
      errorCard.id = `${sectionBaseId}-error`;
      errorCard.innerHTML = `
        <div class="thread-section__head">
          <h4>Fehler</h4>
        </div>
        <pre class="thread-pre">${escapeHtml(item.errorMessage)}</pre>
      `;
      contentSections.appendChild(errorCard);
    }

    const threadColumn = document.createElement("div");
    threadColumn.className = "thread-conversation";
    threadColumn.id = `${sectionBaseId}-conversation`;

    const resultLead = document.createElement("div");
    resultLead.className = "thread-root-message";
    resultLead.id = `${sectionBaseId}-overview`;
    resultLead.innerHTML = `
      <div class="thread-root-message__icon"><i class="fas fa-file-lines"></i></div>
      <div>
        <strong>Originaler Content-Request</strong>
        <p>Der Fortschrittsbalken ist erst voll, wenn die Generierung abgeschlossen und das Ergebnis im Thread angekommen ist.</p>
      </div>
    `;
    threadColumn.appendChild(resultLead);

    const messages = Array.isArray(item.messages) ? item.messages.filter(isThreadMessage) : [];
    const comments = messages.filter((message) => message.messageType === "comment");
    const childMessagesByParent = new Map();
    for (const message of messages) {
      if (message.parentMessageId && ["revision", "ai_comment", "ai_recommendation"].includes(message.messageType)) {
        const current = childMessagesByParent.get(message.parentMessageId) || [];
        current.push(message);
        childMessagesByParent.set(message.parentMessageId, current);
      }
    }

    for (const comment of comments) {
      const commentCard = createThreadMessageCard(comment, {
        title: comment.authorName || "Kommentar",
        chipLabel: comment.status || "processing",
        extraClass: `thread-message--comment is-status-${comment.status || "processing"}`,
      });
      commentCard.id = `${sectionBaseId}-comment-${comment.id}`;

      const childMessages = childMessagesByParent.get(comment.id) || [];
      for (const childMessage of childMessages) {
        if (childMessage.messageType === "revision") {
          commentCard.appendChild(
            createThreadMessageCard(childMessage, {
              title: childMessage.authorName || "Revision",
              chipLabel: "ueberarbeitet",
              extraClass: "thread-message--revision",
              icon: "<i class='fas fa-sparkles'></i>",
              anchorPrefix: `${sectionBaseId}-revision-${childMessage.id}`,
              collectTocEntries: contentHeadingEntries,
            })
          );
          continue;
        }

        if (childMessage.messageType === "ai_comment") {
          commentCard.appendChild(
            createThreadMessageCard(childMessage, {
              title: "KI-Kommentar",
              chipLabel: "ki-feedback",
              extraClass: "thread-message--ai-comment",
              icon: "<i class='fas fa-comment-dots'></i>",
            })
          );
          continue;
        }

        if (childMessage.messageType === "ai_recommendation") {
          commentCard.appendChild(
            createThreadMessageCard(childMessage, {
              title: "Empfehlung fuer Bearbeiter",
              chipLabel: "qualitaets-tipp",
              extraClass: "thread-message--ai-recommendation",
              icon: "<i class='fas fa-lightbulb'></i>",
            })
          );
        }
      }

      threadColumn.appendChild(commentCard);
    }

    const commentComposer = document.createElement("section");
    commentComposer.className = "thread-section thread-section--composer";
    commentComposer.id = `${sectionBaseId}-composer`;
    commentComposer.innerHTML = `
      <div class="thread-section__head">
        <h4>Naechste Optimierungsrunde</h4>
        <p class="thread-section__hint">Die KI arbeitet immer mit dem zuletzt ueberarbeiteten Textstand, dem aktuellen Systemprompt und deinem neuen Kommentar weiter.</p>
      </div>
      <div class="thread-comment-form">
        <input
          type="text"
          class="form-input"
          data-thread-comment-author="${escapeHtml(item.id)}"
          placeholder="Kommentar von"
          value="${escapeHtml(item.assignee || "")}"
        />
        <textarea
          class="form-input form-input--textarea"
          rows="4"
          data-thread-comment-text="${escapeHtml(item.id)}"
          placeholder="Beschreibe konkret, was die KI am aktuellen Text als Naechstes verbessern soll..."
        ></textarea>
        <div class="thread-comment-actions">
          <button type="button" class="btn btn-primary btn--small" data-action="submit-comment" data-thread-id="${escapeHtml(item.id)}">
            <i class="fas fa-reply"></i> Kommentar absenden
          </button>
          <button type="button" class="btn btn--danger btn--small" data-action="delete-thread" data-thread-id="${escapeHtml(item.id)}">
            <i class="fas fa-trash"></i> Thread loeschen
          </button>
        </div>
      </div>
    `;
    threadColumn.appendChild(commentComposer);

    const tableOfContents = document.createElement("aside");
    tableOfContents.className = "thread-toc";
    tableOfContents.innerHTML = `
      <div class="thread-toc__head">
        <h4>Inhaltsverzeichnis</h4>
      </div>
    `;

    const tocList = document.createElement("div");
    tocList.className = "thread-toc__list";
    const tocEntries = [
      { href: `#${sectionBaseId}-prompt`, label: "Prompt" },
      item.generatedContent ? { href: `#${sectionBaseId}-result`, label: "Generierter Content" } : null,
      ...contentHeadingEntries,
      item.errorMessage ? { href: `#${sectionBaseId}-error`, label: "Fehler" } : null,
      { href: `#${sectionBaseId}-overview`, label: "Thread-Ueberblick" },
      ...comments.map((comment, index) => ({
        href: `#${sectionBaseId}-comment-${comment.id}`,
        label: `Kommentar ${index + 1}`,
      })),
      { href: `#${sectionBaseId}-composer`, label: "Naechste Optimierungsrunde" },
    ].filter(Boolean);

    for (const entry of tocEntries) {
      const link = document.createElement("a");
      link.className = "thread-toc__link";
      if (entry.className) {
        link.className += ` ${entry.className}`;
      }
      link.href = entry.href;
      link.textContent = entry.label;
      tocList.appendChild(link);
    }
    tableOfContents.appendChild(tocList);

    threadMain.appendChild(contentSections);
    threadMain.appendChild(threadColumn);
    threadDetails.appendChild(threadMain);
    threadDetails.appendChild(tableOfContents);
    threadBody.appendChild(threadDetails);

    article.appendChild(head);
    article.appendChild(meta);
    article.appendChild(progressWrap);
    article.appendChild(infoGrid);
    article.appendChild(threadBody);
    return article;
  }

  function clearForm() {
    state.draft.assignee = "";
    state.draft.country = "";
    state.draft.title = "";
    state.draft.prompt = "";
    state.draft.contentType = "";
    state.draft.source = "empty";
    syncInputsFromState();
    saveState();
    queueDraftSave();
    setRequestStatus("Formular geleert.", null);
  }

  function saveDraft() {
    syncStateFromInputs();
    saveState();
    renderSystemPromptMeta();
    queueDraftSave();
  }

  async function persistSharedDraft() {
    const payload = {
      assignee: state.draft.assignee || "",
      country: state.draft.country || "",
      countryKey: getSelectedDraftCountryKey(),
      title: state.draft.title || "",
      prompt: state.draft.prompt || "",
      source: state.draft.source || "draft",
      updatedBy: state.draft.assignee || "",
    };

    const response = await fetch(CONTENT_DRAFT_API_URL, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify(payload),
    });

    const data = await response.json();
    if (!response.ok || !data?.success) {
      throw new Error(data?.error || `HTTP ${response.status}`);
    }

    return data?.draft || null;
  }

  function queueDraftSave() {
    if (draftSaveTimer) {
      clearTimeout(draftSaveTimer);
      draftSaveTimer = null;
    }

    draftSaveTimer = setTimeout(async () => {
      try {
        await persistSharedDraft();
      } catch (error) {
        console.error(error);
      } finally {
        draftSaveTimer = null;
      }
    }, DRAFT_SAVE_DELAY_MS);
  }

  async function loadSharedDraft() {
    const response = await fetch(`${CONTENT_DRAFT_API_URL}?cb=${Date.now()}`, {
      headers: {
        Accept: "application/json",
      },
    });
    const data = await response.json();
    if (!response.ok || !data?.success) {
      throw new Error(data?.error || `HTTP ${response.status}`);
    }

    const draft = data?.draft;
    if (!draft) return null;

    state.draft.assignee = typeof draft.assignee === "string" ? draft.assignee : "";
    state.draft.country = typeof draft.country === "string" ? draft.country : "";
    state.draft.title = typeof draft.title === "string" ? draft.title : "";
    state.draft.prompt = typeof draft.prompt === "string" ? draft.prompt : "";
    state.draft.source = typeof draft.source === "string" ? draft.source : "draft";
    if (typeof draft.countryKey === "string" && draft.countryKey) {
      state.systemPromptCountry = draft.countryKey;
    }

    return draft;
  }

  async function loadContentHistory() {
    try {
      const response = await fetch(`${CONTENT_HISTORY_API_URL}?cb=${Date.now()}`, {
        headers: {
          Accept: "application/json",
        },
      });
      const data = await response.json();
      if (!response.ok || !data?.success) {
        throw new Error(data?.error || `HTTP ${response.status}`);
      }

      state.history = Array.isArray(data.threads) ? data.threads.filter(isHistoryItem).slice(0, 50) : [];
      state.threadStats = {
        totalThreads: Number(data?.stats?.totalThreads || 0),
        activeThreads: Number(data?.stats?.activeThreads || 0),
        completedThreads: Number(data?.stats?.completedThreads || 0),
        avgDurationSeconds:
          typeof data?.stats?.avgDurationSeconds === "number" ? data.stats.avgDurationSeconds : null,
      };
      renderHistory();
    } catch (error) {
      console.error(error);
      setRequestStatus(
        `Historie konnte nicht geladen werden. ${error instanceof Error ? error.message : "Unbekannter Fehler."}`,
        "error"
      );
    }
  }

  async function postThreadApi(params) {
    const body = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
      if (value === undefined || value === null) continue;
      body.set(key, String(value));
    }

    const response = await fetch(CONTENT_HISTORY_API_URL, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        Accept: "application/json",
      },
      body,
    });

    const data = await response.json();
    if (!response.ok || !data?.success) {
      throw new Error(data?.error || `HTTP ${response.status}`);
    }

    state.history = Array.isArray(data.threads) ? data.threads.filter(isHistoryItem).slice(0, 50) : [];
    state.threadStats = {
      totalThreads: Number(data?.stats?.totalThreads || 0),
      activeThreads: Number(data?.stats?.activeThreads || 0),
      completedThreads: Number(data?.stats?.completedThreads || 0),
      avgDurationSeconds:
        typeof data?.stats?.avgDurationSeconds === "number" ? data.stats.avgDurationSeconds : null,
    };
    renderHistory();
    return data;
  }

  async function createContentThread(payload) {
    const promptCountryKey = getSelectedDraftCountryKey();
    const data = await postThreadApi({
      action: "create_thread",
      assignee: payload.assignee || "",
      country: payload.country || "",
      countryKey: promptCountryKey,
      title: payload.title || "",
      prompt: payload.prompt || "",
      systemPrompt: payload.systemPrompt || DEFAULT_SYSTEM_PROMPT,
    });
    return data?.thread || null;
  }

  async function markThreadFailed(threadId, errorMessage) {
    await postThreadApi({
      action: "mark_thread_failed",
      threadId,
      errorMessage,
    });
  }

  async function deleteContentHistoryEntry(item) {
    try {
      await postThreadApi({
        action: "delete_thread",
        threadId: item.id,
      });
      setRequestStatus(`"${item.title}" wurde als Thread entfernt.`, null);
    } catch (error) {
      console.error(error);
      setRequestStatus(
        `Loeschen fehlgeschlagen. ${error instanceof Error ? error.message : "Unbekannter Fehler."}`,
        "error"
      );
    }
  }

  async function createRevisionComment(thread, authorName, comment) {
    const data = await postThreadApi({
      action: "create_comment",
      threadId: thread.id,
      authorName,
      comment,
    });
    return {
      commentId: Number(data?.commentId || 0),
      thread: data?.thread || null,
    };
  }

  async function markCommentFailed(threadId, parentMessageId, errorMessage) {
    await postThreadApi({
      action: "mark_comment_failed",
      threadId,
      parentMessageId,
      errorMessage,
    });
  }

  function loadThreadIntoForm(thread) {
    state.draft.assignee = thread.assignee || "";
    state.draft.country = thread.country || "";
    state.draft.title = thread.title || "";
    state.draft.prompt = thread.prompt || "";
    state.draft.source = "history";
    if (thread.countryKey && thread.systemPrompt) {
      state.systemPromptCountry = thread.countryKey;
      state.systemPrompts[thread.countryKey] = thread.systemPrompt;
      state.promptMeta[thread.countryKey].hasUnsavedChanges = false;
    }
    state.activeView = "content";
    syncInputsFromState();
    renderActiveView();
    saveState();
    queueDraftSave();
    setRequestStatus(`"${thread.title}" wurde ins Formular geladen.`, null);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function openSidebar() {
    els.sidebar.classList.add("open");
    els.sidebarOverlay.classList.add("open");
  }

  function closeSidebar() {
    els.sidebar.classList.remove("open");
    els.sidebarOverlay.classList.remove("open");
  }

  function renderActiveView() {
    const config = {
      content: {
        title: "<i class='fas fa-pen-to-square'></i> DMC Dashboard",
        subtitle: "Im Content-Modus erstellst du neue Inhalte und startest die Generierung.",
        breadcrumb: "Content",
      },
      "content-threads": {
        title: "<i class='fas fa-comments'></i> Content Threads",
        subtitle: "Hier findest du alle Content-Threads inklusive Verlauf, KI-Kommentaren und weiteren Optimierungsrunden.",
        breadcrumb: "Content / Threads",
      },
      "content-systemprompt": {
        title: "<i class='fas fa-sliders'></i> Systemprompt",
        subtitle: "Hier pflegst du länderspezifische Systemprompts mit Versionierung und Wiederherstellung.",
        breadcrumb: "Content / Systemprompt",
      },
      "seo-backlinks": {
        title: "<i class='fas fa-link'></i> DMC Backlinks",
        subtitle: "Backlink-Daten für die DMC-Domains inklusive Europamaut.",
        breadcrumb: "SEO / Backlinks",
      },
      "seo-rankings": {
        title: "<i class='fas fa-chart-line'></i> DMC Rankings",
        subtitle: "Ranking-Daten für die DMC-Domains inklusive Europamaut.",
        breadcrumb: "SEO / Rankings",
      },
    }[state.activeView];

    els.pageTitle.innerHTML = config.title;
    els.pageSubtitle.textContent = config.subtitle;
    els.breadcrumbLabel.textContent = config.breadcrumb;

    for (const view of els.views) {
      view.classList.toggle("hidden", view.dataset.view !== state.activeView);
    }

    for (const trigger of els.viewTriggers) {
      trigger.classList.toggle("active", trigger.dataset.viewTrigger === state.activeView);
    }
  }

  function formatDomainLabel(domain) {
    const labels = {
      "digitale-vignette-online.at": "Vignette AT",
      "digitale-vignette-online.cz": "Vignette CZ",
      "digitale-vignette-schweiz.de": "Schweiz",
      "digitale-vignette-slowenien.de": "Slowenien",
      "digitale-vignette-ro.online": "Rumänien",
      "digitale-vignette-ungarn.de": "Ungarn",
      "digitale-vignette-slowakei.de": "Slowakei",
      "digitale-vignette-kroatien.de": "Kroatien",
      "digitale-vignette-bulgarien.de": "Bulgarien",
      "europamaut.com": "Europamaut",
    };
    return labels[domain] || domain;
  }

  async function ensureBacklinksOverviewLoaded() {
    if (state.backlinks.loaded) return;

    try {
      const response = await fetch("/dashboards/dmc/api/backlinks.php");
      const data = await response.json();
      state.backlinks.rows = Array.isArray(data?.data) ? data.data : [];
      state.backlinks.loaded = true;
    } catch (error) {
      console.error(error);
    }
  }

  function setBacklinkDomainFilter(domain) {
    state.backlinks.selectedDomain = domain;
    els.backlinksDetailBanner.textContent = domain
      ? `Detailansicht gefiltert auf ${formatDomainLabel(domain)}.`
      : "Detailansicht für alle DMC- und Europamaut-Zieldomains.";
    applyBacklinksFrameFilters();
    saveState();
  }

  function applyBacklinksFrameFilters() {
    const frame = els.backlinksFrame;
    if (!frame) return;
    const base = "/dashboards/dmc/backlinks.php";
    const domain = state.backlinks.selectedDomain || "";
    const target = domain ? `${base}?domain=${encodeURIComponent(domain)}` : base;
    if (frame.src !== target && !frame.src.endsWith(target.replace(/^\//, ""))) {
      frame.src = target;
    }
  }

  function applyRankingsFrameFilters() {
    // DMC rankings page is already pre-filtered to DMC domains – no cross-frame manipulation needed.
  }

  function setActiveView(view) {
    if (!["content", "content-threads", "content-systemprompt", "seo-backlinks", "seo-rankings"].includes(view)) return;
    state.activeView = view;
    renderActiveView();
    if (view === "seo-backlinks") {
      ensureBacklinksOverviewLoaded();
      applyBacklinksFrameFilters();
    }
    if (view === "seo-rankings") {
      applyRankingsFrameFilters();
    }
    saveState();
    closeSidebar();
  }

  async function fetchSystemPromptVersions(countryKey) {
    const response = await fetch(`${SYSTEM_PROMPT_API_URL}?country=${encodeURIComponent(countryKey)}`);
    const data = await response.json();
    if (!response.ok || !data?.success) {
      throw new Error(data?.error || `HTTP ${response.status}`);
    }

    state.promptVersions[countryKey] = Array.isArray(data.versions) ? data.versions : [];
    if (data.latest?.prompt_text) {
      state.systemPrompts[countryKey] = data.latest.prompt_text;
      state.promptMeta[countryKey].latestSavedPrompt = data.latest.prompt_text;
      state.promptMeta[countryKey].lastSavedAt = data.latest.created_at || null;
      state.promptMeta[countryKey].latestVersionId = data.latest.id || null;
      state.promptMeta[countryKey].hasUnsavedChanges = false;
    }
  }

  async function loadAllSystemPromptData() {
    try {
      const response = await fetch(SYSTEM_PROMPT_API_URL);
      const data = await response.json();
      if (!response.ok || !data?.success) {
        throw new Error(data?.error || `HTTP ${response.status}`);
      }

      for (const country of COUNTRY_OPTIONS) {
        const latest = data.latestByCountry?.[country.key];
        if (latest?.prompt_text) {
          state.systemPrompts[country.key] = latest.prompt_text;
          state.promptMeta[country.key].latestSavedPrompt = latest.prompt_text;
          state.promptMeta[country.key].lastSavedAt = latest.created_at || null;
          state.promptMeta[country.key].latestVersionId = latest.id || null;
          state.promptMeta[country.key].hasUnsavedChanges = false;
        }
      }

      await fetchSystemPromptVersions(state.systemPromptCountry);
      syncInputsFromState();
    } catch (error) {
      console.error(error);
      setSystemPromptStatus(
        `Prompt-Versionen konnten nicht geladen werden. ${error instanceof Error ? error.message : ""}`.trim(),
        "error"
      );
    }
  }

  function queuePromptAutosave() {
    if (promptAutosaveTimer) {
      clearTimeout(promptAutosaveTimer);
      promptAutosaveTimer = null;
    }

    const promptValue = state.systemPrompts[state.systemPromptCountry] || DEFAULT_SYSTEM_PROMPT;
    const latestSavedPrompt = state.promptMeta[state.systemPromptCountry].latestSavedPrompt || DEFAULT_SYSTEM_PROMPT;
    if (promptValue === latestSavedPrompt) {
      renderSystemPromptMeta();
      return;
    }

    promptAutosaveTimer = setTimeout(() => {
      persistSystemPrompt("autosave");
    }, AUTOSAVE_DELAY_MS);

    renderSystemPromptMeta();
  }

  async function persistSystemPrompt(saveMode = "manual") {
    syncStateFromInputs();
    const countryKey = state.systemPromptCountry;
    const promptText = state.systemPrompts[countryKey] || DEFAULT_SYSTEM_PROMPT;
    if (els.saveSystemPromptButton) {
      els.saveSystemPromptButton.disabled = true;
    }

    setSystemPromptStatus(
      saveMode === "autosave" ? "Systemprompt wird automatisch gespeichert..." : "Systemprompt wird gespeichert...",
      null
    );

    try {
      const response = await fetch(SYSTEM_PROMPT_API_URL, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          country: countryKey,
          prompt: promptText,
          saveMode,
        }),
      });

      const data = await response.json();
      if (!response.ok || !data?.success) {
        throw new Error(data?.error || `HTTP ${response.status}`);
      }

      state.promptVersions[countryKey] = Array.isArray(data.versions) ? data.versions : [];
      if (data.latest?.prompt_text) {
        state.systemPrompts[countryKey] = data.latest.prompt_text;
        state.promptMeta[countryKey].latestSavedPrompt = data.latest.prompt_text;
        state.promptMeta[countryKey].lastSavedAt = data.latest.created_at || null;
        state.promptMeta[countryKey].latestVersionId = data.latest.id || null;
      }
      state.promptMeta[countryKey].hasUnsavedChanges = false;

      syncInputsFromState();
      saveState();
      setSystemPromptStatus(
        data.skipped
          ? "Keine neue Version gespeichert, da sich der Prompt nicht geändert hat."
          : `Systemprompt gespeichert (${saveMode}).`,
        "success"
      );
    } catch (error) {
      console.error(error);
      setSystemPromptStatus(
        `Speichern fehlgeschlagen. ${error instanceof Error ? error.message : "Unbekannter Fehler."}`,
        "error"
      );
    } finally {
      if (els.saveSystemPromptButton) {
        els.saveSystemPromptButton.disabled = false;
      }
      if (promptAutosaveTimer) {
        clearTimeout(promptAutosaveTimer);
        promptAutosaveTimer = null;
      }
      renderSystemPromptMeta();
    }
  }

  async function deleteSystemPromptVersion(version) {
    if (els.saveSystemPromptButton) {
      els.saveSystemPromptButton.disabled = true;
    }

    setSystemPromptStatus("Version wird geloescht...", null);

    try {
      const response = await fetch(SYSTEM_PROMPT_API_URL, {
        method: "DELETE",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          id: version.id,
          country: version.country_key,
        }),
      });

      const data = await response.json();
      if (!response.ok || !data?.success) {
        throw new Error(data?.error || `HTTP ${response.status}`);
      }

      const countryKey = version.country_key;
      state.promptVersions[countryKey] = Array.isArray(data.versions) ? data.versions : [];

      if (data.latest?.prompt_text) {
        state.systemPrompts[countryKey] = data.latest.prompt_text;
        state.promptMeta[countryKey].latestSavedPrompt = data.latest.prompt_text;
        state.promptMeta[countryKey].lastSavedAt = data.latest.created_at || null;
        state.promptMeta[countryKey].latestVersionId = data.latest.id || null;
      } else {
        state.systemPrompts[countryKey] = DEFAULT_SYSTEM_PROMPT;
        state.promptMeta[countryKey].latestSavedPrompt = DEFAULT_SYSTEM_PROMPT;
        state.promptMeta[countryKey].lastSavedAt = null;
        state.promptMeta[countryKey].latestVersionId = null;
      }

      state.promptMeta[countryKey].hasUnsavedChanges = false;

      if (state.systemPromptCountry === countryKey) {
        syncInputsFromState();
      } else {
        renderSystemPromptVersions();
        renderSystemPromptMeta();
      }

      saveState();
      setSystemPromptStatus(`Version #${version.id} wurde geloescht.`, "success");
    } catch (error) {
      console.error(error);
      setSystemPromptStatus(
        `Loeschen fehlgeschlagen. ${error instanceof Error ? error.message : "Unbekannter Fehler."}`,
        "error"
      );
    } finally {
      if (els.saveSystemPromptButton) {
        els.saveSystemPromptButton.disabled = false;
      }
    }
  }

  async function startContentWorkflow(thread, payload) {
    const response = await fetch(WORKFLOW_PROXY_API_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "content_generate",
        payload: {
          ...payload,
          threadId: thread.id,
          dashboardApiUrl: `${window.location.origin}/dashboards/dmc/api/content_history.php`,
          dashboardThreadId: thread.id,
        },
      }),
    });

    const responseData = await response.json().catch(() => null);
    const responseBody = responseData?.body || "";
    if (!response.ok || !responseData?.success) {
      throw new Error(`Webhook antwortete mit HTTP ${response.status}${responseBody ? `: ${responseBody}` : ""}`);
    }

    state.draft.assignee = "";
    state.draft.country = "";
    state.draft.title = "";
    state.draft.prompt = "";
    state.draft.source = "empty";
    syncInputsFromState();
    saveState();
    queueDraftSave();

    setRequestStatus("Workflow gestartet. Der Fortschrittsbalken im Thread laeuft bis zur finalen Generierung.", "success");
  }

  function renderQuestionCard() {
    const qc = els.questionCard;
    if (!qc || !state.questionMode) return;

    const { thread, questions } = state.questionMode;

    qc.className = "question-card";
    qc.innerHTML = `
      <div class="question-card__head">
        <div class="question-card__head-icon"><i class="fas fa-circle-question"></i></div>
        <div class="question-card__head-text">
          <strong>KI-Rückfragen zu: ${escapeHtml(thread.title || "")}</strong>
          <p>Beantworte die Fragen der KI für ein präziseres Ergebnis. Alle Felder sind optional.</p>
        </div>
      </div>
      <div class="question-card__body" id="questionCardBody"></div>
      <div class="question-card__actions">
        <button type="button" class="btn btn-primary" id="questionSubmitBtn">
          <i class="fas fa-paper-plane"></i> Generierung starten
        </button>
        <button type="button" class="btn btn-secondary" id="questionCancelBtn">
          <i class="fas fa-times"></i> Abbrechen
        </button>
      </div>
    `;

    const body = document.getElementById("questionCardBody");
    questions.forEach((question, index) => {
      const item = document.createElement("div");
      item.className = "question-item";
      const label = document.createElement("label");
      label.className = "question-item__label";
      label.htmlFor = `qi-${index}`;
      label.innerHTML = `<span class="question-item__num">${index + 1}</span>${escapeHtml(question)}`;
      const textarea = document.createElement("textarea");
      textarea.id = `qi-${index}`;
      textarea.className = "form-input form-input--textarea";
      textarea.rows = 2;
      textarea.placeholder = "Deine Antwort (optional)...";
      textarea.dataset.questionIndex = String(index);
      item.appendChild(label);
      item.appendChild(textarea);
      body.appendChild(item);
    });

    document.getElementById("questionSubmitBtn").addEventListener("click", handleQuestionSubmit);
    document.getElementById("questionCancelBtn").addEventListener("click", handleQuestionCancel);

    if (els.submitButton) els.submitButton.disabled = true;
  }

  function clearQuestionCard() {
    state.questionMode = null;
    if (els.questionCard) {
      els.questionCard.className = "question-card hidden";
      els.questionCard.innerHTML = "";
    }
    if (els.submitButton) els.submitButton.disabled = false;
    if (els.askQuestionsCheckbox) els.askQuestionsCheckbox.checked = false;
  }

  async function handleQuestionSubmit() {
    if (!state.questionMode) return;
    const { thread, questions, basePayload } = state.questionMode;

    const submitBtn = document.getElementById("questionSubmitBtn");
    const cancelBtn = document.getElementById("questionCancelBtn");
    if (submitBtn) submitBtn.disabled = true;
    if (cancelBtn) cancelBtn.disabled = true;

    const qaLines = questions
      .map((q, i) => {
        const answer = (document.getElementById(`qi-${i}`)?.value || "").trim();
        return answer ? `F: ${q}\nA: ${answer}` : null;
      })
      .filter(Boolean);

    const enrichedPayload = {
      ...basePayload,
      prompt: basePayload.prompt + (qaLines.length ? "\n\n---\nBearbeiter-Antworten auf KI-Rückfragen:\n" + qaLines.join("\n\n") : ""),
    };

    try {
      setRequestStatus("Generierung wird gestartet...", null);
      await startContentWorkflow(thread, enrichedPayload);
      clearQuestionCard();
    } catch (error) {
      console.error(error);
      if (submitBtn) submitBtn.disabled = false;
      if (cancelBtn) cancelBtn.disabled = false;
      setRequestStatus(`Generierung fehlgeschlagen: ${error instanceof Error ? error.message : "Unbekannter Fehler."}`, "error");
    }
  }

  async function handleQuestionCancel() {
    const thread = state.questionMode?.thread;
    if (thread?.id) {
      try { await postThreadApi({ action: "delete_thread", threadId: thread.id }); } catch (e) { console.error(e); }
    }
    clearQuestionCard();
    setRequestStatus("Fragen-Modus abgebrochen.", null);
  }

  async function handleSubmit(event) {
    event.preventDefault();
    syncStateFromInputs();

    const payload = buildPayload();
    const askQuestions = els.askQuestionsCheckbox?.checked || false;

    els.submitButton.disabled = true;
    setRequestStatus("Thread wird angelegt...", null);

    try {
      const createdThread = await createContentThread(payload);
      if (!createdThread?.id) {
        throw new Error("Thread konnte nicht angelegt werden.");
      }

      if (askQuestions) {
        setRequestStatus("KI formuliert Rückfragen...", null);
        const qResp = await fetch("/dashboards/dmc/api/workflow_questions.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ topic: payload.title, prompt: payload.prompt, country: payload.country, assignee: payload.assignee }),
        });
        const qData = await qResp.json().catch(() => null);
        if (!qResp.ok || !qData?.success || !Array.isArray(qData.questions) || !qData.questions.length) {
          throw new Error(qData?.error || "Rückfragen konnten nicht generiert werden.");
        }
        state.questionMode = { thread: createdThread, questions: qData.questions, basePayload: payload };
        renderQuestionCard();
        setRequestStatus("Bitte beantworte die KI-Rückfragen und starte dann die Generierung.", null);
      } else {
        await startContentWorkflow(createdThread, payload);
      }
    } catch (error) {
      console.error(error);
      try {
        const newestThread = state.history[0];
        if (newestThread?.status === "queued" && newestThread?.generatedContent == null) {
          await markThreadFailed(newestThread.id, error instanceof Error ? error.message : "Unbekannter Fehler.");
        }
      } catch (markError) {
        console.error(markError);
      }
      setRequestStatus(
        `Senden fehlgeschlagen. ${error instanceof Error ? error.message : "Unbekannter Fehler."}`,
        "error"
      );
    } finally {
      if (!state.questionMode) els.submitButton.disabled = false;
    }
  }

  async function submitRevisionComment(threadId) {
    const thread = state.history.find((entry) => String(entry.id) === String(threadId));
    if (!thread) return;

    const article = els.historyList.querySelector(`[data-thread-id="${threadId}"]`);
    if (!article) return;

    const authorInput = article.querySelector(`[data-thread-comment-author="${threadId}"]`);
    const commentInput = article.querySelector(`[data-thread-comment-text="${threadId}"]`);
    const authorName = authorInput?.value?.trim() || thread.assignee || "DMC Team";
    const comment = commentInput?.value?.trim() || "";
    const latestContent = getLatestThreadContent(thread);
    const latestAiComment = getLatestThreadInsight(thread, "ai_comment");
    const latestEditorRecommendation = getLatestThreadInsight(thread, "ai_recommendation");

    if (!comment) {
      setRequestStatus("Bitte erst einen Kommentar fuer die Ueberarbeitung eingeben.", "error");
      return;
    }

    if (!latestContent) {
      setRequestStatus("Es gibt noch keinen Textstand, der weiter optimiert werden kann.", "error");
      return;
    }

    try {
      const { commentId } = await createRevisionComment(thread, authorName, comment);
      setRequestStatus("Kommentar gespeichert. Der Revisions-Workflow wurde gestartet.", "success");

      if (commentInput) commentInput.value = "";

      const response = await fetch(WORKFLOW_PROXY_API_URL, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          action: "content_revision",
          payload: {
            threadId: thread.id,
            parentMessageId: commentId,
            title: thread.title,
            assignee: thread.assignee,
            country: thread.country,
            countryKey: thread.countryKey,
            prompt: thread.prompt,
            systemPrompt: thread.systemPrompt,
            originalContent: latestContent,
            latestAiComment,
            latestEditorRecommendation,
            comment,
            authorName,
            dashboardApiUrl: `${window.location.origin}/dashboards/dmc/api/content_history.php`,
          },
        }),
      });

      const responseData = await response.json().catch(() => null);
      const responseBody = responseData?.body || "";
      if (!response.ok || !responseData?.success) {
        throw new Error(
          `Revisions-Webhook antwortete mit HTTP ${response.status}${responseBody ? `: ${responseBody}` : ""}`
        );
      }
    } catch (error) {
      console.error(error);
      const pendingComment = state.history
        .find((entry) => String(entry.id) === String(threadId))
        ?.messages?.filter((message) => message.messageType === "comment")
        ?.slice(-1)?.[0];
      if (pendingComment?.id) {
        try {
          await markCommentFailed(threadId, pendingComment.id, error instanceof Error ? error.message : "Unbekannter Fehler.");
        } catch (markError) {
          console.error(markError);
        }
      }
      setRequestStatus(
        `Kommentar konnte nicht verarbeitet werden. ${error instanceof Error ? error.message : "Unbekannter Fehler."}`,
        "error"
      );
    }
  }

  function handleHistoryListClick(event) {
    const interactiveTarget = event.target.closest("button, a, input, select, textarea, label");
    const actionTarget = event.target.closest("[data-action]");
    const threadCard = event.target.closest("[data-thread-id]");
    const fallbackThreadId = threadCard?.dataset.threadId;
    const actionThreadId = actionTarget?.dataset.threadId;
    const threadId = actionThreadId || fallbackThreadId;
    if (!threadId) return;

    const thread = state.history.find((entry) => String(entry.id) === String(threadId));
    if (!thread) return;

    const article = els.historyList.querySelector(`[data-thread-id="${threadId}"]`);
    if (!article) return;

    const toggleThreadCard = () => {
      const isCollapsed = article.classList.toggle("is-collapsed");
      setThreadExpanded(threadId, !isCollapsed);
      const toggleButton = article.querySelector('[data-action="toggle-thread"]');
      if (toggleButton) {
        toggleButton.setAttribute("aria-expanded", String(!isCollapsed));
        toggleButton.innerHTML = isCollapsed
          ? "<i class='fas fa-chevron-down'></i> Ausklappen"
          : "<i class='fas fa-chevron-up'></i> Einklappen";
      }
    };

    if (!actionTarget) {
      if (!threadCard || interactiveTarget) return;
      toggleThreadCard();
      return;
    }

    if (actionTarget.dataset.action === "toggle-thread") {
      toggleThreadCard();
      return;
    }

    if (actionTarget.dataset.action === "load-thread") {
      loadThreadIntoForm(thread);
      return;
    }

    if (actionTarget.dataset.action === "copy-document-link") {
      copyTextToClipboard(thread.documentUrl)
        .then((copied) => {
          if (!copied) {
            setRequestStatus("Der Link konnte nicht in die Zwischenablage kopiert werden.", "error");
            return;
          }
          const original = actionTarget.innerHTML;
          actionTarget.innerHTML = "<i class='fas fa-check'></i> Kopiert";
          window.setTimeout(() => {
            actionTarget.innerHTML = original;
          }, 1800);
          setRequestStatus("Artikel-Link wurde in die Zwischenablage kopiert.", "success");
        })
        .catch((error) => {
          console.error(error);
          setRequestStatus(
            `Der Link konnte nicht kopiert werden. ${error instanceof Error ? error.message : "Unbekannter Fehler."}`,
            "error"
          );
        });
      return;
    }

    if (actionTarget.dataset.action === "copy-content") {
      const content = thread.generatedContent || "";
      if (!content) {
        setRequestStatus("Kein generierter Content zum Kopieren vorhanden.", "error");
        return;
      }
      const original = actionTarget.innerHTML;
      copyTextToClipboard(content)
        .then((copied) => {
          if (!copied) {
            setRequestStatus("Content konnte nicht in die Zwischenablage kopiert werden.", "error");
            return;
          }
          actionTarget.innerHTML = "<i class='fas fa-check'></i> Kopiert";
          window.setTimeout(() => { actionTarget.innerHTML = original; }, 1800);
          setRequestStatus("Generierter Content wurde in die Zwischenablage kopiert.", "success");
        })
        .catch((error) => {
          console.error(error);
          setRequestStatus(`Content konnte nicht kopiert werden. ${error instanceof Error ? error.message : ""}`.trim(), "error");
        });
      return;
    }

    if (actionTarget.dataset.action === "delete-thread") {
      const confirmed = window.confirm("Soll dieser Thread inklusive Kommentaren fuer alle geloescht werden?");
      if (!confirmed) return;
      deleteContentHistoryEntry(thread);
      return;
    }

    if (actionTarget.dataset.action === "submit-comment") {
      submitRevisionComment(threadId);
    }
  }

  function renderClock() {
    els.topbarTime.textContent = new Date().toLocaleString("de-DE", {
      dateStyle: "short",
      timeStyle: "medium",
    });
  }

  function initializeApp() {
    loadState();
    applyTheme();
    applyDraftFromQueryParams();
    syncInputsFromState();
    renderHistory();
    renderActiveView();
    renderClock();
    loadContentHistory();
    threadRefreshTimer = setInterval(loadContentHistory, THREAD_REFRESH_INTERVAL_MS);
    loadAllSystemPromptData();

    if (state.activeView === "seo-backlinks") {
      ensureBacklinksOverviewLoaded();
      applyBacklinksFrameFilters();
    }
    if (state.activeView === "seo-rankings") {
      applyRankingsFrameFilters();
    }

    loadSharedDraft()
      .then((draft) => {
        if (!draft) return;
        if (state.draft.source === "url" || state.draft.source === "history") return;
        syncInputsFromState();
      })
      .catch((error) => {
        console.error(error);
        setRequestStatus(
          `Gemeinsamer Entwurf konnte nicht geladen werden. ${error instanceof Error ? error.message : "Unbekannter Fehler."}`,
          "error"
        );
      });
  }

  clockTimer = setInterval(renderClock, 1000);

  els.form.addEventListener("submit", handleSubmit);
  els.historyList.addEventListener("click", handleHistoryListClick);
  els.clearButton.addEventListener("click", clearForm);
  els.themeToggle?.addEventListener("click", toggleTheme);
  els.systemPrompt?.addEventListener("input", () => {
    saveDraft();
    queuePromptAutosave();
  });
  els.systemPromptCountry?.addEventListener("change", async () => {
    state.systemPromptCountry = els.systemPromptCountry.value;
    await fetchSystemPromptVersions(state.systemPromptCountry);
    syncInputsFromState();
    saveState();
  });
  els.saveSystemPromptButton?.addEventListener("click", () => persistSystemPrompt("manual"));
  els.contentType?.addEventListener("change", saveDraft);
  els.assignee.addEventListener("change", saveDraft);
  els.country.addEventListener("change", saveDraft);
  els.title.addEventListener("input", saveDraft);
  els.prompt.addEventListener("input", saveDraft);
  els.sidebarToggle.addEventListener("click", openSidebar);
  els.mobileMenuBtn.addEventListener("click", openSidebar);
  els.sidebarOverlay.addEventListener("click", closeSidebar);
  els.backlinksFrame?.addEventListener("load", applyBacklinksFrameFilters);
  els.rankingsFrame?.addEventListener("load", applyRankingsFrameFilters);
  els.btnShowAllBacklinks?.addEventListener("click", () => setBacklinkDomainFilter(""));
  els.btnFocusEuropamaut?.addEventListener("click", () => setBacklinkDomainFilter("europamaut.com"));
  els.threadFilterAssignee?.addEventListener("change", updateThreadFiltersFromInputs);
  els.threadFilterCountry?.addEventListener("change", updateThreadFiltersFromInputs);
  els.threadFilterDateFrom?.addEventListener("change", updateThreadFiltersFromInputs);
  els.threadFilterDateTo?.addEventListener("change", updateThreadFiltersFromInputs);
  els.resetThreadFiltersButton?.addEventListener("click", resetThreadFilters);

  for (const trigger of els.viewTriggers) {
    trigger.addEventListener("click", () => setActiveView(trigger.dataset.viewTrigger));
  }
  initializeApp();

  window.addEventListener("beforeunload", () => {
    if (clockTimer) clearInterval(clockTimer);
    if (promptAutosaveTimer) clearTimeout(promptAutosaveTimer);
    if (threadRefreshTimer) clearInterval(threadRefreshTimer);
    if (draftSaveTimer) clearTimeout(draftSaveTimer);
  });
})();
