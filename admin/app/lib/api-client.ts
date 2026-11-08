import "server-only";

import { cookies } from "next/headers";

type Primitive = string | number | boolean;
type QueryValue = Primitive | null | undefined | Primitive[];

export type QueryParams = Record<string, QueryValue>;

export class ApiError extends Error {
  readonly status: number;
  readonly method: string;
  readonly url: string;
  readonly data: unknown;

  constructor(
    message: string,
    meta: { status: number; method: string; url: string; data: unknown },
  ) {
    super(message);
    this.name = "ApiError";
    this.status = meta.status;
    this.method = meta.method;
    this.url = meta.url;
    this.data = meta.data;
  }
}

export type ApiRequestOptions = Omit<RequestInit, "body"> & {
  query?: QueryParams;
  body?: BodyInit | Record<string, unknown> | null;
  timeoutMs?: number;
};

export type ApiClientConfig = {
  baseUrl?: string;
  defaultHeaders?: HeadersInit;
  timeoutMs?: number;
};

const CONTENT_MODE_COOKIE_KEY = "zostream_content_mode";
const AGE_RESTRICTION_COOKIE_KEY = "zostream_age_restriction";
const ACCESS_TOKEN_COOKIE_KEY = "zostream_admin_access_token";
const REFRESH_TOKEN_COOKIE_KEY = "zostream_admin_refresh_token";
const TOKEN_TYPE_COOKIE_KEY = "zostream_admin_token_type";

type RefreshTokenResponse = {
  status?: "success" | "error";
  message?: string;
  access_token?: string;
  refresh_token?: string;
  access_expires_at?: string;
  refresh_expires_at?: string;
  token_type?: string;
};

type ApiEnvelope<T> = {
  success: boolean;
  data: T;
  message: string | null;
  meta: {
    request_id?: string;
    api_version?: string;
  };
  error: {
    code?: string;
    message?: string;
    details?: unknown;
  } | null;
};

function isApiEnvelope(value: unknown): value is ApiEnvelope<unknown> {
  return (
    typeof value === "object" &&
    value !== null &&
    "success" in value &&
    "data" in value &&
    "error" in value
  );
}

function unwrapApiEnvelope<T>(value: unknown): T {
  return (isApiEnvelope(value) ? value.data : value) as T;
}

function normalizeBaseUrl(url?: string) {
  if (!url) return "";
  return url.endsWith("/") ? url.slice(0, -1) : url;
}

function isAbsoluteUrl(path: string) {
  return /^https?:\/\//i.test(path);
}

function buildUrl(path: string, query?: QueryParams, baseUrl?: string) {
  const normalizedPath = path.startsWith("/") ? path : `/${path}`;
  const normalizedBase = normalizeBaseUrl(baseUrl);

  if (!isAbsoluteUrl(path) && !normalizedBase) {
    throw new Error(
      `API base URL is missing. Set API_BASE_URL (or NEXT_PUBLIC_API_BASE_URL) before calling ${path}`,
    );
  }

  const raw = isAbsoluteUrl(path) ? path : `${normalizedBase}${normalizedPath}`;
  const url = new URL(raw);

  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value === null || value === undefined) continue;
      if (Array.isArray(value)) {
        for (const item of value) {
          url.searchParams.append(key, String(item));
        }
      } else {
        url.searchParams.set(key, String(value));
      }
    }
  }

  return url.toString();
}

function isMovieApiPath(path: string) {
  try {
    const pathname = isAbsoluteUrl(path) ? new URL(path).pathname : path;
    return pathname.startsWith("/api/v4/catalog");
  } catch {
    return path.includes("/api/v4/catalog");
  }
}

function mergeHeaders(base?: HeadersInit, incoming?: HeadersInit) {
  const merged = new Headers(base);
  const next = new Headers(incoming);
  next.forEach((value, key) => merged.set(key, value));
  return merged;
}

async function getContentModeHeader() {
  try {
    const cookieStore = await cookies();
    return cookieStore.get(CONTENT_MODE_COOKIE_KEY)?.value === "kids"
      ? "kids"
      : "adult";
  } catch {
    return "adult";
  }
}

async function getAgeRestrictionQueryValue() {
  try {
    const cookieStore = await cookies();
    const mode =
      cookieStore.get(CONTENT_MODE_COOKIE_KEY)?.value === "kids"
        ? "kids"
        : "adult";

    return mode === "adult" &&
      cookieStore.get(AGE_RESTRICTION_COOKIE_KEY)?.value === "true"
      ? "true"
      : "false";
  } catch {
    return "false";
  }
}

async function applyGlobalQuery(path: string, query?: QueryParams) {
  if (!isMovieApiPath(path)) return query;

  return {
    ...query,
    age_restriction:
      query?.age_restriction ?? (await getAgeRestrictionQueryValue()),
  };
}

async function applyContentModeHeader(headers: Headers) {
  if (headers.has("X-Mode")) return;
  headers.set("X-Mode", await getContentModeHeader());
}

async function applyAuthorizationHeader(headers: Headers) {
  if (headers.has("authorization")) return;

  try {
    const cookieStore = await cookies();
    const accessToken = cookieStore.get(ACCESS_TOKEN_COOKIE_KEY)?.value?.trim();

    if (accessToken) {
      headers.set("Authorization", `Bearer ${accessToken}`);
    }
  } catch {
    // Unauthenticated server-side calls can still proceed.
  }
}

async function getRefreshTokenCookie() {
  try {
    const cookieStore = await cookies();
    return cookieStore.get(REFRESH_TOKEN_COOKIE_KEY)?.value?.trim() ?? "";
  } catch {
    return "";
  }
}

async function persistRefreshedAuthCookies(data: RefreshTokenResponse) {
  if (!data.access_token || !data.refresh_token) return;

  try {
    const isProd = process.env.NODE_ENV === "production";
    const cookieStore = await cookies();

    cookieStore.set(ACCESS_TOKEN_COOKIE_KEY, data.access_token, {
      httpOnly: true,
      sameSite: "lax",
      secure: isProd,
      path: "/",
      maxAge: 60 * 60 * 24 * 7,
    });

    cookieStore.set(REFRESH_TOKEN_COOKIE_KEY, data.refresh_token, {
      httpOnly: true,
      sameSite: "lax",
      secure: isProd,
      path: "/",
      maxAge: 60 * 60 * 24 * 30,
    });

    if (data.token_type) {
      cookieStore.set(TOKEN_TYPE_COOKIE_KEY, data.token_type, {
        httpOnly: true,
        sameSite: "lax",
        secure: isProd,
        path: "/",
        maxAge: 60 * 60 * 24 * 30,
      });
    }
  } catch {
    // Server Components cannot mutate cookies, but the refreshed token can
    // still be used to retry the current backend request.
  }
}

function shouldAttemptTokenRefresh(
  path: string,
  response: Response,
  data: unknown,
) {
  if (response.status !== 401) return false;

  try {
    const pathname = isAbsoluteUrl(path) ? new URL(path).pathname : path;
    if (pathname.includes("/api/v4/auth/tokens/")) return false;
  } catch {
    if (path.includes("/api/v4/auth/tokens/")) return false;
  }

  if (typeof data === "object" && data !== null && "message" in data) {
    const message = String(
      (data as { message?: unknown }).message ?? "",
    ).toLowerCase();
    return (
      message.includes("access token expired") ||
      message.includes("invalid or revoked token") ||
      message.includes("missing or invalid authorization")
    );
  }

  return true;
}

function isJsonBody(body: unknown) {
  if (!body || typeof body !== "object") return false;
  if (
    body instanceof FormData ||
    body instanceof URLSearchParams ||
    body instanceof Blob ||
    body instanceof ArrayBuffer
  ) {
    return false;
  }
  return true;
}

async function parseResponseBody(response: Response): Promise<unknown> {
  if (response.status === 204) return null;
  const contentType = response.headers.get("content-type")?.toLowerCase() ?? "";

  if (contentType.includes("application/json")) {
    return response.json();
  }

  return response.text();
}

function createTimeoutSignal(timeoutMs: number, externalSignal?: AbortSignal) {
  const controller = new AbortController();
  const timer = setTimeout(
    () => controller.abort(new Error(`Request timed out after ${timeoutMs}ms`)),
    timeoutMs,
  );

  const abort = () => controller.abort(externalSignal?.reason);
  externalSignal?.addEventListener("abort", abort, { once: true });

  return {
    signal: controller.signal,
    clear: () => {
      clearTimeout(timer);
      externalSignal?.removeEventListener("abort", abort);
    },
  };
}

export function createApiClient(config: ApiClientConfig = {}) {
  const resolvedBaseUrl =
    config.baseUrl ??
    process.env.API_BASE_URL ??
    process.env.NEXT_PUBLIC_API_BASE_URL ??
    "";
  const defaultTimeout = config.timeoutMs ?? 10_000;

  async function request<T>(
    method: string,
    path: string,
    options: ApiRequestOptions = {},
  ): Promise<T> {
    const { query, body, headers, timeoutMs, signal, ...rest } = options;
    const requestQuery = await applyGlobalQuery(path, query);
    const url = buildUrl(path, requestQuery, resolvedBaseUrl);

    const requestHeaders = mergeHeaders(config.defaultHeaders, headers);
    let requestBody: BodyInit | null | undefined = body as
      | BodyInit
      | null
      | undefined;

    if (isJsonBody(body)) {
      requestBody = JSON.stringify(body);
      if (!requestHeaders.has("content-type")) {
        requestHeaders.set("content-type", "application/json");
      }
    }

    if (!requestHeaders.has("accept")) {
      requestHeaders.set(
        "accept",
        "application/json, text/plain;q=0.9, */*;q=0.8",
      );
    }
    await applyAuthorizationHeader(requestHeaders);
    await applyContentModeHeader(requestHeaders);
    requestHeaders.set("X-Client-Platform", "admin");
    requestHeaders.set(
      "X-Client-Version",
      process.env.NEXT_PUBLIC_APP_VERSION ?? "admin",
    );
    requestHeaders.set("X-Device-Type", "browser");

    const timeout = createTimeoutSignal(
      timeoutMs ?? defaultTimeout,
      signal ?? undefined,
    );

    const send = async (headersToSend: Headers) => {
      const response = await fetch(url, {
        method,
        headers: headersToSend,
        body: method === "GET" || method === "HEAD" ? undefined : requestBody,
        signal: timeout.signal,
        cache: "no-store",
        ...rest,
      });

      const data = await parseResponseBody(response);
      return { response, data };
    };

    const toApiError = (response: Response, data: unknown) => {
      const message =
        typeof data === "object" && data !== null && "message" in data
          ? String((data as { message?: string }).message)
          : `${method} ${url} failed (${response.status})`;

      return new ApiError(message, {
        status: response.status,
        method,
        url,
        data,
      });
    };

    const refreshAccessToken = async () => {
      const refreshToken = await getRefreshTokenCookie();
      if (!refreshToken) return null;

      const refreshHeaders = mergeHeaders(config.defaultHeaders, {
        accept: "application/json",
        "content-type": "application/json",
        "X-Client-Platform": "admin",
        "X-Client-Version": process.env.NEXT_PUBLIC_APP_VERSION ?? "admin",
        "X-Device-Type": "browser",
      });

      const refreshUrl = buildUrl(
        "/api/v4/auth/tokens/refresh",
        undefined,
        resolvedBaseUrl,
      );
      const response = await fetch(refreshUrl, {
        method: "POST",
        headers: refreshHeaders,
        body: JSON.stringify({ refresh_token: refreshToken }),
        signal: timeout.signal,
        cache: "no-store",
      });
      const raw = await parseResponseBody(response);
      const data = unwrapApiEnvelope<RefreshTokenResponse>(raw);

      if (!response.ok || !data.access_token) {
        return null;
      }

      await persistRefreshedAuthCookies(data);
      return data;
    };

    try {
      let { response, data } = await send(requestHeaders);

      if (!response.ok && shouldAttemptTokenRefresh(path, response, data)) {
        const refreshed = await refreshAccessToken();

        if (refreshed?.access_token) {
          const retryHeaders = new Headers(requestHeaders);
          retryHeaders.set("Authorization", `Bearer ${refreshed.access_token}`);
          ({ response, data } = await send(retryHeaders));
        }
      }

      if (!response.ok || (isApiEnvelope(data) && !data.success)) {
        throw toApiError(response, data);
      }

      return unwrapApiEnvelope<T>(data);
    } finally {
      timeout.clear();
    }
  }

  return {
    request,
    get: <T>(path: string, options?: Omit<ApiRequestOptions, "body">) =>
      request<T>("GET", path, options),
    post: <T>(
      path: string,
      body?: ApiRequestOptions["body"],
      options?: Omit<ApiRequestOptions, "body">,
    ) => request<T>("POST", path, { ...options, body }),
    put: <T>(
      path: string,
      body?: ApiRequestOptions["body"],
      options?: Omit<ApiRequestOptions, "body">,
    ) => request<T>("PUT", path, { ...options, body }),
    patch: <T>(
      path: string,
      body?: ApiRequestOptions["body"],
      options?: Omit<ApiRequestOptions, "body">,
    ) => request<T>("PATCH", path, { ...options, body }),
    delete: <T>(path: string, options?: Omit<ApiRequestOptions, "body">) =>
      request<T>("DELETE", path, options),
  };
}

export const apiClient = createApiClient();
