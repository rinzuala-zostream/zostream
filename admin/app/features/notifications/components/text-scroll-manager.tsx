"use client";

import { useActionState, useEffect, useRef, useState, useTransition } from "react";
import { Edit3, Plus, RotateCcw, Save, Trash2 } from "lucide-react";
import { useRouter } from "next/navigation";
import { toast } from "react-toastify";
import {
  addTextScrollAction,
  deleteTextScrollAction,
  type TextScrollFormState,
  updateTextScrollAction,
} from "@/app/(admin)/notifications/scrolling-text/add/actions";
import type { TextScrollItem } from "@/app/features/notifications/services/text-scroll-service";
import { cn } from "@/lib/utils";

type TextScrollManagerProps = {
  items: TextScrollItem[];
};

const initialState: TextScrollFormState = {
  status: "idle",
  message: "",
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";

function StatusMessage({ state }: { state: TextScrollFormState }) {
  if (state.status === "idle") return null;

  return (
    <div
      className={cn(
        "rounded-md border px-4 py-3 text-sm font-semibold",
        state.status === "success"
          ? "border-teal-200 bg-teal-50/90 text-teal-800 dark:border-cyan-300/25 dark:bg-cyan-300/10 dark:text-cyan-100"
          : "border-rose-200 bg-rose-50/90 text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100",
      )}
    >
      {state.message}
    </div>
  );
}

function ToggleField({
  name = "show",
  defaultChecked,
  label,
}: {
  name?: string;
  defaultChecked?: boolean;
  label: string;
}) {
  return (
    <label className="group flex min-h-12 items-center justify-between gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/64 dark:border-white/10 dark:bg-white/6 dark:text-slate-200 dark:hover:bg-white/10">
      <span>{label}</span>
      <input
        type="checkbox"
        name={name}
        defaultChecked={defaultChecked}
        className="peer sr-only"
      />
      <span className="relative h-6 w-11 shrink-0 rounded-full bg-slate-300/80 after:absolute after:left-1 after:top-1 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition after:content-[''] peer-checked:bg-teal-500 peer-checked:after:translate-x-5 dark:bg-slate-700 dark:peer-checked:bg-cyan-400" />
    </label>
  );
}

export function TextScrollManager({ items }: TextScrollManagerProps) {
  const router = useRouter();
  const formRef = useRef<HTMLFormElement>(null);
  const lastToastKeyRef = useRef("");
  const [state, formAction, isPending] = useActionState(
    addTextScrollAction,
    initialState,
  );
  const [editingId, setEditingId] = useState("");
  const [itemActionState, setItemActionState] =
    useState<TextScrollFormState>(initialState);
  const [isItemActionPending, startItemActionTransition] = useTransition();

  useEffect(() => {
    if (state.status === "success") {
      formRef.current?.reset();
      router.refresh();
    }
  }, [router, state.status, state.resetKey]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(state.message || "Scrolling text added.");
      return;
    }

    toast.error(state.message || "Scrolling text could not be added.");
  }, [state.message, state.resetKey, state.status]);

  const runItemAction = (
    action: (formData: FormData) => Promise<TextScrollFormState>,
    formData: FormData,
  ) => {
    startItemActionTransition(async () => {
      const result = await action(formData);
      setItemActionState(result);

      if (result.status === "success") {
        toast.success(result.message);
        setEditingId("");
        router.refresh();
        return;
      }

      toast.error(result.message);
    });
  };

  return (
    <div className="space-y-5 pb-28">
      <form
        ref={formRef}
        action={formAction}
        className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]"
      >
        <div className="mb-5 flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
            <Plus className="size-4" />
          </span>
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              Realtime Database
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Add scrolling text
            </h2>
          </div>
        </div>

        <div className="space-y-4">
          <StatusMessage state={state} />

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Text
            </span>
            <textarea
              name="text"
              rows={3}
              required
              placeholder="Enter text to show in the scrolling ticker"
              className={cn(inputClassName, "resize-y")}
            />
          </label>

          <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-end">
            <ToggleField label="Show scrolling text" defaultChecked />

            <button
              type="reset"
              className="inline-flex min-h-12 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/58 px-4 text-sm font-bold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:-translate-y-0.5 hover:border-[rgba(15,23,42,0.22)] hover:bg-white/80 hover:text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12 dark:hover:text-cyan-200"
            >
              <RotateCcw className="size-4" />
              Reset
            </button>

            <button
              type="submit"
              disabled={isPending}
              className="inline-flex min-h-12 items-center justify-center gap-2 rounded-md bg-slate-950 px-5 text-sm font-bold text-white shadow-[0_14px_34px_rgba(15,23,42,0.22)] transition hover:-translate-y-0.5 hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white dark:text-slate-950 dark:hover:bg-cyan-100"
            >
              <Save className="size-4" />
              {isPending ? "Saving..." : "Save"}
            </button>
          </div>
        </div>
      </form>

      <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
        <div className="mb-5 flex items-center justify-between gap-3">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              List view
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Existing scrolling texts
            </h2>
          </div>
          <span className="rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 px-3 py-1.5 text-xs font-bold text-slate-600 dark:border-white/10 dark:bg-white/8 dark:text-slate-300">
            {items.length} total
          </span>
        </div>

        <StatusMessage state={itemActionState} />

        {items.length === 0 ? (
          <div className="mt-4 rounded-md border border-dashed border-[rgba(15,23,42,0.18)] bg-white/38 px-4 py-8 text-center text-sm font-semibold text-slate-500 dark:border-white/12 dark:bg-white/6 dark:text-slate-400">
            No scrolling text added yet.
          </div>
        ) : (
          <div className="mt-4 grid gap-3">
            {items.map((item) => {
              const isEditing = editingId === item.id;

              return (
                <form
                  key={item.id}
                  action={(formData) =>
                    runItemAction(updateTextScrollAction, formData)
                  }
                  className="rounded-md border border-[rgba(15,23,42,0.12)] bg-white/58 p-4 shadow-[0_10px_28px_rgba(15,23,42,0.08)] dark:border-white/10 dark:bg-white/7"
                >
                  <input type="hidden" name="id" value={item.id} />

                  <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_13rem]">
                    <label className="block min-w-0">
                      <span className="text-xs font-bold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">
                        Text
                      </span>
                      {isEditing ? (
                        <textarea
                          name="text"
                          rows={3}
                          required
                          defaultValue={item.text}
                          className={cn(inputClassName, "resize-y")}
                        />
                      ) : (
                        <>
                          <input type="hidden" name="text" value={item.text} />
                          <p className="mt-2 rounded-md bg-white/46 px-3 py-3 text-sm font-semibold leading-6 text-slate-800 dark:bg-slate-950/30 dark:text-slate-100">
                            {item.text}
                          </p>
                        </>
                      )}
                    </label>

                    <div className="space-y-3">
                      {isEditing ? (
                        <ToggleField
                          label="Show"
                          defaultChecked={item.show}
                        />
                      ) : (
                        <>
                          <input
                            type="hidden"
                            name="show"
                            value={item.show ? "on" : ""}
                          />
                          <div className="flex min-h-12 items-center justify-between gap-3 rounded-md border border-[rgba(15,23,42,0.12)] bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 dark:border-white/10 dark:bg-white/6 dark:text-slate-200">
                            <span>Status</span>
                            <span
                              className={cn(
                                "rounded-md px-2.5 py-1 text-xs font-bold",
                                item.show
                                  ? "bg-teal-100 text-teal-800 dark:bg-cyan-300/15 dark:text-cyan-100"
                                  : "bg-slate-200 text-slate-700 dark:bg-white/10 dark:text-slate-300",
                              )}
                            >
                              {item.show ? "Shown" : "Hidden"}
                            </span>
                          </div>
                        </>
                      )}

                      <div className="grid grid-cols-2 gap-2">
                        {isEditing ? (
                          <>
                            <button
                              type="button"
                              onClick={() => setEditingId("")}
                              className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/58 px-3 text-sm font-bold text-slate-700 transition hover:bg-white/80 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12"
                            >
                              Cancel
                            </button>
                            <button
                              type="submit"
                              disabled={isItemActionPending}
                              className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-slate-950 px-3 text-sm font-bold text-white transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white dark:text-slate-950 dark:hover:bg-cyan-100"
                            >
                              <Save className="size-4" />
                              Update
                            </button>
                          </>
                        ) : (
                          <>
                            <button
                              type="button"
                              onClick={() => setEditingId(item.id)}
                              className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/58 px-3 text-sm font-bold text-slate-700 transition hover:bg-white/80 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12"
                            >
                              <Edit3 className="size-4" />
                              Edit
                            </button>
                            <button
                              type="button"
                              disabled={isItemActionPending}
                              onClick={() => {
                                if (
                                  !window.confirm(
                                    "Delete this scrolling text?",
                                  )
                                ) {
                                  return;
                                }

                                const formData = new FormData();
                                formData.set("id", item.id);
                                runItemAction(deleteTextScrollAction, formData);
                              }}
                              className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-rose-600 px-3 text-sm font-bold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                              <Trash2 className="size-4" />
                              Delete
                            </button>
                          </>
                        )}
                      </div>
                    </div>
                  </div>
                </form>
              );
            })}
          </div>
        )}
      </section>
    </div>
  );
}
