import { setLocale, resolveLocale } from "../i18n";

const LOCALE_KEY = "livecode.locale";
const THEME_KEY = "livecode.theme";

let mediaQueryListenerBound = false;

function normalizeTheme(theme) {
  if (theme === "light" || theme === "dark" || theme === "system") {
    return theme;
  }

  return "system";
}

function resolveTheme(themePreference) {
  const normalizedPreference = normalizeTheme(themePreference);

  if (normalizedPreference !== "system") {
    return normalizedPreference;
  }

  if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
    return "light";
  }

  return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}

function bindSystemThemeListener() {
  if (mediaQueryListenerBound || typeof window === "undefined" || typeof window.matchMedia !== "function") {
    return;
  }

  const mediaQuery = window.matchMedia("(prefers-color-scheme: dark)");
  const listener = () => {
    const themePreference = getStoredTheme();
    if (themePreference === "system") {
      applyTheme(themePreference);
    }
  };

  if (typeof mediaQuery.addEventListener === "function") {
    mediaQuery.addEventListener("change", listener);
  } else if (typeof mediaQuery.addListener === "function") {
    mediaQuery.addListener(listener);
  }

  mediaQueryListenerBound = true;
}

function setStoredValue(key, value) {
  if (typeof window === "undefined") {
    return;
  }

  if (value) {
    window.localStorage.setItem(key, value);
    return;
  }

  window.localStorage.removeItem(key);
}

function getStoredValue(key) {
  if (typeof window === "undefined") {
    return "";
  }

  return window.localStorage.getItem(key) || "";
}

export function languageToLocale(language) {
  return language === "rus" ? "ru" : "en";
}

export function localeToLanguage(locale) {
  return resolveLocale(locale) === "ru" ? "rus" : "eng";
}

export function getStoredTheme() {
  return normalizeTheme(getStoredValue(THEME_KEY));
}

export function getStoredLocale() {
  return resolveLocale(getStoredValue(LOCALE_KEY));
}

export function applyTheme(themePreference) {
  const preference = normalizeTheme(themePreference);
  const effectiveTheme = resolveTheme(preference);

  if (typeof document !== "undefined") {
    document.documentElement.setAttribute("data-theme", effectiveTheme);
    document.documentElement.setAttribute("data-theme-preference", preference);
  }

  bindSystemThemeListener();
}

export function setThemePreference(themePreference, persist = true) {
  const preference = normalizeTheme(themePreference);

  if (persist) {
    setStoredValue(THEME_KEY, preference);
  }

  applyTheme(preference);
}

export function setLocalePreference(locale, persist = true) {
  const resolvedLocale = resolveLocale(locale);

  if (persist) {
    setStoredValue(LOCALE_KEY, resolvedLocale);
  }

  setLocale(resolvedLocale);
}

export function applyUserPreferences(user, persist = true) {
  if (!user || typeof user !== "object") {
    return;
  }

  if (typeof user.language === "string" && user.language.trim() !== "") {
    setLocalePreference(languageToLocale(user.language), persist);
  }

  if (typeof user.theme === "string" && user.theme.trim() !== "") {
    setThemePreference(user.theme, persist);
  }
}

export function initializePreferences() {
  const preferredLocale = getStoredValue(LOCALE_KEY);
  const preferredTheme = getStoredValue(THEME_KEY);

  const browserLocale =
    typeof navigator !== "undefined" ? resolveLocale(navigator.language || navigator.languages?.[0] || "en") : "en";

  setLocalePreference(preferredLocale || browserLocale, Boolean(preferredLocale));
  setThemePreference(preferredTheme || "system", Boolean(preferredTheme));
}

