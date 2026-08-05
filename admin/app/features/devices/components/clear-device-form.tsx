"use client";

import { useActionState, useEffect, useMemo, useRef } from "react";
import { Eraser, ScanSearch, ShieldAlert, Trash2 } from "lucide-react";
import { toast } from "react-toastify";
import {
  clearDeviceAction,
  type ClearDeviceFormState,
} from "@/app/(admin)/devices/clear/actions";
import { cn } from "@/lib/utils";
import { PasteUIDButton } from "@/app/features/users/components/uid-clipboard";

type ClearDeviceFormProps = {
  initialUserId?: string;
};

const initialState: ClearDeviceFormState = {
  status: "idle",
  message: "",
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const selectClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm font-semibold text-slate-950 [color-scheme:light] outline-none shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:[color-scheme:dark] dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";
const optionClassName =
  "bg-white text-slate-950 dark:bg-slate-950 dark:text-white";

function statusText(state: ClearDeviceFormState) {
  if (state.status === "success") {
    const count =
      typeof state.deletedCount === "number"
        ? ` Deleted: ${state.deletedCount}.`
        : "";
    return `${state.message}${count}`;
  }

  return state.message;
}

export function ClearDeviceForm({ initialUserId = "" }: ClearDeviceFormProps) {
  const formRef = useRef<HTMLFormElement>(null);
  const userIdInputRef = useRef<HTMLInputElement>(null);
  const lastToastKeyRef = useRef("");
  const [state, formAction, isPending] = useActionState(
    clearDeviceAction,
    initialState,
  );
  const message = useMemo(
    () => statusText(state),
    [state.deletedCount, state.message, state.status],
  );

  useEffect(() => {
    if (state.status === "success") {
      formRef.current?.reset();
    }
  }, [state.resetKey, state.status]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(message || "Device records cleared.");
      return;
    }

    toast.error(message || "Device records could not be cleared.");
  }, [message, state.message, state.resetKey, state.status]);

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

      <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
        <div className="mb-5 flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-amber-700 dark:border-white/10 dark:bg-white/8 dark:text-amber-200">
            <Eraser className="size-4" />
          </span>
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-amber-700 dark:text-amber-200">
              Device cleanup
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Clear device records
            </h2>
          </div>
        </div>

        <div className="mb-5 rounded-md border border-amber-200/80 bg-amber-50/90 px-4 py-3 text-sm text-amber-900 dark:border-amber-300/20 dark:bg-amber-300/10 dark:text-amber-100">
          <div className="flex items-start gap-3">
            <ShieldAlert className="mt-0.5 size-4 shrink-0" />
            <p>
              This action removes matching device rows from the backend. Use
              only the filters you want to target.
            </p>
          </div>
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <label className="block min-w-0 lg:col-span-2">
            <div className="flex items-center justify-between">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                User ID
              </span>
              <PasteUIDButton
                onPaste={(uid) => {
                  if (userIdInputRef.current)
                    userIdInputRef.current.value = uid;
                }}
              />
            </div>
            <input
              ref={userIdInputRef}
              name="user_id"
              type="text"
              required
              placeholder="Enter UID"
              defaultValue={initialUserId}
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Device type
            </span>
            <select
              name="device_type"
              defaultValue=""
              className={selectClassName}
            >
              <option value="" className={optionClassName}>
                All device types
              </option>
              <option value="mobile" className={optionClassName}>
                Mobile
              </option>
              <option value="tv" className={optionClassName}>
                TV
              </option>
              <option value="browser" className={optionClassName}>
                Browser
              </option>
            </select>
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Device token
            </span>
            <input
              name="device_token"
              type="text"
              placeholder="Optional exact device token"
              className={inputClassName}
            />
          </label>
        </div>
      </section>

      <section className="grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
        <div className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
          <div className="mb-4 flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
              <ScanSearch className="size-4" />
            </span>
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
                Filter logic
              </p>
              <h2 className="text-lg font-bold text-slate-950 dark:text-white">
                How this clear works
              </h2>
            </div>
          </div>

          <div className="space-y-3 text-sm leading-6 text-slate-600 dark:text-slate-300">
            <p>`user_id` is always required and defines the base match.</p>
            <p>
              Add `device_type` to clear only one platform, or add
              `device_token` to target a single device record.
            </p>
            <p>
              Leaving the optional fields empty clears all device rows for that
              user.
            </p>
          </div>
        </div>

        <div className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
          <div className="mb-4 flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-rose-700 dark:border-white/10 dark:bg-white/8 dark:text-rose-200">
              <Trash2 className="size-4" />
            </span>
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.18em] text-rose-700 dark:text-rose-200">
                Submit
              </p>
              <h2 className="text-lg font-bold text-slate-950 dark:text-white">
                Run clear
              </h2>
            </div>
          </div>

          <button
            type="submit"
            disabled={isPending}
            className="inline-flex min-h-11 w-full items-center justify-center rounded-md bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
          >
            {isPending ? "Clearing device records..." : "Clear devices"}
          </button>
        </div>
      </section>
    </form>
  );
}
