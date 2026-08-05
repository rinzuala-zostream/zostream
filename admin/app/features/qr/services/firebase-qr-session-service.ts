import "server-only";

import { realtimeDb } from "@/app/lib/firebase-admin";
import type { QrSessionData } from "./qr-session-service";

const QR_SESSION_TOKEN_PATTERN = /^[A-Za-z0-9_-]{1,128}$/;

function assertValidQrSessionToken(token: string) {
  if (!QR_SESSION_TOKEN_PATTERN.test(token)) {
    throw new Error("Invalid QR session token");
  }
}

export async function getFirebaseQrSession(token: string) {
  assertValidQrSessionToken(token);

  const snapshot = await realtimeDb.ref(`qr_sessions/${token}`).get();

  return snapshot.val() as QrSessionData | null;
}

export async function updateFirebaseQrSession(
  token: string,
  data: Partial<QrSessionData>,
) {
  assertValidQrSessionToken(token);

  await realtimeDb.ref(`qr_sessions/${token}`).update(data);
}
