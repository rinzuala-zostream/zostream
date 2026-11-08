"use server";

import {
  ApiError,
  searchService,
} from "@/app/features/movies/services/search-service";
import {
  movieService,
  type MovieItem,
} from "@/app/features/movies/services/movie-service";

export type MovieSearchResultItem = {
  id: string;
  movieNum: string;
  title: string;
  description: string;
  coverImage: string;
  movieUrl: string;
  releaseOn: string;
  duration: string;
  status: string;
};

export type MovieSearchState = {
  status: "idle" | "success" | "error";
  message: string;
  results: MovieSearchResultItem[];
};

export type DeleteMovieState = {
  status: "success" | "error";
  message: string;
};

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

function movieId(movie: MovieItem) {
  return valueToString(movie.id) || valueToString(movie.num);
}

function toSearchResult(movie: MovieItem): MovieSearchResultItem {
  return {
    id: movieId(movie),
    movieNum: valueToString(movie.num),
    title: valueToString(movie.title) || "Untitled movie",
    description: valueToString(movie.description),
    coverImage: valueToString(movie.cover_img),
    movieUrl: valueToString(movie.url),
    releaseOn: valueToString(movie.release_on),
    duration: valueToString(movie.duration),
    status: valueToString(movie.status) || "Unknown",
  };
}

function errorMessage(
  error: unknown,
  fallback = "Movie search failed. Please try again.",
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

export async function searchMoviesAction(
  query: string,
): Promise<MovieSearchState> {
  const trimmedQuery = query.trim();

  if (!trimmedQuery) {
    return {
      status: "idle",
      message: "Search by movie title, director, or keyword.",
      results: [],
    };
  }

  try {
    const response = await searchService.movies({
      query: trimmedQuery,
    });
    if (!Array.isArray(response) && !Array.isArray(response.data)) {
      return {
        status: "error",
        message:
          response.message ?? response.error ?? "Movie search failed. Please try again.",
        results: [],
      };
    }

    const movies = Array.isArray(response) ? response : (response.data ?? []);

    return {
      status: "success",
      message:
        movies.length > 0
          ? `${movies.length} movie${movies.length === 1 ? "" : "s"} found.`
          : "No movies matched that search.",
      results: movies.map(toSearchResult),
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error),
      results: [],
    };
  }
}

export async function deleteMovieAction(
  movieIdValue: string,
): Promise<DeleteMovieState> {
  const trimmedMovieId = movieIdValue.trim();

  if (!trimmedMovieId) {
    return {
      status: "error",
      message: "Movie ID is missing.",
    };
  }

  try {
    const response = await movieService.remove(trimmedMovieId);

    if (response.status === "error") {
      return {
        status: "error",
        message:
          response.message ?? response.error ?? "Movie could not be deleted.",
      };
    }

    return {
      status: "success",
      message: response.message ?? "Movie deleted.",
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error, "Movie could not be deleted."),
    };
  }
}
