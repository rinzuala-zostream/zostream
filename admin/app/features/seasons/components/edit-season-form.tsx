"use client";

import { useActionState, useEffect, useMemo, useRef, useState } from "react";
import {
  CalendarDays,
  Check,
  CircleDollarSign,
  Image as ImageIcon,
  Layers3,
  LockKeyhole,
  Save,
  Sparkles,
  X,
  type LucideIcon,
} from "lucide-react";
import {
  updateSeasonAction,
  type EditSeasonFormState,
} from "@/app/(admin)/seasons/update/[id]/actions";
import { useAdminSidebar } from "@/app/components/admin-sidebar-shell";
import type {
  EpisodeItem,
  SeasonItem,
} from "@/app/features/seasons/services/season-service";
import { cn } from "@/lib/utils";
import { toast } from "react-toastify";

const initialState: EditSeasonFormState = {
  status: "idle",
  message: "",
};

const statusOptions = ["Draft", "Published", "Scheduled"] as const;

const inputClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm text-slate-950 outline-none shadow-[inset_0_1px_0_rgba(255,255,255,0.75),0_1px_2px_rgba(15,23,42,0.04)] transition placeholder:text-slate-400 focus:border-teal-300 focus:bg-white/76 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-cyan-300/60 dark:focus:bg-white/12 dark:focus:ring-cyan-300/15";
const selectClassName =
  "mt-2 w-full rounded-md border border-[rgba(15,23,42,0.16)] bg-white/58 px-4 py-3 text-sm font-semibold text-slate-950 [color-scheme:light] outline-none shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition focus:border-teal-300 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-white/8 dark:text-white dark:[color-scheme:dark] dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15";
const optionClassName =
  "bg-white text-slate-950 dark:bg-slate-950 dark:text-white";

function textValue(value: unknown) {
  if (typeof value === "string") return value;
  if (typeof value === "number") return String(value);
  return "";
}

function boolValue(value: unknown) {
  return value === true || value === 1 || value === "1";
}

function statusValue(status: SeasonItem["status"]) {
  return statusOptions.some((option) => option === status)
    ? String(status)
    : "Draft";
}

function NumberField({
  label,
  name,
  defaultValue,
  placeholder,
  helper,
  min,
  max,
  step,
}: {
  label: string;
  name: string;
  defaultValue?: string;
  placeholder?: string;
  helper?: string;
  min?: number;
  max?: number;
  step?: string;
}) {
  return (
    <label className="block min-w-0">
      <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
        {label}
      </span>
      <input
        name={name}
        type="number"
        min={min}
        max={max}
        step={step}
        defaultValue={defaultValue}
        placeholder={placeholder}
        className={inputClassName}
      />
      {helper ? (
        <span className="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">
          {helper}
        </span>
      ) : null}
    </label>
  );
}

function PosterUrlField({ season }: { season: SeasonItem }) {
  const [posterUrl, setPosterUrl] = useState(textValue(season.poster));
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
          title={previewUrl ? "Preview season poster" : "Paste an image URL first"}
        >
          <ImageIcon className="size-4" />
        </button>
      </div>

      <input
        id="season-poster"
        name="poster"
        type="text"
        value={posterUrl}
        onChange={(event) => setPosterUrl(event.target.value)}
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

function SwitchPill({
  name,
  label,
  defaultChecked,
  checked,
  onChange,
}: {
  name: string;
  label: string;
  defaultChecked?: boolean;
  checked?: boolean;
  onChange?: (checked: boolean) => void;
}) {
  return (
    <label className="group flex min-h-12 items-center justify-between gap-3 rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 px-3 py-2 text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition hover:border-[rgba(15,23,42,0.22)] hover:bg-white/64 dark:border-white/10 dark:bg-white/6 dark:text-slate-200 dark:hover:bg-white/10">
      <span>{label}</span>
      <input
        type="checkbox"
        name={name}
        defaultChecked={defaultChecked}
        checked={checked}
        onChange={(event) => onChange?.(event.target.checked)}
        className="peer sr-only"
      />
      <span className="relative h-6 w-11 shrink-0 rounded-full bg-slate-300/80 after:absolute after:left-1 after:top-1 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition after:content-[''] peer-checked:bg-teal-500 peer-checked:after:translate-x-5 dark:bg-slate-700 dark:peer-checked:bg-cyan-400" />
    </label>
  );
}

type EpisodePpvDraft = {
  id: string;
  episodeNumber: string;
  title: string;
  isPayPerView: boolean;
  amount: string;
  isManualAmount: boolean;
};

function numericValue(value: string) {
  const parsedValue = Number(value);
  return Number.isFinite(parsedValue) ? parsedValue : 0;
}

function moneyValue(value: number) {
  if (!Number.isFinite(value) || value <= 0) return "";
  return Number(value.toFixed(2)).toString();
}

function percentValue(value: string) {
  const parsedValue = numericValue(value);
  if (parsedValue < 0) return 0;
  if (parsedValue > 100) return 100;
  return parsedValue;
}

function applyPercentIncrease(amount: number, percent: string) {
  return amount + (amount * percentValue(percent)) / 100;
}

function episodeDraft(episode: EpisodeItem): EpisodePpvDraft | null {
  const id = textValue(episode.id);
  if (!id) return null;

  const episodeNumber = textValue(episode.episode_number);

  return {
    id,
    episodeNumber,
    title:
      textValue(episode.title) ||
      (episodeNumber ? `Episode ${episodeNumber}` : "Untitled episode"),
    isPayPerView: boolValue(episode.isPayPerView),
    amount: textValue(episode.amount),
    isManualAmount: Boolean(textValue(episode.amount)),
  };
}

function EpisodePpvManager({
  episodes,
  isSeasonPayPerView,
  seasonAmount,
  seasonPpvPercent,
}: {
  episodes?: EpisodeItem[];
  isSeasonPayPerView: boolean;
  seasonAmount: string;
  seasonPpvPercent: string;
}) {
  const [drafts, setDrafts] = useState<EpisodePpvDraft[]>(() =>
    (episodes ?? [])
      .map(episodeDraft)
      .filter((episode): episode is EpisodePpvDraft => Boolean(episode)),
  );

  const selectedCount = drafts.filter((episode) => episode.isPayPerView).length;
  const baseSplitAmount =
    selectedCount > 0 ? numericValue(seasonAmount) / selectedCount : 0;
  const splitAmount = applyPercentIncrease(baseSplitAmount, seasonPpvPercent);
  const splitAmountText = moneyValue(splitAmount);
  const payload = useMemo(
    () =>
      JSON.stringify(
        isSeasonPayPerView
          ? drafts.map((episode) => ({
              id: episode.id,
              isPayPerView: episode.isPayPerView,
              amount: episode.isPayPerView ? numericValue(episode.amount) : 0,
            }))
          : [],
      ),
    [drafts, isSeasonPayPerView],
  );

  useEffect(() => {
    if (!isSeasonPayPerView || selectedCount === 0) return;

    setDrafts((currentDrafts) =>
      currentDrafts.map((episode) => {
        if (!episode.isPayPerView || episode.isManualAmount) return episode;

        return {
          ...episode,
          amount: splitAmountText,
        };
      }),
    );
  }, [isSeasonPayPerView, selectedCount, splitAmountText]);

  const handleEpisodeSelection = (episodeId: string, isSelected: boolean) => {
    setDrafts((currentDrafts) => {
      const nextSelectedCount =
        currentDrafts.filter((episode) =>
          episode.id === episodeId ? isSelected : episode.isPayPerView,
        ).length || 1;
      const nextSplitAmount = moneyValue(
        applyPercentIncrease(
          numericValue(seasonAmount) / nextSelectedCount,
          seasonPpvPercent,
        ),
      );

      return currentDrafts.map((episode) => {
        if (episode.id !== episodeId) return episode;

        return {
          ...episode,
          isPayPerView: isSelected,
          amount: isSelected ? nextSplitAmount : episode.amount,
          isManualAmount: false,
        };
      });
    });
  };

  const handleEpisodeAmount = (episodeId: string, amount: string) => {
    setDrafts((currentDrafts) =>
      currentDrafts.map((episode) =>
        episode.id === episodeId
          ? {
              ...episode,
              amount,
              isManualAmount: true,
            }
          : episode,
      ),
    );
  };

  return (
    <FormSection
      title="Episode PPV"
      eyebrow="Season split"
      icon={CircleDollarSign}
    >
      <input type="hidden" name="episode_ppv_updates" value={payload} />

      {!isSeasonPayPerView ? (
        <p className="rounded-md border border-[rgba(15,23,42,0.12)] bg-white/42 p-3 text-sm font-semibold text-slate-600 dark:border-white/10 dark:bg-white/6 dark:text-slate-300">
          Enable season pay per view to choose which episodes are PPV.
        </p>
      ) : drafts.length === 0 ? (
        <p className="rounded-md border border-[rgba(15,23,42,0.12)] bg-white/42 p-3 text-sm font-semibold text-slate-600 dark:border-white/10 dark:bg-white/6 dark:text-slate-300">
          No episodes are attached to this season yet. Save the season first,
          add episodes, then come back here to split PPV pricing.
        </p>
      ) : (
        <div className="space-y-4">
          <div className="rounded-md border border-teal-200/70 bg-teal-50/75 p-3 text-sm font-semibold text-teal-900 dark:border-cyan-300/20 dark:bg-cyan-300/10 dark:text-cyan-100">
            {selectedCount > 0 ? (
              <>
                {selectedCount} selected episode
                {selectedCount === 1 ? "" : "s"} - auto price{" "}
                {splitAmountText || "0"} each from season amount{" "}
                {seasonAmount || "0"}
                {percentValue(seasonPpvPercent) > 0
                  ? ` plus ${percentValue(seasonPpvPercent)}%`
                  : ""}
                . You can still type a manual amount per episode.
              </>
            ) : (
              "Select the episodes that should be rented under this season PPV."
            )}
          </div>

          <div className="grid gap-2">
            {drafts.map((episode) => (
              <div
                key={episode.id}
                className={cn(
                  "grid gap-3 rounded-md border border-[rgba(15,23,42,0.12)] bg-white/48 p-3 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition sm:grid-cols-[minmax(0,1fr)_150px] sm:items-center dark:border-white/10 dark:bg-white/6",
                  episode.isPayPerView
                    ? "border-teal-200 bg-teal-50/70 dark:border-cyan-300/24 dark:bg-cyan-300/10"
                    : "",
                )}
              >
                <label className="flex min-w-0 items-center gap-3">
                  <input
                    type="checkbox"
                    checked={episode.isPayPerView}
                    onChange={(event) =>
                      handleEpisodeSelection(episode.id, event.target.checked)
                    }
                    className="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500 dark:border-white/20 dark:bg-white/10"
                  />
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-bold text-slate-950 dark:text-white">
                      {episode.title}
                    </span>
                    <span className="mt-0.5 block text-xs font-semibold text-slate-500 dark:text-slate-400">
                      Episode {episode.episodeNumber || "-"}
                      {episode.isManualAmount ? " - manual price" : ""}
                    </span>
                  </span>
                </label>

                <input
                  type="number"
                  min={0}
                  step="0.01"
                  value={episode.amount}
                  disabled={!episode.isPayPerView}
                  onChange={(event) =>
                    handleEpisodeAmount(episode.id, event.target.value)
                  }
                  placeholder={splitAmountText || "0"}
                  className={cn(
                    inputClassName,
                    "mt-0 disabled:cursor-not-allowed disabled:opacity-50",
                  )}
                  aria-label={`${episode.title} PPV amount`}
                />
              </div>
            ))}
          </div>
        </div>
      )}
    </FormSection>
  );
}

export function EditSeasonForm({ season }: { season: SeasonItem }) {
  const { isDesktopSidebarOpen } = useAdminSidebar();
  const lastToastKeyRef = useRef("");
  const movieTitle = textValue(season.movie?.title);
  const movieId = textValue(season.movie_id);
  const [seasonNumber, setSeasonNumber] = useState(
    textValue(season.season_number),
  );
  const [seasonTitle, setSeasonTitle] = useState(textValue(season.title));
  const [isSeasonPayPerView, setIsSeasonPayPerView] = useState(
    boolValue(season.isPayPerView),
  );
  const [seasonAmount, setSeasonAmount] = useState(textValue(season.amount));
  const [seasonPpvPercent, setSeasonPpvPercent] = useState("");
  const [isSeasonTitleTouched, setIsSeasonTitleTouched] = useState(
    Boolean(textValue(season.title)),
  );
  const [state, formAction, isPending] = useActionState(
    updateSeasonAction,
    initialState,
  );

  const statusMessage = useMemo(() => state.message, [state.message]);

  useEffect(() => {
    if (state.status === "idle") return;

    const toastKey = `${state.status}-${state.resetKey ?? state.message}`;
    if (lastToastKeyRef.current === toastKey) return;

    lastToastKeyRef.current = toastKey;

    if (state.status === "success") {
      toast.success(statusMessage || "Season updated successfully.");
      return;
    }

    toast.error(statusMessage || "Season could not be updated.");
  }, [state.message, state.resetKey, state.status, statusMessage]);

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
    <form action={formAction} className="space-y-4 pb-28">
      <input type="hidden" name="season_id" value={textValue(season.id)} />

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
          <FormSection title="Season details" eyebrow="Edit" icon={Layers3}>
            <div className="grid gap-4 lg:grid-cols-2">
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Movie
                </span>
                <input
                  type="text"
                  value={movieTitle || (movieId ? `Movie ${movieId}` : "")}
                  readOnly
                  className={cn(inputClassName, "cursor-not-allowed opacity-70")}
                />
                {movieId ? (
                  <span className="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">
                    Movie ID: {movieId}
                  </span>
                ) : null}
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
              <label className="block min-w-0 lg:col-span-2">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Description
                </span>
                <textarea
                  name="description"
                  defaultValue={textValue(season.description)}
                  placeholder="Short season synopsis"
                  rows={5}
                  className={cn(inputClassName, "resize-y leading-6")}
                />
              </label>
            </div>
          </FormSection>

          <FormSection title="Artwork" eyebrow="Image" icon={ImageIcon}>
            <PosterUrlField season={season} />
          </FormSection>
        </div>

        <aside className="space-y-4">
          <FormSection title="Release" eyebrow="Schedule" icon={CalendarDays}>
            <div className="grid gap-4">
              <NumberField
                label="Release year"
                name="release_year"
                min={1900}
                max={2200}
                defaultValue={textValue(season.release_year)}
                placeholder="2026"
                helper="Stored as the season release year."
              />
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Status
                </span>
                <select
                  name="status"
                  defaultValue={statusValue(season.status)}
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
              <SwitchPill
                name="isPayPerView"
                label="Pay per view"
                checked={isSeasonPayPerView}
                onChange={setIsSeasonPayPerView}
              />
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Pay per view amount
                </span>
                <input
                  name="amount"
                  type="number"
                  min={0}
                  step="0.01"
                  value={seasonAmount}
                  onChange={(event) => setSeasonAmount(event.target.value)}
                  placeholder="0"
                  className={inputClassName}
                />
                <span className="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">
                  Used only when pay per view is enabled. Selected episodes can
                  be split from this amount.
                </span>
              </label>
              <label className="block min-w-0">
                <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                  Episode split plus percent
                </span>
                <input
                  name="season_ppv_episode_percent"
                  type="number"
                  min={0}
                  max={100}
                  step="0.01"
                  value={seasonPpvPercent}
                  onChange={(event) =>
                    setSeasonPpvPercent(event.target.value)
                  }
                  placeholder="0"
                  className={inputClassName}
                />
                <span className="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">
                  Added to the auto-split episode amount. Manual episode
                  amounts stay as typed.
                </span>
              </label>
            </div>
          </FormSection>

          <EpisodePpvManager
            episodes={season.episodes}
            isSeasonPayPerView={isSeasonPayPerView}
            seasonAmount={seasonAmount}
            seasonPpvPercent={seasonPpvPercent}
          />

          <FormSection title="Season record" eyebrow="ID" icon={LockKeyhole}>
            <div className="rounded-md border border-[rgba(15,23,42,0.14)] bg-white/42 p-3 text-sm leading-6 text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.03)] dark:border-white/10 dark:bg-white/6 dark:text-slate-200">
              <p className="font-semibold text-slate-900 dark:text-white">
                {textValue(season.id)}
              </p>
              <p className="mt-1 text-slate-500 dark:text-slate-400">
                Movie name is shown for reference. This form updates season
                details only.
              </p>
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
          <span>Update the season details and publish state.</span>
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
              Update season
            </>
          )}
        </button>
      </div>
    </form>
  );
}
