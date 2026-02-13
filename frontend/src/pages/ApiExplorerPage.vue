<template>
  <div class="page explorer-page">
    <section class="card endpoint-panel">
      <div class="panel-head">
        <h2>{{ t("explorer.title") }}</h2>
        <input
          v-model.trim="searchTerm"
          type="text"
          :placeholder="t('explorer.searchPlaceholder')"
        />
      </div>

      <div class="endpoint-list">
        <button
          v-for="endpoint in filteredEndpoints"
          :key="endpoint.id"
          type="button"
          class="endpoint-item"
          :class="{ active: selectedEndpointId === endpoint.id }"
          @click="selectEndpoint(endpoint.id)"
        >
          <span class="method-badge" :class="methodClass(endpoint.method)">
            {{ endpoint.method }}
          </span>
          <span class="endpoint-copy">
            <strong>{{ endpoint.title }}</strong>
            <code>{{ endpoint.uri }}</code>
            <small>{{ endpoint.group }}</small>
          </span>
        </button>
      </div>
    </section>

    <section class="card request-panel">
      <div v-if="selectedEndpoint" class="request-head">
        <div>
          <h2>{{ selectedEndpoint.title }}</h2>
          <p class="muted-text">{{ selectedEndpoint.description }}</p>
          <code>{{ selectedEndpoint.uri }}</code>
        </div>
        <button class="btn btn-ghost btn-sm" type="button" @click="applyEndpointPreset(selectedEndpoint)">
          {{ t("explorer.resetPreset") }}
        </button>
      </div>

      <form class="form-grid" @submit.prevent="sendRequest">
        <label class="field">
          <span>{{ t("explorer.method") }}</span>
          <select v-model="requestMethod">
            <option v-for="method in methods" :key="method" :value="method">
              {{ method }}
            </option>
          </select>
        </label>

        <label class="field">
          <span>{{ t("explorer.useBearerToken") }}</span>
          <select v-model="useAuthToken">
            <option :value="true">{{ t("common.yes") }}</option>
            <option :value="false">{{ t("common.no") }}</option>
          </select>
        </label>

        <label class="field field-row">
          <span>{{ t("explorer.requestPath") }}</span>
          <input
            v-model.trim="requestPath"
            type="text"
            placeholder="/users/1"
            spellcheck="false"
          />
        </label>

        <label class="field field-row">
          <span>{{ t("explorer.queryString") }}</span>
          <input
            v-model.trim="requestQueryString"
            type="text"
            placeholder="per_page=20&project_id=1"
            spellcheck="false"
          />
        </label>

        <label class="field field-row">
          <span>{{ t("explorer.jsonBody") }}</span>
          <textarea
            v-model="requestBodyText"
            rows="12"
            spellcheck="false"
            placeholder='{"name":"Example"}'
          />
        </label>

        <p v-if="useAuthToken && !hasToken" class="warning-banner field-row">
          {{ t("explorer.bearerMissing") }}
        </p>
        <p v-if="requestError" class="error-banner field-row">{{ requestError }}</p>

        <button class="btn field-row" type="submit" :disabled="loading">
          {{ loading ? t("explorer.sendingRequest") : t("explorer.sendRequest") }}
        </button>
      </form>

      <section class="response-panel">
        <h3>{{ t("explorer.response") }}</h3>
        <p v-if="responseStatus !== null" class="meta-row">
          <span>{{ t("explorer.status") }}: <strong>{{ responseStatus }}</strong></span>
          <span>{{ t("explorer.time") }}: <strong>{{ responseTimeMs }}ms</strong></span>
        </p>
        <pre class="response-box">{{ responseText || t("explorer.noResponse") }}</pre>
      </section>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { apiEndpoints } from "../config/endpoints";
import { request } from "../services/api";
import { getAccessToken } from "../services/auth";

const { t } = useI18n();
const methods = ["GET", "POST", "PUT", "PATCH", "DELETE"];

const searchTerm = ref("");
const selectedEndpointId = ref(apiEndpoints[0]?.id || "");
const requestMethod = ref("GET");
const requestPath = ref("/");
const requestQueryString = ref("");
const requestBodyText = ref("");
const useAuthToken = ref(false);

const hasToken = ref(Boolean(getAccessToken()));
const loading = ref(false);
const requestError = ref("");
const responseStatus = ref(null);
const responseTimeMs = ref(null);
const responseText = ref("");

const filteredEndpoints = computed(() => {
  if (!searchTerm.value) {
    return apiEndpoints;
  }

  const normalized = searchTerm.value.toLowerCase();
  return apiEndpoints.filter((endpoint) => {
    return (
      endpoint.group.toLowerCase().includes(normalized) ||
      endpoint.title.toLowerCase().includes(normalized) ||
      endpoint.method.toLowerCase().includes(normalized) ||
      endpoint.uri.toLowerCase().includes(normalized)
    );
  });
});

const selectedEndpoint = computed(() => {
  return apiEndpoints.find((endpoint) => endpoint.id === selectedEndpointId.value) || null;
});

function methodClass(method) {
  return `method-${method.toLowerCase()}`;
}

function serializeQuerySample(sampleQuery) {
  if (!sampleQuery || typeof sampleQuery !== "object") {
    return "";
  }

  const query = new URLSearchParams();
  Object.entries(sampleQuery).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== "") {
      query.set(key, String(value));
    }
  });

  return query.toString();
}

function formatResponseData(data) {
  if (data === null || data === undefined) {
    return "";
  }

  if (typeof data === "string") {
    return data;
  }

  return JSON.stringify(data, null, 2);
}

function buildErrorMessage(error) {
  if (error?.data?.errors && typeof error.data.errors === "object") {
    const mergedErrors = Object.values(error.data.errors).flat().join(" ");
    if (mergedErrors) {
      return mergedErrors;
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

function parseQueryString(rawQueryString) {
  const trimmed = rawQueryString.trim();

  if (!trimmed) {
    return undefined;
  }

  const normalized = trimmed.startsWith("?") ? trimmed.slice(1) : trimmed;
  const params = new URLSearchParams(normalized);
  const query = {};

  for (const [key, value] of params.entries()) {
    query[key] = value;
  }

  return query;
}

function parseBody(rawBodyText) {
  const trimmed = rawBodyText.trim();

  if (!trimmed) {
    return undefined;
  }

  try {
    return JSON.parse(trimmed);
  } catch (_error) {
    throw new Error(t("explorer.invalidJson"));
  }
}

function applyEndpointPreset(endpoint) {
  requestMethod.value = endpoint.method;
  requestPath.value = endpoint.path;
  requestQueryString.value = serializeQuerySample(endpoint.sampleQuery);
  requestBodyText.value = endpoint.sampleBody ? JSON.stringify(endpoint.sampleBody, null, 2) : "";
  useAuthToken.value = Boolean(endpoint.auth);

  requestError.value = "";
  responseStatus.value = null;
  responseTimeMs.value = null;
  responseText.value = "";
}

function selectEndpoint(endpointId) {
  selectedEndpointId.value = endpointId;
}

function syncTokenState() {
  hasToken.value = Boolean(getAccessToken());
}

watch(
  selectedEndpoint,
  (endpoint) => {
    if (endpoint) {
      applyEndpointPreset(endpoint);
    }
  },
  { immediate: true },
);

async function sendRequest() {
  requestError.value = "";
  responseStatus.value = null;
  responseTimeMs.value = null;
  responseText.value = "";

  if (useAuthToken.value && !getAccessToken()) {
    requestError.value = t("explorer.noBearerStored");
    return;
  }

  let parsedBody;
  let parsedQuery;

  try {
    parsedBody = parseBody(requestBodyText.value);
    parsedQuery = parseQueryString(requestQueryString.value);
  } catch (error) {
    requestError.value = buildErrorMessage(error);
    return;
  }

  loading.value = true;
  const startedAt = Date.now();

  try {
    const response = await request({
      method: requestMethod.value,
      path: requestPath.value,
      query: parsedQuery,
      body: parsedBody,
      auth: useAuthToken.value,
    });

    responseStatus.value = response.status;
    responseTimeMs.value = Date.now() - startedAt;
    responseText.value = formatResponseData(response.data);
  } catch (error) {
    responseStatus.value = error?.status ?? 0;
    responseTimeMs.value = Date.now() - startedAt;
    responseText.value = formatResponseData(error?.data || { message: buildErrorMessage(error) });
    requestError.value = buildErrorMessage(error);
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  window.addEventListener("auth-changed", syncTokenState);
  syncTokenState();
});

onUnmounted(() => {
  window.removeEventListener("auth-changed", syncTokenState);
});
</script>
