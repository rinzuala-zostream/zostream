"use server";

import { revalidatePath } from "next/cache";
import { ApiError } from "@/app/lib/api-client";
import {
  legalPageService,
  type LegalPagePayload,
  type LegalPageSection,
} from "@/app/features/legal/services/legal-page-service";

export type LegalPageMutationState = {
  status: "idle" | "success" | "error";
  message: string;
  resetKey?: string;
};

function value(formData: FormData, key: string) {
  const entry = formData.get(key);
  return typeof entry === "string" ? entry.trim() : "";
}

export async function saveLegalPageAction(
  _previousState: LegalPageMutationState,
  formData: FormData,
): Promise<LegalPageMutationState> {
  const id = value(formData, "id");
  const slug = value(formData, "slug");
  const title = value(formData, "title");
  const headings = formData.getAll("section_heading");
  const bodies = formData.getAll("section_body");
  const sections: LegalPageSection[] = headings
    .map((heading, index) => ({
      heading: typeof heading === "string" ? heading.trim() : "",
      body: typeof bodies[index] === "string" ? bodies[index].trim() : "",
    }))
    .filter((section) => section.heading || section.body);

  if (!slug || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
    return { status: "error", message: "Slug must use lowercase letters, numbers and hyphens.", resetKey: `${Date.now()}` };
  }
  if (!title) {
    return { status: "error", message: "Page title is required.", resetKey: `${Date.now()}` };
  }
  if (!sections.length || sections.some((section) => !section.heading || !section.body)) {
    return { status: "error", message: "Every section needs a heading and body.", resetKey: `${Date.now()}` };
  }

  const payload: LegalPagePayload = {
    slug,
    eyebrow: value(formData, "eyebrow") || null,
    title,
    effective_date: value(formData, "effective_date") || null,
    intro: value(formData, "intro") || null,
    sections,
    is_published: formData.get("is_published") === "on",
    sort_order: Number(value(formData, "sort_order")) || 0,
  };

  try {
    if (id) await legalPageService.update(id, payload);
    else await legalPageService.create(payload);

    revalidatePath("/legal/pages");
    return { status: "success", message: id ? "Legal page updated." : "Legal page created.", resetKey: `${Date.now()}` };
  } catch (error) {
    const message = error instanceof ApiError || error instanceof Error
      ? error.message
      : "Legal page could not be saved.";
    return { status: "error", message, resetKey: `${Date.now()}` };
  }
}
