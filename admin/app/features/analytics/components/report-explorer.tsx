import Link from "next/link";
import { Fragment } from "react";
import { SessionMetrics } from "./session-metrics";
import { ApiError } from "@/app/lib/api-client";
import { analyticsService, type AnalyticsFilters, type ReportRow, type ReportTab } from "../services/analytics-service";
import { analyticsPlatformLabel } from "../services/analytics-platform";

export function analyticsHref(filters: AnalyticsFilters, extra: Record<string, string> = {}) {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries({ ...filters, ...extra })) {
    if (value) query.set(key, value);
  }
  return `/analytics?${query}`;
}

export function ReportError({ error, href }: { error: unknown; href: string }) {
  const status = error instanceof ApiError ? error.status : 0;
  const message = status === 401 || status === 403
    ? "Your session or permissions do not allow this report. Sign in again with an admin account."
    : status === 422 ? "The report filters are invalid. Check the date range and filter values."
    : "The report could not be loaded. Please retry. If this continues, check the analytics service.";
  return <div role="alert" className="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-100"><p className="font-bold">Report unavailable</p><p className="mt-2">{message}</p><a href={href} className="mt-3 inline-block font-semibold underline">Retry report</a></div>;
}

const columns: Record<ReportTab | "errors/events", [string, string][]> = {
  content: [["title", "Content"], ["content_type", "Type"], ["playback_starts", "Sessions"], ["valid_views", "Valid views"], ["unique_viewers", "Viewers"], ["watch_hours", "Watch hours"], ["completion_rate", "Completion %"]],
  sessions: [["display_title", "Content"], ["user_id", "User"], ["started_at", "Started (IST)"], ["platform", "Platform"], ["app_version", "App version"], ["watched_ms", "Watched (min)"], ["completion_percent", "Completion %"], ["error_count", "Errors"], ["end_reason", "End reason"]],
  quality: [["platform", "Platform"], ["app_version", "App version"], ["sessions", "Sessions"], ["average_startup_ms", "Startup (ms)"], ["rebuffer_ratio", "Rebuffer %"], ["failure_rate", "Error rate %"]],
  errors: [["category", "Category"], ["stage", "Stage"], ["code", "Code"], ["occurrences", "Occurrences"], ["affected_sessions", "Sessions"], ["fatal_count", "Fatal"], ["last_seen_at", "Last seen (IST)"]],
  "errors/events": [["occurred_at", "Occurred (IST)"], ["session_id", "Session"], ["user_id", "User"], ["category", "Category"], ["code", "Code"], ["http_status", "HTTP status"], ["is_fatal", "Fatal"], ["retry_count", "Retries"], ["sanitized_message", "Message"]],
  events: [["name", "Event"], ["occurred_at", "Occurred (IST)"], ["user_id", "User"], ["platform", "Platform"], ["app_version", "App version"]],
};

function cell(row: ReportRow, key: string): string {
  const value = key === "title" ? row.title || row.content_id
    : key === "display_title" ? row.display_title || row.title || row.content_id
    : row[key];
  if (value === null || value === undefined || value === "") return "—";
  if (key.endsWith("_at")) {
    const timestamp = String(value).replace(" ", "T");
    const date = new Date(/[zZ]$|[+-]\d{2}:?\d{2}$/.test(timestamp) ? timestamp : `${timestamp}Z`);
    return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat("en-IN", { timeZone: "Asia/Kolkata", dateStyle: "medium", timeStyle: "short" }).format(date);
  }
  if (key === "is_fatal") return value === true || value === 1 || value === "1" ? "Yes" : "No";
  if (key === "watched_ms") return (Number(value) / 60_000).toFixed(1);
  if (key === "rebuffer_ratio") return (Number(value) * 100).toFixed(2);
  if (key === "platform") return analyticsPlatformLabel(value);
  return typeof value === "object" ? JSON.stringify(value) : String(value);
}

export async function ReportExplorer({ tab, filters, page, contentId, sessionId, errorMode }: {
  tab: ReportTab; filters: AnalyticsFilters; page: string; contentId?: string; sessionId?: string; errorMode?: string;
}) {
  const report = tab === "errors" && errorMode === "events" ? "errors/events" : tab;
  const navigation = { tab, ...(contentId ? { content_id: contentId } : {}), ...(sessionId ? { session_id: sessionId } : {}), ...(errorMode ? { error_mode: errorMode } : {}) };
  let result;
  try {
    result = await analyticsService.getReport(report, filters, { page, per_page: 25, ...(tab === "sessions" && contentId ? { content_id: contentId } : {}), ...(tab === "sessions" && sessionId ? { q: sessionId } : {}) });
  } catch (error) {
    return <ReportError error={error} href={analyticsHref(filters, { ...navigation, page })} />;
  }
  const rows = Array.isArray(result) ? result : result.data;
  const pagination = Array.isArray(result) ? null : result;
  return <section className="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-slate-900">
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div><h2 className="text-xl font-bold capitalize">{tab === "sessions" && filters.user_id ? "User playback history" : tab}</h2><p className="mt-1 text-xs text-slate-500">{pagination ? `${pagination.total.toLocaleString()} records · 25 per page` : `${rows.length} groups${tab === "errors" ? " · up to 200 most frequent" : ""}`}{contentId && tab === "sessions" ? ` · Content: ${String(rows[0]?.display_title || rows[0]?.title || contentId)}` : ""}{sessionId && tab === "sessions" ? ` · Session: ${sessionId}` : ""}</p></div>
      {tab === "errors" ? <div className="flex gap-4 text-sm"><Link className="underline" href={analyticsHref(filters, { tab: "errors" })}>Grouped errors</Link><Link className="underline" href={analyticsHref(filters, { tab: "errors", error_mode: "events" })}>Individual errors</Link></div> : null}
    </div>
    {contentId && tab === "sessions" ? <Link className="mb-4 inline-block text-xs underline" href={analyticsHref(filters, { tab })}>Clear content selection</Link> : null}
    {sessionId && tab === "sessions" ? <Link className="mb-4 ml-4 inline-block text-xs underline" href={analyticsHref(filters, { tab })}>Clear session selection</Link> : null}
    {tab === "events" ? <p className="mb-4 text-xs text-slate-500">App events use date, platform, app version and user filters. Content type does not apply.</p> : null}
    {rows.length ? <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead><tr className="border-b border-slate-200 text-xs text-slate-500 dark:border-white/10">
          {columns[report].map(([key, label]) => <th key={key} className="whitespace-nowrap px-3 py-3 font-semibold">{label}</th>)}
          {["events", "errors/events"].includes(report) ? <th className="px-3 py-3">Details</th> : null}
        </tr></thead>
        <tbody className="divide-y divide-slate-100 dark:divide-white/10">
          {rows.map((row, index) => <Fragment key={String(row.event_id ?? row.session_id ?? row.dimension_value ?? index)}>
            <tr className="align-top hover:bg-slate-50 dark:hover:bg-white/5">
              {columns[report].map(([key]) => <td key={key} className="max-w-xs break-words px-3 py-4">
                {tab === "content" && key === "title" ? <div><Link className="font-semibold text-cyan-700 underline dark:text-cyan-300" href={analyticsHref({ ...filters, content_type: String(row.content_type) }, { tab: "sessions", content_id: String(row.content_id) })}>{cell(row, key)}</Link>{row.parent_title ? <p className="mt-1 text-xs text-slate-500">Series: {String(row.parent_title)}</p> : null}</div>
                  : key === "user_id" ? <Link className="text-cyan-700 underline dark:text-cyan-300" href={analyticsHref({ ...filters, user_id: String(row.user_id) }, { tab: "sessions" })}>{cell(row, key)}</Link>
                  : report === "errors/events" && key === "session_id" ? <Link className="text-cyan-700 underline dark:text-cyan-300" href={analyticsHref(filters, { tab: "sessions", session_id: String(row.session_id) })}>{cell(row, key)}</Link>
                  : cell(row, key)}
              </td>)}
              {["events", "errors/events"].includes(report) ? <td className="px-3 py-4"><details><summary className="cursor-pointer whitespace-nowrap text-cyan-700 dark:text-cyan-300">View details</summary><dl className="mt-3 w-72 space-y-2 text-xs">{Object.entries(row).map(([key, value]) => <div key={key}><dt className="font-semibold text-slate-500">{key.replaceAll("_", " ")}</dt><dd className="mt-1 break-words whitespace-pre-wrap">{key === "properties" && value && typeof value === "object" && !Array.isArray(value) ? <dl className="space-y-1">{Object.entries(value as Record<string, unknown>).map(([property, item]) => <div key={property} className="flex gap-2"><dt className="font-semibold">{property.replaceAll("_", " ")}:</dt><dd>{Array.isArray(item) ? item.join(", ") : String(item ?? "—")}</dd></div>)}</dl> : typeof value === "object" && value !== null ? JSON.stringify(value, null, 2) : String(value ?? "—")}</dd></div>)}</dl></details></td> : null}
            </tr>
            {tab === "sessions" ? <tr><td colSpan={columns.sessions.length} className="px-3 pb-4"><SessionMetrics row={row} /></td></tr> : null}
          </Fragment>)}
        </tbody>
      </table>
    </div> : <p className="rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-white/15">No records match these filters. Try another date range or clear the filters.</p>}

    {pagination && pagination.last_page > 1 ? <nav aria-label="Report pagination" className="mt-5 flex items-center justify-between text-sm"><span>Page {pagination.current_page} of {pagination.last_page}</span><div className="flex gap-4">{pagination.current_page > 1 ? <Link className="underline" href={analyticsHref(filters, { ...navigation, page: String(pagination.current_page - 1) })}>Previous</Link> : null}{pagination.current_page < pagination.last_page ? <Link className="underline" href={analyticsHref(filters, { ...navigation, page: String(pagination.current_page + 1) })}>Next</Link> : null}</div></nav> : null}
  </section>;
}
