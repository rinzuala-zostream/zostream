import "server-only";

import { ApiError, apiClient, type QueryParams } from "@/app/lib/api-client";

export type SeasonStatus = "Draft" | "Published" | "Scheduled";

export type EpisodeItem = {
  num?: number;
  id?: string;
  season_id?: string;
  episode_number?: number;
  title?: string | null;
  description?: string | null;
  thumbnail?: string | null;
  duration?: number | null;
  amount?: number | string | null;
  release_date?: string | number | null;
  is_active?: boolean | number;
  isPayPerView?: boolean | number;
  status?: string;
  [key: string]: unknown;
};

export type SeasonItem = {
  num?: number;
  id?: string;
  movie_id?: number | string;
  movie?: SeasonMovieItem | null;
  isPayPerView?: boolean | number;
  amount?: number | string | null;
  season_number?: number;
  title?: string | null;
  description?: string | null;
  poster?: string | null;
  release_date?: number | string | null;
  release_year?: number | string | null;
  status?: SeasonStatus | string | null;
  episodes?: EpisodeItem[];
  [key: string]: unknown;
};

export type SeasonMovieItem = {
  num?: number;
  id?: string | number;
  title?: string;
  poster?: string | null;
  genre?: string | null;
  status?: string | null;
  seasons?: SeasonItem[];
  [key: string]: unknown;
};

export type SearchSeasonsByMovieTitleParams = {
  q: string;
  limit?: number;
};

export type CreateSeasonPayload = {
  movie_id: number;
  isPayPerView?: boolean | null;
  amount?: number | null;
  season_number: number;
  title?: string | null;
  description?: string | null;
  poster?: string | null;
  release_year?: number | null;
  status?: SeasonStatus;
};

export type UpdateSeasonPayload = Partial<
  Pick<
    CreateSeasonPayload,
    | "isPayPerView"
    | "amount"
    | "season_number"
    | "title"
    | "description"
    | "poster"
    | "release_year"
    | "status"
  >
>;

export type SeasonListResponse = {
  status: "success" | "error";
  data?: SeasonItem[];
  message?: string;
};

export type SeasonSingleResponse = {
  status: "success" | "error";
  data?: SeasonItem;
  message?: string;
  errors?: Record<string, string[]>;
};

export type SeasonDeleteResponse = {
  status: "success" | "error";
  message?: string;
};

export type SearchSeasonsByMovieTitleResponse = {
  status: "success" | "error";
  query?: string;
  count?: number;
  data?: SeasonMovieItem[];
  message?: string;
  errors?: Record<string, string[]>;
};

const PUBLIC_CATALOG_BASE_PATH = "/api/v4/catalog";
const ADMIN_SEASONS_BASE_PATH = "/api/v4/admin/catalog/seasons";

function toSearchQuery(params: SearchSeasonsByMovieTitleParams): QueryParams {
  return {
    q: params.q,
    limit: params.limit,
  };
}

export const seasonService = {
  async listByMovie(movieId: string | number) {
    return apiClient.get<SeasonListResponse>(
      `${PUBLIC_CATALOG_BASE_PATH}/items/${movieId}/seasons`,
    );
  },

  async searchByMovieTitle(params: SearchSeasonsByMovieTitleParams) {
    return apiClient.get<SearchSeasonsByMovieTitleResponse>(
      `${ADMIN_SEASONS_BASE_PATH}/search`,
      {
        query: toSearchQuery(params),
      },
    );
  },

  async getById(id: string | number) {
    return apiClient.get<SeasonSingleResponse>(
      `${PUBLIC_CATALOG_BASE_PATH}/seasons/${id}`,
    );
  },

  async create(payload: CreateSeasonPayload) {
    return apiClient.post<SeasonSingleResponse>(ADMIN_SEASONS_BASE_PATH, payload);
  },

  async update(id: string | number, payload: UpdateSeasonPayload) {
    return apiClient.put<SeasonSingleResponse>(
      `${ADMIN_SEASONS_BASE_PATH}/${id}`,
      payload,
    );
  },

  async remove(id: string | number) {
    return apiClient.delete<SeasonDeleteResponse>(
      `${ADMIN_SEASONS_BASE_PATH}/${id}`,
    );
  },
};

export { ApiError };
