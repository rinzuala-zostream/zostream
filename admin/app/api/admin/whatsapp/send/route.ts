import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import { ApiError, apiClient } from "@/app/lib/api-client";

type AdminWhatsAppBody = {
  to?: string;
  type?: "template" | "text";
  template_name?: string;
  template_params?: string[];
  language?: string;
  message?: string;
};

export async function POST(request: Request) {
  try {
    const body = (await request.json()) as AdminWhatsAppBody;
    const cookieStore = await cookies();
    const accessToken =
      cookieStore.get("zostream_admin_access_token")?.value?.trim() ?? "";

    if (!accessToken) {
      return NextResponse.json(
        { status: "error", message: "Not authenticated" },
        { status: 401 },
      );
    }

    if (!body.to?.trim() || !body.type) {
      return NextResponse.json(
        { status: "error", message: "`to` and `type` are required" },
        { status: 400 },
      );
    }

    const response = await apiClient.post(
      "/api/v4/admin/whatsapp/send",
      {
        to: body.to.trim(),
        type: body.type,
        template_name: body.template_name?.trim() || undefined,
        template_params: body.template_params,
        language: body.language?.trim() || undefined,
        message: body.message?.trim() || undefined,
      },
      {
        headers: {
          Authorization: `Bearer ${accessToken}`,
        },
      },
    );

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
          error instanceof Error
            ? error.message
            : "Failed to send WhatsApp message",
      },
      { status: 500 },
    );
  }
}
