import { cookies } from "next/headers";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { UserListCards } from "@/app/features/users/components/user-list-cards";
import {
  userService,
  type PaginationMeta,
  type UserItem,
} from "@/app/features/users/services/user-service";

export const dynamic = "force-dynamic";

type UserUpdatePageProps = {
  searchParams?: Promise<{
    page?: string;
    per_page?: string;
    search?: string;
  }>;
};

function positiveNumber(value: string | undefined, fallback: number) {
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback;
}

function isPaginationMeta(value: unknown): value is PaginationMeta<UserItem> {
  return (
    typeof value === "object" &&
    value !== null &&
    "data" in value &&
    Array.isArray((value as { data?: unknown }).data)
  );
}

export default async function UserUpdatePage({
  searchParams,
}: UserUpdatePageProps) {
  const [cookieStore, resolvedSearchParams] = await Promise.all([
    cookies(),
    searchParams,
  ]);
  const savedMode = cookieStore.get("theme-mode")?.value;
  const initialMode = savedMode === "dark" ? "dark" : "light";
  const page = positiveNumber(resolvedSearchParams?.page, 1);
  const perPage = positiveNumber(resolvedSearchParams?.per_page, 12);
  const search = resolvedSearchParams?.search?.trim() ?? "";

  let users: UserItem[] = [];
  let pagination: PaginationMeta<UserItem> | undefined;
  let errorMessage = "";

  try {
    const response = await userService.list({
      page,
      limit: perPage,
      search: search || undefined,
    });

    if (response.status === "error") {
      errorMessage = response.message ?? "Users could not be loaded.";
    }

    if (isPaginationMeta(response.data)) {
      users = response.data.data;
      pagination = response.data;
    }
  } catch (error) {
    errorMessage =
      error instanceof Error ? error.message : "Users could not be loaded.";
  }

  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 lg:py-1 dark:text-white">
      <div className="flex w-full flex-1 flex-col lg:min-h-0">
        <AdminPageHeader title="Update user" initialMode={initialMode} />

        <div className="admin-sidebar-scroll mt-3 flex-1 pb-4 lg:min-h-0">
          <section className="relative mb-4 overflow-hidden rounded-lg border border-white/58 bg-[linear-gradient(135deg,rgba(255,255,255,0.82),rgba(236,253,245,0.5)_46%,rgba(224,242,254,0.52))] p-5 backdrop-blur-xl sm:p-6 dark:border-white/10 dark:bg-[linear-gradient(135deg,rgba(15,23,42,0.82),rgba(20,83,45,0.2)_46%,rgba(8,47,73,0.28))] dark:shadow-[0_18px_60px_rgba(2,6,23,0.46)]">
            <div className="max-w-3xl">
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-teal-700 dark:text-cyan-200">
                User management
              </p>
              <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl dark:text-white">
                Find and edit user accounts.
              </h2>
              <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
                Search the user index, scan account details, and open a record
                when profile or device metadata needs updating.
              </p>
            </div>
          </section>

          {errorMessage ? (
            <div className="mb-4 rounded-md border border-rose-200 bg-rose-50/90 px-4 py-3 text-sm font-semibold text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100">
              {errorMessage}
            </div>
          ) : null}

          <form
            action="/users/update"
            className="mb-4 grid gap-3 rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/52 p-3 shadow-[0_10px_30px_rgba(15,23,42,0.06)] md:grid-cols-[minmax(0,1fr)_160px_auto] dark:border-white/10 dark:bg-white/6"
          >
            <label className="block min-w-0">
              <span className="sr-only">Search users</span>
              <input
                name="search"
                type="search"
                defaultValue={search}
                placeholder="Search UID, name, email, phone, or device"
                className="min-h-11 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/70 px-4 text-sm font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15"
              />
            </label>
            <label className="block min-w-0">
              <span className="sr-only">Users per page</span>
              <select
                name="per_page"
                defaultValue={String(perPage)}
                className="min-h-11 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/70 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:bg-white focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:bg-slate-950 dark:focus:ring-cyan-300/15"
              >
                <option value="12">12 rows</option>
                <option value="24">24 rows</option>
                <option value="48">48 rows</option>
              </select>
            </label>
            <button
              type="submit"
              className="inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
            >
              Search
            </button>
          </form>

          <UserListCards
            users={users}
            pagination={pagination}
            page={page}
            perPage={perPage}
            search={search}
          />
        </div>
      </div>
    </main>
  );
}
