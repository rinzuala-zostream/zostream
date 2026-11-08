import { NextResponse } from "next/server";
import {
  ApiError,
  VerifyOtpPayload,
  whatsappOtpService,
} from "@/app/features/auth/services/whatsapp-otp-service";
import { buildDeviceName, parseUserAgent } from "@/app/features/qr/lib/device-info";

export async function POST(request: Request) {
  try {
    const body = (await request.json()) as VerifyOtpPayload;
    if (!body.user_id?.trim() || !body.otp?.trim()) {
      return NextResponse.json(
        { status: "error", message: "user_id and otp are required" },
        { status: 400 },
      );
    }

    const userAgent = request.headers.get("user-agent") ?? "";
    const parsed = parseUserAgent(userAgent);

    const response = await whatsappOtpService.verifyOtp({
      user_id: body.user_id.trim(),
      otp: body.otp.trim(),
      device_id: body.device_id?.trim() || `web_${crypto.randomUUID()}`,
      device_type: "browser",
      device_name: body.device_name?.trim() || buildDeviceName(parsed),
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
          error instanceof Error ? error.message : "Failed to verify OTP",
      },
      { status: 500 },
    );
  }
}
