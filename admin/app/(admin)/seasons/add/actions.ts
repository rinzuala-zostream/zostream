"use server";

import {
  ApiError,
  seasonService,
  type CreateSeasonPayload,
  type SeasonStatus,
} from "@/app/features/seasons/services/season-service";

export type AddSeasonFormState = {
  status: "idle" | "success" | "error";
  message: string;
  seasonId?: string;
  resetKey?: string;
};

const initialState: AddSeasonFormState = {
  status: "idle",
  message: "",
};

const statusValues = ["Draft", "Published", "Scheduled"] as const;

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function optionalString(formData: FormData, key: string) {
  const value = stringValue(formData, key);
  return value.length > 0 ? value : undefined;
}

function optionalNumber(formData: FormData, key: string) {
  const value = stringValue(formData, key);
  if (!value) return undefined;

  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : undefined;
}

function seasonStatus(formData: FormData): SeasonStatus {
  const value = stringValue(formData, "status");
  return statusValues.some((status) => status === value)
    ? (value as SeasonStatus)
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
  return "Season could not be saved. Please try again.";
}

function validationError(errors?: Record<string, string[]>) {
  if (!errors) return undefined;

  const firstError = Object.values(errors).flat()[0];
  return firstError;
}

export async function createSeasonAction(
  _previousState: AddSeasonFormState = initialState,
  formData: FormData,
): Promise<AddSeasonFormState> {
  const movieId = optionalNumber(formData, "movie_id");
  const seasonNumber = optionalNumber(formData, "season_number");

  if (!movieId) {
    return {
      status: "error",
      message: "Movie ID is required.",
      resetKey: `${Date.now()}`,
    };
  }

  if (!seasonNumber) {
    return {
      status: "error",
      message: "Season number is required.",
      resetKey: `${Date.now()}`,
    };
  }

  const payload: CreateSeasonPayload = {
    movie_id: movieId,
    isPayPerView: formData.get("isPayPerView") === "on",
    amount: optionalNumber(formData, "amount") ?? 0,
    season_number: seasonNumber,
    title: optionalString(formData, "title") ?? null,
    description: optionalString(formData, "description") ?? null,
    poster: optionalString(formData, "poster") ?? null,
    release_year: optionalNumber(formData, "release_year") ?? null,
    status: seasonStatus(formData),
  };

  try {
    const response = await seasonService.create(payload);

    if (response.status === "error") {
      return {
        status: "error",
        message:
          validationError(response.errors) ??
          response.message ??
          "Season could not be saved.",
        resetKey: `${Date.now()}`,
      };
    }

    return {
      status: "success",
      message: response.message ?? "Season saved successfully.",
      seasonId: response.data?.id,
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
