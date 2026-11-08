"use client";

import { useEffect, useRef } from "react";
import Link from "next/link";
import {
  CalendarDays,
  Hash,
  ListVideo,
  PencilLine,
  Trash2,
  X,
} from "lucide-react";
import type { EpisodeSearchResultItem } from "@/app/(admin)/seasons/update/actions";

type EpisodeListSheetProps = {
  open: boolean;
  title: string;
  subtitle?: string;
  episodes: EpisodeSearchResultItem[];
  initialScrollTop?: number;
  sheetKey?: string;
  onClose: () => void;
  onDeleteEpisode: (episode: EpisodeSearchResultItem) => void;
  onEditEpisode?: (scrollTop: number) => void;
  onScrollTopChange?: (scrollTop: number) => void;
};

export function EpisodeListSheet({
  open,
  title,
  subtitle,
  episodes,
  initialScrollTop = 0,
  sheetKey = "episode-list",
  onClose,
  onDeleteEpisode,
  onEditEpisode,
  onScrollTopChange,
}: EpisodeListSheetProps) {
  const scrollRef = useRef<HTMLDivElement>(null);
  const restoredSheetKeyRef = useRef("");

  useEffect(() => {
    if (!open) {
      restoredSheetKeyRef.current = "";
      return;
    }

    if (restoredSheetKeyRef.current === sheetKey) return;
    restoredSheetKeyRef.current = sheetKey;

    const timeoutId = window.setTimeout(() => {
      if (scrollRef.current) {
        scrollRef.current.scrollTop = initialScrollTop;
      }
    }, 0);

    return () => window.clearTimeout(timeoutId);
  }, [initialScrollTop, open, sheetKey]);

  if (!open) return null;

  const currentScrollTop = () => scrollRef.current?.scrollTop ?? 0;

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/54 px-3 pb-3 pt-16 backdrop-blur-sm">
      <button
        type="button"
        aria-label="Close episode list"
        className="absolute inset-0 cursor-default"
        onClick={onClose}
      />

      <section className="liquid-glass relative z-10 flex max-h-[82vh] w-full max-w-4xl translate-y-0 flex-col overflow-hidden rounded-lg border border-white/58 bg-white/86 shadow-[0_28px_90px_rgba(2,6,23,0.34)] dark:border-white/10 dark:bg-slate-950/88 dark:shadow-[0_28px_90px_rgba(2,6,23,0.62)]">
        <div className="flex items-start justify-between gap-4 border-b border-black/6 p-4 dark:border-white/10 sm:p-5">
          <div className="min-w-0">
            <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              <ListVideo className="size-4" />
              Episodes
            </div>
            <h2 className="mt-2 truncate text-lg font-bold text-slate-950 dark:text-white">
              {title}
            </h2>
            {subtitle ? (
              <p className="mt-1 truncate text-sm font-semibold text-slate-500 dark:text-slate-400">
                {subtitle}
              </p>
            ) : null}
          </div>

          <button
            type="button"
            onClick={onClose}
            className="inline-flex size-9 shrink-0 items-center justify-center rounded-md bg-slate-950 text-white shadow-[0_10px_24px_rgba(15,23,42,0.16)] transition hover:-translate-y-0.5 hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
            aria-label="Close episode list"
          >
            <X className="size-4" />
          </button>
        </div>

        <div
          ref={scrollRef}
          className="admin-sidebar-scroll flex-1 overflow-y-auto p-3 sm:p-4"
          onScroll={(event) => {
            onScrollTopChange?.(event.currentTarget.scrollTop);
          }}
        >
          {episodes.length > 0 ? (
            <div className="grid gap-2">
              {episodes.map((episode, episodeIndex) => (
                <article
                  key={
                    episode.id ||
                    `${episode.title}-${episode.episodeNumber}-${episodeIndex}`
                  }
                  className="flex flex-col gap-3 rounded-md border border-white/55 bg-white/56 p-3 shadow-[0_1px_2px_rgba(15,23,42,0.04)] dark:border-white/10 dark:bg-white/8 sm:flex-row sm:items-center sm:justify-between"
                >
                  <div className="min-w-0">
                    <h3 className="truncate text-sm font-bold text-slate-950 dark:text-white">
                      {episode.title}
                    </h3>
                    <div className="mt-2 flex flex-wrap gap-2 text-xs font-semibold text-slate-600 dark:text-slate-300">
                      {episode.id ? (
                        <span className="inline-flex min-h-8 items-center gap-1.5 rounded-md bg-white/70 px-3 font-mono dark:bg-white/8">
                          <Hash className="size-3.5" />
                          {episode.id}
                        </span>
                      ) : null}
                      {episode.episodeNumber ? (
                        <span className="inline-flex min-h-8 items-center rounded-md bg-white/70 px-3 dark:bg-white/8">
                          Episode {episode.episodeNumber}
                        </span>
                      ) : null}
                      <span className="inline-flex min-h-8 items-center rounded-md bg-white/70 px-3 dark:bg-white/8">
                        {episode.status}
                      </span>
                      {episode.isPayPerView ? (
                        <span className="inline-flex min-h-8 items-center rounded-md bg-teal-100 px-3 text-teal-800 dark:bg-cyan-300/12 dark:text-cyan-100">
                          Pay per view
                          {episode.amount ? ` - ${episode.amount}` : ""}
                        </span>
                      ) : null}
                      {episode.releaseDate ? (
                        <span className="inline-flex min-h-8 items-center gap-1.5 rounded-md bg-white/70 px-3 dark:bg-white/8">
                          <CalendarDays className="size-3.5" />
                          {episode.releaseDate}
                        </span>
                      ) : null}
                    </div>
                  </div>

                  {episode.id ? (
                    <div className="flex shrink-0 items-center gap-2 sm:justify-end">
                      <Link
                        href={`/seasons/episodes/update/${episode.id}`}
                        onClick={() => onEditEpisode?.(currentScrollTop())}
                        className="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-slate-950 px-3 text-sm font-bold text-white shadow-[0_10px_22px_rgba(15,23,42,0.14)] transition hover:-translate-y-0.5 hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                      >
                        <PencilLine className="size-4" />
                        Edit
                      </Link>
                      <button
                        type="button"
                        onClick={() => onDeleteEpisode(episode)}
                        className="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-rose-200/80 bg-rose-50/90 px-3 text-sm font-bold text-rose-700 shadow-[0_8px_18px_rgba(225,29,72,0.08)] transition hover:-translate-y-0.5 hover:border-rose-300 hover:bg-rose-100 dark:border-rose-300/20 dark:bg-rose-300/10 dark:text-rose-100 dark:hover:bg-rose-300/16"
                      >
                        <Trash2 className="size-4" />
                        Delete
                      </button>
                    </div>
                  ) : null}
                </article>
              ))}
            </div>
          ) : (
            <div className="rounded-md border border-white/55 bg-white/50 p-4 text-sm font-semibold text-slate-600 dark:border-white/10 dark:bg-white/8 dark:text-slate-300">
              No episodes are attached to this season yet.
            </div>
          )}
        </div>
      </section>
    </div>
  );
}
