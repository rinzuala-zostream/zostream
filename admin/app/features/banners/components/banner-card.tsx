"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import {
  CalendarDays,
  ExternalLink,
  GalleryHorizontal,
  PencilLine,
  Trash2,
} from "lucide-react";
import { toast } from "react-toastify";
import { deleteBannerAction } from "@/app/(admin)/banners/edit/[id]/actions";
import { ConfirmDialog } from "@/app/components/confirm-dialog";
import { cn } from "@/lib/utils";
import type { BannerItem } from "@/app/features/banners/services/banner-service";

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

function valueToBoolean(value: unknown) {
  if (typeof value === "boolean") return value;
  if (typeof value === "number") return value === 1;
  if (typeof value === "string") {
    return ["1", "true", "yes", "active"].includes(value.trim().toLowerCase());
  }

  return false;
}

function formatDate(value: unknown) {
  const text = valueToString(value);
  if (!text) return "Always";

  const date = new Date(text);
  if (Number.isNaN(date.getTime())) return text;

  return new Intl.DateTimeFormat("en-IN", {
    year: "numeric",
    month: "short",
    day: "numeric",
  }).format(date);
}

function mediaPreview(banner: BannerItem) {
  return valueToString(banner.thumbnail_url) || valueToString(banner.media_url);
}

type BannerCardProps = {
  banner: BannerItem;
};

export function BannerCard({ banner }: BannerCardProps) {
  const router = useRouter();
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [isDeletePending, startDeleteTransition] = useTransition();
  const bannerId = valueToString(banner.id);
  const preview = mediaPreview(banner);
  const active = valueToBoolean(banner.is_active);
  const bannerTitle = valueToString(banner.title) || "this banner";

  const deleteBanner = () => {
    if (!bannerId) return;

    startDeleteTransition(async () => {
      const result = await deleteBannerAction(bannerId);

      if (result.status === "success") {
        toast.success(result.message || "Banner deleted.");
        setIsDeleteDialogOpen(false);
        router.refresh();
        return;
      }

      toast.error(result.message || "Banner could not be deleted.");
    });
  };

  return (
    <>
      <article className="liquid-glass flex min-h-[26rem] flex-col overflow-hidden rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
        <div className="relative overflow-hidden rounded-lg bg-slate-100 dark:bg-white/8">
          <div className="aspect-[16/9] w-full">
            {preview ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={preview}
                alt={valueToString(banner.title) || "Banner preview"}
                className="h-full w-full object-cover"
              />
            ) : (
              <div className="flex h-full w-full items-center justify-center text-slate-400 dark:text-slate-500">
                <GalleryHorizontal className="size-8" />
              </div>
            )}
          </div>
          <span
            className={cn(
              "absolute left-3 top-3 inline-flex min-w-20 items-center justify-center rounded-md px-2.5 py-1 text-xs font-bold backdrop-blur-sm",
              active
                ? "bg-teal-100/95 text-teal-700 dark:bg-cyan-300/20 dark:text-cyan-100"
                : "bg-slate-200/95 text-slate-600 dark:bg-slate-900/70 dark:text-slate-300",
            )}
          >
            {active ? "Active" : "Inactive"}
          </span>
        </div>

        <div className="mt-4 flex min-w-0 items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              {valueToString(banner.type) || "Custom"}
            </p>
            <h2 className="mt-2 truncate text-xl font-bold text-slate-950 dark:text-white">
              {valueToString(banner.title) || "Untitled banner"}
            </h2>
            <p className="mt-1 text-xs font-semibold text-slate-500 dark:text-slate-400">
              ID {bannerId || "unknown"}
            </p>
          </div>
          <span className="rounded-md bg-white/55 px-2.5 py-1 text-xs font-bold text-slate-700 dark:bg-white/8 dark:text-slate-200">
            {valueToString(banner.media_type) || "Image"}
          </span>
        </div>

        {valueToString(banner.description) ? (
          <p className="mt-3 line-clamp-3 text-sm leading-6 text-slate-600 dark:text-slate-300">
            {valueToString(banner.description)}
          </p>
        ) : (
          <p className="mt-3 text-sm leading-6 text-slate-400 dark:text-slate-500">
            No description added yet.
          </p>
        )}

        <div className="mt-4 flex flex-wrap gap-2">
          <span className="inline-flex items-center rounded-md bg-white/55 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:bg-white/8 dark:text-slate-200">
            Priority {valueToString(banner.priority) || "0"}
          </span>
          {valueToString(banner.target_type) ? (
            <span className="inline-flex max-w-full items-center gap-1.5 truncate rounded-md bg-white/55 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:bg-white/8 dark:text-slate-200">
              <ExternalLink className="size-3.5 shrink-0" />
              {valueToString(banner.target_type)}
            </span>
          ) : (
            null
          )}
        </div>
        <dl className="mt-4 grid gap-3 text-sm text-slate-700 dark:text-slate-200">
          <div className="flex items-center gap-3 rounded-md bg-white/45 px-3 py-2 dark:bg-white/6">
            <CalendarDays className="size-4 text-teal-700 dark:text-cyan-200" />
            <dt className="sr-only">Start date</dt>
            <dd>{formatDate(banner.start_date)}</dd>
          </div>
          <div className="flex items-center gap-3 rounded-md bg-white/45 px-3 py-2 dark:bg-white/6">
            <CalendarDays className="size-4 text-teal-700 dark:text-cyan-200" />
            <dt className="sr-only">End date</dt>
            <dd>{formatDate(banner.end_date)}</dd>
          </div>
        </dl>

        <div className="mt-auto grid gap-2 pt-5 sm:grid-cols-2">
          <button
            type="button"
            onClick={() => setIsDeleteDialogOpen(true)}
            disabled={!bannerId || isDeletePending}
            className={cn(
              "inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-md bg-rose-600 px-4 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-rose-700",
              (!bannerId || isDeletePending) &&
                "pointer-events-none opacity-50",
            )}
          >
            <Trash2 className="size-4" />
            {isDeletePending ? "Deleting..." : "Delete"}
          </button>
          <Link
            href={`/banners/edit/${bannerId}`}
            className={cn(
              "inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-md bg-slate-950 px-4 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200",
              !bannerId && "pointer-events-none opacity-50",
            )}
          >
            <PencilLine className="size-4" />
            Edit banner
          </Link>
        </div>
      </article>

      <ConfirmDialog
        open={isDeleteDialogOpen}
        title="Delete banner?"
        description={`This will permanently remove ${bannerTitle}.`}
        confirmLabel="Delete banner"
        cancelLabel="Keep banner"
        isPending={isDeletePending}
        variant="danger"
        onConfirm={deleteBanner}
        onClose={() => {
          if (isDeletePending) return;
          setIsDeleteDialogOpen(false);
        }}
      />
    </>
  );
}
