"use client";

import { useActionState, useEffect, useMemo, useState } from "react";
import { ArrowDown, ArrowUp, Plus, Save, Trash2 } from "lucide-react";
import { useRouter } from "next/navigation";
import { toast } from "react-toastify";
import {
  saveHomeSectionsAction,
  type HomeSectionMutationState,
} from "@/app/(admin)/home/sections/actions";
import type { HomeSectionItem } from "@/app/features/home-sections/services/home-section-service";

const initialState: HomeSectionMutationState = { status: "idle", message: "" };
const inputClass =
  "w-full rounded-md border border-slate-900/15 bg-white/70 px-3 py-2 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-400 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";

export function HomeSectionManager({
  sections,
}: {
  sections: HomeSectionItem[];
}) {
  const router = useRouter();
  const [active, setActive] = useState(() =>
    sections.filter((section) => section.is_enabled),
  );
  const [state, formAction, pending] = useActionState(
    saveHomeSectionsAction,
    initialState,
  );
  const available = useMemo(
    () => sections.filter((section) => !active.some((item) => item.key === section.key)),
    [active, sections],
  );

  useEffect(() => {
    if (state.status === "idle") return;
    if (state.status === "success") {
      toast.success(state.message);
      router.refresh();
    } else {
      toast.error(state.message);
    }
  }, [router, state.message, state.resetKey, state.status]);

  const move = (index: number, direction: -1 | 1) => {
    const target = index + direction;
    if (target < 0 || target >= active.length) return;
    setActive((current) => {
      const next = [...current];
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });
  };

  return (
    <form action={formAction} className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_300px]">
      <input
        type="hidden"
        name="sections"
        value={JSON.stringify(active.map(({ key, title }) => ({ key, title })))}
      />

      <section className="liquid-glass rounded-lg p-4 sm:p-6">
        <div className="mb-5 flex items-center justify-between gap-4">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              Frontend response order
            </p>
            <h2 className="mt-1 text-xl font-bold">Active home sections</h2>
          </div>
          <span className="rounded-full bg-teal-100 px-3 py-1 text-xs font-bold text-teal-800 dark:bg-cyan-300/10 dark:text-cyan-100">
            {active.length} active
          </span>
        </div>

        {active.length ? (
          <div className="space-y-3">
            {active.map((section, index) => (
              <article
                key={section.key}
                className="grid gap-3 rounded-lg border border-slate-900/10 bg-white/45 p-3 sm:grid-cols-[3rem_minmax(0,1fr)_auto] sm:items-center dark:border-white/8 dark:bg-white/4"
              >
                <span className="flex size-10 items-center justify-center rounded-md bg-slate-950 text-sm font-black text-white dark:bg-cyan-300 dark:text-slate-950">
                  {index + 1}
                </span>
                <div className="min-w-0">
                  <p className="mb-1 truncate text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    {section.key}
                  </p>
                  <input
                    aria-label={`${section.key} title`}
                    value={section.title}
                    maxLength={120}
                    onChange={(event) =>
                      setActive((current) =>
                        current.map((item) =>
                          item.key === section.key
                            ? { ...item, title: event.target.value }
                            : item,
                        ),
                      )
                    }
                    className={inputClass}
                  />
                </div>
                <div className="flex items-center gap-1 sm:self-end">
                  <button type="button" aria-label="Move up" disabled={index === 0} onClick={() => move(index, -1)} className="rounded-md p-2 text-slate-600 hover:bg-slate-100 disabled:opacity-25 dark:text-slate-300 dark:hover:bg-white/8"><ArrowUp className="size-4" /></button>
                  <button type="button" aria-label="Move down" disabled={index === active.length - 1} onClick={() => move(index, 1)} className="rounded-md p-2 text-slate-600 hover:bg-slate-100 disabled:opacity-25 dark:text-slate-300 dark:hover:bg-white/8"><ArrowDown className="size-4" /></button>
                  <button type="button" aria-label="Remove section" onClick={() => setActive((current) => current.filter((item) => item.key !== section.key))} className="rounded-md p-2 text-rose-600 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-rose-300/10"><Trash2 className="size-4" /></button>
                </div>
              </article>
            ))}
          </div>
        ) : (
          <div className="rounded-lg border border-dashed border-slate-300 px-5 py-12 text-center text-sm text-slate-500 dark:border-white/15 dark:text-slate-400">
            No home sections are active. Add one from the available list.
          </div>
        )}
      </section>

      <aside className="space-y-4">
        <section className="liquid-glass h-fit rounded-lg p-4">
          <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
            Add sections
          </p>
          <div className="mt-3 space-y-2">
            {available.length ? available.map((section) => (
              <button
                key={section.key}
                type="button"
                onClick={() => setActive((current) => [...current, section])}
                className="flex w-full items-center gap-3 rounded-md border border-slate-900/10 bg-white/45 px-3 py-3 text-left text-sm font-bold transition hover:border-teal-300 hover:bg-teal-50 dark:border-white/8 dark:bg-white/4 dark:hover:border-cyan-300/30 dark:hover:bg-cyan-300/8"
              >
                <Plus className="size-4 shrink-0 text-teal-700 dark:text-cyan-200" />
                <span className="min-w-0"><span className="block truncate">{section.title}</span><span className="mt-0.5 block truncate text-[10px] font-semibold text-slate-500 dark:text-slate-400">{section.key}</span></span>
              </button>
            )) : <p className="py-5 text-center text-sm text-slate-500 dark:text-slate-400">Every available section is active.</p>}
          </div>
        </section>

        <button type="submit" disabled={pending || active.some((section) => !section.title.trim())} className="flex w-full items-center justify-center gap-2 rounded-md bg-slate-950 px-5 py-4 text-sm font-bold text-white disabled:opacity-50 dark:bg-cyan-300 dark:text-slate-950">
          <Save className="size-4" /> {pending ? "Saving…" : "Save home layout"}
        </button>
      </aside>
    </form>
  );
}
