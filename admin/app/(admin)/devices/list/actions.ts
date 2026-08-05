"use server";

import { revalidatePath } from "next/cache";
import { ApiError, deviceService } from "@/app/features/devices/services/device-service";

export type DeviceActionState = {
  status: "success" | "error";
  message: string;
};

function messageFrom(error: unknown, fallback: string) {
  if (error instanceof ApiError || error instanceof Error) return error.message;
  return fallback;
}

export async function deleteDeviceAction(id: string | number): Promise<DeviceActionState> {
  try {
    const response = await deviceService.remove(id);
    if (response.status === "error") {
      return { status: "error", message: response.message ?? "Device could not be deleted." };
    }
    revalidatePath("/devices/list");
    return { status: "success", message: response.message ?? "Device deleted successfully." };
  } catch (error) {
    return { status: "error", message: messageFrom(error, "Device could not be deleted.") };
  }
}

export async function updateDeviceAction(
  id: string | number,
  payload: { device_name?: string; device_type?: string; status?: "active" | "inactive" | "blocked" },
): Promise<DeviceActionState> {
  try {
    const response = await deviceService.update(id, payload);
    if (response.status === "error") {
      return { status: "error", message: response.message ?? "Device could not be updated." };
    }
    revalidatePath("/devices/list");
    return { status: "success", message: response.message ?? "Device updated successfully." };
  } catch (error) {
    return { status: "error", message: messageFrom(error, "Device could not be updated.") };
  }
}
