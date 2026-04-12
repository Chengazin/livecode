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
          <button class="btn btn-ghost" type="button" @click="step = 1">{{ t("common.back") }}</button>
        </div>
      </form>
    </section>
  </div>
</template>

<script setup>
import { reactive, ref, watch } from "vue";
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

const form = reactive({
  name: "",
  email: "",
  password: "",
  code: "",
  language: "rus",
});

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

async function handleInitiateRegister() {
  loading.value = true;
  errorMessage.value = "";

  try {
    await request({
      method: "POST",
      path: "/auth/register/initiate",
      auth: false,
      body: {
        name: form.name,
        email: form.email,
        password: form.password,
        language: form.language || "rus",
      },
    });

    // Move to verification step
    step.value = 2;
    resendCooldown.value = 0;
  } catch (error) {
    errorMessage.value = resolveErrorMessage(error);
  } finally {
    loading.value = false;
  }
}

async function handleVerifyCode() {
  loading.value = true;
  errorMessage.value = "";

  try {
    const deviceName = getAutoDeviceName();
    const response = await request({
      method: "POST",
      path: "/auth/register/verify",
      auth: false,
      body: {
        email: form.email,
        code: form.code,
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
    await request({
      method: "POST",
      path: "/auth/register/resend-code",
      auth: false,
      body: {
        email: form.email,
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
</script>
