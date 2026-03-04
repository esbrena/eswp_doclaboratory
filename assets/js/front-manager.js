(() => {
  const config = window.SharedDocsData;
  const root = document.getElementById("shared-docs-manager");

  if (!config || !root) {
    return;
  }

  const folderRegion = root.querySelector('[data-region="folders"]');
  const fileRegion = root.querySelector('[data-region="files"]');
  const folderSection = root.querySelector('[data-section="folders"]');
  const fileSection = root.querySelector('[data-section="files"]');
  const directoryEmptyRegion = root.querySelector('[data-region="directory-empty"]');
  const breadcrumbRegion = root.querySelector(".shared-docs-breadcrumb");
  const goRootButton = root.querySelector('[data-action="go-root"]');

  const modal = document.getElementById("shared-docs-excel-modal");
  const modalTable = modal ? modal.querySelector('[data-region="excel-table"]') : null;
  const closeModalButtons = modal ? modal.querySelectorAll('[data-action="close-modal"]') : [];
  const saveExcelButton = modal ? modal.querySelector('[data-action="save-excel"]') : null;

  const state = {
    currentFolderId: null,
    folders: [],
    files: [],
    breadcrumb: [],
    excelEditor: null,
  };

  const getHeaders = (asJson = true) => {
    const headers = {
      "X-WP-Nonce": config.nonce,
    };

    if (asJson) {
      headers["Content-Type"] = "application/json";
    }

    return headers;
  };

  const toApiUrl = (path) => {
    return `${config.restBase}${path}`.replace(/([^:]\/)\/+/g, "$1");
  };

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
        // Ignorar parse de error.
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
        // Ignorar parse de error.
      }
      throw new Error(message);
    }

    return response.blob();
  };

  const showInlineMessage = (target, text) => {
    target.innerHTML = "";
    const el = document.createElement("div");
    el.className = "shared-docs-empty";
    el.textContent = text;
    target.appendChild(el);
  };

  const setVisibility = (element, visible) => {
    if (!element) return;
    element.hidden = !visible;
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

  const formatBytes = (bytes) => {
    const value = Number(bytes || 0);
    if (value <= 0) return "0 B";
    const units = ["B", "KB", "MB", "GB"];
    const index = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
    const size = value / Math.pow(1024, index);
    return `${size.toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
  };

  const extensionFromName = (name) => {
    const parts = String(name || "").split(".");
    if (parts.length < 2) return "";
    return parts.pop().toLowerCase();
  };

  const resolveFileIconCode = (name, mimeType, isExcel) => {
    if (isExcel) {
      return "XLS";
    }

    const ext = extensionFromName(name);
    const map = {
      pdf: "PDF",
      xls: "XLS",
      xlsx: "XLS",
      xlsm: "XLS",
      xlsb: "XLS",
      ods: "XLS",
      csv: "XLS",
      ppt: "PPT",
      pptx: "PPT",
      odp: "PPT",
      jpg: "JPG",
      jpeg: "JPG",
      png: "PNG",
      gif: "GIF",
      webp: "WEBP",
      svg: "SVG",
      doc: "DOC",
      docx: "DOC",
      odt: "DOC",
      rtf: "DOC",
      txt: "TXT",
      zip: "ZIP",
      rar: "ZIP",
      "7z": "ZIP",
    };

    if (map[ext]) {
      return map[ext];
    }

    if (String(mimeType || "").indexOf("image/") === 0) {
      return "IMG";
    }

    if (ext) {
      return ext.toUpperCase().slice(0, 4);
    }

    return "FILE";
  };

  const createTypeIcon = (code, kind = "file") => {
    const safeCode =
      String(code || "FILE")
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, "")
        .slice(0, 4) || "FILE";
    const wrapper = document.createElement("span");
    wrapper.className = `shared-docs-icon shared-docs-type-icon ${
      kind === "folder" ? "shared-docs-type-icon--folder" : "shared-docs-type-icon--file"
    }`;
    wrapper.innerHTML = `<svg viewBox="0 0 56 36" aria-hidden="true" focusable="false"><rect x="1" y="1" width="54" height="34" rx="6" ry="6"></rect><text x="28" y="23" text-anchor="middle">${String(
      safeCode
    )}</text></svg>`;
    return wrapper;
  };

  const resolveBookType = (name) => {
    const ext = extensionFromName(name);
    if (["xls", "xlsx", "xlsm", "xlsb", "csv", "ods"].includes(ext)) {
      return ext === "xls" ? "biff8" : ext;
    }
    return "xlsx";
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

  const renderFolders = () => {
    folderRegion.innerHTML = "";

    state.folders.forEach((folder) => {
      const card = document.createElement("article");
      card.className = "shared-docs-card shared-docs-card-folder";
      card.setAttribute("role", "button");
      card.tabIndex = 0;

      const icon = createTypeIcon("DIR", "folder");

      const title = document.createElement("h5");
      title.className = "shared-docs-card-title";
      title.textContent = folder.title;

      const meta = document.createElement("p");
      meta.className = "shared-docs-card-meta";
      meta.textContent = folder.has_children ? "Contiene subcarpetas" : "Sin subcarpetas";

      card.appendChild(icon);
      card.appendChild(title);
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

  const startDownload = async (file) => {
    if (!file.can_download) {
      showToast(config.messages.permissionError, "error");
      return;
    }

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
    }
  };

  const buildEditableTable = (rows) => {
    modalTable.innerHTML = "";

    const maxColumns = Math.max(1, ...rows.map((row) => row.length));
    const tbody = document.createElement("tbody");

    rows.forEach((rowData) => {
      const tr = document.createElement("tr");
      for (let col = 0; col < maxColumns; col += 1) {
        const td = document.createElement("td");
        td.contentEditable = "true";
        td.textContent = rowData[col] !== undefined ? rowData[col] : "";
        tr.appendChild(td);
      }
      tbody.appendChild(tr);
    });

    if (!rows.length) {
      const tr = document.createElement("tr");
      const td = document.createElement("td");
      td.contentEditable = "true";
      td.textContent = "";
      tr.appendChild(td);
      tbody.appendChild(tr);
    }

    modalTable.appendChild(tbody);
  };

  const openExcelEditor = async (file) => {
    if (!file.can_edit_excel || !window.XLSX) {
      showToast(config.messages.permissionError, "error");
      return;
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
        filename: file.filename || file.title || `archivo-${file.id}.xlsx`,
        sheetName,
        bookType: resolveBookType(file.filename || file.title),
      };

      buildEditableTable(rows);
      modal.hidden = false;
    } catch (error) {
      showToast(error.message || config.messages.excelLoadError, "error");
    }
  };

  const collectTableData = () => {
    const rows = [];
    const rowElements = modalTable.querySelectorAll("tr");
    rowElements.forEach((rowEl) => {
      const row = [];
      rowEl.querySelectorAll("td").forEach((cellEl) => {
        row.push(cellEl.textContent || "");
      });
      rows.push(row);
    });
    return rows;
  };

  const saveExcel = async () => {
    if (!state.excelEditor || !window.XLSX) {
      return;
    }

    try {
      const tableData = collectTableData();
      const workbook = window.XLSX.utils.book_new();
      const worksheet = window.XLSX.utils.aoa_to_sheet(tableData);
      window.XLSX.utils.book_append_sheet(workbook, worksheet, state.excelEditor.sheetName || "Sheet1");
      const workbookBase64 = window.XLSX.write(workbook, {
        bookType: state.excelEditor.bookType || "xlsx",
        type: "base64",
      });

      await requestJson("excel/save", {
        method: "POST",
        body: {
          file_id: state.excelEditor.fileId,
          workbook_base64: workbookBase64,
        },
      });

      showToast(config.messages.excelSaveOk, "success");
      closeModal();
      await loadFolder(state.currentFolderId);
    } catch (error) {
      showToast(error.message || config.messages.excelSaveError, "error");
    }
  };

  const renderFiles = () => {
    fileRegion.innerHTML = "";

    state.files.forEach((file) => {
      const card = document.createElement("article");
      card.className = "shared-docs-card shared-docs-card-file";

      const icon = createTypeIcon(
        resolveFileIconCode(file.filename || file.title, file.mime_type, file.is_excel),
        "file"
      );

      const title = document.createElement("h5");
      title.className = "shared-docs-card-title";
      title.textContent = file.title;

      const meta = document.createElement("p");
      meta.className = "shared-docs-card-meta";
      meta.textContent = `${formatBytes(file.size)} · ${file.filename || ""}`;

      const actions = document.createElement("div");
      actions.className = "shared-docs-card-actions";

      const downloadButton = document.createElement("button");
      downloadButton.type = "button";
      downloadButton.className = "shared-docs-btn shared-docs-btn-secondary";
      downloadButton.textContent = "Descargar";
      downloadButton.disabled = !file.can_download;
      downloadButton.addEventListener("click", () => startDownload(file));
      actions.appendChild(downloadButton);

      if (file.is_excel) {
        const editButton = document.createElement("button");
        editButton.type = "button";
        editButton.className = "shared-docs-btn shared-docs-btn-primary";
        editButton.textContent = "Editar Excel";
        editButton.disabled = !file.can_edit_excel;
        editButton.addEventListener("click", () => openExcelEditor(file));
        actions.appendChild(editButton);
      }

      card.appendChild(icon);
      card.appendChild(title);
      card.appendChild(meta);
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
      const text = template.replace("%s", currentDirectoryName);

      if (directoryEmptyRegion) {
        directoryEmptyRegion.textContent = text;
      }
      setVisibility(directoryEmptyRegion, true);
      return;
    }

    if (directoryEmptyRegion) {
      directoryEmptyRegion.textContent = "";
    }
    setVisibility(directoryEmptyRegion, false);
  };

  const closeModal = () => {
    if (!modal) return;
    state.excelEditor = null;
    modal.hidden = true;
    if (modalTable) {
      modalTable.innerHTML = "";
    }
  };

  const loadFolder = async (folderId) => {
    state.currentFolderId = folderId;

    setVisibility(folderSection, true);
    setVisibility(fileSection, true);
    setVisibility(directoryEmptyRegion, false);
    showInlineMessage(folderRegion, config.messages.loading);
    showInlineMessage(fileRegion, config.messages.loading);

    try {
      const [folders, files, breadcrumb] = await Promise.all([
        requestJson(folderId ? `folders?parent_id=${folderId}` : "folders"),
        folderId ? requestJson(`files?folder_id=${folderId}`) : Promise.resolve([]),
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
      setVisibility(folderSection, true);
      setVisibility(fileSection, true);
      setVisibility(directoryEmptyRegion, false);
      showInlineMessage(folderRegion, error.message || config.messages.requestError);
      showInlineMessage(fileRegion, error.message || config.messages.requestError);
    }
  };

  goRootButton.addEventListener("click", () => loadFolder(null));

  closeModalButtons.forEach((btn) => {
    btn.addEventListener("click", closeModal);
  });

  if (saveExcelButton) {
    saveExcelButton.addEventListener("click", saveExcel);
  }

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal && !modal.hidden) {
      closeModal();
    }
  });

  loadFolder(null);
})();
