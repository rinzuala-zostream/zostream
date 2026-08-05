"use server";

import { revalidatePath } from "next/cache";
import {
  ApiError,
  planService,
  type CreateNestedPlanFeaturePayload,
  type PlanDeviceType,
  type PlanQuality,
  type UpdatePlanFeaturePayload,
  type UpdatePlanPayload,
} from "@/app/features/subscriptions/services/plan-service";

export type PlanMutationState = {
  status: "idle" | "success" | "error";
  message: string;
  resetKey?: string;
};

const initialState: PlanMutationState = {
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

function errorMessage(
  error: unknown,
  fallback = "Plan request failed. Please try again.",
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

export async function deletePlanAction(
  planId: string,
): Promise<PlanMutationState> {
  const trimmedPlanId = planId.trim();

  if (!trimmedPlanId) {
    return {
      status: "error",
      message: "Plan ID is missing.",
      resetKey: `${Date.now()}`,
    };
  }

  try {
    const response = await planService.remove(trimmedPlanId);

    if (response.status === "error") {
      return {
        status: "error",
        message: response.message ?? "Plan could not be deleted.",
        resetKey: `${Date.now()}`,
      };
    }

    revalidatePath("/subscriptions/plans/edit");

    return {
      status: "success",
      message: response.message ?? "Plan deleted.",
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error, "Plan could not be deleted."),
      resetKey: `${Date.now()}`,
    };
  }
}

export async function updatePlanAction(
  planId: string,
  _previousState: PlanMutationState = initialState,
  formData: FormData,
): Promise<PlanMutationState> {
  const trimmedPlanId = planId.trim();
  const name = stringValue(formData, "name");
  const deviceLimit = requiredNumber(formData, "device_limit");
  const price = requiredNumber(formData, "price");
  const durationDays = requiredNumber(formData, "duration_days");

  if (!trimmedPlanId) {
    return {
      status: "error",
      message: "Plan ID is missing.",
      resetKey: `${Date.now()}`,
    };
  }

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

  const payload: UpdatePlanPayload = {
    name,
    device_type: planDeviceType(formData),
    device_limit: deviceLimit,
    price,
    duration_days: durationDays,
    quality: planQuality(formData),
    is_active: formData.get("is_active") === "on",
  };

  try {
    const response = await planService.update(trimmedPlanId, payload);

    if (response.status === "error") {
      return {
        status: "error",
        message:
          validationError(response.errors) ??
          response.message ??
          "Plan could not be updated.",
        resetKey: `${Date.now()}`,
      };
    }

    const featureIds = formData.getAll("feature_id");
    const featureNames = formData.getAll("feature");
    const ppvDiscount =
      optionalNumberValue(formData.get("ppv_discount") ?? undefined) ?? 0;
    const existingFeatureIds = formData
      .getAll("existing_feature_id")
      .filter((value): value is string => typeof value === "string")
      .map((value) => value.trim())
      .filter(Boolean);
    const submittedExistingFeatureIds = new Set<string>();

    const features = featureNames
      .map((feature, index) => {
        const id =
          typeof featureIds[index] === "string" ? featureIds[index].trim() : "";
        const featureText = typeof feature === "string" ? feature.trim() : "";

        if (id && featureText) {
          submittedExistingFeatureIds.add(id);
        }

        return {
          id,
          feature: featureText,
          ppv_discount: ppvDiscount,
          sort_order: index + 1,
          is_active: true,
        };
      })
      .filter((feature) => feature.feature.length > 0);

    for (const featureId of existingFeatureIds) {
      if (!submittedExistingFeatureIds.has(featureId)) {
        await planService.removeFeature(featureId);
      }
    }

    for (const feature of features) {
      const featurePayload: UpdatePlanFeaturePayload = {
        feature: feature.feature,
        ppv_discount: feature.ppv_discount,
        sort_order: feature.sort_order,
        is_active: feature.is_active,
      };

      if (feature.id) {
        await planService.updateFeature(feature.id, featurePayload);
      } else {
        const createFeaturePayload: CreateNestedPlanFeaturePayload = {
          feature: feature.feature,
          ppv_discount: feature.ppv_discount,
          sort_order: feature.sort_order,
          is_active: feature.is_active,
        };

        await planService.createFeatureForPlan(
          trimmedPlanId,
          createFeaturePayload,
        );
      }
    }

    revalidatePath("/subscriptions/plans/edit");
    revalidatePath(`/subscriptions/plans/edit/${trimmedPlanId}`);

    return {
      status: "success",
      message:
        features.length > 0
          ? `Plan updated with ${features.length} feature${features.length === 1 ? "" : "s"}.`
          : (response.message ?? "Plan updated."),
      resetKey: `${Date.now()}`,
    };
  } catch (error) {
    return {
      status: "error",
      message: errorMessage(error, "Plan could not be updated."),
      resetKey: `${Date.now()}`,
    };
  }
}
