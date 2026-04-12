const fs = require("node:fs");
const path = require("node:path");
const pty = require("node-pty");

function isPathInside(basePath, targetPath) {
  const relative = path.relative(basePath, targetPath);
  return relative === "" || (!relative.startsWith("..") && !path.isAbsolute(relative));
}

function realpathDirectory(value) {
  const absolute = path.resolve(String(value || process.cwd()));

  if (!fs.existsSync(absolute)) {
    return null;
  }

  let stats = null;
  try {
    stats = fs.statSync(absolute);
  } catch (_error) {
    return null;
  }

  if (!stats.isDirectory()) {
    return null;
  }

  try {
    if (typeof fs.realpathSync.native === "function") {
      return fs.realpathSync.native(absolute);
    }

    return fs.realpathSync(absolute);
  } catch (_error) {
    return null;
  }
}

function ensureDirectoryWithin(basePath, targetPath, options = {}) {
  const strict = Boolean(options.strict);
  const label = String(options.label || "directory");
  const base = realpathDirectory(basePath);

  if (!base) {
    throw new Error(`Ticket ${label} base is invalid.`);
  }

  const candidate = realpathDirectory(targetPath || base);

  if (!candidate) {
    if (strict) {
      throw new Error(`Ticket ${label} is invalid.`);
    }

    return base;
  }

  if (!isPathInside(base, candidate)) {
    if (strict) {
      throw new Error(`Ticket ${label} is outside sandbox.`);
    }

    return base;
  }

  return candidate;
}

function normalizeRows(value, fallback = 24) {
  const parsed = Number.parseInt(String(value || "").trim(), 10);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return fallback;
  }

  return Math.max(10, Math.min(parsed, 400));
}

function normalizeCols(value, fallback = 80) {
  const parsed = Number.parseInt(String(value || "").trim(), 10);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return fallback;
  }

  return Math.max(20, Math.min(parsed, 600));
}

function normalizeRunner(value) {
  const normalized = String(value || "docker").trim().toLowerCase();

  if (normalized === "host") {
    return "host";
  }

  return "docker";
}

function normalizeShell(shellName) {
  const candidate = String(shellName || "").trim();

  if (candidate) {
    return candidate;
  }

  if (process.platform === "win32") {
    return "powershell.exe";
  }

  return "/bin/bash";
}

function hostShellArgs(shellName) {
  const normalized = String(shellName || "").toLowerCase();

  if (normalized.includes("powershell") || normalized.includes("pwsh")) {
    return ["-NoLogo"];
  }

  if (normalized.endsWith("cmd.exe") || normalized === "cmd") {
    return [];
  }

  return [];
}

function normalizeContainerWorkdir(value) {
  const raw = String(value || "/workspace").trim().replace(/\\/g, "/");
  if (!raw) {
    return "/workspace";
  }

  const withLeadingSlash = raw.startsWith("/") ? raw : `/${raw}`;
  return withLeadingSlash.replace(/\/+/g, "/").replace(/\/$/, "") || "/workspace";
}

function normalizeRelativeCwd(value) {
  const raw = String(value || "/").trim().replace(/\\/g, "/");

  if (!raw || raw === "/") {
    return "";
  }

  const normalized = raw.startsWith("/") ? raw.slice(1) : raw;
  const segments = normalized.split("/").filter(Boolean);

  const safeSegments = [];
  for (const segment of segments) {
    if (segment === "." || segment === "..") {
      continue;
    }

    safeSegments.push(segment);
  }

  return safeSegments.join("/");
}

function normalizeTmpfsMount(value) {
  const raw = String(value || "").trim();
  if (!raw) {
    return "";
  }

  if (raw.includes(":")) {
    return raw;
  }

  const normalizedPath = normalizeContainerWorkdir(raw);
  return `${normalizedPath}:rw,noexec,nosuid,nodev,size=64m`;
}

function normalizeTmpfsMounts(values) {
  if (!Array.isArray(values)) {
    return [];
  }

  return values
    .map((item) => normalizeTmpfsMount(item))
    .filter(Boolean);
}

function buildContainerName(ticketPayload) {
  const projectId = Number(ticketPayload.project_id || 0);
  const sessionId = Number(ticketPayload.terminal_session_id || 0);
  const suffix = Math.random().toString(36).slice(2, 8);

  return `livecode-term-${projectId}-${sessionId}-${suffix}`;
}

function safeJsonSend(socket, payload) {
  if (!socket || socket.readyState !== 1) {
    return;
  }

  try {
    socket.send(JSON.stringify(payload));
  } catch (_error) {
    // Ignore transport write errors; socket lifecycle handles cleanup.
  }
}

function normalizeTerminalInput(data, session) {
  const value = String(data || "");
  if (!value) {
    return "";
  }

  // Browser terminals may send CRLF/CR on Enter; normalize for unix/docker PTY
  // so shells do not receive trailing "\r" inside command arguments.
  if (session && (session.runtime === "docker" || process.platform !== "win32")) {
    return value.replace(/\r\n/g, "\n").replace(/\r/g, "\n");
  }

  return value;
}

class PtyManager {
  constructor(config, logger = console) {
    this.config = {
      idleTimeoutMs: Math.max(5_000, Number(config.idleTimeoutMs || 900_000)),
      hardTimeoutMs: Math.max(30_000, Number(config.hardTimeoutMs || 28_800_000)),
      maxInputBytes: Math.max(128, Number(config.maxInputBytes || 64 * 1024)),
      maxOutputChunkBytes: Math.max(256, Number(config.maxOutputChunkBytes || 64 * 1024)),
      maxSocketBufferBytes: Math.max(16 * 1024, Number(config.maxSocketBufferBytes || 1024 * 1024)),
      cleanupIntervalMs: Math.max(1_000, Number(config.cleanupIntervalMs || 5_000)),
      defaultCols: normalizeCols(config.defaultCols || 100),
      defaultRows: normalizeRows(config.defaultRows || 30),
      env: config.env && typeof config.env === "object" ? { ...config.env } : {},
      runner: normalizeRunner(config.runner || "docker"),
      allowUnsafeHostRunner: Boolean(config.allowUnsafeHostRunner),
      dockerBinary: String(config.dockerBinary || "docker").trim() || "docker",
      dockerImage: String(config.dockerImage || "alpine:3.20").trim() || "alpine:3.20",
      dockerShell: String(config.dockerShell || "/bin/sh").trim() || "/bin/sh",
      dockerWorkdir: normalizeContainerWorkdir(config.dockerWorkdir || "/workspace"),
      dockerNetwork: String(config.dockerNetwork || "none").trim() || "none",
      dockerCpus: String(config.dockerCpus || "").trim(),
      dockerMemory: String(config.dockerMemory || "").trim(),
      dockerPidsLimit: String(config.dockerPidsLimit || "").trim(),
      dockerUser: String(config.dockerUser || "").trim(),
      dockerCapDropAll: Boolean(config.dockerCapDropAll),
      dockerNoNewPrivileges: Boolean(config.dockerNoNewPrivileges),
      dockerTmpfsMounts: normalizeTmpfsMounts(config.dockerTmpfsMounts || []),
      dockerHome: normalizeContainerWorkdir(config.dockerHome || ""),
      dockerReadOnly: Boolean(config.dockerReadOnly),
      dockerExtraArgs: Array.isArray(config.dockerExtraArgs)
        ? config.dockerExtraArgs.map((item) => String(item || "").trim()).filter(Boolean)
        : [],
      onSessionClosed: typeof config.onSessionClosed === "function" ? config.onSessionClosed : null,
    };

    if (this.config.runner === "host" && !this.config.allowUnsafeHostRunner) {
      throw new Error(
        "Host runner is disabled for security. Use docker runner or explicitly set TERMINAL_GATEWAY_ALLOW_UNSAFE_HOST_RUNNER=true in trusted single-tenant environments."
      );
    }

    this.logger = logger;
    this.sessions = new Map();
    this.socketToSessionId = new WeakMap();

    this.cleanupTimer = setInterval(() => {
      this.pruneExpiredSessions();
    }, this.config.cleanupIntervalMs);

    if (typeof this.cleanupTimer.unref === "function") {
      this.cleanupTimer.unref();
    }
  }

  attachSocket(ticketPayload, socket, dimensions = {}) {
    const sessionId = Number(ticketPayload.terminal_session_id);
    const session = this.getOrCreateSession(ticketPayload, dimensions);

    session.sockets.add(socket);
    session.lastActivityMs = Date.now();
    this.socketToSessionId.set(socket, sessionId);

    safeJsonSend(socket, {
      type: "ready",
      terminal_session_id: session.id,
      project_id: session.projectId,
      request_user_id: Number(ticketPayload.request_user_id || 0),
      shell: session.shell,
      cwd: session.cwd,
      shared: session.shared,
      runtime: session.runtime,
    });
  }

  handleMessage(socket, rawMessage) {
    const session = this.resolveSessionBySocket(socket);
    if (!session) {
      safeJsonSend(socket, {
        type: "error",
        message: "Terminal session is not attached.",
      });
      return;
    }

    let message = null;

    try {
      const value = typeof rawMessage === "string"
        ? rawMessage
        : Buffer.isBuffer(rawMessage)
          ? rawMessage.toString("utf8")
          : String(rawMessage || "");
      message = JSON.parse(value);
    } catch (_error) {
      safeJsonSend(socket, {
        type: "error",
        message: "Invalid terminal message payload.",
      });
      return;
    }

    if (!message || typeof message !== "object") {
      safeJsonSend(socket, {
        type: "error",
        message: "Invalid terminal message format.",
      });
      return;
    }

    const type = String(message.type || "").trim();

    if (type === "input") {
      const data = String(message.data || "");
      const inputBytes = Buffer.byteLength(data, "utf8");

      if (inputBytes > this.config.maxInputBytes) {
        safeJsonSend(socket, {
          type: "error",
          message: "Terminal input payload too large.",
        });
        return;
      }

      if (data) {
        const normalizedInput = normalizeTerminalInput(data, session);
        if (!normalizedInput) {
          return;
        }

        session.pty.write(normalizedInput);
        this.touchSession(session);
      }
      return;
    }

    if (type === "resize") {
      const cols = normalizeCols(message.cols, session.cols);
      const rows = normalizeRows(message.rows, session.rows);
      session.cols = cols;
      session.rows = rows;

      try {
        session.pty.resize(cols, rows);
        this.touchSession(session);
      } catch (_error) {
        safeJsonSend(socket, {
          type: "error",
          message: "Terminal resize failed.",
        });
      }
      return;
    }

    if (type === "ping") {
      safeJsonSend(socket, {
        type: "pong",
        terminal_session_id: session.id,
      });
      return;
    }

    if (type === "close") {
      this.closeSession(session.id, "closed-by-client");
      return;
    }

    safeJsonSend(socket, {
      type: "error",
      message: "Unsupported terminal message type.",
    });
  }

  detachSocket(socket) {
    const session = this.resolveSessionBySocket(socket);
    this.socketToSessionId.delete(socket);

    if (!session) {
      return;
    }

    session.sockets.delete(socket);
    session.lastActivityMs = Date.now();
  }

  closeSession(sessionId, reason = "closed", options = {}) {
    const key = Number(sessionId);
    const session = this.sessions.get(key);
    if (!session || session.closed) {
      return;
    }

    session.closed = true;
    this.sessions.delete(key);

    if (!options.skipPtyKill) {
      try {
        session.pty.kill();
      } catch (_error) {
        // Ignore PTY termination errors.
      }
    }

    for (const socket of session.sockets) {
      safeJsonSend(socket, {
        type: "closed",
        terminal_session_id: session.id,
        reason,
      });

      try {
        socket.close(1000, reason.slice(0, 100));
      } catch (_error) {
        // Ignore close errors.
      }

      this.socketToSessionId.delete(socket);
    }

    session.sockets.clear();
    this.notifySessionClosed(session, reason, options);
  }

  shutdown() {
    if (this.cleanupTimer) {
      clearInterval(this.cleanupTimer);
      this.cleanupTimer = null;
    }

    for (const sessionId of this.sessions.keys()) {
      this.closeSession(sessionId, "gateway-shutdown");
    }
  }

  getOrCreateSession(ticketPayload, dimensions) {
    const sessionId = Number(ticketPayload.terminal_session_id);

    if (this.sessions.has(sessionId)) {
      return this.sessions.get(sessionId);
    }

    const spawnSpec = this.buildSpawnSpec(ticketPayload, dimensions);

    const ptyProcess = pty.spawn(spawnSpec.command, spawnSpec.args, spawnSpec.options);

    const now = Date.now();
    const session = {
      id: sessionId,
      projectId: Number(ticketPayload.project_id || 0),
      requestUserId: Number(ticketPayload.request_user_id || 0),
      ownerUserId: Number(ticketPayload.session_user_id || 0),
      shared: Boolean(ticketPayload.shared),
      shell: spawnSpec.displayShell,
      cwd: spawnSpec.displayCwd,
      runtime: spawnSpec.runtime,
      ownerRoot: spawnSpec.ownerRoot,
      projectRoot: spawnSpec.projectRoot,
      cols: spawnSpec.cols,
      rows: spawnSpec.rows,
      createdAtMs: now,
      lastActivityMs: now,
      pty: ptyProcess,
      sockets: new Set(),
      closed: false,
    };

    ptyProcess.onData((chunk) => {
      if (session.closed) {
        return;
      }

      const outputBuffer = Buffer.isBuffer(chunk)
        ? chunk
        : Buffer.from(String(chunk || ""), "utf8");

      if (outputBuffer.length === 0) {
        return;
      }

      const maxBytes = this.config.maxOutputChunkBytes;
      let cursor = 0;

      while (cursor < outputBuffer.length) {
        // Slice buffer by byte length instead of character count
        // to prevent splitting multibyte UTF-8 sequences
        const slice = outputBuffer.slice(cursor, cursor + maxBytes);
        const data = slice.toString("utf8");

        this.broadcast(session, {
          type: "output",
          terminal_session_id: session.id,
          data: data,
        });

        cursor += maxBytes;
      }

      this.touchSession(session);
    });

    ptyProcess.onExit(({ exitCode, signal }) => {
      if (session.closed) {
        return;
      }

      const normalizedExitCode = Number.isFinite(exitCode) ? Number(exitCode) : null;
      const normalizedSignal = typeof signal === "number" ? signal : null;

      this.broadcast(session, {
        type: "exit",
        terminal_session_id: session.id,
        exit_code: normalizedExitCode,
        signal: normalizedSignal,
      });

      this.closeSession(session.id, "process-exit", {
        skipPtyKill: true,
        exitCode: normalizedExitCode,
        signal: normalizedSignal,
      });
    });

    this.sessions.set(sessionId, session);

    this.logger.info(
      `[terminal-gateway] spawned runtime=${session.runtime} session=${session.id} project=${session.projectId} cwd=${session.cwd} shell=${session.shell}`
    );

    return session;
  }

  buildSpawnSpec(ticketPayload, dimensions) {
    const ownerRoot = ensureDirectoryWithin(
      ticketPayload.owner_root || ticketPayload.project_root,
      ticketPayload.owner_root || ticketPayload.project_root,
      { strict: true, label: "owner root" }
    );
    const projectRoot = ensureDirectoryWithin(ownerRoot, ticketPayload.project_root, {
      strict: true,
      label: "project root",
    });
    const cwd = ensureDirectoryWithin(projectRoot, ticketPayload.cwd, {
      strict: false,
      label: "cwd",
    });
    const cols = normalizeCols(dimensions.cols, this.config.defaultCols);
    const rows = normalizeRows(dimensions.rows, this.config.defaultRows);

    const env = {
      ...process.env,
      ...this.config.env,
      TERM: "xterm-256color",
      COLORTERM: "truecolor",
      LIVECODE_TERMINAL_OWNER_ROOT: ownerRoot,
      LIVECODE_TERMINAL_PROJECT_ROOT: projectRoot,
    };

    if (process.platform === "win32") {
      env.USERPROFILE = projectRoot;
    } else {
      env.HOME = projectRoot;
    }

    if (this.config.runner === "docker") {
      return this.buildDockerSpawnSpec(ticketPayload, {
        ownerRoot,
        projectRoot,
        cwd,
        cols,
        rows,
        env,
      });
    }

    const shell = normalizeShell(ticketPayload.shell);

    return {
      command: shell,
      args: hostShellArgs(shell),
      options: {
        name: "xterm-256color",
        cols,
        rows,
        cwd,
        env,
        encoding: null,
        useConpty: process.platform === "win32",
      },
      runtime: "host",
      displayShell: shell,
      displayCwd: cwd,
      ownerRoot,
      projectRoot,
      cols,
      rows,
    };
  }

  buildDockerSpawnSpec(ticketPayload, state) {
    const dockerBinary = this.config.dockerBinary;
    const dockerImage = this.config.dockerImage;
    const dockerShell = this.config.dockerShell;
    const dockerWorkdir = this.config.dockerWorkdir;
    const dockerHome = this.config.dockerHome || dockerWorkdir;
    const relativeCwd = normalizeRelativeCwd(ticketPayload.cwd_relative || "/");
    const containerWorkdir = relativeCwd
      ? `${dockerWorkdir}/${relativeCwd}`.replace(/\/+/g, "/")
      : dockerWorkdir;

    const mountArg = `${state.projectRoot}:${dockerWorkdir}`;

    const args = [
      "run",
      "--rm",
      "-i",
      "-t",
      "--name",
      buildContainerName(ticketPayload),
      "--network",
      this.config.dockerNetwork,
      "-v",
      mountArg,
      "-w",
      containerWorkdir,
      "-e",
      "TERM=xterm-256color",
      "-e",
      "COLORTERM=truecolor",
      "-e",
      `HOME=${dockerHome}`,
      "-e",
      `LIVECODE_TERMINAL_PROJECT_ROOT=${dockerWorkdir}`,
    ];

    if (this.config.dockerNoNewPrivileges) {
      args.push("--security-opt", "no-new-privileges");
    }

    if (this.config.dockerCapDropAll) {
      args.push("--cap-drop", "ALL");
    }

    if (this.config.dockerUser) {
      args.push("--user", this.config.dockerUser);
    }

    if (this.config.dockerTmpfsMounts.length > 0) {
      for (const tmpfsMount of this.config.dockerTmpfsMounts) {
        args.push("--tmpfs", tmpfsMount);
      }
    }

    if (this.config.dockerReadOnly) {
      args.push("--read-only");
    }

    if (this.config.dockerCpus) {
      args.push("--cpus", this.config.dockerCpus);
    }

    if (this.config.dockerMemory) {
      args.push("--memory", this.config.dockerMemory);
    }

    if (this.config.dockerPidsLimit) {
      args.push("--pids-limit", this.config.dockerPidsLimit);
    }

    if (this.config.dockerExtraArgs.length > 0) {
      args.push(...this.config.dockerExtraArgs);
    }

    args.push(dockerImage, dockerShell);

    return {
      command: dockerBinary,
      args,
      options: {
        name: "xterm-256color",
        cols: state.cols,
        rows: state.rows,
        cwd: state.cwd,
        env: state.env,
        encoding: null,
        useConpty: process.platform === "win32",
      },
      runtime: "docker",
      displayShell: `${dockerImage}:${dockerShell}`,
      displayCwd: containerWorkdir,
      ownerRoot: state.ownerRoot,
      projectRoot: state.projectRoot,
      cols: state.cols,
      rows: state.rows,
    };
  }

  resolveSessionBySocket(socket) {
    const sessionId = Number(this.socketToSessionId.get(socket) || 0);

    if (!sessionId) {
      return null;
    }

    return this.sessions.get(sessionId) || null;
  }

  touchSession(session) {
    session.lastActivityMs = Date.now();
  }

  pruneExpiredSessions() {
    const now = Date.now();

    for (const session of this.sessions.values()) {
      const elapsedTotal = now - session.createdAtMs;
      if (elapsedTotal >= this.config.hardTimeoutMs) {
        this.closeSession(session.id, "hard-timeout");
        continue;
      }

      if (session.sockets.size > 0) {
        continue;
      }

      const inactiveFor = now - session.lastActivityMs;
      if (inactiveFor >= this.config.idleTimeoutMs) {
        this.closeSession(session.id, "idle-timeout");
      }
    }
  }

  notifySessionClosed(session, reason, options = {}) {
    if (typeof this.config.onSessionClosed !== "function") {
      return;
    }

    const payload = {
      terminal_session_id: session.id,
      project_id: session.projectId,
      request_user_id: session.requestUserId,
      session_user_id: session.ownerUserId,
      shared: session.shared,
      runtime: session.runtime,
      reason: String(reason || "closed"),
      exit_code: options.exitCode !== undefined ? options.exitCode : null,
      signal: options.signal !== undefined ? options.signal : null,
      closed_at: new Date().toISOString(),
    };

    Promise.resolve()
      .then(() => this.config.onSessionClosed(payload))
      .catch((error) => {
        const message = error instanceof Error ? error.message : String(error || "unknown error");
        this.logger.warn(`[terminal-gateway] failed to notify backend close for session=${session.id}: ${message}`);
      });
  }

  broadcast(session, payload) {
    let serialized = "";

    try {
      serialized = JSON.stringify(payload);
    } catch (_error) {
      return;
    }

    const slowSockets = [];

    for (const socket of session.sockets) {
      if (!socket || socket.readyState !== 1) {
        continue;
      }

      if (Number(socket.bufferedAmount || 0) > this.config.maxSocketBufferBytes) {
        slowSockets.push(socket);
        continue;
      }

      try {
        socket.send(serialized);
      } catch (_error) {
        // Ignore transport write errors; socket lifecycle handles cleanup.
      }
    }

    for (const socket of slowSockets) {
      session.sockets.delete(socket);
      this.socketToSessionId.delete(socket);

      try {
        socket.close(1013, "backpressure");
      } catch (_error) {
        // Ignore close errors.
      }
    }
  }
}

module.exports = {
  PtyManager,
};
