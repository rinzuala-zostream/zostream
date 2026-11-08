import { cookies } from "next/headers";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { HomeSectionManager } from "@/app/features/home-sections/components/home-section-manager";
import {
  homeSectionService,
  type HomeSectionItem,
} from "@/app/features/home-sections/services/home-section-service";

export const dynamic = "force-dynamic";

export default async function HomeSectionsPage() {
  const cookieStore = await cookies();
  const initialMode =
    cookieStore.get("theme-mode")?.value === "dark" ? "dark" : "light";
  let sections: HomeSectionItem[] = [];
  let errorMessage = "";

  try {
    sections = await homeSectionService.list();
  } catch (error) {
    errorMessage =
      error instanceof Error ? error.message : "Home sections could not be loaded.";
  }

  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 lg:py-1 dark:text-white">
      <div className="flex w-full flex-1 flex-col lg:min-h-0">
        <AdminPageHeader title="Home sections" initialMode={initialMode} />
        <div className="admin-sidebar-scroll mt-3 flex-1 pb-6 lg:min-h-0">
          <section className="relative mb-4 overflow-hidden rounded-lg border border-white/58 bg-[linear-gradient(135deg,rgba(255,255,255,0.82),rgba(236,253,245,0.5)_46%,rgba(224,242,254,0.52))] p-5 backdrop-blur-xl sm:p-6 dark:border-white/10 dark:bg-[linear-gradient(135deg,rgba(15,23,42,0.82),rgba(20,83,45,0.2)_46%,rgba(8,47,73,0.28))]">
            <p className="text-xs font-bold uppercase tracking-[0.2em] text-teal-700 dark:text-cyan-200">Homepage CMS</p>
            <h2 className="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Arrange the recommendation feed.</h2>
            <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">Move, rename, add or remove shelves without changing how recommendation items are calculated.</p>
          </section>
          {errorMessage ? (
            <div className="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100">{errorMessage}</div>
          ) : (
            <HomeSectionManager sections={sections} />
          )}
        </div>
      </div>
    </main>
  );
}
