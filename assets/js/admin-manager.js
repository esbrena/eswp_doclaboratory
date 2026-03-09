(function () {
  "use strict";

  const adminData = window.SharedDocsAdminData || {};
  const permissionsByFolder = adminData.permissionsByFolder || {};
  const permissionsByFile = adminData.permissionsByFile || {};
  const userPermissionsHtmlByUser = adminData.userPermissionsHtmlByUser || {};
  const excelHistoryByFile = adminData.excelHistoryByFile || {};
  const restBase = adminData.restBase || "";
  const restNonce = adminData.nonce || "";
  const messages = adminData.messages || {};

  const parseCheckboxItem = (checkbox) => {
    if (!checkbox) {
      return null;
    }

    const type = checkbox.getAttribute("data-item-type") || "";
    const id = checkbox.getAttribute("data-item-id") || "";
    if (!type || !id) {
      return null;
    }

    const invalidTargetsRaw = checkbox.getAttribute("data-invalid-targets") || "";
    return {
      checkbox,
      type,
      id,
      label: checkbox.getAttribute("data-item-label") || "",
      currentFolder: checkbox.getAttribute("data-current-folder") || "0",
      invalidTargets: invalidTargetsRaw
        .split(",")
        .map((value) => value.trim())
        .filter((value) => value !== ""),
      openUrl: checkbox.getAttribute("data-open-url") || "",
      downloadUrl: checkbox.getAttribute("data-download-url") || "",
      isExcel: checkbox.getAttribute("data-is-excel") === "1",
      canEditExcel: checkbox.getAttribute("data-can-edit-excel") === "1",
      mimeType: checkbox.getAttribute("data-mime-type") || "",
      filename: checkbox.getAttribute("data-filename") || "",
    };
  };

  const getSelectedItems = () => {
    const checked = Array.from(document.querySelectorAll(".shared-docs-item-checkbox:checked"));
    const items = checked.map(parseCheckboxItem).filter(Boolean);
    const folderIds = items.filter((item) => item.type === "folder").map((item) => item.id);
    const fileIds = items.filter((item) => item.type === "file").map((item) => item.id);

    return {
      items,
      folderIds,
      fileIds,
      total: items.length,
    };
  };

  const toApiUrl = (path) => `${restBase}${path}`.replace(/([^:]\/)\/+/g, "$1");

  const requestJson = async (path, options = {}) => {
    const response = await fetch(toApiUrl(path), {
      method: options.method || "GET",
      headers: {
        "X-WP-Nonce": restNonce,
        "Content-Type": "application/json",
      },
      credentials: "same-origin",
      body: options.body ? JSON.stringify(options.body) : undefined,
    });

    if (!response.ok) {
      let message = messages.requestError || "Error de comunicación con el servidor.";
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
      headers: {
        "X-WP-Nonce": restNonce,
      },
      credentials: "same-origin",
    });

    if (!response.ok) {
      let message = messages.downloadError || "No se pudo descargar el archivo.";
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

  const permissionStateLabel = (row, key, fallbackBool) => {
    const labelKey = `${key}_label`;
    if (row && row[labelKey]) {
      return String(row[labelKey]);
    }

    return fallbackBool ? "Permitir" : "Denegar";
  };

  const resolveAccessLevel = (row) => {
    if (!row) {
      return "reader";
    }
    if (row.can_edit_excel || Number(row.edit_excel_state || 0) === 1) {
      return "editor";
    }
    if (row.can_read || row.can_download || Number(row.read_state || 0) === 1) {
      return "reader";
    }
    return "remove";
  };

  const buildAccessLevelSelect = (selectedValue) => {
    const select = document.createElement("select");
    select.setAttribute("data-access-level-select", "1");
    const options = [
      { value: "reader", label: "Lector (lectura y descarga)" },
      { value: "editor", label: "Editor (lectura, descarga y edición Excel)" },
      { value: "remove", label: "Quitar acceso" },
    ];
    options.forEach((option) => {
      const optionEl = document.createElement("option");
      optionEl.value = option.value;
      optionEl.textContent = option.label;
      if (selectedValue === option.value) {
        optionEl.selected = true;
      }
      select.appendChild(optionEl);
    });
    return select;
  };

  const setButtonLoading = (button, loading, loadingText) => {
    if (!button) {
      return;
    }
    const tag = (button.tagName || "").toUpperCase();
    const isInput = tag === "INPUT";
    const isButton = tag === "BUTTON";
    const readLabel = () => (isInput ? button.value || "" : button.dataset.originalLabel || button.textContent || "");
    const writeLabel = (value) => {
      if (isInput) {
        button.value = value;
        return;
      }
      if (isButton) {
        button.innerHTML = "";
        const labelNode = document.createElement("span");
        labelNode.className = "shared-docs-btn-label";
        labelNode.textContent = value;
        button.appendChild(labelNode);
      } else {
        button.textContent = value;
      }
    };
    if (loading) {
      if (button.dataset.loading === "1") {
        return;
      }
      if (!button.dataset.originalLabel) {
        button.dataset.originalLabel = readLabel();
      }
      button.dataset.loading = "1";
      button.disabled = true;
      button.classList.add("is-loading");
      button.setAttribute("aria-busy", "true");
      const label = loadingText || button.dataset.originalLabel;
      writeLabel(label);
      if (isButton) {
        const spinner = document.createElement("span");
        spinner.className = "shared-docs-btn-spinner";
        spinner.setAttribute("aria-hidden", "true");
        button.appendChild(spinner);
      }
      return;
    }

    button.dataset.loading = "0";
    button.disabled = false;
    button.classList.remove("is-loading");
    button.removeAttribute("aria-busy");
    if (button.dataset.originalLabel) {
      writeLabel(button.dataset.originalLabel);
    }
  };

  const setContainerLoading = (container, message) => {
    if (!container) {
      return {
        stop: () => {},
      };
    }
    container.innerHTML = "";
    const loading = document.createElement("div");
    loading.className = "shared-docs-loading";
    const spinner = document.createElement("span");
    spinner.className = "shared-docs-loading__spinner";
    const text = document.createElement("span");
    text.textContent = message || "Cargando...";
    loading.appendChild(spinner);
    loading.appendChild(text);
    container.appendChild(loading);

    return {
      stop: () => {
        if (loading.parentNode === container) {
          container.removeChild(loading);
        }
      },
    };
  };

  const setupFormSubmitLoading = () => {
    const submitInputs = Array.from(
      document.querySelectorAll('.shared-docs-admin-wrap input[type="submit"]')
    );
    submitInputs.forEach((input) => {
      const button = document.createElement("button");
      button.type = "submit";
      button.className = input.className || "button";
      button.textContent = input.value || "Guardar";
      if (input.name) button.name = input.name;
      if (input.id) button.id = input.id;
      if (input.disabled) button.disabled = true;
      if (input.formNoValidate) button.formNoValidate = true;
      Array.from(input.attributes).forEach((attr) => {
        if (attr.name.indexOf("data-") === 0) {
          button.setAttribute(attr.name, attr.value);
        }
      });
      input.parentNode.replaceChild(button, input);
    });

    const forms = Array.from(document.querySelectorAll(".shared-docs-admin-wrap form"));
    forms.forEach((form) => {
      form.addEventListener("submit", (event) => {
        if (form.dataset.submitting === "1") {
          event.preventDefault();
          return;
        }
        form.dataset.submitting = "1";
        const submitButtons = Array.from(
          form.querySelectorAll('button[type="submit"], input[type="submit"]')
        );
        submitButtons.forEach((button) => {
          setButtonLoading(button, true, messages.processing || "Procesando...");
        });
      });
    });
  };

  const setupAccessRowsFilter = (filterSelect, tableBody, emptyRow) => {
    if (!tableBody) {
      return {
        apply: () => {},
        reset: () => {},
      };
    }

    const defaultEmptyText = emptyRow ? emptyRow.textContent || "" : "";

    const apply = () => {
      const mode = filterSelect ? filterSelect.value || "all" : "all";
      const mainRows = Array.from(tableBody.querySelectorAll("tr[data-access-row-main='1']"));
      const explicitCount = mainRows.filter(
        (row) => (row.getAttribute("data-access-kind") || "explicit") === "explicit"
      ).length;
      const inheritedCount = mainRows.length - explicitCount;
      if (filterSelect) {
        const allOption = filterSelect.querySelector('option[value="all"]');
        const explicitOption = filterSelect.querySelector('option[value="explicit"]');
        const inheritedOption = filterSelect.querySelector('option[value="inherited"]');
        if (allOption) allOption.textContent = `Todos (${mainRows.length})`;
        if (explicitOption) explicitOption.textContent = `Explícitos (${explicitCount})`;
        if (inheritedOption) inheritedOption.textContent = `Heredados (${inheritedCount})`;
      }
      let visibleCount = 0;

      mainRows.forEach((row) => {
        const kind = row.getAttribute("data-access-kind") || "explicit";
        const visible = mode === "all" || kind === mode;
        row.hidden = !visible;
        if (visible) {
          visibleCount += 1;
        }

        const rowKey = row.getAttribute("data-access-row-key") || "";
        if (rowKey) {
          const detailRows = Array.from(
            tableBody.querySelectorAll(`tr[data-access-parent-key="${rowKey}"]`)
          );
          detailRows.forEach((detailRow) => {
            if (!visible) {
              detailRow.hidden = true;
            }
          });
        }
      });

      if (emptyRow) {
        emptyRow.hidden = visibleCount > 0;
        if (visibleCount === 0) {
          emptyRow.textContent =
            mode === "all"
              ? defaultEmptyText
              : "No hay permisos para el filtro seleccionado.";
        } else {
          emptyRow.textContent = defaultEmptyText;
        }
      }
    };

    const reset = () => {
      if (filterSelect) {
        filterSelect.value = "all";
      }
      apply();
    };

    if (filterSelect) {
      filterSelect.addEventListener("change", apply);
    }

    return { apply, reset };
  };

  const setupUserSelectors = () => {
    const selectors = Array.from(document.querySelectorAll("[data-user-selector]"));
    selectors.forEach((container) => {
      const searchInput = container.querySelector("[data-user-search]");
      const master = container.querySelector("[data-user-select-all]");
      const items = Array.from(container.querySelectorAll("[data-user-item]"));
      const checkboxes = Array.from(container.querySelectorAll(".shared-docs-user-checkbox"));

      if (!checkboxes.length) {
        return;
      }

      const visibleCheckboxes = () =>
        items
          .filter((item) => !item.hidden)
          .map((item) => item.querySelector(".shared-docs-user-checkbox"))
          .filter(Boolean);

      const syncMaster = () => {
        if (!master) {
          return;
        }

        const visibles = visibleCheckboxes();
        if (!visibles.length) {
          master.checked = false;
          master.indeterminate = false;
          return;
        }

        const checkedCount = visibles.filter((checkbox) => checkbox.checked).length;
        master.checked = checkedCount === visibles.length;
        master.indeterminate = checkedCount > 0 && checkedCount < visibles.length;
      };

      if (master) {
        master.addEventListener("change", () => {
          visibleCheckboxes().forEach((checkbox) => {
            checkbox.checked = master.checked;
          });
          syncMaster();
        });
      }

      checkboxes.forEach((checkbox) => {
        checkbox.addEventListener("change", syncMaster);
      });

      if (searchInput) {
        searchInput.addEventListener("input", () => {
          const term = (searchInput.value || "").trim().toLowerCase();
          items.forEach((item) => {
            const text = (item.textContent || "").toLowerCase();
            item.hidden = term !== "" && text.indexOf(term) === -1;
          });
          syncMaster();
        });
      }

      syncMaster();
    });
  };

  const setupSingleMoveModal = () => {
    const modal = document.querySelector("[data-shared-move-modal]");
    if (!modal) {
      return {
        open: () => {},
      };
    }

    const itemTypeInput = modal.querySelector("[data-move-item-type]");
    const itemIdInput = modal.querySelector("[data-move-item-id]");
    const itemLabel = modal.querySelector("[data-move-item-label]");
    const targetFolderSelect = modal.querySelector("[data-move-target-folder]");
    const openButtons = Array.from(document.querySelectorAll(".shared-docs-open-move-modal"));
    const closeButtons = modal.querySelectorAll('[data-action="close-move-modal"]');

    const closeModal = () => {
      modal.hidden = true;
      if (itemTypeInput) itemTypeInput.value = "";
      if (itemIdInput) itemIdInput.value = "";
      if (itemLabel) itemLabel.textContent = "";
      if (targetFolderSelect) {
        Array.from(targetFolderSelect.options).forEach((option) => {
          option.disabled = false;
        });
        targetFolderSelect.value = "0";
      }
    };

    const openFromItem = (item) => {
      if (!item) {
        return;
      }

      if (itemTypeInput) itemTypeInput.value = item.type;
      if (itemIdInput) itemIdInput.value = item.id;
      if (itemLabel) {
        itemLabel.textContent = item.label
          ? "Elemento: " + item.label
          : "Selecciona la carpeta destino para mover el elemento.";
      }

      if (targetFolderSelect) {
        Array.from(targetFolderSelect.options).forEach((option) => {
          option.disabled = false;
          if (item.type === "folder" && item.invalidTargets.includes(option.value)) {
            option.disabled = true;
          }
          if (item.type === "file" && option.value === "0") {
            option.disabled = true;
          }
        });

        if (item.type === "file") {
          targetFolderSelect.value =
            item.currentFolder && item.currentFolder !== "0" ? item.currentFolder : "";
        } else {
          targetFolderSelect.value = item.currentFolder || "0";
        }
      }

      modal.hidden = false;
    };

    openButtons.forEach((button) => {
      button.addEventListener("click", () => {
        openFromItem({
          type: button.getAttribute("data-item-type") || "",
          id: button.getAttribute("data-item-id") || "",
          label: button.getAttribute("data-item-label") || "",
          currentFolder: button.getAttribute("data-current-folder") || "0",
          invalidTargets: (button.getAttribute("data-invalid-targets") || "")
            .split(",")
            .map((value) => value.trim())
            .filter((value) => value !== ""),
        });
      });
    });
    closeButtons.forEach((button) => button.addEventListener("click", closeModal));

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !modal.hidden) {
        closeModal();
      }
    });

    return {
      open: openFromItem,
    };
  };

  const setupRenameModal = () => {
    const modal = document.querySelector("[data-shared-rename-modal]");
    if (!modal) {
      return {
        open: () => {},
      };
    }

    const folderIdInput = modal.querySelector("[data-rename-folder-id]");
    const folderNameInput = modal.querySelector("[data-rename-folder-name]");
    const openButtons = Array.from(document.querySelectorAll(".shared-docs-open-rename-modal"));
    const closeButtons = modal.querySelectorAll('[data-action="close-rename-modal"]');

    const closeModal = () => {
      modal.hidden = true;
      if (folderIdInput) folderIdInput.value = "";
      if (folderNameInput) folderNameInput.value = "";
    };

    const openFromItem = (item) => {
      if (!item || item.type !== "folder") {
        return;
      }

      if (folderIdInput) folderIdInput.value = item.id;
      if (folderNameInput) {
        folderNameInput.value = item.label || "";
        folderNameInput.focus();
        folderNameInput.select();
      }

      modal.hidden = false;
    };

    openButtons.forEach((button) => {
      button.addEventListener("click", () =>
        openFromItem({
          type: "folder",
          id: button.getAttribute("data-folder-id") || "",
          label: button.getAttribute("data-folder-name") || "",
        })
      );
    });
    closeButtons.forEach((button) => button.addEventListener("click", closeModal));

    return {
      open: openFromItem,
    };
  };

  const setupAccessModal = () => {
    const modal = document.querySelector("[data-shared-access-modal]");
    if (!modal) {
      return {
        open: () => {},
      };
    }

    const itemLabel = modal.querySelector("[data-access-item-label]");
    const itemTypeInput = modal.querySelector("[data-access-item-type]");
    const itemIdInput = modal.querySelector("[data-access-item-id]");
    const saveForm = modal.querySelector("[data-access-save-form]");
    const saveItemTypeInput = modal.querySelector("[data-access-save-item-type]");
    const saveItemIdInput = modal.querySelector("[data-access-save-item-id]");
    const savePayloadInput = modal.querySelector("[data-access-save-payload]");
    const saveButton = modal.querySelector('[data-action="save-access-changes"]');
    const folderNote = modal.querySelector("[data-access-folder-note]");
    const manageFolderNote = modal.querySelector("[data-access-folder-note-manage]");
    const tableBody = modal.querySelector("[data-access-current-body]");
    const emptyRow = modal.querySelector("[data-access-current-empty]");
    const filterSelect = modal.querySelector("[data-access-filter]");
    const closeButtons = modal.querySelectorAll('[data-action="close-access-modal"]');
    const openButtons = Array.from(document.querySelectorAll(".shared-docs-open-access-modal"));
    const rowsFilter = setupAccessRowsFilter(filterSelect, tableBody, emptyRow);

    const clearRows = () => {
      if (!tableBody) {
        return;
      }
      Array.from(tableBody.querySelectorAll("tr[data-access-row]")).forEach((row) => row.remove());
      if (emptyRow) {
        emptyRow.hidden = false;
      }
    };

    const renderRows = (rows) => {
      if (!tableBody) {
        return;
      }

      clearRows();
      if (!rows.length) {
        return;
      }

      if (emptyRow) {
        emptyRow.hidden = true;
      }

      rows.forEach((row, index) => {
        const rowKind = row && row.inherited ? "inherited" : "explicit";
        const rowKey = `${String(row.user_id || "u")}-${String(row.id || "0")}-${String(index)}`;
        const tr = document.createElement("tr");
        tr.setAttribute("data-access-row", "1");
        tr.setAttribute("data-access-row-main", "1");
        tr.setAttribute("data-access-kind", rowKind);
        tr.setAttribute("data-access-row-key", rowKey);
        tr.setAttribute("data-access-user-id", String(row.user_id || ""));
        tr.setAttribute("data-access-permission-id", String(row.id || ""));

        const userCell = document.createElement("td");
        userCell.textContent = row.user || "";
        tr.appendChild(userCell);

        const readCell = document.createElement("td");
        readCell.textContent = permissionStateLabel(row, "read_state", !!row.can_read);
        tr.appendChild(readCell);

        const downloadCell = document.createElement("td");
        downloadCell.textContent = permissionStateLabel(row, "download_state", !!row.can_download);
        tr.appendChild(downloadCell);

        const editExcelCell = document.createElement("td");
        editExcelCell.textContent = permissionStateLabel(row, "edit_excel_state", !!row.can_edit_excel);
        tr.appendChild(editExcelCell);

        const expiresCell = document.createElement("td");
        expiresCell.textContent = row.expires_at || "";
        tr.appendChild(expiresCell);

        const actionsCell = document.createElement("td");
        const levelSelect = buildAccessLevelSelect(resolveAccessLevel(row));
        actionsCell.appendChild(levelSelect);

        tr.appendChild(actionsCell);
        tableBody.appendChild(tr);
      });
      rowsFilter.apply();
    };

    const closeModal = () => {
      modal.hidden = true;
      if (itemLabel) itemLabel.textContent = "";
      if (itemTypeInput) itemTypeInput.value = "";
      if (itemIdInput) itemIdInput.value = "";
      if (saveItemTypeInput) saveItemTypeInput.value = "";
      if (saveItemIdInput) saveItemIdInput.value = "";
      if (savePayloadInput) savePayloadInput.value = "";
      if (saveForm) {
        saveForm.dataset.submitting = "0";
      }
      if (saveButton) {
        setButtonLoading(saveButton, false);
      }
      if (folderNote) {
        folderNote.hidden = true;
      }
      if (manageFolderNote) {
        manageFolderNote.hidden = true;
      }
      Array.from(modal.querySelectorAll(".shared-docs-user-checkbox")).forEach((checkbox) => {
        checkbox.checked = false;
      });
      Array.from(modal.querySelectorAll("[data-user-item]")).forEach((item) => {
        item.hidden = false;
      });
      Array.from(modal.querySelectorAll("[data-user-search]")).forEach((input) => {
        input.value = "";
        input.dispatchEvent(new Event("input", { bubbles: true }));
      });
      clearRows();
      rowsFilter.reset();
    };

    const openFromItem = (item) => {
      if (!item) {
        return;
      }

      Array.from(modal.querySelectorAll(".shared-docs-user-checkbox")).forEach((checkbox) => {
        checkbox.checked = false;
      });
      Array.from(modal.querySelectorAll("[data-user-item]")).forEach((itemEl) => {
        itemEl.hidden = false;
      });
      Array.from(modal.querySelectorAll("[data-user-search]")).forEach((inputEl) => {
        inputEl.value = "";
        inputEl.dispatchEvent(new Event("input", { bubbles: true }));
      });

      if (itemTypeInput) itemTypeInput.value = item.type;
      if (itemIdInput) itemIdInput.value = item.id;
      if (saveItemTypeInput) saveItemTypeInput.value = item.type;
      if (saveItemIdInput) saveItemIdInput.value = item.id;
      if (itemLabel) {
        itemLabel.textContent = item.label
          ? "Elemento: " + item.label
          : "Gestiona los permisos del elemento seleccionado.";
      }
      if (folderNote) {
        folderNote.hidden = item.type !== "folder";
      }
      if (manageFolderNote) {
        manageFolderNote.hidden = item.type !== "folder";
      }

      const rows =
        item.type === "folder"
          ? permissionsByFolder[item.id] || []
          : item.type === "file"
          ? permissionsByFile[item.id] || []
          : [];
      renderRows(rows);
      rowsFilter.apply();
      modal.hidden = false;
    };

    openButtons.forEach((button) => {
      button.addEventListener("click", () =>
        openFromItem({
          type: button.getAttribute("data-item-type") || "",
          id: button.getAttribute("data-item-id") || "",
          label: button.getAttribute("data-item-label") || "",
        })
      );
    });
    closeButtons.forEach((button) => button.addEventListener("click", closeModal));
    if (saveButton && saveForm && savePayloadInput) {
      saveButton.addEventListener("click", () => {
        if (!tableBody) {
          return;
        }
        if (saveForm.dataset.submitting === "1") {
          return;
        }
        const payload = [];
        Array.from(tableBody.querySelectorAll("tr[data-access-row-main='1']")).forEach((row) => {
          const userId = Number(row.getAttribute("data-access-user-id") || 0);
          const permissionId = Number(row.getAttribute("data-access-permission-id") || 0);
          const select = row.querySelector("[data-access-level-select]");
          const level = select ? select.value : "";
          if (!userId || !level) {
            return;
          }
          payload.push({
            user_id: userId,
            permission_id: permissionId,
            level,
          });
        });
        savePayloadInput.value = JSON.stringify(payload);
        saveForm.dataset.submitting = "1";
        setButtonLoading(saveButton, true, messages.processing || "Guardando...");
        saveForm.submit();
      });
    }

    return {
      open: openFromItem,
    };
  };

  const setupAccessViewModal = () => {
    const modal = document.querySelector("[data-shared-access-view-modal]");
    if (!modal) {
      return { open: () => {} };
    }

    const itemLabel = modal.querySelector("[data-access-item-label]");
    const folderNote = modal.querySelector("[data-access-folder-note]");
    const saveForm = modal.querySelector("[data-access-save-form]");
    const saveItemTypeInput = modal.querySelector("[data-access-save-item-type]");
    const saveItemIdInput = modal.querySelector("[data-access-save-item-id]");
    const savePayloadInput = modal.querySelector("[data-access-save-payload]");
    const saveButton = modal.querySelector('[data-action="save-access-changes"]');
    const tableBody = modal.querySelector("[data-access-current-body]");
    const emptyRow = modal.querySelector("[data-access-current-empty]");
    const filterSelect = modal.querySelector("[data-access-filter]");
    const closeButtons = modal.querySelectorAll('[data-action="close-access-view-modal"]');
    const openButtons = Array.from(document.querySelectorAll(".shared-docs-open-permissions-view-modal"));
    const rowsFilter = setupAccessRowsFilter(filterSelect, tableBody, emptyRow);

    const clearRows = () => {
      if (!tableBody) return;
      Array.from(tableBody.querySelectorAll("tr[data-access-row]")).forEach((row) => row.remove());
      if (emptyRow) emptyRow.hidden = false;
    };

    const renderRows = (rows) => {
      if (!tableBody) return;
      clearRows();
      if (!rows.length) return;
      if (emptyRow) emptyRow.hidden = true;

      rows.forEach((row, index) => {
        const rowKind = row && row.inherited ? "inherited" : "explicit";
        const rowKey = `${String(row.user_id || "u")}-${String(row.id || "0")}-${String(index)}`;
        const tr = document.createElement("tr");
        tr.setAttribute("data-access-row", "1");
        tr.setAttribute("data-access-row-main", "1");
        tr.setAttribute("data-access-kind", rowKind);
        tr.setAttribute("data-access-row-key", rowKey);
        tr.setAttribute("data-access-user-id", String(row.user_id || ""));
        tr.setAttribute("data-access-permission-id", String(row.id || ""));
        const userCell = document.createElement("td");
        userCell.textContent = row.user || "";
        tr.appendChild(userCell);
        const readCell = document.createElement("td");
        readCell.textContent = permissionStateLabel(row, "read_state", !!row.can_read);
        tr.appendChild(readCell);
        const downloadCell = document.createElement("td");
        downloadCell.textContent = permissionStateLabel(row, "download_state", !!row.can_download);
        tr.appendChild(downloadCell);
        const editCell = document.createElement("td");
        editCell.textContent = permissionStateLabel(row, "edit_excel_state", !!row.can_edit_excel);
        tr.appendChild(editCell);
        const expiresCell = document.createElement("td");
        expiresCell.textContent = row.expires_at || "";
        tr.appendChild(expiresCell);
        const actionsCell = document.createElement("td");
        const levelSelect = buildAccessLevelSelect(resolveAccessLevel(row));
        actionsCell.appendChild(levelSelect);

        tr.appendChild(actionsCell);
        tableBody.appendChild(tr);
      });
      rowsFilter.apply();
    };

    const closeModal = () => {
      modal.hidden = true;
      if (itemLabel) itemLabel.textContent = "";
      if (saveItemTypeInput) saveItemTypeInput.value = "";
      if (saveItemIdInput) saveItemIdInput.value = "";
      if (savePayloadInput) savePayloadInput.value = "";
      if (folderNote) folderNote.hidden = true;
      if (saveForm) {
        saveForm.dataset.submitting = "0";
      }
      if (saveButton) {
        setButtonLoading(saveButton, false);
      }
      clearRows();
      rowsFilter.reset();
    };

    const open = (item) => {
      if (!item) return;
      if (itemLabel) {
        itemLabel.textContent = item.label ? `Elemento: ${item.label}` : "";
      }
      if (folderNote) {
        folderNote.hidden = item.type !== "folder";
      }
      if (saveItemTypeInput) saveItemTypeInput.value = item.type;
      if (saveItemIdInput) saveItemIdInput.value = item.id;
      const rows =
        item.type === "folder"
          ? permissionsByFolder[item.id] || []
          : item.type === "file"
          ? permissionsByFile[item.id] || []
          : [];
      renderRows(rows);
      rowsFilter.apply();
      modal.hidden = false;
    };

    openButtons.forEach((button) => {
      button.addEventListener("click", () =>
        open({
          type: button.getAttribute("data-item-type") || "",
          id: button.getAttribute("data-item-id") || "",
          label: button.getAttribute("data-item-label") || "",
        })
      );
    });
    closeButtons.forEach((button) => button.addEventListener("click", closeModal));
    if (saveButton && saveForm && savePayloadInput) {
      saveButton.addEventListener("click", () => {
        if (!tableBody) {
          return;
        }
        if (saveForm.dataset.submitting === "1") {
          return;
        }
        const payload = [];
        Array.from(tableBody.querySelectorAll("tr[data-access-row-main='1']")).forEach((row) => {
          const userId = Number(row.getAttribute("data-access-user-id") || 0);
          const permissionId = Number(row.getAttribute("data-access-permission-id") || 0);
          const select = row.querySelector("[data-access-level-select]");
          const level = select ? select.value : "";
          if (!userId || !level) {
            return;
          }
          payload.push({
            user_id: userId,
            permission_id: permissionId,
            level,
          });
        });
        savePayloadInput.value = JSON.stringify(payload);
        saveForm.dataset.submitting = "1";
        setButtonLoading(saveButton, true, messages.processing || "Guardando...");
        saveForm.submit();
      });
    }

    return { open };
  };

  const setupAccessManageModal = () => {
    const modal = document.querySelector("[data-shared-access-manage-modal]");
    if (!modal) {
      return { open: () => {} };
    }
    const itemLabel = modal.querySelector("[data-access-item-label]");
    const itemTypeInput = modal.querySelector("[data-access-item-type]");
    const itemIdInput = modal.querySelector("[data-access-item-id]");
    const folderNote = modal.querySelector("[data-access-folder-note-manage]");
    const closeButtons = modal.querySelectorAll('[data-action="close-access-manage-modal"]');
    const openButtons = Array.from(document.querySelectorAll(".shared-docs-open-permissions-manage-modal"));

    const closeModal = () => {
      modal.hidden = true;
      if (itemLabel) itemLabel.textContent = "";
      if (itemTypeInput) itemTypeInput.value = "";
      if (itemIdInput) itemIdInput.value = "";
      if (folderNote) folderNote.hidden = true;
      Array.from(modal.querySelectorAll(".shared-docs-user-checkbox")).forEach((checkbox) => {
        checkbox.checked = false;
      });
      Array.from(modal.querySelectorAll("[data-user-item]")).forEach((item) => {
        item.hidden = false;
      });
      Array.from(modal.querySelectorAll("[data-user-search]")).forEach((input) => {
        input.value = "";
        input.dispatchEvent(new Event("input", { bubbles: true }));
      });
    };

    const open = (item) => {
      if (!item) return;
      if (itemTypeInput) itemTypeInput.value = item.type;
      if (itemIdInput) itemIdInput.value = item.id;
      if (itemLabel) itemLabel.textContent = item.label ? `Elemento: ${item.label}` : "";
      if (folderNote) folderNote.hidden = item.type !== "folder";
      modal.hidden = false;
    };

    openButtons.forEach((button) => {
      button.addEventListener("click", () =>
        open({
          type: button.getAttribute("data-item-type") || "",
          id: button.getAttribute("data-item-id") || "",
          label: button.getAttribute("data-item-label") || "",
        })
      );
    });
    closeButtons.forEach((button) => button.addEventListener("click", closeModal));

    return { open };
  };

  const setupHistoryModal = () => {
    const modal = document.querySelector("[data-shared-history-modal]");
    if (!modal) {
      return {
        open: () => {},
      };
    }

    const label = modal.querySelector("[data-history-item-label]");
    const tableBody = modal.querySelector("[data-history-body]");
    const emptyRow = modal.querySelector("[data-history-empty]");
    const openButtons = Array.from(document.querySelectorAll(".shared-docs-open-history-modal"));
    const closeButtons = modal.querySelectorAll('[data-action="close-history-modal"]');

    const clearRows = () => {
      if (!tableBody) {
        return;
      }
      Array.from(tableBody.querySelectorAll("tr[data-history-row]")).forEach((row) => row.remove());
      if (emptyRow) {
        emptyRow.hidden = false;
      }
    };

    const closeModal = () => {
      modal.hidden = true;
      if (label) label.textContent = "";
      clearRows();
    };

    const openFromItem = (item) => {
      if (!item || item.type !== "file") {
        return;
      }

      const rows = excelHistoryByFile[item.id] || [];
      clearRows();
      if (label) {
        label.textContent = item.label ? "Archivo: " + item.label : "";
      }

      if (rows.length && tableBody) {
        if (emptyRow) {
          emptyRow.hidden = true;
        }

        rows.forEach((row) => {
          const tr = document.createElement("tr");
          tr.setAttribute("data-history-row", "1");
          const userCell = document.createElement("td");
          userCell.textContent = row.user || "";
          const dateCell = document.createElement("td");
          dateCell.textContent = row.created_at || "";
          tr.appendChild(userCell);
          tr.appendChild(dateCell);
          tableBody.appendChild(tr);
        });
      }

      modal.hidden = false;
    };

    openButtons.forEach((button) => {
      button.addEventListener("click", () =>
        openFromItem({
          type: "file",
          id: button.getAttribute("data-file-id") || "",
          label: button.getAttribute("data-file-label") || "",
        })
      );
    });
    closeButtons.forEach((button) => button.addEventListener("click", closeModal));

    return {
      open: openFromItem,
    };
  };

  const setupFileModal = () => {
    const modal = document.querySelector("[data-shared-file-modal]");
    if (!modal) {
      return {
        open: () => {},
      };
    }

    const title = modal.querySelector("[data-file-modal-title]");
    const previewWrap = modal.querySelector("[data-file-preview-wrap]");
    const excelWrap = modal.querySelector("[data-file-excel-wrap]");
    const excelTable = modal.querySelector("[data-file-excel-table]");
    const saveExcelButton = modal.querySelector('[data-action="file-modal-save-excel"]');
    const downloadButton = modal.querySelector('[data-action="file-modal-download"]');
    const closeButtons = modal.querySelectorAll('[data-action="close-file-modal"]');

    const state = {
      item: null,
      sheetName: "",
      bookType: "xlsx",
      busy: false,
      excelEditable: false,
      previewObjectUrl: "",
    };

    const closeModal = () => {
      if (state.busy) {
        return;
      }
      modal.hidden = true;
      state.item = null;
      state.sheetName = "";
      state.bookType = "xlsx";
      state.excelEditable = false;
      if (previewWrap) previewWrap.innerHTML = "";
      if (excelTable) excelTable.innerHTML = "";
      if (excelWrap) excelWrap.hidden = true;
      if (saveExcelButton) saveExcelButton.hidden = true;
      if (state.previewObjectUrl) {
        window.URL.revokeObjectURL(state.previewObjectUrl);
        state.previewObjectUrl = "";
      }
    };

    const loadExcel = async (item, editable) => {
      if (!window.XLSX) {
        throw new Error(messages.excelLoadError || "No se pudo abrir el archivo Excel.");
      }
      const loading = setContainerLoading(previewWrap, messages.loading || "Cargando...");
      try {
        const blob = await requestBlob(`download/${item.id}`);
        const buffer = await blob.arrayBuffer();
        const workbook = window.XLSX.read(buffer, { type: "array" });
        const sheetName = workbook.SheetNames[0];
        const firstSheet = workbook.Sheets[sheetName];
        const rows = window.XLSX.utils.sheet_to_json(firstSheet, {
          header: 1,
          raw: false,
          blankrows: true,
        });

        state.sheetName = sheetName || "Sheet1";
        const ext = String(item.filename || "").split(".").pop().toLowerCase();
        state.bookType = ext && ["xls", "xlsx", "xlsm", "xlsb", "csv", "ods"].includes(ext) ? ext : "xlsx";
        state.excelEditable = !!editable;

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
          td.textContent = "";
          tr.appendChild(td);
          tbody.appendChild(tr);
        }
        excelTable.appendChild(tbody);
        excelWrap.hidden = false;
        if (saveExcelButton) saveExcelButton.hidden = !editable;
        if (previewWrap) {
          previewWrap.innerHTML = "";
          const hint = document.createElement("p");
          hint.className = "description";
          hint.textContent = editable
            ? "Modo edición activo."
            : "Vista previa en solo lectura. No tienes permisos para editar este Excel.";
          previewWrap.appendChild(hint);
        }
      } finally {
        loading.stop();
      }
    };

    const loadPreview = async (item) => {
      if (previewWrap) previewWrap.innerHTML = "";
      let previewUrl = item.openUrl || "";
      if (!previewUrl) {
        const blob = await requestBlob(`download/${item.id}`);
        previewUrl = window.URL.createObjectURL(blob);
        state.previewObjectUrl = previewUrl;
      }
      if ((item.mimeType || "").toLowerCase().startsWith("image/")) {
        const img = document.createElement("img");
        img.src = previewUrl;
        img.className = "shared-docs-preview-image";
        img.alt = item.label || "";
        previewWrap.appendChild(img);
      } else {
        const objectEl = document.createElement("object");
        objectEl.data = previewUrl;
        objectEl.type = "application/pdf";
        objectEl.className = "shared-docs-preview-frame";
        const fallback = document.createElement("p");
        fallback.className = "description";
        const openLink = document.createElement("a");
        openLink.href = previewUrl || "#";
        openLink.target = "_blank";
        openLink.rel = "noopener";
        openLink.textContent = "Abrir PDF en una nueva pestaña";
        fallback.appendChild(openLink);
        objectEl.appendChild(fallback);
        previewWrap.appendChild(objectEl);
      }
      if (excelWrap) excelWrap.hidden = true;
      if (saveExcelButton) saveExcelButton.hidden = true;
    };

    const openFromItem = async (item) => {
      if (!item || item.type !== "file" || state.busy) {
        return;
      }
      state.item = item;
      state.busy = true;
      if (title) {
        title.textContent =
          item.isExcel && !item.canEditExcel
            ? `${item.label || "Archivo"} · Vista previa Excel`
            : item.label || "Abrir archivo";
      }
      modal.hidden = false;
      if (saveExcelButton) saveExcelButton.hidden = true;
      if (excelWrap) excelWrap.hidden = true;
      setContainerLoading(previewWrap, messages.loading || "Cargando...");

      try {
        const mime = (item.mimeType || "").toLowerCase();
        if (item.isExcel) {
          await loadExcel(item, !!item.canEditExcel);
          state.busy = false;
          return;
        }
        if (mime === "application/pdf" || mime.startsWith("image/")) {
          await loadPreview(item);
          state.busy = false;
          return;
        }
        if (item.downloadUrl) {
          window.location.href = item.downloadUrl;
        } else if (item.openUrl) {
          window.open(item.openUrl, "_blank", "noopener");
        }
        state.busy = false;
        closeModal();
      } catch (error) {
        state.busy = false;
        alert(error.message || messages.requestError || "Error de comunicación con el servidor.");
        closeModal();
      }
    };

    closeButtons.forEach((button) => {
      button.addEventListener("click", closeModal);
    });

    if (downloadButton) {
      downloadButton.addEventListener("click", () => {
        if (!state.item || state.busy) return;
        setButtonLoading(downloadButton, true, messages.processing || "Procesando...");
        state.busy = true;
        if (state.item.downloadUrl) {
          window.location.href = state.item.downloadUrl;
          window.setTimeout(() => {
            state.busy = false;
            setButtonLoading(downloadButton, false);
          }, 900);
          return;
        }
        if (state.item.openUrl) {
          window.open(state.item.openUrl, "_blank", "noopener");
        }
        state.busy = false;
        setButtonLoading(downloadButton, false);
      });
    }

    if (saveExcelButton) {
      saveExcelButton.addEventListener("click", async () => {
        if (!state.item || !window.XLSX || !excelTable || !state.excelEditable || state.busy) {
          return;
        }
        state.busy = true;
        setButtonLoading(saveExcelButton, true, messages.processing || "Procesando...");
        try {
          const rows = [];
          excelTable.querySelectorAll("tr").forEach((rowEl) => {
            const row = [];
            rowEl.querySelectorAll("td").forEach((cellEl) => {
              row.push(cellEl.textContent || "");
            });
            rows.push(row);
          });
          const workbook = window.XLSX.utils.book_new();
          const worksheet = window.XLSX.utils.aoa_to_sheet(rows);
          window.XLSX.utils.book_append_sheet(workbook, worksheet, state.sheetName || "Sheet1");
          const workbookBase64 = window.XLSX.write(workbook, {
            bookType: state.bookType || "xlsx",
            type: "base64",
          });
          await requestJson("excel/save", {
            method: "POST",
            body: {
              file_id: Number(state.item.id),
              workbook_base64: workbookBase64,
            },
          });
          alert(messages.excelSaveOk || "Cambios guardados correctamente.");
          state.busy = false;
          setButtonLoading(saveExcelButton, false);
          closeModal();
        } catch (error) {
          state.busy = false;
          setButtonLoading(saveExcelButton, false);
          alert(error.message || messages.excelSaveError || "No se pudo guardar el archivo Excel.");
        }
      });
    }

    return {
      open: openFromItem,
    };
  };

  const setupTreeSelectionActions = (modalApis) => {
    const panel = document.querySelector("[data-tree-global-actions]");
    if (!panel) {
      return;
    }

    const countLabel = panel.querySelector("[data-tree-selection-count]");
    const singleActions = panel.querySelector("[data-tree-actions-single]");
    const multiActions = panel.querySelector("[data-tree-actions-multi]");

    const singleOpenButton = panel.querySelector('[data-action="tree-single-open"]');
    const singleDownloadButton = panel.querySelector('[data-action="tree-single-download"]');
    const singleRenameButton = panel.querySelector('[data-action="tree-single-rename"]');
    const singleAccessButton = panel.querySelector('[data-action="tree-single-access"]');
    const singleHistoryButton = panel.querySelector('[data-action="tree-single-history"]');
    const singleMoveButton = panel.querySelector('[data-action="tree-single-move"]');
    const singleDeleteButton = panel.querySelector('[data-action="tree-single-delete"]');

    const deleteButton = panel.querySelector('[data-action="submit-bulk-delete"]');
    const openBulkMoveButton = panel.querySelector('[data-action="open-bulk-move-modal"]');
    const deleteForm = document.querySelector("[data-bulk-delete-form]");
    const deleteFolderInput = deleteForm ? deleteForm.querySelector("[data-bulk-folder-ids]") : null;
    const deleteFileInput = deleteForm ? deleteForm.querySelector("[data-bulk-file-ids]") : null;

    const checkboxes = Array.from(document.querySelectorAll(".shared-docs-item-checkbox"));
    checkboxes.forEach((checkbox) => {
      checkbox.addEventListener("click", (event) => {
        event.stopPropagation();
      });
      checkbox.addEventListener("keydown", (event) => {
        event.stopPropagation();
      });
    });

    const bulkMoveModal = document.querySelector("[data-shared-bulk-move-modal]");
    const bulkMoveFolderInput = bulkMoveModal ? bulkMoveModal.querySelector("[data-bulk-move-folder-ids]") : null;
    const bulkMoveFileInput = bulkMoveModal ? bulkMoveModal.querySelector("[data-bulk-move-file-ids]") : null;
    const bulkMoveLabel = bulkMoveModal ? bulkMoveModal.querySelector("[data-bulk-move-selection-label]") : null;
    const bulkMoveCloseButtons = bulkMoveModal
      ? bulkMoveModal.querySelectorAll('[data-action="close-bulk-move-modal"]')
      : [];
    let submitLocked = false;

    const closeBulkMoveModal = () => {
      if (!bulkMoveModal) {
        return;
      }
      bulkMoveModal.hidden = true;
      if (bulkMoveFolderInput) bulkMoveFolderInput.value = "";
      if (bulkMoveFileInput) bulkMoveFileInput.value = "";
      if (bulkMoveLabel) bulkMoveLabel.textContent = "";
    };

    const submitDelete = (folderIds, fileIds) => {
      if (!deleteForm || !deleteFolderInput || !deleteFileInput) {
        return;
      }
      if (submitLocked) {
        return;
      }
      submitLocked = true;
      if (singleDeleteButton) setButtonLoading(singleDeleteButton, true, messages.processing || "Procesando...");
      if (deleteButton) setButtonLoading(deleteButton, true, messages.processing || "Procesando...");

      deleteFolderInput.value = (folderIds || []).join(",");
      deleteFileInput.value = (fileIds || []).join(",");
      deleteForm.submit();
    };

    const getSingleItem = () => {
      const selected = getSelectedItems();
      return selected.total === 1 ? selected.items[0] : null;
    };

    const updatePanel = () => {
      const selected = getSelectedItems();
      panel.hidden = selected.total === 0;

      if (countLabel) {
        const label = selected.total === 1 ? "1 elemento seleccionado" : selected.total + " elementos seleccionados";
        countLabel.textContent = label;
      }

      const singleItem = selected.total === 1 ? selected.items[0] : null;

      if (singleActions) {
        singleActions.hidden = !singleItem;
      }
      if (multiActions) {
        multiActions.hidden = !(selected.total > 1);
      }

      if (singleItem) {
        if (singleOpenButton) singleOpenButton.style.display = "none";
        if (singleDownloadButton) singleDownloadButton.style.display = "none";
        if (singleHistoryButton) singleHistoryButton.style.display = "none";
        if (singleRenameButton) singleRenameButton.style.display = "none";

        const isFile = singleItem.type === "file";
        const isFolder = singleItem.type === "folder";

        
        if (isFile) { // FILE
          if (singleOpenButton) singleOpenButton.style.display = "inline-block";
          if (singleDownloadButton) singleDownloadButton.style.display = "inline-block";
          if (isFile && singleItem.isExcel) {
            if (singleHistoryButton) singleHistoryButton.style.display = "inline-block";
          }
        } else { // FOLDER
          if (singleRenameButton) singleRenameButton.style.display = "inline-block";
        }
      }
    };

    checkboxes.forEach((checkbox) => {
      checkbox.addEventListener("change", updatePanel);
    });
    updatePanel();

    if (singleOpenButton) {
      singleOpenButton.addEventListener("click", async () => {
        const item = getSingleItem();
        if (!item || item.type !== "file") {
          return;
        }
        setButtonLoading(singleOpenButton, true, messages.processing || "Procesando...");
        try {
          await modalApis.file.open(item);
        } finally {
          setButtonLoading(singleOpenButton, false);
        }
      });
    }

    if (singleDownloadButton) {
      singleDownloadButton.addEventListener("click", () => {
        const item = getSingleItem();
        if (!item || item.type !== "file") {
          return;
        }
        if (item.downloadUrl) {
          setButtonLoading(singleDownloadButton, true, messages.processing || "Procesando...");
          window.location.href = item.downloadUrl;
          window.setTimeout(() => setButtonLoading(singleDownloadButton, false), 900);
          return;
        }
        if (item.openUrl) {
          window.open(item.openUrl, "_blank", "noopener");
        }
      });
    }

    if (singleRenameButton) {
      singleRenameButton.addEventListener("click", () => {
        const item = getSingleItem();
        if (!item || item.type !== "folder") {
          return;
        }
        modalApis.rename.open(item);
      });
    }

    if (singleAccessButton) {
      singleAccessButton.addEventListener("click", () => {
        const item = getSingleItem();
        if (!item) {
          return;
        }
        modalApis.access.open(item);
      });
    }

    if (singleHistoryButton) {
      singleHistoryButton.addEventListener("click", () => {
        const item = getSingleItem();
        if (!item || item.type !== "file" || !item.isExcel) {
          return;
        }
        modalApis.history.open(item);
      });
    }

    if (singleMoveButton) {
      singleMoveButton.addEventListener("click", () => {
        const item = getSingleItem();
        if (!item) {
          return;
        }
        modalApis.move.open(item);
      });
    }

    if (singleDeleteButton) {
      singleDeleteButton.addEventListener("click", () => {
        const item = getSingleItem();
        if (!item) {
          return;
        }

        if (!window.confirm("¿Borrar el elemento seleccionado? Esta acción no se puede deshacer.")) {
          return;
        }

        submitDelete(item.type === "folder" ? [item.id] : [], item.type === "file" ? [item.id] : []);
      });
    }

    if (deleteButton) {
      deleteButton.addEventListener("click", () => {
        const selected = getSelectedItems();
        if (selected.total <= 1) {
          return;
        }

        if (!window.confirm("¿Borrar los elementos seleccionados? Esta acción no se puede deshacer.")) {
          return;
        }

        submitDelete(selected.folderIds, selected.fileIds);
      });
    }

    if (openBulkMoveButton && bulkMoveModal && bulkMoveFolderInput && bulkMoveFileInput) {
      openBulkMoveButton.addEventListener("click", () => {
        const selected = getSelectedItems();
        if (selected.total <= 1) {
          return;
        }

        bulkMoveFolderInput.value = selected.folderIds.join(",");
        bulkMoveFileInput.value = selected.fileIds.join(",");
        if (bulkMoveLabel) {
          bulkMoveLabel.textContent = "Elementos seleccionados: " + selected.total;
        }
        bulkMoveModal.hidden = false;
      });

      bulkMoveCloseButtons.forEach((button) => {
        button.addEventListener("click", closeBulkMoveModal);
      });
    }
  };

  const setupResourceSearch = () => {
    const searchInput = document.querySelector("[data-resource-search]");
    const table = document.querySelector("[data-resource-table]");
    if (!searchInput || !table) {
      return;
    }

    const rows = Array.from(table.querySelectorAll("[data-resource-row]"));
    const emptyMessage = document.querySelector("[data-resource-empty]");
    const typeButtons = Array.from(document.querySelectorAll("[data-resource-type-filter]"));
    let activeType = "all";

    const applyFilter = () => {
      const term = (searchInput.value || "").trim().toLowerCase();
      let visible = 0;

      rows.forEach((row) => {
        const text = (row.textContent || "").toLowerCase();
        const rowType = row.getAttribute("data-resource-kind") || "";
        const matchesTerm = term === "" || text.indexOf(term) !== -1;
        const matchesType = activeType === "all" || rowType === activeType;
        const isVisible = matchesTerm && matchesType;
        row.hidden = !isVisible;
        if (isVisible) {
          visible += 1;
        }
      });

      if (emptyMessage) {
        emptyMessage.hidden = visible > 0;
      }
    };

    searchInput.addEventListener("input", applyFilter);
    typeButtons.forEach((button) => {
      button.addEventListener("click", () => {
        activeType = button.getAttribute("data-resource-type-filter") || "all";
        typeButtons.forEach((btn) => {
          btn.classList.toggle("is-active", btn === button);
        });
        applyFilter();
      });
    });
    applyFilter();
  };

  setupUserSelectors();
  setupFormSubmitLoading();
  const moveModalApi = setupSingleMoveModal();
  const renameModalApi = setupRenameModal();
  const accessModalApi = setupAccessModal();
  setupAccessViewModal();
  setupAccessManageModal();
  const historyModalApi = setupHistoryModal();
  const fileModalApi = setupFileModal();
  setupTreeSelectionActions({
    move: moveModalApi,
    rename: renameModalApi,
    access: accessModalApi,
    history: historyModalApi,
    file: fileModalApi,
  });
  setupResourceSearch();
})();
