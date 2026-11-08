import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import {
  ApiError,
  tokenService,
  type RefreshTokenPayload,
} from "@/app/features/auth/services/token-service";
import {
  parseLimitedJson,
  verifySameOriginRequest,
} from "@/app/lib/route-security";

export async function POST(request: Request) {
  try {
    const blocked = verifySameOriginRequest(request);
    if (blocked) return blocked;

    const body = await parseLimitedJson<RefreshTokenPayload>(request);
    const cookieStore = await cookies();
    const refreshToken =
      body.refresh_token?.trim() ||
      cookieStore.get("zostream_admin_refresh_token")?.value?.trim() ||
      request.headers.get("x-zostream-refresh-token")?.trim() ||
      "";

    if (!refreshToken) {
      return NextResponse.json(
        { status: "error", message: "refresh_token is required" },
        { status: 400 },
      );
    }

    const response = await tokenService.refresh({
      refresh_token: refreshToken,
    });

    const nextResponse = NextResponse.json(response);

    if (
      response.status === "success" &&
      response.access_token &&
      response.refresh_token
    ) {
      const isProd = process.env.NODE_ENV === "production";

      nextResponse.cookies.set("zostream_admin_access_token", response.access_token, {
        httpOnly: true,
        sameSite: "lax",
        secure: isProd,
        path: "/",
        maxAge: 60 * 60 * 24 * 7,
      });

      nextResponse.cookies.set("zostream_admin_refresh_token", response.refresh_token, {
        httpOnly: true,
        sameSite: "lax",
        secure: isProd,
        path: "/",
        maxAge: 60 * 60 * 24 * 30,
      });

      if (response.token_type) {
        nextResponse.cookies.set("zostream_admin_token_type", response.token_type, {
          httpOnly: true,
          sameSite: "lax",
          secure: isProd,
          path: "/",
          maxAge: 60 * 60 * 24 * 30,
        });
      }
    }

    return nextResponse;
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
          error instanceof Error ? error.message : "Failed to refresh token",
      },
      { status: 500 },
    );
  }
}
