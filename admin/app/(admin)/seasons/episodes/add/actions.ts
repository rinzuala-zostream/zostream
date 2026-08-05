"use server";

import {
  ApiError,
  episodeService,
  type CreateEpisodePayload,
  type CreateEpisodeVideoUrlPayload,
  type EpisodeStatus,
} from "@/app/features/seasons/services/episode-service";
import { adminFormDateValue } from "@/app/lib/admin-date";

export type AddEpisodeFormState = {
  status: "idle" | "success" | "error";
  message: string;
  episodeId?: string;
  videoUrlId?: string;
  resetKey?: string;
};

const initialState: AddEpisodeFormState = {
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

function episodeStatus(formData: FormData): EpisodeStatus {
  const value = stringValue(formData, "status");
  return statusValues.some((status) => status === value)
    ? (value as EpisodeStatus)
    : "Draft";
}

function validationError(errors?: Record<string, string[]>) {
  if (!errors) return undefined;
  return Object.values(errors).flat()[0];
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
  return "Episode could not be saved. Please try again.";
}

export async function createEpisodeAction(
  _previousState: AddEpisodeFormState = initialState,
  formData: FormData,
): Promise<AddEpisodeFormState> {
  const seasonId = stringValue(formData, "season_id");
  const episodeNumber = optionalNumber(formData, "episode_number");
  const movieId = optionalNumber(formData, "movie_id");
  const streamUrl = optionalString(formData, "video_url");

  if (!seasonId) {
    return {
      status: "error",
      message: "Season ID is required.",
      resetKey: `${Date.now()}`,
    };
  }

  if (!episodeNumber) {
    return {
      status: "error",
      message: "Episode number is required.",
      resetKey: `${Date.now()}`,
    };
  }

  if (!movieId) {
    return {
      status: "error",
      message: "Movie ID is required to attach the episode stream URL.",
      resetKey: `${Date.now()}`,
    };
  }

  if (!streamUrl) {
    return {
      status: "error",
      message: "Stream URL is required.",
      resetKey: `${Date.now()}`,
    };
  }

  const payload: CreateEpisodePayload = {
    season_id: seasonId,
    episode_number: episodeNumber,
    title: optionalString(formData, "title") ?? null,
    description: optionalString(formData, "description") ?? null,
    thumbnail: optionalString(formData, "thumbnail") ?? null,
    amount: optionalNumber(formData, "amount") ?? 0,
    release_date: adminFormDateValue(formData, "release_date") ?? null,
    is_active: formData.get("is_active") === "on",
    isPremium: formData.get("isPremium") === "on",
    isPayPerView: formData.get("isPayPerView") === "on",
    status: episodeStatus(formData),
    views: 0,
  };

  try {
    const response = await episodeService.create(payload);

    if (response.status === "error") {
      return {
        status: "error",
        message:
          validationError(response.errors) ??
          response.message ??
          "Episode could not be saved.",
        resetKey: `${Date.now()}`,
      };
    }

    const episodeId = response.data?.id;

    if (!episodeId) {
      return {
        status: "error",
        message:
          "Episode was saved, but the API did not return an episode ID for the stream URL.",
        resetKey: `${Date.now()}`,
      };
    }

    const videoUrlPayload: CreateEpisodeVideoUrlPayload = {
      movie_id: movieId,
      episode_id: episodeId,
      quality: optionalString(formData, "video_quality") ?? "HD",
      type: optionalString(formData, "video_type") ?? "DASH",
      url: streamUrl,
    };

    const videoResponse = await episodeService.addUrl(videoUrlPayload);

    if (videoResponse.status === "error") {
      return {
        status: "error",
        message:
          validationError(videoResponse.errors) ??
          videoResponse.message ??
          "Episode was saved, but the stream URL could not be attached.",
        episodeId,
        resetKey: `${Date.now()}`,
      };
    }

    return {
      status: "success",
      message: "Episode and stream URL saved successfully.",
      episodeId,
      videoUrlId: videoResponse.data?.id,
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
