import "server-only";

import { apiClient, type QueryParams } from "@/app/lib/api-client";

export type AnalyticsPlatform = "ios" | "tvos" | "android" | "tv";

export type AnalyticsFilters = {
  from: string;
  to: string;
  platform?: AnalyticsPlatform;
  timezone?: string;
  app_version?: string;
  content_type?: string;
  user_id?: string;
};

export type AnalyticsOverview = {
  playback_starts?: number;
  valid_views?: number;
  unique_viewers?: number;
  watch_hours?: number;
  data_transferred_bytes?: number;
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
  parent_title?: string | null;
  display_title?: string;
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
  engagement?: Record<string, number>;
  watch_trend?: AnalyticsTrendPoint[];
  streaming_health?: AnalyticsHealthPoint[];
  platforms?: AnalyticsPlatformStat[];
  top_content?: AnalyticsContentStat[];
  product_events?: AnalyticsEventStat[];
};

export type AnalyticsPresence = {
  available: boolean;
  online_users: number;
  online_devices: number;
  heartbeat_interval_seconds: number;
  presence_ttl_seconds: number;
  as_of: string;
  platforms: Array<{ platform: string; users: number; devices: number }>;
  devices: Array<Record<string, string>>;
  devices_truncated: boolean;
};

function toQuery(filters: AnalyticsFilters): QueryParams {
  return {
    from: filters.from,
    to: filters.to,
    platform: filters.platform,
    app_version: filters.app_version,
    content_type: filters.content_type,
    user_id: filters.user_id,
    timezone: filters.timezone ?? "Asia/Kolkata",
  };
}

export type ReportRow = Record<string, unknown>;
export type ReportPage = { data: ReportRow[]; current_page: number; last_page: number; total: number };
export type ReportTab = "content" | "sessions" | "quality" | "errors" | "events";

export type InsightsReport = {
  dimension: string;
  summary: Record<string, number | null>;
  groups: ReportPage;
};

export const analyticsService = {
  async getInsights(filters: AnalyticsFilters, dimension: string, page: number) {
    return apiClient.get<InsightsReport>("/api/v4/analytic/reports/insights", {
      query: { ...toQuery(filters), dimension, page, per_page: 25 },
      cache: "no-store",
    });
  },
  async getReport(tab: ReportTab | "errors/events", filters: AnalyticsFilters, query: QueryParams = {}) {
    return apiClient.get<ReportPage | ReportRow[]>(`/api/v4/analytic/reports/${tab}`, {
      query: { ...toQuery(filters), ...query },
      cache: "no-store",
    });
  },
  async getDashboard(filters: AnalyticsFilters) {
    return apiClient.get<AnalyticsDashboardData>(
      "/api/v4/analytic/reports/overview",
      { query: toQuery(filters), cache: "no-store" },
    );
  },
  async getPresence(filters: AnalyticsFilters) {
    return apiClient.get<AnalyticsPresence>(
      "/api/v4/analytic/reports/presence",
      {
        query: {
          platform: filters.platform,
          app_version: filters.app_version,
          user_id: filters.user_id,
        },
        cache: "no-store",
      },
    );
  },
};
