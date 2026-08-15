import "server-only";

import { ApiError, apiClient, type QueryParams } from "@/app/lib/api-client";

export type MovieStatus = "Draft" | "Published" | "Scheduled" | string;

export type MovieItem = {
  num?: number;
  id?: string;
  title?: string | null;
  description?: string | null;
  director?: string | null;
  duration?: string | number | null;
  genre?: string | null;
  age_rating?: string | null;
  poster?: string | null;
  cover_img?: string | null;
  title_img?: string | null;
  release_on?: string | null;
  views?: number;
  status?: MovieStatus | null;
  isProtected?: boolean | number;
  isBollywood?: boolean | number;
  isCompleted?: boolean | number;
  isDocumentary?: boolean | number;
  isDubbed?: boolean | number;
  isEnable?: boolean | number;
  isHollywood?: boolean | number;
  isKorean?: boolean | number;
  isMizo?: boolean | number;
  isPayPerView?: boolean | number;
  isPremium?: boolean | number;
  isAgeRestricted?: boolean | number;
  isChildMode?: boolean | number;
  isSeason?: boolean | number;
  isSubtitle?: boolean | number;
  create_date?: string | null;
  url?: string | null;
  dash_url?: string | null;
  hls_url?: string | null;
  trailer?: string | null;
  subtitle?: string | null;
  token?: string | null;
  ppv_amount?: string | number | null;
  [key: string]: unknown;
};

export type PaginationLink = {
  url: string | null;
  label: string;
  active: boolean;
};

export type PaginatedMovies = {
  current_page: number;
  data: MovieItem[];
  first_page_url?: string | null;
  from?: number | null;
  last_page: number;
  last_page_url?: string | null;
  links?: PaginationLink[];
  next_page_url?: string | null;
  path?: string;
  per_page: number;
  prev_page_url?: string | null;
  to?: number | null;
  total: number;
};

export type MovieIndexParams = {
  page?: number;
  perPage?: number;
};

export type MovieIndexResponse = {
  status: "success" | "error";
  data?: PaginatedMovies;
  message?: string;
  error?: string;
};

export type MovieCreatePayload = {
  title: string;
  description?: string;
  genre?: string;
  age_rating?: string;
  director?: string;
  duration?: string;
  release_on?: string;
  title_img?: string;
  cover_img?: string;
  poster?: string;
  url?: string;
  dash_url?: string;
  hls_url?: string;
  trailer?: string;
  subtitle?: string;
  token?: string;
  views?: number;
  status?: "Published" | "Draft" | "Scheduled";
  create_date?: string;
  ppv_amount?: string;
  notification?: boolean;
  isProtected?: boolean;
  isBollywood?: boolean;
  isCompleted?: boolean;
  isDocumentary?: boolean;
  isAgeRestricted?: boolean;
  isDubbed?: boolean;
  isEnable?: boolean;
  isHollywood?: boolean;
  isKorean?: boolean;
  isMizo?: boolean;
  isPayPerView?: boolean;
  isPremium?: boolean;
  isSeason?: boolean;
  isSubtitle?: boolean;
  isChildMode?: boolean;
};

export type MovieMutationResponse = {
  status?: "success" | "error";
  message?: string;
  error?: string;
  movie?: MovieItem;
  data?: MovieItem;
};

export type MovieDeleteResponse = {
  status?: "success" | "error";
  message?: string;
  error?: string;
};

export type MovieUpdatePayload = {
  [Field in keyof MovieCreatePayload]?: MovieCreatePayload[Field] | null;
};

export type MovieLinkType = "movie" | "episode";

export type MovieDetailsParams = {
  type?: MovieLinkType;
};

export type EpisodeMovieDetailsItem = {
  num?: number;
  id?: string;
  season_id?: string;
  episode_number?: number;
  title?: string | null;
  description?: string | null;
  thumbnail?: string | null;
  duration?: number | string | null;
  amount?: number | string | null;
  ppv_amount?: number | string | null;
  release_date?: string | null;
  is_active?: boolean | number;
  isPayPerView?: boolean | number;
  status?: string | null;
  season?: {
    num?: number;
    id?: string;
    movie_id?: number | string;
    season_number?: number;
    title?: string | null;
    [key: string]: unknown;
  } | null;
  [key: string]: unknown;
};

export type MovieDetailsResponse = MovieItem | EpisodeMovieDetailsItem;

export type MovieDetailsByType<TType extends MovieLinkType> =
  TType extends "episode" ? EpisodeMovieDetailsItem : MovieItem;

export type MovieHomeParams = {
  id?: string | number;
  range?: string;
  category?: string;
  categoryType?: string;
  isEnable?: boolean;
  isChildMode?: boolean;
  ageRestriction?: boolean;
  userId?: string;
  mode?: "adult" | "kids";
  platform?: string;
};

export type MovieHomeSectionsResponse = Record<string, MovieItem[]>;
export type MovieHomeListResponse = MovieItem[];
export type MovieHomeSingleResponse =
  | MovieItem
  | { status: "error"; message?: string };
export type MovieHomeResponse =
  | MovieHomeSectionsResponse
  | MovieHomeListResponse
  | MovieHomeSingleResponse;

export type EpisodeVideoLink = {
  id: number;
  quality?: string | null;
  type?: string | null;
  url: string;
};

export type MovieLinks = Record<string, string>;

export type MovieLinksResponse = {
  status: "success" | "error";
  type?: MovieLinkType;
  movie_id?: number | string | null;
  episode_id?: string | number;
  title?: string | null;
  links?: MovieLinks | EpisodeVideoLink[];
  message?: string;
  error?: string;
};

export type EpisodeUrlItem = {
  id?: number;
  episode_id?: string | number;
  quality?: string | null;
  type?: string | null;
  url?: string | null;
  [key: string]: unknown;
};

export type EpisodeUrlsResponse = {
  status: "success" | "error";
  data?: EpisodeUrlItem[];
  message?: string;
};

export type PayPerViewContentType = "movie" | "episode";

export type PayPerViewContentParams = {
  type: PayPerViewContentType;
  movieId: string | number;
  subscriptionId?: string | number | null;
};

export type PayPerViewPaymentOption = {
  payment_movie_id: string | number;
  ppv_amount: number;
  discount_percent: number;
  discount_amount: number;
  final_ppv_price: number;
};

export type SeasonPayPerViewPaymentOption = PayPerViewPaymentOption & {
  ppv_episode_count: number;
  ppv_episodes_total_amount: number;
  ppv_episodes_discount_amount: number;
  ppv_episodes_final_price: number;
  season_benefit_amount: number;
  season_benefit_message: string;
};

export type PayPerViewMovieContent = {
  type: "movie";
  payment_movie_id: string | number;
  payment_options: {
    movie: PayPerViewPaymentOption;
  };
  movie_id: string | number;
  movie_num?: number | string | null;
  title?: string | null;
  poster?: string | null;
  isPayPerView: boolean;
  ppv_amount: number | string;
  discount_percent: number;
  discount_amount: number;
  final_ppv_price: number;
};

export type PayPerViewSeasonSummary = {
  id: string | number;
  movie_id?: string | number | null;
  title?: string | null;
  poster?: string | null;
  isPayPerView: boolean;
  ppv_amount: number | string;
};

export type PayPerViewEpisodeContent = {
  type: "episode";
  payment_movie_id: string | number;
  payment_options: {
    episode: PayPerViewPaymentOption;
    season: SeasonPayPerViewPaymentOption | null;
  };
  movie_id?: string | number | null;
  episode_id: string | number;
  title?: string | null;
  poster?: string | null;
  isPayPerView: boolean;
  ppv_amount: number | string;
  discount_percent: number;
  discount_amount: number;
  final_ppv_price: number;
  season: PayPerViewSeasonSummary | null;
};

export type PayPerViewContentResponse = {
  status: "success" | "error";
  data?: PayPerViewMovieContent | PayPerViewEpisodeContent;
  message?: string;
  error?: string;
  errors?: Record<string, string[]>;
};

export type PayPerViewContentByType<TType extends PayPerViewContentType> =
  TType extends "episode" ? PayPerViewEpisodeContent : PayPerViewMovieContent;

export type PayPerViewContentResponseByType<
  TType extends PayPerViewContentType,
> = Omit<PayPerViewContentResponse, "data"> & {
  data?: PayPerViewContentByType<TType>;
};

const MOVIES_BASE_PATH = "/api/v4/catalog/items";
const CATALOG_BASE_PATH = "/api/v4/catalog";
const ADMIN_CATALOG_BASE_PATH = "/api/v4/admin/catalog";

function toIndexQuery(params: MovieIndexParams = {}): QueryParams {
  return {
    page: params.page,
    per_page: params.perPage,
  };
}

function toHomeQuery(params: MovieHomeParams = {}): QueryParams {
  return {
    id: params.id,
    range: params.range,
    category: params.category,
    category_type: params.categoryType,
    is_enable: params.isEnable,
    isChildMode: params.isChildMode,
    age_restriction: params.ageRestriction,
    user_id: params.userId,
    platform: params.platform,
  };
}

function toPayPerViewContentQuery(
  params: PayPerViewContentParams,
): QueryParams {
  return {
    type: params.type,
    movie_id: params.movieId,
    subscriptionId: params.subscriptionId,
  };
}

function userHeaders(
  userId?: string,
  mode?: "adult" | "kids",
): HeadersInit | undefined {
  const headers: Record<string, string> = {};

  if (userId) {
    headers["X-User-Id"] = userId;
  }

  if (mode) {
    headers["X-Mode"] = mode;
  }

  return Object.keys(headers).length > 0 ? headers : undefined;
}

export const movieService = {
  async list(params: MovieIndexParams = {}) {
    return apiClient.get<MovieIndexResponse>(MOVIES_BASE_PATH, {
      query: toIndexQuery(params),
    });
  },

  async getById<TType extends MovieLinkType = "movie">(
    id: string | number,
    params: { type?: TType } = {},
  ) {
    return apiClient.get<MovieDetailsByType<TType>>(
      `${MOVIES_BASE_PATH}/${id}`,
      {
        query: {
          type: params.type,
        },
      },
    );
  },

  async getLinks(id: string | number, type: MovieLinkType = "movie") {
    return apiClient.get<MovieLinksResponse>(
      `${ADMIN_CATALOG_BASE_PATH}/items/${id}/links`,
      {
        query: { type },
      },
    );
  },

  async adminGetLinks(id: string | number, type: MovieLinkType = "movie") {
    return apiClient.get<MovieLinksResponse>(
      `${ADMIN_CATALOG_BASE_PATH}/items/${id}/links`,
      {
        query: { type },
      },
    );
  },

  async adminGetById(id: string | number) {
    return apiClient.get<MovieItem>(
      `${ADMIN_CATALOG_BASE_PATH}/items/${id}`,
      {
        query: { type: "movie" },
      },
    );
  },

  async getHome(params: MovieHomeParams = {}) {
    return apiClient.get<MovieHomeResponse>(`${CATALOG_BASE_PATH}/home`, {
      query: toHomeQuery(params),
      headers: userHeaders(params.userId, params.mode),
    });
  },

  async getHomeMovie(
    id: string | number,
    params: Omit<MovieHomeParams, "id"> = {},
  ) {
    return apiClient.get<MovieHomeSingleResponse>(`${CATALOG_BASE_PATH}/home`, {
      query: toHomeQuery({ ...params, id }),
      headers: userHeaders(params.userId, params.mode),
    });
  },

  async getHomeCategory(params: MovieHomeParams) {
    return apiClient.get<MovieHomeListResponse>(`${CATALOG_BASE_PATH}/home`, {
      query: toHomeQuery(params),
      headers: userHeaders(params.userId, params.mode),
    });
  },

  async getPayPerViewContent<TType extends PayPerViewContentType>(
    params: PayPerViewContentParams & { type: TType },
  ) {
    return apiClient.get<PayPerViewContentResponseByType<TType>>(
      `${CATALOG_BASE_PATH}/ppv`,
      {
        query: toPayPerViewContentQuery(params),
      },
    );
  },

  async getEpisodeUrls(episodeId: string | number) {
    return apiClient.get<EpisodeUrlsResponse>(
      `${ADMIN_CATALOG_BASE_PATH}/episodes/${episodeId}/urls`,
    );
  },

  async create(payload: MovieCreatePayload) {
    return apiClient.post<MovieMutationResponse>(
      `${ADMIN_CATALOG_BASE_PATH}/items`,
      payload,
    );
  },

  async update(id: string | number, payload: MovieUpdatePayload) {
    return apiClient.put<MovieMutationResponse>(
      `${ADMIN_CATALOG_BASE_PATH}/items/${id}`,
      payload,
    );
  },

  async remove(id: string | number) {
    return apiClient.delete<MovieDeleteResponse>(
      `${ADMIN_CATALOG_BASE_PATH}/items/${id}`,
    );
  },
};

export { ApiError };
