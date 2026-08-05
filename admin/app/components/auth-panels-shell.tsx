"use client";

import dynamic from "next/dynamic";

type AuthPanelsShellProps = {
  token: string;
  expiresIn: number;
  qrError: string;
};

const AuthPanelsClientNoSsr = dynamic(
  () => import("./auth-panels-client").then((mod) => mod.AuthPanelsClient),
  {
    ssr: false,
    loading: () => (
      <section className="flex w-full max-w-6xl items-center justify-center rounded-4xl border border-slate-200/80 bg-linear-to-br from-white via-slate-50 to-slate-100/90 p-4 shadow-[0_25px_80px_rgba(15,23,42,0.08)] backdrop-blur-sm dark:border-white/12 dark:from-slate-900/95 dark:via-slate-900 dark:to-slate-800/90 dark:shadow-[0_30px_90px_rgba(2,6,23,0.55)] sm:p-6 md:p-8">
        <div className="h-96 w-full" />
      </section>
    ),
  },
);

export function AuthPanelsShell(props: AuthPanelsShellProps) {
  return <AuthPanelsClientNoSsr {...props} />;
}
