"use server";

import { revalidatePath } from "next/cache";
import {
  ApiError,
  subscriptionService,
  type UpdateSubscriptionPayload,
} from "@/app/features/subscriptions/services/subscription-service";
import { adminFormDateValue } from "@/app/lib/admin-date";

export type SubscriberMutationState = {
  status: "idle" | "success" | "error";
  message: string;
  resetKey?: string;
};

const initialState: SubscriberMutationState = {
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
  fallback = "Subscriber could not be updated. Please try again.",
) {
  if (error instanceof ApiError) return error.message;
  if (error instanceof Error) return error.message;
  return fallback;
}

export async function updateSubscriberAction(
  subscriptionId: string,
  _previousState: SubscriberMutationState = initialState,
  formData: FormData,
): Promise<SubscriberMutationState> {
  if (!subscriptionId) {
    return {
      status: "error",
      message: "Subscription ID is missing.",
      resetKey: `${Date.now()}`,
    };
  }

  const payload: UpdateSubscriptionPayload = {
    plan_id: optionalString(formData, "plan_id"),
    start_at: adminFormDateValue(formData, "start_at"),
    end_at: adminFormDateValue(formData, "end_at"),
    is_active: formData.get("is_active") === "on",
    renewed_by: optionalString(formData, "renewed_by"),
  };

  try {
    const response = await subscriptionService.update(subscriptionId, payload);

    if (response.status === "error") {
      return {
        status: "error",
        message:
          response.message ?? response.error ?? "Subscriber could not be updated.",
        resetKey: `${Date.now()}`,
      };
    }

    revalidatePath("/subscriptions/subscribers");
    revalidatePath(`/subscriptions/subscribers/edit/${subscriptionId}`);

    return {
      status: "success",
      message: response.message ?? "Subscriber updated.",
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

export async function deleteSubscriberAction(
  subscriptionId: string,
): Promise<SubscriberMutationState> {
  const trimmedSubscriptionId = subscriptionId.trim();

  if (!trimmedSubscriptionId) {
    return {
      status: "error",
      message: "Subscription ID is missing.",
      resetKey: `${Date.now()}`,
    };
  }

  try {
    const response = await subscriptionService.remove(trimmedSubscriptionId);

    if (response.status === "error") {
      return {
        status: "error",
        message:
          response.message ??
          response.error ??
          "Subscriber could not be deleted.",
        resetKey: `${Date.now()}`,
      };
    }

    revalidatePath("/subscriptions/subscribers");
    revalidatePath(`/subscriptions/subscribers/edit/${trimmedSubscriptionId}`);

    return {
      status: "success",
      message: response.message ?? "Subscriber deleted.",
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error, "Subscriber could not be deleted."),
      resetKey: `${Date.now()}`,
    };
  }
}
