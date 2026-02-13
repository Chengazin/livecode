function sanitizeSegment(value) {
  return String(value || "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 40);
}

export function getAutoDeviceName() {
  if (typeof navigator === "undefined") {
    return "web";
  }

  const isMobile = /android|iphone|ipad|ipod|mobile/i.test(navigator.userAgent || "");
  const platform = sanitizeSegment(navigator.userAgentData?.platform || navigator.platform || "");
  const parts = ["web", isMobile ? "mobile" : "desktop"];

  if (platform) {
    parts.push(platform);
  }

  return parts.join("-");
}
