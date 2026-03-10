(() => {
  const config = window.SharedDocsData;
  if (!config) {
    return;
  }

  const initialized = new WeakSet();
  const initializedBrowser = new WeakSet();

  const getHeaders = (asJson = true) => {
    const headers = { "X-WP-Nonce": config.nonce };
    if (asJson) {
      headers["Content-Type"] = "application/json";
    }
    return headers;
  };

  const toApiUrl = (path) => `${config.restBase}${path}`.replace(/([^:]\/)\/+/g, "$1");

  const requestJson = async (path, options = {}) => {
    const response = await fetch(toApiUrl(path), {
      method: options.method || "GET",
      headers: getHeaders(options.method === "POST"),
      credentials: "same-origin",
      body: options.body ? JSON.stringify(options.body) : undefined,
    });
    if (!response.ok) {
      let message = config.messages.requestError;
      try {
        const json = await response.json();
        if (json && json.message) {
          message = json.message;
        }
      } catch (e) {
        // noop
      }
      throw new Error(message);
    }
    return response.json();
  };

  const requestBlob = async (path) => {
    const response = await fetch(toApiUrl(path), {
      method: "GET",
      headers: getHeaders(false),
      credentials: "same-origin",
    });
    if (!response.ok) {
      let message = config.messages.downloadError;
      try {
        const json = await response.json();
        if (json && json.message) {
          message = json.message;
        }
      } catch (e) {
        // noop
      }
      throw new Error(message);
    }
    return response.blob();
  };

  const isPreviewableMime = (mime) => {
    const value = String(mime || "").toLowerCase();
    return value === "application/pdf" || value.startsWith("image/");
  };

  const extensionFromName = (name) => {
    const parts = String(name || "").split(".");
    return parts.length < 2 ? "" : parts.pop().toLowerCase();
  };

  const resolveBookType = (name) => {
    const ext = extensionFromName(name);
    if (["xls", "xlsx", "xlsm", "xlsb", "csv", "ods"].includes(ext)) {
      return ext === "xls" ? "biff8" : ext;
    }
    return "xlsx";
  };

  const formatBytes = (bytes) => {
    const value = Number(bytes || 0);
    if (value <= 0) return "0 B";
    const units = ["B", "KB", "MB", "GB"];
    const index = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
    const size = value / Math.pow(1024, index);
    return `${size.toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
  };

  const createIcon = (svg, kind) => {
    const wrapper = document.createElement("span");
    wrapper.className = `shared-docs-icon shared-docs-type-icon shared-docs-type-icon--${kind}`;
    wrapper.innerHTML = String(svg || "");
    return wrapper;
  };

  const initManager = (root) => {
    if (!root || initialized.has(root)) {
      return;
    }
    initialized.add(root);

    const folderRegion = root.querySelector('[data-region="folders"]');
    const fileRegion = root.querySelector('[data-region="files"]');
    const folderSection = root.querySelector('[data-section="folders"]');
    const fileSection = root.querySelector('[data-section="files"]');
    const directoryEmptyRegion = root.querySelector('[data-region="directory-empty"]');
    const breadcrumbRegion = root.querySelector(".shared-docs-breadcrumb");
    const goRootButton = root.querySelector('[data-action="go-root"]');

    const excelModal = document.getElementById("shared-docs-excel-modal");
    const excelTitle = excelModal ? excelModal.querySelector(".shared-docs-modal__title") : null;
    const excelTable = excelModal ? excelModal.querySelector('[data-region="excel-table"]') : null;
    const excelHint = excelModal ? excelModal.querySelector('[data-region="excel-hint"]') : null;
    const saveExcelButton = excelModal ? excelModal.querySelector('[data-action="save-excel"]') : null;
    const closeExcelButtons = excelModal ? excelModal.querySelectorAll('[data-action="close-modal"]') : [];

    const previewModal = document.getElementById("shared-docs-file-preview-modal");
    const previewTitle = previewModal ? previewModal.querySelector('[data-region="preview-title"]') : null;
    const previewBody = previewModal ? previewModal.querySelector('[data-region="preview-body"]') : null;
    const previewDownloadButton = previewModal ? previewModal.querySelector('[data-action="preview-download"]') : null;
    const closePreviewButtons = previewModal ? previewModal.querySelectorAll('[data-action="close-preview-modal"]') : [];

    const state = {
      currentFolderId: null,
      folders: [],
      files: [],
      breadcrumb: [],
      excelEditor: null,
      previewFile: null,
      previewObjectUrl: "",
      loadingFolder: false,
      busyDownloadIds: {},
    };

    const setVisibility = (el, visible) => {
      if (el) el.hidden = !visible;
    };

    const showInlineMessage = (target, text) => {
      target.innerHTML = "";
      const el = document.createElement("div");
      el.className = "shared-docs-empty";
      el.textContent = text;
      target.appendChild(el);
    };

    const showToast = (text, type = "info") => {
      const toast = document.createElement("div");
      toast.className = `shared-docs-toast shared-docs-toast-${type}`;
      toast.textContent = text;
      document.body.appendChild(toast);
      window.setTimeout(() => toast.classList.add("visible"), 10);
      window.setTimeout(() => {
        toast.classList.remove("visible");
        window.setTimeout(() => toast.remove(), 240);
      }, 2800);
    };

    const setButtonLoading = (button, loading, loadingText) => {
      if (!button) return;
      const tag = (button.tagName || "").toUpperCase();
      const isInput = tag === "INPUT";
      const isButton = tag === "BUTTON";
      if (loading) {
        if (button.dataset.loading === "1") {
          return;
        }
        if (!button.dataset.originalLabel) {
          button.dataset.originalLabel = isInput ? button.value || "" : button.textContent || "";
        }
        button.dataset.loading = "1";
        button.disabled = true;
        button.classList.add("is-loading");
        button.setAttribute("aria-busy", "true");
        const label = loadingText || button.dataset.originalLabel;
        if (isInput) {
          button.value = label;
        } else if (isButton) {
          button.innerHTML = "";
          const labelNode = document.createElement("span");
          labelNode.className = "shared-docs-btn-label";
          labelNode.textContent = label;
          button.appendChild(labelNode);
          const spinner = document.createElement("span");
          spinner.className = "shared-docs-btn-spinner";
          spinner.setAttribute("aria-hidden", "true");
          button.appendChild(spinner);
        } else {
          button.textContent = label;
        }
        return;
      }
      button.dataset.loading = "0";
      button.disabled = false;
      button.classList.remove("is-loading");
      button.removeAttribute("aria-busy");
      if (button.dataset.originalLabel) {
        if (isInput) {
          button.value = button.dataset.originalLabel;
        } else if (isButton) {
          button.innerHTML = "";
          const labelNode = document.createElement("span");
          labelNode.className = "shared-docs-btn-label";
          labelNode.textContent = button.dataset.originalLabel;
          button.appendChild(labelNode);
        } else {
          button.textContent = button.dataset.originalLabel;
        }
      }
    };

    const setContainerLoading = (container, text) => {
      if (!container) {
        return { stop: () => {} };
      }
      container.innerHTML = "";
      const loading = document.createElement("div");
      loading.className = "shared-docs-loading";
      const spinner = document.createElement("span");
      spinner.className = "shared-docs-loading__spinner";
      const label = document.createElement("span");
      label.textContent = text || config.messages.loading || "Cargando...";
      loading.appendChild(spinner);
      loading.appendChild(label);
      container.appendChild(loading);
      return {
        stop: () => {
          if (loading.parentNode === container) {
            loading.parentNode.removeChild(loading);
          }
        },
      };
    };

    const renderBreadcrumb = () => {
      breadcrumbRegion.innerHTML = "";
      const rootLink = document.createElement("button");
      rootLink.type = "button";
      rootLink.className = "shared-docs-breadcrumb__item";
      rootLink.textContent = "Inicio";
      rootLink.addEventListener("click", () => loadFolder(null));
      breadcrumbRegion.appendChild(rootLink);

      state.breadcrumb.forEach((item, index) => {
        const sep = document.createElement("span");
        sep.className = "shared-docs-breadcrumb__sep";
        sep.textContent = "›";
        breadcrumbRegion.appendChild(sep);

        const crumb = document.createElement("button");
        crumb.type = "button";
        crumb.className = "shared-docs-breadcrumb__item";
        crumb.textContent = item.title;
        crumb.disabled = index === state.breadcrumb.length - 1;
        crumb.addEventListener("click", () => loadFolder(item.id));
        breadcrumbRegion.appendChild(crumb);
      });
    };

    const startDownload = async (file, triggerButton = null) => {
      if (!file || !file.can_download) {
        showToast(config.messages.permissionError, "error");
        return;
      }
      const lockKey = String(file.id || "");
      if (state.busyDownloadIds[lockKey]) {
        return;
      }
      state.busyDownloadIds[lockKey] = true;
      setButtonLoading(triggerButton, true, config.messages.processing || "Procesando...");
      try {
        const blob = await requestBlob(`download/${file.id}`);
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = file.filename || file.title || `archivo-${file.id}`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
      } catch (error) {
        showToast(error.message || config.messages.downloadError, "error");
      } finally {
        delete state.busyDownloadIds[lockKey];
        setButtonLoading(triggerButton, false);
      }
    };

    const closePreview = () => {
      if (!previewModal) return;
      previewModal.hidden = true;
      state.previewFile = null;
      setButtonLoading(previewDownloadButton, false);
      if (previewBody) previewBody.innerHTML = "";
      if (state.previewObjectUrl) {
        window.URL.revokeObjectURL(state.previewObjectUrl);
        state.previewObjectUrl = "";
      }
    };

    const openPreview = async (file) => {
      if (!previewModal || !previewBody || !file) {
        return;
      }
      if (!isPreviewableMime(file.mime_type)) {
        await startDownload(file);
        return;
      }

      const loading = setContainerLoading(previewBody, config.messages.loading || "Cargando...");
      try {
        const blob = await requestBlob(`download/${file.id}`);
        const objectUrl = window.URL.createObjectURL(blob);
        state.previewObjectUrl = objectUrl;
        state.previewFile = file;
        if (previewTitle) {
          previewTitle.textContent = file.title || "Vista previa";
        }
        if (previewDownloadButton) {
          previewDownloadButton.disabled = !file.can_download;
        }
        previewBody.innerHTML = "";
        if (String(file.mime_type).startsWith("image/")) {
          const img = document.createElement("img");
          img.src = objectUrl;
          img.alt = file.title || "";
          img.className = "shared-docs-preview-image";
          previewBody.appendChild(img);
        } else {
          const frame = document.createElement("iframe");
          frame.src = objectUrl;
          frame.className = "shared-docs-preview-frame";
          frame.title = file.title || "Vista previa";
          previewBody.appendChild(frame);
        }
        previewModal.hidden = false;
      } catch (error) {
        showToast(error.message || config.messages.requestError, "error");
      } finally {
        loading.stop();
      }
    };

    const buildEditableTable = (rows, editable) => {
      excelTable.innerHTML = "";
      const maxColumns = Math.max(1, ...rows.map((row) => row.length));
      const tbody = document.createElement("tbody");
      rows.forEach((rowData) => {
        const tr = document.createElement("tr");
        for (let col = 0; col < maxColumns; col += 1) {
          const td = document.createElement("td");
          td.contentEditable = editable ? "true" : "false";
          if (!editable) {
            td.classList.add("shared-docs-excel-table__readonly");
          }
          td.textContent = rowData[col] !== undefined ? rowData[col] : "";
          tr.appendChild(td);
        }
        tbody.appendChild(tr);
      });
      if (!rows.length) {
        const tr = document.createElement("tr");
        const td = document.createElement("td");
        td.contentEditable = editable ? "true" : "false";
        if (!editable) {
          td.classList.add("shared-docs-excel-table__readonly");
        }
        tr.appendChild(td);
        tbody.appendChild(tr);
      }
      excelTable.appendChild(tbody);
    };

    const openExcelEditor = async (file, editable) => {
      if (!excelModal || !excelTable || !window.XLSX) {
        showToast(config.messages.excelLoadError || config.messages.requestError, "error");
        return;
      }
      if (excelHint) {
        excelHint.textContent = config.messages.loading || "Cargando...";
      }
      try {
        const blob = await requestBlob(`download/${file.id}`);
        const buffer = await blob.arrayBuffer();
        const workbook = window.XLSX.read(buffer, { type: "array" });
        const sheetName = workbook.SheetNames[0];
        const firstSheet = workbook.Sheets[sheetName];
        const rows = window.XLSX.utils.sheet_to_json(firstSheet, {
          header: 1,
          raw: false,
          blankrows: true,
        });
        state.excelEditor = {
          fileId: file.id,
          sheetName,
          bookType: resolveBookType(file.filename || file.title),
          editable: !!editable,
        };
        buildEditableTable(rows, !!editable);
        if (excelHint) {
          excelHint.textContent = editable
            ? (config.messages.excelEditHint || "Haz clic sobre una celda para editarla.")
            : (config.messages.excelReadOnlyHint ||
              "Vista previa en solo lectura. No tienes permisos para editar este Excel.");
        }
        if (excelTitle) {
          excelTitle.textContent = editable ? "Editar Excel" : "Vista previa Excel";
        }
        if (saveExcelButton) {
          saveExcelButton.hidden = !editable;
        }
        excelModal.hidden = false;
      } catch (error) {
        showToast(error.message || config.messages.excelLoadError, "error");
      }
    };

    const closeExcel = () => {
      if (!excelModal) return;
      excelModal.hidden = true;
      state.excelEditor = null;
      if (excelTable) excelTable.innerHTML = "";
      if (excelHint) {
        excelHint.textContent = config.messages.excelEditHint || "Haz clic sobre una celda para editarla.";
      }
      if (excelTitle) {
        excelTitle.textContent = "Editar Excel";
      }
      if (saveExcelButton) {
        saveExcelButton.hidden = false;
        setButtonLoading(saveExcelButton, false);
      }
    };

    const collectTableData = () => {
      const rows = [];
      excelTable.querySelectorAll("tr").forEach((rowEl) => {
        const row = [];
        rowEl.querySelectorAll("td").forEach((cellEl) => row.push(cellEl.textContent || ""));
        rows.push(row);
      });
      return rows;
    };

    const saveExcel = async () => {
      if (!state.excelEditor || !window.XLSX || !state.excelEditor.editable) {
        return;
      }
      setButtonLoading(saveExcelButton, true, config.messages.processing || "Procesando...");
      try {
        const workbook = window.XLSX.utils.book_new();
        const worksheet = window.XLSX.utils.aoa_to_sheet(collectTableData());
        window.XLSX.utils.book_append_sheet(workbook, worksheet, state.excelEditor.sheetName || "Sheet1");
        const workbookBase64 = window.XLSX.write(workbook, {
          bookType: state.excelEditor.bookType || "xlsx",
          type: "base64",
        });
        await requestJson("excel/save", {
          method: "POST",
          body: { file_id: state.excelEditor.fileId, workbook_base64: workbookBase64 },
        });
        showToast(config.messages.excelSaveOk, "success");
        closeExcel();
        await loadFolder(state.currentFolderId);
      } catch (error) {
        showToast(error.message || config.messages.excelSaveError, "error");
      } finally {
        setButtonLoading(saveExcelButton, false);
      }
    };

    const openFile = async (file) => {
      if (!file) return;
      if (file.is_excel) {
        await openExcelEditor(file, !!file.can_edit_excel);
        return;
      }
      if (isPreviewableMime(file.mime_type)) {
        await openPreview(file);
        return;
      }
      await startDownload(file);
    };

    const renderFolders = () => {
      folderRegion.innerHTML = "";
      state.folders.forEach((folder) => {
        const card = document.createElement("article");
        card.className = "shared-docs-card shared-docs-card-folder";
        card.setAttribute("role", "button");
        card.tabIndex = 0;
        card.appendChild(createIcon(folder.icon_svg, "folder"));

        const title = document.createElement("h5");
        title.className = "shared-docs-card-title";
        title.textContent = folder.title;
        card.appendChild(title);

        const meta = document.createElement("p");
        meta.className = "shared-docs-card-meta";
        meta.textContent = folder.has_children ? "Contiene subcarpetas" : "Sin subcarpetas";
        card.appendChild(meta);

        const openFolder = () => loadFolder(folder.id);
        card.addEventListener("click", openFolder);
        card.addEventListener("keydown", (ev) => {
          if (ev.key === "Enter" || ev.key === " ") {
            ev.preventDefault();
            openFolder();
          }
        });
        folderRegion.appendChild(card);
      });
    };

    const renderFiles = () => {
      fileRegion.innerHTML = "";
      state.files.forEach((file) => {
        const card = document.createElement("article");
        card.className = "shared-docs-card shared-docs-card-file";
        card.appendChild(createIcon(file.icon_svg, "file"));

        const title = document.createElement("h5");
        title.className = "shared-docs-card-title";
        title.textContent = file.title;
        card.appendChild(title);

        const meta = document.createElement("p");
        meta.className = "shared-docs-card-meta";
        meta.textContent = `${formatBytes(file.size)} · ${file.filename || ""}`;
        card.appendChild(meta);

        const actions = document.createElement("div");
        actions.className = "shared-docs-card-actions";

        const openBtn = document.createElement("button");
        openBtn.type = "button";
        openBtn.className = "shared-docs-btn shared-docs-btn-primary";
        openBtn.textContent = "Abrir";
        openBtn.addEventListener("click", async () => {
          setButtonLoading(openBtn, true, config.messages.processing || "Procesando...");
          try {
            await openFile(file);
          } finally {
            setButtonLoading(openBtn, false);
          }
        });
        actions.appendChild(openBtn);

        const downloadBtn = document.createElement("button");
        downloadBtn.type = "button";
        downloadBtn.className = "shared-docs-btn shared-docs-btn-secondary";
        downloadBtn.textContent = "Descargar";
        downloadBtn.disabled = !file.can_download;
        downloadBtn.addEventListener("click", () => startDownload(file, downloadBtn));
        actions.appendChild(downloadBtn);

        card.appendChild(actions);
        fileRegion.appendChild(card);
      });
    };

    const renderDirectoryState = () => {
      const hasFolders = state.folders.length > 0;
      const hasFiles = state.files.length > 0;
      setVisibility(folderSection, hasFolders);
      setVisibility(fileSection, hasFiles);
      if (!hasFolders && !hasFiles) {
        const fallbackDirectoryName = state.currentFolderId ? "directorio actual" : "Inicio";
        const currentDirectoryName =
          state.currentFolderId && state.breadcrumb.length
            ? state.breadcrumb[state.breadcrumb.length - 1].title
            : fallbackDirectoryName;
        const template =
          (config.messages && config.messages.noDirectoryItems) ||
          'No hay archivos ni carpetas en el directorio "%s".';
        directoryEmptyRegion.textContent = template.replace("%s", currentDirectoryName);
        setVisibility(directoryEmptyRegion, true);
        return;
      }
      directoryEmptyRegion.textContent = "";
      setVisibility(directoryEmptyRegion, false);
    };

    const loadFolder = async (folderId) => {
      if (state.loadingFolder) {
        return;
      }
      state.loadingFolder = true;
      state.currentFolderId = folderId;
      setVisibility(folderSection, true);
      setVisibility(fileSection, true);
      setVisibility(directoryEmptyRegion, false);
      showInlineMessage(folderRegion, config.messages.loading);
      showInlineMessage(fileRegion, config.messages.loading);
      try {
        const [folders, files, breadcrumb] = await Promise.all([
          folderId ? requestJson(`folders?parent_id=${folderId}`) : requestJson("folders"),
          folderId ? requestJson(`files?folder_id=${folderId}`) : requestJson("files"),
          folderId ? requestJson(`folder/${folderId}/breadcrumb`) : Promise.resolve([]),
        ]);
        state.folders = Array.isArray(folders) ? folders : [];
        state.files = Array.isArray(files) ? files : [];
        state.breadcrumb = Array.isArray(breadcrumb) ? breadcrumb : [];
        renderBreadcrumb();
        renderFolders();
        renderFiles();
        renderDirectoryState();
      } catch (error) {
        showInlineMessage(folderRegion, error.message || config.messages.requestError);
        showInlineMessage(fileRegion, error.message || config.messages.requestError);
      } finally {
        state.loadingFolder = false;
      }
    };

    const openFileById = async (fileId) => {
      try {
        const detail = await requestJson(`file/${fileId}`);
        if (detail.folder_id) {
          await loadFolder(detail.folder_id);
          const file = state.files.find((entry) => Number(entry.id) === Number(fileId)) || detail;
          await openFile(file);
          return;
        }
        await openFile(detail);
      } catch (error) {
        showToast(error.message || config.messages.requestError, "error");
      }
    };

    if (goRootButton) {
      goRootButton.addEventListener("click", async () => {
        setButtonLoading(goRootButton, true, config.messages.processing || "Procesando...");
        try {
          await loadFolder(null);
        } finally {
          setButtonLoading(goRootButton, false);
        }
      });
    }
    closeExcelButtons.forEach((btn) => btn.addEventListener("click", closeExcel));
    closePreviewButtons.forEach((btn) => btn.addEventListener("click", closePreview));
    if (saveExcelButton) {
      saveExcelButton.addEventListener("click", saveExcel);
    }
    if (previewDownloadButton) {
      previewDownloadButton.addEventListener("click", () =>
        startDownload(state.previewFile, previewDownloadButton)
      );
    }

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        if (excelModal && !excelModal.hidden) closeExcel();
        if (previewModal && !previewModal.hidden) closePreview();
      }
    });

    const initialFolderId = Number(root.getAttribute("data-initial-folder-id") || 0);
    const initialFileId = Number(root.getAttribute("data-initial-file-id") || 0);
    if (initialFileId > 0) {
      openFileById(initialFileId);
    } else if (initialFolderId > 0) {
      loadFolder(initialFolderId);
    } else {
      loadFolder(null);
    }
  };

  const initAccessBrowser = (root) => {
    if (!root || initializedBrowser.has(root)) {
      return;
    }
    initializedBrowser.add(root);

    const searchInput = root.querySelector("[data-browser-search]");
    const filterButtons = Array.from(root.querySelectorAll("[data-browser-filter]"));
    const tree = root.querySelector("[data-browser-tree]");
    const empty = root.querySelector("[data-browser-empty]");
    if (!tree) {
      return;
    }

    let activeFilter = "all";

    const evaluateItem = (item, term) => {
      if (!item) return false;
      const kind = item.getAttribute("data-browser-kind") || "";
      const title = (item.getAttribute("data-browser-title") || "").toLowerCase();
      if (kind === "file") {
        const group = item.getAttribute("data-browser-group") || "";
        const termMatch = term === "" || title.indexOf(term) !== -1;
        const filterMatch = activeFilter === "all" || group === activeFilter;
        const visible = termMatch && filterMatch;
        item.hidden = !visible;
        return visible;
      }

      const childrenWrap = item.querySelector(":scope > .shared-docs-browser-children");
      let childVisible = false;
      if (childrenWrap) {
        const children = Array.from(childrenWrap.querySelectorAll(":scope > [data-browser-item]"));
        children.forEach((child) => {
          if (evaluateItem(child, term)) {
            childVisible = true;
          }
        });
      }
      const selfMatch = term === "" || title.indexOf(term) !== -1;
      const folderMatchAllowed = activeFilter === "all" || term !== "";
      const visible = (folderMatchAllowed && selfMatch) || childVisible;
      item.hidden = !visible;
      if (!visible && item.open) {
        item.open = false;
      }
      if (visible && term !== "" && childVisible) {
        item.open = true;
      }
      return visible;
    };

    const applyFilters = () => {
      const term = (searchInput ? searchInput.value : "").trim().toLowerCase();
      const topItems = Array.from(tree.querySelectorAll(":scope > [data-browser-item]"));
      let visibleCount = 0;
      topItems.forEach((item) => {
        if (evaluateItem(item, term)) {
          visibleCount += 1;
        }
      });
      if (empty) {
        empty.hidden = visibleCount > 0;
      }
    };

    if (searchInput) {
      searchInput.addEventListener("input", applyFilters);
    }
    filterButtons.forEach((button) => {
      button.addEventListener("click", () => {
        activeFilter = button.getAttribute("data-browser-filter") || "all";
        filterButtons.forEach((btn) => {
          btn.classList.toggle("is-active", btn === button);
        });
        applyFilters();
      });
    });

    applyFilters();
  };

  const scanAndInit = () => {
    const roots = Array.from(document.querySelectorAll("#shared-docs-manager"));
    roots.forEach(initManager);
    const browsers = Array.from(document.querySelectorAll("[data-shared-docs-browser]"));
    browsers.forEach(initAccessBrowser);
  };

  scanAndInit();

  const observer = new MutationObserver(() => scanAndInit());
  observer.observe(document.documentElement, { childList: true, subtree: true });
})();
