export default function AnalyticsLoading() {
  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-4 lg:px-2">
      <div className="h-14 w-56 animate-pulse rounded-2xl bg-slate-300/70 dark:bg-white/10" />
      <div className="mt-5 h-48 animate-pulse rounded-[2rem] bg-slate-300/60 dark:bg-white/8" />
      <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-8">
        {Array.from({ length: 8 }).map((_, index) => (
          <div key={index} className="h-36 animate-pulse rounded-[1.4rem] bg-slate-300/60 dark:bg-white/8" />
        ))}
      </div>
      <div className="mt-4 grid gap-4 xl:grid-cols-2">
        <div className="h-96 animate-pulse rounded-[1.65rem] bg-slate-300/60 dark:bg-white/8" />
        <div className="h-96 animate-pulse rounded-[1.65rem] bg-slate-300/60 dark:bg-white/8" />
      </div>
    </main>
  );
}
