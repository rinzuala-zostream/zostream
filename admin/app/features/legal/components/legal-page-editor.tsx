"use client";

import { useActionState, useEffect, useMemo, useState } from "react";
import { FilePlus2, Plus, Save, Trash2 } from "lucide-react";
import { useRouter } from "next/navigation";
import { toast } from "react-toastify";
import { saveLegalPageAction, type LegalPageMutationState } from "@/app/(admin)/legal/pages/actions";
import type { LegalPageItem, LegalPageSection } from "@/app/features/legal/services/legal-page-service";

const initialState: LegalPageMutationState = { status: "idle", message: "" };
const inputClass = "mt-2 w-full rounded-md border border-slate-900/15 bg-white/60 px-4 py-3 text-sm text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-teal-400 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";

type EditorDraft = Omit<LegalPageItem, "id"> & { id: string };

function emptyDraft(): EditorDraft {
  return { id: "", slug: "", eyebrow: "Legal", title: "", effective_date: "", intro: "", sections: [{ heading: "", body: "" }], is_published: false, sort_order: 0 };
}

function toDraft(page: LegalPageItem): EditorDraft {
  return { ...page, id: String(page.id), sections: page.sections?.length ? page.sections : [{ heading: "", body: "" }] };
}

export function LegalPageEditor({ pages }: { pages: LegalPageItem[] }) {
  const router = useRouter();
  const [selectedId, setSelectedId] = useState(pages[0] ? String(pages[0].id) : "new");
  const selectedPage = useMemo(() => pages.find((page) => String(page.id) === selectedId), [pages, selectedId]);
  const [draft, setDraft] = useState<EditorDraft>(() => selectedPage ? toDraft(selectedPage) : emptyDraft());
  const [state, formAction, pending] = useActionState(saveLegalPageAction, initialState);

  useEffect(() => { setDraft(selectedPage ? toDraft(selectedPage) : emptyDraft()); }, [selectedPage]);
  useEffect(() => {
    if (state.status === "idle") return;
    if (state.status === "success") { toast.success(state.message); router.refresh(); }
    else toast.error(state.message);
  }, [router, state.message, state.resetKey, state.status]);

  const updateSection = (index: number, field: keyof LegalPageSection, text: string) => {
    setDraft((current) => ({ ...current, sections: current.sections.map((section, sectionIndex) => sectionIndex === index ? { ...section, [field]: text } : section) }));
  };

  return (
    <div className="grid gap-4 xl:grid-cols-[280px_minmax(0,1fr)]">
      <aside className="liquid-glass h-fit rounded-lg p-3">
        <button type="button" onClick={() => setSelectedId("new")} className="mb-3 flex w-full items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-3 text-sm font-bold text-white dark:bg-cyan-300 dark:text-slate-950"><FilePlus2 className="size-4" /> New legal page</button>
        <div className="space-y-1">
          {pages.map((page) => <button key={page.id} type="button" onClick={() => setSelectedId(String(page.id))} className={`w-full rounded-md px-3 py-3 text-left transition ${selectedId === String(page.id) ? "bg-teal-50 text-teal-900 dark:bg-cyan-300/12 dark:text-cyan-100" : "hover:bg-white/60 dark:hover:bg-white/8"}`}><span className="block text-sm font-bold">{page.title}</span><span className="mt-1 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">/{page.slug}<i className={`size-2 rounded-full ${page.is_published ? "bg-emerald-500" : "bg-amber-400"}`} /></span></button>)}
        </div>
      </aside>

      <form action={formAction} className="space-y-4">
        <input type="hidden" name="id" value={draft.id} />
        <section className="liquid-glass rounded-lg p-5 sm:p-6">
          <div className="mb-5 flex items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">{draft.id ? "Edit page" : "Create page"}</p><h2 className="mt-1 text-2xl font-bold text-slate-950 dark:text-white">{draft.title || "Untitled legal page"}</h2></div><label className="flex items-center gap-2 text-sm font-semibold"><input name="is_published" type="checkbox" checked={draft.is_published} onChange={(event) => setDraft({ ...draft, is_published: event.target.checked })} className="size-4 accent-teal-600" /> Published</label></div>
          <div className="grid gap-4 md:grid-cols-2"><label className="text-sm font-semibold">Title<input name="title" required value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} className={inputClass} /></label><label className="text-sm font-semibold">Slug<input name="slug" required value={draft.slug} onChange={(event) => setDraft({ ...draft, slug: event.target.value.toLowerCase().replace(/\s+/g, "-") })} className={inputClass} placeholder="privacy-policy" /></label><label className="text-sm font-semibold">Eyebrow<input name="eyebrow" value={draft.eyebrow ?? ""} onChange={(event) => setDraft({ ...draft, eyebrow: event.target.value })} className={inputClass} /></label><label className="text-sm font-semibold">Effective date / version<input name="effective_date" value={draft.effective_date ?? ""} onChange={(event) => setDraft({ ...draft, effective_date: event.target.value })} className={inputClass} /></label></div>
          <label className="mt-4 block text-sm font-semibold">Introduction<textarea name="intro" rows={3} value={draft.intro ?? ""} onChange={(event) => setDraft({ ...draft, intro: event.target.value })} className={inputClass} /></label>
          <label className="mt-4 block max-w-40 text-sm font-semibold">Sort order<input name="sort_order" type="number" min="0" value={draft.sort_order} onChange={(event) => setDraft({ ...draft, sort_order: Number(event.target.value) })} className={inputClass} /></label>
        </section>

        <section className="liquid-glass rounded-lg p-5 sm:p-6"><div className="mb-5 flex items-center justify-between"><div><p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">Page sections</p><h2 className="mt-1 text-xl font-bold">Content blocks</h2></div><button type="button" onClick={() => setDraft({ ...draft, sections: [...draft.sections, { heading: "", body: "" }] })} className="flex items-center gap-2 rounded-md border border-teal-300/50 px-3 py-2 text-sm font-bold text-teal-800 dark:text-cyan-200"><Plus className="size-4" /> Add section</button></div>
          <div className="space-y-4">{draft.sections.map((section, index) => <div key={index} className="rounded-lg border border-slate-900/10 bg-white/35 p-4 dark:border-white/8 dark:bg-white/4"><div className="flex items-center justify-between"><span className="text-xs font-bold text-slate-500">SECTION {String(index + 1).padStart(2, "0")}</span><button type="button" disabled={draft.sections.length === 1} onClick={() => setDraft({ ...draft, sections: draft.sections.filter((_, itemIndex) => itemIndex !== index) })} className="rounded-md p-2 text-rose-500 hover:bg-rose-50 disabled:opacity-30 dark:hover:bg-rose-300/10"><Trash2 className="size-4" /></button></div><input name="section_heading" required value={section.heading} onChange={(event) => updateSection(index, "heading", event.target.value)} className={inputClass} placeholder="Section heading" /><textarea name="section_body" required rows={5} value={section.body} onChange={(event) => updateSection(index, "body", event.target.value)} className={inputClass} placeholder="Section content" /></div>)}</div>
        </section>

        {state.status === "error" ? <p className="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-800 dark:border-rose-300/20 dark:bg-rose-300/10 dark:text-rose-100">{state.message}</p> : null}
        <button type="submit" disabled={pending} className="flex w-full items-center justify-center gap-2 rounded-md bg-slate-950 px-5 py-4 text-sm font-bold text-white disabled:opacity-60 dark:bg-cyan-300 dark:text-slate-950"><Save className="size-4" /> {pending ? "Saving…" : draft.id ? "Save changes" : "Create legal page"}</button>
      </form>
    </div>
  );
}
