"use client";

import { useState, useTransition } from "react";
import { Edit3, Plus, Save } from "lucide-react";
import { useRouter } from "next/navigation";
import { toast } from "react-toastify";
import { saveAppUpdateAction } from "@/app/(admin)/notifications/app-update/manage/actions";
import {
  appUpdatePlatforms,
  type AppUpdateConfig,
  type AppUpdatePlatform,
} from "@/app/features/notifications/app-update-types";

const platformDetails: Record<AppUpdatePlatform, { label: string; versionLabel: string }> = {
  update: { label: "Android Mobile", versionLabel: "Version code (v_code)" },
  ios_update: { label: "iOS", versionLabel: "Version code (v_code)" },
  lg_tv_update: { label: "LG TV", versionLabel: "Version" },
  sam_tv_update: { label: "Samsung TV", versionLabel: "Version" },
  tv_update: { label: "Android TV", versionLabel: "Version code (v)" },
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";

export function AppUpdateManager({ configs }: { configs: AppUpdateConfig[] }) {
  const router = useRouter();
  const [editing, setEditing] = useState<AppUpdatePlatform | null>(null);
  const [isPending, startTransition] = useTransition();
  const configMap = new Map(configs.map((config) => [config.platform, config]));

  function runAction(action: typeof saveAppUpdateAction, formData: FormData) {
    startTransition(async () => {
      const response = await action(formData);
      if (response.status === "success") {
        toast.success(response.message);
      } else {
        toast.error(response.message);
      }
      if (response.status === "success") {
        setEditing(null);
        router.refresh();
      }
    });
  }

  return (
    <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
      <div className="mb-5 flex items-center justify-between gap-3">
        <div>
          <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">Realtime Database</p>
          <h2 className="text-lg font-bold text-slate-950 dark:text-white">Update tables</h2>
        </div>
        <span className="rounded-md border border-black/10 bg-white/55 px-3 py-1.5 text-xs font-bold text-slate-600 dark:border-white/10 dark:bg-white/8 dark:text-slate-300">{configs.length} of {appUpdatePlatforms.length} configured</span>
      </div>

      <div className="grid gap-4 xl:grid-cols-2">
        {appUpdatePlatforms.map((platform) => {
          const config = configMap.get(platform);
          const isEditing = editing === platform || !config;
          const details = platformDetails[platform];
          const numericVersion = platform === "update" || platform === "ios_update" || platform === "tv_update";

          return (
            <form
              key={platform}
              action={(formData) => runAction(saveAppUpdateAction, formData)}
              className="rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/58 p-4 shadow-[0_10px_28px_rgba(15,23,42,0.08)] dark:border-white/10 dark:bg-white/7"
            >
              <input type="hidden" name="platform" value={platform} />
              <div className="mb-4 flex items-start justify-between gap-3">
                <div>
                  <h3 className="font-bold text-slate-950 dark:text-white">{details.label}</h3>
                  <code className="text-xs font-semibold text-teal-700 dark:text-cyan-200">{platform}</code>
                </div>
                <span className={`rounded-md px-2.5 py-1 text-xs font-bold ${!config ? "bg-amber-100 text-amber-800 dark:bg-amber-300/15 dark:text-amber-100" : config.enabled ? "bg-teal-100 text-teal-800 dark:bg-cyan-300/15 dark:text-cyan-100" : "bg-slate-200 text-slate-700 dark:bg-white/10 dark:text-slate-300"}`}>
                  {!config ? "Not added" : config.enabled ? "Enabled" : "Disabled"}
                </span>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <label className="sm:col-span-2 flex min-h-12 items-center justify-between gap-3 rounded-md border border-black/10 bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 dark:border-white/10 dark:bg-white/6 dark:text-slate-200">
                  <span>
                    Enable update prompt
                    <small className="mt-1 block text-xs font-medium text-slate-500 dark:text-slate-400">
                      Disable during app review so apps will not show update dialogs.
                    </small>
                  </span>
                  <input name="enabled" type="checkbox" defaultChecked={config?.enabled ?? true} disabled={!isEditing} className="size-5 accent-teal-600" />
                </label>
                <label className="sm:col-span-2">
                  <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">Update URL</span>
                  <input name="url" type="url" defaultValue={config?.url ?? ""} disabled={!isEditing} placeholder="https://..." className={inputClassName} />
                </label>
                <label>
                  <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">{details.versionLabel}</span>
                  <input name="version" type={numericVersion ? "number" : "text"} min={numericVersion ? 0 : undefined} step={numericVersion ? 1 : undefined} required={numericVersion} defaultValue={config?.version ?? ""} disabled={!isEditing} className={inputClassName} />
                </label>
                <label className="mt-7 flex min-h-12 items-center justify-between gap-3 rounded-md border border-black/10 bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 dark:border-white/10 dark:bg-white/6 dark:text-slate-200">
                  <span>Force update</span>
                  <input name="force" type="checkbox" defaultChecked={config?.force ?? false} disabled={!isEditing} className="size-5 accent-teal-600" />
                </label>
              </div>

              <div className="mt-4 flex justify-end gap-2">
                {isEditing ? (
                  <>
                    {config && <button type="button" onClick={() => setEditing(null)} className="min-h-11 rounded-md border border-black/10 bg-white/60 px-4 text-sm font-bold dark:border-white/10 dark:bg-white/8">Cancel</button>}
                    <button type="submit" disabled={isPending} className="inline-flex min-h-11 items-center gap-2 rounded-md bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-60 dark:bg-white dark:text-slate-950">
                      {config ? <Save className="size-4" /> : <Plus className="size-4" />}{config ? "Save changes" : "Add table"}
                    </button>
                  </>
                ) : (
                  <button type="button" onClick={() => setEditing(platform)} className="inline-flex min-h-11 items-center gap-2 rounded-md border border-black/10 bg-white/60 px-4 text-sm font-bold dark:border-white/10 dark:bg-white/8"><Edit3 className="size-4" />Edit</button>
                )}
              </div>
            </form>
          );
        })}
      </div>
    </section>
  );
}
