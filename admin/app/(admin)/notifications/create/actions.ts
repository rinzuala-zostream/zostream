"use server";

import {
  ApiError,
  sendPushNotification,
  type PushNotificationPayload,
} from "@/app/features/notifications/services/push-notification-service";

export type PushNotificationFormState = {
  status: "idle" | "success" | "error";
  message: string;
  responseStatus?: number;
  resetKey?: string;
};

const initialState: PushNotificationFormState = {
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

function parseDataPairs(formData: FormData) {
  const keys = formData.getAll("data_key");
  const values = formData.getAll("data_value");
  const data: Record<string, string> = {};

  keys.forEach((rawKey, index) => {
    if (typeof rawKey !== "string") return;

    const key = rawKey.trim();
    const rawValue = values[index];
    const value = typeof rawValue === "string" ? rawValue.trim() : "";

    if (key && value) {
      data[key] = value;
    }
  });

  return Object.keys(data).length > 0 ? data : undefined;
}

function actionResult(
  status: PushNotificationFormState["status"],
  message: string,
  responseStatus?: number,
): PushNotificationFormState {
  return {
    status,
    message,
    responseStatus,
    resetKey: `${Date.now()}`,
  };
}

function errorMessage(error: unknown) {
  if (error instanceof ApiError) return error.message;
  if (error instanceof Error) return error.message;
  return "Push notification could not be sent.";
}

export async function sendPushNotificationAction(
  _previousState: PushNotificationFormState = initialState,
  formData: FormData,
): Promise<PushNotificationFormState> {
  const title = stringValue(formData, "title");
  const body = stringValue(formData, "body");
  const targetMode = stringValue(formData, "target_mode") || "topic";
  const topic = optionalString(formData, "topic") ?? "all";
  const token = optionalString(formData, "token");

  if (!title) {
    return actionResult("error", "Title is required.");
  }

  if (!body) {
    return actionResult("error", "Body is required.");
  }

  if (targetMode === "token" && !token) {
    return actionResult("error", "Device token is required.");
  }

  const payload: PushNotificationPayload = {
    title,
    body,
    image: optionalString(formData, "image"),
    key: optionalString(formData, "key"),
    data: parseDataPairs(formData),
  };

  if (targetMode === "token") {
    payload.token = token;
  } else {
    payload.topic = topic;
  }

  try {
    const response = await sendPushNotification(payload);

    if (!response.success) {
      return actionResult(
        "error",
        response.error ?? "Firebase rejected the notification.",
        response.status,
      );
    }

    return actionResult(
      "success",
      "Push notification sent successfully.",
      response.status,
    );
  } catch (error) {
    return actionResult("error", errorMessage(error));
  }
}
