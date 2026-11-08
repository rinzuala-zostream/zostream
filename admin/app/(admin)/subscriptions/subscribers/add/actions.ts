"use server";

import {
  ApiError,
  subscriptionService,
  type CreateSubscriptionWithPaymentPayload,
  type CreateSubscriptionWithPaymentResponse,
} from "@/app/features/subscriptions/services/subscription-service";
import { adminFormDateValue } from "@/app/lib/admin-date";

export type AddSubscriberFormState = {
  status: "idle" | "success" | "error";
  message: string;
  transactionId?: string;
  resolvedUserId?: string;
  resetKey?: string;
};

const initialState: AddSubscriberFormState = {
  status: "idle",
  message: "",
};

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function optionalNumber(formData: FormData, key: string) {
  const value = stringValue(formData, key);
  if (!value) return undefined;

  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : undefined;
}

function stringValues(formData: FormData, key: string) {
  const values = formData
    .getAll(key)
    .map((value) => (typeof value === "string" ? value.trim() : ""))
    .filter(Boolean);

  return Array.from(new Set(values));
}

function errorMessage(
  error: unknown,
  fallback = "Subscriber could not be added. Please try again.",
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

export async function addSubscriberAction(
  _previousState: AddSubscriberFormState = initialState,
  formData: FormData,
): Promise<AddSubscriberFormState> {
  const userId = stringValue(formData, "user_id");
  const planIds = stringValues(formData, "plan_id");

  if (!userId) {
    return {
      status: "error",
      message: "User ID is required.",
      resetKey: `${Date.now()}`,
    };
  }

  if (planIds.length === 0) {
    return {
      status: "error",
      message: "Choose at least one plan.",
      resetKey: `${Date.now()}`,
    };
  }

  const paymentMethod = stringValue(formData, "payment_method") || undefined;
  const paymentGateway = stringValue(formData, "payment_gateway") || undefined;
  const transactionId = stringValue(formData, "transaction_id") || undefined;
  const currency = stringValue(formData, "currency") || "INR";
  const amount = optionalNumber(formData, "amount");
  const startAt = adminFormDateValue(formData, "start_at");
  const endAt = adminFormDateValue(formData, "end_at");

  if (startAt && endAt && endAt < startAt) {
    return {
      status: "error",
      message: "End date cannot be earlier than start date.",
      resetKey: `${Date.now()}`,
    };
  }

  try {
    const results: CreateSubscriptionWithPaymentResponse[] = [];

    for (const planId of planIds) {
      const payload: CreateSubscriptionWithPaymentPayload = {
        user_id: userId,
        plan_id: planId,
        payment_method: paymentMethod,
        payment_gateway: paymentGateway,
        transaction_id:
          planIds.length > 1 && transactionId
            ? `${transactionId}-${planId}`
            : transactionId,
        currency,
        amount,
        start_at: startAt,
        end_at: endAt,
        payment_type: "new",
        status: "success",
      };

      const response = await subscriptionService.createWithPayment(payload);

      if (response.status === "error") {
        return {
          status: "error",
          message:
            response.message ??
            response.error ??
            `Subscriber could not be added for plan ${planId}.`,
          resetKey: `${Date.now()}`,
        };
      }

      results.push(response);
    }

    const transactionIds = results
      .map((response) =>
        typeof response.data?.payment_history === "object" &&
        response.data.payment_history !== null
          ? String(response.data.payment_history.transaction_id ?? "")
          : "",
      )
      .filter(Boolean);
    const resolvedUserId =
      typeof results[0]?.data?.resolved_user_id === "string"
        ? results[0].data.resolved_user_id
        : "";

    return {
      status: "success",
      message:
        planIds.length > 1
          ? `${planIds.length} subscriber subscriptions, payment histories, and stream states created.`
          : (results[0]?.message ??
            "Subscriber subscription, payment history, and stream state created."),
      transactionId: transactionIds.join(", "),
      resolvedUserId,
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
