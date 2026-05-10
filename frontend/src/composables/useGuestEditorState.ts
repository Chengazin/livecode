import { reactive } from "vue";

const GUEST_KEY = "livecode.editor.guest";

export function useGuestEditorState(options) {
  const {
    t,
    currentPath,
    currentText,
    editorLanguage,
    dirty,
    syncEditor,
    isProjectMode,
    activeProjectPath,
    lastKnownFileUpdatedAt,
  } = options || {};

  const guest = reactive({
    path: "scratch/main.js",
    content: t("editor.guestStub"),
  });

  function persistGuest() {
    guest.path = currentPath.value;
    guest.content = currentText.value;

    try {
      window.localStorage.setItem(GUEST_KEY, JSON.stringify({ ...guest, language: editorLanguage.value }));
    } catch (_error) {
      // ignore
    }
  }

  function restoreGuest() {
    try {
      const raw = window.localStorage.getItem(GUEST_KEY);
      if (!raw) {
        return;
      }

      const parsed = JSON.parse(raw);
      if (typeof parsed?.path === "string" && parsed.path.trim() !== "") {
        guest.path = parsed.path;
      }

      if (typeof parsed?.content === "string") {
        guest.content = parsed.content;
      }

      if (typeof parsed?.language === "string") {
        editorLanguage.value = parsed.language;
      }
    } catch (_error) {
      // ignore
    }
  }

  function switchToGuest() {
    isProjectMode.value = false;
    activeProjectPath.value = "";
    currentPath.value = guest.path;
    currentText.value = guest.content;
    lastKnownFileUpdatedAt.value = "";
    dirty.value = false;
    syncEditor();
  }

  function clearProjectEditor() {
    isProjectMode.value = false;
    activeProjectPath.value = "";
    currentPath.value = "";
    currentText.value = "";
    lastKnownFileUpdatedAt.value = "";
    dirty.value = false;
    syncEditor();
  }

  return {
    persistGuest,
    restoreGuest,
    switchToGuest,
    clearProjectEditor,
  };
}
