"use server";

import { revalidatePath } from "next/cache";
import {
  appUpdatePlatforms,
  type AppUpdatePlatform,
} from "@/app/features/notifications/app-update-types";
import {
  deleteAppUpdateConfig,
  saveAppUpdateConfig,
} from "@/app/features/notifications/services/app-update-service";

const PAGE_PATH = "/notifications/app-update/manage";

export type AppUpdateFormState = {
  status: "idle" | "success" | "error";
  message: string;
  resetKey?: string;
};

function result(
  status: AppUpdateFormState["status"],
  message: string,
): AppUpdateFormState {
  return { status, message, resetKey: `${Date.now()}` };
}

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function platformValue(formData: FormData): AppUpdatePlatform | null {
  const value = stringValue(formData, "platform") as AppUpdatePlatform;
  return appUpdatePlatforms.includes(value) ? value : null;
}

function errorMessage(error: unknown, fallback: string) {
  return error instanceof Error ? error.message : fallback;
}

export async function saveAppUpdateAction(
  formData: FormData,
): Promise<AppUpdateFormState> {
  const platform = platformValue(formData);
  if (!platform) return result("error", "Invalid update table.");

  const rawVersion = stringValue(formData, "version");
  const numericVersion = platform === "update" || platform === "ios_update" || platform === "tv_update";
  if (numericVersion && !rawVersion) {
    return result("error", "Version is required.");
  }

  const parsedVersion = numericVersion ? Number(rawVersion) : rawVersion;
  if (numericVersion && (!Number.isInteger(parsedVersion) || Number(parsedVersion) < 0)) {
    return result("error", "Version must be a non-negative whole number.");
  }

  try {
    await saveAppUpdateConfig({
      platform,
      enabled: formData.get("enabled") === "on",
      force: formData.get("force") === "on",
      url: stringValue(formData, "url"),
      version: parsedVersion,
    });
    revalidatePath(PAGE_PATH);
    return result("success", `${platform} saved.`);
  } catch (error) {
    return result("error", errorMessage(error, "App update could not be saved."));
  }
}

export async function deleteAppUpdateAction(
  formData: FormData,
): Promise<AppUpdateFormState> {
  const platform = platformValue(formData);
  if (!platform) return result("error", "Invalid update table.");

  try {
    await deleteAppUpdateConfig(platform);
    revalidatePath(PAGE_PATH);
    return result("success", `${platform} deleted.`);
  } catch (error) {
    return result("error", errorMessage(error, "App update could not be deleted."));
  }
}
