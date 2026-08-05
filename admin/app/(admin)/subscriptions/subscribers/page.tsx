import { cookies } from "next/headers";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { SubscriberListCards } from "@/app/features/subscriptions/components/subscriber-list-cards";
import {
  subscriptionService,
  type ListSubscriptionsParams,
  type PaginationMeta,
  type SubscriptionDeviceType,
  type SubscriptionItem,
} from "@/app/features/subscriptions/services/subscription-service";

export const dynamic = "force-dynamic";

type SubscriberListPageProps = {
  searchParams?: Promise<{
    page?: string;
    per_page?: string;
    search?: string;
    device_type?: string;
    is_active?: string;
    sort?: string;
  }>;
};

type SortBy = NonNullable<ListSubscriptionsParams["sort_by"]>;
type SortDirection = NonNullable<ListSubscriptionsParams["sort_direction"]>;

const SORT_OPTIONS: Array<{
  label: string;
  value: string;
  sortBy: SortBy;
  sortDirection: SortDirection;
}> = [
  {
    label: "Newest first",
    value: "created_at:desc",
    sortBy: "created_at",
    sortDirection: "desc",
  },
  {
    label: "Oldest first",
    value: "created_at:asc",
    sortBy: "created_at",
    sortDirection: "asc",
  },
  {
    label: "Ending soon",
    value: "end_at:asc",
    sortBy: "end_at",
    sortDirection: "asc",
  },
  {
    label: "Latest end date",
    value: "end_at:desc",
    sortBy: "end_at",
    sortDirection: "desc",
  },
  {
    label: "User A-Z",
    value: "user_id:asc",
    sortBy: "user_id",
    sortDirection: "asc",
  },
];

function positiveNumber(value: string | undefined, fallback: number) {
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback;
}

function subscriptionDeviceType(
  value: string | undefined,
): SubscriptionDeviceType | undefined {
  const normalized = value?.trim().toLowerCase();
  return normalized === "mobile" ||
    normalized === "browser" ||
    normalized === "tv"
    ? normalized
    : undefined;
}

function activeFilter(value: string | undefined) {
  if (value === "true") return true;
  if (value === "false") return false;
  return undefined;
}

function sortOption(value: string | undefined) {
  return (
    SORT_OPTIONS.find((option) => option.value === value) ?? SORT_OPTIONS[0]
  );
}

function isPaginationMeta(
  value: unknown,
): value is PaginationMeta<SubscriptionItem> {
  return (
    typeof value === "object" &&
    value !== null &&
    "data" in value &&
    Array.isArray((value as { data?: unknown }).data)
  );
}

export default async function SubscriberListPage({
  searchParams,
}: SubscriberListPageProps) {
  const [cookieStore, resolvedSearchParams] = await Promise.all([
    cookies(),
    searchParams,
  ]);
  const savedMode = cookieStore.get("theme-mode")?.value;
  const initialMode = savedMode === "dark" ? "dark" : "light";
  const page = positiveNumber(resolvedSearchParams?.page, 1);
  const perPage = positiveNumber(resolvedSearchParams?.per_page, 12);
  const search = resolvedSearchParams?.search?.trim() ?? "";
  const deviceType = subscriptionDeviceType(resolvedSearchParams?.device_type);
  const isActive = activeFilter(resolvedSearchParams?.is_active);
  const sort = sortOption(resolvedSearchParams?.sort);

  let subscriptions: SubscriptionItem[] = [];
  let pagination: PaginationMeta<SubscriptionItem> | undefined;
  let errorMessage = "";

  try {
    const listParams = {
      page,
      per_page: perPage,
      search: search || undefined,
      device_type: deviceType,
      is_active: isActive,
      sort_by: sort.sortBy,
      sort_direction: sort.sortDirection,
    } satisfies ListSubscriptionsParams;
    const response = search
      ? await subscriptionService.search({ ...listParams, search })
      : await subscriptionService.list(listParams);

    if (response.status === "error") {
      errorMessage = response.message ?? "Subscribers could not be loaded.";
    }

    if (Array.isArray(response.data)) {
      subscriptions = response.data;
    } else if (isPaginationMeta(response.data)) {
      subscriptions = response.data.data;
      pagination = response.data;
    }
  } catch (error) {
    errorMessage =
      error instanceof Error ? error.message : "Subscribers could not be loaded.";
  }

  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 lg:py-1 dark:text-white">
      <div className="flex w-full flex-1 flex-col lg:min-h-0">
        <AdminPageHeader title="Subscriber list" initialMode={initialMode} />

        <div className="admin-sidebar-scroll mt-3 flex-1 pb-4 lg:min-h-0">
          <section className="relative mb-4 overflow-hidden rounded-lg border border-white/58 bg-[linear-gradient(135deg,rgba(255,255,255,0.82),rgba(236,253,245,0.5)_46%,rgba(224,242,254,0.52))] p-5 backdrop-blur-xl sm:p-6 dark:border-white/10 dark:bg-[linear-gradient(135deg,rgba(15,23,42,0.82),rgba(20,83,45,0.2)_46%,rgba(8,47,73,0.28))] dark:shadow-[0_18px_60px_rgba(2,6,23,0.46)]">
            <div className="max-w-3xl">
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-teal-700 dark:text-cyan-200">
                Subscribers
              </p>
              <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl dark:text-white">
                Review subscription records.
              </h2>
              <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
                Browse subscriber subscriptions from the backend index and open
                a record when it needs editing.
              </p>
            </div>
          </section>

          {errorMessage ? (
            <div className="mb-4 rounded-md border border-rose-200 bg-rose-50/90 px-4 py-3 text-sm font-semibold text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100">
              {errorMessage}
            </div>
          ) : null}

          <form
            action="/subscriptions/subscribers"
            className="mb-4 grid gap-3 rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/52 p-3 shadow-[0_10px_30px_rgba(15,23,42,0.06)] md:grid-cols-[minmax(0,1fr)_160px_150px_170px_auto] dark:border-white/10 dark:bg-white/6"
          >
            <input type="hidden" name="per_page" value={perPage} />
            <label className="block min-w-0">
              <span className="sr-only">Search subscribers</span>
              <input
                name="search"
                type="search"
                defaultValue={search}
                placeholder="Search email, phone, UID, plan, or subscription ID"
                className="min-h-11 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/70 px-4 text-sm font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15"
              />
            </label>
            <label className="block min-w-0">
              <span className="sr-only">Device type</span>
              <select
                name="device_type"
                defaultValue={deviceType ?? ""}
                className="min-h-11 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/70 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:bg-white focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:bg-slate-950 dark:focus:ring-cyan-300/15"
              >
                <option value="">All devices</option>
                <option value="mobile">Mobile</option>
                <option value="browser">Browser</option>
                <option value="tv">TV</option>
              </select>
            </label>
            <label className="block min-w-0">
              <span className="sr-only">Subscription status</span>
              <select
                name="is_active"
                defaultValue={
                  typeof isActive === "boolean" ? String(isActive) : ""
                }
                className="min-h-11 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/70 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:bg-white focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:bg-slate-950 dark:focus:ring-cyan-300/15"
              >
                <option value="">All statuses</option>
                <option value="true">Active</option>
                <option value="false">Inactive</option>
              </select>
            </label>
            <label className="block min-w-0">
              <span className="sr-only">Sort subscriptions</span>
              <select
                name="sort"
                defaultValue={sort.value}
                className="min-h-11 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/70 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:bg-white focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:bg-slate-950 dark:focus:ring-cyan-300/15"
              >
                {SORT_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
            <button
              type="submit"
              className="inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
            >
              Apply
            </button>
          </form>

          <SubscriberListCards
            subscriptions={subscriptions}
            pagination={pagination}
            page={page}
            perPage={perPage}
            search={search}
            deviceType={deviceType}
            isActive={isActive}
            sort={sort.value}
          />
        </div>
      </div>
    </main>
  );
}
