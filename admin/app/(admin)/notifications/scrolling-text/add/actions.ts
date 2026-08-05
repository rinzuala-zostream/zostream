"use server";

import { revalidatePath } from "next/cache";
import {
  createTextScrollItem,
  deleteTextScrollItem,
  updateTextScrollItem,
} from "@/app/features/notifications/services/text-scroll-service";

const TEXT_SCROLL_PAGE_PATH = "/notifications/scrolling-text/add";

export type TextScrollFormState = {
  status: "idle" | "success" | "error";
  message: string;
  resetKey?: string;
};

const initialState: TextScrollFormState = {
  status: "idle",
  message: "",
};

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function showValue(formData: FormData) {
  return formData.get("show") === "on";
}

function actionResult(
  status: TextScrollFormState["status"],
  message: string,
): TextScrollFormState {
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

export async function addTextScrollAction(
  _previousState: TextScrollFormState = initialState,
  formData: FormData,
): Promise<TextScrollFormState> {
  const text = stringValue(formData, "text");

  if (!text) {
    return actionResult("error", "Scrolling text is required.");
  }

  try {
    await createTextScrollItem({
      text,
      show: showValue(formData),
    });

    revalidatePath(TEXT_SCROLL_PAGE_PATH);

    return actionResult("success", "Scrolling text added.");
  } catch (error) {
    return actionResult(
      "error",
      errorMessage(error, "Scrolling text could not be added."),
    );
  }
}

export async function updateTextScrollAction(
  formData: FormData,
): Promise<TextScrollFormState> {
  const id = stringValue(formData, "id");
  const text = stringValue(formData, "text");

  if (!id) {
    return actionResult("error", "Scrolling text ID is missing.");
  }

  if (!text) {
    return actionResult("error", "Scrolling text is required.");
  }

  try {
    await updateTextScrollItem(id, {
      text,
      show: showValue(formData),
    });

    revalidatePath(TEXT_SCROLL_PAGE_PATH);

    return actionResult("success", "Scrolling text updated.");
  } catch (error) {
    return actionResult(
      "error",
      errorMessage(error, "Scrolling text could not be updated."),
    );
  }
}

export async function deleteTextScrollAction(
  formData: FormData,
): Promise<TextScrollFormState> {
  const id = stringValue(formData, "id");

  if (!id) {
    return actionResult("error", "Scrolling text ID is missing.");
  }

  try {
    await deleteTextScrollItem(id);
    revalidatePath(TEXT_SCROLL_PAGE_PATH);

    return actionResult("success", "Scrolling text deleted.");
  } catch (error) {
    return actionResult(
      "error",
      errorMessage(error, "Scrolling text could not be deleted."),
    );
  }
}
