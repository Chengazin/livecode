const { URL } = require("node:url");

function normalizeHeaderName(value) {
  const name = String(value || "X-Terminal-Gateway-Secret").trim();
  return name || "X-Terminal-Gateway-Secret";
}

function resolveCallbackUrl(urlTemplate, terminalSessionId) {
  const template = String(urlTemplate || "").trim();

  if (!template) {
    return "";
  }

  if (!template.includes("{terminalSessionId}")) {
    return "";
  }

  return template.replace("{terminalSessionId}", String(terminalSessionId));
}

function createBackendReporter(config, logger = console) {
  const urlTemplate = String(config.urlTemplate || "").trim();
  const secret = String(config.secret || "").trim();
  const headerName = normalizeHeaderName(config.headerName);
  const timeoutMs = Math.max(500, Number(config.timeoutMs || 5000));

  const enabled = Boolean(urlTemplate && secret && urlTemplate.includes("{terminalSessionId}"));

  async function reportSessionClosed(payload) {
    if (!enabled) {
      return;
    }

    const terminalSessionId = Number(payload?.terminal_session_id || 0);
    if (!terminalSessionId) {
      return;
    }

    const callbackUrl = resolveCallbackUrl(urlTemplate, terminalSessionId);
    if (!callbackUrl) {
      return;
    }

    let parsedUrl = null;
    try {
      parsedUrl = new URL(callbackUrl);
    } catch (_error) {
      logger.warn(`[terminal-gateway] callback URL is invalid: ${callbackUrl}`);
      return;
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);

    try {
      const response = await fetch(parsedUrl.toString(), {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          [headerName]: secret,
        },
        body: JSON.stringify({
          status: "closed",
          reason: payload.reason || "closed",
          runtime: payload.runtime || "host",
          exit_code: payload.exit_code ?? null,
          signal: payload.signal ?? null,
          request_user_id: payload.request_user_id ?? null,
          closed_at: payload.closed_at || new Date().toISOString(),
        }),
        signal: controller.signal,
      });

      if (!response.ok) {
        let body = "";

        try {
          body = await response.text();
        } catch (_readError) {
          body = "";
        }

        const shortBody = body.length > 240 ? `${body.slice(0, 240)}...` : body;
        logger.warn(
          `[terminal-gateway] backend callback failed session=${terminalSessionId} status=${response.status} body=${shortBody}`
        );
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error || "unknown error");
      logger.warn(`[terminal-gateway] backend callback error session=${terminalSessionId}: ${message}`);
    } finally {
      clearTimeout(timer);
    }
  }

  return {
    enabled,
    reportSessionClosed,
  };
}

module.exports = {
  createBackendReporter,
};
