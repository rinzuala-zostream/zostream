import "server-only";

import { NextResponse } from "next/server";

const DEFAULT_JSON_MAX_BYTES = 32 * 1024;

export function secureCookieOptions(maxAge: number) {
  return {
    httpOnly: true,
    sameSite: "lax" as const,
    secure: process.env.NODE_ENV === "production",
    path: "/",
    maxAge,
  };
}

function headerHost(value: string | null) {
  if (!value) return "";

  try {
    return new URL(value).host;
  } catch {
    return "";
  }
}

export function verifySameOriginRequest(request: Request) {
  const requestUrl = new URL(request.url);
  const requestHost = request.headers.get("host") || requestUrl.host;
  const originHost = headerHost(request.headers.get("origin"));
  const refererHost = headerHost(request.headers.get("referer"));

  if (
    (originHost && originHost !== requestHost) ||
    (!originHost && refererHost && refererHost !== requestHost)
  ) {
    return NextResponse.json(
      { status: "error", message: "Cross-origin request blocked" },
      { status: 403 },
    );
  }

  return null;
}

export async function parseLimitedJson<T>(
  request: Request,
  maxBytes = DEFAULT_JSON_MAX_BYTES,
) {
  const contentLength = Number(request.headers.get("content-length") ?? 0);
  if (Number.isFinite(contentLength) && contentLength > maxBytes) {
    throw new Error(`Request body is too large. Limit is ${maxBytes} bytes.`);
  }

  const text = await request.text();
  if (new TextEncoder().encode(text).byteLength > maxBytes) {
    throw new Error(`Request body is too large. Limit is ${maxBytes} bytes.`);
  }

  return (text.trim() ? JSON.parse(text) : {}) as T;
}
