import { applyUserPreferences } from "./preferences";

const TOKEN_KEY = "livecode.access_token";
const USER_KEY = "livecode.user";

function parseUser(rawUser) {
  if (!rawUser) {
    return null;
  }

  try {
    return JSON.parse(rawUser);
  } catch (_error) {
    return null;
  }
}

function emitAuthChanged() {
  window.dispatchEvent(
    new CustomEvent("auth-changed", {
      detail: getSession(),
    }),
  );
}

export function getAccessToken() {
  return window.localStorage.getItem(TOKEN_KEY) || "";
}

export function getUser() {
  return parseUser(window.localStorage.getItem(USER_KEY));
}

export function getSession() {
  return {
    accessToken: getAccessToken(),
    user: getUser(),
  };
}

export function saveSession(accessToken, user) {
  if (accessToken) {
    window.localStorage.setItem(TOKEN_KEY, accessToken);
  } else {
    window.localStorage.removeItem(TOKEN_KEY);
  }

  if (user) {
    window.localStorage.setItem(USER_KEY, JSON.stringify(user));
    applyUserPreferences(user);
  } else {
    window.localStorage.removeItem(USER_KEY);
  }

  emitAuthChanged();
}

export function setUser(user, emit = true) {
  if (user) {
    window.localStorage.setItem(USER_KEY, JSON.stringify(user));
    applyUserPreferences(user);
  } else {
    window.localStorage.removeItem(USER_KEY);
  }

  if (emit) {
    emitAuthChanged();
  }
}

export function clearSession() {
  window.localStorage.removeItem(TOKEN_KEY);
  window.localStorage.removeItem(USER_KEY);
  emitAuthChanged();
}
