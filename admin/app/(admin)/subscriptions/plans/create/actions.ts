"use server";

import {
  ApiError,
  planService,
  type CreatePlanPayload,
  type PlanDeviceType,
  type PlanQuality,
} from "@/app/features/subscriptions/services/plan-service";

export type CreatePlanFormState = {
  status: "idle" | "success" | "error";
  message: string;
  planId?: number | string;
  resetKey?: string;
};

const initialState: CreatePlanFormState = {
  status: "idle",
  message: "",
};

const deviceTypes = ["mobile", "tv", "browser"] as const;
const qualityValues = ["SD", "HD", "FULL_HD", "4K"] as const;

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function requiredNumber(formData: FormData, key: string) {
  const value = stringValue(formData, key);
  const parsed = Number(value);
  return value && Number.isFinite(parsed) ? parsed : undefined;
}

function optionalNumberValue(value: FormDataEntryValue | undefined) {
  if (typeof value !== "string" || !value.trim()) return undefined;

  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : undefined;
}

function planDeviceType(formData: FormData): PlanDeviceType {
  const value = stringValue(formData, "device_type").toLowerCase();
  return deviceTypes.some((deviceType) => deviceType === value)
    ? (value as PlanDeviceType)
    : "mobile";
}

function planQuality(formData: FormData): PlanQuality {
  const value = stringValue(formData, "quality");
  return qualityValues.some((quality) => quality === value)
    ? (value as PlanQuality)
    : "HD";
}

function validationError(errors?: Record<string, string[]>) {
  if (!errors) return undefined;

  return Object.values(errors).flat()[0];
}

function errorMessage(error: unknown) {
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
  return "Plan could not be saved. Please try again.";
}

export async function createPlanAction(
  _previousState: CreatePlanFormState = initialState,
  formData: FormData,
): Promise<CreatePlanFormState> {
  const name = stringValue(formData, "name");
  const deviceLimit = requiredNumber(formData, "device_limit");
  const price = requiredNumber(formData, "price");
  const durationDays = requiredNumber(formData, "duration_days");

  if (!name) {
    return {
      status: "error",
      message: "Plan name is required.",
      resetKey: `${Date.now()}`,
    };
  }

  if (!deviceLimit || deviceLimit < 1) {
    return {
      status: "error",
      message: "Device limit must be at least 1.",
      resetKey: `${Date.now()}`,
    };
  }

  if (price === undefined || price < 0) {
    return {
      status: "error",
      message: "Price is required.",
      resetKey: `${Date.now()}`,
    };
  }

  if (!durationDays || durationDays < 1) {
    return {
      status: "error",
      message: "Duration must be at least 1 day.",
      resetKey: `${Date.now()}`,
    };
  }

  const payload: CreatePlanPayload = {
    name,
    device_type: planDeviceType(formData),
    device_limit: deviceLimit,
    price,
    duration_days: durationDays,
    quality: planQuality(formData),
    is_active: formData.get("is_active") === "on",
  };

  try {
    const response = await planService.create(payload);

    if (response.status === "error" || !response.data?.id) {
      return {
        status: "error",
        message:
          validationError(response.errors) ??
          response.message ??
          "Plan could not be saved.",
        resetKey: `${Date.now()}`,
      };
    }

    const planId = response.data.id;
    const featureNames = formData.getAll("feature");
    const ppvDiscount =
      optionalNumberValue(formData.get("ppv_discount") ?? undefined) ?? 0;

    const features = featureNames
      .map((feature, index) => ({
        feature: typeof feature === "string" ? feature.trim() : "",
        ppv_discount: ppvDiscount,
        sort_order: index + 1,
        is_active: true,
      }))
      .filter((feature) => feature.feature.length > 0);

    for (const feature of features) {
      await planService.createFeatureForPlan(planId, feature);
    }

    return {
      status: "success",
      message:
        features.length > 0
          ? `Plan saved with ${features.length} feature${features.length === 1 ? "" : "s"}.`
          : "Plan saved successfully.",
      planId,
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
