"use client";
import {
  type ClipboardEvent,
  type KeyboardEvent,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import { useRouter } from "next/navigation";
import { toast } from "react-toastify";
import {
  extractAuthSession,
  persistAuthSession,
} from "@/app/features/auth/lib/client-session";
import { AUTH_TOKEN_CACHE_KEY } from "@/app/features/auth/lib/auth-token-cache";
import { setCacheItem } from "@/app/lib/cache-store";
import { OtpActionButton } from "./otp-action-button";
import FlipClockCountdown from "@leenguyen/react-flip-clock-countdown";
import "@leenguyen/react-flip-clock-countdown/dist/index.css";

type RequestOtpResponse = {
  status: "success" | "error";
  message: string;
  user_id?: string;
  WhatsApp_Status?: string;
  otp?: string | null;
};

type VerifyOtpResponse = {
  status: "success" | "error";
  message: string;
  data?: Record<string, unknown>;
};

export function WhatsAppOtpLogin() {
  const OTP_DURATION_SECONDS = 5 * 60;
  const router = useRouter();
  const [countryCode, setCountryCode] = useState("+91");
  const [phoneNumber, setPhoneNumber] = useState("");
  const [userId, setUserId] = useState("");
  const [otpDigits, setOtpDigits] = useState(Array(6).fill(""));
  const [otpRequested, setOtpRequested] = useState(false);
  const currentStep = otpRequested ? 2 : 1;
  const [isSending, setIsSending] = useState(false);
  const [isVerifying, setIsVerifying] = useState(false);
  const [errorMessage, setErrorMessage] = useState("");
  const [secondsLeft, setSecondsLeft] = useState(0);
  const otpInputRefs = useRef<Array<HTMLInputElement | null>>([]);
  const lastAutoVerifiedOtpRef = useRef("");
  const otp = otpDigits.join("");

  const normalizedPhone = useMemo(() => {
    const code = countryCode.replace(/\D/g, "");
    const number = phoneNumber.replace(/\D/g, "");
    return `${code}${number}`;
  }, [countryCode, phoneNumber]);
  const localPhoneNumber = useMemo(
    () => phoneNumber.replace(/\D/g, ""),
    [phoneNumber],
  );
  const createUid = () => {
    const time = Date.now().toString(36).slice(-6);
    const rand = Math.random().toString(36).slice(2, 8);
    return `w${time}${rand}`; // 13 chars max
  };

  const requestOtp = async () => {
    if (!localPhoneNumber) {
      toast.error("Enter phone number first.");
      return;
    }

    setErrorMessage("");

    const resolvedUserId = userId.trim() || createUid();
    if (!userId.trim()) {
      setUserId(resolvedUserId);
    }

    setIsSending(true);
    try {
      const response = await fetch("/api/auth/otp/request", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          phone_number: localPhoneNumber,
          country_code: countryCode,
          user_id: resolvedUserId,
        }),
      });

      const data = (await response.json()) as RequestOtpResponse;
      if (!response.ok || data.status !== "success") {
        throw new Error(data.message || "Failed to send OTP");
      }

      setOtpRequested(true);
      setOtpDigits(Array(6).fill(""));
      lastAutoVerifiedOtpRef.current = "";
      setSecondsLeft(OTP_DURATION_SECONDS);
      if (data.user_id) setUserId(data.user_id);
      toast.success("OTP sent to WhatsApp.");
    } catch (error) {
      setErrorMessage(
        error instanceof Error ? error.message : "Failed to send OTP",
      );
      toast.error(
        error instanceof Error ? error.message : "Failed to send OTP",
      );
    } finally {
      setIsSending(false);
    }
  };

  const verifyOtp = useCallback(async () => {
    if (!userId.trim() || !otp.trim()) {
      toast.error("User ID and OTP are required.");
      return;
    }

    setErrorMessage("");
    setIsVerifying(true);
    try {
      const response = await fetch("/api/auth/otp/verify", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          user_id: userId.trim(),
          otp: otp.trim(),
        }),
      });
      const data = (await response.json()) as VerifyOtpResponse;

      if (!response.ok || data.status !== "success") {
        throw new Error(data.message || "OTP verification failed");
      }

      const session = extractAuthSession(data.data);
      if (!session) {
        throw new Error("Login succeeded but session payload is missing");
      }

      if (data.data && typeof data.data === "object") {
        setCacheItem(AUTH_TOKEN_CACHE_KEY, data.data);
      }
      await persistAuthSession(session);
      toast.success("WhatsApp OTP login successful.");
      router.replace("/dashboard");
      router.refresh();
    } catch (error) {
      setErrorMessage(
        error instanceof Error ? error.message : "OTP verification failed",
      );
      toast.error(
        error instanceof Error ? error.message : "OTP verification failed",
      );
    } finally {
      setIsVerifying(false);
    }
  }, [otp, router, userId]);

  useEffect(() => {
    if (!otpRequested || isVerifying || !userId.trim()) return;
    if (!/^\d{6}$/.test(otp)) return;
    if (lastAutoVerifiedOtpRef.current === otp) return;

    lastAutoVerifiedOtpRef.current = otp;
    void verifyOtp();
  }, [isVerifying, otp, otpRequested, userId, verifyOtp]);

  const handleOtpDigitChange = (index: number, rawValue: string) => {
    const digits = rawValue.replace(/\D/g, "");
    if (digits.length > 1) {
      setOtpDigits((current) => {
        const next = [...current];
        digits
          .slice(0, 6 - index)
          .split("")
          .forEach((digit, offset) => {
            next[index + offset] = digit;
          });
        return next;
      });
      const nextFocusIndex = Math.min(index + digits.length, 5);
      otpInputRefs.current[nextFocusIndex]?.focus();
      return;
    }

    const digit = digits.slice(-1);
    setOtpDigits((current) => {
      const next = [...current];
      next[index] = digit;
      return next;
    });

    if (digit && index < 5) {
      otpInputRefs.current[index + 1]?.focus();
    }
  };

  const handleOtpKeyDown = (
    index: number,
    event: KeyboardEvent<HTMLInputElement>,
  ) => {
    if (event.key !== "Backspace") return;
    if (otpDigits[index]) return;
    if (index > 0) {
      otpInputRefs.current[index - 1]?.focus();
    }
  };

  const handleOtpPaste = (event: ClipboardEvent<HTMLInputElement>) => {
    event.preventDefault();
    const pasted = event.clipboardData
      .getData("text")
      .replace(/\D/g, "")
      .slice(0, 6);
    if (!pasted) return;
    setOtpDigits(() => {
      const next = Array(6).fill("");
      pasted.split("").forEach((digit, index) => {
        next[index] = digit;
      });
      return next;
    });
    const nextFocusIndex = Math.min(pasted.length, 5);
    otpInputRefs.current[nextFocusIndex]?.focus();
  };

  useEffect(() => {
    if (!otpRequested || secondsLeft <= 0) return;

    const timer = window.setInterval(() => {
      setSecondsLeft((current) => {
        if (current <= 1) {
          window.clearInterval(timer);
          return 0;
        }
        return current - 1;
      });
    }, 1000);

    return () => window.clearInterval(timer);
  }, [otpRequested, secondsLeft]);

  return (
    <div className="relative w-full">
      <div className="absolute top-0 left-1 sm:left-4 md:left-8 flex items-center gap-2">
        <div className="h-2 w-20 overflow-hidden rounded-full bg-slate-300/70 dark:bg-white/20">
          <div className="h-full w-full rounded-full bg-sky-500" />
        </div>
        <div className="h-2 w-20 overflow-hidden rounded-full bg-slate-300/70 dark:bg-white/20">
          <div
            className={`h-full rounded-full bg-sky-500 transition-all duration-300 ${
              currentStep === 2 ? "w-full" : "w-0"
            }`}
          />
        </div>
        <p className="text-[11px] font-semibold tracking-wide text-slate-800 dark:text-white/90">
          STEP {currentStep} OF 2
        </p>
      </div>

      {!otpRequested ? (
        <div className="flex flex-col justify-center pt-5 sm:p-7 sm:pt-8">
          <div className="flex flex-col gap-3">
            <h2 className="text-4xl font-bold text-slate-900 dark:text-white">
              OTP Login
            </h2>
            <p className="mt-2 text-sm text-slate-700 dark:text-white/80">
              Enter your WhatsApp number below. We&apos;ll send a 6-digit
              verification code to your WhatsApp for verification.
            </p>

            <div className="mt-6 space-y-4">
              <span className="text-sm font-semibold text-slate-700 dark:text-white/90">
                Phone Number
              </span>
              <div className="grid grid-cols-[110px_1fr] gap-2">
                <select
                  value={countryCode}
                  onChange={(event) => setCountryCode(event.target.value)}
                  className="rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-900 focus:border-sky-500 focus:outline-none dark:border-white/25 dark:bg-white/10 dark:text-white dark:focus:border-white/50"
                >
                  <option value="+91">🇮🇳 +91</option>
                  <option value="+1">🇺🇸 +1</option>
                  <option value="+44">🇬🇧 +44</option>
                  <option value="+61">🇦🇺 +61</option>
                  <option value="+971">🇦🇪 +971</option>
                  <option value="+65">🇸🇬 +65</option>
                  <option value="+852">🇭🇰 +852</option>
                  <option value="+886">🇹🇼 +886</option>
                  <option value="+81">🇯🇵 +81</option>
                  <option value="+82">🇰🇷 +82</option>
                  <option value="+49">🇩🇪 +49</option>
                  <option value="+33">🇫🇷 +33</option>
                  <option value="+55">🇧🇷 +55</option>
                  <option value="+27">🇿🇦 +27</option>
                </select>
                <input
                  type="tel"
                  inputMode="numeric"
                  value={phoneNumber}
                  onChange={(event) =>
                    setPhoneNumber(event.target.value.replace(/[^\d]/g, ""))
                  }
                  placeholder="Enter phone number"
                  className="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-base text-slate-900 placeholder:text-slate-400 focus:border-sky-500 focus:outline-none dark:border-white/25 dark:bg-white/10 dark:text-white dark:placeholder:text-white/45 dark:focus:border-white/50"
                />
              </div>

              <OtpActionButton
                onClick={() => void requestOtp()}
                disabled={isSending}
                isLoading={isSending}
                idleLabel="Send Verification Code"
                loadingLabel="Sending OTP..."
              />

              {errorMessage && (
                <p className="rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/40 dark:bg-rose-500/15 dark:text-rose-100">
                  {errorMessage}
                </p>
              )}
            </div>
          </div>
        </div>
      ) : (
        <div className="flex flex-col justify-center pt-5 sm:p-7 sm:pt-8">
          <h3 className="text-xl font-semibold text-slate-900 dark:text-white">
            Verify Code
          </h3>
          <p className="mt-2 text-sm text-slate-700 dark:text-white/75">
            We sent a 6-digit verification code to{" "}
            {phoneNumber ? `${countryCode} ${phoneNumber}` : normalizedPhone}.
          </p>

          <div className="mt-4 space-y-4">
            <div className="grid grid-cols-6 gap-4">
              {Array.from({ length: 6 }).map((_, index) => (
                <input
                  key={index}
                  ref={(element) => {
                    otpInputRefs.current[index] = element;
                  }}
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={1}
                  value={otpDigits[index] ?? ""}
                  onChange={(e) => handleOtpDigitChange(index, e.target.value)}
                  onKeyDown={(e) => handleOtpKeyDown(index, e)}
                  onPaste={handleOtpPaste}
                  className="h-12 w-12 rounded-xl border border-slate-300 bg-white text-center text-lg font-semibold text-slate-900 outline-none focus:border-sky-500 dark:border-white/25 dark:bg-white/10 dark:text-white dark:focus:border-white/50"
                />
              ))}
            </div>

            <OtpActionButton
              onClick={() => void verifyOtp()}
              disabled={isVerifying}
              isLoading={isVerifying}
              idleLabel="Verify & Process"
              loadingLabel="Verifying..."
            />

            <div className="flex items-center justify-center">
              {secondsLeft > 0 ? (
                <div className="flex flex-col items-center justify-center gap-1 text-center">
                  <p className="text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:text-white/50">
                    OTP expires in
                  </p>
                  <FlipClockCountdown
                    to={Date.now() + secondsLeft * 1000}
                    renderMap={[false, false, true, true]} // MM:SS
                    showLabels={false}
                    showSeparators
                    hideOnComplete={false}
                    onComplete={() => setSecondsLeft(0)}
                    digitBlockStyle={{ width: 24, height: 34, fontSize: 16 }}
                    separatorStyle={{ size: "4px" }}
                    duration={0.5}
                  />
                </div>
              ) : (
                <button
                  type="button"
                  onClick={() => void requestOtp()}
                  disabled={isSending || isVerifying}
                  className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/35 dark:bg-transparent dark:text-white dark:hover:bg-white/10"
                >
                  {isSending ? (
                    <>
                      <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-400 border-t-transparent dark:border-white/70 dark:border-t-transparent" />
                      Sending...
                    </>
                  ) : (
                    "Request Again"
                  )}
                </button>
              )}
            </div>

            {errorMessage && (
              <p className="rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:border-rose-500/40 dark:bg-rose-500/15 dark:text-rose-100">
                {errorMessage}
              </p>
            )}

            <button
              type="button"
              onClick={() => {
                setOtpRequested(false);
                setOtpDigits(Array(6).fill(""));
                lastAutoVerifiedOtpRef.current = "";
                setErrorMessage("");
                setSecondsLeft(0);
              }}
              className="w-full rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:border-white/30 dark:bg-transparent dark:text-white dark:hover:bg-white/10"
            >
              Change Number
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
