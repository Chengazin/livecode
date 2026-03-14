import { ref } from "vue";

export function useProjectSettings(options) {
  const normalizedOptions = options || {};
  const {
    t,
    request,
    readError,
    canManageProjectSettings,
    selectedProjectId,
    projectMeta,
    projectName,
    isAuthenticated,
    route,
    router,
    notice,
    error,
    onReloadProjectMeta = null,
    onReloadTree = null,
  } = normalizedOptions;

  const projectSettingsBusy = ref(false);
  const showProjectSettingsModal = ref(false);
  const projectParticipants = ref([]);
  const participantsLoading = ref(false);
  const collaboratorBusy = ref(false);
  const collaboratorUserId = ref("");
  const inviteBusy = ref(false);
  const inviteLink = ref("");
  const inviteHandlingBusy = ref(false);
  const projectStatsLoading = ref(false);
  const projectStatsError = ref("");
  const projectStatsPeriodDays = ref(30);
  const projectStats = ref(createEmptyProjectStats(30));

  function createEmptyProjectStats(periodDays) {
    return {
      period_days: Number.isFinite(Number(periodDays)) ? Number(periodDays) : 30,
      total_commits: 0,
      contributors: [],
      timeline: [],
    };
  }

  function normalizeProjectStats(statsInput, periodDaysInput) {
    const source = statsInput && typeof statsInput === "object" ? statsInput : {};
    const contributors = Array.isArray(source.contributors) ? source.contributors : [];
    const timeline = Array.isArray(source.timeline) ? source.timeline : [];
    const safePeriodDays = Number.isFinite(Number(periodDaysInput))
      ? Math.max(1, Math.round(Number(periodDaysInput)))
      : 30;

    return {
      period_days: safePeriodDays,
      total_commits: Math.max(0, Number(source.total_commits || 0)),
      contributors: contributors
        .map((entry) => ({
          ...entry,
          key: String(entry?.key || ""),
          name: String(entry?.name || ""),
          email: String(entry?.email || ""),
          commit_count: Math.max(0, Number(entry?.commit_count || 0)),
          last_activity_at: entry?.last_activity_at ? String(entry.last_activity_at) : "",
        }))
        .filter((entry) => entry.key !== "" || entry.name !== ""),
      timeline: timeline
        .map((point) => ({
          date: String(point?.date || ""),
          commits: Math.max(0, Number(point?.commits || 0)),
        }))
        .filter((point) => point.date !== ""),
    };
  }

  function openProjectSettingsModal() {
    if (!canManageProjectSettings.value) {
      return;
    }

    showProjectSettingsModal.value = true;
    void loadProjectStats();
  }

  function closeProjectSettingsModal() {
    showProjectSettingsModal.value = false;
  }

  function resetProjectSettingsState() {
    showProjectSettingsModal.value = false;
    projectParticipants.value = [];
    collaboratorUserId.value = "";
    inviteLink.value = "";
    projectStatsLoading.value = false;
    projectStatsError.value = "";
    projectStatsPeriodDays.value = 30;
    projectStats.value = createEmptyProjectStats(30);
  }

  async function loadProjectStats(customPeriodDays = null) {
    if (!canManageProjectSettings.value || !selectedProjectId.value) {
      projectStats.value = createEmptyProjectStats(projectStatsPeriodDays.value);
      projectStatsError.value = "";
      return;
    }

    const fallbackPeriod = Number(projectStatsPeriodDays.value || 30);
    const requestedPeriod = Number(customPeriodDays ?? fallbackPeriod);
    const safePeriod = Number.isFinite(requestedPeriod)
      ? Math.max(1, Math.min(365, Math.round(requestedPeriod)))
      : Math.max(1, Math.min(365, Math.round(fallbackPeriod)));

    projectStatsPeriodDays.value = safePeriod;
    projectStatsLoading.value = true;
    projectStatsError.value = "";

    try {
      const response = await request({
        method: "GET",
        path: `/projects/${selectedProjectId.value}/info`,
        auth: true,
        query: {
          period_days: safePeriod,
          commit_limit: 200,
        },
      });

      const statsPayload = response.data?.stats;
      projectStats.value = normalizeProjectStats(statsPayload, safePeriod);
    } catch (statsLoadError) {
      projectStats.value = createEmptyProjectStats(safePeriod);
      projectStatsError.value = readError(statsLoadError);
    } finally {
      projectStatsLoading.value = false;
    }
  }

  async function loadProjectParticipants() {
    if (!canManageProjectSettings.value || !selectedProjectId.value) {
      projectParticipants.value = [];
      return;
    }

    participantsLoading.value = true;

    try {
      const response = await request({
        method: "GET",
        path: "/project-participants",
        auth: true,
        query: {
          project_id: selectedProjectId.value,
          per_page: 200,
        },
      });

      projectParticipants.value = Array.isArray(response.data?.data) ? response.data.data : [];
    } catch (participantsError) {
      error.value = readError(participantsError);
    } finally {
      participantsLoading.value = false;
    }
  }

  async function saveProjectSettings(payload) {
    if (!canManageProjectSettings.value || !selectedProjectId.value || projectSettingsBusy.value) {
      return;
    }

    const nextName = String(payload?.name || "").trim();
    const nextDescriptionText = String(payload?.description || "").trim();
    const nextVisibility = payload?.visibility === "public" ? "public" : "private";
    if (!nextName) {
      error.value = t("editor.projectNameRequired");
      return;
    }

    projectSettingsBusy.value = true;
    error.value = "";

    try {
      const response = await request({
        method: "PATCH",
        path: `/projects/${selectedProjectId.value}`,
        auth: true,
        body: {
          name: nextName,
          description: nextDescriptionText !== "" ? nextDescriptionText : null,
          is_public: nextVisibility === "public",
        },
      });

      const responsePayload = response.data || {};
      projectMeta.value = responsePayload;
      projectName.value = typeof responsePayload?.name === "string" ? responsePayload.name.trim() : nextName;
      notice.value = t("editor.projectSettingsSaved");
      closeProjectSettingsModal();
    } catch (settingsError) {
      error.value = readError(settingsError);
    } finally {
      projectSettingsBusy.value = false;
    }
  }

  async function addCollaboratorById() {
    if (!canManageProjectSettings.value || !selectedProjectId.value || collaboratorBusy.value) {
      return;
    }

    const userId = Number.parseInt(String(collaboratorUserId.value || "").trim(), 10);
    if (!Number.isInteger(userId) || userId <= 0) {
      error.value = t("editor.invalidCollaboratorId");
      return;
    }

    collaboratorBusy.value = true;
    error.value = "";

    try {
      await request({
        method: "POST",
        path: "/project-participants",
        auth: true,
        body: {
          project_id: Number(selectedProjectId.value),
          user_id: userId,
        },
      });

      collaboratorUserId.value = "";
      await loadProjectParticipants();
      notice.value = t("editor.collaboratorAdded");
    } catch (participantError) {
      error.value = readError(participantError);
    } finally {
      collaboratorBusy.value = false;
    }
  }

  async function removeCollaborator(participant) {
    if (!canManageProjectSettings.value || !participant?.participant_id || collaboratorBusy.value) {
      return;
    }

    collaboratorBusy.value = true;
    error.value = "";

    try {
      await request({
        method: "DELETE",
        path: `/project-participants/${participant.participant_id}`,
        auth: true,
      });

      await loadProjectParticipants();
      notice.value = t("editor.collaboratorRemoved");
    } catch (participantError) {
      error.value = readError(participantError);
    } finally {
      collaboratorBusy.value = false;
    }
  }

  async function createInviteLink() {
    if (!canManageProjectSettings.value || !selectedProjectId.value || inviteBusy.value) {
      return;
    }

    inviteBusy.value = true;
    error.value = "";

    try {
      const response = await request({
        method: "POST",
        path: "/project-invitations",
        auth: true,
        body: {
          project_id: Number(selectedProjectId.value),
        },
      });

      const token = String(response.data?.invite_token || "");
      if (!token) {
        throw new Error(t("common.requestFailed"));
      }

      const baseUrl = typeof window !== "undefined" ? window.location.origin : "";
      const invitePath = `/projects/${selectedProjectId.value}/editor?invite=${encodeURIComponent(token)}`;
      inviteLink.value = baseUrl ? `${baseUrl}${invitePath}` : invitePath;
      notice.value = t("editor.inviteLinkReady");
    } catch (inviteError) {
      error.value = readError(inviteError);
    } finally {
      inviteBusy.value = false;
    }
  }

  async function copyInviteLink() {
    if (!inviteLink.value) {
      return;
    }

    try {
      if (typeof navigator !== "undefined" && navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(inviteLink.value);
        notice.value = t("editor.inviteLinkCopied");
        return;
      }
    } catch (_copyError) {
      // fallback below
    }

    const textarea = document.createElement("textarea");
    textarea.value = inviteLink.value;
    textarea.style.position = "fixed";
    textarea.style.left = "-9999px";
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    document.execCommand("copy");
    textarea.remove();
    notice.value = t("editor.inviteLinkCopied");
  }

  async function acceptInviteFromQuery() {
    if (!isAuthenticated.value || inviteHandlingBusy.value) {
      return;
    }

    const inviteToken = typeof route.query.invite === "string" ? route.query.invite.trim() : "";
    if (!inviteToken) {
      return;
    }

    inviteHandlingBusy.value = true;

    try {
      const response = await request({
        method: "POST",
        path: "/project-invitations/accept",
        auth: true,
        body: {
          invite_token: inviteToken,
        },
      });

      const acceptedProjectId = String(response.data?.project_id || "").trim();
      const acceptedProjectName = String(response.data?.project_name || "").trim();
      notice.value = acceptedProjectName
        ? t("editor.inviteAcceptedProject", { name: acceptedProjectName })
        : t("editor.inviteAccepted");
      error.value = "";

      const query = { ...route.query };
      delete query.invite;

      if (acceptedProjectId && acceptedProjectId !== selectedProjectId.value) {
        await router.replace({
          path: `/projects/${acceptedProjectId}/editor`,
          query,
        });
        return;
      }

      await router.replace({ query });
      if (acceptedProjectId && acceptedProjectId === selectedProjectId.value) {
        if (typeof onReloadProjectMeta === "function") {
          void onReloadProjectMeta();
        }
        if (typeof onReloadTree === "function") {
          void onReloadTree();
        }
      }
    } catch (inviteError) {
      error.value = readError(inviteError);

      const query = { ...route.query };
      delete query.invite;
      await router.replace({ query });
    } finally {
      inviteHandlingBusy.value = false;
    }
  }

  return {
    projectSettingsBusy,
    showProjectSettingsModal,
    projectParticipants,
    participantsLoading,
    collaboratorBusy,
    collaboratorUserId,
    inviteBusy,
    inviteLink,
    inviteHandlingBusy,
    projectStats,
    projectStatsLoading,
    projectStatsError,
    projectStatsPeriodDays,
    openProjectSettingsModal,
    closeProjectSettingsModal,
    resetProjectSettingsState,
    loadProjectStats,
    loadProjectParticipants,
    saveProjectSettings,
    addCollaboratorById,
    removeCollaborator,
    createInviteLink,
    copyInviteLink,
    acceptInviteFromQuery,
  };
}
