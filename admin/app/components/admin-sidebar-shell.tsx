"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";
import {
  BadgePlus,
  ChevronDown,
  ChevronUp,
  Clapperboard,
  Eraser,
  FilePlus2,
  FileText,
  GalleryHorizontal,
  ImagePlus,
  Images,
  Layers3,
  LayoutGrid,
  List,
  ListVideo,
  PanelLeftClose,
  PanelLeftOpen,
  PencilLine,
  KeyRound,
  RefreshCcw,
  Search,
  Smartphone,
  SquarePen,
  type LucideIcon,
  UserMinus,
  UserPen,
  UserPlus,
  Users,
  X,
  TriangleAlert,
  Bell,
  Scroll,
  ShieldCheck,
  UsersRound,
  ChartArea,
  Crown,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { createContext, useContext } from "react";
import {
  filterSidebarItems,
  sidebarItemMatchesQuery,
} from "@/app/components/admin-sidebar-search";

type AdminSidebarShellProps = {
  children: React.ReactNode;
  defaultOpen?: boolean;
  uid: string;
};

type AdminSidebarContextValue = {
  isDesktopSidebarOpen: boolean;
  isMobileSidebarOpen: boolean;
  toggleSidebar: () => void;
  closeMobileSidebar: () => void;
};

type SidebarNavItem = {
  title: string;
  href: string;
  icon: LucideIcon;
};

type SidebarGroupConfig = {
  id: string;
  title: string;
  icon: LucideIcon;
  items: readonly SidebarNavItem[];
  maxHeightClass: string;
};

const topNavItems = [
  {
    title: "Dashboard",
    href: "/dashboard",
    icon: LayoutGrid,
    expandable: false,
  },
] as const;

const sidebarGroups: readonly SidebarGroupConfig[] = [
  {
    id: "movie",
    title: "Movie",
    icon: Clapperboard,
    items: [
      {
        title: "Add Movie",
        href: "/movies/add",
        icon: FilePlus2,
      },
      {
        title: "Edit Movie",
        href: "/movies/update",
        icon: PencilLine,
      },
    ],
    maxHeightClass: "max-h-[14rem]",
  },

  {
    id: "season",
    title: "Season",
    icon: Layers3,
    items: [
      {
        title: "Add Season",
        href: "/seasons/add",
        icon: BadgePlus,
      },

      {
        title: "Add Episode",
        href: "/seasons/episodes/add",
        icon: ListVideo,
      },
      {
        title: "Edit Season/Episode",
        href: "/seasons/update",
        icon: SquarePen,
      },
    ],
    maxHeightClass: "max-h-[14rem]",
  },
  {
    id: "subscription",
    title: "Subscription",
    icon: Crown,
    items: [
      {
        title: "Add Subscriber",
        href: "/subscriptions/subscribers/add",
        icon: UserPlus,
      },
      {
        title: "Subscriber List",
        href: "/subscriptions/subscribers",
        icon: Users,
      },
      {
        title: "Edit Subscriber",
        href: "/subscriptions/subscribers/edit",
        icon: UserPen,
      },
      {
        title: "Create Plan",
        href: "/subscriptions/plans/create",
        icon: FilePlus2,
      },
      {
        title: "Edit Plan",
        href: "/subscriptions/plans/edit",
        icon: PencilLine,
      },
    ],
    maxHeightClass: "max-h-[14rem]",
  },
  {
    id: "device",
    title: "Device",
    icon: Smartphone,
    items: [
      {
        title: "User Device List",
        href: "/devices/list",
        icon: List,
      },
      {
        title: "Clear Device",
        href: "/devices/clear",
        icon: Eraser,
      },
      {
        title: "Switch Device",
        href: "/devices/switch",
        icon: RefreshCcw,
      },
    ],
    maxHeightClass: "max-h-[11rem]",
  },
  {
    id: "verification",
    title: "Verification",
    icon: ShieldCheck,
    items: [
      {
        title: "Official Clients",
        href: "/verification/official-clients",
        icon: ShieldCheck,
      },
    ],
    maxHeightClass: "max-h-[6rem]",
  },
  {
    id: "user",
    title: "User",
    icon: Users,
    items: [
      {
        title: "Add User",
        href: "/users/add",
        icon: UserPlus,
      },
      {
        title: "Update User",
        href: "/users/update",
        icon: UserPen,
      },
      {
        title: "Shared Users",
        href: "/users/shared",
        icon: UsersRound,
      },
      {
        title: "Request OTP",
        href: "/users/request-otp",
        icon: KeyRound,
      },
      {
        title: "Suspend/Delete User",
        href: "/users/suspend-delete",
        icon: UserMinus,
      },
    ],
    maxHeightClass: "max-h-[12rem]",
  },
  {
    id: "banner",
    title: "Banner",
    icon: Images,
    items: [
      {
        title: "Add Banner",
        href: "/banners/add",
        icon: ImagePlus,
      },
      {
        title: "Edit Banner",
        href: "/banners/edit",
        icon: GalleryHorizontal,
      },
    ],
    maxHeightClass: "max-h-[8rem]",
  },
  {
    id: "legal",
    title: "Legal Content",
    icon: FileText,
    items: [
      {
        title: "Manage Legal Pages",
        href: "/legal/pages",
        icon: FileText,
      },
    ],
    maxHeightClass: "max-h-[6rem]",
  },
  {
    id: "Notification's Center",
    title: "Notification's Center",
    icon: Bell,
    items: [
      {
        title: "Push Notification",
        href: "/notifications/create",
        icon: Bell,
      },
      {
        title: "Add Scrolling",
        href: "/notifications/scrolling-text/add",
        icon: Scroll,
      },
      {
        title: "In-App Message",
        href: "/notifications/warning/add",
        icon: TriangleAlert,
      },
      {
        title: "App Update",
        href: "/notifications/app-update/manage",
        icon: RefreshCcw,
      },
    ],
    maxHeightClass: "max-h-[12rem]",
  },
  {
    id: "polls",
    title: "Polls",
    icon: ChartArea,
    items: [
      {
        title: "Create Poll",
        href: "/polls/create",
        icon: SquarePen,
      },
      {
        title: "Edit Results",
        href: "/polls/results",
        icon: PencilLine,
      },
      {
        title: "Voter List",
        href: "/polls/voters",
        icon: Users,
      },
    ],
    maxHeightClass: "max-h-[12rem]",
  },
] as const;

const AdminSidebarContext = createContext<AdminSidebarContextValue | null>(
  null,
);

export function useAdminSidebar() {
  const context = useContext(AdminSidebarContext);

  if (!context) {
    throw new Error("useAdminSidebar must be used within AdminSidebarShell.");
  }

  return context;
}

function sidebarTextClass(isDesktopSidebarOpen: boolean) {
  return cn(
    "min-w-0 max-w-52 whitespace-nowrap transition-[max-width,opacity,transform] duration-300 ease-out",
    isDesktopSidebarOpen
      ? "md:translate-x-0 md:opacity-100"
      : "md:max-w-0 md:-translate-x-1 md:overflow-hidden md:opacity-0",
  );
}

export function AdminSidebarToggleButton({
  className,
}: {
  className?: string;
}) {
  const { isDesktopSidebarOpen, isMobileSidebarOpen, toggleSidebar } =
    useAdminSidebar();

  const isOpen = isMobileSidebarOpen || isDesktopSidebarOpen;
  const Icon = isOpen ? PanelLeftClose : PanelLeftOpen;

  return (
    <button
      type="button"
      aria-label={isOpen ? "Close sidebar" : "Open sidebar"}
      className={cn(
        "liquid-glass-soft relative inline-flex size-11 items-center justify-center overflow-hidden rounded-2xl text-slate-700 shadow-[inset_0_1px_0_rgba(255,255,255,0.72),inset_0_-1px_0_rgba(148,163,184,0.16),0_12px_28px_rgba(15,23,42,0.12)] transition hover:-translate-y-0.5 hover:text-slate-950 hover:shadow-[inset_0_1px_0_rgba(255,255,255,0.82),inset_0_-1px_0_rgba(148,163,184,0.2),0_16px_34px_rgba(15,23,42,0.16)] dark:text-slate-200 dark:shadow-[inset_0_1px_0_rgba(255,255,255,0.14),inset_0_-1px_0_rgba(2,6,23,0.4),0_14px_34px_rgba(2,6,23,0.45)] dark:hover:text-white dark:hover:shadow-[inset_0_1px_0_rgba(255,255,255,0.18),inset_0_-1px_0_rgba(2,6,23,0.45),0_18px_42px_rgba(2,6,23,0.58)]",
        className,
      )}
      onClick={toggleSidebar}
    >
      <span
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-2 top-1 h-1/3 rounded-full bg-white/45 blur-[1px] dark:bg-white/10"
      />
      <Icon className="relative z-10 size-4" />
    </button>
  );
}

function SidebarExpandableGroup({
  group,
  isDesktopSidebarOpen,
  isMobileSidebarOpen,
  isOpen,
  pathname,
  closeMobileSidebar,
  onToggle,
}: {
  group: SidebarGroupConfig;
  isDesktopSidebarOpen: boolean;
  isMobileSidebarOpen: boolean;
  isOpen: boolean;
  pathname: string;
  closeMobileSidebar: () => void;
  onToggle: () => void;
}) {
  const GroupIcon = group.icon;
  const [isFlyoutDismissed, setIsFlyoutDismissed] = useState(false);
  const isSidebarExpanded = isDesktopSidebarOpen || isMobileSidebarOpen;

  return (
    <div
      className="group/sidebar-flyout relative pt-1"
      onMouseEnter={() => setIsFlyoutDismissed(false)}
      onMouseLeave={() => setIsFlyoutDismissed(false)}
    >
      <button
        type="button"
        onClick={() => {
          if (!isSidebarExpanded) return;
          onToggle();
        }}
        aria-expanded={isSidebarExpanded ? isOpen : undefined}
        className={cn(
          "flex h-11 w-full items-center gap-3 rounded-xl text-left text-sm font-semibold text-slate-900 transition duration-200 ease-out hover:bg-white/60 dark:text-white dark:hover:bg-white/8",
          isSidebarExpanded
            ? "px-3"
            : "justify-center px-0 md:mx-auto md:size-10 md:gap-0 md:rounded-md",
        )}
      >
        <GroupIcon className="size-4 shrink-0" />
        <span className={sidebarTextClass(isSidebarExpanded)}>
          {group.title}
        </span>
        {isSidebarExpanded ? (
          isOpen ? (
            <ChevronUp className="ml-auto size-4 text-slate-500" />
          ) : (
            <ChevronDown className="ml-auto size-4 text-slate-500" />
          )
        ) : null}
      </button>

      {!isDesktopSidebarOpen && !isFlyoutDismissed ? (
        <div className="pointer-events-none absolute left-full top-1 z-50 hidden translate-x-1 opacity-0 will-change-[opacity,transform] transition-[opacity,transform,visibility] duration-100 ease-out md:invisible md:block md:group-hover/sidebar-flyout:pointer-events-auto md:group-hover/sidebar-flyout:visible md:group-hover/sidebar-flyout:translate-x-0 md:group-hover/sidebar-flyout:opacity-100 md:group-focus-within/sidebar-flyout:pointer-events-auto md:group-focus-within/sidebar-flyout:visible md:group-focus-within/sidebar-flyout:translate-x-0 md:group-focus-within/sidebar-flyout:opacity-100">
          <div className="ml-2 w-60 rounded-lg border border-slate-200 bg-white p-2 shadow-[0_18px_52px_rgba(15,23,42,0.24)] dark:border-slate-800 dark:bg-slate-950 dark:shadow-[0_18px_56px_rgba(2,6,23,0.68)]">
            <p className="px-3 pb-2 pt-1 text-xs font-semibold uppercase tracking-normal text-slate-500 dark:text-white/45">
              {group.title}
            </p>
            <div className="space-y-1">
              {group.items.map((item) => {
                const ItemIcon = item.icon;
                const isActive = pathname === item.href;

                return (
                  <Link
                    key={item.title}
                    href={item.href}
                    onClick={() => {
                      closeMobileSidebar();
                      setIsFlyoutDismissed(true);
                    }}
                    className={cn(
                      "flex h-10 items-center gap-3 rounded-md px-3 text-sm font-medium transition duration-100 ease-out",
                      isActive
                        ? "bg-slate-950 text-white shadow-[0_10px_28px_rgba(15,23,42,0.18)] dark:bg-white dark:text-slate-950 dark:shadow-none"
                        : "text-slate-800 hover:bg-slate-950/[0.06] hover:text-slate-950 dark:text-white/72 dark:hover:bg-white/10 dark:hover:text-white",
                    )}
                  >
                    <ItemIcon className="size-4 shrink-0" />
                    <span className="truncate">{item.title}</span>
                  </Link>
                );
              })}
            </div>
          </div>
        </div>
      ) : null}

      <div
        className={cn(
          "mt-2 overflow-hidden transition-all",
          isSidebarExpanded && isOpen
            ? `${group.maxHeightClass} opacity-100 duration-300 ease-out`
            : "max-h-0 opacity-0 md:hidden",
        )}
      >
        <div className="ml-3 border-l border-black/8 pl-4">
          {group.items.map((item) => {
            const ItemIcon = item.icon;
            const isActive = pathname === item.href;

            return (
              <Link
                key={item.title}
                href={item.href}
                onClick={closeMobileSidebar}
                className={cn(
                  "flex h-9 items-center gap-3 rounded-2xl px-4 text-sm transition duration-100 ease-out",
                  isActive
                    ? "bg-white text-slate-900 shadow-[0_1px_0_rgba(15,23,42,0.06),0_10px_30px_rgba(15,23,42,0.05)] dark:bg-white/10 dark:text-white dark:shadow-none"
                    : "text-slate-800 hover:bg-white/60 hover:text-slate-900 dark:text-white/65 dark:hover:bg-white/8 dark:hover:text-white",
                )}
              >
                <ItemIcon className="size-4 shrink-0" />
                <span className={sidebarTextClass(isSidebarExpanded)}>
                  {item.title}
                </span>
              </Link>
            );
          })}
        </div>
      </div>
    </div>
  );
}

export function AdminSidebarShell({
  children,
  defaultOpen = true,
  uid,
}: AdminSidebarShellProps) {
  const pathname = usePathname();
  const [isDesktopSidebarOpen, setIsDesktopSidebarOpen] = useState(defaultOpen);
  const [isMobileSidebarOpen, setIsMobileSidebarOpen] = useState(false);
  const [sidebarSearchQuery, setSidebarSearchQuery] = useState("");
  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>(() =>
    Object.fromEntries(sidebarGroups.map((group) => [group.id, false])),
  );
  const effectiveSidebarSearchQuery =
    isDesktopSidebarOpen || isMobileSidebarOpen ? sidebarSearchQuery : "";
  const isSidebarSearchActive = effectiveSidebarSearchQuery.trim().length > 0;
  const filteredTopNavItems = filterSidebarItems(
    topNavItems,
    effectiveSidebarSearchQuery,
    ["home", "overview", "main"],
  );
  const filteredSidebarGroups = sidebarGroups
    .map((group) => {
      const isGroupMatch = sidebarItemMatchesQuery(
        { title: group.title, href: group.id },
        effectiveSidebarSearchQuery,
      );

      return {
        ...group,
        items: isGroupMatch
          ? group.items
          : filterSidebarItems(group.items, effectiveSidebarSearchQuery, [
              group.title,
              group.id,
            ]),
      };
    })
    .filter(
      (group) => !isSidebarSearchActive || group.items.length > 0,
    ) as SidebarGroupConfig[];
  const hasSidebarSearchResults =
    filteredTopNavItems.length > 0 || filteredSidebarGroups.length > 0;
  const isSidebarContentExpanded = isDesktopSidebarOpen || isMobileSidebarOpen;

  useEffect(() => {
    document.cookie = `sidebar_state=${isDesktopSidebarOpen}; path=/; max-age=${60 * 60 * 24 * 7}`;
  }, [isDesktopSidebarOpen]);

  useEffect(() => {
    if (!isDesktopSidebarOpen && !isMobileSidebarOpen) {
      setSidebarSearchQuery("");
    }
  }, [isDesktopSidebarOpen, isMobileSidebarOpen]);

  useEffect(() => {
    const closeOnDesktop = () => {
      if (window.innerWidth >= 768) {
        setIsMobileSidebarOpen(false);
      }
    };

    closeOnDesktop();
    window.addEventListener("resize", closeOnDesktop);
    return () => window.removeEventListener("resize", closeOnDesktop);
  }, []);

  const toggleSidebar = () => {
    if (window.innerWidth < 768) {
      setIsMobileSidebarOpen((current) => !current);
      return;
    }

    setIsDesktopSidebarOpen((current) => !current);
  };

  const closeMobileSidebar = () => {
    setIsMobileSidebarOpen(false);
  };

  return (
    <AdminSidebarContext.Provider
      value={{
        isDesktopSidebarOpen,
        isMobileSidebarOpen,
        toggleSidebar,
        closeMobileSidebar,
      }}
    >
      <div className="admin-shell flex min-h-svh w-full">
        {isMobileSidebarOpen ? (
          <button
            type="button"
            aria-label="Close sidebar"
            className="fixed inset-0 z-[80] bg-slate-950/55 backdrop-blur-sm md:hidden"
            onClick={closeMobileSidebar}
          />
        ) : null}

        <aside
          className={cn(
            "fixed inset-y-0 left-0 z-[90] flex w-72 flex-col bg-(--app-bg) transition-[width,transform] duration-300 ease-out md:z-40 md:translate-x-0",
            isMobileSidebarOpen ? "translate-x-0" : "-translate-x-full",
            isDesktopSidebarOpen ? "md:w-64" : "md:w-14",
          )}
        >
          <div className="flex items-center justify-between px-3 pt-3">
            <Link
              href="/dashboard"
              className={cn(
                "flex min-w-0 items-center gap-3",
                !isSidebarContentExpanded &&
                  "md:w-full md:justify-center md:gap-0",
              )}
              onClick={closeMobileSidebar}
            >
              <div className="relative size-7 overflow-hidden rounded-sm">
                <Image
                  src="/logo/logo.png"
                  alt="Zo Stream logo"
                  fill
                  className="object-cover"
                  sizes="40px"
                />
              </div>
              <div className={sidebarTextClass(isSidebarContentExpanded)}>
                <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                  Zo Stream
                </p>
                <p className="truncate text-xs text-slate-500 dark:text-white/55">
                  Admin workspace
                </p>
              </div>
            </Link>

            <button
              type="button"
              className="inline-flex size-9 items-center justify-center rounded-md text-slate-600 hover:bg-slate-200/80 hover:text-slate-900 dark:text-white/70 dark:hover:bg-white/10 dark:hover:text-white md:hidden"
              onClick={closeMobileSidebar}
            >
              <X className="size-4" />
            </button>
          </div>

          <div
            className={cn(
              "admin-sidebar-scroll flex-1 pb-4 pt-2 transition-[padding] duration-300 ease-out",
              isSidebarContentExpanded
                ? "overflow-y-auto px-3"
                : "overflow-y-auto px-2 md:overflow-visible",
            )}
          >
            <nav className="mt-1 flex flex-col">
              <label
                className={cn(
                  "mb-2 block min-w-0",
                  !isSidebarContentExpanded && "md:hidden",
                )}
              >
                <span className="sr-only">Search sidebar tabs</span>
                <span className="flex h-10 items-center gap-2 rounded-md border border-black/8 bg-white/55 px-3 text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition focus-within:border-teal-300 focus-within:bg-white/80 focus-within:ring-4 focus-within:ring-teal-200/35 dark:border-white/10 dark:bg-white/6 dark:text-white/72 dark:focus-within:border-cyan-300/50 dark:focus-within:bg-white/10 dark:focus-within:ring-cyan-300/15">
                  <Search className="size-4 shrink-0 text-teal-700 dark:text-cyan-200" />
                  <input
                    type="search"
                    value={sidebarSearchQuery}
                    onChange={(event) =>
                      setSidebarSearchQuery(event.target.value)
                    }
                    placeholder="Search tabs"
                    className="min-w-0 flex-1 bg-transparent text-sm font-semibold text-slate-950 outline-none placeholder:text-slate-400 dark:text-white dark:placeholder:text-slate-500"
                  />
                </span>
              </label>

              {filteredTopNavItems.slice(0, 1).map((item) => {
                const Icon = item.icon;
                const isActive = pathname === item.href;

                return (
                  <Link
                    key={item.title}
                    href={item.href}
                    title={item.title}
                    onClick={closeMobileSidebar}
                    className={cn(
                      "flex h-11 items-center gap-3 rounded-xl text-sm font-medium transition duration-200 ease-out",
                      isActive
                        ? "bg-white/75 text-slate-900 dark:bg-white/8 dark:text-white"
                        : "text-slate-800 hover:bg-white/60 hover:text-slate-900 dark:text-white/72 dark:hover:bg-white/8 dark:hover:text-white",
                      isSidebarContentExpanded
                        ? "px-3"
                        : "justify-center px-0 md:mx-auto md:size-10 md:gap-0 md:rounded-md",
                    )}
                  >
                    <Icon className="size-4 shrink-0" />
                    <span
                      className={sidebarTextClass(isSidebarContentExpanded)}
                    >
                      {item.title}
                    </span>
                  </Link>
                );
              })}

              {filteredSidebarGroups.map((group) => (
                <SidebarExpandableGroup
                  key={group.id}
                  group={group}
                  isDesktopSidebarOpen={isDesktopSidebarOpen}
                  isMobileSidebarOpen={isMobileSidebarOpen}
                  isOpen={
                    isSidebarSearchActive || (openGroups[group.id] ?? false)
                  }
                  pathname={pathname}
                  closeMobileSidebar={closeMobileSidebar}
                  onToggle={() =>
                    setOpenGroups((current) => ({
                      ...current,
                      [group.id]: !(current[group.id] ?? false),
                    }))
                  }
                />
              ))}

              {isSidebarSearchActive && !hasSidebarSearchResults ? (
                <div
                  className={cn(
                    "rounded-md px-3 py-3 text-sm font-semibold text-slate-500 dark:text-white/45",
                    !isSidebarContentExpanded && "md:hidden",
                  )}
                >
                  No matching tabs
                </div>
              ) : null}

              {topNavItems.slice(1).map((item) => {
                const Icon = item.icon;

                return (
                  <Link
                    key={item.title}
                    href={item.href}
                    title={item.title}
                    onClick={closeMobileSidebar}
                    className={cn(
                      "flex h-11 items-center gap-3 rounded-xl text-sm font-medium text-slate-500 transition duration-200 ease-out hover:bg-white/60 hover:text-slate-900 dark:text-white/72 dark:hover:bg-white/8 dark:hover:text-white",
                      isSidebarContentExpanded
                        ? "px-3"
                        : "justify-center px-0 md:mx-auto md:size-10 md:gap-0 md:rounded-md",
                    )}
                  >
                    <Icon className="size-4 shrink-0" />
                    <span
                      className={sidebarTextClass(isSidebarContentExpanded)}
                    >
                      {item.title}
                    </span>
                    {isSidebarContentExpanded && item.expandable ? (
                      <ChevronDown className="ml-auto size-4 text-slate-400" />
                    ) : null}
                  </Link>
                );
              })}
            </nav>
          </div>

          <div
            className={cn(
              "mt-auto border-t border-black/6 py-4 dark:border-white/10",
              isSidebarContentExpanded ? "px-3" : "px-2",
            )}
          >
            <Link
              href="/profile"
              title="Profile"
              onClick={closeMobileSidebar}
              className={cn(
                "flex min-h-12 items-center gap-3 rounded-2xl border border-black/6 bg-white/65 text-slate-900 transition hover:bg-white dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:bg-white/8",
                isSidebarContentExpanded
                  ? "px-3 py-3"
                  : "justify-center px-0 py-2 md:mx-auto md:min-h-10 md:w-10 md:gap-0 md:rounded-xl md:border-0 md:bg-transparent dark:md:bg-transparent",
              )}
            >
              <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-[#232323] text-sm font-semibold text-white dark:bg-white dark:text-slate-900">
                {uid.slice(0, 1).toUpperCase()}
              </div>
              <div className={sidebarTextClass(isSidebarContentExpanded)}>
                <p className="text-sm font-semibold">Profile</p>
                <p className="truncate text-xs text-slate-500 dark:text-white/55">
                  {uid}
                </p>
              </div>
            </Link>
          </div>
        </aside>

        <div
          className={cn(
            "flex min-h-svh min-w-0 flex-1 flex-col transition-[padding-left] duration-300 ease-out",
            isDesktopSidebarOpen ? "md:pl-64" : "md:pl-14",
          )}
        >
          <div className="flex flex-1 flex-col">{children}</div>
        </div>
      </div>
    </AdminSidebarContext.Provider>
  );
}
