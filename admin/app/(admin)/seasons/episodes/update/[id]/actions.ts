"use server";

import {
  ApiError,
  episodeService,
  type CreateEpisodeVideoUrlPayload,
  type EpisodeStatus,
  type UpdateEpisodePayload,
  type UpdateEpisodeVideoUrlPayload,
} from "@/app/features/seasons/services/episode-service";
import { adminFormDateValue } from "@/app/lib/admin-date";

export type EditEpisodeFormState = {
  status: "idle" | "success" | "error";
  message: string;
  errors?: Record<string, string[]>;
  episodeId?: string;
  videoUrlId?: string;
  resetKey?: string;
};

const initialState: EditEpisodeFormState = {
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

function selectedImageFile(formData: FormData) {
  const value = formData.get("image");
  return typeof File !== "undefined" &&
    value instanceof File &&
    value.size > 0
    ? value
    : undefined;
}

function appendUpdatePayload(formData: FormData, payload: UpdateEpisodePayload) {
  for (const [key, value] of Object.entries(payload)) {
    if (typeof value === "boolean") {
      formData.set(key, value ? "1" : "0");
      continue;
    }

    formData.set(
      key,
      value === null || value === undefined ? "" : String(value),
    );
  }
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

function validationErrorsFromData(data: unknown) {
  if (
    typeof data === "object" &&
    data !== null &&
    "errors" in data &&
    typeof (data as { errors?: unknown }).errors === "object" &&
    (data as { errors?: unknown }).errors !== null
  ) {
    return (data as { errors: Record<string, string[]> }).errors;
  }

  return undefined;
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

    return validationError(validationErrorsFromData(error.data)) ?? error.message;
  }

  if (error instanceof Error) return error.message;
  return "Episode could not be updated. Please try again.";
}

export async function updateEpisodeAction(
  episodeId: string,
  _previousState: EditEpisodeFormState = initialState,
  formData: FormData,
): Promise<EditEpisodeFormState> {
  const trimmedEpisodeId = episodeId.trim();
  const seasonId = stringValue(formData, "season_id");
  const episodeNumber = optionalNumber(formData, "episode_number");
  const movieId = optionalNumber(formData, "movie_id");
  const streamUrl = optionalString(formData, "video_url");
  const videoUrlId = stringValue(formData, "video_url_id");
  const initialStreamUrl = stringValue(formData, "initial_video_url");
  const videoQuality = optionalString(formData, "video_quality") ?? "HD";
  const initialVideoQuality =
    optionalString(formData, "initial_video_quality") ?? "HD";
  const videoType = optionalString(formData, "video_type") ?? "DASH";
  const initialVideoType =
    optionalString(formData, "initial_video_type") ?? "DASH";
  const imageFile = selectedImageFile(formData);
  const streamUrlValue = streamUrl ?? "";
  const hasStreamUrl = streamUrlValue.length > 0;
  const isStreamUrlChanged = streamUrlValue !== initialStreamUrl;
  const isVideoMetaChanged =
    videoQuality !== initialVideoQuality || videoType !== initialVideoType;
  const shouldSaveStreamUrl =
    hasStreamUrl && (!videoUrlId || isStreamUrlChanged || isVideoMetaChanged);

  if (!trimmedEpisodeId) {
    return {
      status: "error",
      message: "Episode ID is missing.",
      resetKey: `${Date.now()}`,
    };
  }

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

  const payload: UpdateEpisodePayload = {
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
  };

  try {
    const updatePayload: UpdateEpisodePayload | FormData = imageFile
      ? new FormData()
      : payload;

    if (updatePayload instanceof FormData && imageFile) {
      appendUpdatePayload(updatePayload, payload);
      updatePayload.set("image", imageFile);
    }

    const response = await episodeService.update(
      trimmedEpisodeId,
      updatePayload,
    );

    if (response.status === "error") {
      console.error("Episode update validation failed", {
        episodeId: trimmedEpisodeId,
        errors: response.errors,
        response,
      });

      return {
        status: "error",
        message:
          validationError(response.errors) ??
          response.message ??
          "Episode could not be updated.",
        errors: response.errors,
        resetKey: `${Date.now()}`,
      };
    }

    if (shouldSaveStreamUrl) {
      if (!movieId) {
        return {
          status: "error",
          message:
            "Episode was updated, but Movie ID is required to save the stream URL.",
          episodeId: trimmedEpisodeId,
          resetKey: `${Date.now()}`,
        };
      }

      if (videoUrlId) {
        const videoPayload: UpdateEpisodeVideoUrlPayload = {
          quality: videoQuality,
          type: videoType,
        };

        if (isStreamUrlChanged) {
          videoPayload.url = streamUrlValue;
        }

        const videoResponse = await episodeService.updateUrl(
          videoUrlId,
          videoPayload,
        );

        if (videoResponse.status === "error") {
          console.error("Episode stream update validation failed", {
            episodeId: trimmedEpisodeId,
            videoUrlId,
            errors: videoResponse.errors,
            response: videoResponse,
          });

          return {
            status: "error",
            message:
              validationError(videoResponse.errors) ??
              videoResponse.message ??
              "Episode was updated, but the stream URL could not be updated.",
            errors: videoResponse.errors,
            episodeId: trimmedEpisodeId,
            videoUrlId,
            resetKey: `${Date.now()}`,
          };
        }

        return {
          status: "success",
          message: "Episode and stream URL updated successfully.",
          episodeId: trimmedEpisodeId,
          videoUrlId,
          resetKey: `${Date.now()}`,
        };
      }

      const videoPayload: CreateEpisodeVideoUrlPayload = {
        movie_id: movieId,
        episode_id: trimmedEpisodeId,
        quality: videoQuality,
        type: videoType,
        url: streamUrlValue,
      };
      const videoResponse = await episodeService.addUrl(videoPayload);

      if (videoResponse.status === "error") {
        console.error("Episode stream attach validation failed", {
          episodeId: trimmedEpisodeId,
          errors: videoResponse.errors,
          response: videoResponse,
        });

        return {
          status: "error",
          message:
            validationError(videoResponse.errors) ??
            videoResponse.message ??
            "Episode was updated, but the stream URL could not be attached.",
          errors: videoResponse.errors,
          episodeId: trimmedEpisodeId,
          resetKey: `${Date.now()}`,
        };
      }

      return {
        status: "success",
        message: "Episode updated and stream URL attached successfully.",
        episodeId: trimmedEpisodeId,
        videoUrlId: videoResponse.data?.id,
        resetKey: `${Date.now()}`,
      };
    }

    return {
      status: "success",
      message: "Episode updated successfully.",
      episodeId: trimmedEpisodeId,
      videoUrlId: videoUrlId || undefined,
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    const errors =
      error instanceof ApiError ? validationErrorsFromData(error.data) : undefined;

    console.error("Episode update request failed", {
      episodeId: trimmedEpisodeId,
      errors,
      error:
        error instanceof ApiError
          ? {
              message: error.message,
              status: error.status,
              method: error.method,
              url: error.url,
              data: error.data,
            }
          : error,
    });

    return {
      status: "error",
      message: errorMessage(error),
      errors,
      resetKey: `${Date.now()}`,
    };
  }
}
