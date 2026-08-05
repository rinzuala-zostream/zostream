"use server";

import {
  ApiError,
  whatsappOtpService,
  type RequestOtpPayload,
} from "@/app/features/auth/services/whatsapp-otp-service";

export type RequestOtpFormState = {
  status: "idle" | "success" | "error";
  message: string;
  response?: unknown;
  otp?: string | null;
  resetKey?: string;
};

const initialState: RequestOtpFormState = {
  status: "idle",
  message: "",
};

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function errorMessage(error: unknown) {
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
  return "OTP request failed.";
}

export async function requestOtpAction(
  _previousState: RequestOtpFormState = initialState,
  formData: FormData,
): Promise<RequestOtpFormState> {
  const userId = stringValue(formData, "user_id");
  const phoneNumber = stringValue(formData, "phone_number");

  if (!userId && !phoneNumber) {
    return {
      status: "error",
      message: "Enter a user ID or phone number.",
      resetKey: `${Date.now()}`,
    };
  }

  const payload: RequestOtpPayload = {
    phone_number: phoneNumber,
    user_id: userId || undefined,
  };

  try {
    const response = await whatsappOtpService.requestOtp(payload);
    const isError = response.status === "error";

    return {
      status: isError ? "error" : "success",
      message:
        response.message ??
        (isError ? "OTP could not be requested." : "OTP requested."),
      response,
      otp: response.otp ?? null,
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error),
      response: error instanceof ApiError ? error.data : undefined,
      resetKey: `${Date.now()}`,
    };
  }
}
