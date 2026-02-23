import { computed, ref } from "vue";

const TREE_ITEM_CLICK_DELAY_MS = 220;

export function useProjectFilesystem(options) {
  const normalizedOptions = options || {};
  const {
    t,
    request,
    buildApiUrl,
    getSession,
    nextTick,
    readError,
    canUseProjectFs,
    selectedProjectId,
    isProjectMode,
    activeProjectPath,
    currentPath,
    currentText,
    dirty,
    lastKnownFileUpdatedAt,
    notice,
    error,
    syncEditor,
    clearProjectEditor,
    onFileOpened = null,
  } = normalizedOptions;

  const tree = ref([]);
  const treeLoading = ref(false);
  const expanded = ref([]);
  const draggedPath = ref("");
  const draggedType = ref("");
  const dropTargetPath = ref("");
  const isRootDropTarget = ref(false);
  const moveBusy = ref(false);
  const selectedTreePath = ref("");
  const selectedTreeType = ref("");
  const downloadBusy = ref(false);
  const downloadingScope = ref("");

  const newNodeKind = ref("");
  const newNodePath = ref("");
  const newNodeInput = ref(null);
  const nodeBusy = ref(false);

  let treeItemClickTimerId = null;

  const flatTree = computed(() => {
    const list = [];

    function walk(items, depth) {
      items.forEach((item) => {
        list.push({ ...item, depth });

        if (item.type === "folder" && isExpanded(item.path)) {
          walk(Array.isArray(item.children) ? item.children : [], depth + 1);
        }
      });
    }

    walk(tree.value, 0);
    return list;
  });

  const canDownloadSelectedFolder = computed(() => {
    return canUseProjectFs.value && selectedTreeType.value === "folder" && Boolean(selectedTreePath.value);
  });

  const canMoveSelectedToRoot = computed(() => {
    return canUseProjectFs.value && selectedTreeType.value !== "root" && Boolean(selectedTreePath.value);
  });

  function isExpanded(path) {
    return expanded.value.includes(path);
  }

  function expandParents(path) {
    const parts = String(path || "").split("/").filter(Boolean);
    const next = [...expanded.value];

    for (let index = 0; index < parts.length - 1; index += 1) {
      const folderPath = parts.slice(0, index + 1).join("/");
      if (!next.includes(folderPath)) {
        next.push(folderPath);
      }
    }

    expanded.value = next;
  }

  function firstFile(items) {
    for (const item of items) {
      if (item.type === "file") {
        return item.path;
      }

      if (item.type === "folder" && Array.isArray(item.children)) {
        const nested = firstFile(item.children);
        if (nested) {
          return nested;
        }
      }
    }

    return "";
  }

  function firstRootFile(items) {
    for (const item of items) {
      if (item.type === "file") {
        return item.path;
      }
    }

    return "";
  }

  function hasPath(path, items) {
    for (const item of items) {
      if (item.path === path) {
        return true;
      }

      if (item.type === "folder" && Array.isArray(item.children) && hasPath(path, item.children)) {
        return true;
      }
    }

    return false;
  }

  function localizeNodeType(type) {
    return type === "folder" ? t("editor.typeFolder") : t("editor.typeFile");
  }

  function basename(path) {
    const segments = String(path || "").split("/").filter(Boolean);
    return segments.length > 0 ? segments[segments.length - 1] : "";
  }

  function parentPath(path) {
    const segments = String(path || "").split("/").filter(Boolean);
    if (segments.length <= 1) {
      return "";
    }

    return segments.slice(0, -1).join("/");
  }

  function selectTreeItem(path, type) {
    selectedTreePath.value = String(path || "");
    selectedTreeType.value = String(type || "");
  }

  function selectProjectRoot() {
    selectedTreePath.value = "";
    selectedTreeType.value = "root";
  }

  function resetDragState() {
    draggedPath.value = "";
    draggedType.value = "";
    dropTargetPath.value = "";
    isRootDropTarget.value = false;
  }

  function clearTreeItemClickTimer() {
    if (treeItemClickTimerId === null) {
      return;
    }

    if (typeof window !== "undefined") {
      window.clearTimeout(treeItemClickTimerId);
    }

    treeItemClickTimerId = null;
  }

  function canDropToFolder(folderPath) {
    if (!draggedPath.value) {
      return false;
    }

    if (draggedType.value === "folder" && folderPath.startsWith(`${draggedPath.value}/`)) {
      return false;
    }

    const sourceParent = parentPath(draggedPath.value);
    if (sourceParent === folderPath) {
      return false;
    }

    if (folderPath === draggedPath.value) {
      return false;
    }

    return true;
  }

  function onTreeDragStart(item, event) {
    selectTreeItem(item.path, item.type);
    draggedPath.value = item.path;
    draggedType.value = item.type;
    dropTargetPath.value = "";
    isRootDropTarget.value = false;

    if (event?.dataTransfer) {
      event.dataTransfer.effectAllowed = "move";
      event.dataTransfer.setData("text/plain", item.path);
    }
  }

  function onTreeRowDragOver(item, event) {
    if (item.type !== "folder") {
      return;
    }

    if (!canDropToFolder(item.path)) {
      return;
    }

    dropTargetPath.value = item.path;
    isRootDropTarget.value = false;

    if (event?.dataTransfer) {
      event.dataTransfer.dropEffect = "move";
    }
  }

  function onTreeRowDragLeave(item) {
    if (dropTargetPath.value === item.path) {
      dropTargetPath.value = "";
    }
  }

  function onRootDragOver(event) {
    if (!canDropToFolder("")) {
      return;
    }

    isRootDropTarget.value = true;
    dropTargetPath.value = "";

    if (event?.dataTransfer) {
      event.dataTransfer.dropEffect = "move";
    }
  }

  function onRootDragLeave() {
    isRootDropTarget.value = false;
  }

  async function onTreeRowDrop(item) {
    if (item.type !== "folder") {
      return;
    }

    if (!canDropToFolder(item.path)) {
      return;
    }

    await moveDraggedPath(item.path);
  }

  async function onRootDrop() {
    if (!canDropToFolder("")) {
      return;
    }

    await moveDraggedPath("");
  }

  async function moveDraggedPath(targetFolderPath) {
    if (!canUseProjectFs.value || !draggedPath.value || moveBusy.value) {
      resetDragState();
      return;
    }

    const sourcePath = draggedPath.value;

    await movePathToFolder(sourcePath, targetFolderPath);
    resetDragState();
  }

  async function moveTreePath(sourcePath, targetPath, moveOptions) {
    const normalizedMoveOptions = moveOptions || {};
    if (!canUseProjectFs.value || !sourcePath || !targetPath || moveBusy.value) {
      return false;
    }

    if (sourcePath === targetPath) {
      return false;
    }

    const noticeBuilder = typeof normalizedMoveOptions.noticeBuilder === "function"
      ? normalizedMoveOptions.noticeBuilder
      : (resolvedPath) => t("editor.movedTo", { path: resolvedPath });

    moveBusy.value = true;
    error.value = "";
    notice.value = "";

    try {
      const response = await request({
        method: "PUT",
        path: `/projects/${selectedProjectId.value}/filesystem/move`,
        auth: true,
        body: {
          from_path: sourcePath,
          to_path: targetPath,
        },
      });

      const fromPath = response.data?.from_path || sourcePath;
      const toPath = response.data?.to_path || targetPath;

      if (isProjectMode.value && activeProjectPath.value) {
        if (activeProjectPath.value === fromPath) {
          activeProjectPath.value = toPath;
          currentPath.value = toPath;
        } else if (activeProjectPath.value.startsWith(`${fromPath}/`)) {
          const suffix = activeProjectPath.value.slice(fromPath.length + 1);
          activeProjectPath.value = `${toPath}/${suffix}`;
          currentPath.value = activeProjectPath.value;
        }
      }

      if (selectedTreeType.value !== "root" && selectedTreePath.value) {
        if (selectedTreePath.value === fromPath) {
          selectedTreePath.value = toPath;
        } else if (selectedTreePath.value.startsWith(`${fromPath}/`)) {
          const suffix = selectedTreePath.value.slice(fromPath.length + 1);
          selectedTreePath.value = `${toPath}/${suffix}`;
        }
      }

      expandParents(toPath);
      await loadTree();
      notice.value = noticeBuilder(toPath, fromPath);
      return true;
    } catch (moveError) {
      error.value = readError(moveError);
      return false;
    } finally {
      moveBusy.value = false;
    }
  }

  async function movePathToFolder(sourcePath, targetFolderPath) {
    if (!canUseProjectFs.value || !sourcePath || moveBusy.value) {
      return;
    }

    const name = basename(sourcePath);
    const targetPath = targetFolderPath ? `${targetFolderPath}/${name}` : name;
    if (!targetPath || targetPath === sourcePath) {
      return;
    }

    await moveTreePath(sourcePath, targetPath);
  }

  async function moveSelectedToRoot() {
    if (!canMoveSelectedToRoot.value || !selectedTreePath.value) {
      return;
    }

    if (parentPath(selectedTreePath.value) === "") {
      notice.value = t("editor.alreadyInRoot");
      error.value = "";
      return;
    }

    await movePathToFolder(selectedTreePath.value, "");
  }

  function startCreateNode(kind) {
    if (!canUseProjectFs.value || nodeBusy.value) {
      return;
    }

    if (newNodeKind.value === kind) {
      cancelCreateNode();
      return;
    }

    newNodeKind.value = kind;
    newNodePath.value = "";
    error.value = "";
    notice.value = "";

    nextTick(() => {
      if (newNodeInput.value && typeof newNodeInput.value.focus === "function") {
        newNodeInput.value.focus();
      }
    });
  }

  function cancelCreateNode() {
    newNodeKind.value = "";
    newNodePath.value = "";
  }

  async function submitCreateNode() {
    if (!newNodeKind.value || !newNodePath.value) {
      return;
    }

    if (newNodeKind.value === "folder") {
      const created = await createFolder(newNodePath.value);
      if (created) {
        cancelCreateNode();
      }
      return;
    }

    const created = await createFile(newNodePath.value);
    if (created) {
      cancelCreateNode();
    }
  }

  function resetProjectTreeState() {
    tree.value = [];
    expanded.value = [];
    selectedTreePath.value = "";
    selectedTreeType.value = "";
    clearTreeItemClickTimer();
    cancelCreateNode();
    resetDragState();
  }

  async function loadTree() {
    if (!canUseProjectFs.value) {
      tree.value = [];
      return;
    }

    treeLoading.value = true;
    error.value = "";

    try {
      const response = await request({
        method: "GET",
        path: `/projects/${selectedProjectId.value}/filesystem/tree`,
        auth: true,
      });

      tree.value = Array.isArray(response.data?.items) ? response.data.items : [];

      if (selectedTreeType.value !== "root" && selectedTreePath.value && !hasPath(selectedTreePath.value, tree.value)) {
        selectedTreePath.value = "";
        selectedTreeType.value = "";
      }

      const hasActiveProjectFile =
        isProjectMode.value
        && activeProjectPath.value
        && hasPath(activeProjectPath.value, tree.value);

      if (!hasActiveProjectFile) {
        const first = firstRootFile(tree.value) || firstFile(tree.value);

        if (first) {
          await openFile(first);
        } else if (typeof clearProjectEditor === "function") {
          clearProjectEditor();
        }
      }
    } catch (treeError) {
      error.value = readError(treeError);
    } finally {
      treeLoading.value = false;
    }
  }

  async function createFile(pathValue = "") {
    const requestedPath = String(pathValue || "").trim();

    if (!canUseProjectFs.value || !requestedPath) {
      return false;
    }

    nodeBusy.value = true;
    error.value = "";
    notice.value = "";

    try {
      const response = await request({
        method: "POST",
        path: `/projects/${selectedProjectId.value}/filesystem`,
        auth: true,
        body: {
          action: "create_file",
          path: requestedPath,
          content: "",
        },
      });

      await loadTree();
      await openFile(response.data?.path || requestedPath);
      selectTreeItem(response.data?.path || requestedPath, "file");
      notice.value = t("editor.fileCreated");
      return true;
    } catch (createError) {
      error.value = readError(createError);
      return false;
    } finally {
      nodeBusy.value = false;
    }
  }

  async function createFolder(pathValue = "") {
    const requestedPath = String(pathValue || "").trim();

    if (!canUseProjectFs.value || !requestedPath) {
      return false;
    }

    nodeBusy.value = true;
    error.value = "";
    notice.value = "";

    try {
      const response = await request({
        method: "POST",
        path: `/projects/${selectedProjectId.value}/filesystem`,
        auth: true,
        body: {
          action: "create_folder",
          path: requestedPath,
        },
      });

      const createdPath = response.data?.path || requestedPath;
      expandParents(createdPath);
      await loadTree();
      selectTreeItem(createdPath, "folder");
      notice.value = t("editor.folderCreated");
      return true;
    } catch (createError) {
      error.value = readError(createError);
      return false;
    } finally {
      nodeBusy.value = false;
    }
  }

  async function confirmLoseChanges() {
    if (!dirty.value) {
      return true;
    }

    return window.confirm(t("editor.unsavedPrompt"));
  }

  async function openFile(path) {
    if (!canUseProjectFs.value || !path) {
      return;
    }

    const confirmed = await confirmLoseChanges();
    if (!confirmed) {
      return;
    }

    error.value = "";

    try {
      const response = await request({
        method: "GET",
        path: `/projects/${selectedProjectId.value}/filesystem/file`,
        auth: true,
        query: { path },
      });

      isProjectMode.value = true;
      activeProjectPath.value = path;
      selectTreeItem(path, "file");
      currentPath.value = path;
      currentText.value = response.data?.content || "";
      lastKnownFileUpdatedAt.value = String(response.data?.updated_at || "");
      dirty.value = false;
      expandParents(path);
      syncEditor();

      if (typeof onFileOpened === "function") {
        await onFileOpened({
          path,
          content: currentText.value,
          updatedAt: lastKnownFileUpdatedAt.value,
        });
      }
    } catch (openError) {
      error.value = readError(openError);
    }
  }

  function clickTreeItem(item) {
    selectTreeItem(item.path, item.type);

    clearTreeItemClickTimer();

    const runPrimaryAction = () => {
      if (item.type === "folder") {
        if (isExpanded(item.path)) {
          expanded.value = expanded.value.filter((path) => path !== item.path);
        } else {
          expanded.value = [...expanded.value, item.path];
        }
        return;
      }

      void openFile(item.path);
    };

    if (typeof window === "undefined") {
      runPrimaryAction();
      return;
    }

    treeItemClickTimerId = window.setTimeout(() => {
      treeItemClickTimerId = null;
      runPrimaryAction();
    }, TREE_ITEM_CLICK_DELAY_MS);
  }

  async function renameTreeItem(item) {
    clearTreeItemClickTimer();

    if (!canUseProjectFs.value || !item?.path || moveBusy.value || nodeBusy.value) {
      return;
    }

    const currentName = basename(item.path);
    const renamed = window.prompt(
      t("editor.renamePrompt", {
        type: localizeNodeType(item.type),
        name: currentName,
      }),
      currentName,
    );

    if (renamed === null) {
      return;
    }

    const nextName = String(renamed).trim();
    if (!nextName) {
      error.value = t("editor.renameEmpty");
      notice.value = "";
      return;
    }

    if (nextName.includes("/") || nextName.includes("\\")) {
      error.value = t("editor.renameInvalidName");
      notice.value = "";
      return;
    }

    if (nextName === currentName) {
      notice.value = t("editor.renameUnchanged");
      error.value = "";
      return;
    }

    const parent = parentPath(item.path);
    const nextPath = parent ? `${parent}/${nextName}` : nextName;

    await moveTreePath(item.path, nextPath, {
      noticeBuilder: (resolvedPath) => t("editor.renamedTo", {
        type: localizeNodeType(item.type),
        path: resolvedPath,
      }),
    });
  }

  async function removeTreeItem(item) {
    clearTreeItemClickTimer();

    if (!canUseProjectFs.value) {
      return;
    }

    const confirmed = window.confirm(
      t("editor.deleteConfirm", {
        type: localizeNodeType(item.type),
        path: item.path,
      }),
    );
    if (!confirmed) {
      return;
    }

    error.value = "";

    try {
      await request({
        method: "DELETE",
        path: `/projects/${selectedProjectId.value}/filesystem/item`,
        auth: true,
        query: { path: item.path },
      });

      if (selectedTreeType.value !== "root" && selectedTreePath.value) {
        if (selectedTreePath.value === item.path || selectedTreePath.value.startsWith(`${item.path}/`)) {
          selectedTreePath.value = "";
          selectedTreeType.value = "";
        }
      }

      if (isProjectMode.value && (activeProjectPath.value === item.path || activeProjectPath.value.startsWith(`${item.path}/`))) {
        if (typeof clearProjectEditor === "function") {
          clearProjectEditor();
        }
      }

      await loadTree();
      notice.value = t("editor.deletedNotice", {
        type: localizeNodeType(item.type),
      });
    } catch (removeError) {
      error.value = readError(removeError);
    }
  }

  function parseDownloadFileName(disposition, fallbackName) {
    const header = String(disposition || "");
    const utf8Match = header.match(/filename\*=UTF-8''([^;]+)/i);
    if (utf8Match && utf8Match[1]) {
      try {
        return decodeURIComponent(utf8Match[1]);
      } catch (_error) {
        // ignore invalid header encoding
      }
    }

    const plainMatch = header.match(/filename="?([^";]+)"?/i);
    if (plainMatch && plainMatch[1]) {
      return plainMatch[1];
    }

    return fallbackName;
  }

  async function downloadArchive(pathValue = "") {
    if (!canUseProjectFs.value || !selectedProjectId.value || downloadBusy.value) {
      return;
    }

    downloadBusy.value = true;
    downloadingScope.value = pathValue ? "folder" : "project";
    error.value = "";
    notice.value = "";

    try {
      const url = buildApiUrl(
        `/projects/${selectedProjectId.value}/filesystem/download`,
        pathValue ? { path: pathValue } : undefined,
      );

      const token = getSession().accessToken || "";
      const headers = token
        ? { Accept: "application/zip", Authorization: `Bearer ${token}` }
        : { Accept: "application/zip" };

      const response = await fetch(url, { method: "GET", headers });

      if (!response.ok) {
        const raw = await response.text();
        let data = null;

        try {
          data = JSON.parse(raw);
        } catch (_error) {
          data = { message: raw };
        }

        throw {
          status: response.status,
          data,
          message: typeof data?.message === "string" ? data.message : `HTTP ${response.status}`,
        };
      }

      const blob = await response.blob();
      const fallbackName = pathValue
        ? `${basename(pathValue) || "folder"}.zip`
        : `project-${selectedProjectId.value}.zip`;
      const downloadName = parseDownloadFileName(response.headers.get("content-disposition"), fallbackName);
      const objectUrl = window.URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = objectUrl;
      link.download = downloadName;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(objectUrl);

      notice.value = pathValue
        ? t("editor.folderDownloaded", { path: pathValue })
        : t("editor.projectDownloaded");
    } catch (downloadError) {
      error.value = readError(downloadError);
    } finally {
      downloadBusy.value = false;
      downloadingScope.value = "";
    }
  }

  async function downloadProjectArchive() {
    await downloadArchive("");
  }

  async function downloadSelectedFolder() {
    if (!canDownloadSelectedFolder.value || !selectedTreePath.value) {
      error.value = t("editor.selectFolderToDownload");
      return;
    }

    await downloadArchive(selectedTreePath.value);
  }

  return {
    tree,
    treeLoading,
    expanded,
    draggedPath,
    draggedType,
    dropTargetPath,
    isRootDropTarget,
    moveBusy,
    selectedTreePath,
    selectedTreeType,
    downloadBusy,
    downloadingScope,
    newNodeKind,
    newNodePath,
    newNodeInput,
    nodeBusy,
    flatTree,
    canDownloadSelectedFolder,
    canMoveSelectedToRoot,
    isExpanded,
    expandParents,
    firstFile,
    firstRootFile,
    hasPath,
    basename,
    parentPath,
    selectTreeItem,
    selectProjectRoot,
    resetDragState,
    clearTreeItemClickTimer,
    onTreeDragStart,
    onTreeRowDragOver,
    onTreeRowDragLeave,
    onRootDragOver,
    onRootDragLeave,
    onTreeRowDrop,
    onRootDrop,
    moveTreePath,
    moveSelectedToRoot,
    startCreateNode,
    cancelCreateNode,
    submitCreateNode,
    resetProjectTreeState,
    loadTree,
    createFile,
    createFolder,
    openFile,
    clickTreeItem,
    renameTreeItem,
    removeTreeItem,
    downloadArchive,
    downloadProjectArchive,
    downloadSelectedFolder,
  };
}
