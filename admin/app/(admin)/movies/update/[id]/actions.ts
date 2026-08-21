"use server";

import {
  ApiError,
  movieService,
  type MovieCreatePayload,
  type MovieUpdatePayload,
} from "@/app/features/movies/services/movie-service";
import { adminFormDateValue } from "@/app/lib/admin-date";

export type EditMovieFormState = {
  status: "idle" | "success" | "error";
  message: string;
  resetKey?: string;
};

const initialState: EditMovieFormState = {
  status: "idle",
  message: "",
};

const textFields = [
  "description",
  "genre",
  "age_rating",
  "director",
  "duration",
  "release_on",
  "title_img",
  "cover_img",
  "poster",
  "url",
  "dash_url",
  "hls_url",
  "trailer",
  "subtitle",
  "token",
  "create_date",
  "ppv_amount",
] as const;

const booleanFields = [
  "isProtected",
  "isBollywood",
  "isCompleted",
  "isDocumentary",
  "isAgeRestricted",
  "isDubbed",
  "isEnable",
  "isHollywood",
  "isKorean",
  "isMizo",
  "isPayPerView",
  "isPremium",
  "isSeason",
  "isSubtitle",
  "isChildMode",
] as const;

const statusValues = ["Published", "Draft", "Scheduled"] as const;

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function optionalString(formData: FormData, key: string) {
  const value = stringValue(formData, key);
  return value.length > 0 ? value : undefined;
}

function movieStatus(formData: FormData): MovieCreatePayload["status"] {
  const value = stringValue(formData, "status");
  return statusValues.some((status) => status === value)
    ? (value as MovieCreatePayload["status"])
    : "Draft";
}

function errorMessage(error: unknown) {
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
  return "Movie could not be updated. Please try again.";
}

export async function updateMovieAction(
  _previousState: EditMovieFormState = initialState,
  formData: FormData,
): Promise<EditMovieFormState> {
  void _previousState;
  const movieId = stringValue(formData, "movie_id");
  const title = stringValue(formData, "title");

  if (!movieId) {
    return {
      status: "error",
      message: "Movie ID is missing.",
      resetKey: `${Date.now()}`,
    };
  }

  if (!title) {
    return {
      status: "error",
      message: "Movie title is required.",
      resetKey: `${Date.now()}`,
    };
  }

  const payload: MovieUpdatePayload = {
    title,
    status: movieStatus(formData),
    notification: formData.get("notification") === "on",
    refresh_latest: formData.get("refresh_latest") === "on",
  };

  for (const field of textFields) {
    payload[field] =
      field === "release_on" || field === "create_date"
        ? (adminFormDateValue(formData, field, "readable") ?? null)
        : (optionalString(formData, field) ?? null);
  }

  const submittedBooleanFields = new Set(
    formData
      .getAll("boolean_fields")
      .filter((field): field is string => typeof field === "string"),
  );

  for (const field of booleanFields) {
    if (!submittedBooleanFields.has(field)) continue;
    payload[field] = formData.get(field) === "on";
  }

  if (!payload.isPayPerView) {
    delete payload.ppv_amount;
  }

  try {
    const response = await movieService.update(movieId, payload);

    if (response.status === "error") {
      return {
        status: "error",
        message:
          response.message ?? response.error ?? "Movie could not be updated.",
        resetKey: `${Date.now()}`,
      };
    }

    return {
      status: "success",
      message: response.message ?? "Movie updated successfully.",
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error),
      resetKey: `${Date.now()}`,
    };
  }
}
