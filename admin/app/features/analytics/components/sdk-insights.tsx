import Link from "next/link";
import { analyticsService, type AnalyticsFilters, type InsightsReport } from "../services/analytics-service";
import { analyticsHref, ReportError } from "./report-explorer";

const dimensions = [
  ["Playback", [
    ["platform", "Platform"], ["app_version", "App version"],
    ["content_type", "Content type"], ["content_id", "Content ID"],
    ["series_id", "Series ID"], ["season_id", "Season ID"],
    ["episode_id", "Episode ID"], ["downloaded", "Downloaded"],
    ["autoplay", "Autoplay"], ["state", "Session state"],
    ["end_reason", "End reason"], ["sdk_version", "SDK version"],
  ]],
  ["Stream quality", [
    ["initial_quality", "Initial quality"], ["final_quality", "Final quality"],
    ["video_codec", "Video codec"], ["audio_codec", "Audio codec"],
    ["stream_format", "Stream format"],
  ]],
  ["Audio and subtitles", [
    ["audio_language", "Audio language"], ["subtitle_enabled", "Subtitles on"],
    ["subtitle_language", "Subtitle language"], ["playback_speed", "Playback speed"],
  ]],
  ["Device and network", [
    ["network_type", "Network"], ["build_number", "Build number"],
    ["os_version", "OS version"], ["device_model", "Device model"],
    ["device_category", "Device category"], ["locale", "Locale"],
    ["device_timezone", "Device timezone"],
  ]],
] as const;

type Metric = readonly [key: string, label: string, unit?: "ms" | "hours" | "percent" | "kbps" | "bytes"];

const metricSections: readonly (readonly [string, readonly Metric[]])[] = [
  ["Sessions and outcomes", [
    ["sessions", "Playback sessions"], ["unique_viewers", "Unique viewers"],
    ["valid_views", "Valid views"], ["completed_sessions", "Completed"],
    ["pending_sessions", "Pending checkpoints"], ["error_sessions", "Sessions with errors"],
    ["playback_error_count", "Playback errors"], ["average_completion_percent", "Average completion", "percent"],
    ["downloaded_sessions", "Downloaded plays"], ["autoplay_sessions", "Autoplay sessions"],
    ["subtitle_sessions", "Subtitles enabled"],
  ]],
  ["Time and coverage", [
    ["watched_ms", "Watch time", "hours"], ["unique_watched_ms", "Unique watch time", "hours"],
    ["replayed_ms", "Replay time", "hours"], ["foreground_watch_ms", "Foreground time", "hours"],
    ["background_play_ms", "Background time", "hours"],
    ["average_duration_ms", "Average content duration", "ms"],
    ["average_watch_position_ms", "Average final position", "ms"],
    ["max_position_ms", "Sum of farthest positions", "hours"],
    ["average_startup_ms", "Average startup", "ms"],
  ]],
  ["Playback controls", [
    ["play_count", "Play"], ["pause_count", "Pause"], ["resume_count", "Resume"],
    ["seek_count", "Seeks"], ["seek_forward_ms", "Time skipped forward", "hours"],
    ["seek_backward_ms", "Time skipped backward", "hours"],
    ["fullscreen_count", "Fullscreen"], ["pip_count", "Picture in picture"],
    ["cast_count", "Cast"],
  ]],
  ["Buffering and quality", [
    ["buffer_count", "Buffer events"], ["buffer_ms", "Total buffering", "hours"],
    ["longest_buffer_ms", "Longest buffer", "ms"],
    ["quality_changes", "Quality changes"], ["average_bitrate_kbps", "Average bitrate", "kbps"],
    ["bytes_transferred", "Data transferred", "bytes"],
    ["dropped_frames", "Dropped frames"], ["rendered_frames", "Rendered frames"],
    ["average_playback_speed", "Average playback speed"],
  ]],
  ["Coverage milestones", [
    ["milestone_25", "Reached 25%"], ["milestone_50", "Reached 50%"],
    ["milestone_75", "Reached 75%"], ["milestone_90", "Reached 90%"],
  ]],
];

const metrics = metricSections.flatMap(([, items]) => items);
const metricByKey = new Map(metrics.map((item) => [item[0], item]));
const allDimensions: ReadonlySet<string> = new Set(dimensions.flatMap(([, items]) => items.map(([key]) => key)));

function valueFor(value: unknown, unit?: Metric[2]): string {
  if (value === null || value === undefined || value === "") return "—";
  const number = Number(value);
  if (!Number.isFinite(number)) return "—";
  if (unit === "bytes") {
    const exponent = number > 0 ? Math.min(Math.floor(Math.log(number) / Math.log(1024)), 4) : 0;
    const units = ["B", "KB", "MB", "GB", "TB"];
    return `${(number / (1024 ** exponent)).toLocaleString("en-IN", { maximumFractionDigits: 2 })} ${units[exponent]}`;
  }
  const formatted = (unit === "hours" ? number / 3_600_000 : number)
    .toLocaleString("en-IN", { maximumFractionDigits: unit === "hours" || unit === "percent" ? 2 : unit === "kbps" ? 1 : 0 });
  return unit === "hours" ? `${formatted} h`
    : unit === "ms" ? `${formatted} ms`
    : unit === "percent" ? `${formatted}%`
    : unit === "kbps" ? `${formatted} kbps`
    : formatted;
}

function dimensionLabel(value: unknown): string {
  if (value === null || value === undefined || value === "" || value === "null") return "Unknown / not sent";
  if (value === true || value === "true" || value === "1" || value === 1) return "Yes";
  if (value === false || value === "false" || value === "0" || value === 0) return "No";
  return String(value);
}

function Summary({ data }: { data: InsightsReport }) {
  return <div className="space-y-3">
    {metricSections.map(([title, items], index) => <details key={title} open={index === 0} className="group rounded-2xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900">
      <summary className="flex cursor-pointer items-center justify-between p-4 font-semibold text-slate-900 dark:text-white">{title}<span className="text-xs text-slate-500">{items.length} metrics</span></summary>
      <div className="grid gap-2 border-t border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 dark:border-white/10">
        {items.map(([key, label, unit]) => <div key={key} className="rounded-xl bg-slate-100/80 p-3 dark:bg-white/5"><p className="text-xs text-slate-500 dark:text-slate-400">{label}</p><p className="mt-1 text-xl font-bold tabular-nums text-slate-950 dark:text-white">{valueFor(data.summary[key], unit)}</p></div>)}
      </div>
    </details>)}
  </div>;
}

export async function SdkInsights({ filters, dimension: requestedDimension, metric: requestedMetric, page }: {
  filters: AnalyticsFilters; dimension?: string; metric?: string; page: number;
}) {
  const dimension = requestedDimension && allDimensions.has(requestedDimension) ? requestedDimension : "platform";
  const metric = requestedMetric && metricByKey.has(requestedMetric) ? requestedMetric : "sessions";
  let data: InsightsReport;
  try {
    data = await analyticsService.getInsights(filters, dimension, page);
  } catch (error) {
    return <ReportError error={error} href={analyticsHref(filters, { tab: "insights", dimension, metric })} />;
  }
  const selectedMetric = metricByKey.get(metric)!;
  const groups = data.groups.data;
  const largest = Math.max(...groups.map((row) => Number(row[metric]) || 0), 0);

  return <div className="space-y-4">
    <div className="rounded-2xl border border-cyan-200 bg-cyan-50 p-4 text-sm text-cyan-950 dark:border-cyan-300/20 dark:bg-cyan-300/5 dark:text-cyan-100">
      SDK v1 measurements are grouped below. Choose an attribute and metric to compare sessions. Open a session in the Sessions tab for its original values.
    </div>
    <Summary data={data} />
    <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-slate-900">
      <div className="mb-4"><h2 className="text-xl font-bold">Compare by attribute</h2><p className="mt-1 text-xs text-slate-500">All filters above apply to playback sessions. Unknown means the SDK did not send a value.</p></div>
      <form action="/analytics" className="grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
        {Object.entries({ ...filters, tab: "insights" }).map(([key, value]) => value ? <input key={key} type="hidden" name={key} value={value} /> : null)}
        <label className="text-xs font-semibold text-slate-500">Group sessions by
          <select name="dimension" defaultValue={dimension} className="mt-1 block h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-950 dark:border-white/15 dark:bg-slate-950 dark:text-white">
            {dimensions.map(([title, items]) => <optgroup key={title} label={title}>{items.map(([key, label]) => <option key={key} value={key}>{label}</option>)}</optgroup>)}
          </select>
        </label>
        <label className="text-xs font-semibold text-slate-500">Compare metric
          <select name="metric" defaultValue={metric} className="mt-1 block h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm text-slate-950 dark:border-white/15 dark:bg-slate-950 dark:text-white">
            {metricSections.map(([title, items]) => <optgroup key={title} label={title}>{items.map(([key, label]) => <option key={key} value={key}>{label}</option>)}</optgroup>)}
          </select>
        </label>
        <button type="submit" className="self-end rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white dark:bg-cyan-300 dark:text-slate-950">Compare</button>
      </form>
      <div className="mt-5 space-y-2">
        {groups.length ? groups.map((row, index) => {
          const label = dimensionLabel(row.dimension_label ?? row.dimension_value);
          const amount = Number(row[metric]) || 0;
          const href = dimension === "content_id" && row.dimension_value
            ? analyticsHref(filters, { tab: "sessions", content_id: String(row.dimension_value) })
            : null;
          return <div key={`${label}-${index}`} className="rounded-xl border border-slate-100 p-3 dark:border-white/10">
            <div className="flex items-center justify-between gap-3 text-sm"><span className="min-w-0 truncate font-semibold" title={label}>{href ? <Link href={href} className="text-cyan-700 underline dark:text-cyan-300">{label}</Link> : label}</span><span className="shrink-0 tabular-nums">{valueFor(row[metric], selectedMetric[2])}</span></div>
            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10"><div className="h-full rounded-full bg-cyan-500" style={{ width: `${largest > 0 ? Math.max(amount / largest * 100, 1) : 0}%` }} /></div>
            <p className="mt-1 text-xs text-slate-500">{valueFor(row.sessions)} sessions · {valueFor(row.unique_viewers)} viewers</p>
          </div>;
        }) : <p className="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 dark:border-white/10">No playback sessions match these filters.</p>}
      </div>
      {data.groups.last_page > 1 ? <nav aria-label="Insight pages" className="mt-5 flex items-center justify-between text-sm"><span>Page {data.groups.current_page} of {data.groups.last_page} · {data.groups.total} groups</span><div className="flex gap-4">{data.groups.current_page > 1 ? <Link className="underline" href={analyticsHref(filters, { tab: "insights", dimension, metric, page: String(data.groups.current_page - 1) })}>Previous</Link> : null}{data.groups.current_page < data.groups.last_page ? <Link className="underline" href={analyticsHref(filters, { tab: "insights", dimension, metric, page: String(data.groups.current_page + 1) })}>Next</Link> : null}</div></nav> : null}
    </section>
  </div>;
}
