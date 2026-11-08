import { cookies } from "next/headers";
import { notFound } from "next/navigation";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { EditSubscriberForm } from "@/app/features/subscriptions/components/edit-subscriber-form";
import { planService } from "@/app/features/subscriptions/services/plan-service";
import { subscriptionService } from "@/app/features/subscriptions/services/subscription-service";
import type { PlanItem } from "@/app/features/subscriptions/services/plan-service";
import type { SubscriptionItem } from "@/app/features/subscriptions/services/subscription-service";

export const dynamic = "force-dynamic";

type EditSubscriberPageProps = {
  params: Promise<{
    id: string;
  }>;
  searchParams?: Promise<{
    returnTo?: string;
  }>;
};

const defaultReturnHref = "/subscriptions/subscribers";

function safeReturnHref(value: string | undefined) {
  const candidate = value?.trim();

  if (!candidate || !candidate.startsWith("/") || candidate.startsWith("//")) {
    return defaultReturnHref;
  }

  try {
    const url = new URL(candidate, "https://admin.local");

    if (url.pathname !== defaultReturnHref) {
      return defaultReturnHref;
    }

    return `${url.pathname}${url.search}${url.hash}`;
  } catch {
    return defaultReturnHref;
  }
}

export default async function EditSubscriberPage({
  params,
  searchParams,
}: EditSubscriberPageProps) {
  const [{ id }, cookieStore, resolvedSearchParams] = await Promise.all([
    params,
    cookies(),
    searchParams,
  ]);
  const savedMode = cookieStore.get("theme-mode")?.value;
  const initialMode = savedMode === "dark" ? "dark" : "light";
  const returnHref = safeReturnHref(resolvedSearchParams?.returnTo);

  let subscription: SubscriptionItem;
  let plans: PlanItem[] = [];

  try {
    const [subscriptionResponse, plansResponse] = await Promise.all([
      subscriptionService.getById(id),
      planService.list({ per_page: 100 }),
    ]);

    if (subscriptionResponse.status === "error" || !subscriptionResponse.data) {
      notFound();
    }

    subscription = subscriptionResponse.data;
    plans = plansResponse.data?.data ?? [];
  } catch {
    notFound();
  }

  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 lg:py-1 dark:text-white">
      <div className="flex w-full flex-1 flex-col lg:min-h-0">
        <AdminPageHeader title="Edit subscriber" initialMode={initialMode} />

        <div className="admin-sidebar-scroll mt-3 flex-1 pb-4 lg:min-h-0">
          <section className="relative mb-4 overflow-hidden rounded-lg border border-white/58 bg-[linear-gradient(135deg,rgba(255,255,255,0.82),rgba(236,253,245,0.5)_46%,rgba(224,242,254,0.52))] p-5 backdrop-blur-xl sm:p-6 dark:border-white/10 dark:bg-[linear-gradient(135deg,rgba(15,23,42,0.82),rgba(20,83,45,0.2)_46%,rgba(8,47,73,0.28))] dark:shadow-[0_18px_60px_rgba(2,6,23,0.46)]">
            <div className="max-w-3xl">
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-teal-700 dark:text-cyan-200">
                Subscribers
              </p>
              <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl dark:text-white">
                Update subscription #{id}.
              </h2>
              <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
                Change the linked plan, subscription dates, active state, or
                renewal note.
              </p>
            </div>
          </section>

          <EditSubscriberForm
            subscription={subscription}
            plans={plans}
            returnHref={returnHref}
          />
        </div>
      </div>
    </main>
  );
}
