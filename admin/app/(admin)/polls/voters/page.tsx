import { cookies } from "next/headers";
import Link from "next/link";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { PollVoterList } from "@/app/features/polls/components/poll-voter-list";
import {
  pollService,
  type PollItem,
  type PollVoteItem,
  type PollVotersResponse,
} from "@/app/features/polls/services/poll-service";

export const dynamic = "force-dynamic";

type PollVotersPageProps = {
  searchParams?: Promise<{
    poll_id?: string;
    page?: string;
  }>;
};

function positiveNumber(value: string | undefined, fallback: number) {
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback;
}

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

export default async function PollVotersPage({
  searchParams,
}: PollVotersPageProps) {
  const [cookieStore, resolvedSearchParams] = await Promise.all([
    cookies(),
    searchParams,
  ]);
  const savedMode = cookieStore.get("theme-mode")?.value;
  const initialMode = savedMode === "dark" ? "dark" : "light";
  const page = positiveNumber(resolvedSearchParams?.page, 1);

  let polls: PollItem[] = [];
  let selectedPoll: PollItem | undefined;
  let selectedPollId = resolvedSearchParams?.poll_id?.trim() ?? "";
  let votes: PollVoteItem[] = [];
  let pagination: PollVotersResponse["data"] | undefined;
  let errorMessage = "";

  try {
    const pollResponse = await pollService.list({ limit: 100 });
    polls = pollResponse.data?.data ?? [];

    if (!pollResponse.status) {
      errorMessage =
        pollResponse.message ??
        pollResponse.error ??
        "Polls could not be loaded.";
    }

    if (!selectedPollId && polls.length > 0) {
      selectedPollId = valueToString(polls[0]?.id);
    }

    selectedPoll = polls.find(
      (poll) => valueToString(poll.id) === selectedPollId,
    );

    if (selectedPollId) {
      const [pollDetailResponse, votersResponse] = await Promise.all([
        selectedPoll ? Promise.resolve(null) : pollService.getById(selectedPollId),
        pollService.voters(selectedPollId, page),
      ]);

      if (
        !selectedPoll &&
        pollDetailResponse?.status &&
        pollDetailResponse.data
      ) {
        selectedPoll = pollDetailResponse.data;
      }

      if (!votersResponse.status) {
        errorMessage =
          votersResponse.message ??
          votersResponse.error ??
          "Voters could not be loaded.";
      } else {
        votes = votersResponse.data?.data ?? [];
        pagination = votersResponse.data;
      }
    }
  } catch (error) {
    errorMessage =
      error instanceof Error ? error.message : "Voters could not be loaded.";
  }

  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 lg:py-1 dark:text-white">
      <div className="flex w-full flex-1 flex-col lg:min-h-0">
        <AdminPageHeader title="Voter list" initialMode={initialMode} />

        <div className="admin-sidebar-scroll mt-3 flex-1 pb-4 lg:min-h-0">
          <section className="relative mb-4 overflow-hidden rounded-lg border border-white/58 bg-[linear-gradient(135deg,rgba(255,255,255,0.82),rgba(236,253,245,0.5)_46%,rgba(224,242,254,0.52))] p-5 backdrop-blur-xl sm:p-6 dark:border-white/10 dark:bg-[linear-gradient(135deg,rgba(15,23,42,0.82),rgba(20,83,45,0.2)_46%,rgba(8,47,73,0.28))] dark:shadow-[0_18px_60px_rgba(2,6,23,0.46)]">
            <div className="max-w-3xl">
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-teal-700 dark:text-cyan-200">
                Poll voters
              </p>
              <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl dark:text-white">
                Review exactly who voted.
              </h2>
              <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
                Choose a poll to inspect voter identities, contact details, the
                selected answer, and the vote timestamp.
              </p>
            </div>
          </section>

          {errorMessage ? (
            <div className="mb-4 rounded-md border border-rose-200 bg-rose-50/90 px-4 py-3 text-sm font-semibold text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100">
              {errorMessage}
            </div>
          ) : null}

          <form
            action="/polls/voters"
            className="mb-4 grid gap-3 rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/52 p-3 shadow-[0_10px_30px_rgba(15,23,42,0.06)] md:grid-cols-[minmax(0,1fr)_auto] dark:border-white/10 dark:bg-white/6"
          >
            <label className="block min-w-0">
              <span className="sr-only">Select poll</span>
              <select
                name="poll_id"
                defaultValue={selectedPollId}
                className="min-h-11 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/70 px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-teal-300 focus:bg-white focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:bg-slate-950 dark:focus:ring-cyan-300/15"
              >
                {polls.map((poll) => {
                  const pollId = valueToString(poll.id);
                  return (
                    <option key={pollId} value={pollId}>
                      {poll.question || `Poll #${pollId}`}
                    </option>
                  );
                })}
              </select>
            </label>
            <button
              type="submit"
              className="inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
            >
              Load voters
            </button>
          </form>

          {!selectedPoll ? (
            <section className="rounded-lg border border-dashed border-[rgba(15,23,42,0.18)] bg-white/52 p-8 text-center shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/12 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.36)]">
              <h2 className="text-xl font-bold text-slate-950 dark:text-white">
                No poll selected
              </h2>
              <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-600 dark:text-slate-300">
                Create a poll first, or select one from the dropdown to inspect
                its voters.
              </p>
              <Link
                href="/polls/create"
                className="mt-5 inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
              >
                Create poll
              </Link>
            </section>
          ) : (
            <>
              <section className="mb-4 grid gap-3 md:grid-cols-3">
                <div className="rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/60 p-4 shadow-[0_10px_30px_rgba(15,23,42,0.06)] dark:border-white/10 dark:bg-white/6">
                  <p className="text-xs font-bold uppercase tracking-[0.16em] text-teal-700 dark:text-cyan-200">
                    Selected poll
                  </p>
                  <h3 className="mt-2 text-lg font-bold text-slate-950 dark:text-white">
                    {selectedPoll.question || `Poll #${selectedPollId}`}
                  </h3>
                </div>
                <div className="rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/60 p-4 shadow-[0_10px_30px_rgba(15,23,42,0.06)] dark:border-white/10 dark:bg-white/6">
                  <p className="text-xs font-bold uppercase tracking-[0.16em] text-teal-700 dark:text-cyan-200">
                    Status
                  </p>
                  <p className="mt-2 text-lg font-bold capitalize text-slate-950 dark:text-white">
                    {selectedPoll.status || "Unknown"}
                  </p>
                </div>
                <div className="rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/60 p-4 shadow-[0_10px_30px_rgba(15,23,42,0.06)] dark:border-white/10 dark:bg-white/6">
                  <p className="text-xs font-bold uppercase tracking-[0.16em] text-teal-700 dark:text-cyan-200">
                    Total votes
                  </p>
                  <p className="mt-2 text-lg font-bold text-slate-950 dark:text-white">
                    {pagination?.total ?? votes.length}
                  </p>
                </div>
              </section>

              <PollVoterList
                poll={selectedPoll}
                votes={votes}
                pagination={pagination}
                page={page}
              />
            </>
          )}
        </div>
      </div>
    </main>
  );
}
