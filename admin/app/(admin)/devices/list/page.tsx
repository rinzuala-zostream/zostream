import { cookies } from "next/headers";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { DeviceListTable } from "@/app/features/devices/components/device-list-table";
import { deviceService, type DeviceItem, type PaginationMeta } from "@/app/features/devices/services/device-service";

export const dynamic = "force-dynamic";
type Props = { searchParams?: Promise<{ page?: string; per_page?: string; search?: string }> };
const positive = (value: string | undefined, fallback: number) => { const n = Number(value); return Number.isInteger(n) && n > 0 ? n : fallback; };
const paginated = <T,>(value: unknown): value is PaginationMeta<T> => typeof value === "object" && value !== null && "data" in value && Array.isArray((value as { data?: unknown }).data);

export default async function DeviceListPage({ searchParams }: Props) {
  const [cookieStore, query] = await Promise.all([cookies(), searchParams]);
  const initialMode = cookieStore.get("theme-mode")?.value === "dark" ? "dark" : "light";
  const page = positive(query?.page, 1), perPage = positive(query?.per_page, 15);
  const search = query?.search?.trim() ?? "";
  let devices: DeviceItem[] = [], pagination: PaginationMeta<DeviceItem> | undefined, errorMessage = "";
  try {
    const result = search
      ? await deviceService.search({ q: search, page, per_page: perPage })
      : await deviceService.list({ page, per_page: perPage });
    if (paginated<DeviceItem>(result.data)) { devices = result.data.data; pagination = result.data; }
    if (result.status === "error") errorMessage = result.message ?? "Devices could not be loaded.";
  } catch (error) { errorMessage = error instanceof Error ? error.message : "Devices could not be loaded."; }

  return <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 dark:text-white"><div className="flex flex-1 flex-col lg:min-h-0"><AdminPageHeader title="User device list" initialMode={initialMode}/><div className="admin-sidebar-scroll mt-3 flex-1 pb-4 lg:min-h-0">
    <section className="mb-4 rounded-lg border border-white/60 bg-white/65 p-5 dark:border-white/10 dark:bg-white/5"><p className="text-xs font-bold uppercase tracking-[.2em] text-teal-700 dark:text-cyan-200">Device management</p><h2 className="mt-2 text-2xl font-bold">View, search, update, or delete user devices.</h2><p className="mt-2 text-sm text-slate-600 dark:text-slate-300">Search users by name, email, phone, or UID, then select one to show their owner and shared devices.</p></section>
    <form action="/devices/list" className="mb-4 grid gap-3 rounded-lg border border-slate-200 bg-white/55 p-3 md:grid-cols-[1fr_160px_auto] dark:border-white/10 dark:bg-white/5"><input name="search" type="search" defaultValue={search} placeholder="Search user name, email, phone, or UID" className="min-h-11 rounded-md border bg-transparent px-4"/><select name="per_page" defaultValue={String(perPage)} className="min-h-11 rounded-md border bg-white px-3 dark:bg-slate-950"><option value="15">15 rows</option><option value="30">30 rows</option><option value="60">60 rows</option></select><button className="rounded-md bg-slate-950 px-5 font-bold text-white dark:bg-white dark:text-slate-950">Search users</button></form>
    {errorMessage ? <div className="mb-4 rounded-md bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 dark:bg-rose-300/10 dark:text-rose-100">{errorMessage}</div> : null}
    <DeviceListTable devices={devices} pagination={pagination} page={page} perPage={perPage} search={search}/>
  </div></div></main>;
}
