"use client";

import { useActionState, useEffect, useMemo, useRef, useState } from "react";
import {
  Check,
  CreditCard,
  IndianRupee,
  Save,
  Sparkles,
  Search,
  UserPlus,
  X,
} from "lucide-react";
import { toast } from "react-toastify";
import {
  addSubscriberAction,
  type AddSubscriberFormState,
} from "@/app/(admin)/subscriptions/subscribers/add/actions";
import { PasteUIDButton } from "@/app/features/users/components/uid-clipboard";
import { useAdminSidebar } from "@/app/components/admin-sidebar-shell";
import { cn } from "@/lib/utils";
import type { UserItem } from "@/app/features/users/services/user-service";
import type { PlanItem } from "@/app/features/subscriptions/services/plan-service";

type AddSubscriberFormProps = {
  plans: PlanItem[];
};

const initialState: AddSubscriberFormState = {
  status: "idle",
  message: "",
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

function valueToBoolean(value: unknown) {
  return value === true || value === 1 || value === "1";
}

function formatPlanLabel(plan: PlanItem) {
  const name = valueToString(plan.name) || "Untitled plan";
  const deviceType = valueToString(plan.device_type) || "device";
  const price = valueToString(plan.price) || "0";
  const duration = valueToString(plan.duration_days) || "0";

  return `${name} · ${deviceType} · ₹${price} · ${duration} days`;
}

type UserSearchResult = Pick<
  UserItem,
  "uid" | "num" | "name" | "mail" | "call" | "auth_phone" | "device_name"
>;

type UserSearchResponse = {
  status?: "success" | "error";
  message?: string;
  data?:
    | UserSearchResult[]
    | {
        data?: UserSearchResult[];
      };
};

function isUserSearchResult(value: unknown): value is UserSearchResult {
  return typeof value === "object" && value !== null;
}

function extractUserSearchResults(value: unknown): UserSearchResult[] {
  if (typeof value !== "object" || value === null) return [];

  const response = value as UserSearchResponse;
  const payload = response.data;

  if (Array.isArray(payload)) {
    return payload.filter(isUserSearchResult);
  }

  if (
    payload &&
    typeof payload === "object" &&
    Array.isArray((payload as { data?: unknown }).data)
  ) {
    return ((payload as { data?: unknown[] }).data ?? []).filter(
      isUserSearchResult,
    );
  }

  return [];
}

function resolveUserId(user: UserSearchResult) {
  return (
    valueToString(user.uid) ||
    valueToString(user.auth_phone) ||
    valueToString(user.call) ||
    valueToString(user.num)
  );
}

function formatUserLabel(user: UserSearchResult) {
  return valueToString(user.name) || resolveUserId(user) || "Unknown user";
}

function formatUserMeta(user: UserSearchResult) {
  return [
    valueToString(user.uid) ? `UID ${valueToString(user.uid)}` : "",
    valueToString(user.mail) ? `Mail ${valueToString(user.mail)}` : "",
    valueToString(user.auth_phone) || valueToString(user.call)
      ? `Phone ${valueToString(user.auth_phone) || valueToString(user.call)}`
      : "",
  ]
    .filter(Boolean)
    .join(" · ");
}

export function AddSubscriberForm({ plans }: AddSubscriberFormProps) {
  const { isDesktopSidebarOpen } = useAdminSidebar();
  const formRef = useRef<HTMLFormElement>(null);
  const userSearchWrapRef = useRef<HTMLDivElement>(null);
  const userSearchRequestRef = useRef(0);
  const userIdInputRef = useRef<HTMLInputElement>(null);
  const lastToastKeyRef = useRef("");
  const [state, formAction, isPending] = useActionState(
    addSubscriberAction,
    initialState,
  );
  const [userSearchQuery, setUserSearchQuery] = useState("");
  const [userSearchResults, setUserSearchResults] = useState<
    UserSearchResult[]
  >([]);
  const [userSearchStatus, setUserSearchStatus] = useState<
    "idle" | "loading" | "success" | "error"
  >("idle");
  const [userSearchMessage, setUserSearchMessage] = useState(
    "Search by UID, name, email, or phone.",
  );
  const [isUserSearchOpen, setIsUserSearchOpen] = useState(false);
  const [selectedUser, setSelectedUser] = useState<UserSearchResult | null>(
    null,
  );
  const [userIdValue, setUserIdValue] = useState("");
  const [selectedPlanIds, setSelectedPlanIds] = useState<string[]>([]);

  const activePlans = useMemo(
    () => plans.filter((plan) => valueToBoolean(plan.is_active)),
    [plans],
  );

  const statusMessage = useMemo(() => {
    if (state.status === "success") {
      const details = [
        state.resolvedUserId ? `Resolved UID: ${state.resolvedUserId}` : "",
        state.transactionId ? `Transaction ID: ${state.transactionId}` : "",
      ].filter(Boolean);

      return details.length > 0
        ? `${state.message} ${details.join(" · ")}`
        : state.message;
    }

    return state.message;
  }, [state.message, state.resolvedUserId, state.status, state.transactionId]);

  useEffect(() => {
    if (state.status === "success") {
      formRef.current?.reset();
      setUserSearchQuery("");
      setUserSearchResults([]);
      setUserSearchStatus("idle");
      setUserSearchMessage("Search by UID, name, email, or phone.");
      setIsUserSearchOpen(false);
      setSelectedUser(null);
      setUserIdValue("");
      setSelectedPlanIds([]);
    }
  }, [state.status, state.transactionId]);

  useEffect(() => {
    function handlePointerDown(event: MouseEvent) {
      if (
        userSearchWrapRef.current &&
        !userSearchWrapRef.current.contains(event.target as Node)
      ) {
        setIsUserSearchOpen(false);
      }
    }

    document.addEventListener("mousedown", handlePointerDown);
    return () => document.removeEventListener("mousedown", handlePointerDown);
  }, []);

  useEffect(() => {
    const query = userSearchQuery.trim();

    if (query.length < 2) {
      setUserSearchResults([]);
      setUserSearchStatus("idle");
      setUserSearchMessage("Type at least 2 characters to search users.");
      return;
    }

    const controller = new AbortController();
    const requestId = ++userSearchRequestRef.current;
    const timer = window.setTimeout(async () => {
      try {
        setUserSearchStatus("loading");
        setUserSearchMessage("Searching users...");
        const response = await fetch(
          `/api/admin/users/search?q=${encodeURIComponent(query)}&per_page=8`,
          {
            cache: "no-store",
            signal: controller.signal,
          },
        );
        const data = (await response
          .json()
          .catch(() => null)) as UserSearchResponse | null;

        if (
          controller.signal.aborted ||
          requestId !== userSearchRequestRef.current
        ) {
          return;
        }

        if (!response.ok || data?.status !== "success") {
          throw new Error(data?.message || "Failed to search users");
        }

        const results = extractUserSearchResults(data);
        setUserSearchResults(results);
        setUserSearchStatus("success");
        setUserSearchMessage(
          results.length > 0
            ? `${results.length} user${results.length === 1 ? "" : "s"} found.`
            : "No matching users found.",
        );
        setIsUserSearchOpen(true);
      } catch (error) {
        if (
          controller.signal.aborted ||
          requestId !== userSearchRequestRef.current
        ) {
          return;
        }

        setUserSearchResults([]);
        setSelectedUser(null);
        setUserSearchStatus("error");
        setUserSearchMessage(
          error instanceof Error ? error.message : "Failed to search users",
        );
        setIsUserSearchOpen(true);
      }
    }, 300);

    return () => {
      controller.abort();
      window.clearTimeout(timer);
    };
  }, [userSearchQuery]);

  useEffect(() => {
    if (!selectedUser) return;
    const resolvedUserId = resolveUserId(selectedUser);
    setUserIdValue(resolvedUserId);
  }, [selectedUser]);

  const togglePlan = (planId: string) => {
    setSelectedPlanIds((current) => {
      return current.includes(planId)
        ? current.filter((currentPlanId) => currentPlanId !== planId)
        : [...current, planId];
    });
  };

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(statusMessage || "Subscriber payment created.");
      return;
    }

    toast.error(statusMessage || "Subscriber could not be added.");
  }, [state.message, state.resetKey, state.status, statusMessage]);

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

      <div className="space-y-4">
        <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
          <div className="mb-5 flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
              <UserPlus className="size-4" />
            </span>
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
                Subscriber
              </p>
              <h2 className="text-lg font-bold text-slate-950 dark:text-white">
                Link user to plan
              </h2>
            </div>
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <div className="min-w-0 space-y-4">
              <div ref={userSearchWrapRef} className="relative min-w-0">
                <label className="block min-w-0">
                  <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                    Search user
                  </span>
                  <div className="relative mt-2">
                    <Search className="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-slate-400 dark:text-slate-500" />
                    <input
                      type="search"
                      value={userSearchQuery}
                      onChange={(event) => {
                        setUserSearchQuery(event.target.value);
                        setIsUserSearchOpen(true);
                      }}
                      onFocus={() => setIsUserSearchOpen(true)}
                      placeholder="Search by UID, name, email, or phone"
                      className={`${inputClassName} pl-11`}
                    />
                  </div>
                </label>

                {isUserSearchOpen ? (
                  <div className="absolute left-0 right-0 top-full z-30 mt-2 overflow-hidden rounded-lg border border-[rgba(15,23,42,0.14)] bg-white/96 shadow-[0_18px_40px_rgba(15,23,42,0.16)] backdrop-blur-xl dark:border-white/10 dark:bg-slate-950/92 dark:shadow-[0_18px_50px_rgba(2,6,23,0.48)]">
                    <div className="border-b border-[rgba(15,23,42,0.08)] px-4 py-3 text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 dark:border-white/8 dark:text-slate-400">
                      {userSearchStatus === "loading"
                        ? "Searching users"
                        : "User search"}
                    </div>

                    {userSearchStatus === "loading" ? (
                      <div className="px-4 py-5 text-sm text-slate-600 dark:text-slate-300">
                        {userSearchMessage}
                      </div>
                    ) : userSearchResults.length > 0 ? (
                      <div className="max-h-72 overflow-auto p-2">
                        {userSearchResults.map((user) => {
                          const uid = resolveUserId(user);
                          const label = formatUserLabel(user);

                          return (
                            <button
                              key={uid || label}
                              type="button"
                              onMouseDown={(event) => event.preventDefault()}
                              onClick={() => {
                                setSelectedUser(user);
                                setUserIdValue(uid);
                                setUserSearchQuery("");
                                setUserSearchResults([]);
                                setUserSearchStatus("idle");
                                setUserSearchMessage(
                                  "Search by UID, name, email, or phone.",
                                );
                                setIsUserSearchOpen(false);
                                window.setTimeout(() => {
                                  userIdInputRef.current?.focus();
                                }, 0);
                              }}
                              className="flex min-w-0 w-full flex-col items-start gap-1 overflow-hidden rounded-md px-3 py-3 text-left transition hover:bg-teal-50/90 dark:hover:bg-white/8"
                            >
                              <span className="block max-w-full truncate text-sm font-bold text-slate-950 dark:text-white">
                                {label}
                              </span>
                              <span className="block max-w-full truncate text-xs font-semibold text-slate-500 dark:text-slate-400">
                                {formatUserMeta(user) ||
                                  "Select this user to autofill UID"}
                              </span>
                            </button>
                          );
                        })}
                      </div>
                    ) : (
                      <div className="px-4 py-5 text-sm text-slate-600 dark:text-slate-300">
                        {userSearchMessage}
                      </div>
                    )}
                  </div>
                ) : null}
              </div>

              <label className="block min-w-0">
                <div className="flex items-center justify-between">
                  <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                    Add Subscription UID
                  </span>
                  <PasteUIDButton
                    onPaste={(uid) => {
                      setUserIdValue(uid);
                      if (
                        selectedUser &&
                        uid.trim() !== resolveUserId(selectedUser)
                      ) {
                        setSelectedUser(null);
                      }
                    }}
                  />
                </div>
                <input
                  ref={userIdInputRef}
                  name="user_id"
                  type="text"
                  value={userIdValue}
                  onChange={(event) => {
                    const nextValue = event.target.value;
                    setUserIdValue(nextValue);

                    if (
                      selectedUser &&
                      nextValue.trim() !== resolveUserId(selectedUser)
                    ) {
                      setSelectedUser(null);
                    }
                  }}
                  placeholder="User UID or resolved phone"
                  required
                  className={inputClassName}
                />
              </label>

              {selectedUser ? (
                <div className="max-w-full overflow-hidden rounded-md border border-teal-200 bg-teal-50/80 px-4 py-3 text-sm font-semibold text-teal-800 dark:border-cyan-300/25 dark:bg-cyan-300/10 dark:text-cyan-100">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="text-xs uppercase tracking-[0.18em] text-teal-600 dark:text-cyan-200">
                        Selected user
                      </p>
                      <p className="mt-1 truncate text-sm font-bold">
                        {formatUserLabel(selectedUser)}
                      </p>
                      <p className="mt-1 truncate text-xs font-semibold opacity-80">
                        {formatUserMeta(selectedUser) || "UID will be used"}
                      </p>
                    </div>
                    <button
                      type="button"
                      onClick={() => {
                        setSelectedUser(null);
                        setUserIdValue("");
                        setUserSearchQuery("");
                        setUserSearchResults([]);
                        setUserSearchStatus("idle");
                        setUserSearchMessage(
                          "Search by UID, name, email, or phone.",
                        );
                        setIsUserSearchOpen(false);
                        userIdInputRef.current?.focus();
                      }}
                      className="inline-flex h-8 shrink-0 items-center justify-center rounded-md border border-current/20 px-3 text-xs font-bold transition hover:bg-white/20 dark:hover:bg-white/10"
                    >
                      <X className="mr-1 size-3.5" />
                      Clear
                    </button>
                  </div>
                </div>
              ) : null}
            </div>

            <div className="min-w-0">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Plan
                </span>
                <span className="rounded-md border border-[rgba(15,23,42,0.14)] bg-white/50 px-3 py-1.5 text-xs font-bold text-slate-600 dark:border-white/10 dark:bg-white/6 dark:text-slate-300">
                  {selectedPlanIds.length > 1 ? "Multiple" : "Single"}
                </span>
              </div>

              <div className="mt-2 grid max-h-80 gap-2 overflow-y-auto rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 p-2 dark:border-white/10 dark:bg-white/6">
                {activePlans.length > 0 ? (
                  activePlans.map((plan) => {
                    const planId = valueToString(plan.id);
                    const checked = selectedPlanIds.includes(planId);

                    return (
                      <label
                        key={planId}
                        className={cn(
                          "flex cursor-pointer items-start gap-3 rounded-md border p-3 text-sm transition",
                          checked
                            ? "border-teal-300 bg-teal-50/90 text-teal-900 dark:border-cyan-300/35 dark:bg-cyan-300/10 dark:text-cyan-50"
                            : "border-transparent bg-white/56 text-slate-700 hover:bg-white/80 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10",
                        )}
                      >
                        <input
                          type="checkbox"
                          name="plan_id"
                          value={planId}
                          checked={checked}
                          onChange={() => togglePlan(planId)}
                          className="mt-1 size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-300 dark:border-white/20 dark:bg-slate-950 dark:text-cyan-300 dark:focus:ring-cyan-300"
                        />
                        <span className="min-w-0">
                          <span className="block font-bold">
                            {valueToString(plan.name) || "Untitled plan"}
                          </span>
                          <span className="mt-1 block text-xs font-semibold opacity-75">
                            {formatPlanLabel(plan)}
                          </span>
                        </span>
                      </label>
                    );
                  })
                ) : (
                  <div className="rounded-md bg-white/56 px-3 py-4 text-sm font-semibold text-slate-500 dark:bg-white/5 dark:text-slate-400">
                    No active plans available.
                  </div>
                )}
              </div>

              <p className="mt-2 text-xs font-semibold text-slate-500 dark:text-slate-400">
                {selectedPlanIds.length > 0
                  ? `${selectedPlanIds.length} plan${selectedPlanIds.length === 1 ? "" : "s"} selected.`
                  : "Choose one or more plans."}
              </p>
            </div>
          </div>

          <div className="mt-4 grid gap-4 border-t border-[rgba(15,23,42,0.08)] pt-4 lg:grid-cols-2 dark:border-white/8">
            <label className="block min-w-0">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                Start date{" "}
                <span className="text-xs text-slate-400">(optional)</span>
              </span>
              <input
                name="start_at"
                type="date"
                className={inputClassName}
              />
              <span className="mt-1.5 block text-xs font-semibold text-slate-500 dark:text-slate-400">
                Leave empty to start the subscription today.
              </span>
            </label>

            <label className="block min-w-0">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                End date{" "}
                <span className="text-xs text-slate-400">(optional)</span>
              </span>
              <input
                name="end_at"
                type="date"
                className={inputClassName}
              />
              <span className="mt-1.5 block text-xs font-semibold text-slate-500 dark:text-slate-400">
                Leave empty to calculate it from the selected plan duration.
              </span>
            </label>
          </div>
        </section>

        <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
          <div className="mb-5 flex items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
              <CreditCard className="size-4" />
            </span>
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
                Payment
              </p>
              <h2 className="text-lg font-bold text-slate-950 dark:text-white">
                Order details
              </h2>
            </div>
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <label className="block min-w-0">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                Transaction ID
              </span>
              <input
                name="transaction_id"
                type="text"
                placeholder="manual"
                defaultValue="manual"
                className={inputClassName}
              />
            </label>

            <label className="block min-w-0">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                Payment method
              </span>
              <input
                name="payment_method"
                type="text"
                placeholder="upi, card, cash"
                className={inputClassName}
              />
            </label>

            <label className="block min-w-0">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                Gateway
              </span>
              <input
                name="payment_gateway"
                type="text"
                placeholder="razorpay"
                defaultValue="razorpay"
                className={inputClassName}
              />
            </label>

            <label className="block min-w-0">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                Currency
              </span>
              <input
                name="currency"
                type="text"
                placeholder="INR"
                defaultValue="INR"
                className={inputClassName}
              />
            </label>

            <label className="block min-w-0">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                Amount override
              </span>
              <input
                name="amount"
                type="number"
                min={0}
                step="0.01"
                placeholder="Only used when API needs amount"
                className={inputClassName}
              />
            </label>
          </div>
        </section>

        <section className="liquid-glass rounded-lg p-4 text-sm leading-6 text-slate-700 shadow-[0_18px_54px_rgba(15,23,42,0.12)] dark:text-slate-200 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
          <div className="mb-3 flex items-center gap-3">
            <IndianRupee className="size-4 text-teal-700 dark:text-cyan-200" />
            <h2 className="font-bold text-slate-950 dark:text-white">
              Active subscription
            </h2>
          </div>
          This creates an active subscription, records payment history, and
          links the owner device for the selected plan device type.
        </section>
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
          <span>Create an active subscription and payment history.</span>
        </div>
        <button
          type="submit"
          disabled={
            isPending ||
            activePlans.length === 0 ||
            selectedPlanIds.length === 0
          }
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
              Add subscriber
            </>
          )}
        </button>
      </div>
    </form>
  );
}
