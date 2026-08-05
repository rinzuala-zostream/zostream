"use client";

import { initializeApp, getApps, getApp } from "firebase/app";
import { getDatabase, ref } from "firebase/database";

function requireEnv(value: string | undefined, name: string) {
  if (!value) {
    throw new Error(`Missing Firebase env: ${name}`);
  }
  return value;
}

const firebaseConfig = {
  apiKey: requireEnv(
    process.env.NEXT_PUBLIC_FIREBASE_API_KEY,
    "NEXT_PUBLIC_FIREBASE_API_KEY",
  ),
  authDomain: requireEnv(
    process.env.NEXT_PUBLIC_FIREBASE_AUTH_DOMAIN,
    "NEXT_PUBLIC_FIREBASE_AUTH_DOMAIN",
  ),
  databaseURL: requireEnv(
    process.env.NEXT_PUBLIC_FIREBASE_DATABASE_URL,
    "NEXT_PUBLIC_FIREBASE_DATABASE_URL",
  ),
  projectId: requireEnv(
    process.env.NEXT_PUBLIC_FIREBASE_PROJECT_ID,
    "NEXT_PUBLIC_FIREBASE_PROJECT_ID",
  ),
  storageBucket: requireEnv(
    process.env.NEXT_PUBLIC_FIREBASE_STORAGE_BUCKET,
    "NEXT_PUBLIC_FIREBASE_STORAGE_BUCKET",
  ),
  messagingSenderId: requireEnv(
    process.env.NEXT_PUBLIC_FIREBASE_MESSAGING_SENDER_ID,
    "NEXT_PUBLIC_FIREBASE_MESSAGING_SENDER_ID",
  ),
  appId: requireEnv(process.env.NEXT_PUBLIC_FIREBASE_APP_ID, "NEXT_PUBLIC_FIREBASE_APP_ID"),
};

const app = getApps().length ? getApp() : initializeApp(firebaseConfig);
const db = getDatabase(app);

export function getQrSessionRef(token: string) {
  return ref(db, `qr_sessions/${token}`);
}
