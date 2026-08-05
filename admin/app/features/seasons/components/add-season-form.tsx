"use client";

import {
  useActionState,
  useEffect,
  useMemo,
  useRef,
  useState,
  useTransition,
} from "react";
import {
  CalendarDays,
  Check,
  Clapperboard,
  Image as ImageIcon,
  Layers3,
  Link2,
  Save,
  Search,
  Sparkles,
  X,
  type LucideIcon,
} from "lucide-react";
import {
  createSeasonAction,
  type AddSeasonFormState,
} from "@/app/(admin)/seasons/add/actions";
import {
  searchMoviesAction,
  type MovieSearchResultItem as MovieSearchActionResultItem,
  type MovieSearchState as MovieSearchActionState,
} from "@/app/(admin)/movies/update/actions";
import { useAdminSidebar } from "@/app/components/admin-sidebar-shell";
import { cn } from "@/lib/utils";
import { toast } from "react-toastify";

const initialState: AddSeasonFormState = {
  status: "idle",
  message: "",
};

type MovieSearchResultItem = MovieSearchActionResultItem & {
  movieNum?: string;
};

type MovieSearchState = Omit<MovieSearchActionState, "results"> & {
  results: MovieSearchResultItem[];
};

const initialMovieSearchState: MovieSearchState = {
  status: "idle",
  message: "Search by movie title, then choose a movie.",
  results: [],
};

const statusOptions = ["Draft", "Published", "Scheduled"] as const;

type FieldProps = {
  label: string;
  name: string;
  placeholder?: string;
  type?: string;
  required?: boolean;
  multiline?: boolean;
  helper?: string;
  min?: number;
  max?: number;
  step?: string;
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
  min,
  max,
  step,
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
          min={min}
          max={max}
          step={step}
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

function PosterUrlField({
  posterUrl,
  onPosterUrlChange,
}: {
  posterUrl: string;
  onPosterUrlChange: (value: string) => void;
}) {
  const [isPreviewOpen, setIsPreviewOpen] = useState(false);
  const previewUrl = posterUrl.trim();

  return (
    <div className="block min-w-0">
      <div className="flex items-center justify-between gap-3">
        <label
          htmlFor="season-poster"
          className="text-sm font-semibold text-slate-800 dark:text-slate-100"
        >
          Poster URL
        </label>
        <button
          type="button"
          disabled={!previewUrl}
          onClick={() => setIsPreviewOpen(true)}
          className="inline-flex size-8 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.14)] bg-white/55 text-slate-600 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/80 hover:text-teal-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-white/8 dark:text-slate-300 dark:hover:bg-white/12 dark:hover:text-cyan-200"
          aria-label="Preview season poster"
          title={
            previewUrl ? "Preview season poster" : "Paste an image URL first"
          }
        >
          <ImageIcon className="size-4" />
        </button>
      </div>

      <input
        id="season-poster"
        name="poster"
        type="text"
        value={posterUrl}
        onChange={(event) => onPosterUrlChange(event.target.value)}
        placeholder="https://..."
        className={inputClassName}
      />

      {isPreviewOpen ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/68 p-4 backdrop-blur-sm">
          <div className="relative w-full max-w-3xl overflow-hidden rounded-lg border border-white/18 bg-white/92 p-3 shadow-[0_30px_90px_rgba(2,6,23,0.45)] dark:bg-slate-950/92">
            <button
              type="button"
              onClick={() => setIsPreviewOpen(false)}
              className="absolute right-3 top-3 z-10 inline-flex size-9 items-center justify-center rounded-md bg-slate-950/75 text-white shadow-lg transition hover:bg-slate-800 dark:bg-white/90 dark:text-slate-950 dark:hover:bg-slate-200"
              aria-label="Close poster preview"
            >
              <X className="size-4" />
            </button>
            <div className="overflow-hidden rounded-md bg-slate-100 dark:bg-white/6">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={previewUrl}
                alt="Season poster preview"
                className="max-h-[72vh] w-full object-contain"
              />
            </div>
          </div>
        </div>
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

function MovieSearchResultCard({
  movie,
  onSelect,
}: {
  movie: MovieSearchResultItem;
  onSelect: (movie: MovieSearchResultItem) => void;
}) {
  return (
    <button
      type="button"
      onClick={() => onSelect(movie)}
      className="flex w-full items-center gap-3 rounded-md border border-[rgba(15,23,42,0.12)] bg-white/48 p-3 text-left shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:-translate-y-0.5 hover:border-teal-200 hover:bg-white/72 dark:border-white/10 dark:bg-white/6 dark:hover:border-cyan-300/30 dark:hover:bg-white/10"
    >
      <span className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md bg-slate-200 text-slate-500 dark:bg-white/8 dark:text-slate-400">
        {movie.coverImage ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={movie.coverImage}
            alt={movie.title}
            className="h-full w-full object-cover"
          />
        ) : (
          <Clapperboard className="size-4" />
        )}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-bold text-slate-950 dark:text-white">
          {movie.title}
        </span>
        <span className="mt-1 block truncate text-xs font-semibold text-slate-500 dark:text-slate-400">
          Movie {movie.movieNum || movie.id}
          {movie.status ? ` - ${movie.status}` : ""}
        </span>
      </span>
      <span className="hidden shrink-0 rounded-md bg-white/65 px-2.5 py-1.5 text-xs font-bold text-slate-600 dark:bg-white/8 dark:text-slate-300 sm:inline-flex">
        Select
      </span>
    </button>
  );
}

function MovieIdPicker({
  movieId,
  searchQuery,
  searchState,
  selectedMovieLabel,
  isPending,
  onSearchQueryChange,
  onSelectMovie,
}: {
  movieId: string;
  searchQuery: string;
  searchState: MovieSearchState;
  selectedMovieLabel: string;
  isPending: boolean;
  onSearchQueryChange: (value: string) => void;
  onSelectMovie: (movie: MovieSearchResultItem) => void;
}) {
  return (
    <div className="lg:col-span-2">
      <label className="block min-w-0">
        <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
          Find movie
        </span>
        <div className="mt-2 flex min-h-12 items-center gap-2 rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-3 shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition focus-within:border-teal-300 focus-within:bg-white/76 focus-within:ring-4 focus-within:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:focus-within:border-cyan-300/60 dark:focus-within:bg-white/12 dark:focus-within:ring-cyan-300/15">
          <Search className="size-4 shrink-0 text-teal-700 dark:text-cyan-200" />
          <input
            type="search"
            value={searchQuery}
            onChange={(event) => onSearchQueryChange(event.target.value)}
            placeholder="Search movie title"
            className="min-h-11 min-w-0 flex-1 bg-transparent text-sm font-semibold text-slate-950 outline-none placeholder:text-slate-400 dark:text-white dark:placeholder:text-slate-500"
          />
          {isPending ? (
            <span className="size-4 shrink-0 animate-spin rounded-full border-2 border-teal-600 border-t-transparent dark:border-cyan-200 dark:border-t-transparent" />
          ) : null}
        </div>
      </label>

      <div className="mt-2 min-h-5 text-xs font-semibold text-slate-500 dark:text-slate-400">
        {isPending ? "Searching movies..." : searchState.message}
      </div>

      {searchState.results.length > 0 ? (
        <div className="mt-3 grid gap-2">
          {searchState.results.map((movie, index) => (
            <MovieSearchResultCard
              key={movie.movieNum || movie.id || `${movie.title}-${index}`}
              movie={movie}
              onSelect={onSelectMovie}
            />
          ))}
        </div>
      ) : null}

      <div className="mt-2 min-h-5 text-xs font-semibold text-slate-500 dark:text-slate-400">
        {selectedMovieLabel || "Choose a movie above to fill Movie ID."}
      </div>
    </div>
  );
}

function SwitchPill({ name, label }: { name: string; label: string }) {
  return (
    <label className="group flex min-h-12 items-center justify-between gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/64 dark:border-white/10 dark:bg-white/6 dark:text-slate-200 dark:hover:bg-white/10">
      <span>{label}</span>
      <input type="checkbox" name={name} className="peer sr-only" />
      <span className="relative h-6 w-11 shrink-0 rounded-full bg-slate-300/80 after:absolute after:left-1 after:top-1 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition after:content-[''] peer-checked:bg-teal-500 peer-checked:after:translate-x-5 dark:bg-slate-700 dark:peer-checked:bg-cyan-400" />
    </label>
  );
}

export function AddSeasonForm() {
  const { isDesktopSidebarOpen } = useAdminSidebar();
  const formRef = useRef<HTMLFormElement>(null);
  const lastToastKeyRef = useRef("");
  const lastMovieSearchRequestRef = useRef(0);
  const skipNextMovieSearchRef = useRef(false);
  const [movieId, setMovieId] = useState("");
  const [seasonNumber, setSeasonNumber] = useState("");
  const [seasonTitle, setSeasonTitle] = useState("");
  const [posterUrl, setPosterUrl] = useState("");
  const [isSeasonTitleTouched, setIsSeasonTitleTouched] = useState(false);
  const [movieSearchQuery, setMovieSearchQuery] = useState("");
  const [selectedMovieLabel, setSelectedMovieLabel] = useState("");
  const [movieSearchState, setMovieSearchState] = useState(
    initialMovieSearchState,
  );
  const [state, formAction, isPending] = useActionState(
    createSeasonAction,
    initialState,
  );
  const [isMovieSearchPending, startMovieSearchTransition] = useTransition();
  const seasonResetToken =
    state.status === "success" ? (state.resetKey ?? state.message) : "";

  const statusMessage = useMemo(() => {
    if (state.status === "success") {
      return state.seasonId
        ? `${state.message} Season ID: ${state.seasonId}`
        : state.message;
    }

    return state.message;
  }, [state]);

  useEffect(() => {
    if (state.status === "success") {
      formRef.current?.reset();
      window.setTimeout(() => {
        setMovieId("");
        setSeasonNumber("");
        setSeasonTitle("");
        setPosterUrl("");
        setIsSeasonTitleTouched(false);
        setMovieSearchQuery("");
        setSelectedMovieLabel("");
        setMovieSearchState(initialMovieSearchState);
      }, 0);
    }
  }, [state.status, state.seasonId]);

  useEffect(() => {
    if (skipNextMovieSearchRef.current) {
      skipNextMovieSearchRef.current = false;
      return;
    }

    const query = movieSearchQuery.trim();
    const requestId = lastMovieSearchRequestRef.current + 1;
    lastMovieSearchRequestRef.current = requestId;

    if (!query) {
      const timeoutId = window.setTimeout(() => {
        setMovieSearchState(initialMovieSearchState);
      }, 0);

      return () => window.clearTimeout(timeoutId);
    }

    if (query.length < 2) {
      const timeoutId = window.setTimeout(() => {
        setMovieSearchState({
          status: "idle",
          message: "Type at least 2 characters.",
          results: [],
        });
      }, 0);

      return () => window.clearTimeout(timeoutId);
    }

    const timeoutId = window.setTimeout(() => {
      startMovieSearchTransition(async () => {
        const nextState = await searchMoviesAction(query);

        if (lastMovieSearchRequestRef.current === requestId) {
          setMovieSearchState(nextState as MovieSearchState);
        }
      });
    }, 350);

    return () => window.clearTimeout(timeoutId);
  }, [movieSearchQuery]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(statusMessage || "Season saved successfully.");
      return;
    }

    toast.error(statusMessage || "Season could not be saved.");
  }, [state.message, state.resetKey, state.status, statusMessage]);

  const handleMovieSearchQueryChange = (value: string) => {
    setMovieSearchQuery(value);
    setSelectedMovieLabel("");
    setMovieId("");
  };

  const handleSelectMovie = (movie: MovieSearchResultItem) => {
    const nextMovieId = movie.movieNum || movie.id;

    skipNextMovieSearchRef.current = true;
    lastMovieSearchRequestRef.current += 1;
    setMovieId(nextMovieId);
    setMovieSearchQuery(movie.title);
    setPosterUrl(movie.coverImage);
    setSelectedMovieLabel(`${movie.title} - Movie ${nextMovieId}`);
    setMovieSearchState({
      status: "success",
      message: "Movie selected.",
      results: [],
    });
  };

  const handleSeasonNumberChange = (value: string) => {
    setSeasonNumber(value);

    if (!isSeasonTitleTouched) {
      setSeasonTitle(value ? `Season ${value}` : "");
    }
  };

  const handleSeasonTitleChange = (value: string) => {
    setSeasonTitle(value);
    setIsSeasonTitleTouched(value.trim().length > 0);
  };

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

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.38fr)]">
        <div className="space-y-4">
          <FormSection title="Season details" eyebrow="Required" icon={Layers3}>
            <div className="grid gap-4 lg:grid-cols-2">
              <MovieIdPicker
                movieId={movieId}
                searchQuery={movieSearchQuery}
                searchState={movieSearchState}
                selectedMovieLabel={selectedMovieLabel}
                isPending={isMovieSearchPending}
                onSearchQueryChange={handleMovieSearchQueryChange}
                onSelectMovie={handleSelectMovie}
              />
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Movie ID
                </span>
                <input
                  name="movie_id"
                  type="number"
                  value={movieId}
                  onChange={(event) => setMovieId(event.target.value)}
                  placeholder="Movie num"
                  required
                  min={1}
                  className={inputClassName}
                />
              </label>
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Season number
                </span>
                <input
                  name="season_number"
                  type="number"
                  min={1}
                  value={seasonNumber}
                  onChange={(event) =>
                    handleSeasonNumberChange(event.target.value)
                  }
                  placeholder="1"
                  required
                  className={inputClassName}
                />
              </label>
              <label className="block min-w-0 lg:col-span-2">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Season title
                </span>
                <input
                  name="title"
                  type="text"
                  value={seasonTitle}
                  onChange={(event) =>
                    handleSeasonTitleChange(event.target.value)
                  }
                  placeholder="Season 1"
                  className={inputClassName}
                />
              </label>
              <Field
                label="Description"
                name="description"
                placeholder="Short season synopsis"
                multiline
                className="lg:col-span-2"
              />
            </div>
          </FormSection>

          <FormSection title="Artwork" eyebrow="Image" icon={ImageIcon}>
            <PosterUrlField
              key={`poster-${seasonResetToken}`}
              posterUrl={posterUrl}
              onPosterUrlChange={setPosterUrl}
            />
          </FormSection>
        </div>

        <aside className="space-y-4">
          <FormSection title="Release" eyebrow="Schedule" icon={CalendarDays}>
            <div className="grid gap-4">
              <Field
                label="Create year"
                name="release_year"
                type="number"
                min={1900}
                max={2200}
                placeholder="2026"
                helper="Stored as the season release year."
              />
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
              <SwitchPill name="isPayPerView" label="Pay per view" />
              <Field
                label="Pay per view amount"
                name="amount"
                type="number"
                min={0}
                step="0.01"
                placeholder="0"
                helper="Used only when pay per view is enabled. Episode split is available after episodes are attached."
              />
            </div>
          </FormSection>

          <FormSection title="Linking note" eyebrow="Movie" icon={Link2}>
            <div className="rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 p-3 text-sm leading-6 text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] dark:border-white/10 dark:bg-white/6 dark:text-slate-200">
              Save the season against the movie numeric ID. Episodes can be
              attached after the season exists; then edit the season to select
              PPV episodes and split the season amount.
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
          <span>Build the season shell first, then add episodes.</span>
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
              Save season
            </>
          )}
        </button>
      </div>
    </form>
  );
}
