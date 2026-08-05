"use client";

import {
  useActionState,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import Link from "next/link";
import {
  ArrowLeft,
  BarChart3,
  CalendarClock,
  Check,
  ListPlus,
  Plus,
  Save,
  Settings2,
  Sparkles,
  Trash2,
  Vote,
  type LucideIcon,
} from "lucide-react";
import { toast } from "react-toastify";
import {
  syncPollOptionsAction,
  updatePollAction,
  type PollMutationState,
} from "@/app/(admin)/polls/results/actions";
import { useAdminSidebar } from "@/app/components/admin-sidebar-shell";
import { cn } from "@/lib/utils";
import type {
  PollItem,
  PollOptionItem,
} from "@/app/features/polls/services/poll-service";

type EditPollFormProps = {
  poll: PollItem;
  totalVotes: number;
};

type OptionRow = {
  id: string;
  optionId: string;
  optionText: string;
  votesCount: number;
};

const initialState: PollMutationState = {
  status: "idle",
  message: "",
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const textareaClassName =
  "mt-2 min-h-28 w-full resize-y rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const selectClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm font-semibold text-slate-950 [color-scheme:light] outline-none shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:[color-scheme:dark] dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";
const optionClassName =
  "bg-white text-slate-950 dark:bg-slate-950 dark:text-white";

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

function toDateTimeLocal(value: unknown) {
  const text = valueToString(value);
  if (!text) return "";

  const date = new Date(text);
  if (Number.isNaN(date.getTime())) return "";

  const offsetDate = new Date(
    date.getTime() - date.getTimezoneOffset() * 60000,
  );
  return offsetDate.toISOString().slice(0, 16);
}

function optionRowsFromPoll(options?: PollOptionItem[]): OptionRow[] {
  if (!Array.isArray(options) || options.length === 0) {
    return [
      { id: "new-1", optionId: "", optionText: "", votesCount: 0 },
      { id: "new-2", optionId: "", optionText: "", votesCount: 0 },
    ];
  }

  return options.map((option, index) => {
    const optionId = valueToString(option.id);

    return {
      id: optionId || `new-${index + 1}`,
      optionId,
      optionText: valueToString(option.option_text),
      votesCount: valueToNumber(option.votes_count),
    };
  });
}

function FormSection({
  title,
  eyebrow,
  icon: Icon,
  children,
}: {
  title: string;
  eyebrow: string;
  icon: LucideIcon;
  children: React.ReactNode;
}) {
  return (
    <section className="liquid-glass relative overflow-hidden rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
      <div className="mb-5 flex items-center gap-3">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 shadow-[inset_0_1px_0_rgba(255,255,255,0.85)] dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
          <Icon className="size-4" />
        </span>
        <div className="min-w-0">
          <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
            {eyebrow}
          </p>
          <h2 className="text-lg font-bold text-slate-950 dark:text-white">
            {title}
          </h2>
        </div>
      </div>
      {children}
    </section>
  );
}

export function EditPollForm({ poll, totalVotes }: EditPollFormProps) {
  const { isDesktopSidebarOpen } = useAdminSidebar();
  const pollId = valueToString(poll.id);
  const nextOptionIdRef = useRef(1);
  const settingsToastKeyRef = useRef("");
  const optionsToastKeyRef = useRef("");
  const existingOptionIds = useMemo(
    () =>
      (poll.options ?? [])
        .map((option) => valueToString(option.id))
        .filter(Boolean),
    [poll.options],
  );
  const [optionRows, setOptionRows] = useState<OptionRow[]>(() =>
    optionRowsFromPoll(poll.options),
  );
  const [settingsState, settingsAction, isSettingsPending] = useActionState(
    updatePollAction.bind(null, pollId),
    initialState,
  );
  const [optionsState, optionsAction, isOptionsPending] = useActionState(
    syncPollOptionsAction.bind(null, pollId),
    initialState,
  );

  useEffect(() => {
    if (settingsState.status === "idle") return;

    const toastKey =
      `${settingsState.status}-${settingsState.resetKey ?? settingsState.message}`;
    if (settingsToastKeyRef.current === toastKey) return;

    settingsToastKeyRef.current = toastKey;

    if (settingsState.status === "success") {
      toast.success(settingsState.message || "Poll updated.");
      return;
    }

    toast.error(settingsState.message || "Poll could not be updated.");
  }, [settingsState.message, settingsState.resetKey, settingsState.status]);

  useEffect(() => {
    if (optionsState.status === "idle") return;

    const toastKey =
      `${optionsState.status}-${optionsState.resetKey ?? optionsState.message}`;
    if (optionsToastKeyRef.current === toastKey) return;

    optionsToastKeyRef.current = toastKey;

    if (optionsState.status === "success") {
      toast.success(optionsState.message || "Poll options updated.");
      return;
    }

    toast.error(optionsState.message || "Poll options could not be updated.");
  }, [optionsState.message, optionsState.resetKey, optionsState.status]);

  const addOptionRow = () => {
    const id = `new-${Date.now()}-${nextOptionIdRef.current}`;
    nextOptionIdRef.current += 1;
    setOptionRows((current) => [
      ...current,
      { id, optionId: "", optionText: "", votesCount: 0 },
    ]);
  };

  const removeOptionRow = (id: string) => {
    setOptionRows((current) =>
      current.length <= 2 ? current : current.filter((row) => row.id !== id),
    );
  };

  const updateOptionRow = (id: string, value: string) => {
    setOptionRows((current) =>
      current.map((row) =>
        row.id === id ? { ...row, optionText: value } : row,
      ),
    );
  };

  return (
    <div className="space-y-4 pb-28">
      <Link
        href="/polls/results"
        className="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/58 px-4 text-sm font-bold text-slate-700 transition hover:bg-white hover:text-slate-950 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12 dark:hover:text-white"
      >
        <ArrowLeft className="size-4" />
        Back to polls
      </Link>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.38fr)]">
        <div className="space-y-4">
          <form action={settingsAction} className="space-y-4">
            {settingsState.status !== "idle" ? (
              <div
                className={cn(
                  "rounded-md border px-4 py-3 text-sm font-semibold",
                  settingsState.status === "success"
                    ? "border-teal-200 bg-teal-50/90 text-teal-800 dark:border-cyan-300/25 dark:bg-cyan-300/10 dark:text-cyan-100"
                    : "border-rose-200 bg-rose-50/90 text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100",
                )}
              >
                {settingsState.message}
              </div>
            ) : null}

            <FormSection title="Poll details" eyebrow="Question" icon={Vote}>
              <div className="grid gap-4">
                <label className="block min-w-0">
                  <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                    Question
                  </span>
                  <input
                    name="question"
                    type="text"
                    defaultValue={valueToString(poll.question)}
                    required
                    maxLength={255}
                    className={inputClassName}
                  />
                </label>

                <label className="block min-w-0">
                  <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                    Description
                  </span>
                  <textarea
                    name="description"
                    defaultValue={valueToString(poll.description)}
                    className={textareaClassName}
                  />
                </label>
              </div>
            </FormSection>

            <FormSection title="Publishing" eyebrow="Status" icon={Settings2}>
              <div className="grid gap-4 lg:grid-cols-2">
                <label className="block min-w-0">
                  <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                    Poll status
                  </span>
                  <select
                    name="status"
                    defaultValue={valueToString(poll.status) || "active"}
                    required
                    className={selectClassName}
                  >
                    <option value="active" className={optionClassName}>
                      Active
                    </option>
                    <option value="closed" className={optionClassName}>
                      Closed
                    </option>
                  </select>
                </label>

                <label className="block min-w-0">
                  <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                    Starts at
                  </span>
                  <input
                    name="starts_at"
                    type="datetime-local"
                    defaultValue={toDateTimeLocal(poll.starts_at)}
                    className={inputClassName}
                  />
                </label>

                <label className="block min-w-0 lg:col-span-2">
                  <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                    Ends at
                  </span>
                  <input
                    name="ends_at"
                    type="datetime-local"
                    defaultValue={toDateTimeLocal(poll.ends_at)}
                    className={inputClassName}
                  />
                </label>
              </div>

              <button
                type="submit"
                disabled={isSettingsPending}
                className="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-slate-950 px-5 py-3 text-sm font-bold text-white shadow-[0_14px_34px_rgba(15,23,42,0.2)] transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-65 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
              >
                {isSettingsPending ? (
                  <>
                    <span className="size-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                    Saving
                  </>
                ) : (
                  <>
                    <Save className="size-4" />
                    Save details
                  </>
                )}
              </button>
            </FormSection>
          </form>

          <form action={optionsAction} className="space-y-4">
            {existingOptionIds.map((optionId) => (
              <input
                key={optionId}
                type="hidden"
                name="existing_option_id"
                value={optionId}
              />
            ))}

            {optionsState.status !== "idle" ? (
              <div
                className={cn(
                  "rounded-md border px-4 py-3 text-sm font-semibold",
                  optionsState.status === "success"
                    ? "border-teal-200 bg-teal-50/90 text-teal-800 dark:border-cyan-300/25 dark:bg-cyan-300/10 dark:text-cyan-100"
                    : "border-rose-200 bg-rose-50/90 text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100",
                )}
              >
                {optionsState.message}
              </div>
            ) : null}

            <FormSection title="Options" eyebrow="Answers" icon={ListPlus}>
              <div className="space-y-3">
                {optionRows.map((row, index) => (
                  <div
                    key={row.id}
                    className="grid gap-3 rounded-md border border-[rgba(15,23,42,0.12)] bg-white/42 p-3 shadow-[0_1px_2px_rgba(15,23,42,0.03)] md:grid-cols-[minmax(0,1fr)_110px_auto] dark:border-white/10 dark:bg-white/6"
                  >
                    <input type="hidden" name="option_id" value={row.optionId} />
                    <label className="block min-w-0">
                      <span className="text-xs font-bold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">
                        Option {index + 1}
                      </span>
                      <input
                        name="option_text"
                        type="text"
                        value={row.optionText}
                        onChange={(event) =>
                          updateOptionRow(row.id, event.target.value)
                        }
                        maxLength={255}
                        className={inputClassName}
                      />
                    </label>
                    <div className="rounded-md bg-white/60 px-3 py-3 text-center text-sm font-bold text-slate-700 dark:bg-white/8 dark:text-slate-200">
                      {row.votesCount} votes
                    </div>
                    <div className="flex items-end">
                      <button
                        type="button"
                        onClick={() => removeOptionRow(row.id)}
                        disabled={optionRows.length <= 2}
                        className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/55 px-3 text-sm font-bold text-slate-600 transition hover:bg-white/80 hover:text-rose-700 disabled:cursor-not-allowed disabled:opacity-40 md:w-11 dark:border-white/10 dark:bg-white/8 dark:text-slate-300 dark:hover:bg-white/12 dark:hover:text-rose-200"
                      >
                        <Trash2 className="size-4" />
                        <span className="md:hidden">Remove</span>
                      </button>
                    </div>
                  </div>
                ))}
              </div>

              <div className="mt-4 flex flex-col gap-3 sm:flex-row">
                <button
                  type="button"
                  onClick={addOptionRow}
                  className="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/55 px-4 text-sm font-bold text-slate-700 transition hover:-translate-y-0.5 hover:bg-white/80 hover:text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12 dark:hover:text-cyan-200"
                >
                  <Plus className="size-4" />
                  Add option
                </button>

                <button
                  type="submit"
                  disabled={isOptionsPending}
                  className="inline-flex min-h-10 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-65 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                >
                  {isOptionsPending ? (
                    <>
                      <span className="size-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                      Saving
                    </>
                  ) : (
                    <>
                      <Save className="size-4" />
                      Save options
                    </>
                  )}
                </button>
              </div>
            </FormSection>
          </form>
        </div>

        <aside className="space-y-4">
          <FormSection title="Results" eyebrow="Live tally" icon={BarChart3}>
            <div className="rounded-md bg-white/42 p-4 dark:bg-white/6">
              <p className="text-3xl font-black tracking-tight text-slate-950 dark:text-white">
                {totalVotes}
              </p>
              <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
                Total votes recorded for this poll.
              </p>
            </div>

            <div className="mt-4 space-y-3">
                {optionRows.map((row) => {
                const percentage =
                  totalVotes > 0
                    ? Math.round((row.votesCount / totalVotes) * 100)
                    : 0;

                return (
                  <div key={`${row.id}-summary`} className="space-y-2">
                    <div className="flex items-center justify-between gap-3 text-sm">
                      <span className="truncate font-semibold text-slate-700 dark:text-slate-200">
                        {row.optionText || "Untitled option"}
                      </span>
                      <span className="shrink-0 font-bold text-slate-950 dark:text-white">
                        {row.votesCount}
                      </span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
                      <div
                        className="h-full rounded-full bg-teal-500 dark:bg-cyan-300"
                        style={{ width: `${percentage}%` }}
                      />
                    </div>
                  </div>
                );
              })}
            </div>
          </FormSection>

          <FormSection title="Window" eyebrow="Schedule" icon={CalendarClock}>
            <div className="space-y-3 text-sm text-slate-700 dark:text-slate-200">
              <div className="rounded-md bg-white/42 px-3 py-2 dark:bg-white/6">
                Starts: {valueToString(poll.starts_at) || "Immediately"}
              </div>
              <div className="rounded-md bg-white/42 px-3 py-2 dark:bg-white/6">
                Ends: {valueToString(poll.ends_at) || "No end date"}
              </div>
            </div>
          </FormSection>
        </aside>
      </div>

      <div
        className={cn(
          "fixed inset-x-3 bottom-3 z-50 flex flex-col gap-3 rounded-lg border border-white/58 bg-white/78 p-3 shadow-[0_20px_50px_rgba(15,23,42,0.18)] backdrop-blur-xl transition-[left] duration-300 ease-out sm:flex-row sm:items-center sm:justify-between lg:right-3 dark:border-white/10 dark:bg-slate-950/72 dark:shadow-[0_20px_50px_rgba(2,6,23,0.5)]",
          isDesktopSidebarOpen ? "md:left-[18.75rem]" : "md:left-[4.25rem]",
        )}
      >
        <div className="flex items-center gap-3 text-sm font-semibold text-slate-700 dark:text-slate-200">
          <span className="flex size-9 items-center justify-center rounded-md bg-teal-100 text-teal-700 dark:bg-cyan-300/12 dark:text-cyan-200">
            {settingsState.status === "success" ||
            optionsState.status === "success" ? (
              <Check className="size-4" />
            ) : (
              <Sparkles className="size-4" />
            )}
          </span>
          <span>
            Details and options save independently so you can update either part
            quickly.
          </span>
        </div>
      </div>
    </div>
  );
}
