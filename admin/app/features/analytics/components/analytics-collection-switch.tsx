"use client";

import { useEffect, useState, useTransition } from "react";
import { Power, RadioTower, RefreshCw } from "lucide-react";
import { toast } from "react-toastify";
import { cn } from "@/lib/utils";

type ControlResponse = {
  status: "success" | "error";
  message?: string;
  data?: {
    path?: string;
    enabled?: boolean;
    updated_at?: string | null;
  };
};

export function AnalyticsCollectionSwitch() {
  const [enabled, setEnabled] = useState(false);
  const [updatedAt, setUpdatedAt] = useState<string | null>(null);
  const [status, setStatus] = useState<"loading" | "ready" | "error">(
    "loading",
  );
  const [isPending, startTransition] = useTransition();

  async function loadState() {
    setStatus("loading");
    try {
      const response = await fetch("/api/admin/analytics/config", {
        cache: "no-store",
      });
      const result = (await response.json()) as ControlResponse;
      if (!response.ok || result.status !== "success") {
        throw new Error(result.message || "Analytics control could not be loaded.");
      }
      setEnabled(result.data?.enabled === true);
      setUpdatedAt(result.data?.updated_at ?? null);
      setStatus("ready");
    } catch (error) {
      setStatus("error");
      toast.error(
        error instanceof Error
          ? error.message
          : "Analytics control could not be loaded.",
      );
    }
  }

  useEffect(() => {
    let active = true;

    void fetch("/api/admin/analytics/config", { cache: "no-store" })
      .then(async (response) => {
        const result = (await response.json()) as ControlResponse;
        if (!response.ok || result.status !== "success") {
          throw new Error(
            result.message || "Analytics control could not be loaded.",
          );
        }
        return result;
      })
      .then((result) => {
        if (!active) return;
        setEnabled(result.data?.enabled === true);
        setUpdatedAt(result.data?.updated_at ?? null);
        setStatus("ready");
      })
      .catch((error: unknown) => {
        if (!active) return;
        setStatus("error");
        toast.error(
          error instanceof Error
            ? error.message
            : "Analytics control could not be loaded.",
        );
      });

    return () => {
      active = false;
    };
  }, []);

  function updateState(nextEnabled: boolean) {
    startTransition(async () => {
      try {
        const response = await fetch("/api/admin/analytics/config", {
          method: "PUT",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ enabled: nextEnabled }),
        });
        const result = (await response.json()) as ControlResponse;
        if (!response.ok || result.status !== "success") {
          throw new Error(result.message || "Analytics setting could not be saved.");
        }
        setEnabled(result.data?.enabled === true);
        setUpdatedAt(result.data?.updated_at ?? null);
        setStatus("ready");
        toast.success(result.message || "Analytics setting updated.");
      } catch (error) {
        toast.error(
          error instanceof Error
            ? error.message
            : "Analytics setting could not be saved.",
        );
      }
    });
  }

  const unavailable = status === "error";
  const disabled = status === "loading" || unavailable || isPending;

  return (
    <section className="flex flex-col gap-4 rounded-[1.35rem] border border-white/65 bg-white/80 p-4 shadow-[0_16px_44px_rgba(15,23,42,0.08)] backdrop-blur-xl sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:bg-white/6 dark:shadow-[0_18px_52px_rgba(2,6,23,0.36)]">
      <div className="flex min-w-0 items-start gap-3">
        <div
          className={cn(
            "grid size-11 shrink-0 place-items-center rounded-2xl text-white shadow-lg",
            enabled
              ? "bg-gradient-to-br from-emerald-400 to-cyan-500 shadow-emerald-950/15"
              : "bg-gradient-to-br from-slate-500 to-slate-700 shadow-slate-950/15",
          )}
        >
          <RadioTower className="size-5" />
        </div>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="font-bold text-slate-950 dark:text-white">
              Analytics collection
            </h2>
            <span
              className={cn(
                "rounded-full px-2.5 py-1 text-[0.65rem] font-black uppercase tracking-[0.14em]",
                status === "loading"
                  ? "bg-amber-100 text-amber-800 dark:bg-amber-300/10 dark:text-amber-100"
                  : status === "error"
                    ? "bg-rose-100 text-rose-800 dark:bg-rose-300/10 dark:text-rose-100"
                  : enabled
                    ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-300/10 dark:text-emerald-100"
                    : "bg-slate-200 text-slate-700 dark:bg-white/10 dark:text-slate-300",
              )}
            >
              {status === "loading"
                ? "Checking"
                : status === "error"
                  ? "Unavailable"
                  : enabled
                    ? "Live"
                    : "Off"}
            </span>
          </div>
          <p className="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">
            Updates Firebase Realtime Database path
            <code className="mx-1 rounded bg-slate-950/[0.06] px-1.5 py-0.5 font-bold text-slate-700 dark:bg-white/10 dark:text-slate-200">
              /config/analytics/enabled
            </code>
            for iOS, Apple TV, Android and TV.
          </p>
          <p className="mt-1 text-[0.68rem] text-slate-400">
            Connected devices apply this setting immediately through a live listener.
          </p>
          {updatedAt ? (
            <p className="mt-1 text-[0.68rem] font-semibold text-slate-400">
              Last updated {new Date(updatedAt).toLocaleString()}
            </p>
          ) : null}
        </div>
      </div>

      <div className="flex items-center justify-between gap-3 sm:justify-end">
        {unavailable ? (
          <button
            type="button"
            onClick={() => void loadState()}
            className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 transition hover:bg-slate-50 dark:border-white/10 dark:bg-white/6 dark:text-slate-200 dark:hover:bg-white/10"
          >
            <RefreshCw className="size-4" /> Retry
          </button>
        ) : null}
        <button
          type="button"
          role="switch"
          aria-checked={enabled}
          aria-label="Toggle analytics collection"
          disabled={disabled}
          onClick={() => updateState(!enabled)}
          className={cn(
            "relative inline-flex h-11 w-[5.5rem] shrink-0 items-center rounded-full border p-1 transition duration-300 disabled:cursor-not-allowed disabled:opacity-50",
            enabled
              ? "border-emerald-300 bg-emerald-500 shadow-[0_8px_24px_rgba(16,185,129,0.28)]"
              : "border-slate-300 bg-slate-300 dark:border-white/10 dark:bg-white/10",
          )}
        >
          <span
            className={cn(
              "grid size-8 place-items-center rounded-full bg-white text-slate-600 shadow-md transition-transform duration-300",
              enabled ? "translate-x-11 text-emerald-600" : "translate-x-0",
            )}
          >
            {isPending || status === "loading" ? (
              <RefreshCw className="size-4 animate-spin" />
            ) : (
              <Power className="size-4" />
            )}
          </span>
        </button>
      </div>
    </section>
  );
}
