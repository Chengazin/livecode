export function useProjectScopedReset(options) {
  const {
    projectName,
    projectMeta,
    resetProjectTreeState,
    resetProjectSettingsState,
    resetRealtimeState,
    resetCodeCommentsState,
  } = options || {};

  function resetProjectScopedState() {
    projectName.value = "";
    projectMeta.value = null;
    resetProjectTreeState();
    resetProjectSettingsState();
    resetRealtimeState();
    resetCodeCommentsState();
  }

  return {
    resetProjectScopedState,
  };
}
