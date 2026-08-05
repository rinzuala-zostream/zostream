import "server-only";

import { realtimeDb } from "@/app/lib/firebase-admin";
import type { WarningConfig } from "@/app/features/notifications/warning-types";

const WARNING_PATH = "warning";

type FirebaseWarningConfig = {
  isCancelable?: unknown;
  isShow?: unknown;
  isShowInLatest?: unknown;
  platform?: unknown;
  txt?: unknown;
};

function toWarningPlatform(value: unknown): WarningConfig["platform"] {
  return value === "ios" || value === "android" || value === "all"
    ? value
    : "all";
}

function toWarningConfig(value: FirebaseWarningConfig): WarningConfig {
  return {
    isCancelable: value.isCancelable === true,
    isShow: value.isShow === true,
    isShowInLatest: value.isShowInLatest === true,
    platform: toWarningPlatform(value.platform),
    txt: typeof value.txt === "string" ? value.txt : "",
  };
}

export async function getWarningConfig() {
  const snapshot = await realtimeDb.ref(WARNING_PATH).get();
  const value = snapshot.val() as FirebaseWarningConfig | null;

  return value ? toWarningConfig(value) : null;
}

export async function saveWarningConfig(data: WarningConfig) {
  await realtimeDb.ref(WARNING_PATH).set({
    isCancelable: data.isCancelable,
    isShow: data.isShow,
    isShowInLatest: data.isShowInLatest,
    platform: data.platform,
    txt: data.txt,
  });
}

export async function deleteWarningConfig() {
  await realtimeDb.ref(WARNING_PATH).remove();
}
