"use client";

import { useActionState, useEffect, useMemo, useRef, useState } from "react";
import {
  BellRing,
  Check,
  Film,
  Image as ImageIcon,
  LockKeyhole,
  Save,
  Sparkles,
  Tag,
  Video,
  X,
  type LucideIcon,
} from "lucide-react";
import {
  createMovieAction,
  type AddMovieFormState,
} from "@/app/(admin)/movies/add/actions";
import { useAdminSidebar } from "@/app/components/admin-sidebar-shell";
import { ShakaPlayerPreview } from "@/app/features/movies/components/shaka-player-preview";
import { cn } from "@/lib/utils";
import { toast } from "react-toastify";

const initialState: AddMovieFormState = {
  status: "idle",
  message: "",
};

const statusOptions = ["Draft", "Published", "Scheduled"] as const;

const genreOptions = [
  "Action",
  "Adventure",
  "Animation",
  "Comedy",
  "Crime",
  "Drama",
  "Fantasy",
  "Historical",
  "Horror",
  "Mystery",
  "Romance",
  "Science Fiction (Sci-Fi)",
  "Thriller",
  "Western",
  "War",
  "Superhero",
  "Spy/Espionage",
  "Martial Arts",
  "Disaster",
  "Swashbuckler",
] as const;

const ageRatingOptions = ["G", "PG", "PG-13", "R", "NC-17"] as const;

const categoryFlags = [
  { name: "isMizo", label: "Mizo" },
  { name: "isHollywood", label: "Hollywood" },
  { name: "isBollywood", label: "Bollywood" },
  { name: "isKorean", label: "Asian" },
  { name: "isDocumentary", label: "Documentary" },
  { name: "isSeason", label: "Series" },
] as const;

const accessFlags = [
  { name: "isPremium", label: "Premium", defaultChecked: true },
  { name: "isPayPerView", label: "Pay per view" },
  { name: "isAgeRestricted", label: "18+" },
  { name: "isChildMode", label: "Kids" },
  { name: "isProtected", label: "DRM protected" },
  { name: "isEnable", label: "Visible", defaultChecked: true },
  { name: "isCompleted", label: "Completed", defaultChecked: true },
  { name: "isDubbed", label: "Dubbed", defaultChecked: true },
  { name: "isSubtitle", label: "Subtitles" },
] as const;

type FieldProps = {
  label: string;
  name: string;
  placeholder?: string;
  type?: string;
  required?: boolean;
  multiline?: boolean;
  helper?: string;
  className?: string;
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const selectClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm font-semibold text-slate-950 [color-scheme:light] outline-none shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:[color-scheme:dark] dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";
const optionClassName =
  "bg-white text-slate-950 dark:bg-slate-950 dark:text-white";

function Field({
  label,
  name,
  placeholder,
  type = "text",
  required,
  multiline,
  helper,
  className,
}: FieldProps) {
  return (
    <label className={cn("block min-w-0", className)}>
      <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
        {label}
      </span>
      {multiline ? (
        <textarea
          name={name}
          placeholder={placeholder}
          required={required}
          rows={5}
          className={cn(inputClassName, "resize-y leading-6")}
        />
      ) : (
        <input
          name={name}
          type={type}
          placeholder={placeholder}
          required={required}
          className={inputClassName}
        />
      )}
      {helper ? (
        <span className="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">
          {helper}
        </span>
      ) : null}
    </label>
  );
}

function ImageUrlField({
  label,
  name,
  placeholder,
  className,
}: {
  label: string;
  name: string;
  placeholder?: string;
  className?: string;
}) {
  const [imageUrl, setImageUrl] = useState("");
  const [isPreviewOpen, setIsPreviewOpen] = useState(false);
  const previewUrl = imageUrl.trim();

  return (
    <div className={cn("block min-w-0", className)}>
      <div className="flex items-center justify-between gap-3">
        <label
          htmlFor={`movie-${name}`}
          className="text-sm font-semibold text-slate-800 dark:text-slate-100"
        >
          {label}
        </label>
        <button
          type="button"
          disabled={!previewUrl}
          onClick={() => setIsPreviewOpen(true)}
          className="inline-flex size-8 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.14)] bg-white/55 text-slate-600 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/80 hover:text-teal-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-white/8 dark:text-slate-300 dark:hover:bg-white/12 dark:hover:text-cyan-200"
          aria-label={`Preview ${label}`}
          title={previewUrl ? `Preview ${label}` : "Paste an image URL first"}
        >
          <ImageIcon className="size-4" />
        </button>
      </div>

      <input
        id={`movie-${name}`}
        name={name}
        type="text"
        value={imageUrl}
        onChange={(event) => setImageUrl(event.target.value)}
        placeholder={placeholder}
        className={inputClassName}
      />

      {isPreviewOpen ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/68 p-4 backdrop-blur-sm">
          <div className="relative w-full max-w-3xl overflow-hidden rounded-lg border border-white/18 bg-white/92 p-3 shadow-[0_30px_90px_rgba(2,6,23,0.45)] dark:bg-slate-950/92">
            <button
              type="button"
              onClick={() => setIsPreviewOpen(false)}
              className="absolute right-3 top-3 z-10 inline-flex size-9 items-center justify-center rounded-md bg-slate-950/75 text-white shadow-lg transition hover:bg-slate-800 dark:bg-white/90 dark:text-slate-950 dark:hover:bg-slate-200"
              aria-label="Close image preview"
            >
              <X className="size-4" />
            </button>
            <div className="overflow-hidden rounded-md bg-slate-100 dark:bg-white/6">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={previewUrl}
                alt={`${label} preview`}
                className="max-h-[72vh] w-full object-contain"
              />
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}

function VideoUrlField({
  label,
  name,
  placeholder,
  className,
}: {
  label: string;
  name: string;
  placeholder?: string;
  className?: string;
}) {
  const [videoUrl, setVideoUrl] = useState("");
  const [isPreviewOpen, setIsPreviewOpen] = useState(false);
  const previewUrl = videoUrl.trim();

  return (
    <div className={cn("block min-w-0", className)}>
      <div className="flex items-center justify-between gap-3">
        <label
          htmlFor={`movie-${name}`}
          className="text-sm font-semibold text-slate-800 dark:text-slate-100"
        >
          {label}
        </label>
        <button
          type="button"
          disabled={!previewUrl}
          onClick={() => setIsPreviewOpen(true)}
          className="inline-flex size-8 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.14)] bg-white/55 text-slate-600 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/80 hover:text-teal-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-white/8 dark:text-slate-300 dark:hover:bg-white/12 dark:hover:text-cyan-200"
          aria-label={`Preview ${label}`}
          title={previewUrl ? `Preview ${label}` : "Paste a video URL first"}
        >
          <Video className="size-4" />
        </button>
      </div>

      <input
        id={`movie-${name}`}
        name={name}
        type="text"
        value={videoUrl}
        onChange={(event) => setVideoUrl(event.target.value)}
        placeholder={placeholder}
        className={inputClassName}
      />

      {isPreviewOpen ? (
        <ShakaPlayerPreview
          sourceUrl={previewUrl}
          title={`${label} preview`}
          onClose={() => setIsPreviewOpen(false)}
        />
      ) : null}
    </div>
  );
}

function FormSection({
  title,
  eyebrow,
  icon: Icon,
  children,
}: {
  title: string;
  eyebrow: string;
  icon: LucideIcon;
  children: React.ReactNode;
}) {
  return (
    <section className="liquid-glass relative overflow-hidden rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
      <div className="mb-5 flex items-center gap-3">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.12)] bg-white/55 text-teal-700 shadow-[inset_0_1px_0_rgba(255,255,255,0.85)] dark:border-white/10 dark:bg-white/8 dark:text-cyan-200">
          <Icon className="size-4" />
        </span>
        <div className="min-w-0">
          <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700 dark:text-cyan-200">
            {eyebrow}
          </p>
          <h2 className="text-lg font-bold text-slate-950 dark:text-white">
            {title}
          </h2>
        </div>
      </div>
      {children}
    </section>
  );
}

function SwitchPill({
  name,
  label,
  defaultChecked,
}: {
  name: string;
  label: string;
  defaultChecked?: boolean;
}) {
  return (
    <label className="group flex min-h-12 items-center justify-between gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/64 dark:border-white/10 dark:bg-white/6 dark:text-slate-200 dark:hover:bg-white/10">
      <span>{label}</span>
      <input
        type="checkbox"
        name={name}
        defaultChecked={defaultChecked}
        className="peer sr-only"
      />
      <span className="relative h-6 w-11 shrink-0 rounded-full bg-slate-300/80 after:absolute after:left-1 after:top-1 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition after:content-[''] peer-checked:bg-teal-500 peer-checked:after:translate-x-5 dark:bg-slate-700 dark:peer-checked:bg-cyan-400" />
    </label>
  );
}

function MultiValuePicker({
  name,
  label,
  options,
  emptyText,
  selectLabel,
  customPlaceholder,
}: {
  name: string;
  label: string;
  options: readonly string[];
  emptyText: string;
  selectLabel: string;
  customPlaceholder: string;
}) {
  const [selectedItems, setSelectedItems] = useState<string[]>([]);
  const [customItem, setCustomItem] = useState("");
  const selectedValue = selectedItems.join(", ");

  const addItem = (item: string) => {
    const nextItem = item.trim();
    if (!nextItem) return;

    setSelectedItems((currentItems) => {
      const alreadySelected = currentItems.some(
        (currentItem) => currentItem.toLowerCase() === nextItem.toLowerCase(),
      );

      return alreadySelected ? currentItems : [...currentItems, nextItem];
    });
  };

  const removeItem = (item: string) => {
    setSelectedItems((currentItems) =>
      currentItems.filter((currentItem) => currentItem !== item),
    );
  };

  const addCustomItem = () => {
    addItem(customItem);
    setCustomItem("");
  };

  return (
    <div className="block min-w-0">
      <input type="hidden" name={name} value={selectedValue} />

      <div className="flex items-center justify-between gap-3">
        <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
          {label}
        </span>
        <span className="text-xs font-medium text-slate-500 dark:text-slate-400">
          {selectedItems.length} selected
        </span>
      </div>

      <div className="mt-2 rounded-md border border-[rgba(15,23,42,0.16)] bg-white/50 p-2 shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] dark:border-white/10 dark:bg-white/8">
        <div className="flex flex-wrap gap-2">
          {selectedItems.length > 0 ? (
            selectedItems.map((item) => (
              <button
                key={item}
                type="button"
                onClick={() => removeItem(item)}
                className="inline-flex min-h-8 items-center rounded-md bg-teal-100 px-3 text-xs font-bold text-teal-800 transition hover:bg-teal-200 dark:bg-cyan-300/12 dark:text-cyan-100 dark:hover:bg-cyan-300/18"
              >
                {item}
                <span className="ml-2 text-teal-600 dark:text-cyan-200">x</span>
              </button>
            ))
          ) : (
            <span className="px-2 py-1.5 text-sm text-slate-400">
              {emptyText}
            </span>
          )}
        </div>

        <select
          value=""
          onChange={(event) => addItem(event.target.value)}
          className={cn(
            selectClassName,
            "mt-3 bg-white/70 dark:bg-slate-950/45",
          )}
        >
          <option value="" className={optionClassName}>
            {selectLabel}
          </option>
          {options.map((option) => (
            <option key={option} value={option} className={optionClassName}>
              {option}
            </option>
          ))}
        </select>

        <div className="mt-2 flex flex-col gap-2 sm:flex-row">
          <input
            type="text"
            value={customItem}
            onChange={(event) => setCustomItem(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === "Enter") {
                event.preventDefault();
                addCustomItem();
              }
            }}
            placeholder={customPlaceholder}
            className="min-h-11 flex-1 rounded-md border border-[rgba(15,23,42,0.16)] bg-white/70 px-4 text-sm text-slate-950 outline-none shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/45 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15"
          />
          <button
            type="button"
            onClick={addCustomItem}
            className="inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-4 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
          >
            Add
          </button>
        </div>
      </div>
    </div>
  );
}

function GenrePicker() {
  return (
    <MultiValuePicker
      name="genre"
      label="Genre"
      options={genreOptions}
      emptyText="Choose one or more genres"
      selectLabel="Select genre"
      customPlaceholder="Type a custom genre"
    />
  );
}

function AgeRatingPicker() {
  return (
    <MultiValuePicker
      name="age_rating"
      label="Age Rating"
      options={ageRatingOptions}
      emptyText="Choose one or more age ratings"
      selectLabel="Select age rating"
      customPlaceholder="Type a custom age rating"
    />
  );
}

export function AddMovieForm() {
  const { isDesktopSidebarOpen } = useAdminSidebar();
  const formRef = useRef<HTMLFormElement>(null);
  const lastToastKeyRef = useRef("");
  const [state, formAction, isPending] = useActionState(
    createMovieAction,
    initialState,
  );
  const genreResetToken =
    state.status === "success" ? (state.resetKey ?? state.message) : "";

  const statusMessage = useMemo(() => {
    if (state.status === "success") {
      return state.movieId
        ? `${state.message} Movie ID: ${state.movieId}`
        : state.message;
    }

    return state.message;
  }, [state]);

  useEffect(() => {
    if (state.status === "success") {
      formRef.current?.reset();
    }
  }, [state.status, state.movieId]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(statusMessage || "Movie saved successfully.");
      return;
    }

    toast.error(statusMessage || "Movie could not be saved.");
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

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.42fr)]">
        <div className="space-y-4">
          <FormSection title="Movie details" eyebrow="Required" icon={Film}>
            <div className="grid gap-4 lg:grid-cols-2">
              <Field
                label="Title"
                name="title"
                placeholder="Enter movie title"
                required
              />
              <Field
                label="Release date"
                name="release_on"
                type="date"
                helper="Stored as a readable release date."
              />
              <GenrePicker key={`genre-${genreResetToken}`} />
              <AgeRatingPicker key={`age-rating-${genreResetToken}`} />
              <Field
                label="Director"
                name="director"
                placeholder="Director name"
              />
              <Field label="Duration" name="duration" placeholder="2h 10m" />
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Status
                </span>
                <select
                  name="status"
                  defaultValue="Draft"
                  className={selectClassName}
                >
                  {statusOptions.map((status) => (
                    <option
                      key={status}
                      value={status}
                      className={optionClassName}
                    >
                      {status}
                    </option>
                  ))}
                </select>
              </label>
              <Field
                label="Create date"
                name="create_date"
                type="date"
                helper="Leave empty to use today."
              />
              <Field
                label="Description"
                name="description"
                placeholder="Short synopsis for viewers"
                multiline
                className="lg:col-span-2"
              />
            </div>
          </FormSection>

          <FormSection title="Artwork" eyebrow="Images" icon={ImageIcon}>
            <div className="grid gap-4 lg:grid-cols-2">
              <ImageUrlField
                key={`poster-${genreResetToken}`}
                label="Poster URL"
                name="poster"
                placeholder="https://..."
              />
              <ImageUrlField
                key={`cover-${genreResetToken}`}
                label="Cover image URL"
                name="cover_img"
                placeholder="https://..."
              />
              <ImageUrlField
                key={`title-image-${genreResetToken}`}
                label="Title image URL"
                name="title_img"
                placeholder="https://..."
                className="lg:col-span-2"
              />
            </div>
          </FormSection>

          <FormSection title="Playback links" eyebrow="Streams" icon={Video}>
            <div className="grid gap-4 lg:grid-cols-2">
              <VideoUrlField
                key={`movie-url-${genreResetToken}`}
                label="Movie URL"
                name="url"
                placeholder="https://..."
              />
              <VideoUrlField
                key={`trailer-${genreResetToken}`}
                label="Trailer URL"
                name="trailer"
                placeholder="https://..."
              />
              <VideoUrlField
                key={`dash-${genreResetToken}`}
                label="DASH URL"
                name="dash_url"
                placeholder="https://..."
              />
              <VideoUrlField
                key={`hls-${genreResetToken}`}
                label="HLS URL"
                name="hls_url"
                placeholder="https://..."
              />
              <Field
                label="Subtitle URL"
                name="subtitle"
                placeholder="https://..."
              />
              <Field
                label="Token"
                name="token"
                placeholder="Generated for protected DASH when empty"
              />
            </div>
          </FormSection>
        </div>

        <aside className="space-y-4">
          <FormSection title="Availability" eyebrow="Access" icon={LockKeyhole}>
            <div className="grid gap-2">
              {accessFlags.map((flag) => (
                <SwitchPill key={flag.name} {...flag} />
              ))}
            </div>
            <Field
              label="PPV amount"
              name="ppv_amount"
              placeholder="99"
              className="mt-2"
            />
          </FormSection>

          <FormSection title="Categories" eyebrow="Shelf" icon={Tag}>
            <div className="grid gap-2">
              {categoryFlags.map((flag) => (
                <SwitchPill key={flag.name} {...flag} />
              ))}
            </div>
          </FormSection>

          <FormSection title="Publish signal" eyebrow="Notify" icon={BellRing}>
            <div className="grid gap-2">
              <label className="flex items-start gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 p-3 text-sm text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] dark:border-white/10 dark:bg-white/6 dark:text-slate-200">
                <input type="checkbox" name="notification" defaultChecked className="mt-1 size-4 rounded border-slate-300 text-teal-600" />
                <span>Send a push notification when this movie is saved as Published.</span>
              </label>
              <label className="flex items-start gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 p-3 text-sm text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] dark:border-white/10 dark:bg-white/6 dark:text-slate-200">
                <input type="checkbox" name="refresh_latest" className="mt-1 size-4 rounded border-slate-300 text-teal-600" />
                <span>Move to Latest Update. New Published movies already move automatically.</span>
              </label>
            </div>
          </FormSection>
        </aside>
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
          <span>
            Draft first, publish when the artwork and stream links are ready.
          </span>
        </div>
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
              Save movie
            </>
          )}
        </button>
      </div>
    </form>
  );
}
