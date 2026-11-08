import { NextRequest, NextResponse } from "next/server";
import {
  ApiError,
  searchService,
} from "@/app/features/movies/services/search-service";
import type { MovieItem } from "@/app/features/movies/services/movie-service";

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

function movieResult(movie: MovieItem) {
  return {
    id: valueToString(movie.id) || valueToString(movie.num),
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

function json(data: unknown, status = 200) {
  return NextResponse.json(data, {
    status,
    headers: { "Cache-Control": "no-store, max-age=0" },
  });
}

export async function GET(request: NextRequest) {
  const query = request.nextUrl.searchParams.get("q")?.trim() ?? "";

  if (!query) {
    return json({
      status: "idle",
      message: "Search by movie title, director, or keyword.",
      results: [],
    });
  }

  try {
    const response = await searchService.movies({ query });
    const movies = Array.isArray(response) ? response : response.data;

    if (!Array.isArray(movies)) {
      return json(
        {
          status: "error",
          message:
            (!Array.isArray(response) &&
              (response.message || response.error)) ||
            "Movie search failed. Please try again.",
          results: [],
        },
        502,
      );
    }

    return json({
      status: "success",
      message:
        movies.length > 0
          ? `${movies.length} movie${movies.length === 1 ? "" : "s"} found.`
          : "No movies matched that search.",
      results: movies.map(movieResult),
    });
  } catch (error) {
    return json(
      {
        status: "error",
        message: error instanceof Error ? error.message : "Movie search failed.",
        results: [],
      },
      error instanceof ApiError ? error.status : 500,
    );
  }
}

