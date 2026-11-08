import "server-only";

import { ApiError, apiClient } from "@/app/lib/api-client";

export type RefreshTokenPayload = {
  refresh_token: string;
};

export type RefreshTokenResponse = {
  status: "success" | "error";
  message: string;
  access_token?: string;
  refresh_token?: string;
  access_expires_at?: string;
  refresh_expires_at?: string;
  token_type?: "bearer" | string;
};

export type RevokeTokenPayload = {
  access_token: string;
};

export type RevokeTokenResponse = {
  status: "success" | "error";
  message: string;
};

const TOKEN_BASE_PATH = "/api/v4/auth/tokens";

export const tokenService = {
  async refresh(payload: RefreshTokenPayload) {
    return apiClient.post<RefreshTokenResponse>(
      `${TOKEN_BASE_PATH}/refresh`,
      payload,
    );
  },

  async revoke(payload: RevokeTokenPayload) {
    return apiClient.post<RevokeTokenResponse>("/api/v4/auth/logout", payload);
  },
};

export { ApiError };
