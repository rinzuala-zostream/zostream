import "server-only";

import { ApiError, apiClient, type QueryParams } from "@/app/lib/api-client";
import type { MovieItem } from "@/app/features/movies/services/movie-service";

export type MovieSearchParams = {
  query: string;
  isEnable?: boolean;
  ageRestriction?: boolean;
  isChildMode?: boolean;
  userId?: string;
  mode?: "adult" | "kids";
};

export type MovieSearchErrorResponse = {
  error?: string;
  message?: string;
};

export type MovieSearchResponse = MovieItem[] | MovieSearchErrorResponse;

const MOVIE_SEARCH_PATH = "/api/v4/catalog/items/search";

function toSearchQuery(params: MovieSearchParams): QueryParams {
  return {
    q: params.query,
    is_enable: params.isEnable,
    age_restriction: params.ageRestriction,
    isChildMode: params.isChildMode,
    user_id: params.userId,
  };
}

function searchHeaders(params: MovieSearchParams): HeadersInit | undefined {
  const headers: Record<string, string> = {};

  if (params.userId) {
    headers["X-User-Id"] = params.userId;
  }

  if (params.mode) {
    headers["X-Mode"] = params.mode;
  }

  return Object.keys(headers).length > 0 ? headers : undefined;
}

export const searchService = {
  async movies(params: MovieSearchParams) {
    return apiClient.get<MovieSearchResponse>(MOVIE_SEARCH_PATH, {
      query: toSearchQuery(params),
      headers: searchHeaders(params),
    });
  },
};

export { ApiError };
