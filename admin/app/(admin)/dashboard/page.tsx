import { cookies } from "next/headers";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { cn } from "@/lib/utils";
import {
  dashboardService,
  type DashboardDateField,
  type DashboardDeviceStat,
  type DashboardDeviceType,
  type DashboardPeriod,
  type DashboardPlanStat,
} from "@/app/features/dashboard/services/dashboard-service";

export const dynamic = "force-dynamic";

type DashboardPageProps = {
  searchParams?: Promise<{
    period?: string;
    date?: string;
    month?: string;
    year?: string;
    start_date?: string;
    end_date?: string;
    device_type?: string;
    date_field?: string;
  }>;
};

const PERIOD_OPTIONS: Array<{ label: string; value: DashboardPeriod }> = [
  { label: "Monthly", value: "monthly" },
  { label: "Daily", value: "daily" },
  { label: "Yearly", value: "yearly" },
  { label: "Custom", value: "custom" },
];

const DEVICE_OPTIONS: Array<{ label: string; value: DashboardDeviceType | "all" }> = [
  { label: "All devices", value: "all" },
  { label: "Mobile", value: "mobile" },
  { label: "Browser", value: "browser" },
  { label: "TV", value: "tv" },
];

const DATE_FIELD_OPTIONS: Array<{ label: string; value: DashboardDateField }> = [
  { label: "Created date", value: "created_at" },
  { label: "Start date", value: "start_at" },
  { label: "End date", value: "end_at" },
];

function TrendLine({ values }: { values: number[] }) {
  const points = values.length > 1 ? values : [0, 0];
  const max = Math.max(...points, 1);
  const stepX = 204 / Math.max(points.length - 1, 1);
  const coordinates = points
    .map((value, index) => {
      const x = 8 + index * stepX;
      const y = 104 - (value / max) * 76;
      return `${x},${Number.isFinite(y) ? y : 104}`;
    })
    .join(" ");

  return (
    <svg
      viewBox="0 0 220 120"
      className="h-24 w-full"
      aria-hidden="true"
    >
      <defs>
        <linearGradient id="dashboard-trend-line" x1="0%" x2="100%" y1="0%" y2="0%">
          <stop offset="0%" stopColor="#14b8a6" />
          <stop offset="100%" stopColor="#38bdf8" />
        </linearGradient>
      </defs>
      <path
        d={`M${coordinates} L212,116 L8,116 Z`}
        fill="url(#dashboard-trend-line)"
        opacity="0.14"
      />
      <polyline
        points={coordinates}
        fill="none"
        stroke="url(#dashboard-trend-line)"
        strokeWidth="4"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function formatNumber(value?: number | null) {
  return new Intl.NumberFormat("en-IN").format(value ?? 0);
}

function formatCurrency(value?: number | null) {
  return new Intl.NumberFormat("en-IN", {
    style: "currency",
    currency: "INR",
    maximumFractionDigits: 0,
  }).format(value ?? 0);
}

function normalizePeriod(value?: string): DashboardPeriod {
  return value === "daily" ||
    value === "monthly" ||
    value === "yearly" ||
    value === "custom"
    ? value
    : "monthly";
}

function normalizeDeviceType(value?: string): DashboardDeviceType | undefined {
  return value === "mobile" || value === "browser" || value === "tv"
    ? value
    : undefined;
}

function normalizeDateField(value?: string): DashboardDateField {
  return value === "start_at" || value === "end_at" ? value : "created_at";
}

function planDurationLabel(days?: number) {
  if (!days) return "Flexible duration";
  if (days >= 365) return `${Math.round(days / 365)} year plan`;
  if (days >= 30) return `${Math.round(days / 30)} month plan`;
  return `${days} day plan`;
}

function categoryLabel(key: string) {
  return key
    .split("_")
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

function categoryTone(index: number) {
  const tones = [
    "from-teal-500 via-cyan-500 to-sky-500",
    "from-amber-500 via-orange-500 to-rose-500",
    "from-violet-500 via-fuchsia-500 to-pink-500",
    "from-emerald-500 via-lime-500 to-yellow-500",
    "from-slate-600 via-slate-500 to-slate-400",
  ];

  return tones[index % tones.length];
}

function deviceTone(deviceType?: string | null) {
  if (deviceType === "mobile") return "bg-teal-500/12 text-teal-700 dark:bg-teal-400/14 dark:text-teal-100";
  if (deviceType === "browser") return "bg-sky-500/12 text-sky-700 dark:bg-sky-400/14 dark:text-sky-100";
  if (deviceType === "tv") return "bg-violet-500/12 text-violet-700 dark:bg-violet-400/14 dark:text-violet-100";
  return "bg-slate-500/12 text-slate-700 dark:bg-white/10 dark:text-slate-100";
}

function StatCard({
  label,
  value,
  accent,
  caption,
}: {
  label: string;
  value: string;
  accent: string;
  caption: string;
}) {
  return (
    <article className="relative overflow-hidden rounded-[1.5rem] border border-white/55 bg-white/72 p-5 shadow-[0_18px_45px_rgba(15,23,42,0.08)] backdrop-blur-xl dark:border-white/10 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.34)]">
      <div className={cn("absolute inset-x-5 top-0 h-1 rounded-full bg-gradient-to-r", accent)} />
      <p className="text-xs font-bold uppercase tracking-[0.24em] text-slate-500 dark:text-slate-400">
        {label}
      </p>
      <p className="mt-4 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl dark:text-white">
        {value}
      </p>
      <p className="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">
        {caption}
      </p>
    </article>
  );
}

function PlanCard({ plan, maxAmount }: { plan: DashboardPlanStat; maxAmount: number }) {
  const width = maxAmount > 0 ? Math.max(((plan.total_amount ?? 0) / maxAmount) * 100, 6) : 6;

  return (
    <article className="rounded-[1.35rem] border border-[rgba(15,23,42,0.08)] bg-white/68 p-4 shadow-[0_14px_36px_rgba(15,23,42,0.06)] dark:border-white/10 dark:bg-white/6">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 className="truncate text-base font-bold text-slate-950 dark:text-white">
            {plan.plan_name || "Plan"}
          </h3>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {planDurationLabel(plan.duration_days)}
          </p>
        </div>
        <span className={cn("inline-flex rounded-full px-3 py-1 text-xs font-bold capitalize", deviceTone(plan.device_type))}>
          {plan.device_type || "unknown"}
        </span>
      </div>

      <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
        <div className="rounded-2xl bg-slate-950/[0.04] px-3 py-2 dark:bg-white/8">
          <p className="text-slate-500 dark:text-slate-400">Active subs</p>
          <p className="mt-1 text-lg font-bold text-slate-950 dark:text-white">
            {formatNumber(plan.total_active_subscriptions)}
          </p>
        </div>
        <div className="rounded-2xl bg-slate-950/[0.04] px-3 py-2 dark:bg-white/8">
          <p className="text-slate-500 dark:text-slate-400">Plan price</p>
          <p className="mt-1 text-lg font-bold text-slate-950 dark:text-white">
            {formatCurrency(plan.plan_price)}
          </p>
        </div>
      </div>

      <div className="mt-4">
        <div className="flex items-center justify-between gap-3 text-xs font-bold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
          <span>Revenue weight</span>
          <span>{formatCurrency(plan.total_amount)}</span>
        </div>
        <div className="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-200/90 dark:bg-white/10">
          <div
            className="h-full rounded-full bg-[linear-gradient(90deg,#14b8a6,#38bdf8,#6366f1)]"
            style={{ width: `${width}%` }}
          />
        </div>
      </div>
    </article>
  );
}

function DeviceSplitCard({ item, maxAmount }: { item: DashboardDeviceStat; maxAmount: number }) {
  const width = maxAmount > 0 ? Math.max(((item.total_amount ?? 0) / maxAmount) * 100, 10) : 10;

  return (
    <article className="rounded-[1.35rem] border border-[rgba(15,23,42,0.08)] bg-white/68 p-4 shadow-[0_14px_36px_rgba(15,23,42,0.06)] dark:border-white/10 dark:bg-white/6">
      <div className="flex items-center justify-between gap-3">
        <span className={cn("inline-flex rounded-full px-3 py-1 text-xs font-bold capitalize", deviceTone(item.device_type))}>
          {item.device_type || "unknown"}
        </span>
        <span className="text-sm font-semibold text-slate-500 dark:text-slate-400">
          {formatNumber(item.total_active_subscriptions)} subs
        </span>
      </div>
      <p className="mt-4 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
        {formatCurrency(item.total_amount)}
      </p>
      <div className="mt-4 h-2.5 overflow-hidden rounded-full bg-slate-200/90 dark:bg-white/10">
        <div
          className="h-full rounded-full bg-[linear-gradient(90deg,#0f172a,#334155,#14b8a6)] dark:bg-[linear-gradient(90deg,#22d3ee,#6366f1,#f472b6)]"
          style={{ width: `${width}%` }}
        />
      </div>
    </article>
  );
}

export default async function DashboardPage({
  searchParams,
}: DashboardPageProps) {
  const [cookieStore, resolvedSearchParams] = await Promise.all([
    cookies(),
    searchParams,
  ]);
  const savedMode = cookieStore.get("theme-mode")?.value;
  const initialMode = savedMode === "dark" ? "dark" : "light";
  const period = normalizePeriod(resolvedSearchParams?.period);
  const deviceType = normalizeDeviceType(resolvedSearchParams?.device_type);
  const dateField = normalizeDateField(resolvedSearchParams?.date_field);
  const date = resolvedSearchParams?.date?.trim() || "";
  const month =
    resolvedSearchParams?.month?.trim() || new Date().toISOString().slice(0, 7);
  const currentYear = new Date().getFullYear();
  const year = Number(resolvedSearchParams?.year || currentYear);
  const startDate = resolvedSearchParams?.start_date?.trim() || "";
  const endDate = resolvedSearchParams?.end_date?.trim() || "";

  let errorMessage = "";
  let dashboard:
    | Awaited<ReturnType<typeof dashboardService.getOverview>>
    | undefined;

  try {
    dashboard = await dashboardService.getOverview({
      period,
      device_type: deviceType,
      date_field: dateField,
      date: period === "daily" ? date || undefined : undefined,
      month: period === "monthly" ? month || undefined : undefined,
      year: period === "yearly" && Number.isFinite(year) ? year : undefined,
      start_date: period === "custom" ? startDate || undefined : undefined,
      end_date: period === "custom" ? endDate || undefined : undefined,
    });

    if (dashboard.status === "error") {
      errorMessage = dashboard.message ?? "Dashboard could not be loaded.";
    }
  } catch (error) {
    errorMessage =
      error instanceof Error ? error.message : "Dashboard could not be loaded.";
  }

  const overview = dashboard?.data?.overview;
  const planStats = dashboard?.data?.active_subscriptions_by_plan ?? [];
  const deviceStats = dashboard?.data?.active_subscriptions_by_device ?? [];
  const planSummary = dashboard?.data?.plan_amount_summary;
  const content = dashboard?.data?.content;
  const movieCategoryEntries = Object.entries(content?.movies_by_category ?? {})
    .sort((a, b) => b[1] - a[1]);
  const maxPlanAmount = Math.max(...planStats.map((item) => item.total_amount ?? 0), 0);
  const maxDeviceAmount = Math.max(...deviceStats.map((item) => item.total_amount ?? 0), 0);
  const trendValues = [
    overview?.total_active_users ?? 0,
    overview?.total_users_with_active_subscription ?? 0,
    overview?.total_active_subscriptions ?? 0,
    content?.total_movies ?? 0,
    content?.total_episodes ?? 0,
    content?.total_seasons ?? 0,
  ];
  const periodLabel =
    PERIOD_OPTIONS.find((option) => option.value === period)?.label ?? "Monthly";

  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 lg:py-1 dark:text-white">
      <div className="flex w-full flex-1 flex-col lg:min-h-0">
        <AdminPageHeader title="Dashboard" initialMode={initialMode} />

        <div className="admin-sidebar-scroll mt-3 flex-1 space-y-4 pb-4 lg:min-h-0">
          <section className="relative overflow-hidden rounded-[2rem] border border-white/60 bg-[radial-gradient(circle_at_top_left,rgba(255,255,255,0.95),rgba(229,255,250,0.8)_35%,rgba(224,242,254,0.75)_68%,rgba(240,249,255,0.9))] p-5 shadow-[0_24px_70px_rgba(15,23,42,0.08)] backdrop-blur-xl sm:p-6 xl:p-7 dark:border-white/10 dark:bg-[radial-gradient(circle_at_top_left,rgba(15,23,42,0.96),rgba(17,94,89,0.28)_35%,rgba(30,41,59,0.92)_68%,rgba(3,7,18,0.95))] dark:shadow-[0_30px_80px_rgba(2,6,23,0.48)]">
            <div className="absolute -right-16 top-0 h-40 w-40 rounded-full bg-cyan-300/25 blur-3xl dark:bg-cyan-400/12" />
            <div className="absolute left-10 top-6 h-24 w-24 rounded-full bg-teal-300/30 blur-3xl dark:bg-teal-400/10" />

            <div className="relative grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(260px,0.75fr)]">
              <div className="min-w-0">
                <p className="text-xs font-bold uppercase tracking-[0.24em] text-teal-700 dark:text-cyan-200">
                  Admin analytics
                </p>
                <h2 className="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl dark:text-white">
                  One view for subscriptions, revenue, and content health.
                </h2>
                <p className="mt-4 max-w-3xl text-sm leading-7 text-slate-600 dark:text-slate-300">
                  Filter the dashboard by period, date field, and device type to
                  quickly understand how active plans and content inventory are
                  performing.
                </p>

                <form
                  action="/dashboard"
                  className="mt-6 grid gap-3 rounded-[1.5rem] border border-white/65 bg-white/72 p-3 shadow-[0_18px_40px_rgba(15,23,42,0.06)] md:grid-cols-2 xl:grid-cols-[140px_160px_160px_minmax(0,1fr)_minmax(0,1fr)_auto] dark:border-white/10 dark:bg-white/7"
                >
                  <label className="block min-w-0">
                    <span className="mb-2 block text-xs font-bold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                      Period
                    </span>
                    <select
                      name="period"
                      defaultValue={period}
                      className="min-h-11 w-full rounded-2xl border border-[rgba(15,23,42,0.12)] bg-white/85 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15"
                    >
                      {PERIOD_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </select>
                  </label>

                  <label className="block min-w-0">
                    <span className="mb-2 block text-xs font-bold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                      Device
                    </span>
                    <select
                      name="device_type"
                      defaultValue={deviceType ?? "all"}
                      className="min-h-11 w-full rounded-2xl border border-[rgba(15,23,42,0.12)] bg-white/85 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15"
                    >
                      {DEVICE_OPTIONS.map((option) => (
                        <option
                          key={option.value}
                          value={option.value === "all" ? "" : option.value}
                        >
                          {option.label}
                        </option>
                      ))}
                    </select>
                  </label>

                  <label className="block min-w-0">
                    <span className="mb-2 block text-xs font-bold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                      Date field
                    </span>
                    <select
                      name="date_field"
                      defaultValue={dateField}
                      className="min-h-11 w-full rounded-2xl border border-[rgba(15,23,42,0.12)] bg-white/85 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15"
                    >
                      {DATE_FIELD_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </select>
                  </label>

                  <label className="block min-w-0">
                    <span className="mb-2 block text-xs font-bold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                      Day / month
                    </span>
                    <div className="grid grid-cols-2 gap-3">
                      <input
                        name="date"
                        type="date"
                        defaultValue={date}
                        className="min-h-11 w-full rounded-2xl border border-[rgba(15,23,42,0.12)] bg-white/85 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15"
                      />
                      <input
                        name="month"
                        type="month"
                        defaultValue={month}
                        className="min-h-11 w-full rounded-2xl border border-[rgba(15,23,42,0.12)] bg-white/85 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15"
                      />
                    </div>
                  </label>

                  <label className="block min-w-0">
                    <span className="mb-2 block text-xs font-bold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                      Year / custom range
                    </span>
                    <div className="grid grid-cols-3 gap-3">
                      <input
                        name="year"
                        type="number"
                        min="2000"
                        max="2100"
                        defaultValue={Number.isFinite(year) ? String(year) : ""}
                        className="min-h-11 w-full rounded-2xl border border-[rgba(15,23,42,0.12)] bg-white/85 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15"
                      />
                      <input
                        name="start_date"
                        type="date"
                        defaultValue={startDate}
                        className="min-h-11 w-full rounded-2xl border border-[rgba(15,23,42,0.12)] bg-white/85 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15"
                      />
                      <input
                        name="end_date"
                        type="date"
                        defaultValue={endDate}
                        className="min-h-11 w-full rounded-2xl border border-[rgba(15,23,42,0.12)] bg-white/85 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15"
                      />
                    </div>
                  </label>

                  <button
                    type="submit"
                    className="inline-flex min-h-11 items-center justify-center rounded-2xl bg-slate-950 px-5 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                  >
                    Apply filters
                  </button>
                </form>
              </div>

              <aside className="relative rounded-[1.75rem] border border-white/60 bg-slate-950/92 p-5 text-white shadow-[0_20px_60px_rgba(15,23,42,0.22)] sm:p-6 dark:border-white/10 dark:bg-slate-950">
                <div className="absolute inset-x-5 top-5 h-px bg-gradient-to-r from-transparent via-white/40 to-transparent" />
                <p className="text-xs font-bold uppercase tracking-[0.24em] text-cyan-200">
                  {periodLabel} pulse
                </p>
                <p className="mt-3 text-4xl font-bold tracking-tight">
                  {formatCurrency(planSummary?.total_amount)}
                </p>
                <p className="mt-2 text-sm leading-6 text-slate-300">
                  Revenue from active subscriptions inside the selected range.
                </p>

                <div className="mt-6 rounded-[1.4rem] border border-white/10 bg-white/5 p-3">
                  <TrendLine values={trendValues} />
                </div>

                <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                  <div className="rounded-[1.2rem] border border-white/10 bg-white/6 p-4">
                    <p className="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">
                      Active subs in range
                    </p>
                    <p className="mt-2 text-2xl font-bold">
                      {formatNumber(
                        planSummary?.total_active_subscriptions_in_range,
                      )}
                    </p>
                  </div>
                  <div className="rounded-[1.2rem] border border-white/10 bg-white/6 p-4">
                    <p className="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">
                      Device focus
                    </p>
                    <p className="mt-2 text-2xl font-bold capitalize">
                      {deviceType ?? "All"}
                    </p>
                  </div>
                </div>
              </aside>
            </div>
          </section>

          {errorMessage ? (
            <div className="rounded-[1.5rem] border border-rose-200 bg-rose-50/90 px-4 py-4 text-sm font-semibold text-rose-800 shadow-[0_10px_24px_rgba(244,63,94,0.12)] dark:border-rose-300/20 dark:bg-rose-300/10 dark:text-rose-100">
              {errorMessage}
            </div>
          ) : null}

          <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <StatCard
              label="Active users"
              value={formatNumber(overview?.total_active_users)}
              accent="from-teal-500 via-cyan-500 to-sky-500"
              caption="Users currently marked active in the platform."
            />
            <StatCard
              label="Subscribed users"
              value={formatNumber(
                overview?.total_users_with_active_subscription,
              )}
              accent="from-sky-500 via-indigo-500 to-violet-500"
              caption="Unique users with at least one active subscription."
            />
            <StatCard
              label="Active subscriptions"
              value={formatNumber(overview?.total_active_subscriptions)}
              accent="from-amber-500 via-orange-500 to-rose-500"
              caption="Live subscriptions, filtered by device when selected."
            />
            <StatCard
              label="Range revenue"
              value={formatCurrency(planSummary?.total_amount)}
              accent="from-emerald-500 via-teal-500 to-cyan-500"
              caption="Plan value generated by active subscriptions in the current range."
            />
          </section>

          <div className="grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.9fr)]">
            <section className="rounded-[1.75rem] border border-white/60 bg-white/78 p-5 shadow-[0_18px_52px_rgba(15,23,42,0.08)] backdrop-blur-xl sm:p-6 xl:p-7 dark:border-white/10 dark:bg-white/6 dark:shadow-[0_20px_60px_rgba(2,6,23,0.38)]">
              <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                  <p className="text-xs font-bold uppercase tracking-[0.22em] text-slate-500 dark:text-slate-400">
                    Subscription mix
                  </p>
                  <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
                    Active subscriptions by plan
                  </h2>
                </div>
                <p className="text-sm text-slate-500 dark:text-slate-400">
                  Ranked by current plan revenue in the selected range.
                </p>
              </div>

              <div className="mt-5 grid gap-3 md:grid-cols-2">
                {planStats.length > 0 ? (
                  planStats.map((plan) => (
                    <PlanCard
                      key={`${plan.plan_id}-${plan.device_type}`}
                      plan={plan}
                      maxAmount={maxPlanAmount}
                    />
                  ))
                ) : (
                  <div className="rounded-[1.35rem] border border-dashed border-[rgba(15,23,42,0.16)] bg-slate-50/70 p-6 text-sm text-slate-600 md:col-span-2 dark:border-white/10 dark:bg-white/4 dark:text-slate-300">
                    No plan data found for the selected filters.
                  </div>
                )}
              </div>
            </section>

            <section className="space-y-4">
              <article className="rounded-[1.75rem] border border-white/60 bg-white/78 p-5 shadow-[0_18px_52px_rgba(15,23,42,0.08)] backdrop-blur-xl sm:p-6 dark:border-white/10 dark:bg-white/6 dark:shadow-[0_20px_60px_rgba(2,6,23,0.38)]">
                <p className="text-xs font-bold uppercase tracking-[0.22em] text-slate-500 dark:text-slate-400">
                  Device revenue
                </p>
                <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
                  Split by device type
                </h2>
                <div className="mt-5 space-y-3">
                  {deviceStats.length > 0 ? (
                    deviceStats.map((item) => (
                      <DeviceSplitCard
                        key={item.device_type ?? "unknown"}
                        item={item}
                        maxAmount={maxDeviceAmount}
                      />
                    ))
                  ) : (
                    <div className="rounded-[1.25rem] border border-dashed border-[rgba(15,23,42,0.16)] bg-slate-50/70 p-5 text-sm text-slate-600 dark:border-white/10 dark:bg-white/4 dark:text-slate-300">
                      No device split available for the selected filters.
                    </div>
                  )}
                </div>
              </article>

              <article className="rounded-[1.75rem] border border-white/60 bg-white/78 p-5 shadow-[0_18px_52px_rgba(15,23,42,0.08)] backdrop-blur-xl sm:p-6 dark:border-white/10 dark:bg-white/6 dark:shadow-[0_20px_60px_rgba(2,6,23,0.38)]">
                <p className="text-xs font-bold uppercase tracking-[0.22em] text-slate-500 dark:text-slate-400">
                  Content inventory
                </p>
                <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
                  Library totals
                </h2>
                <div className="mt-5 grid grid-cols-2 gap-3">
                  <StatCard
                    label="Movies"
                    value={formatNumber(content?.total_movies)}
                    accent="from-slate-900 via-slate-700 to-slate-500"
                    caption="Total movie records in the library."
                  />
                  <StatCard
                    label="Episodes"
                    value={formatNumber(content?.total_episodes)}
                    accent="from-teal-500 via-cyan-500 to-sky-500"
                    caption="Total episode records available."
                  />
                  <StatCard
                    label="Seasons"
                    value={formatNumber(content?.total_seasons)}
                    accent="from-violet-500 via-fuchsia-500 to-pink-500"
                    caption="Total seasons connected to series content."
                  />
                  <StatCard
                    label="Active plans"
                    value={formatNumber(content?.total_active_plans)}
                    accent="from-amber-500 via-orange-500 to-rose-500"
                    caption="Plans currently enabled in the subscription catalog."
                  />
                </div>
              </article>
            </section>
          </div>

          <section className="rounded-[1.75rem] border border-white/60 bg-white/78 p-5 shadow-[0_18px_52px_rgba(15,23,42,0.08)] backdrop-blur-xl sm:p-6 xl:p-7 dark:border-white/10 dark:bg-white/6 dark:shadow-[0_20px_60px_rgba(2,6,23,0.38)]">
            <div className="flex flex-wrap items-end justify-between gap-3">
              <div>
                <p className="text-xs font-bold uppercase tracking-[0.22em] text-slate-500 dark:text-slate-400">
                  Category spread
                </p>
                <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
                  Movies by category
                </h2>
              </div>
              <p className="text-sm text-slate-500 dark:text-slate-400">
                Quick scan of the strongest content buckets in the current library.
              </p>
            </div>

            <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              {movieCategoryEntries.length > 0 ? (
                movieCategoryEntries.map(([key, value], index) => {
                  const maxCategoryCount = Math.max(...movieCategoryEntries.map((entry) => entry[1]), 1);
                  const width = Math.max((value / maxCategoryCount) * 100, 8);

                  return (
                    <article
                      key={key}
                      className="rounded-[1.35rem] border border-[rgba(15,23,42,0.08)] bg-white/68 p-4 shadow-[0_14px_36px_rgba(15,23,42,0.06)] dark:border-white/10 dark:bg-white/6"
                    >
                      <div className="flex items-center justify-between gap-3">
                        <h3 className="text-base font-bold text-slate-950 dark:text-white">
                          {categoryLabel(key)}
                        </h3>
                        <span className="text-lg font-bold text-slate-950 dark:text-white">
                          {formatNumber(value)}
                        </span>
                      </div>
                      <div className="mt-4 h-2.5 overflow-hidden rounded-full bg-slate-200/90 dark:bg-white/10">
                        <div
                          className={cn(
                            "h-full rounded-full bg-gradient-to-r",
                            categoryTone(index),
                          )}
                          style={{ width: `${width}%` }}
                        />
                      </div>
                    </article>
                  );
                })
              ) : (
                <div className="rounded-[1.35rem] border border-dashed border-[rgba(15,23,42,0.16)] bg-slate-50/70 p-6 text-sm text-slate-600 md:col-span-2 xl:col-span-3 dark:border-white/10 dark:bg-white/4 dark:text-slate-300">
                  Category data is not available yet.
                </div>
              )}
            </div>
          </section>
        </div>
      </div>
    </main>
  );
}
