(function () {
  "use strict";

  const selectAll = document.getElementById("shared-select-all-users");
  const userCheckboxes = Array.from(document.querySelectorAll(".shared-docs-user-checkbox"));

  if (selectAll && userCheckboxes.length) {
    const syncMaster = () => {
      const checkedCount = userCheckboxes.filter((checkbox) => checkbox.checked).length;
      selectAll.checked = checkedCount === userCheckboxes.length;
      selectAll.indeterminate = checkedCount > 0 && checkedCount < userCheckboxes.length;
    };

    selectAll.addEventListener("change", () => {
      userCheckboxes.forEach((checkbox) => {
        checkbox.checked = selectAll.checked;
      });
      syncMaster();
    });

    userCheckboxes.forEach((checkbox) => {
      checkbox.addEventListener("change", syncMaster);
    });

    syncMaster();
  }

  const modal = document.querySelector("[data-shared-move-modal]");
  if (!modal) {
    return;
  }

  const itemTypeInput = modal.querySelector("[data-move-item-type]");
  const itemIdInput = modal.querySelector("[data-move-item-id]");
  const itemLabel = modal.querySelector("[data-move-item-label]");
  const targetFolderSelect = modal.querySelector("[data-move-target-folder]");
  const openButtons = document.querySelectorAll(".shared-docs-open-move-modal");
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
        ? `Elemento: ${label}`
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

      const selectedValue =
        itemType === "file"
          ? currentFolder && currentFolder !== "0"
            ? currentFolder
            : ""
          : currentFolder || "0";

      if (selectedValue && targetFolderSelect.querySelector(`option[value="${selectedValue}"]`)) {
        targetFolderSelect.value = selectedValue;
      } else {
        targetFolderSelect.value = itemType === "file" ? "" : "0";
      }
    }

    modal.hidden = false;
  };

  openButtons.forEach((button) => {
    button.addEventListener("click", () => openModal(button));
  });

  closeButtons.forEach((button) => {
    button.addEventListener("click", closeModal);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !modal.hidden) {
      closeModal();
    }
  });
})();
