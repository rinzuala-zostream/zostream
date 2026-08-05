"use client";

import { useEffect, useRef } from "react";
import { toast } from "react-toastify";
import {
  AUTH_TOKEN_CACHE_KEY,
  extractAuthTokenCache,
} from "@/app/features/auth/lib/auth-token-cache";
import {
  CACHE_UPDATED_EVENT,
  getCacheItem,
  removeCacheItem,
  setCacheItem,
} from "@/app/lib/cache-store";

type RefreshTokenApiResponse = {
  status: "success" | "error";
  message?: string;
  access_token?: string;
  refresh_token?: string;
  access_expires_at?: string;
  refresh_expires_at?: string;
  token_type?: string;
};

function parseExpiryMs(value: string) {
  const normalized = value.includes(" ") ? value.replace(" ", "T") : value;
  const parsed = Date.parse(normalized);
  return Number.isFinite(parsed) ? parsed : NaN;
}

export function TokenAutoRefresh({
  hasRefreshCookie,
}: {
  hasRefreshCookie: boolean;
}) {
  const timerRef = useRef<number | null>(null);
  const runningRef = useRef(false);
  const bootstrappedRef = useRef(false);
  const retryAfterRef = useRef(0);

  useEffect(() => {
    const clearExistingTimer = () => {
      if (timerRef.current !== null) {
        window.clearTimeout(timerRef.current);
        timerRef.current = null;
      }
    };

    async function scheduleNext() {
      clearExistingTimer();

      const cached = getCacheItem<unknown>(AUTH_TOKEN_CACHE_KEY);
      const authTokenCache = extractAuthTokenCache(cached);
      if (!authTokenCache) {
        if (hasRefreshCookie && !bootstrappedRef.current) {
          bootstrappedRef.current = true;
          void refreshNow({ allowCookieFallback: true, silent: true });
        }
        return;
      }

      const expiryMs = parseExpiryMs(authTokenCache.access_expires_at);
      if (!Number.isFinite(expiryMs)) return;

      const refreshLeadMs = 10_000;
      const retryDelay = Math.max(0, retryAfterRef.current - Date.now());
      const refreshDelay = Math.max(0, expiryMs - Date.now() - refreshLeadMs);
      const delay = retryDelay > 0 ? retryDelay : refreshDelay;

      timerRef.current = window.setTimeout(() => {
        void refreshNow();
      }, delay);
    }

    async function refreshNow(
      options: { allowCookieFallback?: boolean; silent?: boolean } = {},
    ) {
      if (runningRef.current) return;
      runningRef.current = true;

      try {
        const cached = getCacheItem<unknown>(AUTH_TOKEN_CACHE_KEY);
        const authTokenCache = extractAuthTokenCache(cached);
        if (!authTokenCache && !options.allowCookieFallback) return;

        const controller = new AbortController();
        const timeoutId = window.setTimeout(() => {
          controller.abort("Token refresh timed out");
        }, 10_000);

        const response = await fetch("/api/auth/token/refresh", {
          method: "POST",
          headers: { "content-type": "application/json" },
          signal: controller.signal,
          body: JSON.stringify(
            authTokenCache
              ? { refresh_token: authTokenCache.refresh_token }
              : {},
          ),
        }).finally(() => {
          window.clearTimeout(timeoutId);
        });

        const data = (await response.json()) as RefreshTokenApiResponse;
        if (!response.ok || data.status !== "success") {
          throw new Error(data.message || "Auto token refresh failed");
        }

        retryAfterRef.current = 0;
        setCacheItem(AUTH_TOKEN_CACHE_KEY, {
          ...(authTokenCache ?? {}),
          ...data,
        });

        if (authTokenCache?.uid) {
          await fetch("/api/auth/session", {
            method: "POST",
            headers: { "content-type": "application/json" },
            body: JSON.stringify({
              uid: authTokenCache.uid,
              access_token: data.access_token,
              refresh_token: data.refresh_token,
              token_type: data.token_type,
            }),
          });
        }
      } catch (error) {
        const cached = getCacheItem<unknown>(AUTH_TOKEN_CACHE_KEY);
        const authTokenCache = extractAuthTokenCache(cached);
        const accessExpiryMs = authTokenCache
          ? parseExpiryMs(authTokenCache.access_expires_at)
          : NaN;
        const refreshExpiryMs = authTokenCache?.refresh_expires_at
          ? parseExpiryMs(authTokenCache.refresh_expires_at)
          : NaN;
        const now = Date.now();

        if (
          authTokenCache &&
          ((Number.isFinite(accessExpiryMs) && accessExpiryMs <= now) ||
            (Number.isFinite(refreshExpiryMs) && refreshExpiryMs <= now))
        ) {
          removeCacheItem(AUTH_TOKEN_CACHE_KEY);
          retryAfterRef.current = 0;
        } else {
          retryAfterRef.current = now + 60_000;
        }

        if (!options.silent) {
          toast.error(
            error instanceof Error
              ? error.message
              : "Auto token refresh failed",
          );
        }
      } finally {
        runningRef.current = false;
        await scheduleNext();
      }
    }

    const onWindowFocus = () => {
      void scheduleNext();
    };

    const onCacheUpdated = (event: Event) => {
      const customEvent = event as CustomEvent<{ key?: string }>;
      if (customEvent.detail?.key !== AUTH_TOKEN_CACHE_KEY) return;
      void scheduleNext();
    };

    void scheduleNext();
    window.addEventListener("focus", onWindowFocus);
    window.addEventListener(CACHE_UPDATED_EVENT, onCacheUpdated);

    return () => {
      clearExistingTimer();
      window.removeEventListener("focus", onWindowFocus);
      window.removeEventListener(CACHE_UPDATED_EVENT, onCacheUpdated);
    };
  }, [hasRefreshCookie]);

  return null;
}
