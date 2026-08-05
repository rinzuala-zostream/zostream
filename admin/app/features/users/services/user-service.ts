import "server-only";

import { ApiError, apiClient, type QueryParams } from "@/app/lib/api-client";

export type UserItem = {
  num?: number | string;
  uid?: string | null;
  mail?: string | null;
  name?: string | null;
  call?: string | null;
  country_code?: string | null;
  auth_phone?: string | null;
  is_auth_phone_active?: boolean | number;
  img?: string | null;
  dob?: string | null;
  khua?: string | null;
  veng?: string | null;
  device_id?: string | null;
  device_name?: string | null;
  token?: string | null;
  isACActive?: boolean | number;
  isAccountComplete?: boolean | number;
  created_date?: string | null;
  edit_date?: string | null;
  lastLogin?: string | null;
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

export type ListUsersParams = {
  page?: number;
  limit?: number;
  search?: string;
};

export type SearchUsersParams = ListUsersParams & {
  q?: string;
  name?: string;
  mail?: string;
  email?: string;
  phone?: string;
  uid?: string;
  per_page?: number;
};

export type FindUserPayload = {
  uid?: string | null;
  mail?: string | null;
};

export type FindUserKey = "uid" | "mail";

export type CreateUserPayload = {
  uid: string;
  mail?: string | null;
  name?: string | null;
  call?: string | null;
  country_code?: string | null;
  auth_phone?: string | null;
  is_auth_phone_active?: boolean | null;
  img?: string | null;
  dob?: string | null;
  khua?: string | null;
  veng?: string | null;
  device_id?: string | null;
  device_name?: string | null;
  token?: string | null;
  isACActive?: boolean | null;
  isAccountComplete?: boolean | null;
  created_date?: string | null;
  edit_date?: string | null;
  lastLogin?: string | null;
};

export type CreateUserInput = Omit<CreateUserPayload, "uid"> & {
  uid?: string | null;
};

export type UpdateUserPayload = Partial<CreateUserPayload>;

export type UserLookup = {
  uid?: string | null;
  mail?: string | null;
  auth_phone?: string | null;
};

export type UserListResponse = {
  status: "success" | "error";
  data?: PaginationMeta<UserItem>;
  message?: string;
  error?: string;
  errors?: Record<string, string[]>;
};

export type UserSingleResponse = {
  status: "success" | "error";
  data?: UserItem;
  message?: string;
  error?: string;
  errors?: Record<string, string[]>;
};

export type UserMutationResponse = {
  status: "success" | "error";
  message?: string;
  data?: UserItem;
  error?: string;
  errors?: Record<string, string[]>;
};

export type UserDeleteResponse = {
  status: "success" | "error";
  message?: string;
  error?: string;
};

const USERS_BASE_PATH = "/api/v4/admin/users";
const USERS_SEARCH_PATH = "/api/v4/admin/users-search";

function cleanString(value?: string | number | null) {
  if (value === null || value === undefined) return "";
  return String(value).trim();
}

function normalizeNullableString(value?: string | number | null) {
  const cleaned = cleanString(value);
  return cleaned || null;
}

export function normalizeUserPhoneNumber(phoneNumber?: string | number | null) {
  const digits = cleanString(phoneNumber).replace(/\D/g, "");
  return digits ? digits.slice(-10) : null;
}

export function makeUserUid(input?: UserLookup) {
  const uid = cleanString(input?.uid);
  if (uid) return uid;

  const phoneNumber = normalizeUserPhoneNumber(input?.auth_phone);
  if (phoneNumber) return phoneNumber;

  const mail = cleanString(input?.mail);
  if (mail) return mail;

  return `user_${Date.now()}`;
}

export function buildCreateUserPayload(input: CreateUserInput) {
  return {
    ...input,
    uid: makeUserUid(input),
    mail: normalizeNullableString(input.mail),
    name: normalizeNullableString(input.name),
    call: normalizeNullableString(input.call),
    auth_phone: normalizeUserPhoneNumber(input.auth_phone),
    img: normalizeNullableString(input.img),
    dob: normalizeNullableString(input.dob),
    khua: normalizeNullableString(input.khua),
    veng: normalizeNullableString(input.veng),
    device_id: normalizeNullableString(input.device_id),
    device_name: normalizeNullableString(input.device_name),
    token: normalizeNullableString(input.token),
    created_date: normalizeNullableString(input.created_date),
    edit_date: normalizeNullableString(input.edit_date),
    lastLogin: normalizeNullableString(input.lastLogin),
    is_auth_phone_active: input.is_auth_phone_active ?? true,
    isACActive: input.isACActive ?? true,
    isAccountComplete: input.isAccountComplete ?? false,
  } satisfies CreateUserPayload;
}

function toListQueryParams(params?: ListUsersParams): QueryParams | undefined {
  if (!params) return undefined;

  return {
    page: params.page,
    limit: params.limit,
    per_page: params.limit,
  };
}

function toSearchQueryParams(
  params?: SearchUsersParams,
): QueryParams | undefined {
  if (!params) return undefined;

  return {
    page: params.page,
    limit: params.limit,
    per_page: params.per_page ?? params.limit,
    q: params.q ?? params.search,
    name: params.name,
    mail: params.mail,
    email: params.email,
    phone: params.phone,
    uid: params.uid,
  };
}

export const userService = {
  async list(params?: ListUsersParams) {
    if (cleanString(params?.search)) {
      return userService.search(params);
    }

    return apiClient.get<UserListResponse>(USERS_BASE_PATH, {
      query: toListQueryParams(params),
    });
  },

  async search(params?: SearchUsersParams) {
    try {
      const response = await apiClient.get<UserListResponse>(
        USERS_SEARCH_PATH,
        {
          query: toSearchQueryParams(params),
        },
      );

      return response;
    } catch (error) {
      console.error("User search API error:", error);
      throw error;
    }
  },

  async getByUid(uid: string | number) {
    return apiClient.get<UserSingleResponse>(`${USERS_BASE_PATH}/${uid}`);
  },

  async getById(uid: string | number) {
    return userService.getByUid(uid);
  },

  async find(payload: FindUserPayload) {
    return apiClient.get<UserSingleResponse>(`${USERS_BASE_PATH}/find`, {
      query: payload,
    });
  },

  async findBy(key: FindUserKey, value: string) {
    return userService.find({ [key]: value });
  },

  async findByUid(uid: string) {
    return userService.findBy("uid", uid);
  },

  async findByEmail(mail: string) {
    return userService.findBy("mail", mail);
  },

  async create(payload: CreateUserInput) {
    return apiClient.post<UserMutationResponse>(
      USERS_BASE_PATH,
      buildCreateUserPayload(payload),
    );
  },

  async updateByNum(num: string | number, payload: UpdateUserPayload) {
    return apiClient.put<UserMutationResponse>(
      `${USERS_BASE_PATH}/${num}`,
      payload,
    );
  },

  async update(id: string | number, payload: UpdateUserPayload) {
    return userService.updateByNum(id, payload);
  },

  async updateByUid(uid: string | number, payload: UpdateUserPayload) {
    const response = await userService.getByUid(uid);
    const num = response.data?.num;

    if (num === null || num === undefined || num === "") {
      throw new ApiError("User numeric id is missing from API response", {
        status: 500,
        method: "GET",
        url: `${USERS_BASE_PATH}/${uid}`,
        data: response,
      });
    }

    return userService.updateByNum(num, payload);
  },

  async createOrUpdateByUid(payload: CreateUserInput) {
    const createPayload = buildCreateUserPayload(payload);

    try {
      const response = await userService.getByUid(createPayload.uid);
      const num = response.data?.num;

      if (num === null || num === undefined || num === "") {
        throw new ApiError("User numeric id is missing from API response", {
          status: 500,
          method: "GET",
          url: `${USERS_BASE_PATH}/${createPayload.uid}`,
          data: response,
        });
      }

      return userService.updateByNum(num, createPayload);
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) {
        return apiClient.post<UserMutationResponse>(
          USERS_BASE_PATH,
          createPayload,
        );
      }

      throw error;
    }
  },

  async removeByNum(num: string | number) {
    return apiClient.delete<UserDeleteResponse>(`${USERS_BASE_PATH}/${num}`);
  },

  async remove(id: string | number) {
    return userService.removeByNum(id);
  },

  async removeByUid(uid: string | number) {
    const response = await userService.getByUid(uid);
    const num = response.data?.num;

    if (num === null || num === undefined || num === "") {
      throw new ApiError("User numeric id is missing from API response", {
        status: 500,
        method: "GET",
        url: `${USERS_BASE_PATH}/${uid}`,
        data: response,
      });
    }

    return userService.removeByNum(num);
  },
};

export { ApiError };
