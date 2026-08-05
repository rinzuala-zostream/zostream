type CacheStorageType = "local" | "session";
export const CACHE_UPDATED_EVENT = "zostream-admin:cache-updated";
const ADMIN_CACHE_KEYS = ["zostream_admin_auth_token_cache_data"] as const;

type CacheStoreOptions = {
  storage?: CacheStorageType;
};

function getStorage(storageType: CacheStorageType): Storage | null {
  if (typeof window === "undefined") return null;
  return storageType === "local" ? window.localStorage : window.sessionStorage;
}

function emitCacheUpdate(key: string) {
  if (typeof window === "undefined") return;

  try {
    window.dispatchEvent(
      new CustomEvent(CACHE_UPDATED_EVENT, { detail: { key } }),
    );
  } catch {
    // Ignore event dispatch errors.
  }
}

export function setCacheItem<T>(
  key: string,
  value: T,
  options: CacheStoreOptions = {},
) {
  const storage = getStorage(options.storage ?? "session");
  if (!storage) return;

  try {
    storage.setItem(key, JSON.stringify(value));
    emitCacheUpdate(key);
  } catch {
    // Ignore storage quota/private mode errors.
  }
}

export function getCacheItem<T>(
  key: string,
  options: CacheStoreOptions = {},
): T | null {
  const storage = getStorage(options.storage ?? "session");
  if (!storage) return null;

  try {
    const rawValue = storage.getItem(key);
    if (!rawValue) return null;
    return JSON.parse(rawValue) as T;
  } catch {
    return null;
  }
}

export function removeCacheItem(
  key: string,
  options: CacheStoreOptions = {},
) {
  const storage = getStorage(options.storage ?? "session");
  if (!storage) return;

  try {
    storage.removeItem(key);
    emitCacheUpdate(key);
  } catch {
    // Ignore storage access errors.
  }
}

export function clearAllCache() {
  if (typeof window === "undefined") return;

  for (const key of ADMIN_CACHE_KEYS) {
    try {
      window.sessionStorage.removeItem(key);
      window.localStorage.removeItem(key);
    } catch {
      // Ignore storage access errors.
    }
  }

  emitCacheUpdate("*");
}
