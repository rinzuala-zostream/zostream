"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import {
  CalendarDays,
  MonitorSmartphone,
  PencilLine,
  ShieldCheck,
  Trash2,
  Users,
} from "lucide-react";
import { toast } from "react-toastify";
import { deletePlanAction } from "@/app/(admin)/subscriptions/plans/edit/actions";
import { ConfirmDialog } from "@/app/components/confirm-dialog";
import { cn } from "@/lib/utils";
import type { PlanItem } from "@/app/features/subscriptions/services/plan-service";

type PlanListCardsProps = {
  plans: PlanItem[];
};

const planTabs = [
  { label: "All", match: "" },
  { label: "Kar 1", match: "Kar 1" },
  { label: "Thla 1", match: "Thla 1" },
  { label: "Thla 4", match: "Thla 4" },
  { label: "Thla 6", match: "Thla 6" },
  { label: "Kum 1", match: "Kum 1" },
] as const;

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

function isActive(value: PlanItem["is_active"]) {
  return value === true || value === 1;
}

function formatCurrency(value: PlanItem["price"]) {
  const amount = Number(value);
  if (!Number.isFinite(amount)) return "Price not set";

  return new Intl.NumberFormat("en-IN", {
    style: "currency",
    currency: "INR",
    maximumFractionDigits: amount % 1 === 0 ? 0 : 2,
  }).format(amount);
}

function featureNames(plan: PlanItem) {
  if (!Array.isArray(plan.features)) return [];

  return plan.features
    .map((feature) => valueToString(feature.feature))
    .filter(Boolean);
}

export function PlanListCards({ plans }: PlanListCardsProps) {
  const router = useRouter();
  const [selectedPlan, setSelectedPlan] = useState<PlanItem | null>(null);
  const [activeTab, setActiveTab] = useState<(typeof planTabs)[number]["label"]>(
    "All",
  );
  const [isPending, startTransition] = useTransition();

  const selectedTab = planTabs.find((tab) => tab.label === activeTab);
  const visiblePlans = selectedTab?.match
    ? plans.filter((plan) =>
        valueToString(plan.name)
          .toLowerCase()
          .startsWith(selectedTab.match.toLowerCase()),
      )
    : plans;

  const deleteSelectedPlan = () => {
    const planId = valueToString(selectedPlan?.id);
    if (!planId) return;

    startTransition(async () => {
      const result = await deletePlanAction(planId);

      if (result.status === "success") {
        toast.success(result.message || "Plan deleted.");
        setSelectedPlan(null);
        router.refresh();
        return;
      }

      toast.error(result.message || "Plan could not be deleted.");
    });
  };

  if (plans.length === 0) {
    return (
      <section className="rounded-lg border border-dashed border-[rgba(15,23,42,0.18)] bg-white/52 p-8 text-center shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/12 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.36)]">
        <h2 className="text-xl font-bold text-slate-950 dark:text-white">
          No plans yet
        </h2>
        <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-600 dark:text-slate-300">
          Create a subscription plan first, then return here to update pricing,
          device limits, or availability.
        </p>
        <Link
          href="/subscriptions/plans/create"
          className="mt-5 inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
        >
          Create plan
        </Link>
      </section>
    );
  }

  return (
    <>
      <div className="mb-4 overflow-x-auto pb-1">
        <div className="flex min-w-max gap-2">
          {planTabs.map((tab) => {
            const isSelected = activeTab === tab.label;

            return (
              <button
                key={tab.label}
                type="button"
                onClick={() => setActiveTab(tab.label)}
                className={cn(
                  "inline-flex min-h-10 items-center justify-center rounded-md border px-4 text-sm font-bold transition",
                  isSelected
                    ? "border-slate-950 bg-slate-950 text-white shadow-[0_12px_28px_rgba(15,23,42,0.16)] dark:border-white dark:bg-white dark:text-slate-950"
                    : "border-[rgba(15,23,42,0.14)] bg-white/58 text-slate-700 hover:bg-white hover:text-slate-950 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12 dark:hover:text-white",
                )}
              >
                {tab.label}
              </button>
            );
          })}
        </div>
      </div>

      {visiblePlans.length === 0 ? (
        <section className="rounded-lg border border-dashed border-[rgba(15,23,42,0.18)] bg-white/52 p-8 text-center shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/12 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.36)]">
          <h2 className="text-xl font-bold text-slate-950 dark:text-white">
            No {activeTab} plans
          </h2>
          <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-600 dark:text-slate-300">
            Plans matching this tab will appear here after they are created.
          </p>
        </section>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
          {visiblePlans.map((plan) => {
            const planId = valueToString(plan.id);
            const active = isActive(plan.is_active);
            const features = featureNames(plan);

            return (
              <article
                key={planId || valueToString(plan.name)}
                className="liquid-glass flex min-h-72 flex-col rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]"
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
                      {valueToString(plan.device_type) || "Device"}
                    </p>
                    <h2 className="mt-2 truncate text-2xl font-bold text-slate-950 dark:text-white">
                      {valueToString(plan.name) || "Untitled plan"}
                    </h2>
                  </div>
                  <span
                    className={cn(
                      "inline-flex shrink-0 items-center rounded-md px-2.5 py-1 text-xs font-bold",
                      active
                        ? "bg-teal-100 text-teal-700 dark:bg-cyan-300/12 dark:text-cyan-100"
                        : "bg-slate-200 text-slate-600 dark:bg-white/10 dark:text-slate-300",
                    )}
                  >
                    {active ? "Active" : "Inactive"}
                  </span>
                </div>

                <p className="mt-5 text-3xl font-black tracking-tight text-slate-950 dark:text-white">
                  {formatCurrency(plan.price)}
                </p>

                <dl className="mt-5 grid gap-3 text-sm text-slate-700 dark:text-slate-200">
                  <div className="flex items-center gap-3 rounded-md bg-white/45 px-3 py-2 dark:bg-white/6">
                    <CalendarDays className="size-4 text-teal-700 dark:text-cyan-200" />
                    <dt className="sr-only">Duration</dt>
                    <dd>{valueToString(plan.duration_days) || "0"} days</dd>
                  </div>
                  <div className="flex items-center gap-3 rounded-md bg-white/45 px-3 py-2 dark:bg-white/6">
                    <Users className="size-4 text-teal-700 dark:text-cyan-200" />
                    <dt className="sr-only">Device limit</dt>
                    <dd>{valueToString(plan.device_limit) || "0"} devices</dd>
                  </div>
                  <div className="flex items-center gap-3 rounded-md bg-white/45 px-3 py-2 dark:bg-white/6">
                    <MonitorSmartphone className="size-4 text-teal-700 dark:text-cyan-200" />
                    <dt className="sr-only">Quality</dt>
                    <dd>{valueToString(plan.quality) || "HD"}</dd>
                  </div>
                  <div className="rounded-md bg-white/45 px-3 py-2 dark:bg-white/6">
                    <dt className="sr-only">Features</dt>
                    <dd className="min-w-0">
                      <div className="flex items-center gap-3">
                        <ShieldCheck className="size-4 shrink-0 text-teal-700 dark:text-cyan-200" />
                        <span className="font-semibold">
                          {features.length} feature
                          {features.length === 1 ? "" : "s"}
                        </span>
                      </div>
                      {features.length > 0 ? (
                        <ul className="mt-2 space-y-1 pl-7 text-xs leading-5 text-slate-600 dark:text-slate-300">
                          {features.map((feature) => (
                            <li key={feature} className="truncate">
                              {feature}
                            </li>
                          ))}
                        </ul>
                      ) : (
                        <p className="mt-2 pl-7 text-xs text-slate-500 dark:text-slate-400">
                          No feature text yet.
                        </p>
                      )}
                    </dd>
                  </div>
                </dl>

                <div className="mt-auto flex flex-col gap-2 pt-5 sm:flex-row">
                  <Link
                    href={`/subscriptions/plans/edit/${planId}`}
                    className={cn(
                      "inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200",
                      !planId && "pointer-events-none opacity-50",
                    )}
                  >
                    <PencilLine className="size-4" />
                    Edit
                  </Link>
                  <button
                    type="button"
                    disabled={!planId}
                    onClick={() => setSelectedPlan(plan)}
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
        open={Boolean(selectedPlan)}
        title="Delete plan?"
        description={`Delete ${valueToString(selectedPlan?.name) || "this plan"} permanently. Plans with active subscriptions may be blocked by the API.`}
        confirmLabel="Delete plan"
        isPending={isPending}
        variant="danger"
        onConfirm={deleteSelectedPlan}
        onClose={() => {
          if (!isPending) setSelectedPlan(null);
        }}
      />
    </>
  );
}
