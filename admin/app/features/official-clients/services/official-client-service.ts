import "server-only";

import { realtimeDb } from "@/app/lib/firebase-admin";

export type OfficialClientConfig = {
  id: string;
  platform: string;
  name: string;
  enabled: boolean;
  verification_mode: string;
  app_identifier?: string | null;
  certificate_sha256?: string | string[] | null;
  team_id?: string | null;
  key_id?: string | null;
  build_id?: string | null;
  min_version?: string | null;
  latest_version?: string | null;
  api_base_url?: string | null;
  api_version?: string | null;
  allowed_origins?: string[] | null;
  metadata?: Record<string, unknown> | null;
  created_at?: string | null;
  updated_at?: string | null;
};

export type OfficialClientPayload = {
  platform: string;
  name: string;
  enabled: boolean;
  verification_mode: string;
  app_identifier?: string | null;
  certificate_sha256?: string | string[] | null;
  team_id?: string | null;
  key_id?: string | null;
  build_id?: string | null;
  min_version?: string | null;
  latest_version?: string | null;
  api_base_url?: string | null;
  api_version?: string | null;
  allowed_origins?: string[];
  metadata?: Record<string, unknown> | null;
};

const BASE_PATH = "official_client_configs";

export async function listOfficialClientConfigs() {
  const snapshot = await realtimeDb.ref(BASE_PATH).get();
  if (!snapshot.exists()) return [];

  const value = snapshot.val() as Record<string, Record<string, Partial<OfficialClientConfig>>>;
  const configs: OfficialClientConfig[] = [];

  for (const [platform, platformConfigs] of Object.entries(value)) {
    if (!platformConfigs || typeof platformConfigs !== "object") continue;

    for (const [id, config] of Object.entries(platformConfigs)) {
      configs.push(normalizeConfig(id, platform, config));
    }
  }

  return configs.sort((a, b) =>
    `${a.platform}:${a.name}`.localeCompare(`${b.platform}:${b.name}`),
  );
}

export async function saveOfficialClientConfig(
  payload: OfficialClientPayload,
  id?: string,
) {
  const normalized = normalizePayload(payload);

  if (id) {
    await removeExistingConfig(id);
    await realtimeDb.ref(`${BASE_PATH}/${normalized.platform}/${id}`).set({
      ...normalized,
      updated_at: new Date().toISOString(),
    });
    return normalizeConfig(id, normalized.platform, normalized);
  }

  const ref = realtimeDb.ref(`${BASE_PATH}/${normalized.platform}`).push();
  const newId = ref.key;
  if (!newId) throw new Error("Could not create Firebase config key.");

  await ref.set({
    ...normalized,
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
  });

  return normalizeConfig(newId, normalized.platform, normalized);
}

export async function deleteOfficialClientConfig(id: string) {
  await removeExistingConfig(id);
  return { message: "Official client config deleted." };
}

function normalizeConfig(
  id: string,
  platform: string,
  config: Partial<OfficialClientConfig>,
): OfficialClientConfig {
  return {
    id,
    platform: config.platform || platform,
    name: config.name || "Untitled official client",
    enabled: config.enabled !== false,
    verification_mode: config.verification_mode || "manual",
    app_identifier: config.app_identifier ?? null,
    certificate_sha256: normalizeCertificateList(config.certificate_sha256),
    team_id: config.team_id ?? null,
    key_id: config.key_id ?? null,
    build_id: config.build_id ?? null,
    min_version: config.min_version ?? null,
    latest_version: config.latest_version ?? null,
    api_base_url: config.api_base_url ?? null,
    api_version: config.api_version ?? "4",
    allowed_origins: Array.isArray(config.allowed_origins)
      ? config.allowed_origins
      : [],
    metadata:
      config.metadata && typeof config.metadata === "object"
        ? config.metadata
        : null,
    created_at: config.created_at ?? null,
    updated_at: config.updated_at ?? null,
  };
}

function normalizePayload(payload: OfficialClientPayload): OfficialClientPayload {
  const apiBaseUrl = normalizeApiBaseUrl(payload.api_base_url);

  return {
    ...payload,
    platform: payload.platform.trim().toLowerCase().replace(/_/g, "-"),
    name: payload.name.trim(),
    verification_mode: payload.verification_mode || "manual",
    certificate_sha256: normalizeCertificateList(payload.certificate_sha256),
    allowed_origins: (payload.allowed_origins ?? [])
      .map((origin) => origin.trim().replace(/\/+$/, "").toLowerCase())
      .filter(Boolean),
    api_base_url: apiBaseUrl,
    api_version: payload.api_version || "4",
  };
}

function normalizeApiBaseUrl(value?: string | null) {
  const raw = value?.trim();
  return raw ? raw.replace(/\/+$/, "") : null;
}

function normalizeCertificateList(value?: string | string[] | null) {
  const values = Array.isArray(value)
    ? value
    : typeof value === "string"
      ? value.split(/\r?\n|,/)
      : [];

  const normalized = values
    .map((item) => item.replace(/[^a-fA-F0-9]/g, "").toUpperCase())
    .filter(Boolean);

  return Array.from(new Set(normalized));
}

async function removeExistingConfig(id: string) {
  const snapshot = await realtimeDb.ref(BASE_PATH).get();
  if (!snapshot.exists()) return;

  const value = snapshot.val() as Record<string, Record<string, unknown>>;
  const updates: Record<string, null> = {};

  for (const [platform, platformConfigs] of Object.entries(value)) {
    if (platformConfigs && Object.prototype.hasOwnProperty.call(platformConfigs, id)) {
      updates[`${platform}/${id}`] = null;
    }
  }

  if (Object.keys(updates).length > 0) {
    await realtimeDb.ref(BASE_PATH).update(updates);
  }
}
