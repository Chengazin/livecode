<template>
  <div class="page auth-page">
    <section class="card form-card">
      <h1>{{ t("callback.title") }}</h1>
      <p class="muted-text">
        {{ t("callback.subtitle") }}
      </p>
      <p v-if="errorMessage" class="error-banner">{{ errorMessage }}</p>
      <p v-else class="notice-banner">{{ statusMessage }}</p>
      <RouterLink :to="backTarget" class="btn btn-secondary">{{ t("callback.back") }}</RouterLink>
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";
import { request } from "../services/api";
import { saveSession, setUser } from "../services/auth";

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const FORGEJO_RETURN_KEY = "livecode.forgejo.return_path";

const statusMessage = ref(t("callback.processing"));
const errorMessage = ref("");
const backTarget = ref("/projects");

function getQueryValue(value) {
  return typeof value === "string" ? value : "";
}

function consumeReturnPath() {
  try {
    const stored = window.localStorage.getItem(FORGEJO_RETURN_KEY) || "";
    window.localStorage.removeItem(FORGEJO_RETURN_KEY);

    if (!stored || !stored.startsWith("/")) {
      return "/projects";
    }

    return stored;
  } catch (_error) {
    return "/projects";
  }
}

function withForgejoConnected(path) {
  try {
    const url = new URL(path, window.location.origin);
    url.searchParams.set("forgejo", "connected");
    return `${url.pathname}${url.search}${url.hash}`;
  } catch (_error) {
    return "/projects?forgejo=connected";
  }
}

onMounted(async () => {
  const returnPath = consumeReturnPath();
  backTarget.value = returnPath;

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
      auth: true,
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
        router.push(returnPath);
      }, 600);
      return;
    }

    if (response.data?.status === "connected") {
      if (response.data?.user && typeof response.data.user === "object") {
        setUser(response.data.user);
      }
      statusMessage.value = t("callback.connected");
      setTimeout(() => {
        router.push(withForgejoConnected(returnPath));
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
