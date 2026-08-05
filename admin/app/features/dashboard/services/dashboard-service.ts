import "server-only";

import { apiClient, type QueryParams } from "@/app/lib/api-client";

export type DashboardPeriod = "daily" | "monthly" | "yearly" | "custom";
export type DashboardDeviceType = "mobile" | "browser" | "tv";
export type DashboardDateField = "created_at" | "start_at" | "end_at";

export type DashboardFilters = {
  period?: DashboardPeriod;
  date?: string;
  month?: string;
  year?: number;
  start_date?: string;
  end_date?: string;
  device_type?: DashboardDeviceType;
  date_field?: DashboardDateField;
};

export type DashboardOverview = {
  total_active_users?: number;
  total_users_with_active_subscription?: number;
  total_active_subscriptions?: number;
  total_movies?: number;
  total_episodes?: number;
  total_seasons?: number;
};

export type DashboardPlanStat = {
  plan_id?: number;
  plan_name?: string | null;
  device_type?: string | null;
  duration_days?: number;
  plan_price?: number;
  total_active_subscriptions?: number;
  total_amount?: number;
};

export type DashboardDeviceStat = {
  device_type?: string | null;
  total_active_subscriptions?: number;
  total_amount?: number;
};

export type DashboardPlanAmountSummary = {
  total_active_subscriptions_in_range?: number;
  total_amount?: number;
};

export type DashboardContent = {
  total_movies?: number;
  total_episodes?: number;
  total_seasons?: number;
  total_active_plans?: number;
  movies_by_category?: Record<string, number>;
};

export type DashboardData = {
  overview?: DashboardOverview;
  active_subscriptions_by_plan?: DashboardPlanStat[];
  active_subscriptions_by_device?: DashboardDeviceStat[];
  plan_amount_summary?: DashboardPlanAmountSummary;
  content?: DashboardContent;
};

export type DashboardResponse = {
  status: "success" | "error";
  message?: string;
  error?: string;
  filters?: {
    period?: string;
    device_type?: string;
    date_field?: string;
    start_date?: string | null;
    end_date?: string | null;
  };
  data?: DashboardData;
};

function toQueryParams(filters?: DashboardFilters): QueryParams | undefined {
  if (!filters) return undefined;

  return {
    period: filters.period,
    date: filters.date,
    month: filters.month,
    year: filters.year,
    start_date: filters.start_date,
    end_date: filters.end_date,
    device_type: filters.device_type,
    date_field: filters.date_field,
  };
}

export const dashboardService = {
  async getOverview(filters?: DashboardFilters) {
    return apiClient.get<DashboardResponse>("/api/v4/admin/dashboard", {
      query: toQueryParams(filters),
    });
  },
};
