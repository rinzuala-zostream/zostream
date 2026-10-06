import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const directory = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(directory, "../..");
const schema = JSON.parse(fs.readFileSync(path.join(root, "Library/contract/playback-summary.schema.json"), "utf8"));
const sessionView = fs.readFileSync(path.join(directory, "../app/features/analytics/components/session-metrics.tsx"), "utf8");

test("admin session details expose every SDK v1 playback field", () => {
  const shown = new Set([...sessionView.matchAll(/\["([a-z_]+(?:\.[a-z_]+)?)", "/g)].map((match) => match[1]));
  const missing = [];

  for (const [section, definition] of Object.entries(schema.properties)) {
    if (["content", "timing", "interaction", "buffering", "quality", "tracks", "result", "context"].includes(section)) {
      for (const field of Object.keys(definition.properties)) {
        if (!shown.has(`${section}.${field}`)) missing.push(`${section}.${field}`);
      }
    } else if (!shown.has(section)) {
      missing.push(section);
    }
  }

  assert.deepEqual(missing, []);
});
