"use client";

import { Check, ChevronDown, X } from "lucide-react";
import { useState } from "react";

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

function parseGenres(value?: string | null) {
  return (value ?? "")
    .split(",")
    .map((genre) => genre.trim())
    .filter(
      (genre, index, genres) =>
        genre.length > 0 &&
        genres.findIndex(
          (current) => current.toLowerCase() === genre.toLowerCase(),
        ) === index,
    );
}

export function MovieGenrePicker({
  initialValue,
}: {
  initialValue?: string | null;
}) {
  const [selectedGenres, setSelectedGenres] = useState<string[]>(() =>
    parseGenres(initialValue),
  );
  const [customGenre, setCustomGenre] = useState("");

  const toggleGenre = (genre: string) => {
    setSelectedGenres((currentGenres) => {
      const selectedGenre = currentGenres.find(
        (currentGenre) => currentGenre.toLowerCase() === genre.toLowerCase(),
      );

      return selectedGenre
        ? currentGenres.filter((currentGenre) => currentGenre !== selectedGenre)
        : [...currentGenres, genre];
    });
  };

  const addCustomGenres = () => {
    const newGenres = parseGenres(customGenre);
    if (newGenres.length === 0) return;

    setSelectedGenres((currentGenres) => [
      ...currentGenres,
      ...newGenres.filter(
        (genre) =>
          !currentGenres.some(
            (currentGenre) =>
              currentGenre.toLowerCase() === genre.toLowerCase(),
          ),
      ),
    ]);
    setCustomGenre("");
  };

  return (
    <div className="block min-w-0">
      <input type="hidden" name="genre" value={selectedGenres.join(", ")} />

      <div className="flex items-center justify-between gap-3">
        <span className="text-sm font-semibold text-slate-800 dark:text-slate-100">
          Genre
        </span>
        <span className="text-xs font-semibold text-slate-600 dark:text-slate-300">
          {selectedGenres.length} selected
        </span>
      </div>

      <div className="mt-2 rounded-md border border-slate-300 bg-white/90 p-2 shadow-sm dark:border-white/10 dark:bg-white/8">
        <div className="flex min-h-9 flex-wrap gap-2">
          {selectedGenres.length > 0 ? (
            selectedGenres.map((genre) => (
              <button
                key={genre}
                type="button"
                onClick={() => toggleGenre(genre)}
                className="inline-flex min-h-8 items-center gap-2 rounded-md bg-teal-100 px-3 text-xs font-bold text-teal-900 transition hover:bg-teal-200 dark:bg-cyan-300/15 dark:text-cyan-100 dark:hover:bg-cyan-300/22"
              >
                {genre}
                <X className="size-3.5" />
              </button>
            ))
          ) : (
            <span className="px-2 py-1.5 text-sm text-slate-600 dark:text-slate-300">
              No genre selected
            </span>
          )}
        </div>

        <details className="group mt-2">
          <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between rounded-md border border-slate-300 bg-white px-4 text-sm font-bold text-slate-900 transition hover:border-teal-400 hover:bg-teal-50 [&::-webkit-details-marker]:hidden dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:hover:border-cyan-300/50 dark:hover:bg-white/10">
            Select genres
            <ChevronDown className="size-4 transition-transform group-open:rotate-180" />
          </summary>

          <div className="mt-2 grid gap-2 rounded-md border border-slate-200 bg-slate-50 p-2 sm:grid-cols-2 dark:border-white/10 dark:bg-slate-950/55">
            {genreOptions.map((genre) => {
              const isSelected = selectedGenres.some(
                (selectedGenre) =>
                  selectedGenre.toLowerCase() === genre.toLowerCase(),
              );

              return (
                <label
                  key={genre}
                  className="flex min-h-10 cursor-pointer items-center gap-3 rounded-md border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-800 transition hover:border-teal-300 hover:bg-teal-50 dark:border-white/10 dark:bg-white/6 dark:text-slate-100 dark:hover:border-cyan-300/40 dark:hover:bg-white/10"
                >
                  <input
                    type="checkbox"
                    checked={isSelected}
                    onChange={() => toggleGenre(genre)}
                    className="peer sr-only"
                  />
                  <span className="flex size-5 shrink-0 items-center justify-center rounded border border-slate-300 bg-white text-transparent peer-checked:border-teal-600 peer-checked:bg-teal-600 peer-checked:text-white dark:border-white/20 dark:bg-white/8 dark:peer-checked:border-cyan-400 dark:peer-checked:bg-cyan-400 dark:peer-checked:text-slate-950">
                    <Check className="size-3.5" />
                  </span>
                  {genre}
                </label>
              );
            })}
          </div>
        </details>

        <div className="mt-2 flex flex-col gap-2 sm:flex-row">
          <input
            type="text"
            value={customGenre}
            onChange={(event) => setCustomGenre(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === "Enter") {
                event.preventDefault();
                addCustomGenres();
              }
            }}
            placeholder="Custom genre only"
            className="min-h-11 flex-1 rounded-md border border-slate-300 bg-white px-4 text-sm text-slate-950 outline-none transition placeholder:text-slate-500 focus:border-teal-400 focus:ring-4 focus:ring-teal-200/35 dark:border-white/10 dark:bg-slate-950/70 dark:text-white dark:focus:border-cyan-300/60 dark:focus:ring-cyan-300/15"
          />
          <button
            type="button"
            onClick={addCustomGenres}
            className="inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-4 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
          >
            Add custom
          </button>
        </div>
      </div>
    </div>
  );
}
