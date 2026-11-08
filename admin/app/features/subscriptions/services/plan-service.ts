import "server-only";

import { ApiError, apiClient, type QueryParams } from "@/app/lib/api-client";

export type PlanDeviceType = "mobile" | "tv" | "browser";
export type PlanQuality = "SD" | "HD" | "FULL_HD" | "4K";

export type PlanFeatureItem = {
  id?: number;
  plan_id?: number | string | null;
  feature?: string | null;
  ppv_discount?: number | string | null;
  sort_order?: number | string | null;
  is_active?: boolean | number;
  created_at?: string | null;
  updated_at?: string | null;
  [key: string]: unknown;
};

export type PlanItem = {
  id?: number;
  name?: string | null;
  device_type?: PlanDeviceType | string | null;
  device_limit?: number | string | null;
  price?: number | string | null;
  duration_days?: number | string | null;
  quality?: PlanQuality | string | null;
  is_active?: boolean | number;
  features?: PlanFeatureItem[];
  created_at?: string | null;
  updated_at?: string | null;
  [key: string]: unknown;
};

export type PaginationMeta<T> = {
  data: T[];
  current_page?: number;
  first_page_url?: string | null;
  from?: number | null;
  last_page?: number;
  last_page_url?: string | null;
  links?: unknown[];
  next_page_url?: string | null;
  path?: string | null;
  per_page?: number;
  prev_page_url?: string | null;
  to?: number | null;
  total?: number;
  [key: string]: unknown;
};

export type ListPlansParams = {
  search?: string;
  device_type?: PlanDeviceType;
  is_active?: boolean;
  per_page?: number;
  page?: number;
};

export type ListPlanFeaturesParams = {
  plan_id?: number | string;
  search?: string;
  is_active?: boolean;
  per_page?: number;
  page?: number;
};

export type CreatePlanPayload = {
  name: string;
  device_type: PlanDeviceType;
  device_limit: number;
  price: number;
  duration_days: number;
  quality: PlanQuality;
  is_active?: boolean;
};

export type UpdatePlanPayload = Partial<CreatePlanPayload>;

export type CreatePlanFeaturePayload = {
  plan_id?: number | string;
  feature: string;
  ppv_discount?: number | null;
  sort_order?: number;
  is_active?: boolean;
};

export type CreateNestedPlanFeaturePayload = Omit<
  CreatePlanFeaturePayload,
  "plan_id"
>;

export type UpdatePlanFeaturePayload = Partial<CreatePlanFeaturePayload>;

export type PlanListResponse = {
  status: "success" | "error";
  data?: PaginationMeta<PlanItem>;
  message?: string;
  errors?: Record<string, string[]>;
};

export type PlanSingleResponse = {
  status: "success" | "error";
  data?: PlanItem;
  message?: string;
  errors?: Record<string, string[]>;
};

export type PlanDeleteResponse = {
  status: "success" | "error";
  message?: string;
};

export type PlanFeatureListResponse = {
  status: "success" | "error";
  data?: PaginationMeta<PlanFeatureItem>;
  message?: string;
  errors?: Record<string, string[]>;
};

export type PlanFeatureSingleResponse = {
  status: "success" | "error";
  data?: PlanFeatureItem;
  message?: string;
  errors?: Record<string, string[]>;
};

export type PlanFeaturesForPlanResponse = {
  status: "success" | "error";
  data?: {
    plan?: PlanItem;
    features?: PlanFeatureItem[];
  };
  message?: string;
};

export type PlanFeatureDeleteResponse = {
  status: "success" | "error";
  message?: string;
};

const PLANS_BASE_PATH = "/api/v4/admin/plans";
const PLAN_FEATURES_BASE_PATH = "/api/v4/admin/plan-features";

function toQueryParams(
  params?: ListPlansParams | ListPlanFeaturesParams,
): QueryParams | undefined {
  if (!params) return undefined;

  return {
    search: params.search,
    is_active: params.is_active,
    per_page: params.per_page,
    page: params.page,
    ...("device_type" in params ? { device_type: params.device_type } : {}),
    ...("plan_id" in params ? { plan_id: params.plan_id } : {}),
  };
}

export const planService = {
  async list(params?: ListPlansParams) {
    return apiClient.get<PlanListResponse>(PLANS_BASE_PATH, {
      query: toQueryParams(params),
    });
  },

  async create(payload: CreatePlanPayload) {
    return apiClient.post<PlanSingleResponse>(PLANS_BASE_PATH, payload);
  },

  async getById(id: string | number) {
    return apiClient.get<PlanSingleResponse>(`${PLANS_BASE_PATH}/${id}`);
  },

  async update(id: string | number, payload: UpdatePlanPayload) {
    return apiClient.put<PlanSingleResponse>(
      `${PLANS_BASE_PATH}/${id}`,
      payload,
    );
  },

  async remove(id: string | number) {
    return apiClient.delete<PlanDeleteResponse>(`${PLANS_BASE_PATH}/${id}`);
  },

  async listFeatures(params?: ListPlanFeaturesParams) {
    return apiClient.get<PlanFeatureListResponse>(PLAN_FEATURES_BASE_PATH, {
      query: toQueryParams(params),
    });
  },

  async createFeature(payload: CreatePlanFeaturePayload) {
    return apiClient.post<PlanFeatureSingleResponse>(
      PLAN_FEATURES_BASE_PATH,
      payload,
    );
  },

  async getFeatureById(featureId: string | number) {
    return apiClient.get<PlanFeatureSingleResponse>(
      `${PLAN_FEATURES_BASE_PATH}/${featureId}`,
    );
  },

  async updateFeature(
    featureId: string | number,
    payload: UpdatePlanFeaturePayload,
  ) {
    return apiClient.put<PlanFeatureSingleResponse>(
      `${PLAN_FEATURES_BASE_PATH}/${featureId}`,
      payload,
    );
  },

  async removeFeature(featureId: string | number) {
    return apiClient.delete<PlanFeatureDeleteResponse>(
      `${PLAN_FEATURES_BASE_PATH}/${featureId}`,
    );
  },

  async listFeaturesForPlan(planId: string | number) {
    return apiClient.get<PlanFeaturesForPlanResponse>(
      `${PLANS_BASE_PATH}/${planId}/features`,
    );
  },

  async createFeatureForPlan(
    planId: string | number,
    payload: CreateNestedPlanFeaturePayload,
  ) {
    return apiClient.post<PlanFeatureSingleResponse>(
      `${PLANS_BASE_PATH}/${planId}/features`,
      payload,
    );
  },
};

export { ApiError };
