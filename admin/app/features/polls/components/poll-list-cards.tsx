"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import {
  BarChart3,
  CalendarClock,
  PencilLine,
  Trash2,
  Vote,
} from "lucide-react";
import { toast } from "react-toastify";
import { deletePollAction } from "@/app/(admin)/polls/results/actions";
import { ConfirmDialog } from "@/app/components/confirm-dialog";
import { cn } from "@/lib/utils";
import type { PollItem } from "@/app/features/polls/services/poll-service";

type PollListCardsProps = {
  polls: PollItem[];
};

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

function valueToNumber(value: unknown) {
  const parsed =
    typeof value === "number" ? value : Number(valueToString(value) || NaN);
  return Number.isFinite(parsed) ? parsed : 0;
}

function formatDateTime(value: unknown) {
  const text = valueToString(value);
  if (!text) return "Not scheduled";

  const date = new Date(text);
  if (Number.isNaN(date.getTime())) return text;

  return new Intl.DateTimeFormat("en-IN", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(date);
}

function pollState(poll: PollItem) {
  const status = valueToString(poll.status).toLowerCase();
  const startsAt = valueToString(poll.starts_at);
  const endsAt = valueToString(poll.ends_at);
  const now = Date.now();

  if (status === "closed") return "closed";
  if (startsAt && new Date(startsAt).getTime() > now) return "scheduled";
  if (endsAt && new Date(endsAt).getTime() < now) return "ended";
  return "active";
}

export function PollListCards({ polls }: PollListCardsProps) {
  const router = useRouter();
  const [selectedPoll, setSelectedPoll] = useState<PollItem | null>(null);
  const [activeFilter, setActiveFilter] = useState("all");
  const [isPending, startTransition] = useTransition();

  const visiblePolls = polls.filter((poll) => {
    if (activeFilter === "all") return true;
    return pollState(poll) === activeFilter;
  });

  const deleteSelectedPoll = () => {
    const pollId = valueToString(selectedPoll?.id);
    if (!pollId) return;

    startTransition(async () => {
      const result = await deletePollAction(pollId);

      if (result.status === "success") {
        toast.success(result.message || "Poll deleted.");
        setSelectedPoll(null);
        router.refresh();
        return;
      }

      toast.error(result.message || "Poll could not be deleted.");
    });
  };

  if (polls.length === 0) {
    return (
      <section className="rounded-lg border border-dashed border-[rgba(15,23,42,0.18)] bg-white/52 p-8 text-center shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/12 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.36)]">
        <h2 className="text-xl font-bold text-slate-950 dark:text-white">
          No polls yet
        </h2>
        <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-600 dark:text-slate-300">
          Create a poll first, then it will appear here for editing and result
          review.
        </p>
        <Link
          href="/polls/create"
          className="mt-5 inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
        >
          Create poll
        </Link>
      </section>
    );
  }

  return (
    <>
      <div className="mb-4 overflow-x-auto pb-1">
        <div className="flex min-w-max gap-2">
          {[
            ["all", "All"],
            ["active", "Active"],
            ["scheduled", "Scheduled"],
            ["ended", "Ended"],
            ["closed", "Closed"],
          ].map(([value, label]) => {
            const isSelected = activeFilter === value;

            return (
              <button
                key={value}
                type="button"
                onClick={() => setActiveFilter(value)}
                className={cn(
                  "inline-flex min-h-10 items-center justify-center rounded-md border px-4 text-sm font-bold transition",
                  isSelected
                    ? "border-slate-950 bg-slate-950 text-white shadow-[0_12px_28px_rgba(15,23,42,0.16)] dark:border-white dark:bg-white dark:text-slate-950"
                    : "border-[rgba(15,23,42,0.14)] bg-white/58 text-slate-700 hover:bg-white hover:text-slate-950 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12 dark:hover:text-white",
                )}
              >
                {label}
              </button>
            );
          })}
        </div>
      </div>

      {visiblePolls.length === 0 ? (
        <section className="rounded-lg border border-dashed border-[rgba(15,23,42,0.18)] bg-white/52 p-8 text-center shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/12 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.36)]">
          <h2 className="text-xl font-bold text-slate-950 dark:text-white">
            No matching polls
          </h2>
          <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-600 dark:text-slate-300">
            Polls matching this filter will appear here once they exist.
          </p>
        </section>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {visiblePolls.map((poll) => {
            const pollId = valueToString(poll.id);
            const state = pollState(poll);
            const options = Array.isArray(poll.options) ? poll.options : [];
            const totalVotes = valueToNumber(poll.votes_count);

            return (
              <article
                key={pollId || valueToString(poll.question)}
                className="liquid-glass flex min-h-80 flex-col rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]"
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
                      Poll #{pollId || "draft"}
                    </p>
                    <h2 className="mt-2 line-clamp-2 text-xl font-bold text-slate-950 dark:text-white">
                      {valueToString(poll.question) || "Untitled poll"}
                    </h2>
                  </div>
                  <span
                    className={cn(
                      "inline-flex shrink-0 items-center rounded-md px-2.5 py-1 text-xs font-bold",
                      state === "active" &&
                        "bg-teal-100 text-teal-700 dark:bg-cyan-300/12 dark:text-cyan-100",
                      state === "scheduled" &&
                        "bg-amber-100 text-amber-700 dark:bg-amber-300/12 dark:text-amber-100",
                      state === "ended" &&
                        "bg-slate-200 text-slate-700 dark:bg-white/10 dark:text-slate-200",
                      state === "closed" &&
                        "bg-rose-100 text-rose-700 dark:bg-rose-300/12 dark:text-rose-100",
                    )}
                  >
                    {state}
                  </span>
                </div>

                <p className="mt-3 line-clamp-2 text-sm leading-6 text-slate-600 dark:text-slate-300">
                  {valueToString(poll.description) || "No description added."}
                </p>

                <dl className="mt-5 grid gap-3 text-sm text-slate-700 dark:text-slate-200">
                  <div className="flex items-center gap-3 rounded-md bg-white/45 px-3 py-2 dark:bg-white/6">
                    <Vote className="size-4 text-teal-700 dark:text-cyan-200" />
                    <dd>{totalVotes} total votes</dd>
                  </div>
                  <div className="flex items-center gap-3 rounded-md bg-white/45 px-3 py-2 dark:bg-white/6">
                    <BarChart3 className="size-4 text-teal-700 dark:text-cyan-200" />
                    <dd>{options.length} options</dd>
                  </div>
                  <div className="flex items-center gap-3 rounded-md bg-white/45 px-3 py-2 dark:bg-white/6">
                    <CalendarClock className="size-4 text-teal-700 dark:text-cyan-200" />
                    <dd>{formatDateTime(poll.starts_at)}</dd>
                  </div>
                </dl>

                <div className="mt-4 space-y-2 rounded-md bg-white/45 p-3 dark:bg-white/6">
                  {options.slice(0, 3).map((option) => (
                    <div
                      key={valueToString(option.id) || valueToString(option.option_text)}
                      className="flex items-center justify-between gap-3 text-sm"
                    >
                      <span className="truncate text-slate-700 dark:text-slate-200">
                        {valueToString(option.option_text) || "Untitled option"}
                      </span>
                      <span className="shrink-0 font-bold text-slate-950 dark:text-white">
                        {valueToNumber(option.votes_count)}
                      </span>
                    </div>
                  ))}
                  {options.length > 3 ? (
                    <p className="text-xs text-slate-500 dark:text-slate-400">
                      +{options.length - 3} more options
                    </p>
                  ) : null}
                </div>

                <div className="mt-auto flex flex-col gap-2 pt-5 sm:flex-row">
                  <Link
                    href={`/polls/results/${pollId}`}
                    className={cn(
                      "inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200",
                      !pollId && "pointer-events-none opacity-50",
                    )}
                  >
                    <PencilLine className="size-4" />
                    Edit
                  </Link>
                  <button
                    type="button"
                    disabled={!pollId}
                    onClick={() => setSelectedPoll(poll)}
                    className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-md border border-rose-200 bg-rose-50 px-4 text-sm font-bold text-rose-700 transition hover:-translate-y-0.5 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-300/20 dark:bg-rose-300/10 dark:text-rose-100 dark:hover:bg-rose-300/16"
                  >
                    <Trash2 className="size-4" />
                    Delete
                  </button>
                </div>
              </article>
            );
          })}
        </div>
      )}

      <ConfirmDialog
        open={Boolean(selectedPoll)}
        title="Delete poll?"
        description="This will remove the poll and its voting data."
        confirmLabel={isPending ? "Deleting..." : "Delete"}
        cancelLabel="Cancel"
        isPending={isPending}
        variant="danger"
        onConfirm={deleteSelectedPoll}
        onClose={() => setSelectedPoll(null)}
      />
    </>
  );
}
