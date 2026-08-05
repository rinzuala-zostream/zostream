"use client";

import {
  useActionState,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import {
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
  createPollAction,
  type CreatePollFormState,
} from "@/app/(admin)/polls/create/actions";
import { useAdminSidebar } from "@/app/components/admin-sidebar-shell";
import { cn } from "@/lib/utils";

const initialState: CreatePollFormState = {
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

type OptionRow = {
  id: number;
  optionText: string;
};

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

function statusText(state: CreatePollFormState) {
  if (state.status === "success" && state.pollId) {
    return `${state.message} Poll ID: ${state.pollId}`;
  }

  return state.message;
}

export function CreatePollForm() {
  const { isDesktopSidebarOpen } = useAdminSidebar();
  const formRef = useRef<HTMLFormElement>(null);
  const lastToastKeyRef = useRef("");
  const nextOptionIdRef = useRef(4);
  const [optionRows, setOptionRows] = useState<OptionRow[]>([
    { id: 1, optionText: "" },
    { id: 2, optionText: "" },
    { id: 3, optionText: "" },
  ]);
  const [state, formAction, isPending] = useActionState(
    createPollAction,
    initialState,
  );
  const message = useMemo(
    () => statusText(state),
    [state.message, state.pollId, state.status],
  );

  useEffect(() => {
    if (state.status === "success") {
      formRef.current?.reset();
      window.setTimeout(() => {
        nextOptionIdRef.current = 4;
        setOptionRows([
          { id: 1, optionText: "" },
          { id: 2, optionText: "" },
          { id: 3, optionText: "" },
        ]);
      }, 0);
    }
  }, [state.resetKey, state.status]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(message || "Poll saved successfully.");
      return;
    }

    toast.error(message || "Poll could not be saved.");
  }, [message, state.message, state.resetKey, state.status]);

  const addOptionRow = () => {
    const id = nextOptionIdRef.current;
    nextOptionIdRef.current += 1;
    setOptionRows((current) => [...current, { id, optionText: "" }]);
  };

  const removeOptionRow = (id: number) => {
    setOptionRows((current) =>
      current.length <= 2 ? current : current.filter((row) => row.id !== id),
    );
  };

  const updateOptionRow = (id: number, value: string) => {
    setOptionRows((current) =>
      current.map((row) =>
        row.id === id ? { ...row, optionText: value } : row,
      ),
    );
  };

  return (
    <form ref={formRef} action={formAction} className="space-y-4 pb-28">
      {state.status !== "idle" ? (
        <div
          className={cn(
            "rounded-md border px-4 py-3 text-sm font-semibold",
            state.status === "success"
              ? "border-teal-200 bg-teal-50/90 text-teal-800 dark:border-cyan-300/25 dark:bg-cyan-300/10 dark:text-cyan-100"
              : "border-rose-200 bg-rose-50/90 text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100",
          )}
        >
          {message}
        </div>
      ) : null}

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.38fr)]">
        <div className="space-y-4">
          <FormSection title="Poll details" eyebrow="Question" icon={Vote}>
            <div className="grid gap-4">
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Question
                </span>
                <input
                  name="question"
                  type="text"
                  placeholder="Which show should premiere next?"
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
                  placeholder="Add context for voters."
                  className={textareaClassName}
                />
              </label>
            </div>
          </FormSection>

          <FormSection title="Options" eyebrow="Choices" icon={ListPlus}>
            <div className="space-y-3">
              {optionRows.map((row, index) => (
                <div
                  key={row.id}
                  className="grid gap-3 rounded-md border border-[rgba(15,23,42,0.12)] bg-white/42 p-3 shadow-[0_1px_2px_rgba(15,23,42,0.03)] md:grid-cols-[minmax(0,1fr)_auto] dark:border-white/10 dark:bg-white/6"
                >
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
                      placeholder="Type an answer"
                      maxLength={255}
                      className={inputClassName}
                    />
                  </label>
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

            <button
              type="button"
              onClick={addOptionRow}
              className="mt-4 inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/55 px-4 text-sm font-bold text-slate-700 transition hover:-translate-y-0.5 hover:bg-white/80 hover:text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12 dark:hover:text-cyan-200"
            >
              <Plus className="size-4" />
              Add option
            </button>
          </FormSection>
        </div>

        <aside className="space-y-4">
          <FormSection title="Publishing" eyebrow="Status" icon={Settings2}>
            <div className="grid gap-4">
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Poll status
                </span>
                <select
                  name="status"
                  defaultValue="active"
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

              <div className="rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 p-3 text-sm leading-6 text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] dark:border-white/10 dark:bg-white/6 dark:text-slate-200">
                Active polls can receive votes when the schedule allows it.
                Closed polls stay visible for results and review.
              </div>
            </div>
          </FormSection>

          <FormSection
            title="Schedule"
            eyebrow="Availability"
            icon={CalendarClock}
          >
            <div className="grid gap-4">
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Starts at
                </span>
                <input
                  name="starts_at"
                  type="datetime-local"
                  className={inputClassName}
                />
              </label>

              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Ends at
                </span>
                <input
                  name="ends_at"
                  type="datetime-local"
                  className={inputClassName}
                />
              </label>

              <div className="flex items-start gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 p-3 text-sm leading-6 text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] dark:border-white/10 dark:bg-white/6 dark:text-slate-200">
                <CalendarClock className="mt-0.5 size-4 shrink-0 text-teal-700 dark:text-cyan-200" />
                Leave both empty to make the poll available immediately.
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
            {state.status === "success" ? (
              <Check className="size-4" />
            ) : (
              <Sparkles className="size-4" />
            )}
          </span>
          <span>Polls need a question and at least 2 options.</span>
        </div>
        <button
          type="submit"
          disabled={isPending}
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-slate-950 px-5 py-3 text-sm font-bold text-white shadow-[0_14px_34px_rgba(15,23,42,0.2)] transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-65 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
        >
          {isPending ? (
            <>
              <span className="size-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
              Saving
            </>
          ) : (
            <>
              <Save className="size-4" />
              Save poll
            </>
          )}
        </button>
      </div>
    </form>
  );
}
