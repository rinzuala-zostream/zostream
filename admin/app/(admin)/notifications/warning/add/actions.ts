"use server";

import { revalidatePath } from "next/cache";
import {
  deleteWarningConfig,
  saveWarningConfig,
} from "@/app/features/notifications/services/warning-service";

const WARNING_PAGE_PATH = "/notifications/warning/add";

export type WarningFormState = {
  status: "idle" | "success" | "error";
  message: string;
  resetKey?: string;
};

const initialState: WarningFormState = {
  status: "idle",
  message: "",
};

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function checkedValue(formData: FormData, key: string) {
  return formData.get(key) === "on";
}

function platformValue(formData: FormData) {
  const value = stringValue(formData, "platform");
  return value === "ios" || value === "android" || value === "all"
    ? value
    : "all";
}

function actionResult(
  status: WarningFormState["status"],
  message: string,
): WarningFormState {
  return {
    status,
    message,
    resetKey: `${Date.now()}`,
  };
}

function errorMessage(error: unknown, fallback: string) {
  if (error instanceof Error) return error.message;
  return fallback;
}

export async function saveWarningAction(
  _previousState: WarningFormState = initialState,
  formData: FormData,
): Promise<WarningFormState> {
  const txt = stringValue(formData, "txt");

  if (!txt) {
    return actionResult("error", "HTML/text is required.");
  }

  try {
    await saveWarningConfig({
      isCancelable: checkedValue(formData, "isCancelable"),
      isShow: checkedValue(formData, "isShow"),
      isShowInLatest: checkedValue(formData, "isShowInLatest"),
      platform: platformValue(formData),
      txt,
    });

    revalidatePath(WARNING_PAGE_PATH);

    return actionResult("success", "Warning saved.");
  } catch (error) {
    return actionResult(
      "error",
      errorMessage(error, "Warning could not be saved."),
    );
  }
}

export async function deleteWarningAction(): Promise<WarningFormState> {
  try {
    await deleteWarningConfig();
    revalidatePath(WARNING_PAGE_PATH);

    return actionResult("success", "Warning deleted.");
  } catch (error) {
    return actionResult(
      "error",
      errorMessage(error, "Warning could not be deleted."),
    );
  }
}
