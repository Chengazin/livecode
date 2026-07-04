const CLIENT_ID_STORAGE_KEY = "livecode.realtime.client_id";

function toNonNegativeInt(value) {
  const parsed = Number.parseInt(String(value ?? ""), 10);
  if (!Number.isFinite(parsed) || parsed < 0) {
    return 0;
  }

  return parsed;
}

function randomToken() {
  if (typeof window !== "undefined" && window.crypto && typeof window.crypto.randomUUID === "function") {
    return window.crypto.randomUUID();
  }

  const now = Date.now().toString(36);
  const random = Math.random().toString(36).slice(2);
  return `${now}-${random}`;
}

export function getOrCreateRealtimeClientId() {
  if (typeof window === "undefined") {
    return `server-${randomToken()}`;
  }

  let hasSessionStorage = false;

  try {
    hasSessionStorage = Boolean(window.sessionStorage);
  } catch (_error) {
    hasSessionStorage = false;
  }

  if (hasSessionStorage) {
    try {
      const fromSession = String(window.sessionStorage.getItem(CLIENT_ID_STORAGE_KEY) || "").trim();
      if (fromSession) {
        return fromSession;
      }

      const created = `client-${randomToken()}`;
      window.sessionStorage.setItem(CLIENT_ID_STORAGE_KEY, created);
      return created;
    } catch (_error) {
      // Fall through to localStorage fallback.
    }
  }

  try {
    const fromLocal = String(window.localStorage?.getItem(CLIENT_ID_STORAGE_KEY) || "").trim();
    if (fromLocal) {
      return fromLocal;
    }
  } catch (_error) {
    // Fallback below.
  }

  const created = `client-${randomToken()}`;

  try {
    if (window.localStorage) {
      window.localStorage.setItem(CLIENT_ID_STORAGE_KEY, created);
      return created;
    }
  } catch (_error) {
    // Ignore storage errors.
  }

  return created;
}

export function createRealtimeOperationId() {
  return `op-${randomToken()}`;
}

export function normalizeTextOperation(input = {}) {
  return {
    start: toNonNegativeInt(input.start),
    delete_count: toNonNegativeInt(input.delete_count ?? input.deleteCount),
    insert_text: String(input.insert_text ?? input.insertText ?? ""),
    client_id: String(input.client_id ?? input.clientId ?? ""),
    op_id: String(input.op_id ?? input.opId ?? ""),
  };
}

export function isNoopTextOperation(input = {}) {
  const operation = normalizeTextOperation(input);
  return operation.delete_count === 0 && operation.insert_text === "";
}

export function applyTextOperation(contentInput = "", operationInput = {}) {
  const content = String(contentInput ?? "");
  const operation = normalizeTextOperation(operationInput);
  let start = operation.start;

  if (start > content.length) {
    start = content.length;
  }

  const maxDelete = Math.max(0, content.length - start);
  const deleteCount = Math.min(operation.delete_count, maxDelete);

  return `${content.slice(0, start)}${operation.insert_text}${content.slice(start + deleteCount)}`;
}

function mapIndexThroughOperation(indexInput, operationInput, preferAfterInsert = false) {
  const index = toNonNegativeInt(indexInput);
  const operation = normalizeTextOperation(operationInput);
  const start = operation.start;
  const deleteCount = operation.delete_count;
  const insertLength = operation.insert_text.length;

  if (deleteCount === 0) {
    if (index < start) {
      return index;
    }

    return preferAfterInsert ? index + insertLength : index;
  }

  const end = start + deleteCount;
  const delta = insertLength - deleteCount;

  if (index < start) {
    return index;
  }

  if (index > end) {
    return index + delta;
  }

  if (index === start) {
    return start;
  }

  if (index === end) {
    return start + insertLength;
  }

  return start + insertLength;
}

export function transformTextOperation(operationInput = {}, againstOperationInput = {}, preferAfterAtSameInsert = true) {
  const operation = normalizeTextOperation(operationInput);
  const againstOperation = normalizeTextOperation(againstOperationInput);

  if (isNoopTextOperation(operation) || isNoopTextOperation(againstOperation)) {
    return { ...operation };
  }

  const transformedStart = mapIndexThroughOperation(
    operation.start,
    againstOperation,
    preferAfterAtSameInsert,
  );
  const transformedEnd = mapIndexThroughOperation(
    operation.start + operation.delete_count,
    againstOperation,
    preferAfterAtSameInsert,
  );
  const transformedDeleteCount = Math.max(0, transformedEnd - transformedStart);

  return {
    ...operation,
    start: transformedStart,
    delete_count: transformedDeleteCount,
  };
}

function compareOperationPriority(leftInput = {}, rightInput = {}) {
  const left = normalizeTextOperation(leftInput);
  const right = normalizeTextOperation(rightInput);

  const leftClientId = String(left.client_id || "");
  const rightClientId = String(right.client_id || "");
  if (leftClientId !== rightClientId) {
    return leftClientId.localeCompare(rightClientId);
  }

  return String(left.op_id || "").localeCompare(String(right.op_id || ""));
}

export function transformConcurrentTextOperations(remoteOperationInput = {}, localOperationInput = {}) {
  const remoteOperation = normalizeTextOperation(remoteOperationInput);
  const localOperation = normalizeTextOperation(localOperationInput);

  if (
    remoteOperation.client_id
    && localOperation.client_id
    && remoteOperation.client_id === localOperation.client_id
    && remoteOperation.op_id
    && remoteOperation.op_id === localOperation.op_id
  ) {
    return {
      remote: { ...remoteOperation },
      local: { ...localOperation },
    };
  }

  const priorityOrder = compareOperationPriority(remoteOperation, localOperation);
  const remoteAfterLocal = priorityOrder > 0;
  const localAfterRemote = !remoteAfterLocal;

  return {
    remote: transformTextOperation(remoteOperation, localOperation, remoteAfterLocal),
    local: transformTextOperation(localOperation, remoteOperation, localAfterRemote),
  };
}
