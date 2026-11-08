"use client";

import { useMemo, useState } from "react";
import {
  deleteOfficialClientAction,
  saveOfficialClientAction,
} from "@/app/(admin)/verification/official-clients/actions";
import type { OfficialClientConfig } from "@/app/features/official-clients/services/official-client-service";

const platforms = ["android", "ios", "android-tv", "webos", "tizen"] as const;
type Platform = (typeof platforms)[number];

const androidPackage = "com.buannel.studio.pvt.ltd.zostream";
const iosBundleId = "com.buannel.pvt.ltd.zostream.Zo-Stream";
const androidDebugSha =
  "A8:CC:A1:5F:5D:7B:4A:9C:AB:B4:C1:A2:3F:56:A2:1C:25:22:94:15:D5:E3:B5:B4:68:C8:08:B6:F6:73:27:87";
const androidReleaseSha =
  "24:A4:78:5B:B2:25:D7:39:2A:A4:19:E2:18:D9:E2:E7:46:1E:19:3A:27:C4:2D:8A:F8:41:8D:28:E0:D5:36:76";

const profile = {
  android: {
    label: "Android",
    name: "Android Mobile",
    mode: "certificate_sha256",
    appId: androidPackage,
    path: "official_client_configs/android",
    helper: "Package name + SHA-256 key hmangin verify.",
  },
  ios: {
    label: "iOS",
    name: "Native iOS",
    mode: "bundle_team",
    appId: iosBundleId,
    path: "official_client_configs/ios",
    helper: "Bundle identifier hi main check; Apple Team ID chu optional extra check.",
  },
  "android-tv": {
    label: "Android TV",
    name: "Android TV",
    mode: "certificate_sha256",
    appId: androidPackage,
    path: "official_client_configs/android-tv",
    helper: "TV package + SHA-256 key hmangin verify.",
  },
  webos: {
    label: "LG webOS",
    name: "LG webOS",
    mode: "app_id",
    appId: "zostream-webos",
    path: "official_client_configs/webos",
    helper: "App identifier hi main check; origin/build optional.",
  },
  tizen: {
    label: "Samsung",
    name: "Samsung Tizen",
    mode: "app_id",
    appId: "zostream-tizen",
    path: "official_client_configs/tizen",
    helper: "App identifier hi main check; origin/build optional.",
  },
} as const;

function toPlatform(value?: string): Platform {
  return platforms.includes(value as Platform) ? (value as Platform) : "android";
}

function certificateText(config?: OfficialClientConfig) {
  if (!config?.certificate_sha256) return "";
  return Array.isArray(config.certificate_sha256)
    ? config.certificate_sha256.join("\n")
    : config.certificate_sha256;
}

function originsText(config?: OfficialClientConfig) {
  return (config?.allowed_origins ?? []).join("\n");
}

function metadataText(config?: OfficialClientConfig) {
  if (!config?.metadata || typeof config.metadata !== "object") return "";
  return JSON.stringify(config.metadata, null, 2);
}

function certCount(config?: OfficialClientConfig) {
  return certificateText(config)
    .split(/\r?\n|,/)
    .map((item) => item.trim())
    .filter(Boolean).length;
}

const inputClass =
  "mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-200 dark:border-white/10 dark:bg-white/[0.06] dark:text-white dark:focus:ring-sky-500/25";

function Field({
  label,
  name,
  defaultValue,
  placeholder,
  hint,
}: {
  label: string;
  name: string;
  defaultValue?: string | null;
  placeholder?: string;
  hint?: string;
}) {
  return (
    <label className="block">
      <span className="text-xs font-bold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">
        {label}
      </span>
      <input
        className={inputClass}
        name={name}
        defaultValue={defaultValue ?? ""}
        placeholder={placeholder}
      />
      {hint ? (
        <span className="mt-1 block text-xs leading-5 text-slate-500 dark:text-slate-400">
          {hint}
        </span>
      ) : null}
    </label>
  );
}

function Textarea({
  label,
  name,
  defaultValue,
  placeholder,
  hint,
  rows = 3,
  mono = false,
}: {
  label: string;
  name: string;
  defaultValue?: string;
  placeholder?: string;
  hint?: string;
  rows?: number;
  mono?: boolean;
}) {
  return (
    <label className="block">
      <span className="text-xs font-bold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">
        {label}
      </span>
      <textarea
        className={`${inputClass} resize-y ${mono ? "font-mono text-xs leading-5" : ""}`}
        name={name}
        defaultValue={defaultValue ?? ""}
        placeholder={placeholder}
        rows={rows}
      />
      {hint ? (
        <span className="mt-1 block text-xs leading-5 text-slate-500 dark:text-slate-400">
          {hint}
        </span>
      ) : null}
    </label>
  );
}

function PlatformTabs({
  value,
  onChange,
}: {
  value: Platform;
  onChange: (platform: Platform) => void;
}) {
  return (
    <div className="grid gap-2 sm:grid-cols-5">
      {platforms.map((platform) => (
        <button
          key={platform}
          type="button"
          onClick={() => onChange(platform)}
          className={`rounded-xl border px-3 py-2 text-sm font-bold transition ${
            value === platform
              ? "border-sky-300 bg-sky-50 text-sky-800 ring-2 ring-sky-200 dark:border-sky-300/40 dark:bg-sky-400/10 dark:text-sky-100 dark:ring-sky-500/20"
              : "border-slate-200 bg-white/70 text-slate-600 hover:border-sky-200 dark:border-white/10 dark:bg-white/[0.04] dark:text-slate-300"
          }`}
        >
          {profile[platform].label}
        </button>
      ))}
    </div>
  );
}

function ConfigForm({ config }: { config?: OfficialClientConfig }) {
  const [platform, setPlatform] = useState<Platform>(toPlatform(config?.platform));
  const current = profile[platform];
  const isAndroid = platform === "android" || platform === "android-tv";
  const isIOS = platform === "ios";

  return (
    <form
      action={saveOfficialClientAction}
      className="rounded-2xl border border-white/70 bg-white/78 p-4 shadow-[0_18px_48px_rgba(15,23,42,0.07)] dark:border-white/10 dark:bg-white/[0.04]"
    >
      <input type="hidden" name="id" value={config?.id ?? ""} />
      <input type="hidden" name="platform" value={platform} />
      <input type="hidden" name="verification_mode" value={current.mode} />

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="text-lg font-black text-slate-950 dark:text-white">
            {config ? "Edit verification config" : "Add verification config"}
          </h3>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {current.helper}
          </p>
        </div>
        <label className="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 dark:border-white/10 dark:bg-white/[0.05] dark:text-slate-200">
          <input
            type="checkbox"
            name="enabled"
            defaultChecked={config?.enabled ?? true}
            className="size-4 accent-sky-500"
          />
          Enabled
        </label>
      </div>

      <div className="mt-4">
        <PlatformTabs value={platform} onChange={setPlatform} />
        <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
          Firebase path: <code>{current.path}</code>
        </p>
      </div>

      <div className="mt-5 grid gap-4 md:grid-cols-2">
        <Field
          label="Name"
          name="name"
          defaultValue={config?.name}
          placeholder={`${current.name} production`}
        />
        <Field
          label={isAndroid ? "Package name" : isIOS ? "Bundle identifier" : "App identifier"}
          name="app_identifier"
          defaultValue={config?.app_identifier ?? current.appId}
          placeholder={current.appId}
        />

        {isAndroid ? (
          <div className="md:col-span-2">
            <Textarea
              label="SHA-256 keys"
              name="certificate_sha256"
              defaultValue={certificateText(config)}
              placeholder={`${androidDebugSha}\n${androidReleaseSha}`}
              rows={5}
              mono
              hint="Debug, release, Play signing key te line hrangin dah theih."
            />
            <div className="mt-2 rounded-xl bg-slate-950/[0.04] p-3 text-xs leading-5 text-slate-600 dark:bg-white/[0.05] dark:text-slate-300">
              <div>
                <b>Debug:</b> <code>{androidDebugSha}</code>
              </div>
              <div className="mt-1">
                <b>Release:</b> <code>{androidReleaseSha}</code>
              </div>
            </div>
            <input type="hidden" name="build_id" value="" />
            <input type="hidden" name="allowed_origins" value="" />
          </div>
        ) : isIOS ? (
          <>
            <Field
              label="Apple Team ID (optional)"
              name="team_id"
              defaultValue={config?.team_id ?? ""}
              placeholder="Optional; e.g. 5N48USNJ2K"
              hint="Blank dah chuan bundle identifier chauh check a ni. App transfer/developer account thlak theih nan optional-a dah a him zawk."
            />
            <Field
              label="Build ID"
              name="build_id"
              defaultValue={config?.build_id}
              placeholder="optional"
            />
            <input type="hidden" name="allowed_origins" value="" />
            <input type="hidden" name="certificate_sha256" value="" />
            <input type="hidden" name="key_id" value="" />
          </>
        ) : (
          <>
            <Field
              label="Build ID"
              name="build_id"
              defaultValue={config?.build_id}
              placeholder="optional"
            />
            <div className="md:col-span-2">
              <Textarea
                label="Allowed origins"
                name="allowed_origins"
                defaultValue={originsText(config)}
                placeholder="https://zostream.in"
                hint="Origin check i hmang duh loh chuan blank dah rawh."
              />
            </div>
            <div className="md:col-span-2">
              <Textarea
                label="Certificate SHA-256 (optional)"
                name="certificate_sha256"
                defaultValue={certificateText(config)}
                placeholder="optional"
                rows={2}
                mono
              />
            </div>
          </>
        )}
      </div>

      <div className="mt-5 grid gap-4 md:grid-cols-4">
        <Field
          label="Min version"
          name="min_version"
          defaultValue={config?.min_version}
          placeholder={isAndroid ? "36.3.0" : "1.0.0"}
        />
        <Field
          label="Latest version"
          name="latest_version"
          defaultValue={config?.latest_version}
          placeholder={isAndroid ? "36.3.0" : "1.0.0"}
        />
        <Field
          label="API base URL"
          name="api_base_url"
          defaultValue={config?.api_base_url ?? "https://apis.zostream.in/api/v4"}
          placeholder="https://apis.zostream.in/api/v4"
          hint="Verification pass chuan he saved URL hi app-in 그대로 hmang ang. Local/testing URL pawh dah theih."
        />
        <Field
          label="API version"
          name="api_version"
          defaultValue={config?.api_version ?? "4"}
          placeholder="4"
        />
      </div>

      <div className="mt-5">
        <Textarea
          label="Metadata JSON"
          name="metadata"
          defaultValue={metadataText(config)}
          placeholder='{"note":"production"}'
          rows={3}
          mono
        />
      </div>

      <button
        type="submit"
        className="mt-5 rounded-full bg-slate-950 px-5 py-2.5 text-sm font-black text-white shadow-[0_12px_34px_rgba(15,23,42,0.18)] dark:bg-white dark:text-slate-950"
      >
        {config ? "Save changes" : "Create config"}
      </button>
    </form>
  );
}

function ConfigRow({ config }: { config: OfficialClientConfig }) {
  const platform = toPlatform(config.platform);

  return (
    <details className="rounded-2xl border border-slate-200 bg-white/75 p-4 dark:border-white/10 dark:bg-white/[0.04]">
      <summary className="cursor-pointer list-none">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div className="text-xs font-black uppercase tracking-[0.16em] text-slate-400">
              {profile[platform].name}
            </div>
            <h4 className="mt-1 font-black text-slate-950 dark:text-white">
              {config.name}
            </h4>
            <p className="mt-1 break-all font-mono text-xs text-slate-500 dark:text-slate-400">
              {config.app_identifier || "No identifier"}
            </p>
          </div>
          <div className="flex items-center gap-2">
            {certCount(config) > 0 ? (
              <span className="rounded-full bg-slate-950/[0.06] px-2 py-1 text-xs font-bold text-slate-600 dark:bg-white/10 dark:text-slate-300">
                {certCount(config)} SHA
              </span>
            ) : null}
            <span
              className={`rounded-full px-2.5 py-1 text-xs font-black ${
                config.enabled
                  ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-200"
                  : "bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-300"
              }`}
            >
              {config.enabled ? "Enabled" : "Disabled"}
            </span>
          </div>
        </div>
      </summary>

      <div className="mt-4 border-t border-slate-200 pt-4 dark:border-white/10">
        <ConfigForm config={config} />
        <form action={deleteOfficialClientAction} className="mt-3">
          <input type="hidden" name="id" value={config.id} />
          <button
            type="submit"
            className="rounded-full border border-red-200 bg-red-50 px-4 py-2 text-sm font-bold text-red-700 dark:border-red-400/20 dark:bg-red-500/10 dark:text-red-200"
          >
            Delete config
          </button>
        </form>
      </div>
    </details>
  );
}

export function OfficialClientManager({
  configs,
}: {
  configs: OfficialClientConfig[];
}) {
  const [activePlatform, setActivePlatform] = useState<Platform>("android");
  const visibleConfigs = useMemo(
    () => configs.filter((config) => toPlatform(config.platform) === activePlatform),
    [activePlatform, configs],
  );

  return (
    <div className="grid gap-4 xl:grid-cols-[minmax(0,1.05fr)_minmax(22rem,0.95fr)]">
      <section>
        <ConfigForm />
      </section>

      <section className="rounded-2xl border border-white/70 bg-white/58 p-4 dark:border-white/10 dark:bg-white/[0.035]">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-lg font-black text-slate-950 dark:text-white">
              Saved configs
            </h3>
            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
              Platform filter hmangin en leh edit rawh.
            </p>
          </div>
          <span className="rounded-full bg-slate-950/[0.06] px-3 py-1 text-xs font-black text-slate-600 dark:bg-white/10 dark:text-slate-300">
            {configs.length} total
          </span>
        </div>

        <div className="mt-4">
          <PlatformTabs value={activePlatform} onChange={setActivePlatform} />
        </div>

        <div className="mt-4 space-y-3">
          {visibleConfigs.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-slate-300 p-5 text-sm leading-6 text-slate-500 dark:border-white/15 dark:text-slate-400">
              Hemi platform tan config a la awm lo.
            </div>
          ) : (
            visibleConfigs.map((config) => (
              <ConfigRow key={config.id} config={config} />
            ))
          )}
        </div>
      </section>
    </div>
  );
}
