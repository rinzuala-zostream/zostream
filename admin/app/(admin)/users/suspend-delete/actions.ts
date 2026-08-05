"use server";

import { revalidatePath } from "next/cache";
import { ApiError, userService } from "@/app/features/users/services/user-service";

export type UserDangerActionState = {
  status: "success" | "error";
  message: string;
  resetKey?: string;
};

function errorMessage(
  error: unknown,
  fallback = "User action failed. Please try again.",
) {
  if (error instanceof ApiError) return error.message;
  if (error instanceof Error) return error.message;
  return fallback;
}

function cleanUserId(userId: string) {
  return userId.trim();
}

export async function suspendUserAction(
  userId: string,
): Promise<UserDangerActionState> {
  const trimmedUserId = cleanUserId(userId);

  if (!trimmedUserId) {
    return {
      status: "error",
      message: "User ID is missing.",
      resetKey: `${Date.now()}`,
    };
  }

  try {
    const response = await userService.updateByUid(trimmedUserId, {
      isACActive: false,
    });

    if (response.status === "error") {
      return {
        status: "error",
        message: response.message ?? response.error ?? "User could not be suspended.",
        resetKey: `${Date.now()}`,
      };
    }

    revalidatePath("/users/suspend-delete");
    revalidatePath("/users/update");
    revalidatePath(`/users/update/${encodeURIComponent(trimmedUserId)}`);

    return {
      status: "success",
      message: response.message ?? "User suspended.",
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error, "User could not be suspended."),
      resetKey: `${Date.now()}`,
    };
  }
}

export async function deleteUserAction(
  userId: string,
): Promise<UserDangerActionState> {
  const trimmedUserId = cleanUserId(userId);

  if (!trimmedUserId) {
    return {
      status: "error",
      message: "User ID is missing.",
      resetKey: `${Date.now()}`,
    };
  }

  try {
    const response = await userService.removeByUid(trimmedUserId);

    if (response.status === "error") {
      return {
        status: "error",
        message: response.message ?? response.error ?? "User could not be deleted.",
        resetKey: `${Date.now()}`,
      };
    }

    revalidatePath("/users/suspend-delete");
    revalidatePath("/users/update");

    return {
      status: "success",
      message: response.message ?? "User deleted.",
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error, "User could not be deleted."),
      resetKey: `${Date.now()}`,
    };
  }
}
