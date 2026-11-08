type DeviceType = "mobile" | "tv" | "browser";

export type DeviceInfo = {
  visitorId: string;
  deviceType: DeviceType;
  deviceName: string;
  browserName: string;
  browserVersion: string;
  osName: string;
  osVersion: string;
  model: string;
};

function fallback<T extends string>(
  value: string | undefined,
  fallbackValue: T,
): string | T {
  return value?.trim() ? value : fallbackValue;
}

export function parseUserAgent(
  userAgent: string,
): Omit<DeviceInfo, "visitorId" | "deviceName"> {
  const ua = userAgent.toLowerCase();

  const isIphone = /iphone/.test(ua);
  const isIpad = /ipad/.test(ua);
  const isAndroid = /android/.test(ua);
  const isTv =
    /smart-tv|smarttv|hbbtv|appletv|googletv|android tv|bravia|tizen|webos/.test(
      ua,
    );
  const isMobile = /mobile/.test(ua) || isIphone || isAndroid;

  const deviceType: DeviceType = isTv
    ? "tv"
    : isMobile || isIpad
      ? "mobile"
      : "browser";

  let osName = "Unknown OS";
  let osVersion = "";
  if (/windows nt/.test(ua)) {
    osName = "Windows";
    const match = userAgent.match(/Windows NT ([0-9.]+)/i);
    osVersion = match?.[1] ?? "";
  } else if (/android/.test(ua)) {
    osName = "Android";
    const match = userAgent.match(/Android ([0-9.]+)/i);
    osVersion = match?.[1] ?? "";
  } else if (/iphone|ipad|ipod/.test(ua)) {
    osName = "iOS";
    const match = userAgent.match(/OS ([0-9_]+)/i);
    osVersion = match?.[1]?.replace(/_/g, ".") ?? "";
  } else if (/mac os x/.test(ua)) {
    osName = "macOS";
    const match = userAgent.match(/Mac OS X ([0-9_]+)/i);
    osVersion = match?.[1]?.replace(/_/g, ".") ?? "";
  } else if (/linux/.test(ua)) {
    osName = "Linux";
  }

  let browserName = "Unknown Browser";
  let browserVersion = "";
  const browserMatchers: Array<{ name: string; regex: RegExp }> = [
    { name: "Edge", regex: /Edg\/([0-9.]+)/i },
    { name: "Chrome", regex: /Chrome\/([0-9.]+)/i },
    { name: "Firefox", regex: /Firefox\/([0-9.]+)/i },
    { name: "Safari", regex: /Version\/([0-9.]+).*Safari/i },
    { name: "Opera", regex: /OPR\/([0-9.]+)/i },
  ];
  for (const matcher of browserMatchers) {
    const match = userAgent.match(matcher.regex);
    if (match) {
      browserName = matcher.name;
      browserVersion = match[1] ?? "";
      break;
    }
  }

  let model = "";
  if (isIphone) model = "iPhone";
  else if (isIpad) model = "iPad";
  else if (isAndroid) model = "Android Device";
  else if (isTv) model = "Smart TV";

  return {
    deviceType,
    browserName,
    browserVersion: fallback(browserVersion, ""),
    osName,
    osVersion: fallback(osVersion, ""),
    model: fallback(model, ""),
  };
}

export function buildDeviceName(
  info: Omit<DeviceInfo, "visitorId" | "deviceName">,
): string {
  const modelPart = info.model || info.deviceType.toUpperCase();
  const browserPart = info.browserVersion
    ? `${info.browserName} ${info.browserVersion.split(".")[0]}`
    : info.browserName;
  return `${modelPart} • ${browserPart}`;
}
