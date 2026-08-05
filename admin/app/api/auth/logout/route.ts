import { NextRequest, NextResponse } from "next/server";
import { tokenService } from "@/app/features/auth/services/token-service";
import { verifySameOriginRequest } from "@/app/lib/route-security";

export async function POST(request: NextRequest) {
  const blocked = verifySameOriginRequest(request);
  if (blocked) return blocked;

  const accessToken = request.cookies.get("zostream_admin_access_token")?.value?.trim();

  if (accessToken) {
    try {
      await tokenService.revoke({ access_token: accessToken });
    } catch {
      // Continue logout even if revoke fails.
    }
  }

  const response = NextResponse.json({ status: "success" });

  for (const key of [
    "zostream_admin_uid",
    "zostream_admin_access_token",
    "zostream_admin_refresh_token",
    "zostream_admin_token_type",
  ]) {
    response.cookies.set(key, "", {
      path: "/",
      maxAge: 0,
    });
  }

  return response;
}
