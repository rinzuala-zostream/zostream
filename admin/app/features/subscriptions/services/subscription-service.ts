import "server-only";

import { ApiError, apiClient, type QueryParams } from "@/app/lib/api-client";
import type { PlanDeviceType, PlanItem } from "./plan-service";

export type SubscriptionDeviceType = PlanDeviceType;
export type SubscriptionPaymentType = "new" | "renew" | "upgrade" | "downgrade";
export type SubscriptionPaymentStatus =
  | "pending"
  | "success"
  | "failed"
  | "refunded";

export type SubscriptionPlanSummary = {
  plan_id?: number;
  plan?: string | null;
  original_price?: number | string | null;
  duration_days?: number | string | null;
  per_device_price?: Partial<
    Record<"Mobile" | "Tv" | "TV" | "Browser", number>
  >;
  per_device_features?: Partial<
    Record<"Mobile" | "Tv" | "TV" | "Browser", string[]>
  >;
  [key: string]: unknown;
};

export type SubscriptionDeviceItem = {
  id?: number | string;
  subscription_id?: number | string | null;
  user_id?: string | null;
  device_id?: string | null;
  device_name?: string | null;
  device_type?: string | null;
  device_token?: string | null;
  is_owner_device?: boolean | number;
  shared_to_user_id?: string | null;
  last_activity?: string | null;
  status?: string | null;
  is_active?: boolean | number;
  created_at?: string | null;
  updated_at?: string | null;
  [key: string]: unknown;
};

export type ActiveStreamItem = {
  id?: number | string;
  subscription_id?: number | string | null;
  device_id?: string | null;
  started_at?: string | null;
  last_ping_at?: string | null;
  [key: string]: unknown;
};

export type SubscriptionItem = {
  id?: number | string;
  user_id?: string | null;
  plan_id?: number | string | null;
  plan?: PlanItem | null;
  user?: {
    num?: number | string;
    uid?: string | null;
    name?: string | null;
    mail?: string | null;
    call?: string | null;
    auth_phone?: string | null;
  } | null;
  devices?: SubscriptionDeviceItem[];
  active_streams?: ActiveStreamItem[];
  activeStreams?: ActiveStreamItem[];
  start_at?: string | null;
  end_at?: string | null;
  is_active?: boolean | number;
  renewed_by?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
  current_date?: string;
  [key: string]: unknown;
};

export type PaymentHistoryMeta = {
  device_id?: number | string | null;
  device_token?: string | null;
  device_name?: string | null;
  [key: string]: unknown;
};

export type PaymentHistoryItem = {
  id?: number | string;
  subscription_id?: number | string | null;
  user_id?: string | null;
  plan_id?: number | string | null;
  movie_id?: string | null;
  device_type?: string | null;
  app_payment_type?: string | null;
  amount?: number | string | null;
  currency?: string | null;
  payment_method?: string | null;
  payment_gateway?: string | null;
  transaction_id?: string | null;
  status?: SubscriptionPaymentStatus | string | null;
  payment_type?: SubscriptionPaymentType | string | null;
  payment_date?: string | null;
  expiry_date?: string | null;
  meta?: PaymentHistoryMeta | null;
  created_at?: string | null;
  updated_at?: string | null;
  [key: string]: unknown;
};

export type SubscriptionRenewResponse = {
  status?: "success" | "error" | string;
  title?: string;
  message?: string;
  device_type?: SubscriptionDeviceType | string;
  owner_device?: Array<number | string>;
  blocked_devices?: Array<number | string>;
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

export type ListUserSubscriptionsParams = {
  device_type?: SubscriptionDeviceType;
  per_page?: number;
};

export type ListSubscriptionsParams = {
  page?: number;
  per_page?: number;
  search?: string;
  device_type?: SubscriptionDeviceType;
  is_active?: boolean;
  sort_by?:
    | "id"
    | "user_id"
    | "plan_id"
    | "start_at"
    | "end_at"
    | "created_at";
  sort_direction?: "asc" | "desc";
};

export type StoreSubscriptionPayload = {
  user_id: string;
  plan_id?: number | string | null;
  amount?: number | null;
  device_type?: SubscriptionDeviceType | string | null;
  app_payment_type?: string | null;
  payment_method?: string | null;
  payment_gateway?: string | null;
  transaction_id?: string | null;
  currency?: string | null;
  movie_id?: string | null;
};

type CreateSubscriptionWithPaymentBasePayload = {
  /**
   * Backend accepts either the user's UID or auth_phone and resolves it to UID.
   */
  user_id: string;
  amount?: number | null;
  currency?: string | null;
  payment_method?: string | null;
  payment_gateway?: string | null;
  transaction_id?: string | null;
  payment_type?: SubscriptionPaymentType | string | null;
  status?: SubscriptionPaymentStatus | string | null;
  start_at?: string | null;
  end_at?: string | null;
};

export type CreateSubscriptionWithPaymentPayload =
  CreateSubscriptionWithPaymentBasePayload &
    (
      | {
          plan_id: number | string;
          selected_plan_id?: number | string | null;
        }
      | {
          selected_plan_id: number | string;
          plan_id?: number | string | null;
        }
    );

export type UpdateSubscriptionPayload = {
  plan_id?: number | string | null;
  start_at?: string | null;
  end_at?: string | null;
  is_active?: boolean;
  renewed_by?: string | null;
};

export type SubscriptionPlanListResponse = {
  current_date?: string;
  status: "success" | "error";
  data?: SubscriptionPlanSummary[];
  message?: string;
};

export type SubscriptionSingleResponse = {
  current_date?: string;
  status: "success" | "error";
  data?: SubscriptionItem;
  message?: string;
  error?: string;
};

export type SubscriptionListResponse = {
  current_date?: string;
  status: "success" | "error";
  data?: PaginationMeta<SubscriptionItem>;
  message?: string;
  error?: string;
};

export type SubscriptionIndexResponse = {
  current_date?: string;
  status: "success" | "error";
  data?: PaginationMeta<SubscriptionItem> | SubscriptionItem[];
  message?: string;
  error?: string;
};

export type UserDeviceSubscriptionResponse =
  | (SubscriptionItem & { current_date?: string })
  | {
      current_date?: string;
      status: "error";
      message?: string;
      error?: string;
    };

export type StoreSubscriptionResponse = {
  current_date?: string;
  status: "success" | "error";
  message?: string;
  data?:
    | PaymentHistoryItem
    | {
        user_id?: string;
        plan_id?: number | string;
        start_at?: string;
        end_at?: string;
        is_active?: boolean;
        transaction_id?: string;
        [key: string]: unknown;
      };
  error?: string;
};

export type CreateSubscriptionWithPaymentData = {
  requested_user_id?: string;
  resolved_user_id?: string;
  subscription?: SubscriptionItem;
  payment_history?: PaymentHistoryItem;
  device?: SubscriptionDeviceItem;
  renew_response?: SubscriptionRenewResponse;
  [key: string]: unknown;
};

export type CreateSubscriptionWithPaymentResponse = {
  current_date?: string;
  status: "success" | "error";
  message?: string;
  data?: CreateSubscriptionWithPaymentData;
  error?: string;
};

export type SubscriptionDeleteResponse = {
  current_date?: string;
  status: "success" | "error";
  message?: string;
  error?: string;
};

const SUBSCRIPTIONS_BASE_PATH = "/api/v4/admin/subscriptions";

function toUserSubscriptionQueryParams(
  params?: ListUserSubscriptionsParams,
): QueryParams | undefined {
  if (!params) return undefined;

  return {
    device_type: params.device_type,
    per_page: params.per_page,
  };
}

function toSubscriptionListQueryParams(
  params?: ListSubscriptionsParams,
): QueryParams | undefined {
  if (!params) return undefined;

  return {
    page: params.page,
    per_page: params.per_page,
    search: params.search,
    device_type: params.device_type,
    is_active: params.is_active,
    sort_by: params.sort_by,
    sort_direction: params.sort_direction,
  };
}

export const subscriptionService = {
  async list(params?: ListSubscriptionsParams) {
    return apiClient.get<SubscriptionIndexResponse>(SUBSCRIPTIONS_BASE_PATH, {
      query: toSubscriptionListQueryParams(params),
    });
  },

  async search(params: ListSubscriptionsParams & { search: string }) {
    return apiClient.get<SubscriptionIndexResponse>(
      `${SUBSCRIPTIONS_BASE_PATH}/search`,
      { query: toSubscriptionListQueryParams(params) },
    );
  },

  async listPlans() {
    return apiClient.get<SubscriptionPlanListResponse>("/api/v4/admin/plans");
  },

  async listPlansByDevice(deviceType: SubscriptionDeviceType) {
    return apiClient.get<SubscriptionPlanListResponse>(
      `/api/v4/billing/plans/device/${deviceType}`,
    );
  },

  async getById(id: string | number) {
    return apiClient.get<SubscriptionSingleResponse>(
      `${SUBSCRIPTIONS_BASE_PATH}/${id}`,
    );
  },

  async getByUser(userId: string | number, params?: ListUserSubscriptionsParams) {
    const query = toUserSubscriptionQueryParams(params);

    if (params?.device_type) {
      return apiClient.get<UserDeviceSubscriptionResponse>(
        `${SUBSCRIPTIONS_BASE_PATH}/user/${userId}`,
        { query },
      );
    }

    return apiClient.get<SubscriptionListResponse>(
      `${SUBSCRIPTIONS_BASE_PATH}/user/${userId}`,
      { query },
    );
  },

  async create(payload: StoreSubscriptionPayload) {
    return apiClient.post<StoreSubscriptionResponse>(
      SUBSCRIPTIONS_BASE_PATH,
      payload,
    );
  },

  async createWithPayment(payload: CreateSubscriptionWithPaymentPayload) {
    return apiClient.post<CreateSubscriptionWithPaymentResponse>(
      `${SUBSCRIPTIONS_BASE_PATH}/with-payment`,
      payload,
    );
  },

  async update(id: string | number, payload: UpdateSubscriptionPayload) {
    return apiClient.put<SubscriptionSingleResponse>(
      `${SUBSCRIPTIONS_BASE_PATH}/${id}`,
      payload,
    );
  },

  async remove(id: string | number) {
    return apiClient.delete<SubscriptionDeleteResponse>(
      `${SUBSCRIPTIONS_BASE_PATH}/${id}`,
    );
  },
};

export { ApiError };
