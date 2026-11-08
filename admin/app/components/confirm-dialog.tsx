"use client";

import { AlertTriangle, X } from "lucide-react";
import { cn } from "@/lib/utils";

type ConfirmDialogProps = {
  open: boolean;
  title: string;
  description: string;
  confirmLabel?: string;
  cancelLabel?: string;
  isPending?: boolean;
  variant?: "danger" | "default";
  onConfirm: () => void;
  onClose: () => void;
};

export function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel = "Confirm",
  cancelLabel = "Cancel",
  isPending,
  variant = "default",
  onConfirm,
  onClose,
}: ConfirmDialogProps) {
  if (!open) return null;

  const isDanger = variant === "danger";

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/62 p-4 backdrop-blur-sm"
      role="dialog"
      aria-modal="true"
      aria-labelledby="confirm-dialog-title"
    >
      <div className="liquid-glass relative w-full max-w-md overflow-hidden rounded-lg p-4 shadow-[0_28px_80px_rgba(2,6,23,0.36)] sm:p-5 dark:shadow-[0_30px_90px_rgba(2,6,23,0.7)]">
        <button
          type="button"
          onClick={onClose}
          disabled={isPending}
          className="absolute right-3 top-3 inline-flex size-9 items-center justify-center rounded-md text-slate-500 transition hover:bg-white/60 hover:text-slate-950 disabled:cursor-not-allowed disabled:opacity-50 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white"
          aria-label="Close confirmation"
        >
          <X className="size-4" />
        </button>

        <div className="flex gap-3 pr-8">
          <span
            className={cn(
              "flex size-11 shrink-0 items-center justify-center rounded-md",
              isDanger
                ? "bg-rose-100 text-rose-700 dark:bg-rose-300/12 dark:text-rose-100"
                : "bg-teal-100 text-teal-700 dark:bg-cyan-300/12 dark:text-cyan-100",
            )}
          >
            <AlertTriangle className="size-5" />
          </span>
          <div className="min-w-0">
            <h2
              id="confirm-dialog-title"
              className="text-lg font-bold text-slate-950 dark:text-white"
            >
              {title}
            </h2>
            <p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">
              {description}
            </p>
          </div>
        </div>

        <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button
            type="button"
            onClick={onClose}
            disabled={isPending}
            className="inline-flex min-h-10 items-center justify-center rounded-md border border-[rgba(15,23,42,0.14)] bg-white/62 px-4 text-sm font-bold text-slate-700 transition hover:bg-white disabled:cursor-not-allowed disabled:opacity-60 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12"
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={isPending}
            className={cn(
              "inline-flex min-h-10 items-center justify-center rounded-md px-4 text-sm font-bold text-white transition disabled:cursor-not-allowed disabled:opacity-65",
              isDanger
                ? "bg-rose-600 hover:bg-rose-700 dark:bg-rose-500 dark:hover:bg-rose-400"
                : "bg-slate-950 hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200",
            )}
          >
            {isPending ? "Working..." : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
