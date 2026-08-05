"use client";

import { useActionState, useEffect, useMemo, useRef } from "react";
import Link from "next/link";
import {
  ArrowLeft,
  CalendarDays,
  Check,
  ImagePlus,
  LinkIcon,
  Save,
  ShieldAlert,
  Sparkles,
} from "lucide-react";
import { toast } from "react-toastify";
import {
  updateBannerAction,
  type EditBannerFormState,
} from "@/app/(admin)/banners/edit/[id]/actions";
import { useAdminSidebar } from "@/app/components/admin-sidebar-shell";
import { cn } from "@/lib/utils";
import type { BannerItem } from "@/app/features/banners/services/banner-service";

type EditBannerFormProps = {
  banner: BannerItem;
};

const initialState: EditBannerFormState = {
  status: "idle",
  message: "",
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const selectClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm font-semibold text-slate-950 [color-scheme:light] outline-none shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:[color-scheme:dark] dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";
const optionClassName =
  "bg-white text-slate-950 dark:bg-slate-950 dark:text-white";

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

function valueToBoolean(value: unknown) {
  return value === true || value === 1 || value === "1";
}

function toDateTimeLocalValue(value: unknown) {
  const text = valueToString(value);
  if (!text) return "";

  const date = new Date(text);
  if (Number.isNaN(date.getTime())) return "";

  const year = date.getFullYear();
  const month = `${date.getMonth() + 1}`.padStart(2, "0");
  const day = `${date.getDate()}`.padStart(2, "0");
  const hours = `${date.getHours()}`.padStart(2, "0");
  const minutes = `${date.getMinutes()}`.padStart(2, "0");

  return `${year}-${month}-${day}T${hours}:${minutes}`;
}

export function EditBannerForm({ banner }: EditBannerFormProps) {
  const { isDesktopSidebarOpen } = useAdminSidebar();
  const lastToastKeyRef = useRef("");
  const bannerId = valueToString(banner.id);
  const [state, formAction, isPending] = useActionState(
    updateBannerAction.bind(null, bannerId),
    initialState,
  );
  const message = useMemo(() => state.message, [state.message]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(message || "Banner updated.");
      return;
    }

    toast.error(message || "Banner could not be updated.");
  }, [message, state.message, state.resetKey, state.status]);

  return (
    <form action={formAction} className="space-y-4 pb-28">
      {state.status !== "idle" ? (
        <div
          className={cn(
            "rounded-md border px-4 py-3 text-sm font-semibold",
            state.status === "success"
              ? "border-teal-200 bg-teal-50/90 text-teal-800 dark:border-cyan-300/25 dark:bg-cyan-300/10 dark:text-cyan-100"
              : "border-rose-200 bg-rose-50/90 text-rose-800 dark:border-rose-300/25 dark:bg-rose-300/10 dark:text-rose-100",
          )}
        >
          {message}
        </div>
      ) : null}

      <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
        <div className="mb-5 flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
            <ImagePlus className="size-4" />
          </span>
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              Creative
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Banner details
            </h2>
          </div>
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Title
            </span>
            <input
              name="title"
              type="text"
              defaultValue={valueToString(banner.title)}
              placeholder="Now streaming"
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Button text
            </span>
            <input
              name="button_text"
              type="text"
              defaultValue={valueToString(banner.button_text)}
              placeholder="Watch now"
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0 lg:col-span-2">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Description
            </span>
            <textarea
              name="description"
              rows={3}
              defaultValue={valueToString(banner.description)}
              placeholder="Short banner copy"
              className={cn(inputClassName, "resize-y")}
            />
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Banner type
            </span>
            <select
              name="type"
              defaultValue={valueToString(banner.type) || "movie"}
              required
              className={selectClassName}
            >
              <option value="movie" className={optionClassName}>Movie</option>
              <option value="ad" className={optionClassName}>Ad</option>
              <option value="external" className={optionClassName}>External</option>
              <option value="category" className={optionClassName}>Category</option>
              <option value="custom" className={optionClassName}>Custom</option>
            </select>
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Priority
            </span>
            <input
              name="priority"
              type="number"
              step={1}
              defaultValue={valueToString(banner.priority) || "0"}
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Media type
            </span>
            <select
              name="media_type"
              defaultValue={valueToString(banner.media_type) || "image"}
              required
              className={selectClassName}
            >
              <option value="image" className={optionClassName}>Image</option>
              <option value="video" className={optionClassName}>Video</option>
            </select>
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Media URL
            </span>
            <input
              name="media_url"
              type="url"
              defaultValue={valueToString(banner.media_url)}
              placeholder="https://..."
              required
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0 lg:col-span-2">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Thumbnail URL
            </span>
            <input
              name="thumbnail_url"
              type="url"
              defaultValue={valueToString(banner.thumbnail_url)}
              placeholder="https://..."
              className={inputClassName}
            />
          </label>

          <label className="group flex min-h-12 items-center justify-between gap-3 self-end rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/64 dark:border-white/10 dark:bg-white/6 dark:text-slate-200 dark:hover:bg-white/10">
            <span>Banner active</span>
            <input
              type="checkbox"
              name="is_active"
              defaultChecked={valueToBoolean(banner.is_active)}
              className="peer sr-only"
            />
            <span className="relative h-6 w-11 shrink-0 rounded-full bg-slate-300/80 after:absolute after:left-1 after:top-1 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition after:content-[''] peer-checked:bg-teal-500 peer-checked:after:translate-x-5 dark:bg-slate-700 dark:peer-checked:bg-cyan-400" />
          </label>
        </div>
      </section>

      <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
        <div className="mb-5 flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
            <LinkIcon className="size-4" />
          </span>
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              Destination
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Tap behavior
            </h2>
          </div>
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Target type
            </span>
            <select
              name="target_type"
              defaultValue={valueToString(banner.target_type) || "movie"}
              required
              className={selectClassName}
            >
              <option value="movie" className={optionClassName}>Movie</option>
              <option value="series" className={optionClassName}>Series</option>
              <option value="episode" className={optionClassName}>Episode</option>
              <option value="url" className={optionClassName}>URL</option>
              <option value="category" className={optionClassName}>Category</option>
              <option value="none" className={optionClassName}>None</option>
            </select>
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Target ID
            </span>
            <input
              name="target_id"
              type="text"
              defaultValue={valueToString(banner.target_id)}
              placeholder="Movie, series, episode, or category ID"
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0 lg:col-span-2">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Target URL
            </span>
            <input
              name="target_url"
              type="url"
              defaultValue={valueToString(banner.target_url)}
              placeholder="https://..."
              className={inputClassName}
            />
          </label>
        </div>
      </section>

      <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
        <div className="mb-5 flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
            <CalendarDays className="size-4" />
          </span>
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              Schedule
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Visibility window
            </h2>
          </div>
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Start date
            </span>
            <input
              name="start_date"
              type="datetime-local"
              defaultValue={toDateTimeLocalValue(banner.start_date)}
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              End date
            </span>
            <input
              name="end_date"
              type="datetime-local"
              defaultValue={toDateTimeLocalValue(banner.end_date)}
              className={inputClassName}
            />
          </label>
        </div>
      </section>

      <section className="liquid-glass rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
        <div className="mb-5 flex items-center gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
            <ShieldAlert className="size-4" />
          </span>
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
              Access
            </p>
            <h2 className="text-lg font-bold text-slate-950 dark:text-white">
              Age restriction
            </h2>
          </div>
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <label className="group flex min-h-12 items-center justify-between gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/64 dark:border-white/10 dark:bg-white/6 dark:text-slate-200 dark:hover:bg-white/10">
            <span>Enable age restriction</span>
            <input
              type="checkbox"
              name="age_restriction_enabled"
              defaultChecked={valueToBoolean(banner.age_restriction_enabled)}
              className="peer sr-only"
            />
            <span className="relative h-6 w-11 shrink-0 rounded-full bg-slate-300/80 after:absolute after:left-1 after:top-1 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition after:content-[''] peer-checked:bg-teal-500 peer-checked:after:translate-x-5 dark:bg-slate-700 dark:peer-checked:bg-cyan-400" />
          </label>

          <label className="group flex min-h-12 items-center justify-between gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/64 dark:border-white/10 dark:bg-white/6 dark:text-slate-200 dark:hover:bg-white/10">
            <span>Require parental PIN</span>
            <input
              type="checkbox"
              name="requires_parental_pin"
              defaultChecked={valueToBoolean(banner.requires_parental_pin)}
              className="peer sr-only"
            />
            <span className="relative h-6 w-11 shrink-0 rounded-full bg-slate-300/80 after:absolute after:left-1 after:top-1 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition after:content-[''] peer-checked:bg-teal-500 peer-checked:after:translate-x-5 dark:bg-slate-700 dark:peer-checked:bg-cyan-400" />
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Age rating
            </span>
            <select
              name="age_rating"
              defaultValue={valueToString(banner.age_rating)}
              className={selectClassName}
            >
              <option value="" className={optionClassName}>No rating</option>
              <option value="G" className={optionClassName}>G</option>
              <option value="PG" className={optionClassName}>PG</option>
              <option value="PG13" className={optionClassName}>PG13</option>
              <option value="R" className={optionClassName}>R</option>
              <option value="18+" className={optionClassName}>18+</option>
              <option value="21+" className={optionClassName}>21+</option>
            </select>
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Minimum age
            </span>
            <input
              name="min_age"
              type="number"
              min={0}
              step={1}
              defaultValue={valueToString(banner.min_age)}
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Maximum age
            </span>
            <input
              name="max_age"
              type="number"
              min={0}
              step={1}
              defaultValue={valueToString(banner.max_age)}
              className={inputClassName}
            />
          </label>
        </div>
      </section>

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
          <span>Update this banner placement for the app.</span>
        </div>
        <div className="flex flex-col gap-3 sm:flex-row">
          <Link
            href="/banners/edit"
            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/58 px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-white dark:border-white/10 dark:bg-white/8 dark:text-slate-100 dark:hover:bg-white/12"
          >
            <ArrowLeft className="size-4" />
            Back
          </Link>
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
                Update banner
              </>
            )}
          </button>
        </div>
      </div>
    </form>
  );
}
