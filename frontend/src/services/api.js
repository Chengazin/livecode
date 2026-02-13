import { getAccessToken } from "./auth";

const environmentBase = process.env.VUE_APP_API_BASE_URL;
const API_BASE =
  environmentBase && environmentBase.trim() !== ""
    ? environmentBase.replace(/\/+$/, "")
    : "/api";

function resolveApiPath(path) {
  if (/^https?:\/\//i.test(path)) {
    return path;
  }

  const normalizedPath = path.startsWith("/") ? path : `/${path}`;

  if (normalizedPath.startsWith("/api/")) {
    if (API_BASE === "/api") {
      return normalizedPath;
    }

    if (API_BASE.endsWith("/api")) {
      return `${API_BASE}${normalizedPath.slice(4)}`;
    }
  }

  return `${API_BASE}${normalizedPath}`;
}

function buildUrl(path, query) {
  const resolvedPath = resolveApiPath(path);
  const url = new URL(resolvedPath, window.location.origin);

  if (query && typeof query === "object") {
    Object.entries(query).forEach(([key, value]) => {
      if (value === undefined || value === null || value === "") {
        return;
      }

      if (Array.isArray(value)) {
        value.forEach((item) => url.searchParams.append(key, String(item)));
        return;
      }

      url.searchParams.set(key, String(value));
    });
  }

  return url;
}

export function buildApiUrl(path, query = undefined) {
  return buildUrl(path, query).toString();
}

function parseBody(text) {
  if (!text) {
    return null;
  }

  try {
    return JSON.parse(text);
  } catch (_error) {
    return text;
  }
}

function buildError(response, data) {
  let message = `HTTP ${response.status}`;

  if (data && typeof data === "object") {
    if (typeof data.message === "string" && data.message.trim() !== "") {
      message = data.message;
    } else if (typeof data.error === "string" && data.error.trim() !== "") {
      message = data.error;
    }
  }

  const error = new Error(message);
  error.status = response.status;
  error.data = data;

  return error;
}

export async function request({
  method = "GET",
  path,
  query = undefined,
  body = undefined,
  auth = true,
  headers = {},
}) {
  const upperMethod = method.toUpperCase();
  const url = buildUrl(path, query);

  const requestHeaders = {
    Accept: "application/json",
    ...headers,
  };

  if (auth) {
    const accessToken = getAccessToken();
    if (accessToken) {
      requestHeaders.Authorization = `Bearer ${accessToken}`;
    }
  }

  let serializedBody;
  if (body !== undefined && body !== null && upperMethod !== "GET" && upperMethod !== "HEAD") {
    const isFormData =
      typeof FormData !== "undefined" && body instanceof FormData;

    if (isFormData) {
      serializedBody = body;
    } else {
      requestHeaders["Content-Type"] = "application/json";
      serializedBody = JSON.stringify(body);
    }
  }

  const response = await fetch(url.toString(), {
    method: upperMethod,
    headers: requestHeaders,
    body: serializedBody,
  });

  const rawText = await response.text();
  const data = parseBody(rawText);

  if (!response.ok) {
    throw buildError(response, data);
  }

  return {
    ok: true,
    status: response.status,
    data,
    headers: response.headers,
  };
}
