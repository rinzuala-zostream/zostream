"use client";

import {
  useActionState,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  useTransition,
} from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  ArrowLeft,
  Check,
  Save,
  Sparkles,
  Trash2,
  UserPen,
} from "lucide-react";
import { toast } from "react-toastify";
import {
  deleteSubscriberAction,
  updateSubscriberAction,
  type SubscriberMutationState,
} from "@/app/(admin)/subscriptions/subscribers/edit/actions";
import { useAdminSidebar } from "@/app/components/admin-sidebar-shell";
import { ConfirmDialog } from "@/app/components/confirm-dialog";
import { cn } from "@/lib/utils";
import { adminDateValue } from "@/app/lib/admin-date";
import type { PlanItem } from "@/app/features/subscriptions/services/plan-service";
import type { SubscriptionItem } from "@/app/features/subscriptions/services/subscription-service";

type EditSubscriberFormProps = {
  subscription: SubscriptionItem;
  plans: PlanItem[];
  returnHref?: string;
};

const initialState: SubscriberMutationState = {
  status: "idle",
  message: "",
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const selectClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm font-semibold text-slate-950 [color-scheme:light] outline-none shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:[color-scheme:dark] dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";
const optionClassName =
  "bg-white text-slate-950 dark:bg-slate-950 dark:text-white";

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

function valueToBoolean(value: unknown) {
  return value === true || value === 1 || value === "1";
}

function dateValue(value: unknown) {
  return adminDateValue(value);
}

function formatPlanLabel(plan: PlanItem) {
  const name = valueToString(plan.name) || "Untitled plan";
  const deviceType = valueToString(plan.device_type) || "device";
  const price = valueToString(plan.price) || "0";

  return `${name} · ${deviceType} · ₹${price}`;
}

export function EditSubscriberForm({
  subscription,
  plans,
  returnHref = "/subscriptions/subscribers",
}: EditSubscriberFormProps) {
  const router = useRouter();
  const { isDesktopSidebarOpen } = useAdminSidebar();
  const lastToastKeyRef = useRef("");
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [isDeletePending, startDeleteTransition] = useTransition();
  const subscriptionId = valueToString(subscription.id);
  const [state, formAction, isPending] = useActionState(
    updateSubscriberAction.bind(null, subscriptionId),
    initialState,
  );
  const statusMessage = useMemo(() => state.message, [state.message]);

  const navigateToSubscriberList = useCallback(() => {
    router.replace(returnHref);
    window.setTimeout(() => {
      router.refresh();
    }, 0);
  }, [returnHref, router]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(statusMessage || "Subscriber updated.");
      const timeoutId = window.setTimeout(navigateToSubscriberList, 200);
      return () => window.clearTimeout(timeoutId);
    }

    toast.error(statusMessage || "Subscriber could not be updated.");
  }, [
    navigateToSubscriberList,
    state.message,
    state.resetKey,
    state.status,
    statusMessage,
  ]);

  const deleteSubscriber = () => {
    if (!subscriptionId) return;

    startDeleteTransition(async () => {
      const result = await deleteSubscriberAction(subscriptionId);

      if (result.status === "success") {
        toast.success(result.message || "Subscriber deleted.");
        setIsDeleteDialogOpen(false);
        navigateToSubscriberList();
        return;
      }

      toast.error(result.message || "Subscriber could not be deleted.");
    });
  };

  return (
    <form
      action={formAction}
      className="space-y-4 pb-72 sm:pb-40 lg:pb-28"
    >
      {state.status !== "idle" ? (
        <div
          className={cn(
            "rounded-md border px-4 py-3 text-sm font-semibold",
            state.status === "success"
              ? "border-teal-200 bg-teal-50/90 text-teal-800 dark:border-cyan-300/25 dark:bg-cyan-300/10 dark:text-cyan-100"
              : "border-rose-200 bg-rose-50/90 text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100",
          )}
        >
          {statusMessage}
        </div>
      ) : null}

      <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
        <div className="mb-5 flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
            <UserPen className="size-4" />
          </span>
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              Subscriber
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Subscription #{subscriptionId}
            </h2>
          </div>
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              User ID
            </span>
            <input
              type="text"
              value={valueToString(subscription.user_id)}
              readOnly
              className={cn(inputClassName, "cursor-not-allowed opacity-75")}
            />
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Plan
            </span>
            <select
              name="plan_id"
              defaultValue={valueToString(subscription.plan_id)}
              required
              className={selectClassName}
            >
              {plans.map((plan) => (
                <option
                  key={valueToString(plan.id)}
                  value={valueToString(plan.id)}
                  className={optionClassName}
                >
                  {formatPlanLabel(plan)}
                </option>
              ))}
            </select>
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Start date
            </span>
            <input
              name="start_at"
              type="date"
              defaultValue={dateValue(subscription.start_at)}
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              End date
            </span>
            <input
              name="end_at"
              type="date"
              defaultValue={dateValue(subscription.end_at)}
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Renewed by
            </span>
            <input
              name="renewed_by"
              type="text"
              defaultValue={valueToString(subscription.renewed_by)}
              placeholder="Admin UID or note"
              className={inputClassName}
            />
          </label>

          <label className="group flex min-h-12 items-center justify-between gap-3 self-end rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/64 dark:border-white/10 dark:bg-white/6 dark:text-slate-200 dark:hover:bg-white/10">
            <span>Subscription active</span>
            <input
              type="checkbox"
              name="is_active"
              defaultChecked={valueToBoolean(subscription.is_active)}
              className="peer sr-only"
            />
            <span className="relative h-6 w-11 shrink-0 rounded-full bg-slate-300/80 after:absolute after:left-1 after:top-1 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition after:content-[''] peer-checked:bg-teal-500 peer-checked:after:translate-x-5 dark:bg-slate-700 dark:peer-checked:bg-cyan-400" />
          </label>
        </div>
      </section>

      <div
        className={cn(
          "fixed inset-x-3 bottom-3 z-50 grid gap-3 rounded-lg border border-white/58 bg-white/78 p-3 shadow-[0_20px_50px_rgba(15,23,42,0.18)] backdrop-blur-xl transition-[left] duration-300 ease-out sm:grid-cols-[1fr_auto_1fr] sm:items-center lg:right-3 dark:border-white/10 dark:bg-slate-950/72 dark:shadow-[0_20px_50px_rgba(2,6,23,0.5)]",
          isDesktopSidebarOpen ? "md:left-[18.75rem]" : "md:left-[4.25rem]",
        )}
      >
        <Link
          href={returnHref}
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/65 px-4 text-sm font-bold text-slate-700 transition hover:bg-white sm:justify-self-start dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12"
        >
          <ArrowLeft className="size-4" />
          Subscriber list
        </Link>
        <button
          type="button"
          disabled={!subscriptionId || isPending || isDeletePending}
          onClick={() => setIsDeleteDialogOpen(true)}
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-rose-200 bg-rose-50 px-5 py-3 text-sm font-bold text-rose-700 transition hover:-translate-y-0.5 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 sm:justify-self-center dark:border-rose-300/20 dark:bg-rose-300/10 dark:text-rose-100 dark:hover:bg-rose-300/16"
        >
          <Trash2 className="size-4" />
          Delete subscriber
        </button>
        <div className="flex flex-col gap-3 sm:justify-self-end lg:flex-row lg:items-center">
          <div className="flex items-center gap-3 text-sm font-semibold text-slate-700 dark:text-slate-200">
            <span className="flex size-9 items-center justify-center rounded-md bg-teal-100 text-teal-700 dark:bg-cyan-300/12 dark:text-cyan-200">
              {state.status === "success" ? (
                <Check className="size-4" />
              ) : (
                <Sparkles className="size-4" />
              )}
            </span>
            <span className="hidden xl:inline">
              Update the selected subscriber subscription.
            </span>
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
                Save subscriber
              </>
            )}
          </button>
        </div>
      </div>

      <ConfirmDialog
        open={isDeleteDialogOpen}
        title="Delete subscriber?"
        description={`Delete subscription #${subscriptionId || "this subscriber"} permanently. This action cannot be undone.`}
        confirmLabel="Delete subscriber"
        isPending={isDeletePending}
        variant="danger"
        onConfirm={deleteSubscriber}
        onClose={() => {
          if (!isDeletePending) setIsDeleteDialogOpen(false);
        }}
      />
    </form>
  );
}
