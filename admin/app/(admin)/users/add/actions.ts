"use server";

import {
  ApiError,
  userService,
  type CreateUserInput,
} from "@/app/features/users/services/user-service";
import { adminFormDateValue } from "@/app/lib/admin-date";

export type AddUserFormState = {
  status: "idle" | "success" | "error";
  message: string;
  uid?: string;
  resetKey?: string;
};

const initialState: AddUserFormState = {
  status: "idle",
  message: "",
};

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function optionalString(formData: FormData, key: string) {
  const value = stringValue(formData, key);
  return value || null;
}

function checkboxValue(formData: FormData, key: string) {
  return formData.get(key) === "on";
}

function errorMessage(
  error: unknown,
  fallback = "User could not be created. Please try again.",
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

export async function addUserAction(
  _previousState: AddUserFormState = initialState,
  formData: FormData,
): Promise<AddUserFormState> {
  const mail = stringValue(formData, "mail");
  const authPhone = stringValue(formData, "auth_phone");
  const uid = stringValue(formData, "uid");

  if (!mail && !authPhone && !uid) {
    return {
      status: "error",
      message: "Enter a UID, email, or auth phone number.",
      resetKey: `${Date.now()}`,
    };
  }

  const payload: CreateUserInput = {
    uid: uid || null,
    mail: mail || null,
    name: optionalString(formData, "name"),
    call: optionalString(formData, "call"),
    auth_phone: authPhone || null,
    img: optionalString(formData, "img"),
    dob: adminFormDateValue(formData, "dob") ?? null,
    khua: optionalString(formData, "khua"),
    veng: optionalString(formData, "veng"),
    device_id: optionalString(formData, "device_id"),
    device_name: optionalString(formData, "device_name"),
    token: optionalString(formData, "token"),
    is_auth_phone_active: checkboxValue(formData, "is_auth_phone_active"),
    isACActive: checkboxValue(formData, "isACActive"),
    isAccountComplete: checkboxValue(formData, "isAccountComplete"),
  };

  try {
    const response = await userService.create(payload);

    if (response.status === "error") {
      return {
        status: "error",
        message: response.message ?? response.error ?? "User could not be created.",
        resetKey: `${Date.now()}`,
      };
    }

    const createdUid =
      typeof response.data?.uid === "string" && response.data.uid
        ? response.data.uid
        : uid;

    return {
      status: "success",
      message: response.message ?? "User created successfully.",
      uid: createdUid,
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
