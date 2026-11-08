"use client";

import { useActionState, useEffect, useMemo, useRef, useState } from "react";
import { Clipboard, KeyRound, Loader2, Search, Send, X } from "lucide-react";
import { toast } from "react-toastify";
import {
  requestOtpAction,
  type RequestOtpFormState,
} from "@/app/(admin)/users/request-otp/actions";
import { PasteUIDButton } from "@/app/features/users/components/uid-clipboard";
import { cn } from "@/lib/utils";
import type { UserItem } from "@/app/features/users/services/user-service";

const initialState: RequestOtpFormState = {
  status: "idle",
  message: "",
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";

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

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

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

function resolveUserPhone(user: UserSearchResult) {
  return valueToString(user.auth_phone) || valueToString(user.call);
}

function formatUserLabel(user: UserSearchResult) {
  return valueToString(user.name) || resolveUserId(user) || "Unknown user";
}

function formatUserMeta(user: UserSearchResult) {
  return [
    valueToString(user.uid) ? `UID ${valueToString(user.uid)}` : "",
    valueToString(user.mail) ? `Mail ${valueToString(user.mail)}` : "",
    resolveUserPhone(user) ? `Phone ${resolveUserPhone(user)}` : "",
  ]
    .filter(Boolean)
    .join(" · ");
}

function responseText(response: unknown) {
  if (response === undefined) return "";
  return JSON.stringify(response, null, 2);
}

async function copyText(value: string, label: string) {
  if (!value) return;

  try {
    await navigator.clipboard.writeText(value);
    toast.success(`${label} copied.`);
  } catch {
    toast.error(`${label} could not be copied.`);
  }
}

export function RequestOtpForm() {
  const userSearchWrapRef = useRef<HTMLDivElement>(null);
  const userSearchRequestRef = useRef(0);
  const userIdInputRef = useRef<HTMLInputElement>(null);
  const [state, formAction, isPending] = useActionState(
    requestOtpAction,
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
  const [phoneNumberValue, setPhoneNumberValue] = useState("");
  const [lastCopiedKey, setLastCopiedKey] = useState("");

  const formattedResponse = useMemo(
    () => responseText(state.response),
    [state.response],
  );

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

  const copyOtp = async () => {
    const value = state.otp ? String(state.otp) : "";
    await copyText(value, "OTP");
    setLastCopiedKey(`otp-${state.resetKey ?? value}`);
  };

  const copyResponse = async () => {
    await copyText(formattedResponse, "Response");
    setLastCopiedKey(`response-${state.resetKey ?? formattedResponse.length}`);
  };

  const clearSelectedUser = () => {
    setSelectedUser(null);
    setUserIdValue("");
    setPhoneNumberValue("");
    setUserSearchQuery("");
    setUserSearchResults([]);
    setUserSearchStatus("idle");
    setUserSearchMessage("Search by UID, name, email, or phone.");
    setIsUserSearchOpen(false);
    userIdInputRef.current?.focus();
  };

  return (
    <div className="space-y-4 pb-28">
      <form action={formAction} className="liquid-glass rounded-lg p-4 sm:p-5">
        <div className="mb-5 flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
            <KeyRound className="size-4" />
          </span>
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              OTP
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Request OTP
            </h2>
          </div>
        </div>

        <div className="mb-4" ref={userSearchWrapRef}>
          <div className="relative min-w-0">
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
                      const phone = resolveUserPhone(user);
                      const label = formatUserLabel(user);

                      return (
                        <button
                          key={uid || phone || label}
                          type="button"
                          onMouseDown={(event) => event.preventDefault()}
                          onClick={() => {
                            setSelectedUser(user);
                            setUserIdValue(uid);
                            setPhoneNumberValue(phone);
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
                          className="flex w-full flex-col items-start gap-1 rounded-md px-3 py-3 text-left transition hover:bg-teal-50/90 dark:hover:bg-white/8"
                        >
                          <span className="truncate text-sm font-bold text-slate-950 dark:text-white">
                            {label}
                          </span>
                          <span className="truncate text-xs font-semibold text-slate-500 dark:text-slate-400">
                            {formatUserMeta(user) ||
                              "Select this user to autofill UID and phone"}
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
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <label className="block min-w-0">
            <div className="flex items-center justify-between">
              <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                User ID
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
              placeholder="Existing UID"
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Phone number
            </span>
            <input
              name="phone_number"
              type="tel"
              value={phoneNumberValue}
              onChange={(event) => {
                const nextValue = event.target.value;
                setPhoneNumberValue(nextValue);

                if (
                  selectedUser &&
                  nextValue.trim() !== resolveUserPhone(selectedUser)
                ) {
                  setSelectedUser(null);
                }
              }}
              placeholder="Required when user does not exist"
              className={inputClassName}
            />
          </label>
        </div>

        {selectedUser ? (
          <div className="mt-4 rounded-md border border-teal-200 bg-teal-50/80 px-4 py-3 text-sm font-semibold text-teal-800 dark:border-cyan-300/25 dark:bg-cyan-300/10 dark:text-cyan-100">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="text-xs uppercase tracking-[0.18em] text-teal-600 dark:text-cyan-200">
                  Selected user
                </p>
                <p className="mt-1 truncate text-sm font-bold">
                  {formatUserLabel(selectedUser)}
                </p>
                <p className="mt-1 truncate text-xs font-semibold opacity-80">
                  {formatUserMeta(selectedUser) || "UID and phone will be used"}
                </p>
              </div>
              <button
                type="button"
                onClick={clearSelectedUser}
                className="inline-flex h-8 items-center justify-center rounded-md border border-current/20 px-3 text-xs font-bold transition hover:bg-white/20 dark:hover:bg-white/10"
              >
                <X className="mr-1 size-3.5" />
                Clear
              </button>
            </div>
          </div>
        ) : null}

        <div className="mt-5 flex flex-wrap items-center gap-3">
          <button
            type="submit"
            disabled={isPending}
            className="inline-flex h-11 items-center justify-center gap-2 rounded-md bg-teal-700 px-4 text-sm font-bold text-white shadow-[0_10px_24px_rgba(13,148,136,0.22)] transition hover:bg-teal-600 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-cyan-500 dark:text-slate-950 dark:hover:bg-cyan-400"
          >
            {isPending ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <Send className="size-4" />
            )}
            {isPending ? "Requesting" : "Request OTP"}
          </button>

          {state.status !== "idle" ? (
            <span
              className={cn(
                "rounded-md border px-3 py-2 text-sm font-semibold",
                state.status === "success"
                  ? "border-teal-200 bg-teal-50/90 text-teal-800 dark:border-cyan-300/25 dark:bg-cyan-300/10 dark:text-cyan-100"
                  : "border-rose-200 bg-rose-50/90 text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100",
              )}
            >
              {state.message}
            </span>
          ) : null}
        </div>
      </form>

      {state.status !== "idle" ? (
        <section className="liquid-glass rounded-lg p-4 sm:p-5">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
                Response
              </p>
              <h2 className="mt-1 text-lg font-bold text-slate-950 dark:text-white">
                API result
              </h2>
            </div>

            <div className="flex flex-wrap gap-2">
              {state.otp ? (
                <button
                  type="button"
                  onClick={copyOtp}
                  className="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-teal-200 bg-white/70 px-3 text-sm font-bold text-teal-800 transition hover:bg-teal-50 dark:border-cyan-300/20 dark:bg-white/8 dark:text-cyan-100 dark:hover:bg-white/12"
                >
                  <Clipboard className="size-4" />
                  Copy OTP
                </button>
              ) : null}

              {formattedResponse ? (
                <button
                  type="button"
                  onClick={copyResponse}
                  className="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white/70 px-3 text-sm font-bold text-slate-800 transition hover:bg-slate-50 dark:border-white/10 dark:bg-white/8 dark:text-slate-100 dark:hover:bg-white/12"
                >
                  <Clipboard className="size-4" />
                  Copy JSON
                </button>
              ) : null}
            </div>
          </div>

          {state.otp ? (
            <div className="mt-4 rounded-md border border-teal-200 bg-teal-50/90 px-4 py-3 text-sm text-teal-900 dark:border-cyan-300/20 dark:bg-cyan-300/10 dark:text-cyan-50">
              <span className="font-semibold">OTP:</span>{" "}
              <span className="font-mono text-base font-bold">{state.otp}</span>
              {lastCopiedKey.startsWith("otp-") ? (
                <span className="ml-2 text-xs font-semibold">Copied</span>
              ) : null}
            </div>
          ) : null}

          {formattedResponse ? (
            <pre className="mt-4 max-h-[28rem] overflow-auto rounded-md border border-[rgba(15,23,42,0.12)] bg-slate-950 p-4 text-xs leading-6 text-slate-50 dark:border-white/10">
              {formattedResponse}
            </pre>
          ) : (
            <p className="mt-4 text-sm text-slate-600 dark:text-slate-300">
              No response body was returned.
            </p>
          )}
        </section>
      ) : null}
    </div>
  );
}
