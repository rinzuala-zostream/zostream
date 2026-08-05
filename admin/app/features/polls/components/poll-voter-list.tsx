import Link from "next/link";
import {
  ChevronLeft,
  ChevronRight,
  Mail,
  Phone,
  User2,
  Vote,
} from "lucide-react";
import { cn } from "@/lib/utils";
import type {
  PollItem,
  PollVoteItem,
  PollVotersResponse,
} from "@/app/features/polls/services/poll-service";

type PollVoterListProps = {
  poll: PollItem;
  votes: PollVoteItem[];
  pagination?: PollVotersResponse["data"];
  page: number;
};

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

function formatDateTime(value: unknown) {
  const text = valueToString(value);
  if (!text) return "Unknown";

  const date = new Date(text);
  if (Number.isNaN(date.getTime())) return text;

  return new Intl.DateTimeFormat("en-IN", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(date);
}

function pageHref(pollId: string, page: number) {
  const params = new URLSearchParams({
    poll_id: pollId,
    page: String(page),
  });

  return `/polls/voters?${params.toString()}`;
}

export function PollVoterList({
  poll,
  votes,
  pagination,
  page,
}: PollVoterListProps) {
  const pollId = valueToString(poll.id);
  const currentPage = pagination?.current_page ?? page;
  const lastPage = pagination?.last_page ?? 1;
  const total = pagination?.total ?? votes.length;
  const from = pagination?.from ?? (votes.length > 0 ? 1 : 0);
  const to = pagination?.to ?? votes.length;
  const hasPrevious = currentPage > 1;
  const hasNext = currentPage < lastPage;

  if (votes.length === 0) {
    return (
      <section className="rounded-lg border border-dashed border-[rgba(15,23,42,0.18)] bg-white/52 p-8 text-center shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/12 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.36)]">
        <h2 className="text-xl font-bold text-slate-950 dark:text-white">
          No votes yet
        </h2>
        <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-600 dark:text-slate-300">
          Votes for this poll will appear here once people start participating.
        </p>
      </section>
    );
  }

  return (
    <div className="space-y-4">
      <div className="overflow-hidden rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/62 shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/10 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.38)]">
        <div className="overflow-x-auto">
          <table className="min-w-[980px] w-full table-fixed border-collapse text-left text-sm">
            <thead className="bg-slate-950 text-xs font-bold uppercase text-white dark:bg-white/10 dark:text-slate-200">
              <tr>
                <th scope="col" className="w-28 px-4 py-3">
                  Vote
                </th>
                <th scope="col" className="w-56 px-4 py-3">
                  User
                </th>
                <th scope="col" className="w-44 px-4 py-3">
                  Contact
                </th>
                <th scope="col" className="w-52 px-4 py-3">
                  Selected option
                </th>
                <th scope="col" className="w-36 px-4 py-3">
                  UID
                </th>
                <th scope="col" className="w-44 px-4 py-3">
                  Voted at
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[rgba(15,23,42,0.1)] dark:divide-white/10">
              {votes.map((vote) => {
                const voteId = valueToString(vote.id);
                const userName = valueToString(vote.user?.name);
                const userUid = valueToString(vote.uid || vote.user?.uid);
                const userMail = valueToString(vote.user?.mail);
                const userPhone = valueToString(vote.user?.call);
                const optionText = valueToString(vote.option?.option_text);

                return (
                  <tr
                    key={voteId || `${userUid}-${optionText}`}
                    className="bg-white/30 text-slate-700 transition hover:bg-teal-50/80 dark:bg-transparent dark:text-slate-200 dark:hover:bg-white/8"
                  >
                    <td className="px-4 py-3 align-middle">
                      <span className="font-bold text-slate-950 dark:text-white">
                        #{voteId || "unknown"}
                      </span>
                    </td>
                    <td className="px-4 py-3 align-middle">
                      <div className="flex items-center gap-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-teal-100 text-teal-700 dark:bg-cyan-300/12 dark:text-cyan-100">
                          <User2 className="size-4" />
                        </span>
                        <div className="min-w-0">
                          <span className="block truncate font-semibold text-slate-950 dark:text-white">
                            {userName || "Unknown user"}
                          </span>
                          <span className="mt-0.5 block truncate text-xs font-semibold text-slate-500 dark:text-slate-400">
                            {userMail || userPhone || "No contact info"}
                          </span>
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-3 align-middle">
                      <div className="space-y-1">
                        <span className="flex items-center gap-2 truncate">
                          <Mail className="size-3.5 text-slate-400" />
                          {userMail || "No email"}
                        </span>
                        <span className="flex items-center gap-2 truncate">
                          <Phone className="size-3.5 text-slate-400" />
                          {userPhone || "No phone"}
                        </span>
                      </div>
                    </td>
                    <td className="px-4 py-3 align-middle">
                      <span className="inline-flex items-center gap-2 rounded-md bg-white/70 px-3 py-1.5 font-semibold text-slate-950 dark:bg-white/8 dark:text-white">
                        <Vote className="size-3.5 text-teal-700 dark:text-cyan-200" />
                        {optionText || "Unknown option"}
                      </span>
                    </td>
                    <td className="px-4 py-3 align-middle">
                      <span className="block truncate font-mono text-xs">
                        {userUid || "Unknown UID"}
                      </span>
                    </td>
                    <td className="px-4 py-3 align-middle">
                      {formatDateTime(vote.created_at)}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>

      <div className="flex flex-col gap-3 rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/52 p-3 text-sm font-semibold text-slate-600 shadow-[0_10px_30px_rgba(15,23,42,0.06)] sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:bg-white/6 dark:text-slate-300">
        <span>
          Showing {from}-{to} of {total}
        </span>
        <div className="flex gap-2">
          <Link
            href={pageHref(pollId, Math.max(1, currentPage - 1))}
            className={cn(
              "inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/65 px-3 transition hover:bg-white dark:border-white/10 dark:bg-white/8 dark:hover:bg-white/12",
              !hasPrevious && "pointer-events-none opacity-45",
            )}
          >
            <ChevronLeft className="size-4" />
            Previous
          </Link>
          <Link
            href={pageHref(pollId, currentPage + 1)}
            className={cn(
              "inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/65 px-3 transition hover:bg-white dark:border-white/10 dark:bg-white/8 dark:hover:bg-white/12",
              !hasNext && "pointer-events-none opacity-45",
            )}
          >
            Next
            <ChevronRight className="size-4" />
          </Link>
        </div>
      </div>
    </div>
  );
}
