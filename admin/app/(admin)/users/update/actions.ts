"use server";

import { revalidatePath } from "next/cache";
import {
  ApiError,
  userService,
  type UpdateUserPayload,
} from "@/app/features/users/services/user-service";
import { adminFormDateValue } from "@/app/lib/admin-date";

export type UserMutationState = {
  status: "idle" | "success" | "error";
  message: string;
  error?: string;
  errors?: Record<string, string[]>;
  debug?: {
    status?: number;
    method?: string;
    url?: string;
    backendError?: string;
    data?: unknown;
    response?: unknown;
  };
  resetKey?: string;
};

const initialState: UserMutationState = {
  status: "idle",
  message: "",
};

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function optionalString(formData: FormData, key: string) {
  const value = stringValue(formData, key);
  return value ? value : null;
}

function optionalDefinedString(formData: FormData, key: string) {
  const value = stringValue(formData, key);
  return value ? value : undefined;
}

function checkboxValue(formData: FormData, key: string) {
  return formData.get(key) === "on";
}

function errorMessage(
  error: unknown,
  fallback = "User could not be updated. Please try again.",
) {
  if (error instanceof ApiError) {
    return (
      validationError(validationErrorsFromData(error.data)) ?? error.message
    );
  }
  if (error instanceof Error) return error.message;
  return fallback;
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

function errorFromData(data: unknown) {
  if (
    typeof data === "object" &&
    data !== null &&
    "error" in data &&
    typeof (data as { error?: unknown }).error === "string"
  ) {
    return (data as { error: string }).error;
  }

  return undefined;
}

export async function updateUserAction(
  userId: string,
  _previousState: UserMutationState = initialState,
  formData: FormData,
): Promise<UserMutationState> {
  const trimmedUserId = userId.trim();

  if (!trimmedUserId) {
    return {
      status: "error",
      message: "User ID is missing.",
      resetKey: `${Date.now()}`,
    };
  }

  const payload: UpdateUserPayload = {
    uid: optionalDefinedString(formData, "uid"),
    mail: optionalString(formData, "mail"),
    name: optionalString(formData, "name"),
    call: optionalString(formData, "call"),
    country_code: optionalString(formData, "country_code"),
    auth_phone: optionalString(formData, "auth_phone"),
    img: optionalString(formData, "img"),
    dob: adminFormDateValue(formData, "dob") ?? null,
    khua: optionalString(formData, "khua"),
    veng: optionalString(formData, "veng"),
    device_id: optionalString(formData, "device_id"),
    device_name: optionalString(formData, "device_name"),
    token: optionalString(formData, "token"),
    is_auth_phone_active: checkboxValue(formData, "is_auth_phone_active"),
    isACActive: checkboxValue(formData, "isACActive"),
    isAccountComplete: checkboxValue(formData, "isAccountComplete"),
  };

  try {
    const response = await userService.updateByUid(trimmedUserId, payload);

    if (response.status === "error") {
      console.error("User update validation failed", {
        userId: trimmedUserId,
        error: response.error,
        errors: response.errors,
        response,
      });

      return {
        status: "error",
        message:
          validationError(response.errors) ??
          response.message ??
          response.error ??
          "User could not be updated.",
        error: response.error,
        errors: response.errors,
        debug: {
          backendError: response.error,
          response,
        },
        resetKey: `${Date.now()}`,
      };
    }

    revalidatePath("/users/update");
    revalidatePath(`/users/update/${encodeURIComponent(trimmedUserId)}`);

    return {
      status: "success",
      message: response.message ?? "User updated.",
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    const errors =
      error instanceof ApiError
        ? validationErrorsFromData(error.data)
        : undefined;
    const backendError =
      error instanceof ApiError ? errorFromData(error.data) : undefined;

    console.error("User update request failed", {
      userId: trimmedUserId,
      backendError,
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
      error: backendError,
      errors,
      debug:
        error instanceof ApiError
          ? {
              status: error.status,
              method: error.method,
              url: error.url,
              backendError,
              data: error.data,
            }
          : undefined,
      resetKey: `${Date.now()}`,
    };
  }
}
