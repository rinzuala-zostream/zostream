"use server";

import { revalidatePath } from "next/cache";
import {
  ApiError,
  pollService,
  type PollStatus,
  type UpdatePollPayload,
} from "@/app/features/polls/services/poll-service";

export type PollMutationState = {
  status: "idle" | "success" | "error";
  message: string;
  resetKey?: string;
};

const initialState: PollMutationState = {
  status: "idle",
  message: "",
};

const pollStatuses = ["active", "closed"] as const;

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function nullableDateTime(formData: FormData, key: string) {
  const value = stringValue(formData, key);
  return value || null;
}

function pollStatus(formData: FormData): PollStatus {
  const value = stringValue(formData, "status").toLowerCase();
  return pollStatuses.some((status) => status === value)
    ? (value as PollStatus)
    : "active";
}

function validationError(errors?: Record<string, string[]>) {
  if (!errors) return undefined;
  return Object.values(errors).flat()[0];
}

function errorMessage(
  error: unknown,
  fallback = "Poll request failed. Please try again.",
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

export async function deletePollAction(
  pollId: string,
): Promise<PollMutationState> {
  const trimmedPollId = pollId.trim();

  if (!trimmedPollId) {
    return {
      status: "error",
      message: "Poll ID is missing.",
      resetKey: `${Date.now()}`,
    };
  }

  try {
    const response = await pollService.remove(trimmedPollId);

    if (!response.status) {
      return {
        status: "error",
        message:
          response.message ?? response.error ?? "Poll could not be deleted.",
        resetKey: `${Date.now()}`,
      };
    }

    revalidatePath("/polls/results");

    return {
      status: "success",
      message: response.message ?? "Poll deleted.",
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error, "Poll could not be deleted."),
      resetKey: `${Date.now()}`,
    };
  }
}

export async function updatePollAction(
  pollId: string,
  _previousState: PollMutationState = initialState,
  formData: FormData,
): Promise<PollMutationState> {
  const trimmedPollId = pollId.trim();
  const question = stringValue(formData, "question");
  const description = stringValue(formData, "description");
  const startsAt = nullableDateTime(formData, "starts_at");
  const endsAt = nullableDateTime(formData, "ends_at");

  if (!trimmedPollId) {
    return {
      status: "error",
      message: "Poll ID is missing.",
      resetKey: `${Date.now()}`,
    };
  }

  if (!question) {
    return {
      status: "error",
      message: "Poll question is required.",
      resetKey: `${Date.now()}`,
    };
  }

  if (
    startsAt &&
    endsAt &&
    new Date(endsAt).getTime() < new Date(startsAt).getTime()
  ) {
    return {
      status: "error",
      message: "End date must be after the start date.",
      resetKey: `${Date.now()}`,
    };
  }

  const payload: UpdatePollPayload = {
    question,
    description: description || null,
    status: pollStatus(formData),
    starts_at: startsAt,
    ends_at: endsAt,
  };

  try {
    const response = await pollService.update(trimmedPollId, payload);

    if (!response.status) {
      return {
        status: "error",
        message:
          validationError(response.errors) ??
          response.message ??
          response.error ??
          "Poll could not be updated.",
        resetKey: `${Date.now()}`,
      };
    }

    revalidatePath("/polls/results");
    revalidatePath(`/polls/results/${trimmedPollId}`);

    return {
      status: "success",
      message: response.message ?? "Poll updated successfully.",
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error, "Poll could not be updated."),
      resetKey: `${Date.now()}`,
    };
  }
}

export async function syncPollOptionsAction(
  pollId: string,
  _previousState: PollMutationState = initialState,
  formData: FormData,
): Promise<PollMutationState> {
  const trimmedPollId = pollId.trim();

  if (!trimmedPollId) {
    return {
      status: "error",
      message: "Poll ID is missing.",
      resetKey: `${Date.now()}`,
    };
  }

  const optionIds = formData.getAll("option_id");
  const optionTexts = formData.getAll("option_text");
  const existingOptionIds = formData
    .getAll("existing_option_id")
    .filter((value): value is string => typeof value === "string")
    .map((value) => value.trim())
    .filter(Boolean);
  const submittedExistingOptionIds = new Set<string>();

  const options = optionTexts
    .map((option, index) => {
      const id =
        typeof optionIds[index] === "string" ? optionIds[index].trim() : "";
      const optionText = typeof option === "string" ? option.trim() : "";

      if (id && optionText) {
        submittedExistingOptionIds.add(id);
      }

      return {
        id,
        option_text: optionText,
        sort_order: index,
      };
    })
    .filter((option) => option.option_text.length > 0);

  if (options.length < 2) {
    return {
      status: "error",
      message: "A poll must have at least 2 options.",
      resetKey: `${Date.now()}`,
    };
  }

  try {
    for (const option of options) {
      if (option.id) {
        const response = await pollService.updateOption(option.id, {
          option_text: option.option_text,
          sort_order: option.sort_order,
        });

        if (!response.status) {
          return {
            status: "error",
            message:
              validationError(response.errors) ??
              response.message ??
              response.error ??
              "An option could not be updated.",
            resetKey: `${Date.now()}`,
          };
        }
      } else {
        const response = await pollService.createOption(trimmedPollId, {
          option_text: option.option_text,
          sort_order: option.sort_order,
        });

        if (!response.status) {
          return {
            status: "error",
            message:
              validationError(response.errors) ??
              response.message ??
              response.error ??
              "An option could not be created.",
            resetKey: `${Date.now()}`,
          };
        }
      }
    }

    for (const optionId of existingOptionIds) {
      if (!submittedExistingOptionIds.has(optionId)) {
        const response = await pollService.removeOption(optionId);

        if (!response.status) {
          return {
            status: "error",
            message:
              response.message ?? response.error ?? "An option could not be removed.",
            resetKey: `${Date.now()}`,
          };
        }
      }
    }

    revalidatePath("/polls/results");
    revalidatePath(`/polls/results/${trimmedPollId}`);

    return {
      status: "success",
      message: "Poll options updated successfully.",
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error, "Poll options could not be updated."),
      resetKey: `${Date.now()}`,
    };
  }
}
