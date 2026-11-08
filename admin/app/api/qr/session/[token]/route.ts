import { NextRequest, NextResponse } from "next/server";
import { getFirebaseQrSession } from "@/app/features/qr/services/firebase-qr-session-service";

type RouteContext = {
  params: Promise<{
    token: string;
  }>;
};

export async function GET(_request: NextRequest, context: RouteContext) {
  try {
    const { token } = await context.params;
    const session = await getFirebaseQrSession(token);

    if (!session) {
      return NextResponse.json(
        {
          status: "error",
          message: "Session not found",
        },
        { status: 404 },
      );
    }

    return NextResponse.json({
      status: "success",
      data: session,
    });
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "Failed to read QR session";
    const status = message === "Invalid QR session token" ? 400 : 500;

    return NextResponse.json(
      {
        status: "error",
        message,
      },
      { status },
    );
  }
}
