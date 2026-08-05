import { NextRequest, NextResponse } from "next/server";
import { createAdminQrSession } from "@/app/features/qr/services/qr-session-service";
import {
  buildDeviceName,
  parseUserAgent,
} from "@/app/features/qr/lib/device-info";

export async function POST(request: NextRequest) {
  try {
    const userAgent = request.headers.get("user-agent") ?? "";
    const parsed = parseUserAgent(userAgent);
    const visitorId =
      request.cookies.get("zostream_admin_visitor_id")?.value ?? crypto.randomUUID();

    const created = await createAdminQrSession({
      device_type: "browser",
      device_name: buildDeviceName(parsed),
      device_id: visitorId,
      note: `os=${parsed.osName} ${parsed.osVersion}; browser=${parsed.browserName} ${parsed.browserVersion}; model=${parsed.model}`,
    });

    const response = NextResponse.json({
      status: "completed",
      token: created.token,
      expires_in: created.expires_in,
    });

    console.log(`database response: ${response}`);

    response.cookies.set("zostream_admin_visitor_id", visitorId, {
      httpOnly: false,
      sameSite: "lax",
      secure: process.env.NODE_ENV === "production",
      maxAge: 60 * 60 * 24 * 365,
      path: "/",
    });

    return response;
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "Failed to generate QR session";

    return NextResponse.json(
      {
        status: "error",
        message,
      },
      { status: 500 },
    );
  }
}
