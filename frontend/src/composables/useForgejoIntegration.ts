import { ref } from "vue";

const FORGEJO_RETURN_KEY = "livecode.forgejo.return_path";

export function useForgejoIntegration(options) {
  const normalizedOptions = options || {};
  const {
    t,
    request,
    readError,
    route,
    isAuthenticated,
    selectedProjectId,
    canUseProjectFs,
    isProjectOwner,
    canCreatePullRequest,
    canSyncFromForgejo,
    treeLoading,
    isProjectMode,
    activeProjectPath,
    dirty,
    tree,
    hasPath,
    projectMeta,
    projectName,
    notice,
    error,
    onLoadTree = null,
    onOpenFile = null,
  } = normalizedOptions;

  const forgejoBusy = ref(false);
  const repoSyncBusy = ref(false);
  const forgejoMode = ref("create");
  const forgejoRepoName = ref("");
  const forgejoRepoUrl = ref("");
  const forgejoMessage = ref("");
  const forgejoPrTitle = ref("");
  const forgejoPrBody = ref("");
  const forgejoPrMessage = ref("");

  async function startForgejoConnect() {
    if (!isAuthenticated.value) {
      error.value = t("editor.loginRequiredForgejo");
      return;
    }

    forgejoBusy.value = true;
    error.value = "";

    try {
      const response = await request({
        method: "POST",
        path: "/forgejo/oauth/start",
        auth: true,
        body: { mode: "connect" },
      });

      const authUrl = response.data?.auth_url || "";
      if (!authUrl) {
        throw new Error(t("common.requestFailed"));
      }

      try {
        window.localStorage.setItem(FORGEJO_RETURN_KEY, route.fullPath || "/projects");
      } catch (_error) {
        // ignore storage limitations
      }

      window.location.assign(authUrl);
    } catch (connectError) {
      error.value = readError(connectError);
      forgejoBusy.value = false;
    }
  }

  async function connectProjectForgejo() {
    if (!selectedProjectId.value) {
      error.value = t("editor.selectProjectFirst");
      return;
    }

    forgejoBusy.value = true;
    error.value = "";

    try {
      const body = forgejoMode.value === "existing"
        ? { mode: "existing", repo_url: forgejoRepoUrl.value }
        : { mode: "create", repo_name: forgejoRepoName.value || "project", private: true };

      const response = await request({
        method: "POST",
        path: `/projects/${selectedProjectId.value}/forgejo/connect`,
        auth: true,
        body,
      });

      if (response.data && typeof response.data === "object") {
        projectMeta.value = response.data;
        if (typeof response.data?.name === "string" && response.data.name.trim() !== "") {
          projectName.value = response.data.name.trim();
        }
      }

      notice.value = t("editor.connectedRepo");
    } catch (connectError) {
      error.value = readError(connectError);
    } finally {
      forgejoBusy.value = false;
    }
  }

  async function pushToForgejo() {
    if (!selectedProjectId.value) {
      error.value = t("editor.selectProjectFirst");
      return;
    }

    forgejoBusy.value = true;
    error.value = "";

    try {
      const body = forgejoMessage.value ? { message: forgejoMessage.value } : {};

      const response = await request({
        method: "POST",
        path: `/projects/${selectedProjectId.value}/forgejo/save`,
        auth: true,
        body,
      });

      notice.value = response.data?.status === "nothing_to_commit"
        ? t("editor.nothingToCommit")
        : t("editor.pushed");
    } catch (pushError) {
      error.value = readError(pushError);
    } finally {
      forgejoBusy.value = false;
    }
  }

  async function createPullRequestInForgejo() {
    if (!selectedProjectId.value) {
      error.value = t("editor.selectProjectFirst");
      return;
    }

    forgejoBusy.value = true;
    error.value = "";

    try {
      const body = {
        ...(forgejoPrTitle.value ? { title: forgejoPrTitle.value } : {}),
        ...(forgejoPrBody.value ? { body: forgejoPrBody.value } : {}),
        ...(forgejoPrMessage.value ? { message: forgejoPrMessage.value } : {}),
      };

      const response = await request({
        method: "POST",
        path: `/projects/${selectedProjectId.value}/forgejo/pull-request`,
        auth: true,
        body,
      });

      if (response.data?.status === "nothing_to_commit") {
        notice.value = t("editor.nothingToCommit");
        return;
      }

      const number = Number(response.data?.number || 0);
      if (number > 0) {
        notice.value = t("editor.pullRequestCreated", { number });
      } else {
        notice.value = t("editor.pullRequestCreated", { number: "?" });
      }

      if (typeof response.data?.html_url === "string" && response.data.html_url.trim() !== "") {
        notice.value = `${notice.value} ${response.data.html_url.trim()}`;
      }

      forgejoPrTitle.value = "";
      forgejoPrBody.value = "";
      forgejoPrMessage.value = "";
    } catch (pullRequestError) {
      error.value = readError(pullRequestError);
    } finally {
      forgejoBusy.value = false;
    }
  }

  async function syncProjectWorkspace() {
    if (!canUseProjectFs.value || !selectedProjectId.value || repoSyncBusy.value || treeLoading.value) {
      return;
    }

    if (!canSyncFromForgejo.value) {
      if (typeof onLoadTree === "function") {
        await onLoadTree();
      }
      return;
    }

    repoSyncBusy.value = true;
    error.value = "";
    notice.value = "";

    try {
      const response = await request({
        method: "POST",
        path: `/projects/${selectedProjectId.value}/forgejo/sync`,
        auth: true,
        body: {},
      });

      if (typeof onLoadTree === "function") {
        await onLoadTree();
      }

      if (
        isProjectMode.value
        && activeProjectPath.value
        && !dirty.value
        && typeof hasPath === "function"
        && hasPath(activeProjectPath.value, tree.value)
        && typeof onOpenFile === "function"
      ) {
        await onOpenFile(activeProjectPath.value);
      }

      notice.value = response.data?.status === "up_to_date"
        ? t("editor.repoUpToDate")
        : t("editor.repoSynced");
    } catch (syncError) {
      error.value = readError(syncError);
    } finally {
      repoSyncBusy.value = false;
    }
  }

  return {
    forgejoBusy,
    repoSyncBusy,
    forgejoMode,
    forgejoRepoName,
    forgejoRepoUrl,
    forgejoMessage,
    forgejoPrTitle,
    forgejoPrBody,
    forgejoPrMessage,
    startForgejoConnect,
    connectProjectForgejo,
    pushToForgejo,
    createPullRequestInForgejo,
    syncProjectWorkspace,
  };
}
