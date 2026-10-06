import { cookies } from "next/headers";
import {
  Activity,
  AlertTriangle,
  CalendarDays,
  ChartNoAxesCombined,
  CirclePlay,
  Clock3,
  Eye,
  Gauge,
  MonitorSmartphone,
  RadioTower,
  Users,
} from "lucide-react";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { AnalyticsCollectionSwitch } from "@/app/features/analytics/components/analytics-collection-switch";
import {
  analyticsService,
  type AnalyticsContentStat,
  type AnalyticsDashboardData,
  type AnalyticsEventStat,
  type AnalyticsHealthPoint,
  type AnalyticsPlatform,
  type AnalyticsPlatformStat,
  type AnalyticsTrendPoint,
} from "@/app/features/analytics/services/analytics-service";
import { cn } from "@/lib/utils";

export const dynamic = "force-dynamic";

type AnalyticsPageProps = {
  searchParams?: Promise<{
    from?: string;
    to?: string;
    platform?: string;
  }>;
};

const PLATFORM_OPTIONS: Array<{
  label: string;
  value: AnalyticsPlatform | "all";
}> = [
  { label: "All platforms", value: "all" },
  { label: "iOS", value: "ios" },
  { label: "Android", value: "android" },
  { label: "TV", value: "tv" },
];

const platformColors = ["#22d3ee", "#6366f1", "#a855f7", "#f59e0b", "#10b981"];

function formatDateInput(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function safeDate(value: string | undefined, fallback: string) {
  return /^\d{4}-\d{2}-\d{2}$/.test(value ?? "") ? value! : fallback;
}

function normalizePlatform(value?: string): AnalyticsPlatform | undefined {
  return value === "ios" || value === "android" || value === "tv"
    ? value
    : undefined;
}

function formatNumber(value?: number | null, maximumFractionDigits = 0) {
  return new Intl.NumberFormat("en-IN", { maximumFractionDigits }).format(
    value ?? 0,
  );
}

function formatPercent(value?: number | null, digits = 1) {
  return `${formatNumber(value, digits)}%`;
}

function shortDate(value: string) {
  const date = new Date(`${value}T00:00:00`);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat("en-IN", { month: "short", day: "numeric" }).format(date);
}

function formatEventName(value: string) {
  return value
    .split("_")
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

function MetricCard({
  label,
  value,
  detail,
  icon: Icon,
  tone,
}: {
  label: string;
  value: string;
  detail: string;
  icon: typeof Activity;
  tone: string;
}) {
  return (
    <article className="group relative overflow-hidden rounded-[1.4rem] border border-white/65 bg-white/78 p-4 shadow-[0_16px_42px_rgba(15,23,42,0.08)] backdrop-blur-xl transition hover:-translate-y-0.5 hover:shadow-[0_20px_50px_rgba(15,23,42,0.12)] dark:border-white/10 dark:bg-white/6 dark:shadow-[0_18px_48px_rgba(2,6,23,0.34)]">
      <div className={cn("absolute inset-x-0 top-0 h-1 bg-gradient-to-r", tone)} />
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-[0.68rem] font-bold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">
            {label}
          </p>
          <p className="mt-3 text-2xl font-black tracking-tight text-slate-950 dark:text-white">
            {value}
          </p>
        </div>
        <div className={cn("grid size-10 shrink-0 place-items-center rounded-2xl bg-gradient-to-br text-white shadow-lg", tone)}>
          <Icon className="size-5" />
        </div>
      </div>
      <p className="mt-3 text-xs font-medium text-slate-500 dark:text-slate-400">
        {detail}
      </p>
    </article>
  );
}

function Panel({
  eyebrow,
  title,
  caption,
  children,
  className,
}: {
  eyebrow: string;
  title: string;
  caption?: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <section className={cn("rounded-[1.65rem] border border-white/65 bg-white/78 p-4 shadow-[0_18px_52px_rgba(15,23,42,0.08)] backdrop-blur-xl sm:p-5 dark:border-white/10 dark:bg-white/6 dark:shadow-[0_20px_60px_rgba(2,6,23,0.38)]", className)}>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-[0.68rem] font-bold uppercase tracking-[0.22em] text-cyan-700 dark:text-cyan-300">
            {eyebrow}
          </p>
          <h2 className="mt-1.5 text-xl font-bold tracking-tight text-slate-950 dark:text-white">
            {title}
          </h2>
        </div>
        {caption ? (
          <p className="max-w-md text-xs leading-5 text-slate-500 dark:text-slate-400">
            {caption}
          </p>
        ) : null}
      </div>
      {children}
    </section>
  );
}

function WatchTrendChart({ points }: { points: AnalyticsTrendPoint[] }) {
  const width = 760;
  const height = 260;
  const left = 48;
  const right = 18;
  const top = 20;
  const bottom = 42;
  const values = points.map((item) => item.watch_hours ?? 0);
  const max = Math.max(...values, 1);
  const plotWidth = width - left - right;
  const plotHeight = height - top - bottom;
  const coordinates = points.map((item, index) => ({
    x: left + (index * plotWidth) / Math.max(points.length - 1, 1),
    y: top + plotHeight - ((item.watch_hours ?? 0) / max) * plotHeight,
    item,
  }));
  const line = coordinates.map(({ x, y }) => `${x},${y}`).join(" ");
  const area = coordinates.length
    ? `M ${coordinates[0].x} ${top + plotHeight} L ${line.replaceAll(",", " ")} L ${coordinates.at(-1)!.x} ${top + plotHeight} Z`
    : "";

  return (
    <div className="relative mt-5 min-h-64 overflow-hidden rounded-[1.35rem] border border-slate-200/80 bg-slate-950 p-2 text-white dark:border-white/10">
      {points.length ? (
        <svg viewBox={`0 0 ${width} ${height}`} className="h-64 w-full" role="img" aria-label="Watch hours trend">
          <defs>
            <linearGradient id="watch-area" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" stopColor="#22d3ee" stopOpacity="0.42" />
              <stop offset="100%" stopColor="#6366f1" stopOpacity="0.02" />
            </linearGradient>
            <linearGradient id="watch-line" x1="0" x2="1">
              <stop offset="0%" stopColor="#22d3ee" />
              <stop offset="100%" stopColor="#818cf8" />
            </linearGradient>
          </defs>
          {[0, 0.25, 0.5, 0.75, 1].map((ratio) => {
            const y = top + plotHeight * ratio;
            return <line key={ratio} x1={left} x2={width - right} y1={y} y2={y} stroke="rgba(148,163,184,.16)" />;
          })}
          <path d={area} fill="url(#watch-area)" />
          <polyline points={line} fill="none" stroke="url(#watch-line)" strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" />
          {coordinates.map(({ x, y, item }, index) => (
            <g key={`${item.date}-${index}`}>
              <circle cx={x} cy={y} r="5" fill="#020617" stroke="#67e8f9" strokeWidth="3" />
              {(points.length <= 8 || index % Math.ceil(points.length / 7) === 0 || index === points.length - 1) ? (
                <text x={x} y={height - 14} textAnchor="middle" fill="#94a3b8" fontSize="11">
                  {shortDate(item.date)}
                </text>
              ) : null}
            </g>
          ))}
          <text x="10" y="28" fill="#94a3b8" fontSize="11">{formatNumber(max, 1)}h</text>
          <text x="18" y={top + plotHeight + 4} fill="#94a3b8" fontSize="11">0h</text>
        </svg>
      ) : (
        <ChartEmpty message="Watch-time trend will appear after the report API receives playback summaries." />
      )}
    </div>
  );
}

function StreamingHealthChart({ points }: { points: AnalyticsHealthPoint[] }) {
  const maxStartup = Math.max(...points.map((point) => point.average_startup_ms ?? 0), 1);

  return (
    <div className="mt-5 min-h-64 rounded-[1.35rem] border border-slate-200/80 bg-slate-950 p-4 text-white dark:border-white/10">
      {points.length ? (
        <>
          <div className="flex flex-wrap gap-4 text-[0.68rem] font-semibold text-slate-300">
            <span className="inline-flex items-center gap-2"><i className="size-2 rounded-full bg-cyan-400" />Startup time</span>
            <span className="inline-flex items-center gap-2"><i className="size-2 rounded-full bg-fuchsia-400" />Rebuffer rate</span>
            <span className="inline-flex items-center gap-2"><i className="size-2 rounded-full bg-emerald-400" />Playback success</span>
          </div>
          <div className="mt-6 grid h-44 grid-flow-col items-end gap-3 border-b border-white/10 px-1">
            {points.map((point) => {
              const startupHeight = Math.max(((point.average_startup_ms ?? 0) / maxStartup) * 100, 3);
              const successHeight = Math.max(point.playback_success_rate ?? 0, 3);
              const rebufferHeight = Math.max((point.rebuffer_rate ?? 0) * 5, 3);
              return (
                <div key={point.date} className="flex h-full min-w-10 flex-col justify-end">
                  <div className="flex flex-1 items-end justify-center gap-1">
                    <div className="w-2.5 rounded-t bg-cyan-400" style={{ height: `${startupHeight}%` }} title={`${formatNumber(point.average_startup_ms)} ms startup`} />
                    <div className="w-2.5 rounded-t bg-fuchsia-400" style={{ height: `${rebufferHeight}%` }} title={`${formatPercent(point.rebuffer_rate)} rebuffer`} />
                    <div className="w-2.5 rounded-t bg-emerald-400" style={{ height: `${successHeight}%` }} title={`${formatPercent(point.playback_success_rate)} success`} />
                  </div>
                  <p className="mt-2 truncate text-center text-[0.62rem] text-slate-400">{shortDate(point.date)}</p>
                </div>
              );
            })}
          </div>
        </>
      ) : (
        <ChartEmpty message="Startup, buffering and playback success data will appear here." />
      )}
    </div>
  );
}

function ChartEmpty({ message }: { message: string }) {
  return (
    <div className="grid min-h-60 place-items-center px-5 text-center">
      <div>
        <RadioTower className="mx-auto size-8 text-cyan-300" />
        <p className="mt-3 text-sm font-semibold text-slate-200">Waiting for analytics data</p>
        <p className="mx-auto mt-1 max-w-sm text-xs leading-5 text-slate-400">{message}</p>
      </div>
    </div>
  );
}

function PlatformDonut({ items }: { items: AnalyticsPlatformStat[] }) {
  const total = items.reduce((sum, item) => sum + (item.sessions ?? item.viewers ?? 0), 0);
  const percentages = items.map((item) => {
    const raw = item.percentage ?? (total ? ((item.sessions ?? item.viewers ?? 0) / total) * 100 : 0);
    return Math.max(raw, 0);
  });
  const segments = percentages.map((value, index) => {
    const start = percentages
      .slice(0, index)
      .reduce((sum, percentage) => sum + percentage, 0);
    const end = start + value;
    return `${platformColors[index % platformColors.length]} ${start}% ${end}%`;
  });

  return (
    <div className="mt-6 grid items-center gap-6 sm:grid-cols-[170px_1fr]">
      <div className="relative mx-auto size-40 rounded-full p-5" style={{ background: segments.length ? `conic-gradient(${segments.join(",")})` : "conic-gradient(#334155 0 100%)" }}>
        <div className="grid size-full place-items-center rounded-full bg-white text-center shadow-inner dark:bg-slate-950">
          <div>
            <p className="text-2xl font-black text-slate-950 dark:text-white">{formatNumber(total)}</p>
            <p className="text-[0.62rem] font-bold uppercase tracking-[0.16em] text-slate-500">Sessions</p>
          </div>
        </div>
      </div>
      <div className="space-y-2.5">
        {items.length ? items.map((item, index) => {
          const value = item.sessions ?? item.viewers ?? 0;
          const percentage = item.percentage ?? (total ? (value / total) * 100 : 0);
          return (
            <div key={item.platform} className="flex items-center justify-between gap-3 rounded-xl bg-slate-950/[0.04] px-3 py-2 dark:bg-white/6">
              <span className="inline-flex items-center gap-2 text-xs font-semibold capitalize text-slate-700 dark:text-slate-200">
                <i className="size-2.5 rounded-full" style={{ backgroundColor: platformColors[index % platformColors.length] }} />
                {item.platform}
              </span>
              <span className="text-xs font-bold text-slate-950 dark:text-white">{formatPercent(percentage)}</span>
            </div>
          );
        }) : <p className="text-center text-sm text-slate-500 sm:text-left">No platform sessions yet.</p>}
      </div>
    </div>
  );
}

function TopContentTable({ items }: { items: AnalyticsContentStat[] }) {
  return (
    <div className="mt-5 overflow-x-auto rounded-[1.25rem] border border-slate-200/80 dark:border-white/10">
      <table className="w-full min-w-[620px] text-left text-sm">
        <thead className="bg-slate-950 text-[0.66rem] uppercase tracking-[0.16em] text-slate-300">
          <tr>
            <th className="px-4 py-3 font-bold">Content</th>
            <th className="px-4 py-3 font-bold">Views</th>
            <th className="px-4 py-3 font-bold">Watch hours</th>
            <th className="px-4 py-3 font-bold">Completion</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-200/80 dark:divide-white/10">
          {items.length ? items.map((item, index) => (
            <tr key={`${item.content_type}-${item.content_id}`} className="bg-white/45 transition hover:bg-cyan-50/70 dark:bg-white/[0.02] dark:hover:bg-cyan-300/[0.06]">
              <td className="px-4 py-3">
                <div className="flex items-center gap-3">
                  <span className="grid size-8 shrink-0 place-items-center rounded-xl bg-slate-950 text-xs font-black text-white dark:bg-white dark:text-slate-950">{index + 1}</span>
                  <div>
                    <p className="font-bold text-slate-950 dark:text-white">{item.title || item.content_id}</p>
                    <p className="mt-0.5 text-xs capitalize text-slate-500">{item.content_type || "content"}</p>
                  </div>
                </div>
              </td>
              <td className="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">{formatNumber(item.views)}</td>
              <td className="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">{formatNumber(item.watch_hours, 1)}h</td>
              <td className="px-4 py-3">
                <div className="flex items-center gap-3">
                  <div className="h-2 w-20 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10"><div className="h-full rounded-full bg-gradient-to-r from-cyan-400 to-indigo-500" style={{ width: `${Math.min(Math.max(item.completion_rate ?? 0, 0), 100)}%` }} /></div>
                  <span className="font-bold text-slate-950 dark:text-white">{formatPercent(item.completion_rate)}</span>
                </div>
              </td>
            </tr>
          )) : (
            <tr><td colSpan={4} className="px-5 py-12 text-center text-sm text-slate-500">Top content will appear after playback summaries are processed.</td></tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

function ProductEvents({ items }: { items: AnalyticsEventStat[] }) {
  const max = Math.max(...items.map((item) => item.count ?? 0), 1);
  return (
    <div className="mt-5 space-y-3">
      {items.length ? items.slice(0, 7).map((item, index) => (
        <div key={item.name} className="rounded-xl border border-slate-200/80 bg-white/55 p-3 dark:border-white/10 dark:bg-white/[0.03]">
          <div className="flex items-center justify-between gap-3 text-xs">
            <span className="font-bold text-slate-800 dark:text-slate-100">{formatEventName(item.name)}</span>
            <span className="font-black text-slate-950 dark:text-white">{formatNumber(item.count)}</span>
          </div>
          <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
            <div className="h-full rounded-full" style={{ width: `${Math.max(((item.count ?? 0) / max) * 100, 2)}%`, background: `linear-gradient(90deg, ${platformColors[index % platformColors.length]}, ${platformColors[(index + 1) % platformColors.length]})` }} />
          </div>
          <p className="mt-2 text-[0.68rem] text-slate-500">{formatNumber(item.unique_users)} unique users</p>
        </div>
      )) : <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 dark:border-white/10">Product events will appear after app activity is uploaded.</div>}
    </div>
  );
}

export default async function AnalyticsPage({ searchParams }: AnalyticsPageProps) {
  const [cookieStore, params] = await Promise.all([cookies(), searchParams]);
  const initialMode = cookieStore.get("theme-mode")?.value === "dark" ? "dark" : "light";
  const defaultTo = formatDateInput(new Date());
  const fromDate = new Date();
  fromDate.setDate(fromDate.getDate() - 6);
  const defaultFrom = formatDateInput(fromDate);
  const from = safeDate(params?.from, defaultFrom);
  const to = safeDate(params?.to, defaultTo);
  const platform = normalizePlatform(params?.platform);

  let data: AnalyticsDashboardData = {};
  let reportUnavailable = false;
  try {
    data = await analyticsService.getDashboard({ from, to, platform });
  } catch {
    reportUnavailable = true;
  }

  const overview = data.overview ?? data;
  const trend = data.watch_trend ?? [];
  const health = data.streaming_health ?? [];
  const platforms = data.platforms ?? [];
  const topContent = data.top_content ?? [];
  const productEvents = data.product_events ?? [];
  const rangeLabel = `${shortDate(from)} – ${shortDate(to)}`;

  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 dark:text-white">
      <div className="flex w-full flex-1 flex-col lg:min-h-0">
        <AdminPageHeader title="Analytics" initialMode={initialMode} />
        <div className="admin-sidebar-scroll mt-3 flex-1 space-y-4 pb-5 lg:min-h-0">
          <section className="relative overflow-hidden rounded-[2rem] border border-white/60 bg-[radial-gradient(circle_at_top_left,rgba(14,165,233,0.2),transparent_34%),radial-gradient(circle_at_bottom_right,rgba(99,102,241,0.22),transparent_38%),linear-gradient(135deg,#071326,#0b1f3b_52%,#111b3d)] p-5 text-white shadow-[0_28px_80px_rgba(2,6,23,0.24)] sm:p-6 dark:border-white/10">
            <div className="absolute -right-16 -top-16 size-56 rounded-full border border-cyan-300/20 bg-cyan-300/5" />
            <div className="absolute -bottom-24 right-24 size-64 rounded-full border border-indigo-300/20 bg-indigo-400/5" />
            <div className="relative flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
              <div className="max-w-3xl">
                <span className="inline-flex items-center gap-2 rounded-full border border-cyan-300/20 bg-cyan-300/10 px-3 py-1 text-[0.68rem] font-bold uppercase tracking-[0.22em] text-cyan-200"><Activity className="size-3.5" />Streaming intelligence</span>
                <h2 className="mt-4 text-3xl font-black tracking-tight sm:text-4xl">Playback and audience health in one view.</h2>
                <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-300">Watch time, playback quality, content performance and product behaviour from ZoAnalytics SDK sessions.</p>
              </div>
              <form action="/analytics" className="grid gap-2 rounded-[1.35rem] border border-white/10 bg-white/7 p-3 backdrop-blur-xl sm:grid-cols-[1fr_1fr_150px_auto]">
                <label><span className="mb-1.5 block text-[0.62rem] font-bold uppercase tracking-[0.16em] text-slate-400">From</span><input type="date" name="from" defaultValue={from} className="h-10 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 text-xs font-semibold text-white outline-none focus:border-cyan-300/60" /></label>
                <label><span className="mb-1.5 block text-[0.62rem] font-bold uppercase tracking-[0.16em] text-slate-400">To</span><input type="date" name="to" defaultValue={to} className="h-10 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 text-xs font-semibold text-white outline-none focus:border-cyan-300/60" /></label>
                <label><span className="mb-1.5 block text-[0.62rem] font-bold uppercase tracking-[0.16em] text-slate-400">Platform</span><select name="platform" defaultValue={platform ?? "all"} className="h-10 w-full rounded-xl border border-white/10 bg-slate-950/60 px-3 text-xs font-semibold text-white outline-none focus:border-cyan-300/60">{PLATFORM_OPTIONS.map((option) => <option key={option.value} value={option.value === "all" ? "" : option.value}>{option.label}</option>)}</select></label>
                <button type="submit" className="mt-auto inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-cyan-400 to-indigo-500 px-4 text-xs font-black text-white shadow-lg shadow-cyan-950/30 transition hover:brightness-110"><CalendarDays className="size-4" />Apply</button>
              </form>
            </div>
          </section>

          <AnalyticsCollectionSwitch />

          {reportUnavailable ? <div className="flex items-start gap-3 rounded-[1.2rem] border border-amber-300/50 bg-amber-50/90 px-4 py-3 text-sm text-amber-900 dark:border-amber-300/15 dark:bg-amber-300/8 dark:text-amber-100"><AlertTriangle className="mt-0.5 size-4 shrink-0" /><div><p className="font-bold">Analytics report API is not available yet.</p><p className="mt-0.5 text-xs opacity-80">The page is ready and will populate automatically when the backend report endpoint is enabled.</p></div></div> : null}

          <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-8">
            <MetricCard label="Playback starts" value={formatNumber(overview.playback_starts)} detail={rangeLabel} icon={CirclePlay} tone="from-blue-500 to-cyan-400" />
            <MetricCard label="Valid views" value={formatNumber(overview.valid_views)} detail="Qualified playback sessions" icon={Eye} tone="from-cyan-500 to-teal-400" />
            <MetricCard label="Unique viewers" value={formatNumber(overview.unique_viewers)} detail="Distinct viewers in range" icon={Users} tone="from-emerald-500 to-green-400" />
            <MetricCard label="Watch hours" value={`${formatNumber(overview.watch_hours, 1)}h`} detail="Foreground and background play" icon={Clock3} tone="from-violet-500 to-indigo-500" />
            <MetricCard label="Avg. watch" value={`${formatNumber(overview.average_watch_minutes, 1)}m`} detail="Per valid view" icon={ChartNoAxesCombined} tone="from-fuchsia-500 to-pink-500" />
            <MetricCard label="Completion" value={formatPercent(overview.completion_rate)} detail="Completed playback sessions" icon={Gauge} tone="from-amber-500 to-orange-500" />
            <MetricCard label="Startup" value={`${formatNumber(overview.average_startup_ms)}ms`} detail="Average time to first frame" icon={RadioTower} tone="from-orange-500 to-rose-500" />
            <MetricCard label="Error rate" value={formatPercent(overview.playback_error_rate)} detail="Sessions with playback errors" icon={AlertTriangle} tone="from-rose-500 to-red-500" />
          </section>

          <div className="grid gap-4 2xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
            <Panel eyebrow="Audience trend" title="Watch hours over time" caption="Playback consumption across the selected date range."><WatchTrendChart points={trend} /></Panel>
            <Panel eyebrow="Quality of experience" title="Streaming health" caption="Startup speed, rebuffering and successful playback."><StreamingHealthChart points={health} /></Panel>
          </div>

          <div className="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,0.7fr)]">
            <Panel eyebrow="Content performance" title="Most watched titles" caption="Ranked by valid views, watch hours and completion."><TopContentTable items={topContent} /></Panel>
            <Panel eyebrow="Device analytics" title="Sessions by platform" caption="iOS, Android and TV distribution."><PlatformDonut items={platforms} /></Panel>
          </div>

          <div className="grid gap-4 xl:grid-cols-[minmax(320px,0.75fr)_minmax(0,1.4fr)]">
            <Panel eyebrow="Product behaviour" title="Top app events" caption="Actions recorded outside playback."><ProductEvents items={productEvents} /></Panel>
            <Panel eyebrow="Operational summary" title="What needs attention" caption="Fast signals for the selected range.">
              <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {[
                  { label: "App sessions", value: formatNumber(overview.app_sessions), icon: MonitorSmartphone, color: "text-cyan-600 dark:text-cyan-300" },
                  { label: "Rebuffer ratio", value: formatPercent((overview.rebuffer_ratio ?? 0) * 100), icon: Activity, color: "text-violet-600 dark:text-violet-300" },
                  { label: "Playback success", value: formatPercent(Math.max(0, 100 - (overview.playback_error_rate ?? 0))), icon: RadioTower, color: "text-emerald-600 dark:text-emerald-300" },
                  { label: "Platform focus", value: platform ? platform.toUpperCase() : "ALL", icon: Gauge, color: "text-amber-600 dark:text-amber-300" },
                ].map(({ label, value, icon: Icon, color }) => (
                  <article key={label} className="rounded-[1.2rem] border border-slate-200/80 bg-slate-950/[0.025] p-4 dark:border-white/10 dark:bg-white/[0.03]">
                    <Icon className={cn("size-5", color)} />
                    <p className="mt-6 text-2xl font-black text-slate-950 dark:text-white">{value}</p>
                    <p className="mt-1 text-xs font-semibold text-slate-500">{label}</p>
                  </article>
                ))}
              </div>
            </Panel>
          </div>
        </div>
      </div>
    </main>
  );
}
