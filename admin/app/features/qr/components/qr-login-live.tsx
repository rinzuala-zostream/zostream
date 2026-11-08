"use client";

import { onValue } from "firebase/database";
import dynamic from "next/dynamic";
import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { toast } from "react-toastify";
import {
  extractAuthSession,
  persistAuthSession,
} from "@/app/features/auth/lib/client-session";
import { AUTH_TOKEN_CACHE_KEY } from "@/app/features/auth/lib/auth-token-cache";
import {
  getCacheItem,
  removeCacheItem,
  setCacheItem,
} from "@/app/lib/cache-store";
import { getQrSessionRef } from "../lib/firebase-client";

const QrTokenCode = dynamic(
  () => import("./qr-token-code").then((mod) => mod.QrTokenCode),
  { ssr: false },
);

type QrLoginLiveProps = {
  initialToken: string;
  initialExpiresIn: number;
  initialError?: string;
};

type RefreshResponse = {
  status: "completed" | "error";
  token?: string;
  expires_in?: number;
  message?: string;
};

export function QrLoginLive({
  initialToken,
  initialExpiresIn,
  initialError = "",
}: QrLoginLiveProps) {
  const router = useRouter();
  const [token, setToken] = useState(initialToken);
  const [secondsLeft, setSecondsLeft] = useState(initialExpiresIn);
  const [error, setError] = useState(initialError);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [sessionStatus, setSessionStatus] = useState("initialized");
  const [sessionMessage, setSessionMessage] = useState("");
  const [sessionUserId, setSessionUserId] = useState("");
  const refreshingRef = useRef(false);
  const lastNotifiedStatusRef = useRef("initialized");
  const didRedirectRef = useRef(false);
  const isTerminalSession =
    sessionStatus === "completed" ||
    sessionStatus === "failed" ||
    sessionStatus === "expired";

  const refreshQr = async () => {
    if (refreshingRef.current) return;
    refreshingRef.current = true;
    setIsRefreshing(true);

    try {
      const response = await fetch("/api/qr/session", { method: "POST" });
      const data = (await response.json()) as RefreshResponse;

      if (!response.ok || data.status !== "completed" || !data.token) {
        throw new Error(data.message ?? "Failed to regenerate QR");
      }

      setToken(data.token);
      setSecondsLeft(data.expires_in ?? 120);
      setError("");
      setSessionStatus("initialized");
      setSessionMessage("");
      setSessionUserId("");
      didRedirectRef.current = false;
      lastNotifiedStatusRef.current = "initialized";
      removeCacheItem(AUTH_TOKEN_CACHE_KEY);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to regenerate QR");
    } finally {
      setIsRefreshing(false);
      refreshingRef.current = false;
    }
  };

  useEffect(() => {
    if (!token || isTerminalSession) return;

    const timer = setInterval(() => {
      setSecondsLeft((current) => {
        if (current <= 1) {
          clearInterval(timer);
          return 0;
        }
        return current - 1;
      });
    }, 1000);

    return () => clearInterval(timer);
  }, [isTerminalSession, token]);

  useEffect(() => {
    if (!token) return;

    try {
      const sessionRef = getQrSessionRef(token);
      const unsubscribe = onValue(sessionRef, (snapshot) => {
        const value = snapshot.val() as {
          status?: string;
          user_id?: string;
          response?: {
            message?: string;
            data?: unknown;
          };
        } | null;

        if (!value) {
          setSessionStatus("missing");
          setSessionMessage("QR session not found.");
          setSessionUserId("");
          return;
        }

        const status = value.status ?? "unknown";
        if (value.response?.data !== undefined) {
          setCacheItem(AUTH_TOKEN_CACHE_KEY, value.response.data);
        }
        setSessionStatus(status);
        setSessionMessage(
          typeof value.response?.message === "string"
            ? value.response.message.trim()
            : "",
        );
        setSessionUserId(
          typeof value.user_id === "string" ? value.user_id.trim() : "",
        );
      });

      return () => unsubscribe();
    } catch (err) {
      toast.error(
        err instanceof Error
          ? err.message
          : "Failed to subscribe realtime session",
      );
      return;
    }
  }, [token]);

  useEffect(() => {
    if (sessionStatus !== "completed" || didRedirectRef.current) return;
    if (!sessionUserId) return;

    const cachedResponseData = getCacheItem<unknown>(AUTH_TOKEN_CACHE_KEY);
    const extractedSession = extractAuthSession(cachedResponseData);
    if (!extractedSession) {
      toast.error("Login completed without a complete authenticated session");
      return;
    }

    didRedirectRef.current = true;
    void persistAuthSession(extractedSession)
      .then(() => {
        router.replace("/dashboard");
        router.refresh();
      })
      .catch((error) => {
        didRedirectRef.current = false;
        toast.error(
          error instanceof Error
            ? error.message
            : "Failed to persist login session",
        );
      });
  }, [router, sessionStatus, sessionUserId]);

  useEffect(() => {
    if (!token) return;
    if (sessionStatus === lastNotifiedStatusRef.current) return;
    lastNotifiedStatusRef.current = sessionStatus;

    if (sessionStatus === "pending") {
      toast.info("QR scanned. Waiting for mobile approval...", {
        toastId: `qr-${token}-pending`,
      });
      return;
    }

    if (sessionStatus === "completed") {
      toast.success("Login approved successfully.", {
        toastId: `qr-${token}-completed`,
      });
      return;
    }

    if (sessionStatus === "failed" || sessionStatus === "expired") {
      toast.error(sessionMessage || "QR login failed or expired.", {
        toastId: `qr-${token}-${sessionStatus}`,
      });
    }
  }, [sessionMessage, token, sessionStatus]);

  const statusBadgeClass =
    sessionStatus === "completed"
      ? "text-emerald-700 dark:text-emerald-300"
      : sessionStatus === "failed" || sessionStatus === "expired"
        ? "text-rose-700 dark:text-rose-300"
        : "text-slate-900 dark:text-white";
  const showRefreshOverlay =
    (secondsLeft === 0 && sessionStatus !== "completed") ||
    sessionStatus === "failed";
  const hasScanStarted =
    sessionStatus === "pending" ||
    sessionStatus === "completed" ||
    sessionStatus === "failed" ||
    sessionStatus === "expired";

  return (
    <div className="w-full">
      <div className="flex w-full flex-col items-center justify-center gap-5 rounded-2xl border border-transparent bg-transparent p-3 sm:p-4 lg:p-6">
        {token ? (
          <>
            <div className="flex flex-col items-center gap-2">
              <div className="relative inline-flex mb-3">
                <QrTokenCode token={token} size={310} />
                {showRefreshOverlay ? (
                  <div className="absolute inset-0 flex items-center justify-center rounded-[2.1rem] bg-slate-950/35 backdrop-blur-[1px]">
                    <button
                      type="button"
                      onClick={() => void refreshQr()}
                      disabled={isRefreshing}
                      className="rounded-full border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-white/30 dark:bg-slate-900 dark:text-white/90 dark:hover:bg-slate-800"
                    >
                      {isRefreshing ? "Refreshing..." : "Refresh QR"}
                    </button>
                  </div>
                ) : null}
              </div>

              <span className="text-sm text-slate-500 dark:text-white/70">
                Scan the QR code with your Zo Stream app to login
              </span>
            </div>

            <div className="px-20 rounded-2xl border border-slate-200 bg-linear-to-r from-slate-50 to-white p-4 dark:border-white/12 dark:from-white/4 dark:to-white/2">
              <div className="flex items-center justify-center text-sm">
                {hasScanStarted ? (
                  <span
                    className={`text-base font-semibold capitalize ${statusBadgeClass}`}
                  >
                    {sessionStatus}
                  </span>
                ) : (
                  <span className="text-base font-semibold tabular-nums text-slate-900 dark:text-white">
                    {secondsLeft}s
                  </span>
                )}
              </div>
            </div>
          </>
        ) : (
          <p className="rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/40 dark:bg-rose-500/15 dark:text-rose-100">
            {error || "Failed to generate QR session."}
          </p>
        )}
      </div>
    </div>
  );
}
