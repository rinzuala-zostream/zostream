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
  Film,
  ImagePlus,
  LinkIcon,
  RotateCcw,
  Save,
  Search,
  ShieldAlert,
  Sparkles,
} from "lucide-react";
import { toast } from "react-toastify";
import {
  addBannerAction,
  type AddBannerFormState,
} from "@/app/(admin)/banners/add/actions";
import {
  searchMoviesAction,
  type MovieSearchResultItem,
  type MovieSearchState,
} from "@/app/(admin)/movies/update/actions";
import { useAdminSidebar } from "@/app/components/admin-sidebar-shell";
import { cn } from "@/lib/utils";

const initialState: AddBannerFormState = {
  status: "idle",
  message: "",
};

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const selectClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm font-semibold text-slate-950 [color-scheme:light] outline-none shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:[color-scheme:dark] dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";
const optionClassName =
  "bg-white text-slate-950 dark:bg-slate-950 dark:text-white";
const initialMovieSearchState: MovieSearchState = {
  status: "idle",
  message: "Search by movie title, then choose a movie.",
  results: [],
};

function statusText(state: AddBannerFormState) {
  if (state.status === "success" && state.bannerId) {
    return `${state.message} Banner ID: ${state.bannerId}`;
  }

  return state.message;
}

function movieTargetId(movie: MovieSearchResultItem) {
  return movie.id || movie.movieNum;
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
      className="flex w-full items-center gap-3 rounded-md border border-[rgba(15,23,42,0.12)] bg-white/88 p-3 text-left shadow-[0_10px_28px_rgba(15,23,42,0.1)] transition hover:-translate-y-0.5 hover:border-teal-200 hover:bg-white dark:border-white/10 dark:bg-slate-950/88 dark:hover:border-cyan-300/30 dark:hover:bg-slate-900"
    >
      <span className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md bg-slate-200 text-slate-500 dark:bg-white/8 dark:text-slate-400">
        {movie.coverImage ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={movie.coverImage}
            alt={movie.title}
            className="h-full w-full object-cover"
          />
        ) : (
          <Film className="size-5" />
        )}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-bold text-slate-950 dark:text-white">
          {movie.title}
        </span>
        <span className="mt-1 block truncate text-xs font-semibold text-slate-500 dark:text-slate-400">
          Movie {movieTargetId(movie)}
          {movie.status ? ` - ${movie.status}` : ""}
        </span>
      </span>
      <span className="hidden shrink-0 rounded-md bg-slate-950 px-2.5 py-1.5 text-xs font-bold text-white dark:bg-white dark:text-slate-950 sm:inline-flex">
        Select
      </span>
    </button>
  );
}

export function CreateBannerForm() {
  const { isDesktopSidebarOpen } = useAdminSidebar();
  const formRef = useRef<HTMLFormElement>(null);
  const lastToastKeyRef = useRef("");
  const lastMovieSearchRequestRef = useRef(0);
  const skipNextMovieSearchRef = useRef(false);
  const [title, setTitle] = useState("");
  const [buttonText, setButtonText] = useState("");
  const [description, setDescription] = useState("");
  const [bannerType, setBannerType] = useState("movie");
  const [mediaUrl, setMediaUrl] = useState("");
  const [thumbnailUrl, setThumbnailUrl] = useState("");
  const [targetType, setTargetType] = useState("movie");
  const [targetId, setTargetId] = useState("");
  const [targetUrl, setTargetUrl] = useState("");
  const [movieSearchQuery, setMovieSearchQuery] = useState("");
  const [selectedMovieLabel, setSelectedMovieLabel] = useState("");
  const [movieSearchState, setMovieSearchState] = useState(
    initialMovieSearchState,
  );
  const [state, formAction, isPending] = useActionState(
    addBannerAction,
    initialState,
  );
  const [isMovieSearchPending, startMovieSearchTransition] = useTransition();
  const message = useMemo(
    () => statusText(state),
    [state.bannerId, state.message, state.status],
  );

  useEffect(() => {
    if (state.status === "success") {
      formRef.current?.reset();
      window.setTimeout(() => {
        setTitle("");
        setButtonText("");
        setDescription("");
        setBannerType("movie");
        setMediaUrl("");
        setThumbnailUrl("");
        setTargetType("movie");
        setTargetId("");
        setTargetUrl("");
        setMovieSearchQuery("");
        setSelectedMovieLabel("");
        setMovieSearchState(initialMovieSearchState);
      }, 0);
    }
  }, [state.status, state.resetKey]);

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
          setMovieSearchState(nextState);
        }
      });
    }, 350);

    return () => window.clearTimeout(timeoutId);
  }, [movieSearchQuery, startMovieSearchTransition]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(message || "Banner created.");
      return;
    }

    toast.error(message || "Banner could not be created.");
  }, [message, state.message, state.resetKey, state.status]);

  const handleMovieSearchQueryChange = (value: string) => {
    setMovieSearchQuery(value);
    setSelectedMovieLabel("");
  };

  const resetForm = () => {
    formRef.current?.reset();
    setTitle("");
    setButtonText("");
    setDescription("");
    setBannerType("movie");
    setMediaUrl("");
    setThumbnailUrl("");
    setTargetType("movie");
    setTargetId("");
    setTargetUrl("");
    setMovieSearchQuery("");
    setSelectedMovieLabel("");
    setMovieSearchState(initialMovieSearchState);
  };

  const handleSelectMovie = (movie: MovieSearchResultItem) => {
    const nextTargetId = movieTargetId(movie);
    const nextMediaUrl = movie.coverImage;

    skipNextMovieSearchRef.current = true;
    lastMovieSearchRequestRef.current += 1;
    setTitle(movie.title);
    setButtonText("Watch now");
    setDescription(movie.description);
    setBannerType("movie");
    setMediaUrl(nextMediaUrl);
    setThumbnailUrl(nextMediaUrl);
    setTargetType("movie");
    setTargetId(nextTargetId);
    setTargetUrl(movie.movieUrl);
    setMovieSearchQuery(movie.title);
    setSelectedMovieLabel(`${movie.title} - Movie ${nextTargetId}`);
    setMovieSearchState({
      status: "success",
      message: "Movie selected. Banner fields filled.",
      results: [],
    });
  };

  return (
    <form ref={formRef} action={formAction} className="space-y-4 pb-28">
      <div className="flex justify-end">
        <button
          type="button"
          onClick={resetForm}
          className="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/58 px-3 text-sm font-bold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:-translate-y-0.5 hover:border-[rgba(15,23,42,0.22)] hover:bg-white/80 hover:text-teal-700 dark:border-white/10 dark:bg-white/8 dark:text-slate-200 dark:hover:bg-white/12 dark:hover:text-cyan-200"
        >
          <RotateCcw className="size-4" />
          Reset
        </button>
      </div>

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

      <section className="liquid-glass relative z-30 rounded-lg p-4 shadow-[0_18px_54px_rgba(15,23,42,0.12)] sm:p-5 dark:shadow-[0_18px_54px_rgba(2,6,23,0.48)]">
        <label className="block min-w-0">
          <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
            Find movie
          </span>
          <div className="mt-2 flex min-h-12 items-center gap-2 rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-3 shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition focus-within:border-teal-300 focus-within:bg-white/76 focus-within:ring-4 focus-within:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:focus-within:border-cyan-300/60 dark:focus-within:bg-white/12 dark:focus-within:ring-cyan-300/15">
            <Search className="size-4 shrink-0 text-teal-700 dark:text-cyan-200" />
            <input
              type="search"
              value={movieSearchQuery}
              onChange={(event) =>
                handleMovieSearchQueryChange(event.target.value)
              }
              placeholder="Search movie title"
              className="min-h-11 min-w-0 flex-1 bg-transparent text-sm font-semibold text-slate-950 outline-none placeholder:text-slate-400 dark:text-white dark:placeholder:text-slate-500"
            />
            {isMovieSearchPending ? (
              <span className="size-4 shrink-0 animate-spin rounded-full border-2 border-teal-600 border-t-transparent dark:border-cyan-200 dark:border-t-transparent" />
            ) : null}
          </div>
        </label>

        <div className="mt-2 min-h-5 text-xs font-semibold text-slate-500 dark:text-slate-400">
          {selectedMovieLabel ||
            (isMovieSearchPending
              ? "Searching movies..."
              : movieSearchState.message)}
        </div>

        {movieSearchState.results.length > 0 ? (
          <div className="absolute left-4 right-4 top-[calc(100%-0.75rem)] z-40 max-h-96 overflow-y-auto rounded-lg border border-white/60 bg-white/82 p-2 shadow-[0_24px_70px_rgba(15,23,42,0.22)] backdrop-blur-xl dark:border-white/10 dark:bg-slate-950/82 dark:shadow-[0_24px_70px_rgba(2,6,23,0.58)]">
            <div className="grid gap-2">
              {movieSearchState.results.map((movie, index) => (
                <MovieSearchResultCard
                  key={movie.movieNum || movie.id || `${movie.title}-${index}`}
                  movie={movie}
                  onSelect={handleSelectMovie}
                />
              ))}
            </div>
          </div>
        ) : null}
      </section>

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
              value={title}
              onChange={(event) => setTitle(event.target.value)}
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
              value={buttonText}
              onChange={(event) => setButtonText(event.target.value)}
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
              value={description}
              onChange={(event) => setDescription(event.target.value)}
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
              value={bannerType}
              onChange={(event) => setBannerType(event.target.value)}
              required
              className={selectClassName}
            >
              <option value="movie" className={optionClassName}>
                Movie
              </option>
              <option value="ad" className={optionClassName}>
                Ad
              </option>
              <option value="external" className={optionClassName}>
                External
              </option>
              <option value="category" className={optionClassName}>
                Category
              </option>
              <option value="custom" className={optionClassName}>
                Custom
              </option>
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
              defaultValue={0}
              className={inputClassName}
            />
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Media type
            </span>
            <select
              name="media_type"
              defaultValue="image"
              required
              className={selectClassName}
            >
              <option value="image" className={optionClassName}>
                Image
              </option>
              <option value="video" className={optionClassName}>
                Video
              </option>
            </select>
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Media URL
            </span>
            <input
              name="media_url"
              type="url"
              value={mediaUrl}
              onChange={(event) => setMediaUrl(event.target.value)}
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
              value={thumbnailUrl}
              onChange={(event) => setThumbnailUrl(event.target.value)}
              placeholder="https://..."
              className={inputClassName}
            />
          </label>

          <label className="group flex min-h-12 items-center justify-between gap-3 self-end rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/64 dark:border-white/10 dark:bg-white/6 dark:text-slate-200 dark:hover:bg-white/10">
            <span>Banner active</span>
            <input
              type="checkbox"
              name="is_active"
              defaultChecked
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
              value={targetType}
              onChange={(event) => setTargetType(event.target.value)}
              required
              className={selectClassName}
            >
              <option value="movie" className={optionClassName}>
                Movie
              </option>
              <option value="series" className={optionClassName}>
                Series
              </option>
              <option value="episode" className={optionClassName}>
                Episode
              </option>
              <option value="url" className={optionClassName}>
                URL
              </option>
              <option value="category" className={optionClassName}>
                Category
              </option>
              <option value="none" className={optionClassName}>
                None
              </option>
            </select>
          </label>

          <label className="block min-w-0">
            <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
              Target ID
            </span>
            <input
              name="target_id"
              type="text"
              value={targetId}
              onChange={(event) => setTargetId(event.target.value)}
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
              value={targetUrl}
              onChange={(event) => setTargetUrl(event.target.value)}
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
              className="peer sr-only"
            />
            <span className="relative h-6 w-11 shrink-0 rounded-full bg-slate-300/80 after:absolute after:left-1 after:top-1 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition after:content-[''] peer-checked:bg-teal-500 peer-checked:after:translate-x-5 dark:bg-slate-700 dark:peer-checked:bg-cyan-400" />
          </label>

          <label className="group flex min-h-12 items-center justify-between gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/64 dark:border-white/10 dark:bg-white/6 dark:text-slate-200 dark:hover:bg-white/10">
            <span>Require parental PIN</span>
            <input
              type="checkbox"
              name="requires_parental_pin"
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
              defaultValue=""
              className={selectClassName}
            >
              <option value="" className={optionClassName}>
                No rating
              </option>
              <option value="G" className={optionClassName}>
                G
              </option>
              <option value="PG" className={optionClassName}>
                PG
              </option>
              <option value="PG13" className={optionClassName}>
                PG13
              </option>
              <option value="R" className={optionClassName}>
                R
              </option>
              <option value="18+" className={optionClassName}>
                18+
              </option>
              <option value="21+" className={optionClassName}>
                21+
              </option>
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
          <span>Create a banner placement for the app.</span>
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
              Add banner
            </>
          )}
        </button>
      </div>
    </form>
  );
}
