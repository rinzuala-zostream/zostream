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
  Clapperboard,
  Layers3,
  ListVideo,
  Mic,
  MicOff,
  PencilLine,
  Search,
  Trash2,
  X,
} from "lucide-react";
import {
  deleteEpisodeAction,
  deleteSeasonAction,
  searchSeasonsAction,
  type EpisodeSearchResultItem,
  type SeasonSearchResultItem,
  type SeasonMovieSearchResult,
  type SeasonSearchState,
} from "@/app/(admin)/seasons/update/actions";
import { ConfirmDialog } from "@/app/components/confirm-dialog";
import { EpisodeListSheet } from "@/app/features/seasons/components/episode-list-sheet";
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

const initialSearchState: SeasonSearchState = {
  status: "idle",
  message: "Loading seasons...",
  results: [],
};

const seasonSearchStorageKey = "zostream-admin-season-update-search";
const episodeSheetStorageKey = "zostream-admin-season-update-episode-sheet";

type StoredSeasonSearch = {
  query: string;
  state: SeasonSearchState;
};

type StoredEpisodeSheet = {
  seasonId: string;
  scrollTop: number;
};

function isSeasonSearchState(value: unknown): value is SeasonSearchState {
  if (typeof value !== "object" || value === null) return false;

  const searchState = value as Partial<SeasonSearchState>;
  return (
    (searchState.status === "idle" ||
      searchState.status === "success" ||
      searchState.status === "error") &&
    typeof searchState.message === "string" &&
    Array.isArray(searchState.results)
  );
}

function readStoredSeasonSearch(): StoredSeasonSearch {
  if (typeof window === "undefined") {
    return {
      query: "",
      state: initialSearchState,
    };
  }

  try {
    const rawValue = window.sessionStorage.getItem(seasonSearchStorageKey);
    if (!rawValue) {
      return {
        query: "",
        state: initialSearchState,
      };
    }

    const parsedValue = JSON.parse(rawValue) as Partial<StoredSeasonSearch>;
    const query =
      typeof parsedValue.query === "string" ? parsedValue.query : "";

    return {
      query,
      state: isSeasonSearchState(parsedValue.state)
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

function writeStoredSeasonSearch(query: string, state: SeasonSearchState) {
  if (typeof window === "undefined") return;

  window.sessionStorage.setItem(
    seasonSearchStorageKey,
    JSON.stringify({
      query,
      state,
    }),
  );
}

function readStoredEpisodeSheet(): StoredEpisodeSheet | null {
  if (typeof window === "undefined") return null;

  try {
    const rawValue = window.sessionStorage.getItem(episodeSheetStorageKey);
    if (!rawValue) return null;

    const parsedValue = JSON.parse(rawValue) as Partial<StoredEpisodeSheet>;
    const seasonId =
      typeof parsedValue.seasonId === "string" ? parsedValue.seasonId : "";
    const scrollTop =
      typeof parsedValue.scrollTop === "number" &&
      Number.isFinite(parsedValue.scrollTop)
        ? parsedValue.scrollTop
        : 0;

    return seasonId ? { seasonId, scrollTop } : null;
  } catch {
    return null;
  }
}

function writeStoredEpisodeSheet(seasonId: string, scrollTop = 0) {
  if (typeof window === "undefined" || !seasonId) return;

  window.sessionStorage.setItem(
    episodeSheetStorageKey,
    JSON.stringify({ seasonId, scrollTop }),
  );
}

function clearStoredEpisodeSheet() {
  if (typeof window === "undefined") return;

  window.sessionStorage.removeItem(episodeSheetStorageKey);
}

function findEpisodeSheetView(
  state: SeasonSearchState,
  seasonId: string,
): {
  movie: SeasonMovieSearchResult;
  season: SeasonSearchResultItem;
} | null {
  for (const movie of state.results) {
    const season = movie.seasons.find((item) => item.id === seasonId);

    if (season) {
      return {
        movie,
        season,
      };
    }
  }

  return null;
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

function SeasonMovieResult({
  movie,
  onDeleteSeason,
  onViewEpisodes,
}: {
  movie: SeasonMovieSearchResult;
  onDeleteSeason: (season: SeasonSearchResultItem) => void;
  onViewEpisodes: (
    movie: SeasonMovieSearchResult,
    season: SeasonSearchResultItem,
  ) => void;
}) {
  return (
    <li className="liquid-glass-soft overflow-hidden rounded-lg p-3 shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:shadow-[0_18px_42px_rgba(2,6,23,0.35)]">
      <div className="flex flex-col gap-3 md:flex-row md:gap-4">
        <div className="relative flex aspect-video w-full shrink-0 items-center justify-center overflow-hidden rounded-md bg-slate-200 text-slate-500 dark:bg-white/8 dark:text-slate-400 md:w-48">
          {movie.poster ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={movie.poster}
              alt={movie.title}
              className="h-full w-full object-cover"
            />
          ) : (
            <Clapperboard className="size-7" />
          )}
        </div>

        <div className="min-w-0 flex-1">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0">
              <h2 className="truncate text-base font-bold text-slate-950 dark:text-white">
                {movie.title}
              </h2>
              <div className="mt-2 flex flex-wrap gap-2 text-xs font-semibold text-slate-600 dark:text-slate-300">
                <span className="inline-flex min-h-8 items-center rounded-md bg-white/55 px-3 dark:bg-white/8">
                  {movie.status}
                </span>
                {movie.genre ? (
                  <span className="inline-flex min-h-8 items-center rounded-md bg-white/55 px-3 dark:bg-white/8">
                    {movie.genre}
                  </span>
                ) : null}
              </div>
            </div>
          </div>

          <div className="mt-4 space-y-2">
            {movie.seasons.map((season) => {
              return (
                <div
                  key={season.id || `${movie.movieId}-${season.seasonNumber}`}
                  className="rounded-md border border-white/50 bg-white/46 p-3 shadow-[0_1px_2px_rgba(15,23,42,0.04)] dark:border-white/10 dark:bg-white/6"
                >
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                      <h3 className="truncate text-sm font-bold text-slate-950 dark:text-white">
                        {season.title}
                      </h3>
                      <div className="mt-2 flex flex-wrap gap-2 text-xs font-semibold text-slate-600 dark:text-slate-300">
                        {season.seasonNumber ? (
                          <span className="inline-flex min-h-8 items-center gap-1.5 rounded-md bg-white/65 px-3 dark:bg-white/8">
                            <Layers3 className="size-3.5" />
                            Season {season.seasonNumber}
                          </span>
                        ) : null}
                        <span className="inline-flex min-h-8 items-center rounded-md bg-white/65 px-3 dark:bg-white/8">
                          {season.status}
                        </span>
                        {season.isPayPerView ? (
                          <span className="inline-flex min-h-8 items-center rounded-md bg-teal-100 px-3 text-teal-800 dark:bg-cyan-300/12 dark:text-cyan-100">
                            Pay per view
                            {season.amount ? ` - ${season.amount}` : ""}
                          </span>
                        ) : null}
                        {season.releaseDate ? (
                          <span className="inline-flex min-h-8 items-center gap-1.5 rounded-md bg-white/65 px-3 dark:bg-white/8">
                            <CalendarDays className="size-3.5" />
                            {season.releaseDate}
                          </span>
                        ) : null}
                        <button
                          type="button"
                          onClick={() => onViewEpisodes(movie, season)}
                          disabled={!season.episodeCount}
                          className="inline-flex min-h-8 items-center gap-1.5 rounded-md bg-white/65 px-3 text-xs font-semibold transition hover:bg-white/85 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-white/8 dark:hover:bg-white/12"
                        >
                          <ListVideo className="size-3.5" />
                          {season.episodeCount} episode
                          {season.episodeCount === 1 ? "" : "s"}
                        </button>
                      </div>
                    </div>

                    {season.id ? (
                      <div className="flex shrink-0 items-center gap-2 sm:justify-end">
                        <Link
                          href={`/seasons/update/${season.id}`}
                          className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 text-sm font-bold text-white shadow-[0_12px_26px_rgba(15,23,42,0.16)] transition hover:-translate-y-0.5 hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                        >
                          <PencilLine className="size-4" />
                          Edit
                        </Link>
                        <button
                          type="button"
                          onClick={() => onDeleteSeason(season)}
                          className="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-rose-200/80 bg-rose-50/90 px-4 text-sm font-bold text-rose-700 shadow-[0_10px_24px_rgba(225,29,72,0.08)] transition hover:-translate-y-0.5 hover:border-rose-300 hover:bg-rose-100 dark:border-rose-300/20 dark:bg-rose-300/10 dark:text-rose-100 dark:hover:bg-rose-300/16"
                        >
                          <Trash2 className="size-4" />
                          Delete
                        </button>
                      </div>
                    ) : null}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </div>
    </li>
  );
}

export function UpdateSeasonSearch() {
  const recognitionRef = useRef<SpeechRecognitionLike | null>(null);
  const hasTypedRef = useRef(false);
  const searchRequestRef = useRef(0);
  const [query, setQuery] = useState("");
  const [speechMessage, setSpeechMessage] = useState("");
  const [isListening, setIsListening] = useState(false);
  const [episodeToDelete, setEpisodeToDelete] =
    useState<EpisodeSearchResultItem | null>(null);
  const [episodeListView, setEpisodeListView] = useState<{
    movie: SeasonMovieSearchResult;
    season: SeasonSearchResultItem;
  } | null>(null);
  const [episodeListScrollTop, setEpisodeListScrollTop] = useState(0);
  const [seasonToDelete, setSeasonToDelete] =
    useState<SeasonSearchResultItem | null>(null);
  const [searchState, setSearchState] =
    useState<SeasonSearchState>(initialSearchState);
  const [isPending, startTransition] = useTransition();
  const [isEpisodeDeletePending, startEpisodeDeleteTransition] =
    useTransition();
  const [isDeletePending, startDeleteTransition] = useTransition();
  const speechSupported = useSyncExternalStore(
    subscribeToSpeechSupport,
    getSpeechSupportSnapshot,
    getSpeechSupportServerSnapshot,
  );
  const resultSeasonCount = searchState.results.reduce(
    (total, movie) => total + movie.seasons.length,
    0,
  );

  useEffect(() => {
    return () => {
      recognitionRef.current?.stop();
    };
  }, []);

  const runSearch = useCallback((nextQuery = query) => {
    const requestId = searchRequestRef.current + 1;
    searchRequestRef.current = requestId;

    startTransition(async () => {
      const nextState = await searchSeasonsAction(nextQuery);
      if (searchRequestRef.current === requestId) {
        setSearchState(nextState);
        writeStoredSeasonSearch(nextQuery, nextState);

        const storedSheet = readStoredEpisodeSheet();
        if (storedSheet) {
          setEpisodeListView(
            findEpisodeSheetView(nextState, storedSheet.seasonId),
          );
          setEpisodeListScrollTop(storedSheet.scrollTop);
        }
      }
    });
  }, [query, startTransition]);

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      const storedSearch = readStoredSeasonSearch();
      setQuery(storedSearch.query);

      if (storedSearch.state.results.length > 0 || storedSearch.query) {
        setSearchState(storedSearch.state);

        const storedSheet = readStoredEpisodeSheet();
        if (storedSheet) {
          setEpisodeListView(
            findEpisodeSheetView(storedSearch.state, storedSheet.seasonId),
          );
          setEpisodeListScrollTop(storedSheet.scrollTop);
        }

        return;
      }

      runSearch("");
    }, 0);

    return () => window.clearTimeout(timeoutId);
  }, [runSearch]);

  useEffect(() => {
    if (!hasTypedRef.current) return;

    const timeoutId = window.setTimeout(() => {
      runSearch(query);
    }, 350);

    return () => window.clearTimeout(timeoutId);
  }, [query, runSearch]);

  const confirmDeleteSeason = () => {
    if (!seasonToDelete) return;

    startDeleteTransition(async () => {
      const result = await deleteSeasonAction(seasonToDelete.id);

      if (result.status === "success") {
        toast.success(result.message);
        setSeasonToDelete(null);
        runSearch(query);
        return;
      }

      toast.error(result.message);
    });
  };

  const confirmDeleteEpisode = () => {
    if (!episodeToDelete) return;

    startEpisodeDeleteTransition(async () => {
      const result = await deleteEpisodeAction(episodeToDelete.id);

      if (result.status === "success") {
        toast.success(result.message);
        setEpisodeToDelete(null);
        setEpisodeListView(null);
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
              writeStoredSeasonSearch(nextQuery, searchState);
            }}
            placeholder="Search movie title, or leave blank to browse"
            className="min-h-12 min-w-0 flex-1 bg-transparent text-base font-semibold text-slate-950 outline-none placeholder:text-slate-400 dark:text-white dark:placeholder:text-slate-500"
          />
          {query ? (
            <button
              type="button"
              onClick={() => {
                hasTypedRef.current = true;
                setQuery("");
                runSearch("");
              }}
              className="inline-flex size-10 shrink-0 items-center justify-center rounded-md text-slate-500 transition hover:bg-white/72 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white"
              aria-label="Show all recent seasons"
              title="Show all recent seasons"
            >
              <X className="size-4" />
            </button>
          ) : null}
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

      <div className="flex min-h-8 flex-wrap items-center justify-between gap-3 px-1 text-sm font-semibold text-slate-600 dark:text-slate-300">
        <p>{isPending ? "Searching seasons..." : searchState.message}</p>
        {resultSeasonCount > 0 ? (
          <p className="rounded-full bg-white/60 px-3 py-1 text-xs font-bold text-slate-700 dark:bg-white/8 dark:text-slate-200">
            {searchState.results.length} title
            {searchState.results.length === 1 ? "" : "s"} / {resultSeasonCount} season
            {resultSeasonCount === 1 ? "" : "s"}
          </p>
        ) : null}
        {speechMessage ? <p>{speechMessage}</p> : null}
      </div>

      {searchState.results.length > 0 ? (
        <ul className="grid gap-3 xl:grid-cols-2">
          {searchState.results.map((movie, index) => (
            <SeasonMovieResult
              key={movie.movieId || movie.movieNum || `${movie.title}-${index}`}
              movie={movie}
              onDeleteSeason={setSeasonToDelete}
              onViewEpisodes={(nextMovie, nextSeason) => {
                setEpisodeListScrollTop(0);
                setEpisodeListView({
                  movie: nextMovie,
                  season: nextSeason,
                });
              }}
            />
          ))}
        </ul>
      ) : null}

      <EpisodeListSheet
        open={Boolean(episodeListView)}
        title={episodeListView?.season.title ?? "Episodes"}
        subtitle={
          episodeListView
            ? `${episodeListView.movie.title}${
                episodeListView.season.seasonNumber
                  ? ` - Season ${episodeListView.season.seasonNumber}`
                  : ""
              }`
            : undefined
        }
        episodes={episodeListView?.season.episodes ?? []}
        initialScrollTop={episodeListScrollTop}
        sheetKey={episodeListView?.season.id}
        onClose={() => {
          clearStoredEpisodeSheet();
          setEpisodeListScrollTop(0);
          setEpisodeListView(null);
        }}
        onDeleteEpisode={setEpisodeToDelete}
        onEditEpisode={(scrollTop) => {
          if (episodeListView?.season.id) {
            writeStoredEpisodeSheet(episodeListView.season.id, scrollTop);
          }
        }}
        onScrollTopChange={(scrollTop) => {
          setEpisodeListScrollTop(scrollTop);

          if (episodeListView?.season.id) {
            writeStoredEpisodeSheet(episodeListView.season.id, scrollTop);
          }
        }}
      />

      <ConfirmDialog
        open={Boolean(seasonToDelete)}
        title="Delete season?"
        description={
          seasonToDelete
            ? `This will permanently delete ${seasonToDelete.title}. This action cannot be undone.`
            : "This action cannot be undone."
        }
        confirmLabel="Delete season"
        isPending={isDeletePending}
        variant="danger"
        onClose={() => {
          if (!isDeletePending) {
            setSeasonToDelete(null);
          }
        }}
        onConfirm={confirmDeleteSeason}
      />

      <ConfirmDialog
        open={Boolean(episodeToDelete)}
        title="Delete episode?"
        description={
          episodeToDelete
            ? `This will permanently delete ${episodeToDelete.title}. This action cannot be undone.`
            : "This action cannot be undone."
        }
        confirmLabel="Delete episode"
        isPending={isEpisodeDeletePending}
        variant="danger"
        onClose={() => {
          if (!isEpisodeDeletePending) {
            setEpisodeToDelete(null);
          }
        }}
        onConfirm={confirmDeleteEpisode}
      />
    </section>
  );
}
