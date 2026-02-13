import { createI18n } from "vue-i18n";
import { messages } from "./messages";

const SUPPORTED_LOCALES = ["en", "ru"];

export function resolveLocale(value) {
  if (!value || typeof value !== "string") {
    return "en";
  }

  const normalized = value.toLowerCase().trim();

  if (normalized === "rus" || normalized === "ru" || normalized.startsWith("ru-")) {
    return "ru";
  }

  if (normalized === "eng" || normalized === "en" || normalized.startsWith("en-")) {
    return "en";
  }

  return SUPPORTED_LOCALES.includes(normalized) ? normalized : "en";
}

const i18n = createI18n({
  legacy: false,
  locale: "en",
  fallbackLocale: "en",
  messages,
});

export function setLocale(locale) {
  i18n.global.locale.value = resolveLocale(locale);
}

export function getLocale() {
  return i18n.global.locale.value;
}

export default i18n;

