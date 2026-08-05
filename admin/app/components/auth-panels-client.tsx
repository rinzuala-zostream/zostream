"use client";

import { WhatsAppOtpLogin } from "../features/auth/components/whatsapp-otp-login";
import { QrLoginLive } from "../features/qr/components/qr-login-live";

type AuthPanelsClientProps = {
  token: string;
  expiresIn: number;
  qrError: string;
};

export function AuthPanelsClient({
  token,
  expiresIn,
  qrError,
}: AuthPanelsClientProps) {
  return (
    <section className="flex w-full max-w-6xl items-center justify-center rounded-4xl border border-slate-200/80 bg-linear-to-br from-white via-slate-50 to-slate-100/90 p-4 shadow-[0_25px_80px_rgba(15,23,42,0.08)] backdrop-blur-sm dark:border-white/12 dark:from-slate-900/95 dark:via-slate-900 dark:to-slate-800/90 dark:shadow-[0_30px_90px_rgba(2,6,23,0.55)] sm:p-6 md:p-8">
      <div className="grid w-full grid-cols-1 items-center gap-8 md:grid-cols-[1fr_90px_1fr] md:gap-10">
        <div className="hidden md:order-1 md:block">
          <QrLoginLive
            initialToken={token}
            initialExpiresIn={expiresIn}
            initialError={qrError}
          />
        </div>
        <div className="relative hidden h-120 items-center justify-center md:order-2 md:flex">
          <div className="absolute inset-y-0 left-1/2 w-px -translate-x-1/2 bg-linear-to-b from-transparent via-slate-300 to-transparent dark:via-slate-400/70" />
          <div className="relative z-10 rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold tracking-wide text-slate-700 shadow-sm dark:border-white/15 dark:bg-slate-900 dark:text-white/85">
            OR
          </div>
        </div>

        <div className="md:order-3">
          <WhatsAppOtpLogin />
        </div>
      </div>
    </section>
  );
}
