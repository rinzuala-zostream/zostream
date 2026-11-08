import "server-only";

import { ApiError, apiClient } from "@/app/lib/api-client";

export type PushNotificationPayload = {
  title: string;
  body: string;
  image?: string;
  token?: string;
  topic?: string;
  key?: string;
  data?: Record<string, string>;
};

export type PushNotificationResponse = {
  success: boolean;
  status?: number;
  body?: unknown;
  error?: string;
};

export async function sendPushNotification(payload: PushNotificationPayload) {
  return apiClient.post<PushNotificationResponse>("/api/v4/admin/notifications/push", payload, {
    timeoutMs: 20_000,
  });
}

export { ApiError };
