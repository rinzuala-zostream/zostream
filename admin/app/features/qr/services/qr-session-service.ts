import "server-only";

import { ApiError, apiClient, type QueryParams } from "@/app/lib/api-client";

export type QrSessionType = "login" | "admin_login" | "payment";
export type QrSessionStatus =
  | "initialized"
  | "pending"
  | "payment_started"
  | "completed"
  | "failed"
  | "expired"
  | "unknown";

export type DeviceType = "mobile" | "tv" | "browser" | "web" | string;

export type CreateQrSessionPayload = {
  device_id?: string;
  movie_id?: string | number;
  movie_name?: string;
  device_name?: string;
  device_type?: DeviceType;
  amount?: number;
  currency?: string;
  plan_id?: string | number;
  app_payment_type?: string;
  payment_method?: string;
  payment_gateway?: string;
  transaction_id?: string;
  note?: string;
  type?: QrSessionType;
  user_id?: string;
};

export type QrSessionData = CreateQrSessionPayload & {
  status?: QrSessionStatus | string;
  expires_at?: number;
  order_id?: string;
  response?: {
    status?: "success" | "error";
    message?: string;
    data?: Record<string, unknown>;
  };
  [key: string]: unknown;
};

export type CreateQrSessionResponse = {
  status: "success";
  token: string;
  qr_url: string;
  expires_in: number;
  message?: string;
};

export type GetQrSessionStatusParams = {
  user_id?: string;
};

export type GetQrSessionStatusResponse = {
  status: "success" | "error";
  message?: string;
  session_status?: QrSessionStatus | string;
  data?: QrSessionData;
};

export type VerifyQrSessionPayload = {
  token: string;
  user_id: string;
};

export type VerifyQrSessionResponse = {
  status: "success" | "error";
  message?: string;
  type?: QrSessionType;
  data?: Record<string, unknown>;
  error?: unknown;
};

const QR_BASE_PATH = "/api/v4/qr-sessions";
export type CreateAdminQrSessionPayload = Omit<CreateQrSessionPayload, "type">;

function toStatusQuery(params: GetQrSessionStatusParams = {}): QueryParams {
  return {
    user_id: params.user_id,
  };
}

export async function createAdminQrSession(
  payload: CreateAdminQrSessionPayload,
) {
  return apiClient.post<CreateQrSessionResponse>(
    "/api/v4/admin/qr-sessions",
    payload,
  );
}

export const qrSessionService = {
  async create(payload: CreateQrSessionPayload) {
    return apiClient.post<CreateQrSessionResponse>(
      QR_BASE_PATH,
      payload,
    );
  },

  createAdmin: createAdminQrSession,

  async status(token: string, params: GetQrSessionStatusParams = {}) {
    return apiClient.get<GetQrSessionStatusResponse>(
      `${QR_BASE_PATH}/${encodeURIComponent(token)}/status`,
      {
        query: toStatusQuery(params),
      },
    );
  },

  async verify(payload: VerifyQrSessionPayload) {
    return apiClient.post<VerifyQrSessionResponse>(
      `${QR_BASE_PATH}/${encodeURIComponent(payload.token)}/verify`,
      { user_id: payload.user_id },
    );
  },
};

export { ApiError };
