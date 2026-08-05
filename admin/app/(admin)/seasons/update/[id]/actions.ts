"use server";

import { episodeService } from "@/app/features/seasons/services/episode-service";
import {
  ApiError,
  seasonService,
  type SeasonStatus,
  type UpdateSeasonPayload,
} from "@/app/features/seasons/services/season-service";

export type EditSeasonFormState = {
  status: "idle" | "success" | "error";
  message: string;
  resetKey?: string;
};

const initialState: EditSeasonFormState = {
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

function validationError(errors?: Record<string, string[]>) {
  if (!errors) return undefined;
  return Object.values(errors).flat()[0];
}

type EpisodePpvUpdate = {
  id?: unknown;
  isPayPerView?: unknown;
  amount?: unknown;
};

type ParsedEpisodePpvUpdate = {
  id: string;
  isPayPerView: boolean;
  amount: number;
};

function parseEpisodePpvUpdates(formData: FormData) {
  const rawValue = stringValue(formData, "episode_ppv_updates");
  if (!rawValue) return [];

  try {
    const parsedValue = JSON.parse(rawValue) as unknown;
    if (!Array.isArray(parsedValue)) return [];

    return parsedValue
      .map((item): ParsedEpisodePpvUpdate | null => {
        if (!item || typeof item !== "object") return null;

        const update = item as EpisodePpvUpdate;
        const id = typeof update.id === "string" ? update.id.trim() : "";
        if (!id) return null;

        const amount = Number(update.amount);

        return {
          id,
          isPayPerView: update.isPayPerView === true,
          amount: Number.isFinite(amount) && amount > 0 ? amount : 0,
        };
      })
      .filter((item): item is ParsedEpisodePpvUpdate => Boolean(item));
  } catch {
    return [];
  }
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
  return "Season could not be updated. Please try again.";
}

export async function updateSeasonAction(
  _previousState: EditSeasonFormState = initialState,
  formData: FormData,
): Promise<EditSeasonFormState> {
  const seasonId = stringValue(formData, "season_id");
  const seasonNumber = optionalNumber(formData, "season_number");

  if (!seasonId) {
    return {
      status: "error",
      message: "Season ID is missing.",
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

  const payload: UpdateSeasonPayload = {
    isPayPerView: formData.get("isPayPerView") === "on",
    amount: optionalNumber(formData, "amount") ?? 0,
    season_number: seasonNumber,
    title: optionalString(formData, "title") ?? null,
    description: optionalString(formData, "description") ?? null,
    poster: optionalString(formData, "poster") ?? null,
    release_year: optionalNumber(formData, "release_year") ?? null,
    status: seasonStatus(formData),
  };
  const episodePpvUpdates = parseEpisodePpvUpdates(formData);

  try {
    const response = await seasonService.update(seasonId, payload);

    if (response.status === "error") {
      return {
        status: "error",
        message:
          validationError(response.errors) ??
          response.message ??
          "Season could not be updated.",
        resetKey: `${Date.now()}`,
      };
    }

    if (payload.isPayPerView && episodePpvUpdates.length > 0) {
      for (const episodeUpdate of episodePpvUpdates) {
        const episodeResponse = await episodeService.update(episodeUpdate.id, {
          isPayPerView: episodeUpdate.isPayPerView,
          amount: episodeUpdate.amount,
        });

        if (episodeResponse.status === "error") {
          return {
            status: "error",
            message:
              validationError(episodeResponse.errors) ??
              episodeResponse.message ??
              "Season was updated, but an episode PPV price could not be saved.",
            resetKey: `${Date.now()}`,
          };
        }
      }
    }

    return {
      status: "success",
      message:
        response.message ??
        (episodePpvUpdates.length > 0
          ? "Season and episode PPV settings updated successfully."
          : "Season updated successfully."),
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
