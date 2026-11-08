import Link from "next/link";
import { BannerCard } from "@/app/features/banners/components/banner-card";
import type { BannerItem } from "@/app/features/banners/services/banner-service";

type BannerListCardsProps = {
  banners: BannerItem[];
};

export function BannerListCards({ banners }: BannerListCardsProps) {
  if (banners.length === 0) {
    return (
      <section className="rounded-lg border border-dashed border-[rgba(15,23,42,0.18)] bg-white/52 p-8 text-center shadow-[0_14px_40px_rgba(15,23,42,0.08)] dark:border-white/12 dark:bg-white/6 dark:shadow-[0_18px_54px_rgba(2,6,23,0.36)]">
        <h2 className="text-xl font-bold text-slate-950 dark:text-white">
          No banners available
        </h2>
        <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-600 dark:text-slate-300">
          Create a banner first, then it will appear here for editing.
        </p>
        <Link
          href="/banners/add"
          className="mt-5 inline-flex min-h-11 items-center justify-center rounded-md bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
        >
          Add banner
        </Link>
      </section>
    );
  }

  return (
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
      {banners.map((banner) => (
        <BannerCard
          key={`${banner.id ?? "banner"}-${banner.title ?? banner.media_url ?? "item"}`}
          banner={banner}
        />
      ))}
    </div>
  );
}
