import "server-only";

import { ApiError, apiClient } from "@/app/lib/api-client";

export type RequestOtpPayload = {
  phone_number: string;
  country_code?: string;
  user_id?: string;
};

export type RequestOtpResponse = {
  status: "success" | "error";
  message: string;
  user_id?: string;
  WhatsApp_Status?: "sent" | "failed" | "skipped" | null;
  otp?: string | null;
};

export type VerifyOtpPayload = {
  user_id: string;
  otp: string;
  device_name?: string;
  device_id?: string;
  device_type?: string;
};

export type VerifyOtpResponse = {
  status: "success" | "error";
  message: string;
  data?: Record<string, unknown>;
};

const OTP_BASE_PATH = "/api/v4/auth/otp";
const ADMIN_OTP_PATH = "/api/v4/auth/admin/otp/request";

export const whatsappOtpService = {
  async requestOtp(payload: RequestOtpPayload) {
    return apiClient.post<RequestOtpResponse>(`${OTP_BASE_PATH}/request`, payload);
  },

  async requestAdminOtp(payload: RequestOtpPayload) {
    return apiClient.post<RequestOtpResponse>(
      ADMIN_OTP_PATH,
      payload,
    );
  },

  async verifyOtp(payload: VerifyOtpPayload) {
    return apiClient.post<VerifyOtpResponse>(`${OTP_BASE_PATH}/verify`, payload);
  },
};

export { ApiError };
