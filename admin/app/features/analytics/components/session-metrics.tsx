import type { ReportRow } from "../services/analytics-service";

type Item = readonly [path: string, label: string, unit?: "ms" | "percent" | "bytes"];

const sections: readonly (readonly [string, readonly Item[]])[] = [
  ["Content and session", [
    ["schema_version", "Schema version"], ["revision", "Revision"],
    ["state", "State"], ["started_at", "Started"], ["ended_at", "Ended"],
    ["end_reason", "End reason"], ["content.id", "Content ID"],
    ["content.type", "Content type"], ["content.series_id", "Series ID"],
    ["content.season_id", "Season ID"], ["content.episode_id", "Episode ID"],
    ["content.is_downloaded", "Downloaded"], ["content.autoplay", "Autoplay"],
  ]],
  ["Timing and coverage", [
    ["timing.duration_ms", "Content duration", "ms"],
    ["timing.watch_position_ms", "Final position", "ms"],
    ["timing.max_position_ms", "Farthest position", "ms"],
    ["timing.watched_ms", "Watch time", "ms"],
    ["timing.unique_watched_ms", "Unique watched", "ms"],
    ["timing.replayed_ms", "Replayed", "ms"],
    ["timing.foreground_watch_ms", "Foreground", "ms"],
    ["timing.background_play_ms", "Background", "ms"],
    ["timing.startup_ms", "Startup", "ms"],
  ]],
  ["Controls and buffering", [
    ["interaction.play_count", "Play"], ["interaction.pause_count", "Pause"],
    ["interaction.resume_count", "Resume"], ["interaction.seek_count", "Seeks"],
    ["interaction.seek_forward_ms", "Skipped forward", "ms"],
    ["interaction.seek_backward_ms", "Skipped backward", "ms"],
    ["interaction.fullscreen_count", "Fullscreen"],
    ["interaction.pip_count", "Picture in picture"],
    ["interaction.cast_count", "Cast"],
    ["buffering.count", "Buffer events"],
    ["buffering.total_ms", "Buffer time", "ms"],
    ["buffering.longest_ms", "Longest buffer", "ms"],
  ]],
  ["Video and audio quality", [
    ["quality.initial", "Initial quality"], ["quality.final", "Final quality"],
    ["quality.change_count", "Quality changes"],
    ["quality.average_bitrate_kbps", "Average bitrate (kbps)"],
    ["quality.bytes_transferred", "Data transferred", "bytes"],
    ["quality.dropped_frames", "Dropped frames"],
    ["quality.rendered_frames", "Rendered frames"],
    ["quality.video_codec", "Video codec"], ["quality.audio_codec", "Audio codec"],
    ["quality.stream_format", "Stream format"],
    ["tracks.audio_language", "Audio language"],
    ["tracks.subtitle_enabled", "Subtitles enabled"],
    ["tracks.subtitle_language", "Subtitle language"],
    ["tracks.playback_speed", "Playback speed"],
  ]],
  ["Outcome", [
    ["result.error_count", "Error count"],
    ["result.completion_percent", "Completion", "percent"],
    ["result.completed", "Completed"],
    ["result.milestones", "Coverage milestones"],
  ]],
  ["Device and connection", [
    ["context.platform", "Platform"], ["context.network_type", "Network"],
    ["context.app_version", "App version"],
    ["context.build_number", "Build number"],
    ["context.os_version", "OS version"],
    ["context.device_model", "Device model"],
    ["context.device_category", "Device category"],
    ["context.locale", "Locale"], ["context.timezone", "Device timezone"],
  ]],
];

function nestedValue(record: Record<string, unknown>, path: string): unknown {
  return path.split(".").reduce<unknown>((value, part) => {
    return value && typeof value === "object" ? (value as Record<string, unknown>)[part] : undefined;
  }, record);
}

function display(value: unknown, unit?: Item[2]): string {
  if (value === undefined || value === null || value === "") return "—";
  if (typeof value === "boolean") return value ? "Yes" : "No";
  if (Array.isArray(value)) return value.length ? value.join("%, ") + "%" : "None";
  if (typeof value === "number" && unit === "bytes") {
    const exponent = value > 0 ? Math.min(Math.floor(Math.log(value) / Math.log(1024)), 4) : 0;
    const units = ["B", "KB", "MB", "GB", "TB"];
    return `${(value / (1024 ** exponent)).toLocaleString("en-IN", { maximumFractionDigits: 2 })} ${units[exponent]}`;
  }
  if (typeof value === "number") return `${value.toLocaleString("en-IN", { maximumFractionDigits: 2 })}${unit === "ms" ? " ms" : unit === "percent" ? "%" : ""}`;
  return String(value);
}

export function SessionMetrics({ row }: { row: ReportRow }) {
  const payload = row.metrics && typeof row.metrics === "object" ? row.metrics as Record<string, unknown> : {};
  const session = { ...payload, revision: row.revision, state: row.state, started_at: row.started_at, ended_at: row.ended_at, end_reason: row.end_reason };
  return <details className="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
    <summary className="cursor-pointer text-sm font-semibold text-cyan-700 dark:text-cyan-300">View all SDK measurements</summary>
    <div className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500">
      <span>Session: {String(row.session_id ?? "—")}</span>
      <span>User: {String(row.user_id ?? "—")}</span>
      <span>Device: {String(row.device_id ?? "—")}</span>
      <span>SDK: {String(row.sdk_version ?? "—")}</span>
    </div>
    <div className="mt-4 grid gap-3 lg:grid-cols-2">
      {sections.map(([title, items]) => <section key={title} className="rounded-xl bg-white p-3 dark:bg-slate-900"><h3 className="mb-3 text-sm font-bold">{title}</h3><dl className="grid grid-cols-2 gap-2 sm:grid-cols-3">{items.map(([path, label, unit]) => <div key={path} className="min-w-0"><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-0.5 break-words text-sm font-semibold tabular-nums">{display(nestedValue(session, path), unit)}</dd></div>)}</dl></section>)}
    </div>
  </details>;
}
