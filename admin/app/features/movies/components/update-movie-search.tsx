"use client";

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  useSyncExternalStore,
  useTransition,
} from "react";
import Link from "next/link";
import {
  CalendarDays,
  Clock3,
  Film,
  Mic,
  MicOff,
  PencilLine,
  Search,
  Trash2,
} from "lucide-react";
import {
  deleteMovieAction,
  searchMoviesAction,
  type MovieSearchResultItem,
  type MovieSearchState,
} from "@/app/(admin)/movies/update/actions";
import { ConfirmDialog } from "@/app/components/confirm-dialog";
import { cn } from "@/lib/utils";
import { toast } from "react-toastify";

type SpeechRecognitionAlternativeLike = {
  transcript: string;
};

type SpeechRecognitionResultLike = {
  isFinal: boolean;
  0: SpeechRecognitionAlternativeLike;
};

type SpeechRecognitionResultListLike = {
  length: number;
  item(index: number): SpeechRecognitionResultLike;
  [index: number]: SpeechRecognitionResultLike;
};

type SpeechRecognitionEventLike = Event & {
  results: SpeechRecognitionResultListLike;
};

type SpeechRecognitionErrorEventLike = Event & {
  error?: string;
};

type SpeechRecognitionLike = EventTarget & {
  continuous: boolean;
  interimResults: boolean;
  lang: string;
  start: () => void;
  stop: () => void;
  onstart: (() => void) | null;
  onend: (() => void) | null;
  onresult: ((event: SpeechRecognitionEventLike) => void) | null;
  onerror: ((event: SpeechRecognitionErrorEventLike) => void) | null;
};

type SpeechRecognitionConstructor = new () => SpeechRecognitionLike;

type SpeechWindow = Window &
  typeof globalThis & {
    SpeechRecognition?: SpeechRecognitionConstructor;
    webkitSpeechRecognition?: SpeechRecognitionConstructor;
  };

const initialSearchState: MovieSearchState = {
  status: "idle",
  message: "Search by movie title, director, or keyword.",
  results: [],
};

const movieSearchStorageKey = "zostream-admin-movie-update-search";

type StoredMovieSearch = {
  query: string;
  state: MovieSearchState;
};

function isMovieSearchState(value: unknown): value is MovieSearchState {
  if (typeof value !== "object" || value === null) return false;

  const searchState = value as Partial<MovieSearchState>;
  return (
    (searchState.status === "idle" ||
      searchState.status === "success" ||
      searchState.status === "error") &&
    typeof searchState.message === "string" &&
    Array.isArray(searchState.results)
  );
}

function readStoredMovieSearch(): StoredMovieSearch {
  if (typeof window === "undefined") {
    return {
      query: "",
      state: initialSearchState,
    };
  }

  try {
    const rawValue = window.sessionStorage.getItem(movieSearchStorageKey);
    if (!rawValue) {
      return {
        query: "",
        state: initialSearchState,
      };
    }

    const parsedValue = JSON.parse(rawValue) as Partial<StoredMovieSearch>;
    const query =
      typeof parsedValue.query === "string" ? parsedValue.query : "";

    return {
      query,
      state: isMovieSearchState(parsedValue.state)
        ? parsedValue.state
        : initialSearchState,
    };
  } catch {
    return {
      query: "",
      state: initialSearchState,
    };
  }
}

function writeStoredMovieSearch(query: string, state: MovieSearchState) {
  if (typeof window === "undefined") return;

  window.sessionStorage.setItem(
    movieSearchStorageKey,
    JSON.stringify({
      query,
      state,
    }),
  );
}

function getSpeechRecognition() {
  if (typeof window === "undefined") return undefined;

  const speechWindow = window as SpeechWindow;
  return speechWindow.SpeechRecognition ?? speechWindow.webkitSpeechRecognition;
}

function subscribeToSpeechSupport(onStoreChange: () => void) {
  if (typeof window === "undefined") return () => {};

  const timeoutId = window.setTimeout(onStoreChange, 0);
  return () => window.clearTimeout(timeoutId);
}

function getSpeechSupportSnapshot() {
  return Boolean(getSpeechRecognition());
}

function getSpeechSupportServerSnapshot() {
  return false;
}

function MovieResultItem({
  movie,
  onDeleteMovie,
}: {
  movie: MovieSearchResultItem;
  onDeleteMovie: (movie: MovieSearchResultItem) => void;
}) {
  return (
    <li className="liquid-glass-soft overflow-hidden rounded-lg p-3 shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:shadow-[0_18px_42px_rgba(2,6,23,0.35)]">
      <div className="flex flex-col gap-3 sm:flex-row sm:gap-4">
        <div className="relative flex aspect-video w-full shrink-0 items-center justify-center overflow-hidden rounded-md bg-slate-200 text-slate-500 dark:bg-white/8 dark:text-slate-400 sm:w-44">
          {movie.coverImage ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={movie.coverImage}
              alt={movie.title}
              className="h-full w-full object-cover"
            />
          ) : (
            <Film className="size-7" />
          )}
        </div>

        <div className="min-w-0 flex-1">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0">
              <h2 className="truncate text-base font-bold text-slate-950 dark:text-white">
                {movie.title}
              </h2>
            </div>

            {movie.id ? (
              <div className="flex shrink-0 items-center gap-2 sm:justify-end">
                <Link
                  href={`/movies/update/${movie.id}`}
                  className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 text-sm font-bold text-white shadow-[0_12px_26px_rgba(15,23,42,0.16)] transition hover:-translate-y-0.5 hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                >
                  <PencilLine className="size-4" />
                  Edit
                </Link>
                <button
                  type="button"
                  onClick={() => onDeleteMovie(movie)}
                  className="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-rose-200/80 bg-rose-50/90 px-4 text-sm font-bold text-rose-700 shadow-[0_10px_24px_rgba(225,29,72,0.08)] transition hover:-translate-y-0.5 hover:border-rose-300 hover:bg-rose-100 dark:border-rose-300/20 dark:bg-rose-300/10 dark:text-rose-100 dark:hover:bg-rose-300/16"
                >
                  <Trash2 className="size-4" />
                  Delete
                </button>
              </div>
            ) : null}
          </div>

          <div className="mt-3 flex flex-wrap gap-2 text-xs font-semibold text-slate-600 dark:text-slate-300">
            {movie.id ? (
              <span className="inline-flex min-h-8 items-center rounded-md bg-slate-950 px-3 text-white dark:bg-white dark:text-slate-950">
                ID {movie.id}
              </span>
            ) : null}
            <span className="inline-flex min-h-8 items-center rounded-md bg-white/55 px-3 dark:bg-white/8">
              {movie.status}
            </span>
            {movie.releaseOn ? (
              <span className="inline-flex min-h-8 items-center gap-1.5 rounded-md bg-white/55 px-3 dark:bg-white/8">
                <CalendarDays className="size-3.5" />
                {movie.releaseOn}
              </span>
            ) : null}
            {movie.duration ? (
              <span className="inline-flex min-h-8 items-center gap-1.5 rounded-md bg-white/55 px-3 dark:bg-white/8">
                <Clock3 className="size-3.5" />
                {movie.duration}
              </span>
            ) : null}
          </div>
        </div>
      </div>
    </li>
  );
}

export function UpdateMovieSearch() {
  const recognitionRef = useRef<SpeechRecognitionLike | null>(null);
  const hasTypedRef = useRef(false);
  const searchRequestRef = useRef(0);
  const [query, setQuery] = useState("");
  const [speechMessage, setSpeechMessage] = useState("");
  const [isListening, setIsListening] = useState(false);
  const [movieToDelete, setMovieToDelete] =
    useState<MovieSearchResultItem | null>(null);
  const [searchState, setSearchState] =
    useState<MovieSearchState>(initialSearchState);
  const [isPending, startTransition] = useTransition();
  const [isDeletePending, startDeleteTransition] = useTransition();
  const speechSupported = useSyncExternalStore(
    subscribeToSpeechSupport,
    getSpeechSupportSnapshot,
    getSpeechSupportServerSnapshot,
  );

  useEffect(() => {
    return () => {
      recognitionRef.current?.stop();
    };
  }, []);

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      const storedSearch = readStoredMovieSearch();
      setQuery(storedSearch.query);
      setSearchState(storedSearch.state);
    }, 0);

    return () => window.clearTimeout(timeoutId);
  }, []);

  const runSearch = useCallback((nextQuery = query) => {
    const requestId = searchRequestRef.current + 1;
    searchRequestRef.current = requestId;

    startTransition(async () => {
      const nextState = await searchMoviesAction(nextQuery);
      if (searchRequestRef.current === requestId) {
        setSearchState(nextState);
        writeStoredMovieSearch(nextQuery, nextState);
      }
    });
  }, [query, startTransition]);

  useEffect(() => {
    if (!hasTypedRef.current) return;

    const timeoutId = window.setTimeout(() => {
      runSearch(query);
    }, 350);

    return () => window.clearTimeout(timeoutId);
  }, [query, runSearch]);

  const confirmDeleteMovie = () => {
    if (!movieToDelete) return;

    startDeleteTransition(async () => {
      const result = await deleteMovieAction(movieToDelete.id);

      if (result.status === "success") {
        toast.success(result.message);
        setMovieToDelete(null);
        runSearch(query);
        return;
      }

      toast.error(result.message);
    });
  };

  const startVoiceSearch = () => {
    const SpeechRecognition = getSpeechRecognition();

    if (!SpeechRecognition) {
      setSpeechMessage("Voice search is not supported in this browser.");
      return;
    }

    recognitionRef.current?.stop();

    const recognition = new SpeechRecognition();
    recognitionRef.current = recognition;
    recognition.continuous = false;
    recognition.interimResults = true;
    recognition.lang = "en-US";

    recognition.onstart = () => {
      setIsListening(true);
      setSpeechMessage("Listening...");
    };

    recognition.onend = () => {
      setIsListening(false);
    };

    recognition.onerror = (event) => {
      setIsListening(false);
      setSpeechMessage(
        event.error === "not-allowed"
          ? "Microphone permission was blocked."
          : "Voice search stopped. Try again.",
      );
    };

    recognition.onresult = (event) => {
      let transcript = "";
      let hasFinalResult = false;

      for (let index = 0; index < event.results.length; index += 1) {
        const result = event.results.item(index);
        transcript += result[0]?.transcript ?? "";
        hasFinalResult = hasFinalResult || result.isFinal;
      }

      const nextQuery = transcript.trim();

      if (nextQuery) {
        setQuery(nextQuery);
      }

      if (hasFinalResult && nextQuery) {
        setSpeechMessage("Voice captured.");
        runSearch(nextQuery);
      }
    };

    recognition.start();
  };

  return (
    <section className="space-y-4">
      <form
        onSubmit={(event) => {
          event.preventDefault();
          runSearch();
        }}
      >
        <div className="flex min-h-14 items-center gap-2 rounded-md border border-white/58 bg-white/68 px-3 shadow-[inset_0_1px_0_rgba(255,255,255,0.82),0_10px_30px_rgba(15,23,42,0.06)] dark:border-white/10 dark:bg-white/8">
          <Search className="size-5 shrink-0 text-teal-700 dark:text-cyan-200" />
          <input
            type="search"
            value={query}
            onChange={(event) => {
              const nextQuery = event.target.value;
              hasTypedRef.current = true;
              setQuery(nextQuery);
              writeStoredMovieSearch(nextQuery, searchState);
            }}
            placeholder="Search movies to update"
            className="min-h-12 min-w-0 flex-1 bg-transparent text-base font-semibold text-slate-950 outline-none placeholder:text-slate-400 dark:text-white dark:placeholder:text-slate-500"
          />
          <button
            type="button"
            onClick={startVoiceSearch}
            disabled={!speechSupported || isListening}
            aria-label={isListening ? "Listening" : "Start voice search"}
            title={
              speechSupported
                ? "Start voice search"
                : "Voice search is not supported in this browser"
            }
            className={cn(
              "inline-flex size-10 shrink-0 items-center justify-center rounded-md transition",
              isListening
                ? "bg-teal-500 text-white dark:bg-cyan-300 dark:text-slate-950"
                : "text-slate-600 hover:bg-white/72 hover:text-teal-700 disabled:cursor-not-allowed disabled:opacity-45 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-cyan-200",
            )}
          >
            {speechSupported ? (
              <Mic className="size-4" />
            ) : (
              <MicOff className="size-4" />
            )}
          </button>
          <button
            type="submit"
            disabled={isPending}
            className="inline-flex min-h-10 shrink-0 items-center justify-center rounded-md bg-slate-950 px-4 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-65 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
          >
            {isPending ? "Searching" : "Search"}
          </button>
        </div>
      </form>

      <div className="flex min-h-8 items-center justify-between gap-3 px-1 text-sm font-semibold text-slate-600 dark:text-slate-300">
        <p>{isPending ? "Searching movies..." : searchState.message}</p>
        {speechMessage ? <p>{speechMessage}</p> : null}
      </div>

      {searchState.results.length > 0 ? (
        <ul className="space-y-3">
          {searchState.results.map((movie, index) => (
            <MovieResultItem
              key={movie.id || `${movie.title}-${index}`}
              movie={movie}
              onDeleteMovie={setMovieToDelete}
            />
          ))}
        </ul>
      ) : null}

      <ConfirmDialog
        open={Boolean(movieToDelete)}
        title="Delete movie?"
        description={
          movieToDelete
            ? `This will permanently delete ${movieToDelete.title}. This action cannot be undone.`
            : "This action cannot be undone."
        }
        confirmLabel="Delete movie"
        isPending={isDeletePending}
        variant="danger"
        onClose={() => {
          if (!isDeletePending) {
            setMovieToDelete(null);
          }
        }}
        onConfirm={confirmDeleteMovie}
      />
    </section>
  );
}
