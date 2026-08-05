import Link from "next/link";
import { ChevronLeft, ChevronRight, PencilLine } from "lucide-react";
import { cn } from "@/lib/utils";
import type {
  PaginationMeta,
  SubscriptionDeviceType,
  SubscriptionItem,
} from "@/app/features/subscriptions/services/subscription-service";

type SubscriberListCardsProps = {
  subscriptions: SubscriptionItem[];
  pagination?: PaginationMeta<SubscriptionItem>;
  page: number;
  perPage: number;
  search?: string;
  deviceType?: SubscriptionDeviceType;
  isActive?: boolean;
  sort?: string;
};

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

function valueToBoolean(value: unknown) {
  if (typeof value === "boolean") return value;
  if (typeof value === "number") return value === 1;
  if (typeof value === "string") {
    return ["1", "true", "yes", "active"].includes(value.trim().toLowerCase());
  }

  return false;
}

function formatDate(value: unknown) {
  const text = valueToString(value);
  if (!text) return "Not set";

  const date = new Date(text);
  if (Number.isNaN(date.getTime())) return text;

  return new Intl.DateTimeFormat("en-IN", {
    year: "numeric",
    month: "short",
    day: "numeric",
  }).format(date);
}

function formatPlanName(subscription: SubscriptionItem) {
  return valueToString(subscription.plan?.name) || "Plan not loaded";
}

function formatDeviceType(subscription: SubscriptionItem) {
  return (
    valueToString(subscription.plan?.device_type) ||
    valueToString(subscription.devices?.[0]?.device_type) ||
    "Device"
  );
}

function formatUserContact(subscription: SubscriptionItem) {
  return Array.from(
    new Set(
      [
        valueToString(subscription.user?.mail),
        valueToString(subscription.user?.auth_phone),
        valueToString(subscription.user?.call),
      ].filter(Boolean),
    ),
  ).join(" · ");
}

function pageHref({
  page,
  perPage,
  search,
  deviceType,
  isActive,
  sort,
}: {
  page: number;
  perPage: number;
  search?: string;
  deviceType?: SubscriptionDeviceType;
  isActive?: boolean;
  sort?: string;
}) {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });

  if (search) {
    params.set("search", search);
  }

  if (deviceType) {
    params.set("device_type", deviceType);
  }

  if (typeof isActive === "boolean") {
    params.set("is_active", String(isActive));
  }

  if (sort) {
    params.set("sort", sort);
  }

  return `/subscriptions/subscribers?${params.toString()}`;
}

function editHref(subscriptionId: string, returnHref: string) {
  const params = new URLSearchParams({
    returnTo: returnHref,
  });

  return `/subscriptions/subscribers/edit/${subscriptionId}?${params.toString()}`;
}

export function SubscriberListCards({
  subscriptions,
  pagination,
  page,
  perPage,
  search,
  deviceType,
  isActive,
  sort,
}: SubscriberListCardsProps) {
  const currentPage = pagination?.current_page ?? page;
  const lastPage = pagination?.last_page ?? 1;
  const total = pagination?.total ?? subscriptions.length;
  const from = pagination?.from ?? (subscriptions.length > 0 ? 1 : 0);
  const to = pagination?.to ?? subscriptions.length;
  const hasPrevious = currentPage > 1;
  const hasNext = currentPage < lastPage;
  const currentListHref = pageHref({
    page: currentPage,
    perPage,
    search,
    deviceType,
    isActive,
    sort,
  });

  if (subscriptions.length === 0) {
    return (
      <section className="rounded-lg border border-dashed border-[rgba(15,23,42,0.18)] bg-white/52 p-8 text-center shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/12 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.36)]">
        <h2 className="text-xl font-bold text-slate-950 dark:text-white">
          {search ? "No matching subscribers" : "No subscribers yet"}
        </h2>
        <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-600 dark:text-slate-300">
          {search
            ? `No subscription user matched “${search}”. Try an email, phone number, or UID.`
            : "Add a subscriber first, then subscription records will appear here."}
        </p>
        {search ? null : (
          <Link
            href="/subscriptions/subscribers/add"
            className="mt-5 inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
          >
            Add subscriber
          </Link>
        )}
      </section>
    );
  }

  return (
    <div className="space-y-4">
      <div className="overflow-hidden rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/62 shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/10 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.38)]">
        <div className="overflow-x-auto">
          <table className="min-w-[920px] w-full table-fixed border-collapse text-left text-sm">
            <thead className="bg-slate-950 text-xs font-bold uppercase text-white dark:bg-white/10 dark:text-slate-200">
              <tr>
                <th scope="col" className="w-32 px-4 py-3">
                  Subscription
                </th>
                <th scope="col" className="w-56 px-4 py-3">
                  User
                </th>
                <th scope="col" className="w-44 px-4 py-3">
                  Plan
                </th>
                <th scope="col" className="w-32 px-4 py-3">
                  Device
                </th>
                <th scope="col" className="w-36 px-4 py-3">
                  Start
                </th>
                <th scope="col" className="w-36 px-4 py-3">
                  End
                </th>
                <th scope="col" className="w-28 px-4 py-3">
                  Status
                </th>
                <th scope="col" className="w-24 px-4 py-3 text-right">
                  Action
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[rgba(15,23,42,0.1)] dark:divide-white/10">
              {subscriptions.map((subscription) => {
                const subscriptionId = valueToString(subscription.id);
                const active = valueToBoolean(subscription.is_active);
                const userId = valueToString(subscription.user_id);
                const userName = valueToString(subscription.user?.name);
                const userContact = formatUserContact(subscription);

                return (
                  <tr
                    key={
                      subscriptionId ||
                      `${subscription.user_id}-${subscription.plan_id}`
                    }
                    className="bg-white/30 text-slate-700 transition hover:bg-teal-50/80 dark:bg-transparent dark:text-slate-200 dark:hover:bg-white/8"
                  >
                    <td className="px-4 py-3 align-middle">
                      <span className="font-bold text-slate-950 dark:text-white">
                        #{subscriptionId || "unknown"}
                      </span>
                    </td>
                    <td className="px-4 py-3 align-middle">
                      <span className="block truncate font-semibold text-slate-950 dark:text-white">
                        {userName || userId || "Unknown user"}
                      </span>
                      {userName || userContact ? (
                        <span className="mt-0.5 block truncate text-xs font-semibold text-slate-500 dark:text-slate-400">
                          {userName ? userId || "UID not set" : ""}
                          {userName && userContact ? " · " : ""}
                          {userContact}
                        </span>
                      ) : null}
                    </td>
                    <td className="px-4 py-3 align-middle">
                      <span className="block truncate">
                        {formatPlanName(subscription)}
                      </span>
                      <span className="mt-0.5 block text-xs font-semibold text-slate-500 dark:text-slate-400">
                        ID {valueToString(subscription.plan_id) || "unknown"}
                      </span>
                    </td>
                    <td className="px-4 py-3 align-middle">
                      <span className="capitalize">
                        {formatDeviceType(subscription)}
                      </span>
                    </td>
                    <td className="px-4 py-3 align-middle">
                      {formatDate(subscription.start_at)}
                    </td>
                    <td className="px-4 py-3 align-middle">
                      {formatDate(subscription.end_at)}
                    </td>
                    <td className="px-4 py-3 align-middle">
                      <span
                        className={cn(
                          "inline-flex min-w-20 items-center justify-center rounded-md px-2.5 py-1 text-xs font-bold",
                          active
                            ? "bg-teal-100 text-teal-700 dark:bg-cyan-300/12 dark:text-cyan-100"
                            : "bg-slate-200 text-slate-600 dark:bg-white/10 dark:text-slate-300",
                        )}
                      >
                        {active ? "Active" : "Inactive"}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right align-middle">
                      <Link
                        href={editHref(subscriptionId, currentListHref)}
                        className={cn(
                          "inline-flex min-h-9 items-center justify-center gap-2 rounded-md bg-slate-950 px-3 text-xs font-bold text-white transition hover:-translate-y-0.5 hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200",
                          !subscriptionId && "pointer-events-none opacity-50",
                        )}
                      >
                        <PencilLine className="size-3.5" />
                        Edit
                      </Link>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>

      <div className="flex flex-col gap-3 rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/52 p-3 text-sm font-semibold text-slate-600 shadow-[0_10px_30px_rgba(15,23,42,0.06)] sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:bg-white/6 dark:text-slate-300">
        <span>
          Showing {from}-{to} of {total}
        </span>
        <div className="flex gap-2">
          <Link
            href={pageHref({
              page: Math.max(1, currentPage - 1),
              perPage,
              search,
              deviceType,
              isActive,
              sort,
            })}
            className={cn(
              "inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/65 px-3 transition hover:bg-white dark:border-white/10 dark:bg-white/8 dark:hover:bg-white/12",
              !hasPrevious && "pointer-events-none opacity-45",
            )}
          >
            <ChevronLeft className="size-4" />
            Previous
          </Link>
          <Link
            href={pageHref({
              page: currentPage + 1,
              perPage,
              search,
              deviceType,
              isActive,
              sort,
            })}
            className={cn(
              "inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/65 px-3 transition hover:bg-white dark:border-white/10 dark:bg-white/8 dark:hover:bg-white/12",
              !hasNext && "pointer-events-none opacity-45",
            )}
          >
            Next
            <ChevronRight className="size-4" />
          </Link>
        </div>
      </div>
    </div>
  );
}
