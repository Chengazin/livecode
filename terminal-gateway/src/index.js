const path = require("node:path");
const http = require("node:http");
const { URL } = require("node:url");
const dotenv = require("dotenv");
const { WebSocketServer } = require("ws");
const { verifyTicket } = require("./ticketVerifier");
const { PtyManager } = require("./ptyManager");
const { createBackendReporter } = require("./backendReporter");

dotenv.config({ path: path.resolve(__dirname, "../../.env") });
dotenv.config({ path: path.resolve(__dirname, "../.env"), override: true });

function envNumber(name, fallback) {
  const parsed = Number.parseInt(String(process.env[name] || "").trim(), 10);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return fallback;
  }

  return parsed;
}

function envString(name, fallback = "") {
  const value = String(process.env[name] || "").trim();
  return value || fallback;
}

function envBoolean(name, fallback = false) {
  const value = String(process.env[name] || "").trim().toLowerCase();
  if (!value) {
    return fallback;
  }

  return value === "1" || value === "true" || value === "yes" || value === "on";
}

function parseCsv(rawValue) {
  const value = String(rawValue || "").trim();
  if (!value) {
    return [];
  }

  return value.split(",").map((item) => item.trim()).filter(Boolean);
}

function parseAllowedOrigins(rawValue) {
  const value = String(rawValue || "").trim();
  if (!value) {
    return ["http://localhost:8080", "http://127.0.0.1:8080"];
  }

  return value
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean);
}

function originAllowed(origin, allowedOrigins) {
  if (allowedOrigins.includes("*")) {
    return true;
  }

  if (!origin) {
    return false;
  }

  return allowedOrigins.includes(origin);
}

function rejectUpgrade(socket, statusCode, message) {
  try {
    socket.write(
      `HTTP/1.1 ${statusCode} ${message}\r\nConnection: close\r\nContent-Type: text/plain\r\n\r\n${message}`
    );
  } catch (_error) {
    // ignore
  }

  try {
    socket.destroy();
  } catch (_error) {
    // ignore
  }
}

const host = envString("TERMINAL_GATEWAY_HOST", "127.0.0.1");
const port = envNumber("TERMINAL_GATEWAY_PORT", 8090);
const wsPath = envString("TERMINAL_GATEWAY_PATH", "/terminal");
const sharedSecret = envString("TERMINAL_SHARED_SECRET", "");
const maxPayloadBytes = envNumber("TERMINAL_GATEWAY_MAX_PAYLOAD_BYTES", 128 * 1024);
const allowedOrigins = parseAllowedOrigins(
  process.env.TERMINAL_GATEWAY_ALLOWED_ORIGINS || "http://localhost:8080,http://127.0.0.1:8080"
);
const callbackUrl = envString("TERMINAL_BACKEND_CALLBACK_URL", "");
const callbackSecret = envString("TERMINAL_BACKEND_CALLBACK_SECRET", "");
const callbackHeader = envString("TERMINAL_BACKEND_CALLBACK_HEADER", "X-Terminal-Gateway-Secret");
const callbackTimeoutMs = envNumber("TERMINAL_BACKEND_CALLBACK_TIMEOUT_MS", 5000);
const runner = envString("TERMINAL_GATEWAY_RUNNER", "docker");
const allowUnsafeHostRunner = envBoolean("TERMINAL_GATEWAY_ALLOW_UNSAFE_HOST_RUNNER", false);

const backendReporter = createBackendReporter({
  urlTemplate: callbackUrl,
  secret: callbackSecret,
  headerName: callbackHeader,
  timeoutMs: callbackTimeoutMs,
});

if (!sharedSecret) {
  console.error("[terminal-gateway] TERMINAL_SHARED_SECRET is required");
  process.exit(1);
}

if (runner.trim().toLowerCase() === "host" && !allowUnsafeHostRunner) {
  console.error(
    "[terminal-gateway] host runner is disabled by default for security. Use TERMINAL_GATEWAY_RUNNER=docker or explicitly set TERMINAL_GATEWAY_ALLOW_UNSAFE_HOST_RUNNER=true in trusted single-tenant environments."
  );
  process.exit(1);
}

const manager = new PtyManager({
  idleTimeoutMs: envNumber("TERMINAL_GATEWAY_IDLE_TIMEOUT_SECONDS", 900) * 1000,
  hardTimeoutMs: envNumber("TERMINAL_GATEWAY_HARD_TIMEOUT_SECONDS", 8 * 3600) * 1000,
  cleanupIntervalMs: envNumber("TERMINAL_GATEWAY_CLEANUP_INTERVAL_MS", 5000),
  maxInputBytes: envNumber("TERMINAL_GATEWAY_MAX_INPUT_BYTES", 64 * 1024),
  maxOutputChunkBytes: envNumber("TERMINAL_GATEWAY_MAX_OUTPUT_CHUNK_BYTES", 64 * 1024),
  maxSocketBufferBytes: envNumber("TERMINAL_GATEWAY_MAX_SOCKET_BUFFER_BYTES", 1024 * 1024),
  defaultCols: envNumber("TERMINAL_GATEWAY_DEFAULT_COLS", 100),
  defaultRows: envNumber("TERMINAL_GATEWAY_DEFAULT_ROWS", 30),
  runner,
  allowUnsafeHostRunner,
  dockerBinary: envString("TERMINAL_DOCKER_BINARY", "docker"),
  dockerImage: envString("TERMINAL_DOCKER_IMAGE", "alpine:3.20"),
  dockerShell: envString("TERMINAL_DOCKER_SHELL", "/bin/sh"),
  dockerWorkdir: envString("TERMINAL_DOCKER_WORKDIR", "/workspace"),
  dockerNetwork: envString("TERMINAL_DOCKER_NETWORK", "none"),
  dockerCpus: envString("TERMINAL_DOCKER_CPU_LIMIT", ""),
  dockerMemory: envString("TERMINAL_DOCKER_MEMORY_LIMIT", ""),
  dockerPidsLimit: envString("TERMINAL_DOCKER_PIDS_LIMIT", ""),
  dockerUser: envString("TERMINAL_DOCKER_USER", ""),
  dockerCapDropAll: envBoolean("TERMINAL_DOCKER_CAP_DROP_ALL", true),
  dockerNoNewPrivileges: envBoolean("TERMINAL_DOCKER_NO_NEW_PRIVILEGES", true),
  dockerTmpfsMounts: parseCsv(process.env.TERMINAL_DOCKER_TMPFS_MOUNTS || "/tmp"),
  dockerHome: envString("TERMINAL_DOCKER_HOME", ""),
  dockerReadOnly: envBoolean("TERMINAL_DOCKER_READ_ONLY", true),
  dockerExtraArgs: parseCsv(process.env.TERMINAL_DOCKER_EXTRA_ARGS || ""),
  onSessionClosed: backendReporter.reportSessionClosed,
});

const server = http.createServer((req, res) => {
  if (!req.url) {
    res.statusCode = 404;
    res.end("Not found");
    return;
  }

  const requestUrl = new URL(req.url, `http://${req.headers.host || "localhost"}`);

  if (requestUrl.pathname === "/healthz") {
    res.setHeader("Content-Type", "application/json");
    res.end(JSON.stringify({ status: "ok" }));
    return;
  }

  res.statusCode = 404;
  res.end("Not found");
});

const wss = new WebSocketServer({
  noServer: true,
  maxPayload: maxPayloadBytes,
});

server.on("upgrade", (req, socket, head) => {
  const origin = String(req.headers.origin || "").trim();
  if (!originAllowed(origin, allowedOrigins)) {
    rejectUpgrade(socket, 403, "Forbidden");
    return;
  }

  if (!req.url) {
    rejectUpgrade(socket, 400, "Bad Request");
    return;
  }

  let requestUrl = null;

  try {
    requestUrl = new URL(req.url, `http://${req.headers.host || "localhost"}`);
  } catch (_error) {
    rejectUpgrade(socket, 400, "Bad Request");
    return;
  }

  if (requestUrl.pathname !== wsPath) {
    rejectUpgrade(socket, 404, "Not Found");
    return;
  }

  const ticket = requestUrl.searchParams.get("ticket") || "";
  if (!ticket) {
    rejectUpgrade(socket, 401, "Unauthorized");
    return;
  }

  let payload = null;

  try {
    payload = verifyTicket(ticket, sharedSecret);
  } catch (error) {
    const message = error instanceof Error ? error.message : "Invalid ticket";
    console.warn(`[terminal-gateway] ticket rejected: ${message}`);
    rejectUpgrade(socket, 401, "Unauthorized");
    return;
  }

  req.terminalTicketPayload = payload;
  req.terminalCols = requestUrl.searchParams.get("cols") || "";
  req.terminalRows = requestUrl.searchParams.get("rows") || "";

  wss.handleUpgrade(req, socket, head, (ws) => {
    wss.emit("connection", ws, req);
  });
});

wss.on("connection", (socket, req) => {
  const payload = req.terminalTicketPayload;

  try {
    manager.attachSocket(payload, socket, {
      cols: req.terminalCols,
      rows: req.terminalRows,
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Failed to attach terminal session.";

    try {
      socket.send(JSON.stringify({ type: "error", message }));
    } catch (_sendError) {
      // ignore
    }

    socket.close(1011, "Attach failed");
    return;
  }

  socket.on("message", (data) => {
    manager.handleMessage(socket, data);
  });

  socket.on("close", () => {
    manager.detachSocket(socket);
  });

  socket.on("error", () => {
    manager.detachSocket(socket);
  });
});

function shutdown(signal) {
  console.log(`[terminal-gateway] received ${signal}, shutting down`);

  try {
    wss.close();
  } catch (_error) {
    // ignore
  }

  manager.shutdown();

  server.close(() => {
    process.exit(0);
  });

  setTimeout(() => {
    process.exit(0);
  }, 3000).unref?.();
}

process.on("SIGINT", () => shutdown("SIGINT"));
process.on("SIGTERM", () => shutdown("SIGTERM"));

server.listen(port, host, () => {
  console.log(`[terminal-gateway] listening on ws://${host}:${port}${wsPath}`);
  console.log(`[terminal-gateway] allowed origins: ${allowedOrigins.join(", ")}`);
  console.log(`[terminal-gateway] runner: ${runner}`);
  console.log(
    `[terminal-gateway] backend callback: ${
      backendReporter.enabled ? "enabled" : "disabled"
    }`
  );
});
