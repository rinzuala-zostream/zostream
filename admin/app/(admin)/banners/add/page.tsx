import { cookies } from "next/headers";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { CreateBannerForm } from "@/app/features/banners/components/create-banner-form";

export const dynamic = "force-dynamic";

export default async function AddBannerPage() {
  const cookieStore = await cookies();
  const savedMode = cookieStore.get("theme-mode")?.value;
  const initialMode = savedMode === "dark" ? "dark" : "light";

  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 lg:py-1 dark:text-white">
      <div className="flex w-full flex-1 flex-col lg:min-h-0">
        <AdminPageHeader title="Add banner" initialMode={initialMode} />

        <div className="admin-sidebar-scroll mt-3 flex-1 pb-4 lg:min-h-0">
          <section className="relative mb-4 overflow-hidden rounded-lg border border-white/58 bg-[linear-gradient(135deg,rgba(255,255,255,0.82),rgba(236,253,245,0.5)_46%,rgba(224,242,254,0.52))] p-5 backdrop-blur-xl sm:p-6 dark:border-white/10 dark:bg-[linear-gradient(135deg,rgba(15,23,42,0.82),rgba(20,83,45,0.2)_46%,rgba(8,47,73,0.28))] dark:shadow-[0_18px_60px_rgba(2,6,23,0.46)]">
            <div className="max-w-3xl">
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-teal-700 dark:text-cyan-200">
                Banner setup
              </p>
              <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl dark:text-white">
                Create a featured banner.
              </h2>
              <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
                Add image or video placements for movies, ads, categories, or
                external links.
              </p>
            </div>
          </section>

          <CreateBannerForm />
        </div>
      </div>
    </main>
  );
}
