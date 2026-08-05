"use client";

import { MoonStar, SunMedium } from "lucide-react";
import { useEffect, useRef, useState } from "react";

type ThemeMode = "light" | "dark";

type ThemeToggleProps = {
  className?: string;
  showLabel?: boolean;
  size?: "sm" | "md" | "lg";
  initialMode?: ThemeMode;
};

const BUTTON_SIZES: Record<NonNullable<ThemeToggleProps["size"]>, string> = {
  sm: "h-10 w-20",
  md: "h-11 w-24",
  lg: "h-12 w-28",
};

const ICON_SIZES: Record<NonNullable<ThemeToggleProps["size"]>, string> = {
  sm: "h-4 w-4",
  md: "h-5 w-5",
  lg: "h-6 w-6",
};

const basePillClass =
  "pointer-events-none absolute left-1 top-1 h-[calc(100%-0.5rem)] w-[calc(50%-0.25rem)] rounded-full transition-transform duration-300 ease-out";

function getInitialMode(fallbackMode: ThemeMode): ThemeMode {
  if (typeof window === "undefined") return fallbackMode;

  try {
    const storedMode = window.localStorage.getItem("theme-mode");
    return storedMode === "light" || storedMode === "dark"
      ? storedMode
      : fallbackMode;
  } catch {
    return fallbackMode;
  }
}

export function ThemeToggle({
  className = "",
  showLabel = false,
  size = "md",
  initialMode = "light",
}: ThemeToggleProps) {
  const [mode, setMode] = useState<ThemeMode>(() =>
    getInitialMode(initialMode),
  );
  const [isBouncing, setIsBouncing] = useState(false);
  const bounceTimeoutRef = useRef<number | null>(null);

  useEffect(() => {
    const root = document.documentElement;
    root.classList.toggle("dark", mode === "dark");
    root.style.colorScheme = mode;

    try {
      localStorage.setItem("theme-mode", mode);
      document.cookie = `theme-mode=${mode}; path=/; max-age=31536000; samesite=lax`;
    } catch {
      // Ignore storage errors in private/restricted browsers.
    }
  }, [mode]);

  useEffect(() => {
    return () => {
      if (bounceTimeoutRef.current !== null) {
        window.clearTimeout(bounceTimeoutRef.current);
      }
    };
  }, []);

  const isLight = mode === "light";
  const nextMode: ThemeMode = isLight ? "dark" : "light";

  const handleToggle = () => {
    setIsBouncing(true);
    if (bounceTimeoutRef.current !== null) {
      window.clearTimeout(bounceTimeoutRef.current);
    }
    bounceTimeoutRef.current = window.setTimeout(() => {
      setIsBouncing(false);
      bounceTimeoutRef.current = null;
    }, 420);

    setMode((currentMode) => (currentMode === "light" ? "dark" : "light"));
  };

  return (
    <div
      className={[
        "inline-flex items-center gap-3 transition-transform duration-500",
        isBouncing ? "scale-[1.08] -rotate-1" : "scale-100 rotate-0",
        className,
      ].join(" ")}
    >
      <button
        type="button"
        onClick={handleToggle}
        aria-label={`Switch to ${nextMode} mode`}
        title={`Switch to ${nextMode} mode`}
        aria-pressed={mode === "dark"}
        className={[
          "relative inline-grid grid-cols-2 items-center rounded-full p-1.5",
          "touch-manipulation select-none",
          "overflow-hidden border border-white/70 bg-white/45 text-slate-700 backdrop-blur-2xl",
          "shadow-[inset_0_1px_0_rgba(255,255,255,0.8),inset_0_-1px_0_rgba(255,255,255,0.35),0_18px_35px_rgba(15,23,42,0.2)]",
          "dark:border-slate-300/25 dark:bg-slate-900/45 dark:text-slate-200",
          "dark:shadow-[inset_0_1px_0_rgba(255,255,255,0.18),inset_0_-1px_0_rgba(255,255,255,0.08),0_18px_35px_rgba(2,6,23,0.55)]",
          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 focus-visible:ring-offset-2",
          "focus-visible:ring-offset-slate-50 dark:focus-visible:ring-offset-slate-950",
          "transition-all duration-300",
          BUTTON_SIZES[size],
        ].join(" ")}
      >
        <span
          aria-hidden="true"
          className="pointer-events-none absolute inset-x-2 top-1 h-1/3 rounded-full bg-white/45 blur-[1px] dark:bg-white/10"
        />

        <span
          className={[
            basePillClass,
            isLight
              ? "border border-amber-100/80 bg-gradient-to-br from-yellow-200 via-amber-300 to-orange-500 shadow-[0_10px_24px_rgba(245,158,11,0.45),inset_0_1px_0_rgba(255,255,255,0.62)]"
              : "border border-indigo-100/45 bg-gradient-to-br from-indigo-400 via-blue-500 to-slate-800 shadow-[0_10px_24px_rgba(30,64,175,0.5),inset_0_1px_0_rgba(255,255,255,0.28)]",
            isLight ? "translate-x-full" : "translate-x-0",
          ].join(" ")}
        />

        <span className="relative z-10 grid place-items-center">
          <MoonStar
            className={`${ICON_SIZES[size]} transition-all duration-300 ${
              mode === "dark" ? "scale-100 text-white" : "scale-90 text-slate-500 dark:text-slate-400"
            }`}
            strokeWidth={2.25}
          />
        </span>

        <span className="relative z-10 grid place-items-center">
          <SunMedium
            className={`${ICON_SIZES[size]} transition-all duration-300 ${
              isLight ? "scale-100 text-white" : "scale-90 text-slate-500 dark:text-slate-400"
            }`}
            strokeWidth={2.25}
          />
        </span>
      </button>

      {showLabel && (
        <span className="text-sm font-semibold text-slate-700 dark:text-slate-300">
          {mode === "dark" ? "Dark mode" : "Light mode"}
        </span>
      )}
    </div>
  );
}
