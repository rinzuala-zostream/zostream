import { cookies } from "next/headers";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { LegalPageEditor } from "@/app/features/legal/components/legal-page-editor";
import { legalPageService, type LegalPageItem } from "@/app/features/legal/services/legal-page-service";

export const dynamic = "force-dynamic";

export default async function LegalPagesPage() {
  const cookieStore = await cookies();
  const initialMode = cookieStore.get("theme-mode")?.value === "dark" ? "dark" : "light";
  let pages: LegalPageItem[] = [];
  let errorMessage = "";

  try { pages = await legalPageService.list(); }
  catch (error) { errorMessage = error instanceof Error ? error.message : "Legal pages could not be loaded."; }

  return <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 lg:py-1 dark:text-white"><div className="flex w-full flex-1 flex-col lg:min-h-0"><AdminPageHeader title="Legal content" initialMode={initialMode} /><div className="admin-sidebar-scroll mt-3 flex-1 pb-6 lg:min-h-0"><section className="relative mb-4 overflow-hidden rounded-lg border border-white/58 bg-[linear-gradient(135deg,rgba(255,255,255,0.82),rgba(236,253,245,0.5)_46%,rgba(224,242,254,0.52))] p-5 backdrop-blur-xl sm:p-6 dark:border-white/10 dark:bg-[linear-gradient(135deg,rgba(15,23,42,0.82),rgba(20,83,45,0.2)_46%,rgba(8,47,73,0.28))]"><p className="text-xs font-bold uppercase tracking-[0.2em] text-teal-700 dark:text-cyan-200">Website CMS</p><h2 className="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Create, edit and publish legal pages.</h2><p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">Published changes are loaded automatically by the Zo Stream public website. Drafts remain hidden.</p></section>{errorMessage ? <div className="mb-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100">{errorMessage}</div> : null}<LegalPageEditor pages={pages} /></div></div></main>;
}
