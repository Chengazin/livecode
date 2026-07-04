import { ref, watch } from "vue";
import ace from "ace-builds/src-noconflict/ace";
import "ace-builds/src-noconflict/ext-language_tools";
import "ace-builds/src-noconflict/theme-github";
import "ace-builds/src-noconflict/theme-monokai";
import "ace-builds/src-noconflict/theme-tomorrow_night";
import "ace-builds/src-noconflict/mode-javascript";
import "ace-builds/src-noconflict/mode-typescript";
import "ace-builds/src-noconflict/mode-json";
import "ace-builds/src-noconflict/mode-html";
import "ace-builds/src-noconflict/mode-css";
import "ace-builds/src-noconflict/mode-php";
import "ace-builds/src-noconflict/mode-python";
import "ace-builds/src-noconflict/mode-markdown";
import "ace-builds/src-noconflict/mode-text";

export function useAceEditor(options) {
  const normalizedOptions = options || {};
  const {
    currentText,
    currentPath,
    dirty,
    onEditorDelta = null,
    onCursorChange = null,
    onSelectionChange = null,
  } = normalizedOptions;

  const editorHost = ref(null);
  const editorInstance = ref(null);
  const syncingEditor = ref(false);
  const editorLanguage = ref("javascript");
  const editorTheme = ref("github");

  const languageOptions = [
    { value: "javascript", label: "JavaScript" },
    { value: "typescript", label: "TypeScript" },
    { value: "json", label: "JSON / ipynb" },
    { value: "html", label: "HTML" },
    { value: "css", label: "CSS" },
    { value: "php", label: "PHP" },
    { value: "python", label: "Python" },
    { value: "markdown", label: "Markdown" },
    { value: "text", label: "Plain text" },
  ];

  const themeOptions = [
    { value: "github", label: "GitHub light" },
    { value: "monokai", label: "Monokai" },
    { value: "tomorrow_night", label: "Tomorrow night" },
  ];

  function detectLanguage(path) {
    const ext = String(path || "").toLowerCase().split(".").pop() || "";
    const map = {
      js: "javascript",
      ts: "typescript",
      json: "json",
      ipynb: "json",
      html: "html",
      htm: "html",
      css: "css",
      php: "php",
      py: "python",
      md: "markdown",
      txt: "text",
    };

    return map[ext] || "text";
  }

  function syncEditor() {
    editorLanguage.value = detectLanguage(currentPath.value);
    if (!editorInstance.value) {
      return;
    }

    syncingEditor.value = true;
    editorInstance.value.session.setValue(currentText.value || "");
    editorInstance.value.session.setNewLineMode("unix");
    editorInstance.value.clearSelection();
    syncingEditor.value = false;
  }

  function resolveDefaultEditorTheme(session) {
    const preference = typeof session?.value?.user?.theme === "string" ? session.value.user.theme.trim() : "";
    if (preference === "dark") {
      return "tomorrow_night";
    }

    if (preference === "light") {
      return "github";
    }

    if (typeof document === "undefined") {
      return "github";
    }

    const effectiveTheme = document.documentElement.getAttribute("data-theme");
    return effectiveTheme === "dark" ? "tomorrow_night" : "github";
  }

  function initEditor() {
    if (!editorHost.value) {
      return;
    }

    const editor = ace.edit(editorHost.value);
    editorInstance.value = editor;
    editor.setOptions({
      fontSize: 14,
      tabSize: 2,
      useSoftTabs: true,
      showPrintMargin: false,
      enableBasicAutocompletion: true,
      enableLiveAutocompletion: true,
    });
    editor.session.setUseWrapMode(true);
    editor.session.setUseWorker(false);
    editor.session.setNewLineMode("unix");
    editor.setTheme(`ace/theme/${editorTheme.value}`);
    editor.session.setMode(`ace/mode/${editorLanguage.value}`);
    editor.session.setValue(currentText.value);

    editor.on("change", (delta) => {
      if (syncingEditor.value) {
        return;
      }

      currentText.value = editor.getValue();
      dirty.value = true;

      if (typeof onEditorDelta === "function") {
        onEditorDelta(delta);
      }
    });

    editor.selection.on("changeCursor", () => {
      if (typeof onCursorChange === "function") {
        onCursorChange();
      }
    });

    editor.selection.on("changeSelection", () => {
      if (typeof onSelectionChange === "function") {
        onSelectionChange();
      }
    });
  }

  watch(editorLanguage, (value) => {
    if (editorInstance.value) {
      editorInstance.value.session.setMode(`ace/mode/${value}`);
      editorInstance.value.session.setUseWorker(false);
    }
  });

  watch(editorTheme, (value) => {
    if (editorInstance.value) {
      editorInstance.value.setTheme(`ace/theme/${value}`);
    }
  });

  function destroyEditor() {
    if (!editorInstance.value) {
      return;
    }

    editorInstance.value.destroy();
    editorInstance.value.container.remove();
    editorInstance.value = null;
  }

  return {
    editorHost,
    editorInstance,
    syncingEditor,
    editorLanguage,
    editorTheme,
    languageOptions,
    themeOptions,
    detectLanguage,
    syncEditor,
    resolveDefaultEditorTheme,
    initEditor,
    destroyEditor,
  };
}
