import type { AnalyticsPlatform } from "./analytics-service";

export const ANALYTICS_PLATFORM_OPTIONS: ReadonlyArray<{
  label: string;
  value: AnalyticsPlatform | "all";
}> = [
  { label: "All platforms", value: "all" },
  { label: "iOS", value: "ios" },
  { label: "Apple TV", value: "tvos" },
  { label: "Android", value: "android" },
  { label: "Samsung / LG TV", value: "tv" },
];

const labels: Record<AnalyticsPlatform, string> = {
  ios: "iOS",
  tvos: "Apple TV",
  android: "Android",
  tv: "Samsung / LG TV",
};

export function analyticsPlatformLabel(value: unknown): string {
  const key = String(value ?? "").toLowerCase() as AnalyticsPlatform;
  return labels[key] ?? String(value ?? "Unknown");
}
