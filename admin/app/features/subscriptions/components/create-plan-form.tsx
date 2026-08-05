"use client";

import {
  useActionState,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ChangeEvent,
} from "react";
import {
  Check,
  CircleDollarSign,
  Crown,
  ListPlus,
  Plus,
  Save,
  Settings2,
  Sparkles,
  Trash2,
  type LucideIcon,
} from "lucide-react";
import {
  createPlanAction,
  type CreatePlanFormState,
} from "@/app/(admin)/subscriptions/plans/create/actions";
import { useAdminSidebar } from "@/app/components/admin-sidebar-shell";
import { cn } from "@/lib/utils";
import { toast } from "react-toastify";

const initialState: CreatePlanFormState = {
  status: "idle",
  message: "",
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const selectClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm font-semibold text-slate-950 [color-scheme:light] outline-none shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:[color-scheme:dark] dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";
const optionClassName =
  "bg-white text-slate-950 dark:bg-slate-950 dark:text-white";

const planNameOptions = [
  { name: "Kar 1(1 week)", durationDays: "7" },
  { name: "Thla 1(1 month)", durationDays: "30" },
  { name: "Thla 4(4 months)", durationDays: "120" },
  { name: "Thla 6(6 months)", durationDays: "180" },
  { name: "Kum 1(1 year)", durationDays: "365" },
] as const;

type FieldProps = {
  label: string;
  name: string;
  placeholder?: string;
  type?: string;
  required?: boolean;
  helper?: string;
  min?: number;
  max?: number;
  step?: string;
  defaultValue?: string | number;
  value?: string;
  onChange?: (event: ChangeEvent<HTMLInputElement>) => void;
};

function Field({
  label,
  name,
  placeholder,
  type = "text",
  required,
  helper,
  min,
  max,
  step,
  defaultValue,
  value,
  onChange,
}: FieldProps) {
  return (
    <label className="block min-w-0">
      <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
        {label}
      </span>
      <input
        name={name}
        type={type}
        placeholder={placeholder}
        required={required}
        min={min}
        max={max}
        step={step}
        defaultValue={defaultValue}
        value={value}
        onChange={onChange}
        className={inputClassName}
      />
      {helper ? (
        <span className="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">
          {helper}
        </span>
      ) : null}
    </label>
  );
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

function SwitchPill({
  name,
  label,
  defaultChecked = true,
}: {
  name: string;
  label: string;
  defaultChecked?: boolean;
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

type FeatureRow = {
  id: number;
  feature: string;
};

export function CreatePlanForm() {
  const { isDesktopSidebarOpen } = useAdminSidebar();
  const formRef = useRef<HTMLFormElement>(null);
  const lastToastKeyRef = useRef("");
  const nextFeatureIdRef = useRef(4);
  const [planName, setPlanName] = useState("");
  const [durationDays, setDurationDays] = useState("");
  const [ppvDiscount, setPpvDiscount] = useState("0");
  const [featureRows, setFeatureRows] = useState<FeatureRow[]>([
    { id: 1, feature: "Watch on selected device" },
    { id: 2, feature: "Unlock all premium content" },
    { id: 3, feature: "PPV discount" },
  ]);
  const [state, formAction, isPending] = useActionState(
    createPlanAction,
    initialState,
  );

  const statusMessage = useMemo(() => {
    if (state.status === "success" && state.planId) {
      return `${state.message} Plan ID: ${state.planId}`;
    }

    return state.message;
  }, [state.message, state.planId, state.status]);

  useEffect(() => {
    if (state.status === "success") {
      formRef.current?.reset();
      window.setTimeout(() => {
        setPlanName("");
        setDurationDays("");
        setPpvDiscount("0");
        nextFeatureIdRef.current = 4;
        setFeatureRows([
          { id: 1, feature: "Watch on selected device" },
          { id: 2, feature: "Unlock all premium content" },
          { id: 3, feature: "PPV discount" },
        ]);
      }, 0);
    }
  }, [state.planId, state.status]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(statusMessage || "Plan saved successfully.");
      return;
    }

    toast.error(statusMessage || "Plan could not be saved.");
  }, [state.message, state.resetKey, state.status, statusMessage]);

  const addFeatureRow = () => {
    const id = nextFeatureIdRef.current;
    nextFeatureIdRef.current += 1;
    setFeatureRows((current) => [...current, { id, feature: "" }]);
  };

  const removeFeatureRow = (id: number) => {
    setFeatureRows((current) =>
      current.length === 1 ? current : current.filter((row) => row.id !== id),
    );
  };

  const updateFeatureRow = (
    id: number,
    field: "feature",
    value: string,
  ) => {
    setFeatureRows((current) =>
      current.map((row) =>
        row.id === id ? { ...row, [field]: value } : row,
      ),
    );
  };

  const updatePlanName = (value: string) => {
    setPlanName(value);

    const selectedPlan = planNameOptions.find((option) => option.name === value);
    if (selectedPlan) {
      setDurationDays(selectedPlan.durationDays);
    }
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
          {statusMessage}
        </div>
      ) : null}

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.38fr)]">
        <div className="space-y-4">
          <FormSection title="Plan details" eyebrow="Required" icon={Crown}>
            <div className="grid gap-4 lg:grid-cols-2">
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Plan name
                </span>
                <input
                  name="name"
                  type="text"
                  list="plan-name-options"
                  value={planName}
                  onChange={(event) => updatePlanName(event.target.value)}
                  placeholder="Select or type a plan name"
                  required
                  className={inputClassName}
                />
                <datalist id="plan-name-options">
                  {planNameOptions.map((option) => (
                    <option key={option.name} value={option.name} />
                  ))}
                </datalist>
                <span className="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">
                  Choose a preset to auto-fill duration, or type a custom plan.
                </span>
              </label>
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Device type
                </span>
                <select
                  name="device_type"
                  defaultValue="mobile"
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
              <Field
                label="Device limit"
                name="device_limit"
                type="number"
                min={1}
                placeholder="2"
                required
                helper="Maximum active streams for this plan."
              />
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Quality
                </span>
                <select
                  name="quality"
                  defaultValue="HD"
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
          </FormSection>

          <FormSection title="Features" eyebrow="Benefits" icon={ListPlus}>
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
          </FormSection>
        </div>

        <aside className="space-y-4">
          <FormSection title="Pricing" eyebrow="Amount" icon={CircleDollarSign}>
            <div className="grid gap-4">
              <Field
                label="Price"
                name="price"
                type="number"
                min={0}
                step="0.01"
                placeholder="199"
                required
              />
              <Field
                label="Duration days"
                name="duration_days"
                type="number"
                min={1}
                placeholder="30"
                value={durationDays}
                onChange={(event) => setDurationDays(event.target.value)}
                required
              />
              <SwitchPill name="is_active" label="Plan is active" />
            </div>
          </FormSection>

          <FormSection title="Publishing note" eyebrow="Status" icon={Settings2}>
            <div className="rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 p-3 text-sm leading-6 text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] dark:border-white/10 dark:bg-white/6 dark:text-slate-200">
              Active plans can be listed for subscribers immediately. Use
              inactive while preparing pricing or feature text.
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
          <span>Create the plan first, then features are attached to it.</span>
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
              Save plan
            </>
          )}
        </button>
      </div>
    </form>
  );
}
