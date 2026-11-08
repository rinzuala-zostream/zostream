"use client";

import { useMemo, useState, useTransition } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  Ban,
  ChevronLeft,
  ChevronRight,
  Trash2,
  UserPlus,
} from "lucide-react";
import { toast } from "react-toastify";
import {
  deleteUserAction,
  suspendUserAction,
} from "@/app/(admin)/users/suspend-delete/actions";
import { ConfirmDialog } from "@/app/components/confirm-dialog";
import { cn } from "@/lib/utils";
import type {
  PaginationMeta,
  UserItem,
} from "@/app/features/users/services/user-service";

type UserSuspendDeleteListProps = {
  users: UserItem[];
  pagination?: PaginationMeta<UserItem>;
  page: number;
  perPage: number;
  search?: string;
};

type PendingAction = {
  type: "suspend" | "delete";
  userId: string;
  label: string;
} | null;

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

function userLastLogin(user: UserItem) {
  return (
    user.lastLogin ||
    user.last_login ||
    user.last_login_at ||
    user.lastlogin ||
    user.lastLogIn
  );
}

function userActionId(user: UserItem) {
  return valueToString(user.uid) || valueToString(user.num);
}

function userLabel(user: UserItem) {
  return (
    valueToString(user.name) ||
    valueToString(user.uid) ||
    valueToString(user.mail) ||
    "this user"
  );
}

function pageHref({
  page,
  perPage,
  search,
}: {
  page: number;
  perPage: number;
  search?: string;
}) {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });

  if (search) {
    params.set("search", search);
  }

  return `/users/suspend-delete?${params.toString()}`;
}

export function UserSuspendDeleteList({
  users,
  pagination,
  page,
  perPage,
  search,
}: UserSuspendDeleteListProps) {
  const router = useRouter();
  const [pendingAction, setPendingAction] = useState<PendingAction>(null);
  const [isPending, startTransition] = useTransition();
  const currentPage = pagination?.current_page ?? page;
  const lastPage = pagination?.last_page ?? 1;
  const total = pagination?.total ?? users.length;
  const from = pagination?.from ?? (users.length > 0 ? 1 : 0);
  const to = pagination?.to ?? users.length;
  const hasPrevious = currentPage > 1;
  const hasNext = currentPage < lastPage;

  const dialogCopy = useMemo(() => {
    if (!pendingAction) return null;

    if (pendingAction.type === "delete") {
      return {
        title: "Delete user?",
        description: `Delete ${pendingAction.label} permanently. This action cannot be undone.`,
        confirmLabel: "Delete user",
      };
    }

    return {
      title: "Suspend user?",
      description: `Suspend ${pendingAction.label} by turning off account access.`,
      confirmLabel: "Suspend user",
    };
  }, [pendingAction]);

  const runAction = () => {
    if (!pendingAction) return;

    startTransition(async () => {
      const result =
        pendingAction.type === "delete"
          ? await deleteUserAction(pendingAction.userId)
          : await suspendUserAction(pendingAction.userId);

      if (result.status === "success") {
        toast.success(result.message);
        setPendingAction(null);
        router.refresh();
        return;
      }

      toast.error(result.message);
    });
  };

  if (users.length === 0) {
    return (
      <section className="rounded-lg border border-dashed border-[rgba(15,23,42,0.18)] bg-white/52 p-8 text-center shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/12 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.36)]">
        <h2 className="text-xl font-bold text-slate-950 dark:text-white">
          No users found
        </h2>
        <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-600 dark:text-slate-300">
          Try a different search or add a user first.
        </p>
        <Link
          href="/users/add"
          className="mt-5 inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
        >
          <UserPlus className="size-4" />
          Add user
        </Link>
      </section>
    );
  }

  return (
    <>
      <div className="space-y-4">
        <div className="overflow-hidden rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/62 shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/10 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.38)]">
          <div className="overflow-x-auto">
            <table className="min-w-[1040px] w-full table-fixed border-collapse text-left text-sm">
              <thead className="bg-slate-950 text-xs font-bold uppercase text-white dark:bg-white/10 dark:text-slate-200">
                <tr>
                  <th scope="col" className="w-32 px-4 py-3">
                    UID
                  </th>
                  <th scope="col" className="w-48 px-4 py-3">
                    Name
                  </th>
                  <th scope="col" className="w-56 px-4 py-3">
                    Email
                  </th>
                  <th scope="col" className="w-36 px-4 py-3">
                    Phone
                  </th>
                  <th scope="col" className="w-36 px-4 py-3">
                    Device
                  </th>
                  <th
                    scope="col"
                    className="w-32 px-4 py-3"
                    suppressHydrationWarning
                  >
                    Last login
                  </th>
                  <th scope="col" className="w-28 px-4 py-3">
                    Status
                  </th>
                  <th scope="col" className="w-44 px-4 py-3 text-right">
                    Action
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[rgba(15,23,42,0.1)] dark:divide-white/10">
                {users.map((user) => {
                  const uid = valueToString(user.uid);
                  const actionId = userActionId(user);
                  const active = valueToBoolean(user.isACActive);
                  const label = userLabel(user);

                  return (
                    <tr
                      key={actionId || valueToString(user.mail)}
                      className="bg-white/30 text-slate-700 transition hover:bg-teal-50/80 dark:bg-transparent dark:text-slate-200 dark:hover:bg-white/8"
                    >
                      <td className="px-4 py-3 align-middle">
                        <span className="block truncate font-bold text-slate-950 dark:text-white">
                          {uid || valueToString(user.num) || "Unknown"}
                        </span>
                      </td>
                      <td className="px-4 py-3 align-middle">
                        <span className="block truncate font-semibold text-slate-950 dark:text-white">
                          {valueToString(user.name) || "Unnamed user"}
                        </span>
                      </td>
                      <td className="px-4 py-3 align-middle">
                        <span className="block truncate">
                          {valueToString(user.mail) || "Not set"}
                        </span>
                      </td>
                      <td className="px-4 py-3 align-middle">
                        {valueToString(user.auth_phone) ||
                          valueToString(user.call) ||
                          "Not set"}
                      </td>
                      <td className="px-4 py-3 align-middle">
                        <span className="block truncate">
                          {valueToString(user.device_name) || "Not linked"}
                        </span>
                        {valueToString(user.device_id) ? (
                          <span className="mt-0.5 block truncate text-xs font-semibold text-slate-500 dark:text-slate-400">
                            {valueToString(user.device_id)}
                          </span>
                        ) : null}
                      </td>
                      <td
                        className="px-4 py-3 align-middle"
                        suppressHydrationWarning
                      >
                        {formatDate(userLastLogin(user))}
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
                          {active ? "Active" : "Suspended"}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-right align-middle">
                        <div className="flex justify-end gap-2">
                          <button
                            type="button"
                            disabled={!actionId || !active || isPending}
                            onClick={() =>
                              setPendingAction({
                                type: "suspend",
                                userId: actionId,
                                label,
                              })
                            }
                            className="inline-flex min-h-9 items-center justify-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 text-xs font-bold text-amber-800 transition hover:-translate-y-0.5 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-amber-300/20 dark:bg-amber-300/10 dark:text-amber-100 dark:hover:bg-amber-300/16"
                          >
                            <Ban className="size-3.5" />
                            Suspend
                          </button>
                          <button
                            type="button"
                            disabled={!actionId || isPending}
                            onClick={() =>
                              setPendingAction({
                                type: "delete",
                                userId: actionId,
                                label,
                              })
                            }
                            className="inline-flex min-h-9 items-center justify-center gap-2 rounded-md border border-rose-200 bg-rose-50 px-3 text-xs font-bold text-rose-700 transition hover:-translate-y-0.5 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-300/20 dark:bg-rose-300/10 dark:text-rose-100 dark:hover:bg-rose-300/16"
                          >
                            <Trash2 className="size-3.5" />
                            Delete
                          </button>
                        </div>
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

      {dialogCopy && pendingAction ? (
        <ConfirmDialog
          open={Boolean(pendingAction)}
          title={dialogCopy.title}
          description={dialogCopy.description}
          confirmLabel={dialogCopy.confirmLabel}
          isPending={isPending}
          variant="danger"
          onConfirm={runAction}
          onClose={() => {
            if (!isPending) setPendingAction(null);
          }}
        />
      ) : null}
    </>
  );
}
