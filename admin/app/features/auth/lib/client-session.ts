export type AuthSessionPayload = {
  uid: string;
  access_token: string;
  refresh_token: string;
  token_type?: string;
  expires_in?: number;
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

export function extractAuthSession(data: unknown): AuthSessionPayload | null {
  if (!isRecord(data)) return null;

  const uid = typeof data.uid === "string" ? data.uid.trim() : "";
  const accessToken =
    typeof data.access_token === "string" ? data.access_token.trim() : "";
  const refreshToken =
    typeof data.refresh_token === "string" ? data.refresh_token.trim() : "";
  if (!uid || !accessToken || !refreshToken) return null;

  return {
    uid,
    access_token: accessToken,
    refresh_token: refreshToken,
    token_type: typeof data.token_type === "string" ? data.token_type : undefined,
    expires_in: typeof data.expires_in === "number" ? data.expires_in : undefined,
  };
}

export async function persistAuthSession(payload: AuthSessionPayload) {
  const response = await fetch("/api/auth/session", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(payload),
  });

  if (!response.ok) {
    const data = (await response.json().catch(() => null)) as
      | { message?: string }
      | null;
    throw new Error(data?.message || "Failed to persist login session");
  }
}
