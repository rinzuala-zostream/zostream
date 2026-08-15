import "server-only";

import { ApiError, apiClient, type QueryParams } from "@/app/lib/api-client";
import type { MovieItem } from "@/app/features/movies/services/movie-service";

export type MovieSearchParams = {
  query: string;
};

export type MovieSearchErrorResponse = {
  error?: string;
  message?: string;
};

export type AdminMovieSearchResponse = MovieSearchErrorResponse & {
  status?: "success" | "error";
  query?: string;
  count?: number;
  data?: MovieItem[];
};

export type MovieSearchResponse = MovieItem[] | AdminMovieSearchResponse;

const MOVIE_SEARCH_PATH = "/api/v4/admin/catalog/items/search";

function toSearchQuery(params: MovieSearchParams): QueryParams {
  return {
    q: params.query,
  };
}

export const searchService = {
  async movies(params: MovieSearchParams) {
    return apiClient.get<MovieSearchResponse>(MOVIE_SEARCH_PATH, {
      query: toSearchQuery(params),
    });
  },
};

export { ApiError };
