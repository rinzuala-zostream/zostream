import "server-only";

import { ApiError, apiClient, type QueryParams } from "@/app/lib/api-client";

export type BannerType = "movie" | "ad" | "external" | "category" | "custom";
export type BannerMediaType = "image" | "video";
export type BannerTargetType =
  | "movie"
  | "series"
  | "episode"
  | "url"
  | "category"
  | "none";
export type BannerAgeRating = "G" | "PG" | "PG13" | "R" | "18+" | "21+";

export type BannerItem = {
  id?: number | string;
  title?: string | null;
  description?: string | null;
  type?: BannerType | string | null;
  media_type?: BannerMediaType | string | null;
  media_url?: string | null;
  thumbnail_url?: string | null;
  target_type?: BannerTargetType | string | null;
  target_id?: string | null;
  target_url?: string | null;
  priority?: number | string | null;
  is_active?: boolean | number;
  age_restriction_enabled?: boolean | number;
  min_age?: number | string | null;
  max_age?: number | string | null;
  age_rating?: BannerAgeRating | string | null;
  requires_parental_pin?: boolean | number;
  start_date?: string | null;
  end_date?: string | null;
  button_text?: string | null;
  batch?: string;
  [key: string]: unknown;
};

export type ListBannersParams = {
  age?: number | string;
  parental_pin_verified?: boolean;
};

export type CreateBannerPayload = {
  title?: string | null;
  description?: string | null;
  type: BannerType;
  media_type: BannerMediaType;
  media_url: string;
  thumbnail_url?: string | null;
  target_type: BannerTargetType;
  target_id?: string | null;
  target_url?: string | null;
  priority?: number | null;
  is_active?: boolean;
  age_restriction_enabled?: boolean;
  min_age?: number | null;
  max_age?: number | null;
  age_rating?: BannerAgeRating | null;
  requires_parental_pin?: boolean;
  start_date?: string | null;
  end_date?: string | null;
  button_text?: string | null;
};

export type UpdateBannerPayload = Partial<CreateBannerPayload>;

export type BannerListResponse = {
  status: boolean;
  data?: BannerItem[];
  message?: string;
  error?: string;
};

export type BannerMutationResponse = {
  status: boolean;
  message?: string;
  data?: BannerItem;
  errors?: Record<string, string[]>;
  error?: string;
};

export type BannerDeleteResponse = {
  status: boolean;
  message?: string;
  error?: string;
};

export type BannerSingleResponse = {
  status: boolean;
  data?: BannerItem;
  message?: string;
  error?: string;
};

const BANNERS_BASE_PATH = "/api/v4/banners";
const ADMIN_BANNERS_BASE_PATH = "/api/v4/admin/banners";

function toBannerListQueryParams(
  params?: ListBannersParams,
): QueryParams | undefined {
  if (!params) return undefined;

  return {
    age: params.age,
    parental_pin_verified: params.parental_pin_verified,
  };
}

export const bannerService = {
  async list(params?: ListBannersParams) {
    return apiClient.get<BannerListResponse>(BANNERS_BASE_PATH, {
      query: toBannerListQueryParams(params),
    });
  },

  async getById(id: string | number) {
    return apiClient.get<BannerSingleResponse>(`${BANNERS_BASE_PATH}/${id}`);
  },

  async create(payload: CreateBannerPayload) {
    return apiClient.post<BannerMutationResponse>(ADMIN_BANNERS_BASE_PATH, payload);
  },

  async update(id: string | number, payload: UpdateBannerPayload) {
    return apiClient.put<BannerMutationResponse>(
      `${ADMIN_BANNERS_BASE_PATH}/${id}`,
      payload,
    );
  },

  async remove(id: string | number) {
    return apiClient.delete<BannerDeleteResponse>(
      `${ADMIN_BANNERS_BASE_PATH}/${id}`,
    );
  },
};

export { ApiError };
