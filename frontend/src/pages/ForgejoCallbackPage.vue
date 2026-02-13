<template>
  <div class="page auth-page">
    <section class="card form-card">
      <h1>{{ t("callback.title") }}</h1>
      <p class="muted-text">
        {{ t("callback.subtitle") }}
      </p>
      <p v-if="errorMessage" class="error-banner">{{ errorMessage }}</p>
      <p v-else class="notice-banner">{{ statusMessage }}</p>
      <RouterLink to="/editor" class="btn btn-secondary">{{ t("callback.back") }}</RouterLink>
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";
import { request } from "../services/api";
import { saveSession } from "../services/auth";

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const statusMessage = ref(t("callback.processing"));
const errorMessage = ref("");

function getQueryValue(value) {
  return typeof value === "string" ? value : "";
}

onMounted(async () => {
  const code = getQueryValue(route.query.code);
  const state = getQueryValue(route.query.state);

  if (!code || !state) {
    errorMessage.value = t("callback.missingParams");
    return;
  }

  try {
    const response = await request({
      method: "GET",
      path: "/forgejo/oauth/callback",
      auth: false,
      query: {
        code,
        state,
      },
    });

    const token = response.data?.access_token || "";
    const user = response.data?.user || null;

    if (token) {
      saveSession(token, user);
      statusMessage.value = t("callback.oauthSucceeded");
      setTimeout(() => {
        router.push("/editor");
      }, 600);
      return;
    }

    if (response.data?.status === "connected") {
      statusMessage.value = t("callback.connected");
      setTimeout(() => {
        router.push("/editor?forgejo=connected");
      }, 600);
      return;
    }

    statusMessage.value = t("callback.noToken");
  } catch (error) {
    errorMessage.value =
      (typeof error?.data?.message === "string" && error.data.message) ||
      (typeof error?.message === "string" && error.message) ||
      t("callback.failed");
  }
});
</script>
