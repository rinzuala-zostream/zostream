"use client";

import {
  useActionState,
  useCallback,
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
  Link2,
  ListVideo,
  Search,
  PlayCircle,
  Save,
  Sparkles,
  Video,
  X,
  type LucideIcon,
} from "lucide-react";
import {
  createEpisodeAction,
  type AddEpisodeFormState as AddEpisodeActionState,
} from "@/app/(admin)/seasons/episodes/add/actions";
import {
  searchSeasonsAction,
  type SeasonMovieSearchResult,
  type SeasonSearchResultItem,
  type SeasonSearchState,
} from "@/app/(admin)/seasons/update/actions";
import { useAdminSidebar } from "@/app/components/admin-sidebar-shell";
import { ShakaPlayerPreview } from "@/app/features/movies/components/shaka-player-preview";
import { cn } from "@/lib/utils";
import { toast } from "react-toastify";

type AddEpisodeFormState = AddEpisodeActionState & {
  videoUrlId?: string;
};

const initialState: AddEpisodeFormState = {
  status: "idle",
  message: "",
};

const initialSeasonSearchState: SeasonSearchState = {
  status: "idle",
  message: "Loading seasons...",
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
  step?: string;
  className?: string;
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const selectClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm font-semibold text-slate-950 [color-scheme:light] outline-none shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:[color-scheme:dark] dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";
const optionClassName = "bg-white text-slate-950 dark:bg-slate-950 dark:text-white";

function Field({
  label,
  name,
  placeholder,
  type = "text",
  required,
  multiline,
  helper,
  min,
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

function ThumbnailUrlField() {
  const [thumbnailUrl, setThumbnailUrl] = useState("");
  const [isPreviewOpen, setIsPreviewOpen] = useState(false);
  const previewUrl = thumbnailUrl.trim();

  return (
    <div className="block min-w-0">
      <div className="flex items-center justify-between gap-3">
        <label
          htmlFor="episode-thumbnail"
          className="text-sm font-semibold text-slate-800 dark:text-slate-100"
        >
          Thumbnail URL
        </label>
        <button
          type="button"
          disabled={!previewUrl}
          onClick={() => setIsPreviewOpen(true)}
          className="inline-flex size-8 shrink-0 items-center justify-center rounded-md border border-[rgba(15,23,42,0.14)] bg-white/55 text-slate-600 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/80 hover:text-teal-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-white/8 dark:text-slate-300 dark:hover:bg-white/12 dark:hover:text-cyan-200"
          aria-label="Preview episode thumbnail"
          title={
            previewUrl
              ? "Preview episode thumbnail"
              : "Paste an image URL first"
          }
        >
          <ImageIcon className="size-4" />
        </button>
      </div>

      <input
        id="episode-thumbnail"
        name="thumbnail"
        type="text"
        value={thumbnailUrl}
        onChange={(event) => setThumbnailUrl(event.target.value)}
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
              aria-label="Close thumbnail preview"
            >
              <X className="size-4" />
            </button>
            <div className="overflow-hidden rounded-md bg-slate-100 dark:bg-white/6">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={previewUrl}
                alt="Episode thumbnail preview"
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
  required,
  helper,
}: {
  label: string;
  name: string;
  placeholder?: string;
  required?: boolean;
  helper?: string;
}) {
  const [videoUrl, setVideoUrl] = useState("");
  const [isPreviewOpen, setIsPreviewOpen] = useState(false);
  const previewUrl = videoUrl.trim();

  return (
    <div className="block min-w-0">
      <div className="flex items-center justify-between gap-3">
        <label
          htmlFor={`episode-${name}`}
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
        id={`episode-${name}`}
        name={name}
        type="text"
        value={videoUrl}
        onChange={(event) => setVideoUrl(event.target.value)}
        placeholder={placeholder}
        required={required}
        className={inputClassName}
      />

      {helper ? (
        <span className="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">
          {helper}
        </span>
      ) : null}

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

function SeasonSearchResultCard({
  movie,
  season,
  onSelect,
}: {
  movie: SeasonMovieSearchResult;
  season: SeasonSearchResultItem;
  onSelect: (
    movie: SeasonMovieSearchResult,
    season: SeasonSearchResultItem,
  ) => void;
}) {
  return (
    <button
      type="button"
      onClick={() => onSelect(movie, season)}
      className="group flex w-full flex-col gap-3 rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/52 p-3 text-left shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:-translate-y-0.5 hover:border-teal-200 hover:bg-white/78 dark:border-white/10 dark:bg-white/6 dark:hover:border-cyan-300/30 dark:hover:bg-white/10 sm:flex-row sm:items-center"
    >
      <span className="relative flex aspect-video w-full shrink-0 items-center justify-center overflow-hidden rounded-md bg-slate-950 text-white dark:bg-white/10 dark:text-white sm:w-24">
        {movie.poster ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={movie.poster}
            alt={movie.title}
            className="h-full w-full object-cover"
          />
        ) : (
          <Clapperboard className="size-5" />
        )}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-bold text-slate-950 dark:text-white">
          {season.title}
        </span>
        <span className="mt-1 block text-xs font-semibold text-slate-500 dark:text-slate-400">
          {movie.title}
        </span>
        <span className="mt-2 flex flex-wrap gap-1.5 text-[11px] font-bold text-slate-600 dark:text-slate-300">
          {season.seasonNumber ? (
            <span className="rounded-md bg-white/70 px-2 py-1 dark:bg-white/8">
              Season {season.seasonNumber}
            </span>
          ) : null}
          {season.status ? (
            <span className="rounded-md bg-white/70 px-2 py-1 dark:bg-white/8">
              {season.status}
            </span>
          ) : null}
          <span className="inline-flex items-center gap-1 rounded-md bg-white/70 px-2 py-1 dark:bg-white/8">
            <ListVideo className="size-3" />
            {season.episodeCount} episode{season.episodeCount === 1 ? "" : "s"}
          </span>
        </span>
      </span>
      <span className="inline-flex shrink-0 justify-center rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition group-hover:bg-teal-700 dark:bg-white dark:text-slate-950 dark:group-hover:bg-cyan-200">
        Select
      </span>
    </button>
  );
}

function MovieSearchResultCard({
  movie,
  onSelect,
}: {
  movie: SeasonMovieSearchResult;
  onSelect: (movie: SeasonMovieSearchResult) => void;
}) {
  const seasonCount = movie.seasons.length;
  const episodeCount = movie.seasons.reduce(
    (total, season) => total + season.episodeCount,
    0,
  );

  return (
    <button
      type="button"
      onClick={() => onSelect(movie)}
      className="group flex w-full flex-col gap-3 rounded-lg border border-[rgba(15,23,42,0.12)] bg-white/52 p-3 text-left shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:-translate-y-0.5 hover:border-teal-200 hover:bg-white/78 dark:border-white/10 dark:bg-white/6 dark:hover:border-cyan-300/30 dark:hover:bg-white/10 sm:flex-row sm:items-center"
    >
      <span className="relative flex aspect-video w-full shrink-0 items-center justify-center overflow-hidden rounded-md bg-slate-950 text-white dark:bg-white/10 dark:text-white sm:w-28">
        {movie.poster ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={movie.poster}
            alt={movie.title}
            className="h-full w-full object-cover"
          />
        ) : (
          <Clapperboard className="size-5" />
        )}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-bold text-slate-950 dark:text-white">
          {movie.title}
        </span>
        <span className="mt-2 flex flex-wrap gap-1.5 text-[11px] font-bold text-slate-600 dark:text-slate-300">
          {movie.status ? (
            <span className="rounded-md bg-white/70 px-2 py-1 dark:bg-white/8">
              {movie.status}
            </span>
          ) : null}
          {movie.genre ? (
            <span className="rounded-md bg-white/70 px-2 py-1 dark:bg-white/8">
              {movie.genre}
            </span>
          ) : null}
          <span className="rounded-md bg-teal-100 px-2 py-1 text-teal-800 dark:bg-cyan-300/12 dark:text-cyan-100">
            {seasonCount} season{seasonCount === 1 ? "" : "s"}
          </span>
          <span className="inline-flex items-center gap-1 rounded-md bg-white/70 px-2 py-1 dark:bg-white/8">
            <ListVideo className="size-3" />
            {episodeCount} episode{episodeCount === 1 ? "" : "s"}
          </span>
        </span>
      </span>
      <span className="inline-flex shrink-0 justify-center rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition group-hover:bg-teal-700 dark:bg-white dark:text-slate-950 dark:group-hover:bg-cyan-200">
        View seasons
      </span>
    </button>
  );
}

function SeasonIdPicker({
  seasonId,
  movieId,
  searchQuery,
  searchState,
  selectedSeasonLabel,
  selectedMovieLabel,
  isPending,
  onSeasonIdChange,
  onSearchQueryChange,
  onSelectSeason,
}: {
  seasonId: string;
  movieId: string;
  searchQuery: string;
  searchState: SeasonSearchState;
  selectedSeasonLabel: string;
  selectedMovieLabel: string;
  isPending: boolean;
  onSeasonIdChange: (value: string) => void;
  onSearchQueryChange: (value: string) => void;
  onSelectSeason: (
    movie: SeasonMovieSearchResult,
    season: SeasonSearchResultItem,
  ) => void;
}) {
  const [selectedMovie, setSelectedMovie] =
    useState<SeasonMovieSearchResult | null>(null);
  const selectedMovieResult =
    selectedMovie && searchState.results.some(
      (movie) =>
        (movie.movieId || movie.movieNum || movie.title) ===
        (selectedMovie.movieId || selectedMovie.movieNum || selectedMovie.title),
    )
      ? selectedMovie
      : null;
  const visibleSeasonResults = selectedMovieResult
    ? selectedMovieResult.seasons.map((season) => ({
        movie: selectedMovieResult,
        season,
      }))
    : [];
  const movieCount = searchState.results.length;
  const seasonCount = searchState.results.reduce(
    (total, movie) => total + movie.seasons.length,
    0,
  );

  useEffect(() => {
    setSelectedMovie(null);
  }, [searchQuery, searchState]);

  return (
    <div className="lg:col-span-2">
      <label className="block min-w-0">
        <span className="flex flex-wrap items-center justify-between gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
          <span>Find season</span>
          {seasonCount > 0 ? (
            <span className="rounded-full bg-teal-100 px-2.5 py-1 text-xs font-bold text-teal-800 dark:bg-cyan-300/12 dark:text-cyan-100">
              {movieCount} movie{movieCount === 1 ? "" : "s"} / {seasonCount} season
              {seasonCount === 1 ? "" : "s"}
            </span>
          ) : null}
        </span>
        <div className="mt-2 flex min-h-12 items-center gap-2 rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-3 shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition focus-within:border-teal-300 focus-within:bg-white/76 focus-within:ring-4 focus-within:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:focus-within:border-cyan-300/60 dark:focus-within:bg-white/12 dark:focus-within:ring-cyan-300/15">
          <Search className="size-4 shrink-0 text-teal-700 dark:text-cyan-200" />
          <input
            type="search"
            value={searchQuery}
            onChange={(event) => onSearchQueryChange(event.target.value)}
            placeholder="Search movie title, or leave blank to browse"
            className="min-h-11 min-w-0 flex-1 bg-transparent text-sm font-semibold text-slate-950 outline-none placeholder:text-slate-400 dark:text-white dark:placeholder:text-slate-500"
          />
          {searchQuery ? (
            <button
              type="button"
              onClick={() => onSearchQueryChange("")}
              className="inline-flex size-8 shrink-0 items-center justify-center rounded-md text-slate-500 transition hover:bg-white/70 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white"
              aria-label="Show all recent seasons"
            >
              <X className="size-4" />
            </button>
          ) : null}
          {isPending ? (
            <span className="size-4 shrink-0 animate-spin rounded-full border-2 border-teal-600 border-t-transparent dark:border-cyan-200 dark:border-t-transparent" />
          ) : null}
        </div>
      </label>

      <div className="mt-2 min-h-5 text-xs font-semibold text-slate-500 dark:text-slate-400">
        {isPending
          ? "Searching seasons..."
          : selectedMovieResult
            ? `Showing seasons from ${selectedMovieResult.title}.`
            : searchState.message}
      </div>

      {!selectedMovieResult && searchState.results.length > 0 ? (
        <div className="admin-sidebar-scroll mt-3 grid max-h-96 gap-2 overflow-y-auto rounded-lg border border-white/55 bg-white/30 p-2 dark:border-white/10 dark:bg-white/5 sm:grid-cols-2">
          {searchState.results.map((movie, index) => (
            <MovieSearchResultCard
              key={movie.movieId || movie.movieNum || `${movie.title}-${index}`}
              movie={movie}
              onSelect={setSelectedMovie}
            />
          ))}
        </div>
      ) : null}

      {selectedMovieResult ? (
        <div className="mt-3 rounded-lg border border-white/55 bg-white/34 p-2 dark:border-white/10 dark:bg-white/5">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2 px-1">
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-teal-700 dark:text-cyan-200">
              {selectedMovieResult.title}
            </p>
            <button
              type="button"
              onClick={() => setSelectedMovie(null)}
              className="inline-flex min-h-8 items-center justify-center rounded-md bg-white/70 px-3 text-xs font-bold text-slate-700 transition hover:bg-white dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12"
            >
              Back to movies
            </button>
          </div>
        <div className="admin-sidebar-scroll mt-3 grid max-h-96 gap-2 overflow-y-auto rounded-lg border border-white/55 bg-white/30 p-2 dark:border-white/10 dark:bg-white/5 sm:grid-cols-2">
          {visibleSeasonResults.map(({ movie, season }) => (
            <SeasonSearchResultCard
              key={`${movie.movieId || movie.movieNum}-${season.id}`}
              movie={movie}
              season={season}
              onSelect={onSelectSeason}
            />
          ))}
        </div>
        </div>
      ) : searchState.status !== "idle" && !isPending ? (
        <div className="mt-3 rounded-lg border border-white/55 bg-white/45 p-4 text-sm font-semibold text-slate-600 dark:border-white/10 dark:bg-white/6 dark:text-slate-300">
          No season found. Clear the search to browse recent seasons.
        </div>
      ) : null}

      <div className="mt-4 grid gap-4 md:grid-cols-2">
        <input name="movie_id" type="hidden" value={movieId} />
        <label className="block min-w-0">
          <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
            Season ID
          </span>
          <input
            name="season_id"
            type="text"
            value={seasonId}
            onChange={(event) => onSeasonIdChange(event.target.value)}
            placeholder="Season UUID"
            required
            className={inputClassName}
          />
          <span className="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">
            {selectedSeasonLabel || "Choose a season above to fill this value."}
          </span>
        </label>

        <label className="block min-w-0">
          <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
            Movie
          </span>
          <input
            type="text"
            value={selectedMovieLabel}
            readOnly
            placeholder="Choose a season above"
            className={inputClassName}
          />
          <span className="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">
            Movie ID is saved in the background for the stream URL.
          </span>
        </label>
      </div>
    </div>
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

export function AddEpisodeForm() {
  const { isDesktopSidebarOpen } = useAdminSidebar();
  const formRef = useRef<HTMLFormElement>(null);
  const lastToastKeyRef = useRef("");
  const lastSeasonSearchRequestRef = useRef(0);
  const skipNextSeasonSearchRef = useRef(false);
  const [seasonId, setSeasonId] = useState("");
  const [movieId, setMovieId] = useState("");
  const [episodeNumber, setEpisodeNumber] = useState("");
  const [episodeTitle, setEpisodeTitle] = useState("");
  const [isEpisodeTitleTouched, setIsEpisodeTitleTouched] = useState(false);
  const [seasonSearchQuery, setSeasonSearchQuery] = useState("");
  const [selectedSeasonLabel, setSelectedSeasonLabel] = useState("");
  const [selectedMovieLabel, setSelectedMovieLabel] = useState("");
  const [seasonSearchState, setSeasonSearchState] = useState(
    initialSeasonSearchState,
  );
  const resetSeasonPicker = useCallback(() => {
    setSeasonId("");
    setMovieId("");
    setEpisodeNumber("");
    setEpisodeTitle("");
    setIsEpisodeTitleTouched(false);
    setSeasonSearchQuery("");
    setSelectedSeasonLabel("");
    setSelectedMovieLabel("");
    setSeasonSearchState(initialSeasonSearchState);
  }, []);
  const submitEpisodeAction = useCallback(
    async (previousState: AddEpisodeFormState, formData: FormData) => {
      const nextState = await createEpisodeAction(previousState, formData);

      if (nextState.status === "success") {
        formRef.current?.reset();
        resetSeasonPicker();
      }

      return nextState as AddEpisodeFormState;
    },
    [resetSeasonPicker],
  );
  const [state, formAction, isPending] = useActionState(
    submitEpisodeAction,
    initialState,
  );
  const [isSeasonSearchPending, startSeasonSearchTransition] = useTransition();
  const episodeResetToken =
    state.status === "success" ? (state.resetKey ?? state.message) : "";

  const statusMessage = useMemo(() => {
    if (state.status === "success") {
      const ids = [
        state.episodeId ? `Episode ID: ${state.episodeId}` : "",
        state.videoUrlId ? `Stream ID: ${state.videoUrlId}` : "",
      ]
        .filter(Boolean)
        .join(" ");

      return ids ? `${state.message} ${ids}` : state.message;
    }

    return state.message;
  }, [state]);

  useEffect(() => {
    if (skipNextSeasonSearchRef.current) {
      skipNextSeasonSearchRef.current = false;
      return;
    }

    const query = seasonSearchQuery.trim();
    const requestId = lastSeasonSearchRequestRef.current + 1;
    lastSeasonSearchRequestRef.current = requestId;

    if (!query) {
      const timeoutId = window.setTimeout(() => {
        startSeasonSearchTransition(async () => {
          const nextState = await searchSeasonsAction("");

          if (lastSeasonSearchRequestRef.current === requestId) {
            setSeasonSearchState(nextState);
          }
        });
      }, 350);

      return () => window.clearTimeout(timeoutId);
    }

    const timeoutId = window.setTimeout(() => {
      startSeasonSearchTransition(async () => {
        const nextState = await searchSeasonsAction(query);

        if (lastSeasonSearchRequestRef.current === requestId) {
          setSeasonSearchState(nextState);
        }
      });
    }, 350);

    return () => window.clearTimeout(timeoutId);
  }, [seasonSearchQuery]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(statusMessage || "Episode saved successfully.");
      return;
    }

    toast.error(statusMessage || "Episode could not be saved.");
  }, [state.message, state.resetKey, state.status, statusMessage]);

  const handleSeasonSearchQueryChange = (value: string) => {
    setSeasonSearchQuery(value);
    setSelectedSeasonLabel("");
    setSelectedMovieLabel("");
    setSeasonId("");
    setMovieId("");
  };

  const handleSeasonIdChange = (value: string) => {
    setSeasonId(value);
    setSelectedSeasonLabel("");
    setSelectedMovieLabel("");
  };

  const handleSelectSeason = (
    movie: SeasonMovieSearchResult,
    season: SeasonSearchResultItem,
  ) => {
    skipNextSeasonSearchRef.current = true;
    lastSeasonSearchRequestRef.current += 1;
    setSeasonId(season.id);
    setMovieId(movie.movieNum);
    setSeasonSearchQuery(movie.title);
    setSelectedMovieLabel(movie.title);
    setSelectedSeasonLabel(
      `${season.title} from ${movie.title}${
        season.seasonNumber ? `, season ${season.seasonNumber}` : ""
      }`,
    );
    setSeasonSearchState({
      status: "success",
      message: "Season selected.",
      results: [],
    });
  };

  const handleEpisodeNumberChange = (value: string) => {
    setEpisodeNumber(value);

    if (!isEpisodeTitleTouched) {
      setEpisodeTitle(value ? `Episode ${value}` : "");
    }
  };

  const handleEpisodeTitleChange = (value: string) => {
    setEpisodeTitle(value);
    setIsEpisodeTitleTouched(value.trim().length > 0);
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
          <FormSection
            title="Episode details"
            eyebrow="Required"
            icon={ListVideo}
          >
            <div className="grid gap-4 lg:grid-cols-2">
              <SeasonIdPicker
                seasonId={seasonId}
                movieId={movieId}
                searchQuery={seasonSearchQuery}
                searchState={seasonSearchState}
                selectedSeasonLabel={selectedSeasonLabel}
                selectedMovieLabel={selectedMovieLabel}
                isPending={isSeasonSearchPending}
                onSeasonIdChange={handleSeasonIdChange}
                onSearchQueryChange={handleSeasonSearchQueryChange}
                onSelectSeason={handleSelectSeason}
              />
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Episode number
                </span>
                <input
                  name="episode_number"
                  type="number"
                  min={1}
                  value={episodeNumber}
                  onChange={(event) =>
                    handleEpisodeNumberChange(event.target.value)
                  }
                  placeholder="1"
                  required
                  className={inputClassName}
                />
              </label>
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Episode title
                </span>
                <input
                  name="title"
                  type="text"
                  value={episodeTitle}
                  onChange={(event) =>
                    handleEpisodeTitleChange(event.target.value)
                  }
                  placeholder="Episode 1"
                  className={inputClassName}
                />
              </label>
              <Field
                label="Description"
                name="description"
                placeholder="Short episode synopsis"
                multiline
                className="lg:col-span-2"
              />
            </div>
          </FormSection>

          <FormSection title="Playback" eyebrow="Stream" icon={PlayCircle}>
            <div className="grid gap-4">
              <VideoUrlField
                key={`video-url-${episodeResetToken}`}
                label="Stream URL"
                name="video_url"
                placeholder="https://..."
                required
                helper="This saves after the episode is created."
              />
              <div className="grid gap-4 sm:grid-cols-2">
                <Field
                  label="Quality"
                  name="video_quality"
                  placeholder="HD"
                  helper="Default is HD."
                />
                <label className="block min-w-0">
                  <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                    Type
                  </span>
                  <select
                    name="video_type"
                    defaultValue="DASH"
                    className={selectClassName}
                  >
                    <option value="DASH" className={optionClassName}>
                      DASH
                    </option>
                    <option value="HLS" className={optionClassName}>
                      HLS
                    </option>
                    <option value="MP4" className={optionClassName}>
                      MP4
                    </option>
                    <option value="Other" className={optionClassName}>
                      Other
                    </option>
                  </select>
                </label>
              </div>
            </div>
          </FormSection>
        </div>

        <aside className="space-y-4">
          <FormSection title="Release" eyebrow="Schedule" icon={CalendarDays}>
            <div className="grid gap-4">
              <Field
                label="Release date"
                name="release_date"
                type="date"
                helper="Use the planned viewer release date."
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
              <SwitchPill name="is_active" label="Active" defaultChecked />
              <SwitchPill name="isPremium" label="Premium" />
              <SwitchPill name="isPayPerView" label="Pay per view" />
              <Field
                label="Pay per view amount"
                name="amount"
                type="number"
                min={0}
                step="0.01"
                placeholder="0"
                helper="Used only when pay per view is enabled."
              />
            </div>
          </FormSection>

          <FormSection title="Thumbnail" eyebrow="Image" icon={ImageIcon}>
            <ThumbnailUrlField key={`thumbnail-${episodeResetToken}`} />
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
          <span>Save the episode and stream URL together.</span>
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
              Save episode
            </>
          )}
        </button>
      </div>
    </form>
  );
}
