<template>
  <div class="page auth-page">
    <section class="card form-card">
      <h1>{{ t("register.title") }}</h1>
      <p class="muted-text">{{ t("register.subtitle") }}</p>

      <form class="form-grid" @submit.prevent="handleSubmit">
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
            <option value="rus">rus</option>
            <option value="eng">eng</option>
          </select>
        </label>

        <p v-if="errorMessage" class="error-banner field-row">{{ errorMessage }}</p>

        <button class="btn field-row" type="submit" :disabled="loading">
          {{ loading ? t("register.submitting") : t("register.submit") }}
        </button>
      </form>
    </section>
  </div>
</template>

<script setup>
import { reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";
import { useRouter } from "vue-router";
import { request } from "../services/api";
import { saveSession } from "../services/auth";
import { getAutoDeviceName } from "../services/device";

const router = useRouter();
const route = useRoute();
const { t } = useI18n();
const loading = ref(false);
const errorMessage = ref("");

const form = reactive({
  name: "",
  email: "",
  password: "",
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

async function handleSubmit() {
  loading.value = true;
  errorMessage.value = "";

  try {
    const deviceName = getAutoDeviceName();
    const response = await request({
      method: "POST",
      path: "/auth/register",
      auth: false,
      body: {
        name: form.name,
        email: form.email,
        password: form.password,
        language: form.language || "rus",
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
</script>
