import "server-only";

import { ApiError, apiClient } from "@/app/lib/api-client";

export type EpisodeStatus = "Draft" | "Published" | "Scheduled";

export type EpisodeItem = {
  num?: number;
  id?: string;
  season_id?: string;
  episode_number?: number;
  title?: string | null;
  description?: string | null;
  thumbnail?: string | null;
  duration?: number | string | null;
  amount?: number | string | null;
  release_date?: string | null;
  is_active?: boolean | number;
  isPremium?: boolean | number;
  isPayPerView?: boolean | number;
  status?: EpisodeStatus | string | null;
  [key: string]: unknown;
};

export type EpisodeSeasonMovie = {
  num?: number;
  id?: string;
  title?: string | null;
  poster?: string | null;
  cover_img?: string | null;
  [key: string]: unknown;
};

export type EpisodeSeason = {
  num?: number;
  id?: string;
  movie_id?: number | string;
  season_number?: number;
  title?: string | null;
  movie?: EpisodeSeasonMovie | null;
  [key: string]: unknown;
};

export type EpisodeDetailsItem = EpisodeItem & {
  season?: EpisodeSeason | null;
};

export type CreateEpisodePayload = {
  season_id: string;
  episode_number: number;
  title?: string | null;
  description?: string | null;
  thumbnail?: string | null;
  amount?: number | null;
  release_date?: string | null;
  is_active?: boolean;
  isPremium?: boolean;
  isPayPerView?: boolean;
  status?: EpisodeStatus;
  views?: number;
};

export type UpdateEpisodePayload = Partial<CreateEpisodePayload>;

export type UpdateEpisodeRequestPayload = UpdateEpisodePayload | FormData;

export type EpisodeListResponse = {
  status: "success" | "error";
  data?: EpisodeItem[];
  message?: string;
};

export type EpisodeSingleResponse = {
  status: "success" | "error";
  data?: EpisodeDetailsItem;
  message?: string;
  errors?: Record<string, string[]>;
};

export type EpisodeDeleteResponse = {
  status: "success" | "error";
  message?: string;
};

export type EpisodeVideoUrlItem = {
  id?: string;
  movie_id?: number | string;
  episode_id?: string | null;
  quality?: string | null;
  type?: string | null;
  url?: string | null;
  [key: string]: unknown;
};

export type CreateEpisodeVideoUrlPayload = {
  movie_id: number;
  episode_id?: string | null;
  quality?: string | null;
  type?: string | null;
  url: string;
};

export type UpdateEpisodeVideoUrlPayload = Partial<
  Pick<CreateEpisodeVideoUrlPayload, "quality" | "type" | "url">
>;

export type EpisodeVideoUrlSingleResponse = {
  status: "success" | "error";
  data?: EpisodeVideoUrlItem;
  message?: string;
  errors?: Record<string, string[]>;
};

export type EpisodeVideoUrlListResponse = {
  status: "success" | "error";
  data?: EpisodeVideoUrlItem[];
  message?: string;
};

export type EpisodeVideoUrlDeleteResponse = {
  status: "success" | "error";
  message?: string;
};

const PUBLIC_CATALOG_BASE_PATH = "/api/v4/catalog";
const ADMIN_EPISODES_BASE_PATH = "/api/v4/admin/catalog/episodes";
const ADMIN_EPISODE_URLS_BASE_PATH = "/api/v4/admin/catalog/episode-urls";

export const episodeService = {
  async listBySeason(seasonId: string | number) {
    return apiClient.get<EpisodeListResponse>(
      `${PUBLIC_CATALOG_BASE_PATH}/seasons/${seasonId}/episodes`,
    );
  },

  async create(payload: CreateEpisodePayload) {
    return apiClient.post<EpisodeSingleResponse>(ADMIN_EPISODES_BASE_PATH, payload);
  },

  async getById(id: string | number) {
    return apiClient.get<EpisodeSingleResponse>(
      `${PUBLIC_CATALOG_BASE_PATH}/episodes/${id}`,
    );
  },

  async update(id: string | number, payload: UpdateEpisodeRequestPayload) {
    const path = `${ADMIN_EPISODES_BASE_PATH}/${id}`;

    if (payload instanceof FormData) {
      if (!payload.has("_method")) {
        payload.append("_method", "PUT");
      }

      return apiClient.post<EpisodeSingleResponse>(path, payload);
    }

    return apiClient.put<EpisodeSingleResponse>(path, payload);
  },

  async remove(id: string | number) {
    return apiClient.delete<EpisodeDeleteResponse>(
      `${ADMIN_EPISODES_BASE_PATH}/${id}`,
    );
  },

  async addUrl(payload: CreateEpisodeVideoUrlPayload) {
    return apiClient.post<EpisodeVideoUrlSingleResponse>(
      ADMIN_EPISODE_URLS_BASE_PATH,
      payload,
    );
  },

  async listUrls(episodeId: string | number) {
    return apiClient.get<EpisodeVideoUrlListResponse>(
      `${ADMIN_EPISODES_BASE_PATH}/${episodeId}/urls`,
    );
  },

  async updateUrl(id: string | number, payload: UpdateEpisodeVideoUrlPayload) {
    return apiClient.put<EpisodeVideoUrlSingleResponse>(
      `${ADMIN_EPISODE_URLS_BASE_PATH}/${id}`,
      payload,
    );
  },

  async removeUrl(id: string | number) {
    return apiClient.delete<EpisodeVideoUrlDeleteResponse>(
      `${ADMIN_EPISODE_URLS_BASE_PATH}/${id}`,
    );
  },
};

export { ApiError };
