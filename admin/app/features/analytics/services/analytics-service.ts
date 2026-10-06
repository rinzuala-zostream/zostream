import "server-only";

import { apiClient, type QueryParams } from "@/app/lib/api-client";

export type AnalyticsPlatform = "ios" | "tvos" | "android" | "tv";

export type AnalyticsFilters = {
  from: string;
  to: string;
  platform?: AnalyticsPlatform;
  timezone?: string;
};

export type AnalyticsOverview = {
  playback_starts?: number;
  valid_views?: number;
  unique_viewers?: number;
  watch_hours?: number;
  average_watch_minutes?: number;
  completion_rate?: number;
  playback_error_rate?: number;
  average_startup_ms?: number;
  rebuffer_ratio?: number;
  app_sessions?: number;
};

export type AnalyticsTrendPoint = {
  date: string;
  watch_hours?: number;
  unique_viewers?: number;
  playback_starts?: number;
};

export type AnalyticsHealthPoint = {
  date: string;
  average_startup_ms?: number;
  rebuffer_rate?: number;
  playback_success_rate?: number;
};

export type AnalyticsPlatformStat = {
  platform: string;
  sessions?: number;
  viewers?: number;
  percentage?: number;
};

export type AnalyticsContentStat = {
  content_id: string;
  title?: string;
  content_type?: string;
  views?: number;
  watch_hours?: number;
  completion_rate?: number;
};

export type AnalyticsEventStat = {
  name: string;
  count?: number;
  unique_users?: number;
};

export type AnalyticsDashboardData = AnalyticsOverview & {
  // The nested shape lets the overview endpoint grow into one dashboard
  // response while remaining compatible with the flat v1 report contract.
  overview?: AnalyticsOverview;
  watch_trend?: AnalyticsTrendPoint[];
  streaming_health?: AnalyticsHealthPoint[];
  platforms?: AnalyticsPlatformStat[];
  top_content?: AnalyticsContentStat[];
  product_events?: AnalyticsEventStat[];
};

function toQuery(filters: AnalyticsFilters): QueryParams {
  return {
    from: filters.from,
    to: filters.to,
    platform: filters.platform,
    timezone: filters.timezone ?? "Asia/Kolkata",
  };
}

export const analyticsService = {
  async getDashboard(filters: AnalyticsFilters) {
    return apiClient.get<AnalyticsDashboardData>(
      "/api/v4/analytic/reports/overview",
      { query: toQuery(filters) },
    );
  },
};
