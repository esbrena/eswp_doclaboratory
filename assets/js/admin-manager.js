(function () {
  "use strict";

  const adminData = window.SharedDocsAdminData || {};
  const permissionsByFolder = adminData.permissionsByFolder || {};
  const permissionsByFile = adminData.permissionsByFile || {};
  const userPermissionsHtmlByUser = adminData.userPermissionsHtmlByUser || {};
  const excelHistoryByFile = adminData.excelHistoryByFile || {};

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
      isExcel: checkbox.getAttribute("data-is-excel") === "1",
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

    const modalTitle = modal.querySelector("[data-access-modal-title]");
    const itemLabel = modal.querySelector("[data-access-item-label]");
    const itemTypeInput = modal.querySelector("[data-access-item-type]");
    const itemIdInput = modal.querySelector("[data-access-item-id]");
    const tableBody = modal.querySelector("[data-access-current-body]");
    const emptyRow = modal.querySelector("[data-access-current-empty]");
    const viewSection = modal.querySelector("[data-access-view-section]");
    const manageSection = modal.querySelector("[data-access-manage-section]");
    const closeButtons = modal.querySelectorAll('[data-action="close-access-modal"]');
    const openButtons = Array.from(document.querySelectorAll(".shared-docs-open-access-modal"));

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
            detailRow.hidden = !detailRow.hidden;
          });
        }

        revokeLink.addEventListener("click", (event) => {
          if (!window.confirm("¿Revocar este permiso?")) {
            event.preventDefault();
          }
        });

        tr.appendChild(actionsCell);
        tableBody.appendChild(tr);
        if (detailRow) {
          tableBody.appendChild(detailRow);
        }
      });
    };

    const closeModal = () => {
      modal.hidden = true;
      if (modalTitle) modalTitle.textContent = "Administrar acceso";
      if (itemLabel) itemLabel.textContent = "";
      if (itemTypeInput) itemTypeInput.value = "";
      if (itemIdInput) itemIdInput.value = "";
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
    };

    const openFromItem = (item, mode) => {
      if (!item) {
        return;
      }

      const resolvedMode =
        mode === "view" || mode === "manage" || mode === "all" ? mode : "all";
      if (modalTitle) {
        modalTitle.textContent =
          resolvedMode === "view"
            ? "Ver permisos"
            : resolvedMode === "manage"
            ? "Gestión de permisos"
            : "Administrar acceso";
      }

      if (viewSection) {
        viewSection.hidden = resolvedMode === "manage";
      }
      if (manageSection) {
        manageSection.hidden = resolvedMode === "view";
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
      if (itemLabel) {
        itemLabel.textContent = item.label
          ? "Elemento: " + item.label
          : "Gestiona los permisos del elemento seleccionado.";
      }

      const rows =
        item.type === "folder"
          ? permissionsByFolder[item.id] || []
          : item.type === "file"
          ? permissionsByFile[item.id] || []
          : [];
      renderRows(rows);
      modal.hidden = false;
    };

    openButtons.forEach((button) => {
      button.addEventListener("click", () =>
        openFromItem(
          {
            type: button.getAttribute("data-item-type") || "",
            id: button.getAttribute("data-item-id") || "",
            label: button.getAttribute("data-item-label") || "",
          },
          button.getAttribute("data-access-mode") || "all"
        )
      );
    });
    closeButtons.forEach((button) => button.addEventListener("click", closeModal));

    return {
      open: openFromItem,
    };
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

  const setupTreeSelectionActions = (modalApis) => {
    const panel = document.querySelector("[data-tree-global-actions]");
    if (!panel) {
      return;
    }

    const countLabel = panel.querySelector("[data-tree-selection-count]");
    const singleActions = panel.querySelector("[data-tree-actions-single]");
    const multiActions = panel.querySelector("[data-tree-actions-multi]");

    const singleOpenButton = panel.querySelector('[data-action="tree-single-open"]');
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

      if (singleOpenButton) {
        singleOpenButton.hidden = !singleItem || singleItem.type !== "file" || !singleItem.openUrl;
      }
      if (singleRenameButton) {
        singleRenameButton.hidden = !singleItem || singleItem.type !== "folder";
      }
      if (singleHistoryButton) {
        singleHistoryButton.hidden = !singleItem || singleItem.type !== "file" || !singleItem.isExcel;
      }
    };

    checkboxes.forEach((checkbox) => {
      checkbox.addEventListener("change", updatePanel);
    });
    updatePanel();

    if (singleOpenButton) {
      singleOpenButton.addEventListener("click", () => {
        const item = getSingleItem();
        if (!item || item.type !== "file" || !item.openUrl) {
          return;
        }
        window.open(item.openUrl, "_blank", "noopener");
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
        modalApis.access.open(item, "all");
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

  setupUserSelectors();
  const moveModalApi = setupSingleMoveModal();
  const renameModalApi = setupRenameModal();
  const accessModalApi = setupAccessModal();
  const historyModalApi = setupHistoryModal();
  setupTreeSelectionActions({
    move: moveModalApi,
    rename: renameModalApi,
    access: accessModalApi,
    history: historyModalApi,
  });
  setupResourceSearch();
})();
