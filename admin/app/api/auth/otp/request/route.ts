import { NextResponse } from "next/server";
import {
  RequestOtpPayload,
  ApiError,
  whatsappOtpService,
} from "@/app/features/auth/services/whatsapp-otp-service";

export async function POST(request: Request) {
  try {
    const body = (await request.json()) as RequestOtpPayload;

    if (!body.phone_number?.trim()) {
      return NextResponse.json(
        { status: "error", message: "phone_number is required" },
        { status: 400 },
      );
    }

    const response = await whatsappOtpService.requestAdminOtp({
      phone_number: body.phone_number.trim(),
      country_code: body.country_code?.trim() || undefined,
      user_id: body.user_id?.trim() || undefined,
    });

    return NextResponse.json(response);
  } catch (error) {
    if (error instanceof ApiError) {
      const message =
        typeof error.data === "object" &&
        error.data !== null &&
        "message" in error.data
          ? String((error.data as { message?: string }).message)
          : error.message;

      return NextResponse.json(
        { status: "error", message },
        { status: error.status || 500 },
      );
    }

    return NextResponse.json(
      {
        status: "error",
        message:
          error instanceof Error ? error.message : "Failed to request OTP",
      },
      { status: 500 },
    );
  }
}
