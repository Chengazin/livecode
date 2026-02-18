const crypto = require("node:crypto");

function base64UrlDecode(value) {
  let normalized = String(value || "").replace(/-/g, "+").replace(/_/g, "/");

  while (normalized.length % 4 !== 0) {
    normalized += "=";
  }

  return Buffer.from(normalized, "base64").toString("utf8");
}

function base64UrlEncodeBuffer(value) {
  return Buffer.from(value)
    .toString("base64")
    .replace(/\+/g, "-")
    .replace(/\//g, "_")
    .replace(/=+$/g, "");
}

function decodePayload(encodedPayload) {
  const json = base64UrlDecode(encodedPayload);
  if (!json) {
    throw new Error("Ticket payload is empty.");
  }

  const payload = JSON.parse(json);
  if (!payload || typeof payload !== "object") {
    throw new Error("Ticket payload is invalid.");
  }

  return payload;
}

function verifySignature(encodedPayload, encodedSignature, sharedSecret) {
  const expected = crypto.createHmac("sha256", sharedSecret).update(encodedPayload).digest();
  const actualBase64 = base64UrlEncodeBuffer(expected);

  const expectedBuffer = Buffer.from(actualBase64);
  const actualBuffer = Buffer.from(String(encodedSignature || ""));

  if (expectedBuffer.length !== actualBuffer.length) {
    return false;
  }

  return crypto.timingSafeEqual(expectedBuffer, actualBuffer);
}

function validatePayload(payload, nowSeconds) {
  const terminalSessionId = Number(payload.terminal_session_id || 0);
  const projectId = Number(payload.project_id || 0);
  const requestUserId = Number(payload.request_user_id || 0);
  const sessionUserId = Number(payload.session_user_id || 0);
  const issuedAt = Number(payload.iat || 0);
  const expiresAt = Number(payload.exp || 0);

  if (!Number.isInteger(terminalSessionId) || terminalSessionId <= 0) {
    throw new Error("Ticket session id is invalid.");
  }

  if (!Number.isInteger(projectId) || projectId <= 0) {
    throw new Error("Ticket project id is invalid.");
  }

  if (!Number.isInteger(requestUserId) || requestUserId <= 0) {
    throw new Error("Ticket request user id is invalid.");
  }

  if (!Number.isInteger(sessionUserId) || sessionUserId <= 0) {
    throw new Error("Ticket session user id is invalid.");
  }

  if (!Number.isInteger(issuedAt) || !Number.isInteger(expiresAt) || expiresAt <= issuedAt) {
    throw new Error("Ticket timestamps are invalid.");
  }

  if (expiresAt < nowSeconds) {
    throw new Error("Ticket expired.");
  }

  if (!payload.project_root || typeof payload.project_root !== "string") {
    throw new Error("Ticket project root is invalid.");
  }

  if (!payload.cwd || typeof payload.cwd !== "string") {
    throw new Error("Ticket cwd is invalid.");
  }

  return {
    iss: String(payload.iss || ""),
    terminal_session_id: terminalSessionId,
    project_id: projectId,
    project_root: String(payload.project_root),
    cwd: String(payload.cwd),
    cwd_relative: String(payload.cwd_relative || "/"),
    request_user_id: requestUserId,
    session_user_id: sessionUserId,
    shared: Boolean(payload.shared),
    shell: String(payload.shell || ""),
    nonce: String(payload.nonce || ""),
    iat: issuedAt,
    exp: expiresAt,
  };
}

function verifyTicket(ticket, sharedSecret, nowSeconds = Math.floor(Date.now() / 1000)) {
  if (!sharedSecret || typeof sharedSecret !== "string") {
    throw new Error("Gateway shared secret is missing.");
  }

  const source = String(ticket || "").trim();
  const parts = source.split(".");

  if (parts.length !== 2 || !parts[0] || !parts[1]) {
    throw new Error("Ticket format is invalid.");
  }

  const [encodedPayload, encodedSignature] = parts;

  if (!verifySignature(encodedPayload, encodedSignature, sharedSecret)) {
    throw new Error("Ticket signature is invalid.");
  }

  const payload = decodePayload(encodedPayload);
  return validatePayload(payload, nowSeconds);
}

module.exports = {
  verifyTicket,
};
