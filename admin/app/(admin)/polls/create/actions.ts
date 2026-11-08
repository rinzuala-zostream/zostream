"use server";

import {
  ApiError,
  pollService,
  type CreatePollPayload,
  type PollStatus,
} from "@/app/features/polls/services/poll-service";

export type CreatePollFormState = {
  status: "idle" | "success" | "error";
  message: string;
  pollId?: number | string;
  resetKey?: string;
};

const initialState: CreatePollFormState = {
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
  return "Poll could not be saved. Please try again.";
}

export async function createPollAction(
  _previousState: CreatePollFormState = initialState,
  formData: FormData,
): Promise<CreatePollFormState> {
  const question = stringValue(formData, "question");
  const description = stringValue(formData, "description");
  const startsAt = nullableDateTime(formData, "starts_at");
  const endsAt = nullableDateTime(formData, "ends_at");
  const optionTexts = formData.getAll("option_text");

  const options = optionTexts
    .map((option, index) => ({
      option_text: typeof option === "string" ? option.trim() : "",
      sort_order: index,
    }))
    .filter((option) => option.option_text.length > 0);

  if (!question) {
    return {
      status: "error",
      message: "Poll question is required.",
      resetKey: `${Date.now()}`,
    };
  }

  if (options.length < 2) {
    return {
      status: "error",
      message: "Add at least 2 poll options.",
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

  const payload: CreatePollPayload = {
    question,
    description: description || null,
    status: pollStatus(formData),
    starts_at: startsAt,
    ends_at: endsAt,
    options,
  };

  try {
    const response = await pollService.create(payload);

    if (!response.status || !response.data?.id) {
      return {
        status: "error",
        message:
          validationError(response.errors) ??
          response.message ??
          response.error ??
          "Poll could not be saved.",
        resetKey: `${Date.now()}`,
      };
    }

    return {
      status: "success",
      message: `Poll saved with ${options.length} options.`,
      pollId: response.data.id,
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
