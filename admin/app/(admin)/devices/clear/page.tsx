import { cookies } from "next/headers";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { ClearDeviceForm } from "@/app/features/devices/components/clear-device-form";
import { DeviceClearList } from "@/app/features/devices/components/device-clear-list";
import {
  deviceService,
  type DeviceItem,
  type PaginationMeta as DevicePaginationMeta,
} from "@/app/features/devices/services/device-service";
import {
  userService,
  type PaginationMeta as UserPaginationMeta,
  type UserItem,
} from "@/app/features/users/services/user-service";
import { StoredLink } from "@/app/features/users/components/uid-clipboard";

export const dynamic = "force-dynamic";

type ClearDevicePageProps = {
  searchParams?: Promise<{
    search?: string;
    user_id?: string;
    per_page?: string;
  }>;
};

function positiveNumber(value: string | undefined, fallback: number) {
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback;
}

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

function isDevicePaginationMeta(
  value: unknown,
): value is DevicePaginationMeta<DeviceItem> {
  return (
    typeof value === "object" &&
    value !== null &&
    "data" in value &&
    Array.isArray((value as { data?: unknown }).data)
  );
}

function isUserPaginationMeta(
  value: unknown,
): value is UserPaginationMeta<UserItem> {
  return (
    typeof value === "object" &&
    value !== null &&
    "data" in value &&
    Array.isArray((value as { data?: unknown }).data)
  );
}

function selectedUserLabel(users: UserItem[], userId: string) {
  const selectedUser = users.find(
    (user) =>
      valueToString(user.uid) === userId || valueToString(user.num) === userId,
  );

  if (!selectedUser) return userId;

  return (
    valueToString(selectedUser.name) ||
    valueToString(selectedUser.uid) ||
    valueToString(selectedUser.mail) ||
    userId
  );
}

export default async function ClearDevicePage({
  searchParams,
}: ClearDevicePageProps) {
  const [cookieStore, resolvedSearchParams] = await Promise.all([
    cookies(),
    searchParams,
  ]);
  const savedMode = cookieStore.get("theme-mode")?.value;
  const initialMode = savedMode === "dark" ? "dark" : "light";
  const search = resolvedSearchParams?.search?.trim() ?? "";
  const userId = resolvedSearchParams?.user_id?.trim() ?? "";
  const perPage = positiveNumber(resolvedSearchParams?.per_page, 15);

  let users: UserItem[] = [];
  let devices: DeviceItem[] = [];
  let pagination: DevicePaginationMeta<DeviceItem> | undefined;
  let userSearchError = "";
  let errorMessage = "";

  if (search) {
    try {
      const response = await userService.list({
        search,
        limit: 8,
      });

      if (response.status === "error") {
        userSearchError = response.message ?? "Users could not be searched.";
      }

      if (isUserPaginationMeta(response.data)) {
        users = response.data.data;
      }
    } catch (error) {
      userSearchError =
        error instanceof Error ? error.message : "Users could not be searched.";
    }
  }

  if (userId) {
    try {
      const response = await deviceService.getByUser(userId, {
        per_page: perPage,
      });

      if (response.status === "error") {
        errorMessage = response.message ?? "Devices could not be loaded.";
      }

      if (isDevicePaginationMeta(response.data)) {
        devices = response.data.data;
        pagination = response.data;
      }
    } catch (error) {
      errorMessage =
        error instanceof Error ? error.message : "Devices could not be loaded.";
    }
  }

  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 lg:py-1 dark:text-white">
      <div className="flex w-full flex-1 flex-col lg:min-h-0">
        <AdminPageHeader title="Clear device" initialMode={initialMode} />

        <div className="admin-sidebar-scroll mt-3 flex-1 pb-4 lg:min-h-0">
          <section className="relative mb-4 overflow-hidden rounded-lg border border-white/58 bg-[linear-gradient(135deg,rgba(255,255,255,0.82),rgba(255,247,237,0.72)_46%,rgba(254,226,226,0.5))] p-5 backdrop-blur-xl sm:p-6 dark:border-white/10 dark:bg-[linear-gradient(135deg,rgba(15,23,42,0.82),rgba(120,53,15,0.24)_46%,rgba(127,29,29,0.24))] dark:shadow-[0_18px_60px_rgba(2,6,23,0.46)]">
            <div className="max-w-3xl">
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-amber-700 dark:text-amber-200">
                Device maintenance
              </p>
              <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl dark:text-white">
                Remove stale or blocked device records.
              </h2>
              <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
                Search by name, email, phone, or UID, pick the user, then clear
                all devices or narrow the cleanup by type or exact token.
              </p>
            </div>
          </section>

          <form
            action="/devices/clear"
            className="mb-4 grid gap-3 rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/52 p-3 shadow-[0_10px_30px_rgba(15,23,42,0.06)] md:grid-cols-[minmax(0,1fr)_160px_auto] dark:border-white/10 dark:bg-white/6"
          >
            <label className="block min-w-0">
              <span className="sr-only">Search users</span>
              <input
                name="search"
                type="search"
                defaultValue={search}
                placeholder="Search name, email, phone, or UID"
                className="min-h-11 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/70 px-4 text-sm font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15"
              />
            </label>
            <label className="block min-w-0">
              <span className="sr-only">Devices per page</span>
              <select
                name="per_page"
                defaultValue={String(perPage)}
                className="min-h-11 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/70 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:bg-white focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:bg-slate-950 dark:focus:ring-cyan-300/15"
              >
                <option value="15">15 rows</option>
                <option value="30">30 rows</option>
                <option value="60">60 rows</option>
              </select>
            </label>
            <button
              type="submit"
              className="inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
            >
              Search users
            </button>
          </form>

          {userSearchError ? (
            <div className="mb-4 rounded-md border border-rose-200 bg-rose-50/90 px-4 py-3 text-sm font-semibold text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100">
              {userSearchError}
            </div>
          ) : null}

          {search ? (
            <section className="mb-4 overflow-hidden rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/62 shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/10 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.38)]">
              <div className="border-b border-[rgba(15,23,42,0.1)] px-4 py-3 dark:border-white/10">
                <h2 className="text-sm font-bold text-slate-950 dark:text-white">
                  User search results
                </h2>
              </div>
              {users.length > 0 ? (
                <div className="divide-y divide-[rgba(15,23,42,0.1)] dark:divide-white/10">
                  {users.map((user) => {
                    const uid = valueToString(user.uid);
                    const fallbackId = valueToString(user.num);
                    const nextUserId = uid || fallbackId;
                    const active = userId === nextUserId;
                    const params = new URLSearchParams({
                      search,
                      user_id: nextUserId,
                      per_page: String(perPage),
                    });

                    return (
                      <div
                        key={nextUserId || valueToString(user.mail)}
                        className="grid gap-3 bg-white/30 px-4 py-3 text-sm text-slate-700 md:grid-cols-[minmax(0,1fr)_auto] md:items-center dark:bg-transparent dark:text-slate-200"
                      >
                        <div className="min-w-0">
                          <p className="truncate font-bold text-slate-950 dark:text-white">
                            {valueToString(user.name) || uid || "Unnamed user"}
                          </p>
                          <p className="mt-1 truncate text-xs font-semibold text-slate-500 dark:text-slate-400">
                            UID {uid || "not set"} ·{" "}
                            {valueToString(user.mail) || "No email"} ·{" "}
                            {valueToString(user.auth_phone) ||
                              valueToString(user.call) ||
                              "No phone"}
                          </p>
                        </div>
                        <StoredLink
                          uid={nextUserId}
                          href={`/devices/clear?${params.toString()}`}
                          className="inline-flex min-h-10 items-center justify-center rounded-md bg-slate-950 px-4 text-xs font-bold text-white transition hover:-translate-y-0.5 hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                        >
                          {active ? "Selected" : "Use user"}
                        </StoredLink>
                      </div>
                    );
                  })}
                </div>
              ) : (
                <div className="px-4 py-5 text-sm font-semibold text-slate-600 dark:text-slate-300">
                  No users matched that search.
                </div>
              )}
            </section>
          ) : null}

          <div className="grid gap-4 xl:grid-cols-[minmax(0,0.92fr)_minmax(0,1.08fr)]">
            <ClearDeviceForm key={userId} initialUserId={userId} />
            <DeviceClearList
              devices={devices}
              pagination={pagination}
              userId={userId ? selectedUserLabel(users, userId) : ""}
            />
          </div>
        </div>
      </div>
    </main>
  );
}
