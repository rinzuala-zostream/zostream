"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { toast } from "react-toastify";
import { clearAllCache } from "@/app/lib/cache-store";

type LogoutButtonProps = {
  compact?: boolean;
};

export function LogoutButton({ compact = false }: LogoutButtonProps) {
  const router = useRouter();
  const [loading, setLoading] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);

  const onLogout = async () => {
    setLoading(true);
    try {
      const response = await fetch("/api/auth/logout", { method: "POST" });
      if (!response.ok) throw new Error("Failed to logout");
      clearAllCache();
      setConfirmOpen(false);
      toast.success("Logged out");
      router.replace("/");
      router.refresh();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Failed to logout");
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <button
        type="button"
        onClick={() => setConfirmOpen(true)}
        disabled={loading}
        className={
          compact
            ? "rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60 sm:text-sm"
            : "rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60"
        }
      >
        {loading ? "Logging out..." : "Logout"}
      </button>

      {confirmOpen ? (
        <div
          aria-labelledby="logout-confirm-title"
          aria-modal="true"
          role="dialog"
          className="fixed inset-0 z-[130] flex items-end justify-center bg-slate-950/55 px-3 backdrop-blur-sm"
        >
          <button
            type="button"
            aria-label="Cancel logout"
            className="absolute inset-0 cursor-default"
            onClick={() => {
              if (!loading) setConfirmOpen(false);
            }}
          />
          <section className="relative w-full max-w-md rounded-t-lg border border-white/15 bg-white/20 px-5 pb-6 pt-4 text-white shadow-[0_-24px_80px_rgba(0,0,0,0.45)] backdrop-blur-2xl dark:bg-[#111116]/90">
            <div className="mx-auto h-1.5 w-20 rounded-full bg-white/35" />
            <div className="mt-8 text-center">
              <h2
                id="logout-confirm-title"
                className="text-2xl font-black tracking-tight"
              >
                Logout from Zostream?
              </h2>
              <p className="mt-4 text-sm leading-6 text-slate-100/85">
                Your saved session on this device will end. You can log in
                again anytime.
              </p>
            </div>

            <div className="mt-7 grid grid-cols-2 gap-3">
              <button
                type="button"
                onClick={() => setConfirmOpen(false)}
                disabled={loading}
                className="h-12 rounded-lg border border-white/20 bg-white/10 text-sm font-bold text-white transition hover:bg-white/15 disabled:cursor-not-allowed disabled:opacity-60"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={() => void onLogout()}
                disabled={loading}
                className="h-12 rounded-lg bg-rose-600 text-sm font-bold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {loading ? "Logging out..." : "Yes, logout"}
              </button>
            </div>
          </section>
        </div>
      ) : null}
    </>
  );
}
