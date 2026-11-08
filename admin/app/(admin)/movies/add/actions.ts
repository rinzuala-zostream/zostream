"use server";

import {
  ApiError,
  movieService,
  type MovieCreatePayload,
} from "@/app/features/movies/services/movie-service";
import { adminFormDateValue } from "@/app/lib/admin-date";

export type AddMovieFormState = {
  status: "idle" | "success" | "error";
  message: string;
  movieId?: string;
  resetKey?: string;
};

const initialState: AddMovieFormState = {
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
  return "Movie could not be saved. Please try again.";
}

export async function createMovieAction(
  _previousState: AddMovieFormState = initialState,
  formData: FormData,
): Promise<AddMovieFormState> {
  void _previousState;
  const title = stringValue(formData, "title");

  if (!title) {
    return {
      status: "error",
      message: "Movie title is required.",
      resetKey: `${Date.now()}`,
    };
  }

  const payload: MovieCreatePayload = {
    title,
    status: movieStatus(formData),
    notification: formData.get("notification") === "on",
    refresh_latest: formData.get("refresh_latest") === "on",
  };

  for (const field of textFields) {
    const value =
      field === "release_on" || field === "create_date"
        ? adminFormDateValue(formData, field, "readable")
        : optionalString(formData, field);

    if (value !== undefined) {
      payload[field] = value;
    }
  }

  for (const field of booleanFields) {
    payload[field] = formData.get(field) === "on";
  }

  if (!payload.isPayPerView) {
    delete payload.ppv_amount;
  }

  try {
    const response = await movieService.create(payload);

    if (response.status === "error") {
      return {
        status: "error",
        message: response.message ?? response.error ?? "Movie could not be saved.",
        resetKey: `${Date.now()}`,
      };
    }

    return {
      status: "success",
      message: response.message ?? "Movie saved successfully.",
      movieId:
        typeof (response.movie ?? response.data)?.id === "string"
          ? (response.movie ?? response.data)?.id
          : undefined,
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
