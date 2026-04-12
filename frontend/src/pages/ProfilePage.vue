<template>
  <div class="page profile-page">
    <section class="card profile-avatar-card">
      <h2>{{ t("profile.iconTitle") }}</h2>

      <div class="profile-avatar-preview-wrap">
        <img
          v-if="previewMode === 'upload' && profile.avatarUrl"
          :src="profile.avatarUrl"
          :alt="t('profile.avatarAlt')"
          class="profile-avatar-image"
        />
        <div v-else class="profile-avatar-preset" :style="{ background: selectedPreset.background }">
          <span>{{ selectedPreset.symbol }}</span>
        </div>
      </div>

      <div ref="presetPickerRef" class="profile-preset-picker">
        <button class="profile-preset-toggle" type="button" :disabled="uploading" @click="togglePresetPicker">
          <span class="profile-preset-toggle-main">
            <span class="profile-preset-chip" :style="{ background: selectedPreset.background }">
              {{ selectedPreset.symbol }}
            </span>
            <span>{{ selectedPreset.label }}</span>
          </span>
          <span class="profile-preset-caret">{{ presetPickerOpen ? "^" : "v" }}</span>
        </button>

        <div v-if="presetPickerOpen" class="profile-preset-menu">
          <button
            v-for="preset in availablePresets"
            :key="preset.key"
            class="profile-preset-option"
            :class="{ active: profile.avatarPreset === preset.key }"
            type="button"
            @click="selectPreset(preset.key)"
          >
            <span class="profile-preset-chip" :style="{ background: preset.background }">
              {{ preset.symbol }}
            </span>
            <span>{{ preset.label }}</span>
          </button>
        </div>
      </div>

      <div class="profile-upload-control">
        <input
          ref="avatarInput"
          class="profile-upload-native"
          type="file"
          accept="image/png,image/jpeg,image/webp,image/gif"
          @change="uploadAvatar"
        />
        <button class="profile-upload-trigger" type="button" :disabled="uploading" @click="openAvatarPicker">
          {{ uploading ? t("common.loading") : t("profile.uploadImage") }}
        </button>
      </div>
    </section>

    <section class="card profile-form-card">
      <h1>{{ t("profile.title") }}</h1>

      <form class="form-grid" @submit.prevent="saveProfile">
        <label class="field field-row">
          <span>{{ t("profile.name") }}</span>
          <input v-model.trim="profile.name" type="text" maxlength="255" required />
        </label>

        <label class="field">
          <span>{{ t("common.language") }}</span>
          <select v-model="profile.language" required>
            <option value="rus">rus</option>
            <option value="eng">eng</option>
          </select>
        </label>

        <label class="field">
          <span>{{ t("profile.themesLabel") }}</span>
          <select v-model="profile.theme" required @change="previewTheme">
            <option v-for="value in profile.themeOptions" :key="value" :value="value">
              {{ t(`themes.${value}`) }}
            </option>
          </select>
        </label>

        <label class="field field-row">
          <span>{{ t("profile.email") }}</span>
          <input v-model.trim="profile.email" type="email" maxlength="255" required />
        </label>

        <p class="profile-security-hint field-row">
          {{ t("profile.passwordChangeHint") }}
        </p>

        <label class="field">
          <span>{{ t("profile.newPassword") }}</span>
          <div class="profile-password-input-wrap">
            <input
              v-model="profile.newPassword"
              :type="newPasswordVisible ? 'text' : 'password'"
              autocomplete="new-password"
              minlength="8"
              maxlength="255"
              class="profile-password-input"
            />
            <button
              class="profile-password-input-toggle"
              type="button"
              :aria-label="newPasswordVisible ? t('profile.hidePassword') : t('profile.showPassword')"
              @click="newPasswordVisible = !newPasswordVisible"
            >
              <svg v-if="newPasswordVisible" viewBox="0 0 24 24" class="profile-password-input-icon" aria-hidden="true">
                <path d="M2 4.2 3.2 3 21 20.8 19.8 22l-3.2-3.2A11.9 11.9 0 0 1 12 20C7 20 2.7 17.1 1 12.9a12.3 12.3 0 0 1 3.8-5L2 4.2Zm4 4 2.4 2.4A4 4 0 0 0 12 16a4 4 0 0 0 2.4-.8l1.8 1.8A9.9 9.9 0 0 1 12 18c-4 0-7.5-2.2-9.1-5.6A10.5 10.5 0 0 1 6 8.2Zm6-4.2c5 0 9.3 2.9 11 7.1a12.1 12.1 0 0 1-4.7 5.6l-1.4-1.4A10 10 0 0 0 21.1 12c-1.6-3.4-5.1-5.6-9.1-5.6-1.4 0-2.7.3-4 .8L6.4 5.6A11.7 11.7 0 0 1 12 4Zm0 4a4 4 0 0 1 4 4c0 .9-.3 1.8-.8 2.4l-5.6-5.6c.6-.5 1.5-.8 2.4-.8Z" />
              </svg>
              <svg v-else viewBox="0 0 24 24" class="profile-password-input-icon" aria-hidden="true">
                <path d="M12 5c5 0 9.3 2.9 11 7-1.7 4.1-6 7-11 7S2.7 16.1 1 12C2.7 7.9 7 5 12 5Zm0 2C8 7 4.5 9.2 2.9 12 4.5 14.8 8 17 12 17s7.5-2.2 9.1-5C19.5 9.2 16 7 12 7Zm0 2.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Z" />
              </svg>
            </button>
          </div>
        </label>

        <label class="field">
          <span>{{ t("profile.newPasswordConfirmation") }}</span>
          <div class="profile-password-input-wrap">
            <input
              v-model="profile.newPasswordConfirmation"
              :type="newPasswordConfirmationVisible ? 'text' : 'password'"
              autocomplete="new-password"
              minlength="8"
              maxlength="255"
              class="profile-password-input"
            />
            <button
              class="profile-password-input-toggle"
              type="button"
              :aria-label="newPasswordConfirmationVisible ? t('profile.hidePassword') : t('profile.showPassword')"
              @click="newPasswordConfirmationVisible = !newPasswordConfirmationVisible"
            >
              <svg v-if="newPasswordConfirmationVisible" viewBox="0 0 24 24" class="profile-password-input-icon" aria-hidden="true">
                <path d="M2 4.2 3.2 3 21 20.8 19.8 22l-3.2-3.2A11.9 11.9 0 0 1 12 20C7 20 2.7 17.1 1 12.9a12.3 12.3 0 0 1 3.8-5L2 4.2Zm4 4 2.4 2.4A4 4 0 0 0 12 16a4 4 0 0 0 2.4-.8l1.8 1.8A9.9 9.9 0 0 1 12 18c-4 0-7.5-2.2-9.1-5.6A10.5 10.5 0 0 1 6 8.2Zm6-4.2c5 0 9.3 2.9 11 7.1a12.1 12.1 0 0 1-4.7 5.6l-1.4-1.4A10 10 0 0 0 21.1 12c-1.6-3.4-5.1-5.6-9.1-5.6-1.4 0-2.7.3-4 .8L6.4 5.6A11.7 11.7 0 0 1 12 4Zm0 4a4 4 0 0 1 4 4c0 .9-.3 1.8-.8 2.4l-5.6-5.6c.6-.5 1.5-.8 2.4-.8Z" />
              </svg>
              <svg v-else viewBox="0 0 24 24" class="profile-password-input-icon" aria-hidden="true">
                <path d="M12 5c5 0 9.3 2.9 11 7-1.7 4.1-6 7-11 7S2.7 16.1 1 12C2.7 7.9 7 5 12 5Zm0 2C8 7 4.5 9.2 2.9 12 4.5 14.8 8 17 12 17s7.5-2.2 9.1-5C19.5 9.2 16 7 12 7Zm0 2.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5Z" />
              </svg>
            </button>
          </div>
        </label>

        <p v-if="notice" class="notice-banner field-row">{{ notice }}</p>
        <p v-if="error" class="error-banner field-row">{{ error }}</p>

        <button class="btn field-row" type="submit" :disabled="saving || uploading">
          {{ saving ? t("common.saving") : t("profile.saveProfile") }}
        </button>

        <button class="btn btn-danger field-row" type="button" :disabled="loggingOut" @click="logout">
          {{ loggingOut ? t("common.loggingOut") : t("common.logout") }}
        </button>
      </form>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRouter } from "vue-router";
import { request } from "../services/api";
import { clearSession, getSession, setUser } from "../services/auth";
import { avatarPresetStyles, defaultAvatarPreset } from "../config/avatarPresets";
import { languageToLocale, setLocalePreference, setThemePreference } from "../services/preferences";

const router = useRouter();
const { t } = useI18n();

const profile = reactive({
  name: "",
  email: "",
  language: "rus",
  theme: "system",
  themeOptions: ["light", "dark", "system"],
  avatarType: "preset",
  avatarPreset: defaultAvatarPreset,
  avatarUrl: "",
  presets: [defaultAvatarPreset],
  newPassword: "",
  newPasswordConfirmation: "",
});

const previewMode = ref("preset");
const loading = ref(false);
const saving = ref(false);
const uploading = ref(false);
const newPasswordVisible = ref(false);
const newPasswordConfirmationVisible = ref(false);
const avatarInput = ref(null);
const selectedAvatarFilename = ref("");
const presetPickerRef = ref(null);
const presetPickerOpen = ref(false);
const avatarPresetDirty = ref(false);
const notice = ref("");
const error = ref("");
const loggingOut = ref(false);

const isAuthenticated = computed(() => Boolean(getSession().accessToken));

const availablePresets = computed(() => {
  const keys = Array.isArray(profile.presets) && profile.presets.length > 0
    ? profile.presets
    : [defaultAvatarPreset];

  return keys.map((key) => {
    const style = avatarPresetStyles[key] || avatarPresetStyles[defaultAvatarPreset];
    return {
      key,
      label: t(`profile.presets.${key}`),
      symbol: style.symbol,
      background: style.background,
    };
  });
});

const selectedPreset = computed(() => {
  return (
    availablePresets.value.find((preset) => preset.key === profile.avatarPreset) ||
    availablePresets.value[0] || {
      key: defaultAvatarPreset,
      label: t(`profile.presets.${defaultAvatarPreset}`),
      ...avatarPresetStyles[defaultAvatarPreset],
    }
  );
});

function resolveErrorMessage(errorInput) {
  if (errorInput?.data?.errors && typeof errorInput.data.errors === "object") {
    const mergedErrors = Object.values(errorInput.data.errors).flat().join(" ");
    if (mergedErrors) {
      return mergedErrors;
    }
  }

  if (typeof errorInput?.data?.message === "string" && errorInput.data.message.trim() !== "") {
    return errorInput.data.message;
  }

  if (typeof errorInput?.message === "string" && errorInput.message.trim() !== "") {
    return errorInput.message;
  }

  return t("common.requestFailed");
}

function applyProfile(user) {
  profile.name = user?.name || "";
  profile.email = user?.email || "";
  profile.language = user?.language || "rus";
  profile.theme = user?.theme || "system";
  profile.themeOptions = Array.isArray(user?.theme_options) && user.theme_options.length > 0
    ? user.theme_options
    : ["light", "dark", "system"];
  profile.avatarType = user?.avatar_type || "preset";
  profile.avatarPreset = user?.avatar_preset || defaultAvatarPreset;
  profile.avatarUrl = user?.avatar_url || "";
  profile.presets = Array.isArray(user?.avatar_presets) && user.avatar_presets.length > 0
    ? user.avatar_presets
    : [defaultAvatarPreset];
  previewMode.value = profile.avatarType === "upload" && profile.avatarUrl ? "upload" : "preset";
  selectedAvatarFilename.value = "";
  presetPickerOpen.value = false;
  avatarPresetDirty.value = false;
  profile.newPassword = "";
  profile.newPasswordConfirmation = "";
  newPasswordVisible.value = false;
  newPasswordConfirmationVisible.value = false;

  setUser(user || null);
}

async function loadProfile() {
  if (!isAuthenticated.value) {
    router.push("/login");
    return;
  }

  loading.value = true;
  error.value = "";

  try {
    const response = await request({
      method: "GET",
      path: "/me",
      auth: true,
    });

    applyProfile(response.data);
  } catch (loadError) {
    error.value = resolveErrorMessage(loadError);
  } finally {
    loading.value = false;
  }
}

function selectPreset(presetKey) {
  profile.avatarPreset = presetKey;
  previewMode.value = "preset";
  presetPickerOpen.value = false;
  avatarPresetDirty.value = true;
}

function previewTheme() {
  setThemePreference(profile.theme, false);
}

function togglePresetPicker() {
  if (uploading.value) {
    return;
  }

  presetPickerOpen.value = !presetPickerOpen.value;
}

function handlePointerDown(event) {
  if (!presetPickerOpen.value || !presetPickerRef.value) {
    return;
  }

  if (!presetPickerRef.value.contains(event.target)) {
    presetPickerOpen.value = false;
  }
}

function handleKeydown(event) {
  if (event.key === "Escape") {
    presetPickerOpen.value = false;
  }
}

function openAvatarPicker() {
  if (uploading.value || !avatarInput.value) {
    return;
  }

  avatarInput.value.click();
}

async function saveProfile() {
  error.value = "";
  notice.value = "";

  const passwordProvided = profile.newPassword.trim() !== "";

  if (passwordProvided && profile.newPassword !== profile.newPasswordConfirmation) {
    error.value = t("profile.passwordConfirmationMismatch");
    return;
  }

  saving.value = true;

  try {
    const payload = {
      name: profile.name,
      email: profile.email,
      language: profile.language,
      theme: profile.theme,
    };

    if (avatarPresetDirty.value) {
      payload.avatar_preset = profile.avatarPreset;
    }

    if (passwordProvided) {
      payload.new_password = profile.newPassword;
      payload.new_password_confirmation = profile.newPasswordConfirmation;
    }

    const response = await request({
      method: "PATCH",
      path: "/me/profile",
      auth: true,
      body: payload,
    });

    if (response?.data?.logged_out_all) {
      clearSession();
      router.push("/login");
      return;
    }

    applyProfile(response.data);
    setLocalePreference(languageToLocale(profile.language), true);
    setThemePreference(profile.theme, true);
    notice.value = t("profile.profileUpdated");
  } catch (saveError) {
    error.value = resolveErrorMessage(saveError);
  } finally {
    saving.value = false;
  }
}

async function uploadAvatar(event) {
  const file = event?.target?.files?.[0];
  if (!file) {
    return;
  }

  selectedAvatarFilename.value = file.name;
  uploading.value = true;
  error.value = "";
  notice.value = "";

  try {
    const formData = new FormData();
    formData.append("avatar", file);

    const response = await request({
      method: "POST",
      path: "/me/avatar",
      auth: true,
      body: formData,
    });

    applyProfile(response.data);
    notice.value = t("profile.avatarUploaded");
  } catch (uploadError) {
    error.value = resolveErrorMessage(uploadError);
  } finally {
    uploading.value = false;

    if (event?.target) {
      event.target.value = "";
    }
  }
}

async function logout() {
  loggingOut.value = true;
  error.value = "";
  notice.value = "";

  try {
    await request({
      method: "POST",
      path: "/auth/logout",
      auth: true,
    });
  } catch (_logoutError) {
    // Ignore remote logout error and clear local session anyway.
  } finally {
    clearSession();
    router.push("/login");
    loggingOut.value = false;
  }
}

onMounted(() => {
  if (loading.value) {
    return;
  }

  document.addEventListener("pointerdown", handlePointerDown);
  window.addEventListener("keydown", handleKeydown);
  void loadProfile();
});

onUnmounted(() => {
  document.removeEventListener("pointerdown", handlePointerDown);
  window.removeEventListener("keydown", handleKeydown);
});
</script>
