<template>
  <div class="page auth-page">
    <section class="card form-card">
      <h1>{{ t("register.title") }}</h1>

      <!-- Step 1: Initial Registration -->
      <form v-if="step === 1" class="form-grid" @submit.prevent="handleInitiateRegister">
        <label class="field">
          <span>{{ t("register.name") }}</span>
          <input v-model.trim="form.name" type="text" required autocomplete="name" />
        </label>

        <label class="field">
          <span>{{ t("register.email") }}</span>
          <input v-model.trim="form.email" type="email" required autocomplete="email" />
        </label>

        <label class="field">
          <span>{{ t("register.password") }}</span>
          <input
            v-model="form.password"
            type="password"
            required
            minlength="8"
            autocomplete="new-password"
          />
        </label>

        <label class="field">
          <span>{{ t("register.language") }}</span>
          <select v-model="form.language" required>
            <option value="rus">Русский</option>
            <option value="eng">English</option>
          </select>
        </label>

        <label v-if="captcha.enabled || captcha.loading || captcha.loadError" class="field">
          <span>{{ t("register.captchaLabel") }}</span>
          <p v-if="captcha.loading" class="muted-text">{{ t("register.captchaLoading") }}</p>
          <div
            v-if="captcha.enabled && (captcha.provider === 'cloudflare-turnstile' || captcha.provider === 'yandex-smartcaptcha')"
            ref="captchaContainer"
            class="captcha-container"
          ></div>
          <p v-else-if="captcha.enabled && captcha.provider === 'recaptcha'" class="muted-text">
            {{ t("register.captchaRecaptchaHint") }}
          </p>
          <p v-if="captcha.loadError" class="muted-text">{{ captcha.loadError }}</p>
        </label>

        <p v-if="errorMessage" class="error-banner field-row">{{ errorMessage }}</p>

        <button class="btn field-row" type="submit" :disabled="loading">
          {{ loading ? t("register.submitting") : t("register.submit") }}
        </button>
      </form>

      <!-- Step 2: Email Verification -->
      <form v-if="step === 2" class="form-grid" @submit.prevent="handleVerifyCode">
        <p class="muted-text">{{ t("register.verificationSent", { email: form.email }) }}</p>

        <label class="field">
          <span>{{ t("register.verificationCode") }}</span>
          <input v-model.trim="form.code" type="text" required maxlength="6" placeholder="000000" />
        </label>

        <p v-if="errorMessage" class="error-banner field-row">{{ errorMessage }}</p>

        <div class="field-row field-buttons">
          <button class="btn" type="submit" :disabled="loading">
            {{ loading ? t("register.verifying") : t("register.verify") }}
          </button>
          <button class="btn btn-secondary" type="button" @click="handleResendCode" :disabled="loading || resendCooldown > 0">
            {{ resendCooldown > 0 ? `${t("register.resendIn")} ${resendCooldown}s` : t("register.resendCode") }}
          </button>
          <button class="btn btn-ghost" type="button" @click="handleBackToInitialStep">{{ t("common.back") }}</button>
        </div>
      </form>
    </section>
  </div>
</template>

<script setup>
import { nextTick, onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";
import { useRouter } from "vue-router";
import { request } from "../services/api";
import { saveSession } from "../services/auth";
import { getAutoDeviceName } from "../services/device";

const router = useRouter();
const route = useRoute();
const { t } = useI18n();

const step = ref(1);  // 1 = initial, 2 = verification
const loading = ref(false);
const errorMessage = ref("");
const resendCooldown = ref(0);
const registrationVerificationId = ref(null);
const captchaContainer = ref(null);

const captcha = reactive({
  enabled: false,
  provider: "disabled",
  siteKey: "",
  token: "",
  widgetId: null,
  loading: false,
  loadError: "",
});

const form = reactive({
  name: "",
  email: "",
  password: "",
  code: "",
  language: "rus",
});

function getWindowObject() {
  return window;
}

function loadExternalScript(scriptId, source) {
  return new Promise((resolve, reject) => {
    const existing = document.getElementById(scriptId);
    if (existing) {
      resolve();
      return;
    }

    const script = document.createElement("script");
    script.id = scriptId;
    script.src = source;
    script.async = true;
    script.defer = true;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error(`Failed to load script: ${source}`));
    document.head.appendChild(script);
  });
}

function resetCaptchaToken() {
  captcha.token = "";

  const w = getWindowObject();
  if (captcha.provider === "cloudflare-turnstile" && w.turnstile && captcha.widgetId !== null) {
    w.turnstile.reset(captcha.widgetId);
  }

  if (captcha.provider === "yandex-smartcaptcha" && w.smartCaptcha && captcha.widgetId !== null) {
    w.smartCaptcha.reset(captcha.widgetId);
  }
}

async function initTurnstileCaptcha() {
  await loadExternalScript(
    "turnstile-script",
    "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit",
  );

  const w = getWindowObject();
  if (!w.turnstile) {
    throw new Error("Turnstile SDK not available");
  }

  await nextTick();
  if (!captchaContainer.value) {
    throw new Error("Turnstile container missing");
  }

  captcha.widgetId = w.turnstile.render(captchaContainer.value, {
    sitekey: captcha.siteKey,
    callback: (token) => {
      captcha.token = token || "";
    },
    "expired-callback": () => {
      captcha.token = "";
    },
    "error-callback": () => {
      captcha.token = "";
    },
  });
}

async function initRecaptchaCaptcha() {
  await loadExternalScript(
    "recaptcha-script",
    `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(captcha.siteKey)}`,
  );

  const w = getWindowObject();
  if (!w.grecaptcha) {
    throw new Error("reCAPTCHA SDK not available");
  }

  await new Promise((resolve) => {
    w.grecaptcha.ready(() => resolve());
  });
}

async function initYandexSmartCaptcha() {
  await loadExternalScript(
    "yandex-smartcaptcha-script",
    "https://smartcaptcha.yandexcloud.net/captcha.js",
  );

  const w = getWindowObject();
  if (!w.smartCaptcha || typeof w.smartCaptcha.render !== "function") {
    throw new Error("Yandex SmartCaptcha SDK not available");
  }

  await nextTick();
  if (!captchaContainer.value) {
    throw new Error("Yandex SmartCaptcha container missing");
  }

  captcha.widgetId = w.smartCaptcha.render(captchaContainer.value, {
    sitekey: captcha.siteKey,
    callback: (token) => {
      captcha.token = token || "";
    },
    "expired-callback": () => {
      captcha.token = "";
    },
    "error-callback": () => {
      captcha.token = "";
    },
  });
}

async function fetchCaptchaConfig() {
  captcha.loading = true;
  captcha.loadError = "";

  try {
    const response = await request({
      method: "GET",
      path: "/auth/captcha-config",
      auth: false,
    });

    const provider = String(response.data?.provider || "disabled");
    const siteKey = String(response.data?.site_key || "");
    const enabled = Boolean(response.data?.enabled) && provider !== "disabled" && siteKey !== "";

    captcha.provider = provider;
    captcha.siteKey = siteKey;
    captcha.enabled = enabled;
    captcha.token = "";
    captcha.widgetId = null;

    if (!captcha.enabled) {
      return;
    }

    if (provider === "cloudflare-turnstile") {
      await initTurnstileCaptcha();
      return;
    }

    if (provider === "yandex-smartcaptcha") {
      await initYandexSmartCaptcha();
      return;
    }

    if (provider === "recaptcha") {
      await initRecaptchaCaptcha();
      return;
    }
  } catch (error) {
    captcha.enabled = false;
    captcha.loadError = t("register.captchaLoadError");
  } finally {
    captcha.loading = false;
  }
}

async function resolveCaptchaToken() {
  if (!captcha.enabled) {
    return null;
  }

  const w = getWindowObject();

  if (captcha.provider === "cloudflare-turnstile") {
    if (!captcha.token) {
      throw new Error(t("register.captchaRequired"));
    }

    return captcha.token;
  }

  if (captcha.provider === "yandex-smartcaptcha") {
    if (!captcha.token) {
      throw new Error(t("register.captchaRequired"));
    }

    return captcha.token;
  }

  if (captcha.provider === "recaptcha") {
    if (!w.grecaptcha) {
      throw new Error(t("register.captchaUnavailable"));
    }

    await new Promise((resolve) => {
      w.grecaptcha.ready(() => resolve());
    });

    const token = await w.grecaptcha.execute(captcha.siteKey, { action: "register" });
    if (!token) {
      throw new Error(t("register.captchaRequired"));
    }

    captcha.token = token;
    return token;
  }

  return null;
}

function resolveErrorMessage(error) {
  if (error?.data?.errors && typeof error.data.errors === "object") {
    const joinedErrors = Object.values(error.data.errors).flat().join(" ");
    if (joinedErrors) {
      return joinedErrors;
    }
  }

  if (typeof error?.data?.message === "string" && error.data.message.trim() !== "") {
    return error.data.message;
  }

  if (typeof error?.message === "string" && error.message.trim() !== "") {
    return error.message;
  }

  return t("common.requestFailed");
}

function resolveRedirectPath() {
  const redirect = route.query.redirect;
  if (typeof redirect === "string" && redirect.startsWith("/") && !redirect.startsWith("//")) {
    return redirect;
  }

  return "/projects";
}

function handleBackToInitialStep() {
  step.value = 1;
  form.code = "";
  registrationVerificationId.value = null;
  resendCooldown.value = 0;
  errorMessage.value = "";
  resetCaptchaToken();
}

async function handleInitiateRegister() {
  loading.value = true;
  errorMessage.value = "";

  try {
    const captchaToken = await resolveCaptchaToken();

    const response = await request({
      method: "POST",
      path: "/auth/register/initiate",
      auth: false,
      body: {
        name: form.name,
        email: form.email,
        password: form.password,
        language: form.language || "rus",
        captcha_token: captchaToken || undefined,
      },
    });

    const verificationId = Number(response.data?.registration_verification_id || 0);
    if (!Number.isInteger(verificationId) || verificationId <= 0) {
      throw new Error(t("register.missingVerificationSession"));
    }

    registrationVerificationId.value = verificationId;

    // Move to verification step
    step.value = 2;
    form.code = "";
    resendCooldown.value = 0;
  } catch (error) {
    errorMessage.value = resolveErrorMessage(error);
    resetCaptchaToken();
  } finally {
    loading.value = false;
  }
}

async function handleVerifyCode() {
  loading.value = true;
  errorMessage.value = "";

  try {
    if (!registrationVerificationId.value) {
      throw new Error(t("register.missingVerificationSession"));
    }

    const deviceName = getAutoDeviceName();
    const response = await request({
      method: "POST",
      path: "/auth/register/verify",
      auth: false,
      body: {
        registration_verification_id: registrationVerificationId.value,
        verification_code: form.code,
        device_name: deviceName,
      },
    });

    const token = response.data?.access_token || "";
    const user = response.data?.user || null;

    if (!token) {
      throw new Error(t("register.missingToken"));
    }

    saveSession(token, user);
    router.push(resolveRedirectPath());
  } catch (error) {
    errorMessage.value = resolveErrorMessage(error);
  } finally {
    loading.value = false;
  }
}

async function handleResendCode() {
  loading.value = true;
  errorMessage.value = "";

  try {
    if (!registrationVerificationId.value) {
      throw new Error(t("register.missingVerificationSession"));
    }

    await request({
      method: "POST",
      path: "/auth/register/resend-code",
      auth: false,
      body: {
        registration_verification_id: registrationVerificationId.value,
      },
    });

    // Start cooldown
    resendCooldown.value = 60;
    const timer = setInterval(() => {
      resendCooldown.value--;
      if (resendCooldown.value <= 0) {
        clearInterval(timer);
      }
    }, 1000);
  } catch (error) {
    errorMessage.value = resolveErrorMessage(error);
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  fetchCaptchaConfig();
});
</script>
