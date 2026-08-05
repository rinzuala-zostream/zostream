"use client";

import {
  AdminSidebarToggleButton,
  useAdminSidebar,
} from "@/app/components/admin-sidebar-shell";
import { ThemeToggle } from "@/app/components/theme-toggle";
import { cn } from "@/lib/utils";

type ThemeMode = "light" | "dark";

type AdminPageHeaderProps = {
  title: string;
  initialMode: ThemeMode;
  className?: string;
};

export function AdminPageHeader({
  title,
  initialMode,
  className,
}: AdminPageHeaderProps) {
  const { isDesktopSidebarOpen } = useAdminSidebar();

  return (
    <>
      <header
        className={cn(
          "fixed inset-x-0 top-0 z-30 mt-4 flex h-10 shrink-0 items-center justify-between gap-4 bg-(--app-bg) px-3 transition-[left] duration-300 ease-out sm:h-20 lg:px-2 xl:h-14",
          isDesktopSidebarOpen ? "md:left-64" : "md:left-14",
          className,
        )}
      >
        <div className="flex min-w-0 items-center gap-4">
          <AdminSidebarToggleButton className="shrink-0" />
          <h1 className="truncate text-3xl font-bold tracking-tight text-[#252525] sm:text-4xl xl:text-5xl dark:text-white">
            {title}
          </h1>
        </div>
        <ThemeToggle initialMode={initialMode} size="sm" />
      </header>
      <div aria-hidden="true" className="h-16 shrink-0 sm:h-20 xl:h-14" />
    </>
  );
}
