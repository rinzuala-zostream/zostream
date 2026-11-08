import { cookies } from "next/headers";
import { LogOut, ShieldCheck, UserRound } from "lucide-react";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { LogoutButton } from "@/app/features/auth/components/logout-button";

export const dynamic = "force-dynamic";

export default async function ProfilePage() {
  const cookieStore = await cookies();
  const uid = cookieStore.get("zostream_admin_uid")?.value?.trim() ?? "";
  const savedMode = cookieStore.get("theme-mode")?.value;
  const initialMode = savedMode === "dark" ? "dark" : "light";

  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 lg:py-1 dark:text-white">
      <AdminPageHeader title="Profile" initialMode={initialMode} />

      <section className="liquid-glass relative flex w-full flex-1 flex-col overflow-hidden rounded-2xl p-4 sm:rounded-[2rem] sm:p-7">
        <span
          aria-hidden="true"
          className="pointer-events-none absolute -left-16 -top-20 size-56 rounded-full bg-white/35 blur-3xl dark:bg-cyan-300/10"
        />
        <span
          aria-hidden="true"
          className="pointer-events-none absolute -bottom-24 right-4 size-64 rounded-full bg-sky-200/25 blur-3xl dark:bg-emerald-300/10"
        />

        <header className="relative flex flex-col gap-3 border-b border-white/55 pb-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:pb-5 dark:border-white/10">
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400 sm:text-sm sm:tracking-[0.2em] dark:text-white/45">
            Account
          </p>
          <div className="liquid-glass-soft inline-flex w-fit items-center gap-2 rounded-md px-3 py-2 text-sm font-semibold text-emerald-700 shadow-[inset_0_1px_0_rgba(255,255,255,0.72),0_10px_24px_rgba(16,185,129,0.12)] sm:rounded-2xl sm:px-4 dark:text-emerald-300">
            <ShieldCheck className="size-4" />
            Active session
          </div>
        </header>

        <div className="relative grid flex-1 items-start gap-4 overflow-y-auto py-4 sm:gap-5 sm:py-6 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.45fr)] lg:items-center lg:overflow-visible">
          <div className="liquid-glass-soft rounded-xl p-4 shadow-[inset_0_1px_0_rgba(255,255,255,0.7),0_16px_35px_rgba(15,23,42,0.08)] sm:rounded-[1.75rem] sm:p-6 dark:shadow-[inset_0_1px_0_rgba(255,255,255,0.12),0_18px_45px_rgba(2,6,23,0.35)]">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
              <div className="flex size-14 shrink-0 items-center justify-center rounded-2xl border border-white/40 bg-[#232323]/90 text-xl font-bold text-white shadow-[inset_0_1px_0_rgba(255,255,255,0.25),0_14px_30px_rgba(15,23,42,0.18)] sm:size-16 sm:rounded-3xl sm:text-2xl dark:bg-white/90 dark:text-slate-950">
                {uid.slice(0, 1).toUpperCase()}
              </div>
              <div className="min-w-0">
                <p className="text-sm font-semibold text-slate-500 dark:text-white/55">
                  Signed in as
                </p>
                <p className="mt-1 break-words text-xl font-bold text-[#252525] sm:text-2xl dark:text-white">
                  {uid}
                </p>
              </div>
            </div>

            <div className="mt-5 grid gap-3 sm:mt-8 sm:grid-cols-2">
              <div className="rounded-xl border border-white/55 bg-white/35 p-4 shadow-[inset_0_1px_0_rgba(255,255,255,0.55)] sm:rounded-2xl dark:border-white/10 dark:bg-white/5">
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400 sm:tracking-[0.16em]">
                  Role
                </p>
                <p className="mt-2 font-semibold text-slate-800 dark:text-white">
                  Admin
                </p>
              </div>
              <div className="rounded-xl border border-white/55 bg-white/35 p-4 shadow-[inset_0_1px_0_rgba(255,255,255,0.55)] sm:rounded-2xl dark:border-white/10 dark:bg-white/5">
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400 sm:tracking-[0.16em]">
                  Access
                </p>
                <p className="mt-2 font-semibold text-slate-800 dark:text-white">
                  Dashboard
                </p>
              </div>
            </div>
          </div>

          <aside className="rounded-xl border border-rose-100/80 bg-gradient-to-br from-white/58 via-rose-50/55 to-white/18 p-4 shadow-[inset_0_1px_0_rgba(255,255,255,0.78),0_16px_35px_rgba(225,29,72,0.08)] backdrop-blur-2xl sm:rounded-[1.75rem] sm:p-6 dark:border-rose-300/15 dark:from-rose-400/12 dark:via-white/5 dark:to-white/0 dark:shadow-[inset_0_1px_0_rgba(255,255,255,0.12),0_18px_45px_rgba(2,6,23,0.35)]">
            <div className="flex size-11 items-center justify-center rounded-xl border border-white/55 bg-white/55 text-rose-600 shadow-[inset_0_1px_0_rgba(255,255,255,0.72),0_10px_24px_rgba(225,29,72,0.12)] sm:size-12 sm:rounded-2xl dark:border-white/10 dark:bg-white/10 dark:text-rose-300">
              <LogOut className="size-5" />
            </div>
            <h2 className="mt-4 text-lg font-bold text-rose-950 sm:mt-5 sm:text-xl dark:text-white">
              Logout
            </h2>
            <p className="mt-2 text-sm leading-6 text-rose-900/70 dark:text-white/60">
              End this admin session on the current device. You can sign in
              again with QR login anytime.
            </p>
            <div className="mt-6">
              <LogoutButton />
            </div>
          </aside>
        </div>

        <div className="relative mt-1 flex items-start gap-2 rounded-xl border border-white/55 bg-white/35 px-3 py-3 text-sm text-slate-500 shadow-[inset_0_1px_0_rgba(255,255,255,0.58)] sm:mt-auto sm:items-center sm:rounded-2xl sm:px-4 dark:border-white/10 dark:bg-white/5 dark:text-white/55">
          <UserRound className="mt-0.5 size-4 shrink-0 sm:mt-0" />
          <span className="min-w-0">
            Profile details are based on the current admin session.
          </span>
        </div>
      </section>
    </main>
  );
}
