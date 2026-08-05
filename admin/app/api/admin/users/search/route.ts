import { NextRequest, NextResponse } from "next/server";
import { userService } from "@/app/features/users/services/user-service";

export async function GET(request: NextRequest) {
  try {
    const query = request.nextUrl.searchParams.get("q")?.trim() ?? "";
    const perPageParam = request.nextUrl.searchParams.get("per_page")?.trim();
    const perPage = perPageParam ? Number(perPageParam) : 8;

    if (query.length < 2) {
      return NextResponse.json(
        { status: "error", message: "Search query must be at least 2 characters" },
        { status: 400 },
      );
    }

    const response = await userService.search({
      q: query,
      limit: Number.isFinite(perPage) ? Math.min(Math.max(perPage, 1), 20) : 8,
    });

    return NextResponse.json(response);
  } catch (error) {
    return NextResponse.json(
      {
        status: "error",
        message:
          error instanceof Error ? error.message : "Failed to search users",
      },
      { status: 500 },
    );
  }
}
