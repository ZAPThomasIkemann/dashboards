(() => {
  const STORAGE_KEY = "dmc_content_dashboard_v2";
  const WEBHOOK_URL = "https://zap-hosting.app.n8n.cloud/webhook/dmc-content-dashboard";
  const DEFAULT_SYSTEM_PROMPT =
    "Du bist der Content-Assistent von DMC. Beruecksichtige Unternehmensprofil, Zielgruppen, Leistungsangebot, Tonalitaet und Qualitaetsstandards.";

  const els = {
    form: document.getElementById("contentForm"),
    title: document.getElementById("contentTitle"),
    prompt: document.getElementById("contentPrompt"),
    submitButton: document.getElementById("submitButton"),
    clearButton: document.getElementById("btnClearForm"),
    requestStatus: document.getElementById("requestStatus"),
    historyList: document.getElementById("historyList"),
  };

  let state = {
    draft: {
      title: "",
      prompt: "",
    },
    history: [],
  };

  function uid() {
    if (typeof crypto !== "undefined" && crypto.randomUUID) return crypto.randomUUID();
    return `content_${Date.now()}_${Math.random().toString(16).slice(2)}`;
  }

  function loadState() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      const data = JSON.parse(raw);
      state = {
        draft: {
          title: typeof data?.draft?.title === "string" ? data.draft.title : "",
          prompt: typeof data?.draft?.prompt === "string" ? data.draft.prompt : "",
        },
        history: Array.isArray(data?.history)
          ? data.history.filter(isHistoryItem).slice(0, 50)
          : [],
      };
    } catch (error) {
      console.error(error);
    }
  }

  function isHistoryItem(item) {
    return (
      item &&
      typeof item.id === "string" &&
      typeof item.title === "string" &&
      typeof item.prompt === "string" &&
      typeof item.submittedAt === "string"
    );
  }

  function saveState() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  }

  function syncInputsFromState() {
    els.title.value = state.draft.title;
    els.prompt.value = state.draft.prompt;
  }

  function syncStateFromInputs() {
    state.draft.title = els.title.value.trim();
    state.draft.prompt = els.prompt.value.trim();
  }

  function setRequestStatus(message, kind) {
    els.requestStatus.textContent = message;
    els.requestStatus.classList.remove("is-success", "is-error");
    if (kind === "success") els.requestStatus.classList.add("is-success");
    if (kind === "error") els.requestStatus.classList.add("is-error");
  }

  function buildPayload() {
    return {
      title: state.draft.title,
      prompt: state.draft.prompt,
      systemPrompt: DEFAULT_SYSTEM_PROMPT,
      meta: {
        source: "dmc-content-dashboard",
        submittedAt: new Date().toISOString(),
      },
    };
  }

  function renderHistory() {
    els.historyList.innerHTML = "";

    if (state.history.length === 0) {
      const empty = document.createElement("div");
      empty.className = "empty-state";
      empty.textContent = "Noch keine abgeschickten Inhalte vorhanden.";
      els.historyList.appendChild(empty);
      return;
    }

    for (const item of state.history) {
      els.historyList.appendChild(buildHistoryItem(item));
    }
  }

  function buildHistoryItem(item) {
    const article = document.createElement("article");
    article.className = "history-item";

    const head = document.createElement("div");
    head.className = "history-item__head";

    const titleWrap = document.createElement("div");
    const title = document.createElement("h3");
    title.textContent = item.title || "Ohne Titel";
    titleWrap.appendChild(title);

    const time = document.createElement("time");
    time.dateTime = item.submittedAt;
    time.textContent = new Date(item.submittedAt).toLocaleString("de-DE");

    head.appendChild(titleWrap);
    head.appendChild(time);

    const prompt = document.createElement("p");
    prompt.textContent = item.prompt;

    const button = document.createElement("button");
    button.type = "button";
    button.className = "btn btn--ghost btn--small";
    button.textContent = "In Formular laden";
    button.addEventListener("click", () => {
      state.draft.title = item.title;
      state.draft.prompt = item.prompt;
      syncInputsFromState();
      saveState();
      setRequestStatus(`"${item.title}" wurde ins Formular geladen.`, null);
      window.scrollTo({ top: 0, behavior: "smooth" });
    });

    article.appendChild(head);
    article.appendChild(prompt);
    article.appendChild(button);
    return article;
  }

  function clearForm() {
    state.draft.title = "";
    state.draft.prompt = "";
    syncInputsFromState();
    saveState();
    setRequestStatus("Formular geleert.", null);
  }

  function saveDraft() {
    syncStateFromInputs();
    saveState();
  }

  async function handleSubmit(event) {
    event.preventDefault();
    syncStateFromInputs();

    const payload = buildPayload();
    els.submitButton.disabled = true;
    setRequestStatus("Content wird an n8n gesendet...", null);

    try {
      const response = await fetch(WEBHOOK_URL, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify(payload),
      });

      const responseBody = await response.text();
      if (!response.ok) {
        throw new Error(
          `Webhook antwortete mit HTTP ${response.status}${responseBody ? `: ${responseBody}` : ""}`
        );
      }

      state.history.unshift({
        id: uid(),
        title: payload.title,
        prompt: payload.prompt,
        submittedAt: payload.meta.submittedAt,
      });
      state.history = state.history.slice(0, 50);
      saveState();
      renderHistory();

      setRequestStatus(
        `Content erfolgreich abgeschickt.${responseBody ? ` Antwort: ${responseBody}` : ""}`,
        "success"
      );
    } catch (error) {
      console.error(error);
      setRequestStatus(
        `Senden fehlgeschlagen. ${error instanceof Error ? error.message : "Unbekannter Fehler."}`,
        "error"
      );
    } finally {
      els.submitButton.disabled = false;
    }
  }

  loadState();
  syncInputsFromState();
  renderHistory();

  els.form.addEventListener("submit", handleSubmit);
  els.clearButton.addEventListener("click", clearForm);
  els.title.addEventListener("input", saveDraft);
  els.prompt.addEventListener("input", saveDraft);
})();
