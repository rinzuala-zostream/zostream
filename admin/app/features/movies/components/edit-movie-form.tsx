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
} from "lucide-react";
import {
  updateMovieAction,
  type EditMovieFormState,
} from "@/app/(admin)/movies/update/[id]/actions";
import { ShakaPlayerPreview } from "@/app/features/movies/components/shaka-player-preview";
import { useAdminSidebar } from "@/app/components/admin-sidebar-shell";
import { AdminFormSection as FormSection } from "@/app/components/admin-form-section";
import type {
  MovieItem,
  MovieStatus,
} from "@/app/features/movies/services/movie-service";
import { adminDateValue } from "@/app/lib/admin-date";
import { cn } from "@/lib/utils";
import { toast } from "react-toastify";

const initialState: EditMovieFormState = {
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

const categoryFlags = [
  { name: "isMizo", label: "Mizo" },
  { name: "isHollywood", label: "Hollywood" },
  { name: "isBollywood", label: "Bollywood" },
  { name: "isKorean", label: "Asian" },
  { name: "isDocumentary", label: "Documentary" },
  { name: "isSeason", label: "Series" },
] as const;

const accessFlags = [
  { name: "isPremium", label: "Premium" },
  { name: "isPayPerView", label: "Pay per view" },
  { name: "isAgeRestricted", label: "18+" },
  { name: "isChildMode", label: "Kids" },
  { name: "isProtected", label: "DRM protected" },
  { name: "isEnable", label: "Visible" },
  { name: "isCompleted", label: "Completed" },
  { name: "isDubbed", label: "Dubbed" },
  { name: "isSubtitle", label: "Subtitles" },
] as const;

const booleanFlagNames = [...categoryFlags, ...accessFlags].map(
  (flag) => flag.name,
);

type FieldProps = {
  label: string;
  name: string;
  movie: MovieItem;
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
const optionClassName = "bg-white text-slate-950 dark:bg-slate-950 dark:text-white";

function boolValue(value: unknown) {
  return value === true || value === 1 || value === "1";
}

function textValue(value: unknown) {
  if (typeof value === "string") return value;
  if (typeof value === "number") return String(value);
  return "";
}

function dateValue(value: unknown) {
  return adminDateValue(value);
}

function statusValue(status: MovieStatus | null | undefined) {
  return statusOptions.some((option) => option === status)
    ? String(status)
    : "Draft";
}

function Field({
  label,
  name,
  movie,
  placeholder,
  type = "text",
  required,
  multiline,
  helper,
  className,
}: FieldProps) {
  const defaultValue =
    type === "date" ? dateValue(movie[name]) : textValue(movie[name]);

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
          defaultValue={defaultValue}
          className={cn(inputClassName, "resize-y leading-6")}
        />
      ) : (
        <input
          name={name}
          type={type}
          placeholder={placeholder}
          required={required}
          defaultValue={defaultValue}
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
  movie,
  placeholder,
  className,
}: {
  label: string;
  name: string;
  movie: MovieItem;
  placeholder?: string;
  className?: string;
}) {
  const [imageUrl, setImageUrl] = useState(textValue(movie[name]));
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
  movie,
  placeholder,
}: {
  label: string;
  name: string;
  movie: MovieItem;
  placeholder?: string;
}) {
  const [videoUrl, setVideoUrl] = useState(textValue(movie[name]));
  const [isPreviewOpen, setIsPreviewOpen] = useState(false);
  const previewUrl = videoUrl.trim();

  return (
    <div className="block min-w-0">
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

function SwitchPill({
  name,
  label,
  movie,
}: {
  name: string;
  label: string;
  movie: MovieItem;
}) {
  return (
    <label className="group flex min-h-12 items-center justify-between gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/64 dark:border-white/10 dark:bg-white/6 dark:text-slate-200 dark:hover:bg-white/10">
      <span>{label}</span>
      <input
        type="checkbox"
        name={name}
        defaultChecked={boolValue(movie[name])}
        className="peer sr-only"
      />
      <span className="relative h-6 w-11 shrink-0 rounded-full bg-slate-300/80 after:absolute after:left-1 after:top-1 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition after:content-[''] peer-checked:bg-teal-500 peer-checked:after:translate-x-5 dark:bg-slate-700 dark:peer-checked:bg-cyan-400" />
    </label>
  );
}

function GenrePicker({ movie }: { movie: MovieItem }) {
  const [selectedGenres, setSelectedGenres] = useState<string[]>(() =>
    textValue(movie.genre)
      .split(",")
      .map((genre) => genre.trim())
      .filter(Boolean),
  );
  const [customGenre, setCustomGenre] = useState("");

  const addGenre = (genre: string) => {
    const nextGenre = genre.trim();
    if (!nextGenre) return;

    setSelectedGenres((currentGenres) => {
      const alreadySelected = currentGenres.some(
        (currentGenre) =>
          currentGenre.toLowerCase() === nextGenre.toLowerCase(),
      );

      return alreadySelected ? currentGenres : [...currentGenres, nextGenre];
    });
  };

  const addCustomGenre = () => {
    addGenre(customGenre);
    setCustomGenre("");
  };

  return (
    <div className="block min-w-0">
      <input type="hidden" name="genre" value={selectedGenres.join(", ")} />

      <div className="flex items-center justify-between gap-3">
        <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
          Genre
        </span>
        <span className="text-xs font-medium text-slate-500 dark:text-slate-400">
          {selectedGenres.length} selected
        </span>
      </div>

      <div className="mt-2 rounded-md border border-[rgba(15,23,42,0.16)] bg-white/50 p-2 shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] dark:border-white/10 dark:bg-white/8">
        <div className="flex flex-wrap gap-2">
          {selectedGenres.length > 0 ? (
            selectedGenres.map((genre) => (
              <button
                key={genre}
                type="button"
                onClick={() =>
                  setSelectedGenres((currentGenres) =>
                    currentGenres.filter(
                      (currentGenre) => currentGenre !== genre,
                    ),
                  )
                }
                className="inline-flex min-h-8 items-center rounded-md bg-teal-100 px-3 text-xs font-bold text-teal-800 transition hover:bg-teal-200 dark:bg-cyan-300/12 dark:text-cyan-100 dark:hover:bg-cyan-300/18"
              >
                {genre}
                <span className="ml-2 text-teal-600 dark:text-cyan-200">x</span>
              </button>
            ))
          ) : (
            <span className="px-2 py-1.5 text-sm text-slate-400">
              Choose one or more genres
            </span>
          )}
        </div>

        <select
          value=""
          onChange={(event) => addGenre(event.target.value)}
          className={cn(
            selectClassName,
            "mt-3 bg-white/70 dark:bg-slate-950/45",
          )}
        >
          <option value="" className={optionClassName}>
            Select genre
          </option>
          {genreOptions.map((genre) => (
            <option key={genre} value={genre} className={optionClassName}>
              {genre}
            </option>
          ))}
        </select>

        <div className="mt-2 flex flex-col gap-2 sm:flex-row">
          <input
            type="text"
            value={customGenre}
            onChange={(event) => setCustomGenre(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === "Enter") {
                event.preventDefault();
                addCustomGenre();
              }
            }}
            placeholder="Type a custom genre"
            className="min-h-11 flex-1 rounded-md border border-[rgba(15,23,42,0.16)] bg-white/70 px-4 text-sm text-slate-950 outline-none shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/45 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15"
          />
          <button
            type="button"
            onClick={addCustomGenre}
            className="inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-4 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
          >
            Add
          </button>
        </div>
      </div>
    </div>
  );
}

export function EditMovieForm({ movie }: { movie: MovieItem }) {
  const { isDesktopSidebarOpen } = useAdminSidebar();
  const lastToastKeyRef = useRef("");
  const [state, formAction, isPending] = useActionState(
    updateMovieAction,
    initialState,
  );

  const statusMessage = useMemo(() => state.message, [state.message]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(statusMessage || "Movie updated successfully.");
      return;
    }

    toast.error(statusMessage || "Movie could not be updated.");
  }, [state.message, state.resetKey, state.status, statusMessage]);

  return (
    <form action={formAction} className="space-y-4 pb-28">
      <input type="hidden" name="movie_id" value={textValue(movie.id)} />
      {booleanFlagNames
        .filter((name) => name in movie)
        .map((name) => (
          <input key={name} type="hidden" name="boolean_fields" value={name} />
        ))}

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
          <FormSection title="Movie details" eyebrow="Edit" icon={Film} defaultOpen>
            <div className="grid gap-4 lg:grid-cols-2">
              <Field
                label="Title"
                name="title"
                movie={movie}
                placeholder="Enter movie title"
                required
              />
              <Field
                label="Release date"
                name="release_on"
                movie={movie}
                type="date"
              />
              <GenrePicker movie={movie} />
              <Field
                label="Age Rating"
                name="age_rating"
                movie={movie}
                placeholder="PG-13"
              />
              <Field
                label="Director"
                name="director"
                movie={movie}
                placeholder="Director name"
              />
              <Field
                label="Duration"
                name="duration"
                movie={movie}
                placeholder="2h 10m"
              />
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Status
                </span>
                <select
                  name="status"
                  defaultValue={statusValue(movie.status)}
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
                movie={movie}
                type="date"
              />
              <Field
                label="Description"
                name="description"
                movie={movie}
                placeholder="Short synopsis for viewers"
                multiline
                className="lg:col-span-2"
              />
            </div>
          </FormSection>

          <FormSection title="Artwork" eyebrow="Images" icon={ImageIcon}>
            <div className="grid gap-4 lg:grid-cols-2">
              <ImageUrlField
                label="Poster URL"
                name="poster"
                movie={movie}
                placeholder="https://..."
              />
              <ImageUrlField
                label="Cover image URL"
                name="cover_img"
                movie={movie}
                placeholder="https://..."
              />
              <ImageUrlField
                label="Title image URL"
                name="title_img"
                movie={movie}
                placeholder="https://..."
                className="lg:col-span-2"
              />
            </div>
          </FormSection>

          <FormSection title="Playback links" eyebrow="Streams" icon={Video}>
            <div className="grid gap-4 lg:grid-cols-2">
              <VideoUrlField
                label="Movie URL"
                name="url"
                movie={movie}
                placeholder="https://..."
              />
              <VideoUrlField
                label="Trailer URL"
                name="trailer"
                movie={movie}
                placeholder="https://..."
              />
              <VideoUrlField
                label="DASH URL"
                name="dash_url"
                movie={movie}
                placeholder="https://..."
              />
              <VideoUrlField
                label="HLS URL"
                name="hls_url"
                movie={movie}
                placeholder="https://..."
              />
              <Field
                label="Subtitle URL"
                name="subtitle"
                movie={movie}
                placeholder="https://..."
              />
              <Field
                label="Token"
                name="token"
                movie={movie}
                placeholder="Generated for protected DASH when empty"
              />
            </div>
          </FormSection>
        </div>

        <aside className="space-y-4">
          <FormSection title="Availability" eyebrow="Access" icon={LockKeyhole}>
            <div className="grid gap-2">
              {accessFlags
                .filter((flag) => flag.name in movie)
                .map((flag) => (
                  <SwitchPill key={flag.name} {...flag} movie={movie} />
                ))}
            </div>
            <Field
              label="PPV amount"
              name="ppv_amount"
              movie={movie}
              placeholder="99"
              className="mt-2"
            />
          </FormSection>

          <FormSection title="Categories" eyebrow="Shelf" icon={Tag}>
            <div className="grid gap-2">
              {categoryFlags
                .filter((flag) => flag.name in movie)
                .map((flag) => (
                  <SwitchPill key={flag.name} {...flag} movie={movie} />
                ))}
            </div>
          </FormSection>

          <FormSection title="Publish signal" eyebrow="Notify" icon={BellRing}>
            <div className="grid gap-2">
              <label className="flex items-start gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 p-3 text-sm text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] dark:border-white/10 dark:bg-white/6 dark:text-slate-200">
                <input type="checkbox" name="notification" className="mt-1 size-4 rounded border-slate-300 text-teal-600" />
                <span>Send a push notification if this movie is saved as Published.</span>
              </label>
              <label className="flex items-start gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 p-3 text-sm text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] dark:border-white/10 dark:bg-white/6 dark:text-slate-200">
                <input type="checkbox" name="refresh_latest" className="mt-1 size-4 rounded border-slate-300 text-teal-600" />
                <span>Move this movie to the top of Latest Update.</span>
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
          <span>Review the artwork and stream links before updating.</span>
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
              Update movie
            </>
          )}
        </button>
      </div>
    </form>
  );
}
