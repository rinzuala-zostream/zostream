import { Smartphone, Tv, Monitor, TabletSmartphone } from "lucide-react";
import { cn } from "@/lib/utils";
import type {
  DeviceItem,
  PaginationMeta,
} from "@/app/features/devices/services/device-service";

type DeviceClearListProps = {
  devices: DeviceItem[];
  pagination?: PaginationMeta<DeviceItem>;
  userId?: string;
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
    return ["1", "true", "yes", "owner"].includes(value.trim().toLowerCase());
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

function DeviceIcon({ type }: { type: unknown }) {
  const deviceType = valueToString(type).toLowerCase();

  if (deviceType === "tv") return <Tv className="size-4" />;
  if (deviceType === "browser") return <Monitor className="size-4" />;
  if (deviceType === "tablet") return <TabletSmartphone className="size-4" />;

  return <Smartphone className="size-4" />;
}

export function DeviceClearList({
  devices,
  pagination,
  userId,
}: DeviceClearListProps) {
  const total = pagination?.total ?? devices.length;
  const from = pagination?.from ?? (devices.length > 0 ? 1 : 0);
  const to = pagination?.to ?? devices.length;

  if (!userId) {
    return (
      <section className="rounded-lg border border-dashed border-[rgba(15,23,42,0.18)] bg-white/52 p-6 text-sm font-semibold text-slate-600 shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/12 dark:bg-white/6 dark:text-slate-300 dark:shadow-[0_18px_54px_rgba(2,6,23,0.36)]">
        Search a user ID to preview their owner and shared device records.
      </section>
    );
  }

  if (devices.length === 0) {
    return (
      <section className="rounded-lg border border-dashed border-[rgba(15,23,42,0.18)] bg-white/52 p-6 text-center shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/12 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.36)]">
        <h2 className="text-xl font-bold text-slate-950 dark:text-white">
          No devices found
        </h2>
        <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-600 dark:text-slate-300">
          No owner or shared device rows were returned for {userId}.
        </p>
      </section>
    );
  }

  return (
    <div className="space-y-3">
      <div className="overflow-hidden rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/62 shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/10 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.38)]">
        <div className="overflow-x-auto">
          <table className="min-w-[920px] w-full table-fixed border-collapse text-left text-sm">
            <thead className="bg-slate-950 text-xs font-bold uppercase text-white dark:bg-white/10 dark:text-slate-200">
              <tr>
                <th scope="col" className="w-20 px-4 py-3">
                  ID
                </th>
                <th scope="col" className="w-44 px-4 py-3">
                  Device
                </th>
                <th scope="col" className="w-32 px-4 py-3">
                  Type
                </th>
                <th scope="col" className="w-44 px-4 py-3">
                  Token
                </th>
                <th scope="col" className="w-32 px-4 py-3">
                  Owner
                </th>
                <th scope="col" className="w-32 px-4 py-3">
                  Status
                </th>
                <th scope="col" className="w-36 px-4 py-3">
                  Activity
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[rgba(15,23,42,0.1)] dark:divide-white/10">
              {devices.map((device) => {
                const deviceId = valueToString(device.id);
                const isOwner = valueToBoolean(device.is_owner_device);
                const status = valueToString(device.status) || "unknown";

                return (
                  <tr
                    key={deviceId || valueToString(device.device_token)}
                    className="bg-white/30 text-slate-700 transition hover:bg-amber-50/80 dark:bg-transparent dark:text-slate-200 dark:hover:bg-white/8"
                  >
                    <td className="px-4 py-3 align-middle font-bold text-slate-950 dark:text-white">
                      {deviceId || "Unknown"}
                    </td>
                    <td className="px-4 py-3 align-middle">
                      <span className="block truncate font-semibold text-slate-950 dark:text-white">
                        {valueToString(device.device_name) || "Unnamed device"}
                      </span>
                      <span className="mt-0.5 block truncate text-xs font-semibold text-slate-500 dark:text-slate-400">
                        Subscription {valueToString(device.subscription_id) || "not set"}
                      </span>
                    </td>
                    <td className="px-4 py-3 align-middle">
                      <span className="inline-flex items-center gap-2 rounded-md bg-white/60 px-2.5 py-1 text-xs font-bold capitalize text-slate-700 dark:bg-white/10 dark:text-slate-200">
                        <DeviceIcon type={device.device_type} />
                        {valueToString(device.device_type) || "device"}
                      </span>
                    </td>
                    <td className="px-4 py-3 align-middle">
                      <span className="block truncate font-mono text-xs">
                        {valueToString(device.device_token) || "Not set"}
                      </span>
                    </td>
                    <td className="px-4 py-3 align-middle">
                      <span
                        className={cn(
                          "inline-flex min-w-20 items-center justify-center rounded-md px-2.5 py-1 text-xs font-bold",
                          isOwner
                            ? "bg-teal-100 text-teal-700 dark:bg-cyan-300/12 dark:text-cyan-100"
                            : "bg-violet-100 text-violet-700 dark:bg-violet-300/12 dark:text-violet-100",
                        )}
                      >
                        {isOwner ? "Owner" : "Shared"}
                      </span>
                    </td>
                    <td className="px-4 py-3 align-middle capitalize">
                      {status}
                    </td>
                    <td className="px-4 py-3 align-middle">
                      {formatDate(device.last_activity ?? device.updated_at)}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>

      <div className="rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/52 p-3 text-sm font-semibold text-slate-600 shadow-[0_10px_30px_rgba(15,23,42,0.06)] dark:border-white/10 dark:bg-white/6 dark:text-slate-300">
        Showing {from}-{to} of {total} device records for {userId}
      </div>
    </div>
  );
}
