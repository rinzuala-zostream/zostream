import { cookies } from "next/headers";
import { notFound } from "next/navigation";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { EditPollForm } from "@/app/features/polls/components/edit-poll-form";
import { pollService } from "@/app/features/polls/services/poll-service";
import type { PollItem } from "@/app/features/polls/services/poll-service";

export const dynamic = "force-dynamic";

type EditPollPageProps = {
  params: Promise<{
    id: string;
  }>;
};

export default async function EditPollPage({ params }: EditPollPageProps) {
  const [{ id }, cookieStore] = await Promise.all([params, cookies()]);
  const savedMode = cookieStore.get("theme-mode")?.value;
  const initialMode = savedMode === "dark" ? "dark" : "light";

  let poll: PollItem;
  let totalVotes = 0;

  try {
    const [response, resultsResponse] = await Promise.all([
      pollService.getById(id),
      pollService.results(id).catch(() => null),
    ]);

    if (!response.status || !response.data?.id) {
      notFound();
    }

    poll = response.data;

    if (resultsResponse?.status && resultsResponse.data?.poll) {
      poll = {
        ...poll,
        options: resultsResponse.data.poll.options ?? poll.options,
        votes_count: resultsResponse.data.poll.votes_count ?? poll.votes_count,
      };
      totalVotes = resultsResponse.data.total_votes ?? 0;
    } else {
      totalVotes =
        typeof poll.votes_count === "number"
          ? poll.votes_count
          : Number(poll.votes_count ?? 0) || 0;
    }
  } catch {
    notFound();
  }

  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 lg:py-1 dark:text-white">
      <div className="flex w-full flex-1 flex-col lg:min-h-0">
        <AdminPageHeader title="Edit poll" initialMode={initialMode} />

        <div className="admin-sidebar-scroll mt-3 flex-1 pb-4 lg:min-h-0">
          <section className="relative mb-4 overflow-hidden rounded-lg border border-white/58 bg-[linear-gradient(135deg,rgba(255,255,255,0.82),rgba(236,253,245,0.5)_46%,rgba(224,242,254,0.52))] p-5 backdrop-blur-xl sm:p-6 dark:border-white/10 dark:bg-[linear-gradient(135deg,rgba(15,23,42,0.82),rgba(20,83,45,0.2)_46%,rgba(8,47,73,0.28))] dark:shadow-[0_18px_60px_rgba(2,6,23,0.46)]">
            <div className="max-w-3xl">
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-teal-700 dark:text-cyan-200">
                Poll management
              </p>
              <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl dark:text-white">
                Update {poll.question ?? "this poll"}.
              </h2>
              <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
                Edit the question and choices while keeping the current results
                in view.
              </p>
            </div>
          </section>

          <EditPollForm poll={poll} totalVotes={totalVotes} />
        </div>
      </div>
    </main>
  );
}
