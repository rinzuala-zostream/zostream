"use client";

import dynamic from "next/dynamic";
import type {
  PaginationMeta,
  UserItem,
} from "@/app/features/users/services/user-service";

type UserSuspendDeleteListNoSsrProps = {
  users: UserItem[];
  pagination?: PaginationMeta<UserItem>;
  page: number;
  perPage: number;
  search?: string;
};

const UserSuspendDeleteList = dynamic(
  () =>
    import("./user-suspend-delete-list").then(
      (module) => module.UserSuspendDeleteList,
    ),
  {
    ssr: false,
    loading: () => (
      <section className="rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/52 p-6 text-sm font-semibold text-slate-600 shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/10 dark:bg-white/6 dark:text-slate-300 dark:shadow-[0_18px_54px_rgba(2,6,23,0.36)]">
        Loading users...
      </section>
    ),
  },
);

export function UserSuspendDeleteListNoSsr(
  props: UserSuspendDeleteListNoSsrProps,
) {
  return <UserSuspendDeleteList {...props} />;
}
