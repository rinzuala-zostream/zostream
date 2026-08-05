"use client";

import dynamic from "next/dynamic";
import type {
  EpisodeDetailsItem,
  EpisodeVideoUrlItem,
} from "@/app/features/seasons/services/episode-service";

type EditEpisodeFormNoSsrProps = {
  episode: EpisodeDetailsItem;
  episodeId: string;
  videoUrl?: EpisodeVideoUrlItem | null;
};

const EditEpisodeForm = dynamic(
  () =>
    import("./edit-episode-form").then((module) => module.EditEpisodeForm),
  {
    ssr: false,
    loading: () => (
      <section className="rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/52 p-6 text-sm font-semibold text-slate-600 shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/10 dark:bg-white/6 dark:text-slate-300 dark:shadow-[0_18px_54px_rgba(2,6,23,0.36)]">
        Loading episode form...
      </section>
    ),
  },
);

export function EditEpisodeFormNoSsr(props: EditEpisodeFormNoSsrProps) {
  return <EditEpisodeForm {...props} />;
}
