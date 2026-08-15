import "server-only";

import { createDecipheriv, createHash } from "node:crypto";

const DEFAULT_STREAM_LINK_KEY =
  "d4c6198dabafb243b0d043a3c33a9fe171f81605158c267c7dfe5f66df29559a";

function normalizeCipherText(value: string) {
  let normalized = value.trim();

  try {
    normalized = decodeURIComponent(normalized);
  } catch {
    // Keep malformed percent-encoded input unchanged and let Base64 validation fail.
  }

  normalized = normalized
    .replaceAll(" ", "+")
    .replaceAll("-", "+")
    .replaceAll("_", "/");

  const padding = normalized.length % 4;
  return padding
    ? normalized.padEnd(normalized.length + 4 - padding, "=")
    : normalized;
}

/**
 * Decrypts the IV-prefixed AES-256-CBC links stored by the playback API.
 * Plain links and unrecognized legacy values are returned unchanged.
 */
export function decryptStreamLink(value: string): string {
  const raw = value.trim();
  if (!raw || /^https?:\/\//i.test(raw)) return value;

  try {
    const payload = Buffer.from(normalizeCipherText(raw), "base64");
    if (payload.length < 32 || (payload.length - 16) % 16 !== 0) return value;

    const keySource =
      process.env.STREAM_LINK_ENCRYPTION_KEY ?? DEFAULT_STREAM_LINK_KEY;
    const key = createHash("sha256").update(keySource).digest();
    const decipher = createDecipheriv(
      "aes-256-cbc",
      key,
      payload.subarray(0, 16),
    );
    const decrypted = Buffer.concat([
      decipher.update(payload.subarray(16)),
      decipher.final(),
    ])
      .toString("utf8")
      .replace(/[\r\n]/g, "")
      .trim();

    if (!decrypted) return value;

    try {
      return decodeURIComponent(decrypted);
    } catch {
      return decrypted;
    }
  } catch {
    return value;
  }
}
