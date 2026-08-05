"use server";

import { episodeService } from "@/app/features/seasons/services/episode-service";
import {
  ApiError,
  seasonService,
  type EpisodeItem,
  type SeasonItem,
  type SeasonMovieItem,
} from "@/app/features/seasons/services/season-service";

export type EpisodeSearchResultItem = {
  id: string;
  title: string;
  episodeNumber: string;
  status: string;
  releaseDate: string;
  isPayPerView: boolean;
  amount: string;
};

export type SeasonSearchResultItem = {
  id: string;
  title: string;
  seasonNumber: string;
  status: string;
  releaseDate: string;
  isPayPerView: boolean;
  amount: string;
  episodeCount: number;
  episodes: EpisodeSearchResultItem[];
};

export type SeasonMovieSearchResult = {
  movieId: string;
  movieNum: string;
  title: string;
  poster: string;
  genre: string;
  status: string;
  seasons: SeasonSearchResultItem[];
};

export type SeasonSearchState = {
  status: "idle" | "success" | "error";
  message: string;
  results: SeasonMovieSearchResult[];
};

export type DeleteSeasonState = {
  status: "success" | "error";
  message: string;
};

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

function valueToBoolean(value: unknown) {
  return value === true || value === 1 || value === "1";
}

function seasonId(season: SeasonItem) {
  return valueToString(season.id) || valueToString(season.num);
}

function episodeId(episode: EpisodeItem) {
  return valueToString(episode.id) || valueToString(episode.num);
}

function toEpisodeResult(episode: EpisodeItem): EpisodeSearchResultItem {
  return {
    id: episodeId(episode),
    title:
      valueToString(episode.title) ||
      `Episode ${episode.episode_number ?? ""}`.trim(),
    episodeNumber: valueToString(episode.episode_number),
    status: valueToString(episode.status) || "Unknown",
    releaseDate: valueToString(episode.release_date),
    isPayPerView: valueToBoolean(episode.isPayPerView),
    amount: valueToString(episode.amount),
  };
}

function toSeasonResult(season: SeasonItem): SeasonSearchResultItem {
  const episodes = Array.isArray(season.episodes)
    ? season.episodes.map(toEpisodeResult)
    : [];

  return {
    id: seasonId(season),
    title: valueToString(season.title) || `Season ${season.season_number ?? ""}`.trim(),
    seasonNumber: valueToString(season.season_number),
    status: valueToString(season.status) || "Unknown",
    releaseDate:
      valueToString(season.release_year) || valueToString(season.release_date),
    isPayPerView: valueToBoolean(season.isPayPerView),
    amount: valueToString(season.amount),
    episodeCount: episodes.length,
    episodes,
  };
}

function toMovieResult(movie: SeasonMovieItem): SeasonMovieSearchResult {
  return {
    movieId: valueToString(movie.id),
    movieNum: valueToString(movie.num),
    title: valueToString(movie.title) || "Untitled movie",
    poster: valueToString(movie.poster),
    genre: valueToString(movie.genre),
    status: valueToString(movie.status) || "Unknown",
    seasons: Array.isArray(movie.seasons)
      ? movie.seasons.map(toSeasonResult)
      : [],
  };
}

function validationError(errors?: Record<string, string[]>) {
  if (!errors) return undefined;
  return Object.values(errors).flat()[0];
}

function errorMessage(
  error: unknown,
  fallback = "Season search failed. Please try again.",
) {
  if (error instanceof ApiError) {
    if (
      error.status === 401 ||
      (typeof error.data === "object" &&
        error.data !== null &&
        "message" in error.data &&
        String((error.data as { message?: string }).message)
          .toLowerCase()
          .includes("invalid api key"))
    ) {
      return "Your admin session is missing or is not authorized.";
    }

    return error.message;
  }

  if (error instanceof Error) return error.message;
  return fallback;
}

export async function searchSeasonsAction(
  query: string,
): Promise<SeasonSearchState> {
  const trimmedQuery = query.trim();

  if (!trimmedQuery) {
    try {
      const response = await seasonService.searchByMovieTitle({
        q: "",
        limit: 20,
      });

      if (response.status === "error") {
        return {
          status: "error",
          message:
            validationError(response.errors) ??
            response.message ??
            "Season list failed. Please try again.",
          results: [],
        };
      }

      const results = (response.data ?? []).map(toMovieResult);
      const seasonCount = results.reduce(
        (total, movie) => total + movie.seasons.length,
        0,
      );

      return {
        status: "success",
        message:
          seasonCount > 0
            ? `${seasonCount} recent season${seasonCount === 1 ? "" : "s"} shown. Search to filter.`
            : "No seasons are available yet.",
        results,
      };
    } catch (error) {
      return {
        status: "error",
        message: errorMessage(error, "Season list failed. Please try again."),
        results: [],
      };
    }
  }

  try {
    const response = await seasonService.searchByMovieTitle({
      q: trimmedQuery,
      limit: 20,
    });

    if (response.status === "error") {
      return {
        status: "error",
        message:
          validationError(response.errors) ??
          response.message ??
          "Season search failed. Please try again.",
        results: [],
      };
    }

    const results = (response.data ?? []).map(toMovieResult);
    const seasonCount = results.reduce(
      (total, movie) => total + movie.seasons.length,
      0,
    );

    return {
      status: "success",
      message:
        seasonCount > 0
          ? `${seasonCount} season${seasonCount === 1 ? "" : "s"} found.`
          : "No seasons matched that movie title.",
      results,
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error),
      results: [],
    };
  }
}

export async function deleteSeasonAction(
  seasonId: string,
): Promise<DeleteSeasonState> {
  const trimmedSeasonId = seasonId.trim();

  if (!trimmedSeasonId) {
    return {
      status: "error",
      message: "Season ID is missing.",
    };
  }

  try {
    const response = await seasonService.remove(trimmedSeasonId);

    if (response.status === "error") {
      return {
        status: "error",
        message: response.message ?? "Season could not be deleted.",
      };
    }

    return {
      status: "success",
      message: response.message ?? "Season deleted.",
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error),
    };
  }
}

export async function deleteEpisodeAction(
  episodeIdValue: string,
): Promise<DeleteSeasonState> {
  const trimmedEpisodeId = episodeIdValue.trim();

  if (!trimmedEpisodeId) {
    return {
      status: "error",
      message: "Episode ID is missing.",
    };
  }

  try {
    const response = await episodeService.remove(trimmedEpisodeId);

    if (response.status === "error") {
      return {
        status: "error",
        message: response.message ?? "Episode could not be deleted.",
      };
    }

    return {
      status: "success",
      message: response.message ?? "Episode deleted.",
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error),
    };
  }
}
