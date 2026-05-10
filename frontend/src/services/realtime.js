import Echo from "laravel-echo";
import Pusher from "pusher-js";
let echo = null;
let echoToken = "";
if (typeof window !== "undefined") {
  window.Pusher = Pusher;
}
function normalizeString(value, fallback = "") {
  const text = String(value ?? "").trim().replace(/^['"]|['"]$/g, "");
  return text || fallback;
}
function normalizePort(value, fallback) {
  const parsed = Number.parseInt(String(value ?? "").trim(), 10);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return fallback;
  }
  return parsed;
}
function resolveDefaultHost() {
  if (typeof window === "undefined") {
    return "127.0.0.1";
  }
  const hostname = normalizeString(window.location.hostname, "localhost");
  // Avoid IPv6 localhost (::1) resolution when Reverb listens on IPv4.
  return hostname === "localhost" ? "127.0.0.1" : hostname;
}
function resolveClientConfig(token) {
  const isSecureContext = typeof window !== "undefined" && window.location.protocol === "https:";
  const scheme = normalizeString(process.env.VUE_APP_REVERB_SCHEME, isSecureContext ? "https" : "http").toLowerCase();
  const host = normalizeString(process.env.VUE_APP_REVERB_HOST, resolveDefaultHost());
  const defaultPort = scheme === "https" ? 443 : 80;
  const port = normalizePort(process.env.VUE_APP_REVERB_PORT, defaultPort);
  const key = normalizeString(process.env.VUE_APP_REVERB_APP_KEY, "livecode-key");
  const authEndpoint = normalizeString(process.env.VUE_APP_REVERB_AUTH_ENDPOINT, "/api/broadcasting/auth");
  const wsPath = normalizeString(process.env.VUE_APP_REVERB_PATH, "");
  const config = {
    broadcaster: "reverb",
    key,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === "https",
    enabledTransports: ["ws", "wss"],
    authEndpoint,
    auth: {
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${token}`,
      },
    },
  };
  if (wsPath) {
    config.wsPath = wsPath;
  }
  return config;
}
function createClient(token) {
  return new Echo(resolveClientConfig(token));
}
export function getRealtimeClient(token) {
  const authToken = String(token || "").trim();
  if (!authToken || typeof window === "undefined") {
    return null;
  }
  if (!echo || echoToken !== authToken) {
    if (echo) {
      echo.disconnect();
    }
    echo = createClient(authToken);
    echoToken = authToken;
  }
  return echo;
}
export function joinProjectRealtimeChannel(projectId, token) {
  const client = getRealtimeClient(token);
  if (!client || !projectId) {
    return null;
  }
  return client.private(`project.${projectId}`);
}
export function leaveProjectRealtimeChannel(projectId) {
  if (!echo || !projectId) {
    return;
  }
  echo.leave(`project.${projectId}`);
}
export function disconnectRealtimeClient() {
  if (!echo) {
    return;
  }
  echo.disconnect();
  echo = null;
  echoToken = "";
}
export function getRealtimeSocketId() {
  if (!echo) {
    return "";
  }
  return String(echo.socketId() || "");
}
