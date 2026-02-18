import { request } from "./api";

export async function listTerminalSessions(projectId) {
  return request({
    method: "GET",
    path: `/projects/${projectId}/terminal/sessions`,
    auth: true,
  });
}

export async function createTerminalSession(projectId, payload = {}) {
  return request({
    method: "POST",
    path: `/projects/${projectId}/terminal/sessions`,
    auth: true,
    body: payload,
  });
}

export async function closeTerminalSession(projectId, terminalSessionId) {
  return request({
    method: "POST",
    path: `/projects/${projectId}/terminal/sessions/${terminalSessionId}/close`,
    auth: true,
    body: {},
  });
}

export async function issueTerminalTicket(projectId, terminalSessionId) {
  return request({
    method: "POST",
    path: `/projects/${projectId}/terminal/sessions/${terminalSessionId}/ticket`,
    auth: true,
    body: {},
  });
}
