import { cookies } from "next/headers";
import { notFound } from "next/navigation";
import { AdminPageHeader } from "@/app/components/admin-page-header";
import { EditUserForm } from "@/app/features/users/components/edit-user-form";
import { userService } from "@/app/features/users/services/user-service";
import type { UserItem } from "@/app/features/users/services/user-service";

export const dynamic = "force-dynamic";

type EditUserPageProps = {
  params: Promise<{
    id: string;
  }>;
};

function valueToString(value: unknown) {
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return String(value);
  return "";
}

export default async function EditUserPage({ params }: EditUserPageProps) {
  const [{ id }, cookieStore] = await Promise.all([params, cookies()]);
  const userId = decodeURIComponent(id);
  const savedMode = cookieStore.get("theme-mode")?.value;
  const initialMode = savedMode === "dark" ? "dark" : "light";

  let user: UserItem;

  try {
    const response = await userService.findByUid(userId);

    if (response.status === "error" || !response.data) {
      notFound();
    }

    user = response.data;
  } catch {
    notFound();
  }

  const title = valueToString(user.name) || valueToString(user.uid) || userId;

  return (
    <main className="flex min-h-svh flex-1 flex-col px-3 py-1 text-slate-900 lg:h-svh lg:min-h-0 lg:overflow-hidden lg:px-2 lg:py-1 dark:text-white">
      <div className="flex w-full flex-1 flex-col lg:min-h-0">
        <AdminPageHeader title="Edit user" initialMode={initialMode} />

        <div className="admin-sidebar-scroll mt-3 flex-1 pb-4 lg:min-h-0">
          <section className="relative mb-4 overflow-hidden rounded-lg border border-white/58 bg-[linear-gradient(135deg,rgba(255,255,255,0.82),rgba(236,253,245,0.5)_46%,rgba(224,242,254,0.52))] p-5 backdrop-blur-xl sm:p-6 dark:border-white/10 dark:bg-[linear-gradient(135deg,rgba(15,23,42,0.82),rgba(20,83,45,0.2)_46%,rgba(8,47,73,0.28))] dark:shadow-[0_18px_60px_rgba(2,6,23,0.46)]">
            <div className="max-w-3xl">
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-teal-700 dark:text-cyan-200">
                User management
              </p>
              <h2 className="mt-2 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl dark:text-white">
                Update {title}.
              </h2>
              <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
                Change profile details, account flags, location fields, or
                device metadata for this user.
              </p>
            </div>
          </section>

          <EditUserForm user={user} userId={userId} />
        </div>
      </div>
    </main>
  );
}
