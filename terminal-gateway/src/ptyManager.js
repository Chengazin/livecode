const fs = require("node:fs");
const path = require("node:path");
const pty = require("node-pty");

function resolveDirectory(fallback, candidate) {
  const base = path.resolve(String(fallback || process.cwd()));
  const target = path.resolve(String(candidate || base));

  const relative = path.relative(base, target);
  const isInside = relative === "" || (!relative.startsWith("..") && !path.isAbsolute(relative));

  if (!isInside) {
    return base;
  }

  if (!fs.existsSync(target) || !fs.statSync(target).isDirectory()) {
    return base;
  }

  return target;
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
  const normalized = String(value || "host").trim().toLowerCase();
  return normalized === "docker" ? "docker" : "host";
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
      runner: normalizeRunner(config.runner || "host"),
      dockerBinary: String(config.dockerBinary || "docker").trim() || "docker",
      dockerImage: String(config.dockerImage || "alpine:3.20").trim() || "alpine:3.20",
      dockerShell: String(config.dockerShell || "/bin/sh").trim() || "/bin/sh",
      dockerWorkdir: normalizeContainerWorkdir(config.dockerWorkdir || "/workspace"),
      dockerNetwork: String(config.dockerNetwork || "none").trim() || "none",
      dockerCpus: String(config.dockerCpus || "").trim(),
      dockerMemory: String(config.dockerMemory || "").trim(),
      dockerPidsLimit: String(config.dockerPidsLimit || "").trim(),
      dockerReadOnly: Boolean(config.dockerReadOnly),
      dockerExtraArgs: Array.isArray(config.dockerExtraArgs)
        ? config.dockerExtraArgs.map((item) => String(item || "").trim()).filter(Boolean)
        : [],
      onSessionClosed: typeof config.onSessionClosed === "function" ? config.onSessionClosed : null,
    };

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
        session.pty.write(data);
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

      const output = Buffer.isBuffer(chunk) ? chunk.toString("utf8") : String(chunk || "");
      if (!output) {
        return;
      }

      const maxBytes = this.config.maxOutputChunkBytes;
      let cursor = 0;

      while (cursor < output.length) {
        const slice = output.slice(cursor, cursor + maxBytes);
        this.broadcast(session, {
          type: "output",
          terminal_session_id: session.id,
          data: slice,
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
    const projectRoot = resolveDirectory(ticketPayload.project_root, ticketPayload.project_root);
    const cwd = resolveDirectory(projectRoot, ticketPayload.cwd);
    const cols = normalizeCols(dimensions.cols, this.config.defaultCols);
    const rows = normalizeRows(dimensions.rows, this.config.defaultRows);

    const env = {
      ...process.env,
      ...this.config.env,
      TERM: "xterm-256color",
      COLORTERM: "truecolor",
    };

    if (this.config.runner === "docker") {
      return this.buildDockerSpawnSpec(ticketPayload, {
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
    const relativeCwd = normalizeRelativeCwd(ticketPayload.cwd_relative || "/");
    const containerWorkdir = relativeCwd
      ? `${dockerWorkdir}/${relativeCwd}`.replace(/\/+/g, "/")
      : dockerWorkdir;

    const mountArg = `${state.projectRoot}:${dockerWorkdir}`;

    const args = [
      "run",
      "--rm",
      "-i",
      "--name",
      buildContainerName(ticketPayload),
      "--network",
      this.config.dockerNetwork,
      "-v",
      mountArg,
      "-w",
      containerWorkdir,
    ];

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
