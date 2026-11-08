import "server-only";

import { cert, getApps, initializeApp } from "firebase-admin/app";
import { getDatabase } from "firebase-admin/database";

function requireEnv(value: string | undefined, name: string) {
  if (!value) {
    throw new Error(`Missing Firebase Admin env: ${name}`);
  }

  return value;
}

function formatPrivateKey(privateKey: string) {
  return privateKey.replace(/\\n/g, "\n");
}

const firebaseAdminApp =
  getApps()[0] ??
  initializeApp({
    credential: cert({
      projectId: requireEnv(
        process.env.FIREBASE_PROJECT_ID ??
          process.env.NEXT_PUBLIC_FIREBASE_PROJECT_ID,
        "FIREBASE_PROJECT_ID",
      ),
      clientEmail: requireEnv(
        process.env.FIREBASE_CLIENT_EMAIL,
        "FIREBASE_CLIENT_EMAIL",
      ),
      privateKey: formatPrivateKey(
        requireEnv(process.env.FIREBASE_PRIVATE_KEY, "FIREBASE_PRIVATE_KEY"),
      ),
    }),
    databaseURL: requireEnv(
      process.env.FIREBASE_DATABASE_URL ??
        process.env.NEXT_PUBLIC_FIREBASE_DATABASE_URL,
      "FIREBASE_DATABASE_URL",
    ),
  });

export const realtimeDb = getDatabase(firebaseAdminApp);
