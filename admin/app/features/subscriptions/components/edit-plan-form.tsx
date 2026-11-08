"use client";

import {
  useActionState,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import {
  ArrowLeft,
  CircleDollarSign,
  Crown,
  ListPlus,
  Plus,
  Save,
  Trash2,
} from "lucide-react";
import Link from "next/link";
import { toast } from "react-toastify";
import {
  updatePlanAction,
  type PlanMutationState,
} from "@/app/(admin)/subscriptions/plans/edit/actions";
import { useAdminSidebar } from "@/app/components/admin-sidebar-shell";
import { cn } from "@/lib/utils";
import type {
  PlanFeatureItem,
  PlanItem,
} from "@/app/features/subscriptions/services/plan-service";

type EditPlanFormProps = {
  plan: PlanItem;
};

const initialState: PlanMutationState = {
  status: "idle",
  message: "",
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const selectClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm font-semibold text-slate-950 [color-scheme:light] outline-none shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:[color-scheme:dark] dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";
const optionClassName =
  "bg-white text-slate-950 dark:bg-slate-950 dark:text-white";

type FeatureRow = {
  id: string;
  featureId: string;
  feature: string;
};

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

function valueToBoolean(value: unknown) {
  return value === true || value === 1 || value === "1";
}

function featureRowsFromPlan(features?: PlanFeatureItem[]): FeatureRow[] {
  if (!Array.isArray(features) || features.length === 0) {
    return [{ id: "new-1", featureId: "", feature: "" }];
  }

  return features.map((feature, index) => {
    const featureId = valueToString(feature.id);

    return {
      id: featureId || `new-${index + 1}`,
      featureId,
      feature: valueToString(feature.feature),
    };
  });
}

function ppvDiscountFromPlan(features?: PlanFeatureItem[]) {
  if (!Array.isArray(features)) return "0";

  const featureWithDiscount = features.find(
    (feature) => valueToString(feature.ppv_discount) !== "",
  );

  return valueToString(featureWithDiscount?.ppv_discount) || "0";
}

export function EditPlanForm({ plan }: EditPlanFormProps) {
  const { isDesktopSidebarOpen } = useAdminSidebar();
  const lastToastKeyRef = useRef("");
  const nextFeatureIdRef = useRef(1);
  const planId = valueToString(plan.id);
  const existingFeatureIds = useMemo(
    () =>
      (plan.features ?? [])
        .map((feature) => valueToString(feature.id))
        .filter(Boolean),
    [plan.features],
  );
  const [featureRows, setFeatureRows] = useState<FeatureRow[]>(() =>
    featureRowsFromPlan(plan.features),
  );
  const [ppvDiscount, setPpvDiscount] = useState(() =>
    ppvDiscountFromPlan(plan.features),
  );
  const [state, formAction, isPending] = useActionState(
    updatePlanAction.bind(null, planId),
    initialState,
  );

  const statusMessage = useMemo(() => state.message, [state.message]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(statusMessage || "Plan updated.");
      return;
    }

    toast.error(statusMessage || "Plan could not be updated.");
  }, [state.message, state.resetKey, state.status, statusMessage]);

  const addFeatureRow = () => {
    const id = `new-${Date.now()}-${nextFeatureIdRef.current}`;
    nextFeatureIdRef.current += 1;
    setFeatureRows((current) => [
      ...current,
      { id, featureId: "", feature: "" },
    ]);
  };

  const removeFeatureRow = (id: string) => {
    setFeatureRows((current) =>
      current.length === 1 ? current : current.filter((row) => row.id !== id),
    );
  };

  const updateFeatureRow = (
    id: string,
    field: "feature",
    value: string,
  ) => {
    setFeatureRows((current) =>
      current.map((row) =>
        row.id === id ? { ...row, [field]: value } : row,
      ),
    );
  };

  return (
    <form action={formAction} className="space-y-4 pb-24">
      {existingFeatureIds.map((featureId) => (
        <input
          key={featureId}
          type="hidden"
          name="existing_feature_id"
          value={featureId}
        />
      ))}

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

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.38fr)]">
        <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
          <div className="mb-5 flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
              <Crown className="size-4" />
            </span>
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
                Plan details
              </p>
              <h2 className="text-lg font-bold text-slate-950 dark:text-white">
                Update subscription plan
              </h2>
            </div>
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <label className="block min-w-0">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                Plan name
              </span>
              <input
                name="name"
                type="text"
                defaultValue={valueToString(plan.name)}
                required
                className={inputClassName}
              />
            </label>

            <label className="block min-w-0">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                Device type
              </span>
              <select
                name="device_type"
                defaultValue={valueToString(plan.device_type) || "mobile"}
                required
                className={selectClassName}
              >
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
                Device limit
              </span>
              <input
                name="device_limit"
                type="number"
                min={1}
                defaultValue={valueToString(plan.device_limit)}
                required
                className={inputClassName}
              />
            </label>

            <label className="block min-w-0">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                Quality
              </span>
              <select
                name="quality"
                defaultValue={valueToString(plan.quality) || "HD"}
                required
                className={selectClassName}
              >
                <option value="SD" className={optionClassName}>
                  SD
                </option>
                <option value="HD" className={optionClassName}>
                  HD
                </option>
                <option value="FULL_HD" className={optionClassName}>
                  Full HD
                </option>
                <option value="4K" className={optionClassName}>
                  4K
                </option>
              </select>
            </label>
          </div>
        </section>

        <div className="space-y-4 xl:col-start-1">
          <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
            <div className="mb-5 flex items-center gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
                <ListPlus className="size-4" />
              </span>
              <div>
                <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
                  Benefits
                </p>
                <h2 className="text-lg font-bold text-slate-950 dark:text-white">
                  Features
                </h2>
              </div>
            </div>

            <label className="mb-4 block max-w-xs">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                PPV %
              </span>
              <input
                name="ppv_discount"
                type="number"
                min={0}
                max={100}
                step="0.01"
                value={ppvDiscount}
                onChange={(event) => setPpvDiscount(event.target.value)}
                placeholder="0"
                className={inputClassName}
              />
              <span className="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">
                This PPV discount applies to every feature in this plan.
              </span>
            </label>

            <div className="space-y-3">
              {featureRows.map((row, index) => (
                <div
                  key={row.id}
                  className="grid gap-3 rounded-md border border-[rgba(15,23,42,0.12)] bg-white/42 p-3 shadow-[0_1px_2px_rgba(15,23,42,0.03)] md:grid-cols-[minmax(0,1fr)_auto] dark:border-white/10 dark:bg-white/6"
                >
                  <input type="hidden" name="feature_id" value={row.featureId} />
                  <label className="block min-w-0">
                    <span className="text-xs font-bold uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">
                      Feature {index + 1}
                    </span>
                    <input
                      name="feature"
                      type="text"
                      value={row.feature}
                      onChange={(event) =>
                        updateFeatureRow(row.id, "feature", event.target.value)
                      }
                      placeholder="Ad free"
                      className={inputClassName}
                    />
                  </label>
                  <div className="flex items-end">
                    <button
                      type="button"
                      onClick={() => removeFeatureRow(row.id)}
                      disabled={featureRows.length === 1}
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
              onClick={addFeatureRow}
              className="mt-4 inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/55 px-4 text-sm font-bold text-slate-700 transition hover:-translate-y-0.5 hover:bg-white/80 hover:text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12 dark:hover:text-cyan-200"
            >
              <Plus className="size-4" />
              Add feature
            </button>
          </section>
        </div>

        <aside className="space-y-4 xl:col-start-2 xl:row-start-1 xl:row-span-2">
          <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
            <div className="mb-5 flex items-center gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
                <CircleDollarSign className="size-4" />
              </span>
              <div>
                <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
                  Pricing
                </p>
                <h2 className="text-lg font-bold text-slate-950 dark:text-white">
                  Amount
                </h2>
              </div>
            </div>

            <div className="grid gap-4">
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Price
                </span>
                <input
                  name="price"
                  type="number"
                  min={0}
                  step="0.01"
                  defaultValue={valueToString(plan.price)}
                  required
                  className={inputClassName}
                />
              </label>

              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Duration days
                </span>
                <input
                  name="duration_days"
                  type="number"
                  min={1}
                  defaultValue={valueToString(plan.duration_days)}
                  required
                  className={inputClassName}
                />
              </label>

              <label className="group flex min-h-12 items-center justify-between gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/64 dark:border-white/10 dark:bg-white/6 dark:text-slate-200 dark:hover:bg-white/10">
                <span>Plan is active</span>
                <input
                  type="checkbox"
                  name="is_active"
                  defaultChecked={valueToBoolean(plan.is_active)}
                  className="peer sr-only"
                />
                <span className="relative h-6 w-11 shrink-0 rounded-full bg-slate-300/80 after:absolute after:left-1 after:top-1 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition after:content-[''] peer-checked:bg-teal-500 peer-checked:after:translate-x-5 dark:bg-slate-700 dark:peer-checked:bg-cyan-400" />
              </label>
            </div>
          </section>

        </aside>
      </div>

      <div
        className={cn(
          "fixed inset-x-3 bottom-3 z-50 flex flex-col gap-3 rounded-lg border border-white/58 bg-white/78 p-3 shadow-[0_20px_50px_rgba(15,23,42,0.18)] backdrop-blur-xl transition-[left] duration-300 ease-out sm:flex-row sm:items-center sm:justify-between lg:right-3 dark:border-white/10 dark:bg-slate-950/72 dark:shadow-[0_20px_50px_rgba(2,6,23,0.5)]",
          isDesktopSidebarOpen ? "md:left-[18.75rem]" : "md:left-[4.25rem]",
        )}
      >
        <Link
          href="/subscriptions/plans/edit"
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/65 px-4 text-sm font-bold text-slate-700 transition hover:bg-white dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12"
        >
          <ArrowLeft className="size-4" />
          Plan list
        </Link>
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
              Save changes
            </>
          )}
        </button>
      </div>
    </form>
  );
}
