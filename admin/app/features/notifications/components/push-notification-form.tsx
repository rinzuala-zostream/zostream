"use client";

import { useActionState, useEffect, useRef, useState } from "react";
import {
  Bell,
  Plus,
  RotateCcw,
  Save,
  Send,
  Trash2,
} from "lucide-react";
import { toast } from "react-toastify";
import {
  sendPushNotificationAction,
  type PushNotificationFormState,
} from "@/app/(admin)/notifications/create/actions";
import { cn } from "@/lib/utils";

type DataPair = {
  id: number;
  key: string;
  value: string;
};

const initialState: PushNotificationFormState = {
  status: "idle",
  message: "",
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const selectClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm font-semibold text-slate-950 [color-scheme:light] outline-none shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:[color-scheme:dark] dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";
const optionClassName =
  "bg-white text-slate-950 dark:bg-slate-950 dark:text-white";

function StatusMessage({ state }: { state: PushNotificationFormState }) {
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
      {state.responseStatus ? ` Status: ${state.responseStatus}` : ""}
    </div>
  );
}

export function PushNotificationForm() {
  const formRef = useRef<HTMLFormElement>(null);
  const lastToastKeyRef = useRef("");
  const nextDataPairIdRef = useRef(2);
  const [state, formAction, isPending] = useActionState(
    sendPushNotificationAction,
    initialState,
  );
  const [targetMode, setTargetMode] = useState("topic");
  const [dataPairs, setDataPairs] = useState<DataPair[]>([
    { id: 1, key: "", value: "" },
  ]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(state.message || "Push notification sent.");
      return;
    }

    toast.error(state.message || "Push notification could not be sent.");
  }, [state.message, state.resetKey, state.status]);

  const resetForm = () => {
    formRef.current?.reset();
    setTargetMode("topic");
    setDataPairs([{ id: 1, key: "", value: "" }]);
    nextDataPairIdRef.current = 2;
  };

  const addDataPair = () => {
    const id = nextDataPairIdRef.current;
    nextDataPairIdRef.current += 1;
    setDataPairs((current) => [...current, { id, key: "", value: "" }]);
  };

  const removeDataPair = (id: number) => {
    setDataPairs((current) =>
      current.length === 1 ? current : current.filter((pair) => pair.id !== id),
    );
  };

  const updateDataPair = (
    id: number,
    field: "key" | "value",
    value: string,
  ) => {
    setDataPairs((current) =>
      current.map((pair) =>
        pair.id === id ? { ...pair, [field]: value } : pair,
      ),
    );
  };

  return (
    <form ref={formRef} action={formAction} className="space-y-5 pb-28">
      <div className="flex justify-end">
        <button
          type="button"
          onClick={resetForm}
          className="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/58 px-3 text-sm font-bold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:-translate-y-0.5 hover:border-[rgba(15,23,42,0.22)] hover:bg-white/80 hover:text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12 dark:hover:text-cyan-200"
        >
          <RotateCcw className="size-4" />
          Reset
        </button>
      </div>

      <StatusMessage state={state} />

      <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
        <div className="mb-5 flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
            <Bell className="size-4" />
          </span>
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              Firebase Cloud Messaging
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Notification content
            </h2>
          </div>
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Title
            </span>
            <input
              name="title"
              type="text"
              required
              placeholder="New release"
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Image URL
            </span>
            <input
              name="image"
              type="url"
              placeholder="https://..."
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0 lg:col-span-2">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Body
            </span>
            <textarea
              name="body"
              rows={4}
              required
              placeholder="Write the notification message"
              className={cn(inputClassName, "resize-y")}
            />
          </label>
        </div>
      </section>

      <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
        <div className="mb-5 flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
            <Send className="size-4" />
          </span>
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              Target
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Delivery
            </h2>
          </div>
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Send to
            </span>
            <select
              name="target_mode"
              value={targetMode}
              onChange={(event) => setTargetMode(event.target.value)}
              className={selectClassName}
            >
              <option value="topic" className={optionClassName}>
                Topic
              </option>
              <option value="token" className={optionClassName}>
                Device token
              </option>
            </select>
          </label>

          {targetMode === "topic" ? (
            <label className="block min-w-0">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                Topic
              </span>
              <input
                name="topic"
                type="text"
                defaultValue="all"
                placeholder="all"
                className={inputClassName}
              />
            </label>
          ) : (
            <label className="block min-w-0">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                Device token
              </span>
              <input
                name="token"
                type="text"
                required={targetMode === "token"}
                placeholder="FCM registration token"
                className={inputClassName}
              />
            </label>
          )}
        </div>
      </section>

      <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
        <div className="mb-5 flex items-center justify-between gap-3">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              Payload
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Data fields
            </h2>
          </div>
          <button
            type="button"
            onClick={addDataPair}
            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/58 px-3 text-sm font-bold text-slate-700 transition hover:bg-white/80 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12"
          >
            <Plus className="size-4" />
            Add field
          </button>
        </div>

        <div className="space-y-3">
          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Legacy key
            </span>
            <input
              name="key"
              type="text"
              placeholder="movie_id, promo, update"
              className={inputClassName}
            />
          </label>

          {dataPairs.map((pair) => (
            <div
              key={pair.id}
              className="grid gap-3 rounded-md border border-[rgba(15,23,42,0.12)] bg-white/42 p-3 dark:border-white/10 dark:bg-white/6 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"
            >
              <label className="block min-w-0">
                <span className="text-xs font-bold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">
                  Data key
                </span>
                <input
                  name="data_key"
                  type="text"
                  value={pair.key}
                  onChange={(event) =>
                    updateDataPair(pair.id, "key", event.target.value)
                  }
                  placeholder="screen"
                  className={inputClassName}
                />
              </label>

              <label className="block min-w-0">
                <span className="text-xs font-bold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">
                  Data value
                </span>
                <input
                  name="data_value"
                  type="text"
                  value={pair.value}
                  onChange={(event) =>
                    updateDataPair(pair.id, "value", event.target.value)
                  }
                  placeholder="movie-details"
                  className={inputClassName}
                />
              </label>

              <button
                type="button"
                onClick={() => removeDataPair(pair.id)}
                disabled={dataPairs.length === 1}
                className="inline-flex min-h-12 items-center justify-center gap-2 self-end rounded-md bg-rose-600 px-3 text-sm font-bold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                <Trash2 className="size-4" />
                Remove
              </button>
            </div>
          ))}
        </div>
      </section>

      <div className="flex justify-end">
        <button
          type="submit"
          disabled={isPending}
          className="inline-flex min-h-12 items-center justify-center gap-2 rounded-md bg-slate-950 px-6 text-sm font-bold text-white shadow-[0_14px_34px_rgba(15,23,42,0.22)] transition hover:-translate-y-0.5 hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white dark:text-slate-950 dark:hover:bg-cyan-100"
        >
          <Send className="size-4" />
          {isPending ? "Sending..." : "Send notification"}
        </button>
      </div>
    </form>
  );
}
