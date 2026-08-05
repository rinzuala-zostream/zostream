import "server-only";

import { realtimeDb } from "@/app/lib/firebase-admin";
import {
  appUpdatePlatforms,
  type AppUpdateConfig,
  type AppUpdatePlatform,
} from "@/app/features/notifications/app-update-types";

const versionFields: Record<AppUpdatePlatform, "v_code" | "version" | "v"> = {
  update: "v_code",
  ios_update: "v_code",
  lg_tv_update: "version",
  sam_tv_update: "version",
  tv_update: "v",
};

function assertPlatform(value: string): asserts value is AppUpdatePlatform {
  if (!appUpdatePlatforms.includes(value as AppUpdatePlatform)) {
    throw new Error("Invalid app update platform.");
  }
}

export async function listAppUpdateConfigs() {
  const snapshots = await Promise.all(
    appUpdatePlatforms.map((platform) => realtimeDb.ref(platform).get()),
  );

  return snapshots.flatMap((snapshot, index): AppUpdateConfig[] => {
    if (!snapshot.exists()) return [];

    const platform = appUpdatePlatforms[index];
    const value = snapshot.val() as Record<string, unknown>;
    const version = value[versionFields[platform]];

    return [{
      platform,
      enabled: value.enabled !== false,
      force: value.force === true,
      url: typeof value.url === "string" ? value.url : "",
      version:
        typeof version === "number" || typeof version === "string"
          ? version
          : platform === "update" || platform === "ios_update" || platform === "tv_update"
            ? 0
            : "",
    }];
  });
}

export async function saveAppUpdateConfig(config: AppUpdateConfig) {
  assertPlatform(config.platform);

  await realtimeDb.ref(config.platform).set({
    enabled: config.enabled,
    force: config.force,
    url: config.url,
    [versionFields[config.platform]]: config.version,
  });
}

export async function deleteAppUpdateConfig(platform: string) {
  assertPlatform(platform);
  await realtimeDb.ref(platform).remove();
}
