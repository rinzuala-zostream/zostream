"use client";

import Link from "next/link";
import { useState, useTransition } from "react";
import { ChevronLeft, ChevronRight, PencilLine, Trash2, X } from "lucide-react";
import { ConfirmDialog } from "@/app/components/confirm-dialog";
import { deleteDeviceAction, updateDeviceAction } from "@/app/(admin)/devices/list/actions";
import type { DeviceItem, PaginationMeta } from "@/app/features/devices/services/device-service";
import { cn } from "@/lib/utils";

type Props = { devices: DeviceItem[]; pagination?: PaginationMeta<DeviceItem>; page: number; perPage: number; search?: string; userId?: string };
const text = (value: unknown) => (value === null || value === undefined ? "" : String(value).trim());
const isTrue = (value: unknown) =>
  value === true || value === 1 || text(value).toLowerCase() === "true" || text(value) === "1";

function href(page: number, perPage: number, search?: string, userId?: string) {
  const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
  if (search) params.set("search", search);
  if (userId) params.set("user_id", userId);
  return `/devices/list?${params.toString()}`;
}

export function DeviceListTable({ devices, pagination, page, perPage, search, userId }: Props) {
  const [editing, setEditing] = useState<DeviceItem | null>(null);
  const [deleting, setDeleting] = useState<DeviceItem | null>(null);
  const [notice, setNotice] = useState<{ status: "success" | "error"; message: string } | null>(null);
  const [isPending, startTransition] = useTransition();
  const current = pagination?.current_page ?? page;
  const last = pagination?.last_page ?? 1;

  const remove = () => {
    if (!deleting?.id) return;
    startTransition(async () => {
      const result = await deleteDeviceAction(deleting.id!);
      setNotice(result);
      if (result.status === "success") setDeleting(null);
    });
  };

  const save = (formData: FormData) => {
    if (!editing?.id) return;
    startTransition(async () => {
      const status = text(formData.get("status")) as "active" | "inactive" | "blocked";
      const result = await updateDeviceAction(editing.id!, {
        device_name: text(formData.get("device_name")),
        device_type: text(formData.get("device_type")),
        status,
      });
      setNotice(result);
      if (result.status === "success") setEditing(null);
    });
  };

  if (!devices.length) return <div className="rounded-lg border border-dashed border-slate-300 bg-white/55 p-8 text-center dark:border-white/15 dark:bg-white/5"><h2 className="text-xl font-bold">No devices found</h2><p className="mt-2 text-sm text-slate-600 dark:text-slate-300">Try another user search or view all devices.</p></div>;

  return <div className="space-y-4">
    {notice ? <div className={cn("rounded-md px-4 py-3 text-sm font-semibold", notice.status === "success" ? "bg-emerald-50 text-emerald-800 dark:bg-emerald-300/10 dark:text-emerald-100" : "bg-rose-50 text-rose-800 dark:bg-rose-300/10 dark:text-rose-100")}>{notice.message}</div> : null}
    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white/65 dark:border-white/10 dark:bg-white/5"><div className="overflow-x-auto"><table className="w-full min-w-[1180px] text-left text-sm"><thead className="bg-slate-950 text-xs uppercase text-white dark:bg-white/10"><tr><th className="px-4 py-3">User</th><th className="px-4 py-3">Device</th><th className="px-4 py-3">Ownership</th><th className="px-4 py-3">Type</th><th className="px-4 py-3">Token</th><th className="px-4 py-3">Subscription</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Created</th><th className="px-4 py-3 text-right">Actions</th></tr></thead>
      <tbody className="divide-y divide-slate-200 dark:divide-white/10">{devices.map((device) => <tr key={text(device.id) || text(device.device_token)} className="hover:bg-teal-50/70 dark:hover:bg-white/5"><td className="px-4 py-3 font-bold">{text(device.user_id) || "Unknown"}</td><td className="px-4 py-3 font-semibold">{text(device.device_name) || "Unnamed"}</td><td className="px-4 py-3">{isTrue(device.is_owner_device) ? <span className="inline-flex rounded-md bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800 dark:bg-amber-300/15 dark:text-amber-100">Owner device</span> : <span className="inline-flex rounded-md bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 dark:bg-white/10 dark:text-slate-300">Additional device</span>}</td><td className="px-4 py-3">{text(device.device_type) || "Not set"}</td><td className="max-w-48 truncate px-4 py-3" title={text(device.device_token)}>{text(device.device_token) || "Not set"}</td><td className="px-4 py-3">{text(device.subscription_id) || "-"}</td><td className="px-4 py-3"><span className="rounded-md bg-slate-100 px-2 py-1 text-xs font-bold capitalize dark:bg-white/10">{text(device.status) || "inactive"}</span></td><td className="px-4 py-3">{text(device.created_at) ? new Date(text(device.created_at)).toLocaleDateString("en-IN") : "-"}</td><td className="px-4 py-3"><div className="flex justify-end gap-2"><button onClick={() => { setNotice(null); setEditing(device); }} className="inline-flex min-h-9 items-center gap-1 rounded-md bg-slate-950 px-3 text-xs font-bold text-white dark:bg-white dark:text-slate-950"><PencilLine className="size-3.5"/>Update</button><button onClick={() => { setNotice(null); setDeleting(device); }} className="inline-flex min-h-9 items-center gap-1 rounded-md bg-rose-600 px-3 text-xs font-bold text-white"><Trash2 className="size-3.5"/>Delete</button></div></td></tr>)}</tbody></table></div></div>
    <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-white/55 p-3 text-sm font-semibold dark:border-white/10 dark:bg-white/5"><span>Showing {pagination?.from ?? 1}-{pagination?.to ?? devices.length} of {pagination?.total ?? devices.length}</span><div className="flex gap-2"><Link className={cn("rounded-md border px-3 py-2", current <= 1 && "pointer-events-none opacity-40")} href={href(current - 1, perPage, search, userId)}><ChevronLeft className="inline size-4"/> Previous</Link><Link className={cn("rounded-md border px-3 py-2", current >= last && "pointer-events-none opacity-40")} href={href(current + 1, perPage, search, userId)}>Next <ChevronRight className="inline size-4"/></Link></div></div>
    {editing ? <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"><form action={save} className="relative w-full max-w-lg rounded-lg bg-white p-5 shadow-2xl dark:bg-slate-900"><button type="button" onClick={() => setEditing(null)} className="absolute right-4 top-4"><X className="size-5"/></button><h2 className="text-xl font-bold">Update device</h2><div className="mt-5 grid gap-4"><label className="text-sm font-bold">Device name<input name="device_name" defaultValue={text(editing.device_name)} required className="mt-1 min-h-11 w-full rounded-md border bg-transparent px-3"/></label><label className="text-sm font-bold">Device type<input name="device_type" defaultValue={text(editing.device_type)} required className="mt-1 min-h-11 w-full rounded-md border bg-transparent px-3"/></label><label className="text-sm font-bold">Status<select name="status" defaultValue={text(editing.status) || "inactive"} className="mt-1 min-h-11 w-full rounded-md border bg-white px-3 dark:bg-slate-950"><option value="active">Active</option><option value="inactive">Inactive</option><option value="blocked">Blocked</option></select></label></div><div className="mt-5 flex justify-end gap-2"><button type="button" onClick={() => setEditing(null)} className="rounded-md border px-4 py-2 font-bold">Cancel</button><button disabled={isPending} className="rounded-md bg-slate-950 px-4 py-2 font-bold text-white dark:bg-white dark:text-slate-950">{isPending ? "Saving..." : "Save changes"}</button></div></form></div> : null}
    <ConfirmDialog open={Boolean(deleting)} title="Delete device?" description={`Delete ${text(deleting?.device_name) || "this device"} permanently? Active stream records are not changed by this delete endpoint.`} confirmLabel="Delete device" isPending={isPending} variant="danger" onClose={() => !isPending && setDeleting(null)} onConfirm={remove}/>
  </div>;
}
