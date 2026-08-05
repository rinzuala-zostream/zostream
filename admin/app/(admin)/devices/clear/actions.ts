"use server";

import { revalidatePath } from "next/cache";
import {
  ApiError,
  deviceService,
  type ClearDevicesPayload,
} from "@/app/features/devices/services/device-service";

export type ClearDeviceFormState = {
  status: "idle" | "success" | "error";
  message: string;
  deletedCount?: number;
  resetKey?: string;
};

const initialState: ClearDeviceFormState = {
  status: "idle",
  message: "",
};

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function optionalString(formData: FormData, key: string) {
  const value = stringValue(formData, key);
  return value ? value : undefined;
}

function errorMessage(
  error: unknown,
  fallback = "Device records could not be cleared. Please try again.",
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

export async function clearDeviceAction(
  _previousState: ClearDeviceFormState = initialState,
  formData: FormData,
): Promise<ClearDeviceFormState> {
  const userId = stringValue(formData, "user_id");

  if (!userId) {
    return {
      status: "error",
      message: "User ID is required.",
      resetKey: `${Date.now()}`,
    };
  }

  const payload: ClearDevicesPayload = {
    user_id: userId,
    device_type: optionalString(formData, "device_type"),
    device_token: optionalString(formData, "device_token"),
  };

  try {
    const response = await deviceService.clear(payload);

    if (response.status === "error") {
      return {
        status: "error",
        message: response.message ?? "Device records could not be cleared.",
        resetKey: `${Date.now()}`,
      };
    }

    revalidatePath("/devices/clear");

    return {
      status: "success",
      message: response.message ?? "Device records cleared successfully.",
      deletedCount: response.deleted_count ?? 0,
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
