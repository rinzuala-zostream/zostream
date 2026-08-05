"use server";

import { revalidatePath } from "next/cache";
import {
  deleteOfficialClientConfig,
  saveOfficialClientConfig,
  type OfficialClientPayload,
} from "@/app/features/official-clients/services/official-client-service";

const PAGE_PATH = "/verification/official-clients";

function stringValue(formData: FormData, key: string) {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function nullableString(formData: FormData, key: string) {
  const value = stringValue(formData, key);
  return value || null;
}

function listValue(formData: FormData, key: string) {
  return stringValue(formData, key)
    .split(/\r?\n|,/)
    .map((value) => value.trim())
    .filter(Boolean);
}

function metadataValue(formData: FormData) {
  const raw = stringValue(formData, "metadata");
  if (!raw) return null;

  const parsed = JSON.parse(raw) as unknown;
  if (typeof parsed !== "object" || parsed === null || Array.isArray(parsed)) {
    throw new Error("Metadata must be a JSON object.");
  }

  return parsed as Record<string, unknown>;
}

export async function saveOfficialClientAction(formData: FormData) {
  const id = stringValue(formData, "id");
  const payload: OfficialClientPayload = {
    platform: stringValue(formData, "platform"),
    name: stringValue(formData, "name"),
    enabled: formData.get("enabled") === "on",
    verification_mode: stringValue(formData, "verification_mode") || "manual",
    app_identifier: nullableString(formData, "app_identifier"),
    certificate_sha256: listValue(formData, "certificate_sha256"),
    team_id: nullableString(formData, "team_id"),
    key_id: nullableString(formData, "key_id"),
    build_id: nullableString(formData, "build_id"),
    min_version: nullableString(formData, "min_version"),
    latest_version: nullableString(formData, "latest_version"),
    api_base_url: nullableString(formData, "api_base_url"),
    api_version: nullableString(formData, "api_version") ?? "4",
    allowed_origins: listValue(formData, "allowed_origins"),
    metadata: metadataValue(formData),
  };

  await saveOfficialClientConfig(payload, id || undefined);
  revalidatePath(PAGE_PATH);
}

export async function deleteOfficialClientAction(formData: FormData) {
  const id = stringValue(formData, "id");
  if (!id) return;

  await deleteOfficialClientConfig(id);
  revalidatePath(PAGE_PATH);
}
