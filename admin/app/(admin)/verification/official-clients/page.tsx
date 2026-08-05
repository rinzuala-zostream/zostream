import { cookies } from "next/headers";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { OfficialClientManager } from "@/app/features/official-clients/components/official-client-manager";
import { listOfficialClientConfigs } from "@/app/features/official-clients/services/official-client-service";

export const dynamic = "force-dynamic";

export default async function OfficialClientsPage() {
  const cookieStore = await cookies();
  const initialMode =
    cookieStore.get("theme-mode")?.value === "dark" ? "dark" : "light";
  const configs = await listOfficialClientConfigs();

  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 lg:py-1 dark:text-white">
      <div className="flex w-full flex-1 flex-col lg:min-h-0">
        <AdminPageHeader
          title="Official Verification"
          initialMode={initialMode}
        />
        <div className="admin-sidebar-scroll mt-3 flex-1 pb-4 lg:min-h-0">
          <section className="relative mb-4 overflow-hidden rounded-lg border border-white/58 bg-[linear-gradient(135deg,rgba(255,255,255,0.84),rgba(224,242,254,0.56)_46%,rgba(219,234,254,0.58))] p-5 backdrop-blur-xl sm:p-6 dark:border-white/10 dark:bg-[linear-gradient(135deg,rgba(15,23,42,0.86),rgba(8,47,73,0.24)_46%,rgba(30,64,175,0.24))]">
            <p className="text-xs font-bold uppercase tracking-[0.2em] text-sky-700 dark:text-sky-200">
              Client trust control
            </p>
            <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl dark:text-white">
              Configure official app verification by platform.
            </h2>
            <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600 dark:text-slate-300">
              Android mobile/TV-ah signing SHA-256 multiple key support. Native iOS-ah
              Bundle ID + Apple Team ID check. Samsung/LG-ah app id/build id/origin/certificate
              checks platform capability angin dah theih. Platform thlak veleh trust checks UI a inthlak ang.
            </p>
          </section>

          <OfficialClientManager configs={configs} />
        </div>
      </div>
    </main>
  );
}
