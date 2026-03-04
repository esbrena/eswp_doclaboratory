(function () {
  "use strict";

  const adminData = window.SharedDocsAdminData || {};
  const permissionsByFolder = adminData.permissionsByFolder || {};
  const permissionsByFile = adminData.permissionsByFile || {};
  const userPermissionsHtmlByUser = adminData.userPermissionsHtmlByUser || {};
  const excelHistoryByFile = adminData.excelHistoryByFile || {};

  const getSelectedItems = () => {
    const selected = Array.from(document.querySelectorAll(".shared-docs-item-checkbox:checked"));
    const folderIds = [];
    const fileIds = [];

    selected.forEach((checkbox) => {
      const itemType = checkbox.getAttribute("data-item-type");
      const itemId = checkbox.getAttribute("data-item-id");
      if (!itemId) {
        return;
      }

      if (itemType === "folder") {
        folderIds.push(itemId);
      } else if (itemType === "file") {
        fileIds.push(itemId);
      }
    });

    return {
      selected,
      folderIds,
      fileIds,
      total: folderIds.length + fileIds.length,
    };
  };

  const setupOptionsSelectAllSwitches = () => {
    const switches = Array.from(document.querySelectorAll("[data-select-all-options]"));
    switches.forEach((switchEl) => {
      const selector = switchEl.getAttribute("data-select-all-options");
      if (!selector) {
        return;
      }

      const target = document.querySelector(selector);
      if (!target) {
        return;
      }

      const syncSwitch = () => {
        const options = Array.from(target.options);
        const selected = options.filter((option) => option.selected).length;
        switchEl.checked = selected > 0 && selected === options.length;
        switchEl.indeterminate = selected > 0 && selected < options.length;
      };

      switchEl.addEventListener("change", () => {
        Array.from(target.options).forEach((option) => {
          option.selected = switchEl.checked;
        });
        syncSwitch();
      });

      target.addEventListener("change", syncSwitch);
      syncSwitch();
    });
  };

  const setupSingleMoveModal = () => {
    const modal = document.querySelector("[data-shared-move-modal]");
    if (!modal) {
      return;
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

    const openModal = (button) => {
      const itemType = button.getAttribute("data-item-type") || "";
      const itemId = button.getAttribute("data-item-id") || "";
      const currentFolder = button.getAttribute("data-current-folder") || "0";
      const label = button.getAttribute("data-item-label") || "";
      const invalidTargets = (button.getAttribute("data-invalid-targets") || "")
        .split(",")
        .map((value) => value.trim())
        .filter((value) => value !== "");

      if (itemTypeInput) itemTypeInput.value = itemType;
      if (itemIdInput) itemIdInput.value = itemId;
      if (itemLabel) {
        itemLabel.textContent = label
          ? "Elemento: " + label
          : "Selecciona la carpeta destino para mover el elemento.";
      }

      if (targetFolderSelect) {
        Array.from(targetFolderSelect.options).forEach((option) => {
          option.disabled = false;
          if (itemType === "folder" && invalidTargets.includes(option.value)) {
            option.disabled = true;
          }
          if (itemType === "file" && option.value === "0") {
            option.disabled = true;
          }
        });

        if (itemType === "file") {
          targetFolderSelect.value = currentFolder && currentFolder !== "0" ? currentFolder : "";
        } else {
          targetFolderSelect.value = currentFolder || "0";
        }
      }

      modal.hidden = false;
    };

    openButtons.forEach((button) => {
      button.addEventListener("click", () => openModal(button));
    });
    closeButtons.forEach((button) => button.addEventListener("click", closeModal));

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !modal.hidden) {
        closeModal();
      }
    });
  };

  const setupRenameModal = () => {
    const modal = document.querySelector("[data-shared-rename-modal]");
    if (!modal) {
      return;
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

    const openModal = (button) => {
      const folderId = button.getAttribute("data-folder-id") || "";
      const folderName = button.getAttribute("data-folder-name") || "";
      if (folderIdInput) folderIdInput.value = folderId;
      if (folderNameInput) {
        folderNameInput.value = folderName;
        folderNameInput.focus();
        folderNameInput.select();
      }
      modal.hidden = false;
    };

    openButtons.forEach((button) => {
      button.addEventListener("click", () => openModal(button));
    });
    closeButtons.forEach((button) => button.addEventListener("click", closeModal));
  };

  const setupAccessModal = () => {
    const modal = document.querySelector("[data-shared-access-modal]");
    if (!modal) {
      return;
    }

    const itemLabel = modal.querySelector("[data-access-item-label]");
    const itemTypeInput = modal.querySelector("[data-access-item-type]");
    const itemIdInput = modal.querySelector("[data-access-item-id]");
    const tableBody = modal.querySelector("[data-access-current-body]");
    const emptyRow = modal.querySelector("[data-access-current-empty]");
    const selectAll = modal.querySelector("[data-access-select-all]");
    const userCheckboxes = Array.from(modal.querySelectorAll(".shared-docs-access-user-checkbox"));
    const closeButtons = modal.querySelectorAll('[data-action="close-access-modal"]');
    const openButtons = Array.from(document.querySelectorAll(".shared-docs-open-access-modal"));

    const syncSelectAll = () => {
      if (!selectAll || !userCheckboxes.length) {
        return;
      }
      const checkedCount = userCheckboxes.filter((checkbox) => checkbox.checked).length;
      selectAll.checked = checkedCount > 0 && checkedCount === userCheckboxes.length;
      selectAll.indeterminate = checkedCount > 0 && checkedCount < userCheckboxes.length;
    };

    if (selectAll && userCheckboxes.length) {
      selectAll.addEventListener("change", () => {
        userCheckboxes.forEach((checkbox) => {
          checkbox.checked = selectAll.checked;
        });
        syncSelectAll();
      });
      userCheckboxes.forEach((checkbox) => checkbox.addEventListener("change", syncSelectAll));
    }

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

      rows.forEach((row) => {
        const tr = document.createElement("tr");
        tr.setAttribute("data-access-row", "1");
        const userCell = document.createElement("td");
        userCell.textContent = row.user || "";
        tr.appendChild(userCell);

        const readCell = document.createElement("td");
        readCell.textContent = row.can_read ? "✔" : "—";
        tr.appendChild(readCell);

        const downloadCell = document.createElement("td");
        downloadCell.textContent = row.can_download ? "✔" : "—";
        tr.appendChild(downloadCell);

        const editExcelCell = document.createElement("td");
        editExcelCell.textContent = row.can_edit_excel ? "✔" : "—";
        tr.appendChild(editExcelCell);

        const expiresCell = document.createElement("td");
        expiresCell.textContent = row.expires_at || "";
        tr.appendChild(expiresCell);

        const actionsCell = document.createElement("td");
        const editLink = document.createElement("a");
        editLink.href = row.edit_url || "#";
        editLink.textContent = "Editar";
        actionsCell.appendChild(editLink);

        const sep = document.createTextNode(" | ");
        actionsCell.appendChild(sep);

        const revokeLink = document.createElement("a");
        revokeLink.href = row.revoke_url || "#";
        revokeLink.textContent = "Revocar";
        revokeLink.setAttribute("data-access-revoke", "1");
        actionsCell.appendChild(revokeLink);
        const userPermissionsHtml = row.user_id ? userPermissionsHtmlByUser[row.user_id] : "";
        let detailRow = null;
        if (userPermissionsHtml) {
          const sep2 = document.createTextNode(" | ");
          actionsCell.appendChild(sep2);

          const detailsLink = document.createElement("a");
          detailsLink.href = "#";
          detailsLink.textContent = "Ver permisos usuario";
          actionsCell.appendChild(detailsLink);

          detailRow = document.createElement("tr");
          detailRow.setAttribute("data-access-row", "1");
          detailRow.hidden = true;
          const detailCell = document.createElement("td");
          detailCell.colSpan = 6;
          detailCell.className = "shared-docs-access-user-details";
          detailCell.innerHTML = userPermissionsHtml;
          detailRow.appendChild(detailCell);

          detailsLink.addEventListener("click", (event) => {
            event.preventDefault();
            if (detailRow) {
              detailRow.hidden = !detailRow.hidden;
            }
          });
        }
        tr.appendChild(actionsCell);

        if (revokeLink) {
          revokeLink.addEventListener("click", (event) => {
            if (!window.confirm("¿Revocar este permiso?")) {
              event.preventDefault();
            }
          });
        }

        tableBody.appendChild(tr);
        if (detailRow) {
          tableBody.appendChild(detailRow);
        }
      });
    };

    const closeModal = () => {
      modal.hidden = true;
      if (itemLabel) itemLabel.textContent = "";
      if (itemTypeInput) itemTypeInput.value = "";
      if (itemIdInput) itemIdInput.value = "";
      userCheckboxes.forEach((checkbox) => {
        checkbox.checked = false;
      });
      syncSelectAll();
      clearRows();
    };

    const openModal = (button) => {
      const itemType = button.getAttribute("data-item-type") || "";
      const itemId = button.getAttribute("data-item-id") || "";
      const label = button.getAttribute("data-item-label") || "";

      if (itemTypeInput) itemTypeInput.value = itemType;
      if (itemIdInput) itemIdInput.value = itemId;
      if (itemLabel) {
        itemLabel.textContent = label
          ? "Elemento: " + label
          : "Gestiona los permisos del elemento seleccionado.";
      }

      const rows =
        itemType === "folder"
          ? permissionsByFolder[itemId] || []
          : itemType === "file"
          ? permissionsByFile[itemId] || []
          : [];
      renderRows(rows);
      modal.hidden = false;
    };

    openButtons.forEach((button) => {
      button.addEventListener("click", () => openModal(button));
    });
    closeButtons.forEach((button) => button.addEventListener("click", closeModal));
  };

  const setupHistoryModal = () => {
    const modal = document.querySelector("[data-shared-history-modal]");
    if (!modal) {
      return;
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

    const openModal = (button) => {
      const fileId = button.getAttribute("data-file-id") || "";
      const fileLabel = button.getAttribute("data-file-label") || "";
      const rows = excelHistoryByFile[fileId] || [];

      clearRows();
      if (label) {
        label.textContent = fileLabel ? "Archivo: " + fileLabel : "";
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
      button.addEventListener("click", () => openModal(button));
    });
    closeButtons.forEach((button) => button.addEventListener("click", closeModal));
  };

  const setupBulkSelectionActions = () => {
    const panel = document.querySelector("[data-tree-global-actions]");
    if (!panel) {
      return;
    }

    const countLabel = panel.querySelector("[data-tree-selection-count]");
    const deleteButton = panel.querySelector('[data-action="submit-bulk-delete"]');
    const openBulkMoveButton = panel.querySelector('[data-action="open-bulk-move-modal"]');
    const deleteForm = document.querySelector("[data-bulk-delete-form]");
    const deleteFolderInput = deleteForm ? deleteForm.querySelector("[data-bulk-folder-ids]") : null;
    const deleteFileInput = deleteForm ? deleteForm.querySelector("[data-bulk-file-ids]") : null;
    const checkboxes = Array.from(document.querySelectorAll(".shared-docs-item-checkbox"));

    const bulkMoveModal = document.querySelector("[data-shared-bulk-move-modal]");
    const bulkMoveFolderInput = bulkMoveModal ? bulkMoveModal.querySelector("[data-bulk-move-folder-ids]") : null;
    const bulkMoveFileInput = bulkMoveModal ? bulkMoveModal.querySelector("[data-bulk-move-file-ids]") : null;
    const bulkMoveLabel = bulkMoveModal ? bulkMoveModal.querySelector("[data-bulk-move-selection-label]") : null;
    const bulkMoveCloseButtons = bulkMoveModal
      ? bulkMoveModal.querySelectorAll('[data-action="close-bulk-move-modal"]')
      : [];

    const closeBulkMoveModal = () => {
      if (!bulkMoveModal) {
        return;
      }
      bulkMoveModal.hidden = true;
      if (bulkMoveFolderInput) bulkMoveFolderInput.value = "";
      if (bulkMoveFileInput) bulkMoveFileInput.value = "";
      if (bulkMoveLabel) bulkMoveLabel.textContent = "";
    };

    const updatePanel = () => {
      const selected = getSelectedItems();
      panel.hidden = selected.total === 0;
      if (countLabel) {
        countLabel.textContent = selected.total + " elementos seleccionados";
      }
    };

    checkboxes.forEach((checkbox) => {
      checkbox.addEventListener("change", updatePanel);
    });
    updatePanel();

    if (deleteButton && deleteForm && deleteFolderInput && deleteFileInput) {
      deleteButton.addEventListener("click", () => {
        const selected = getSelectedItems();
        if (!selected.total) {
          return;
        }

        if (!window.confirm("¿Borrar los elementos seleccionados? Esta acción no se puede deshacer.")) {
          return;
        }

        deleteFolderInput.value = selected.folderIds.join(",");
        deleteFileInput.value = selected.fileIds.join(",");
        deleteForm.submit();
      });
    }

    if (openBulkMoveButton && bulkMoveModal && bulkMoveFolderInput && bulkMoveFileInput) {
      openBulkMoveButton.addEventListener("click", () => {
        const selected = getSelectedItems();
        if (!selected.total) {
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

    const applyFilter = () => {
      const term = (searchInput.value || "").trim().toLowerCase();
      let visible = 0;

      rows.forEach((row) => {
        const text = (row.textContent || "").toLowerCase();
        const matches = term === "" || text.indexOf(term) !== -1;
        row.hidden = !matches;
        if (matches) {
          visible += 1;
        }
      });

      if (emptyMessage) {
        emptyMessage.hidden = visible > 0;
      }
    };

    searchInput.addEventListener("input", applyFilter);
    applyFilter();
  };

  setupOptionsSelectAllSwitches();
  setupSingleMoveModal();
  setupRenameModal();
  setupAccessModal();
  setupHistoryModal();
  setupBulkSelectionActions();
  setupResourceSearch();
})();
