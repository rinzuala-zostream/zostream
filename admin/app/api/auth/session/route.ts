import { NextResponse } from "next/server";
import {
  parseLimitedJson,
  secureCookieOptions,
  verifySameOriginRequest,
} from "@/app/lib/route-security";

type SessionBody = {
  uid?: string;
  access_token?: string;
  refresh_token?: string;
  token_type?: string;
  expires_in?: number;
};

export async function POST(request: Request) {
  try {
    const blocked = verifySameOriginRequest(request);
    if (blocked) return blocked;

    const body = await parseLimitedJson<SessionBody>(request);
    const uid = body.uid?.trim() || "";
    const accessToken = body.access_token?.trim() || "";
    const refreshToken = body.refresh_token?.trim() || "";

    if (!uid || !accessToken || !refreshToken) {
      return NextResponse.json(
        {
          status: "error",
          message: "A complete authenticated admin session is required",
        },
        { status: 400 },
      );
    }

    const response = NextResponse.json({ status: "success" });

    response.cookies.set(
      "zostream_admin_uid",
      uid,
      secureCookieOptions(60 * 60 * 24 * 30),
    );
    response.cookies.set(
      "zostream_admin_access_token",
      accessToken,
      secureCookieOptions(60 * 60 * 24 * 7),
    );
    response.cookies.set(
      "zostream_admin_refresh_token",
      refreshToken,
      secureCookieOptions(60 * 60 * 24 * 30),
    );

    if (body.token_type) {
      response.cookies.set(
        "zostream_admin_token_type",
        body.token_type,
        secureCookieOptions(60 * 60 * 24 * 30),
      );
    }

    return response;
  } catch (error) {
    return NextResponse.json(
      {
        status: "error",
        message: error instanceof Error ? error.message : "Failed to create session",
      },
      { status: 500 },
    );
  }
}
