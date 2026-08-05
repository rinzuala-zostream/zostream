import "server-only";

import { ApiError, apiClient, type QueryParams } from "@/app/lib/api-client";

export type DeviceStatus = "active" | "inactive" | "blocked";

export type DeviceItem = {
  id?: number | string;
  user_id?: string | null;
  subscription_id?: number | string | null;
  device_token?: string | null;
  device_name?: string | null;
  device_type?: string | null;
  status?: DeviceStatus | string | null;
  is_owner_device?: boolean | number;
  shared_to_user_id?: string | null;
  last_activity?: string | null;
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

export type ListDevicesParams = {
  page?: number;
  per_page?: number;
};

export type SearchDevicesParams = ListDevicesParams & {
  q: string;
};

export type ClearDevicesPayload = {
  user_id: string;
  device_type?: string | null;
  device_token?: string | null;
};

export type CreateDevicePayload = {
  user_id: string;
  subscription_id: number;
  device_token: string;
  device_name: string;
  device_type: string;
  status?: DeviceStatus | null;
  is_owner_device?: boolean | null;
  shared_to_user_id?: string | null;
};

export type UpdateDevicePayload = Partial<CreateDevicePayload>;

export type DeviceListResponse = {
  status: "success" | "error";
  data?: PaginationMeta<DeviceItem>;
  message?: string;
  error?: string;
  errors?: Record<string, string[]>;
};

export type DeviceSingleResponse = {
  status: "success" | "error";
  data?: DeviceItem;
  message?: string;
  error?: string;
  errors?: Record<string, string[]>;
};

export type DeviceMutationResponse = {
  status: "success" | "error";
  message?: string;
  data?: DeviceItem;
  error?: string;
  errors?: Record<string, string[]>;
};

export type DeviceDeleteResponse = {
  status: "success" | "error";
  message?: string;
  error?: string;
};

export type DeviceClearResponse = {
  status: "success" | "error";
  message?: string;
  deleted_count?: number;
  error?: string;
  errors?: Record<string, string[]>;
};

const DEVICES_BASE_PATH = "/api/v4/admin/devices";

function cleanString(value?: string | number | null) {
  if (value === null || value === undefined) return "";
  return String(value).trim();
}

function normalizeNullableString(value?: string | number | null) {
  const cleaned = cleanString(value);
  return cleaned || undefined;
}

function toListQueryParams(params?: ListDevicesParams): QueryParams | undefined {
  if (!params) return undefined;

  return {
    page: params.page,
    per_page: params.per_page,
  };
}

function buildClearDevicesPayload(payload: ClearDevicesPayload) {
  return {
    user_id: cleanString(payload.user_id),
    device_type: normalizeNullableString(payload.device_type),
    device_token: normalizeNullableString(payload.device_token),
  };
}

function buildCreateDevicePayload(payload: CreateDevicePayload) {
  return {
    ...payload,
    user_id: cleanString(payload.user_id),
    device_token: cleanString(payload.device_token),
    device_name: cleanString(payload.device_name),
    device_type: cleanString(payload.device_type),
    status: payload.status ?? "active",
    shared_to_user_id: normalizeNullableString(payload.shared_to_user_id),
  };
}

export const deviceService = {
  async list(params?: ListDevicesParams) {
    return apiClient.get<DeviceListResponse>(DEVICES_BASE_PATH, {
      query: toListQueryParams(params),
    });
  },

  async search(params: SearchDevicesParams) {
    return apiClient.get<DeviceListResponse>(`${DEVICES_BASE_PATH}/search`, {
      query: {
        q: cleanString(params.q),
        page: params.page,
        per_page: params.per_page,
      },
    });
  },

  async getById(id: string | number) {
    return apiClient.get<DeviceSingleResponse>(`${DEVICES_BASE_PATH}/${id}`);
  },

  async getByUser(userId: string, params?: ListDevicesParams) {
    return apiClient.get<DeviceListResponse>(
      `${DEVICES_BASE_PATH}/user/${encodeURIComponent(cleanString(userId))}`,
      {
        query: toListQueryParams(params),
      },
    );
  },

  async create(payload: CreateDevicePayload) {
    return apiClient.post<DeviceMutationResponse>(
      DEVICES_BASE_PATH,
      buildCreateDevicePayload(payload),
    );
  },

  async update(id: string | number, payload: UpdateDevicePayload) {
    return apiClient.put<DeviceMutationResponse>(
      `${DEVICES_BASE_PATH}/${id}`,
      payload,
    );
  },

  async remove(id: string | number) {
    return apiClient.delete<DeviceDeleteResponse>(`${DEVICES_BASE_PATH}/${id}`);
  },

  async clear(payload: ClearDevicesPayload) {
    return apiClient.post<DeviceClearResponse>(
      `${DEVICES_BASE_PATH}/clear`,
      buildClearDevicesPayload(payload),
    );
  },
};

export { ApiError };
