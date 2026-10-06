import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import { ApiError, apiClient } from "@/app/lib/api-client";

const ANALYTICS_CONFIG_PATH = "config/analytics";

type UpdateBody = {
  enabled?: unknown;
};

function json(data: unknown, status = 200) {
  return NextResponse.json(data, {
    status,
    headers: { "Cache-Control": "no-store, max-age=0" },
  });
}

async function requireAuthenticatedAdmin() {
  const cookieStore = await cookies();
  const accessToken =
    cookieStore.get("zostream_admin_access_token")?.value?.trim() ?? "";

  if (!accessToken) {
    throw new ApiError("Not authenticated", {
      status: 401,
      method: "GET",
      url: "/api/v4/admin/home-sections",
      data: null,
    });
  }

  // This lightweight protected request lets Laravel's admin.token middleware
  // remain the authority instead of trusting the presence of a browser cookie.
  await apiClient.get("/api/v4/admin/home-sections", {
    headers: { Authorization: `Bearer ${accessToken}` },
    timeoutMs: 7_500,
  });
}

function errorResponse(error: unknown) {
  if (error instanceof ApiError) {
    return json(
      { status: "error", message: error.message },
      error.status || 500,
    );
  }

  return json(
    {
      status: "error",
      message:
        error instanceof Error
          ? error.message
          : "Firebase analytics control is unavailable.",
    },
    500,
  );
}

export async function GET() {
  try {
    await requireAuthenticatedAdmin();
    const { realtimeDb } = await import("@/app/lib/firebase-admin");
    const snapshot = await realtimeDb.ref(ANALYTICS_CONFIG_PATH).get();
    const config = snapshot.val() as
      | { enabled?: unknown; updated_at?: unknown }
      | null;

    return json({
      status: "success",
      data: {
        path: `/${ANALYTICS_CONFIG_PATH}/enabled`,
        enabled: config?.enabled === true,
        updated_at:
          typeof config?.updated_at === "string" ? config.updated_at : null,
      },
    });
  } catch (error) {
    return errorResponse(error);
  }
}

export async function PUT(request: Request) {
  try {
    await requireAuthenticatedAdmin();
    const body = (await request.json()) as UpdateBody;
    if (typeof body.enabled !== "boolean") {
      return json(
        { status: "error", message: "`enabled` must be a boolean." },
        422,
      );
    }

    const { realtimeDb } = await import("@/app/lib/firebase-admin");
    const updatedAt = new Date().toISOString();
    await realtimeDb.ref(ANALYTICS_CONFIG_PATH).update({
      enabled: body.enabled,
      updated_at: updatedAt,
    });

    return json({
      status: "success",
      message: body.enabled
        ? "Analytics collection enabled in real time."
        : "Analytics collection disabled in real time.",
      data: {
        path: `/${ANALYTICS_CONFIG_PATH}/enabled`,
        enabled: body.enabled,
        updated_at: updatedAt,
      },
    });
  } catch (error) {
    return errorResponse(error);
  }
}
