import { inject, provide } from "vue";

function createEditorContext(name) {
  const key = Symbol(name);

  function provideContext(context) {
    provide(key, context);
    return context;
  }

  function useContext() {
    const context = inject(key, null);
    if (!context) {
      throw new Error(`${name} is not available`);
    }

    return context;
  }

  return {
    provideContext,
    useContext,
  };
}

const editorLayoutContext = createEditorContext("Editor layout context");
const editorFilesystemContext = createEditorContext("Editor filesystem context");
const editorSettingsContext = createEditorContext("Editor settings context");
const editorForgejoContext = createEditorContext("Editor Forgejo context");
const editorCollaborationContext = createEditorContext("Editor collaboration context");

export const provideEditorLayoutContext = editorLayoutContext.provideContext;
export const useEditorLayoutContext = editorLayoutContext.useContext;

export const provideEditorFilesystemContext = editorFilesystemContext.provideContext;
export const useEditorFilesystemContext = editorFilesystemContext.useContext;

export const provideEditorSettingsContext = editorSettingsContext.provideContext;
export const useEditorSettingsContext = editorSettingsContext.useContext;

export const provideEditorForgejoContext = editorForgejoContext.provideContext;
export const useEditorForgejoContext = editorForgejoContext.useContext;

export const provideEditorCollaborationContext = editorCollaborationContext.provideContext;
export const useEditorCollaborationContext = editorCollaborationContext.useContext;
