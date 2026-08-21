"use client";

import { ChevronDown, type LucideIcon } from "lucide-react";
import { useState } from "react";
import { cn } from "@/lib/utils";

type AdminFormSectionProps = {
  title: string;
  eyebrow: string;
  icon: LucideIcon;
  children: React.ReactNode;
  defaultOpen?: boolean;
};

export function AdminFormSection({
  title,
  eyebrow,
  icon: Icon,
  children,
  defaultOpen = false,
}: AdminFormSectionProps) {
  const [isOpen, setIsOpen] = useState(defaultOpen);

  return (
    <section className="liquid-glass relative overflow-hidden rounded-lg shadow-[0_18px_54px_rgba(15,23,42,0.12)] dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
      <button
        type="button"
        onClick={() => setIsOpen((current) => !current)}
        aria-expanded={isOpen}
        className={cn(
          "flex w-full items-center gap-3 p-4 text-left transition hover:bg-white/55 sm:p-5 dark:hover:bg-white/6",
          isOpen && "border-b border-slate-200/80 dark:border-white/10",
        )}
      >
        <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/75 text-teal-700 shadow-[inset_0_1px_0_rgba(255,255,255,0.85)] dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
          <Icon className="size-4" />
        </span>
        <span className="min-w-0 flex-1">
          <span className="block text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
            {eyebrow}
          </span>
          <span className="block text-lg font-bold text-slate-950 dark:text-white">
            {title}
          </span>
        </span>
        <span className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.12em] text-slate-600 dark:text-slate-300">
          <span className="hidden sm:inline">{isOpen ? "Close" : "Open"}</span>
          <ChevronDown
            className={cn(
              "size-5 transition-transform duration-200",
              isOpen && "rotate-180",
            )}
          />
        </span>
      </button>

      <div className={cn("p-4 sm:p-5", !isOpen && "hidden")}>{children}</div>
    </section>
  );
}
