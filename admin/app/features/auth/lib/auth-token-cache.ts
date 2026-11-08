export const AUTH_TOKEN_CACHE_KEY = "zostream_admin_auth_token_cache_data";

type RecordValue = Record<string, unknown>;

function isRecord(value: unknown): value is RecordValue {
  return typeof value === "object" && value !== null;
}

export type AuthTokenCacheData = {
  access_token: string;
  refresh_token: string;
  access_expires_at: string;
  device_id?: string;
  device_name?: string;
  refresh_expires_at?: string;
  is_account_owner?: boolean;
  is_owner_device?: boolean;
  token_type?: string;
  uid?: string;
};

export function extractAuthTokenCache(data: unknown): AuthTokenCacheData | null {
  if (!isRecord(data)) return null;

  const accessToken =
    typeof data.access_token === "string" ? data.access_token : "";
  const refreshToken =
    typeof data.refresh_token === "string" ? data.refresh_token : "";
  const accessExpiresAt =
    typeof data.access_expires_at === "string" ? data.access_expires_at : "";

  if (!accessToken || !refreshToken || !accessExpiresAt) {
    return null;
  }

  return {
    access_token: accessToken,
    refresh_token: refreshToken,
    access_expires_at: accessExpiresAt,
    device_id: typeof data.device_id === "string" ? data.device_id : undefined,
    device_name:
      typeof data.device_name === "string" ? data.device_name : undefined,
    refresh_expires_at:
      typeof data.refresh_expires_at === "string"
        ? data.refresh_expires_at
        : undefined,
    is_account_owner:
      typeof data.is_account_owner === "boolean"
        ? data.is_account_owner
        : undefined,
    is_owner_device:
      typeof data.is_owner_device === "boolean"
        ? data.is_owner_device
        : undefined,
    token_type: typeof data.token_type === "string" ? data.token_type : undefined,
    uid: typeof data.uid === "string" ? data.uid : undefined,
  };
}
