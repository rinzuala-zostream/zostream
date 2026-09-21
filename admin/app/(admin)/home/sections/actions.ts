"use server";

import { revalidatePath } from "next/cache";
import { ApiError } from "@/app/lib/api-client";
import {
  homeSectionService,
  type HomeSectionItem,
} from "@/app/features/home-sections/services/home-section-service";

export type HomeSectionMutationState = {
  status: "idle" | "success" | "error";
  message: string;
  resetKey?: string;
};

const allowedKeys = new Set([
  "latest_update",
  "continue_watching",
  "because_you_watched",
  "top_picks_for_you",
  "similar_movies",
  "trending_now",
  "new_releases",
  "your_wishlist",
  "next_episode",
]);

export async function saveHomeSectionsAction(
  _previousState: HomeSectionMutationState,
  formData: FormData,
): Promise<HomeSectionMutationState> {
  const raw = formData.get("sections");

  try {
    const parsed = JSON.parse(typeof raw === "string" ? raw : "[]") as Array<
      Pick<HomeSectionItem, "key" | "title">
    >;
    if (!Array.isArray(parsed)) {
      throw new Error("Home section payload must be a list.");
    }
    const keys = parsed.map((section) => section.key);
    const valid =
      Array.isArray(parsed) &&
      parsed.length <= allowedKeys.size &&
      new Set(keys).size === keys.length &&
      parsed.every(
        (section) =>
          allowedKeys.has(section.key) &&
          typeof section.title === "string" &&
          section.title.trim().length > 0 &&
          section.title.trim().length <= 120,
      );

    if (!valid) {
      return {
        status: "error",
        message: "Every active section needs a valid, unique type and title.",
        resetKey: `${Date.now()}`,
      };
    }

    await homeSectionService.update(
      parsed.map((section) => ({
        key: section.key,
        title: section.title.trim(),
      })),
    );
    revalidatePath("/home/sections");

    return {
      status: "success",
      message: "Home section layout saved.",
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    return {
      status: "error",
      message:
        error instanceof ApiError || error instanceof Error
          ? error.message
          : "Home sections could not be saved.",
      resetKey: `${Date.now()}`,
    };
  }
}
