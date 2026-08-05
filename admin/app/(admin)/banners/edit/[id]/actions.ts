"use server";

import { revalidatePath } from "next/cache";
import {
  ApiError,
  bannerService,
  type BannerAgeRating,
  type BannerMediaType,
  type BannerTargetType,
  type BannerType,
  type UpdateBannerPayload,
} from "@/app/features/banners/services/banner-service";

export type EditBannerFormState = {
  status: "idle" | "success" | "error";
  message: string;
  resetKey?: string;
};

const initialState: EditBannerFormState = {
  status: "idle",
  message: "",
};

const bannerTypes = ["movie", "ad", "external", "category", "custom"] as const;
const mediaTypes = ["image", "video"] as const;
const targetTypes = [
  "movie",
  "series",
  "episode",
  "url",
  "category",
  "none",
] as const;
const ageRatings = ["G", "PG", "PG13", "R", "18+", "21+"] as const;

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function optionalString(formData: FormData, key: string) {
  const value = stringValue(formData, key);
  return value ? value : null;
}

function optionalNumber(formData: FormData, key: string) {
  const value = stringValue(formData, key);
  if (!value) return null;

  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
}

function bannerType(formData: FormData): BannerType {
  const value = stringValue(formData, "type");
  return bannerTypes.some((type) => type === value)
    ? (value as BannerType)
    : "custom";
}

function mediaType(formData: FormData): BannerMediaType {
  const value = stringValue(formData, "media_type");
  return mediaTypes.some((type) => type === value)
    ? (value as BannerMediaType)
    : "image";
}

function targetType(formData: FormData): BannerTargetType {
  const value = stringValue(formData, "target_type");
  return targetTypes.some((type) => type === value)
    ? (value as BannerTargetType)
    : "none";
}

function optionalAgeRating(formData: FormData): BannerAgeRating | null {
  const value = stringValue(formData, "age_rating");
  return ageRatings.some((rating) => rating === value)
    ? (value as BannerAgeRating)
    : null;
}

function validationError(errors?: Record<string, string[]>) {
  if (!errors) return undefined;
  return Object.values(errors).flat()[0];
}

function errorMessage(
  error: unknown,
  fallback = "Banner could not be updated. Please try again.",
) {
  if (error instanceof ApiError) {
    if (
      error.status === 401 ||
      (typeof error.data === "object" &&
        error.data !== null &&
        "message" in error.data &&
        String((error.data as { message?: string }).message)
          .toLowerCase()
          .includes("invalid api key"))
    ) {
      return "Your admin session is missing or is not authorized.";
    }

    return error.message;
  }

  if (error instanceof Error) return error.message;
  return fallback;
}

export async function updateBannerAction(
  bannerId: string,
  _previousState: EditBannerFormState = initialState,
  formData: FormData,
): Promise<EditBannerFormState> {
  const mediaUrl = stringValue(formData, "media_url");

  if (!mediaUrl) {
    return {
      status: "error",
      message: "Media URL is required.",
      resetKey: `${Date.now()}`,
    };
  }

  const payload: UpdateBannerPayload = {
    title: optionalString(formData, "title"),
    description: optionalString(formData, "description"),
    type: bannerType(formData),
    media_type: mediaType(formData),
    media_url: mediaUrl,
    thumbnail_url: optionalString(formData, "thumbnail_url"),
    target_type: targetType(formData),
    target_id: optionalString(formData, "target_id"),
    target_url: optionalString(formData, "target_url"),
    priority: optionalNumber(formData, "priority"),
    is_active: formData.get("is_active") === "on",
    age_restriction_enabled:
      formData.get("age_restriction_enabled") === "on",
    min_age: optionalNumber(formData, "min_age"),
    max_age: optionalNumber(formData, "max_age"),
    age_rating: optionalAgeRating(formData),
    requires_parental_pin: formData.get("requires_parental_pin") === "on",
    start_date: optionalString(formData, "start_date"),
    end_date: optionalString(formData, "end_date"),
    button_text: optionalString(formData, "button_text"),
  };

  try {
    const response = await bannerService.update(bannerId, payload);

    if (!response.status) {
      return {
        status: "error",
        message:
          validationError(response.errors) ??
          response.message ??
          response.error ??
          "Banner could not be updated.",
        resetKey: `${Date.now()}`,
      };
    }

    revalidatePath("/banners/edit");
    revalidatePath(`/banners/edit/${bannerId}`);

    return {
      status: "success",
      message: response.message ?? "Banner updated successfully.",
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error),
      resetKey: `${Date.now()}`,
    };
  }
}

export async function deleteBannerAction(
  bannerId: string,
): Promise<EditBannerFormState> {
  const trimmedBannerId = bannerId.trim();

  if (!trimmedBannerId) {
    return {
      status: "error",
      message: "Banner ID is missing.",
      resetKey: `${Date.now()}`,
    };
  }

  try {
    const response = await bannerService.remove(trimmedBannerId);

    if (!response.status) {
      return {
        status: "error",
        message:
          response.message ?? response.error ?? "Banner could not be deleted.",
        resetKey: `${Date.now()}`,
      };
    }

    revalidatePath("/banners/edit");
    revalidatePath(`/banners/edit/${trimmedBannerId}`);

    return {
      status: "success",
      message: response.message ?? "Banner deleted.",
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error, "Banner could not be deleted."),
      resetKey: `${Date.now()}`,
    };
  }
}
