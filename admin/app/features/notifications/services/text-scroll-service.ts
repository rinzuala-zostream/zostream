import "server-only";

import { realtimeDb } from "@/app/lib/firebase-admin";

const TEXT_SCROLL_PATH = "text_scroll";
const TEXT_SCROLL_ID = "text_scroll";

export type TextScrollItem = {
  id: string;
  text: string;
  show: boolean;
  created_at?: number;
  updated_at?: number;
};

type FirebaseTextScrollItem = {
  text?: unknown;
  show?: unknown;
  created_at?: unknown;
  updated_at?: unknown;
};

function toNumber(value: unknown) {
  return typeof value === "number" && Number.isFinite(value)
    ? value
    : undefined;
}

function toTextScrollItem(
  id: string,
  value: FirebaseTextScrollItem,
): TextScrollItem {
  return {
    id,
    text: typeof value.text === "string" ? value.text : "",
    show: value.show === true,
    created_at: toNumber(value.created_at),
    updated_at: toNumber(value.updated_at),
  };
}

function assertValidTextScrollId(id: string) {
  if (id !== TEXT_SCROLL_ID) {
    throw new Error("Invalid scrolling text ID");
  }
}

export async function listTextScrollItems() {
  const snapshot = await realtimeDb.ref(TEXT_SCROLL_PATH).get();
  const value = snapshot.val() as FirebaseTextScrollItem | null;

  if (!value) return [];

  return [toTextScrollItem(TEXT_SCROLL_ID, value)];
}

export async function createTextScrollItem(data: {
  text: string;
  show: boolean;
}) {
  const now = Date.now();
  const ref = realtimeDb.ref(TEXT_SCROLL_PATH);

  await ref.set({
    text: data.text,
    show: data.show,
    created_at: now,
    updated_at: now,
  });

  return TEXT_SCROLL_ID;
}

export async function updateTextScrollItem(
  id: string,
  data: {
    text: string;
    show: boolean;
  },
) {
  assertValidTextScrollId(id);

  await realtimeDb.ref(TEXT_SCROLL_PATH).update({
    text: data.text,
    show: data.show,
    updated_at: Date.now(),
  });
}

export async function deleteTextScrollItem(id: string) {
  assertValidTextScrollId(id);

  await realtimeDb.ref(TEXT_SCROLL_PATH).remove();
}
