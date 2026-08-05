import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { AdminSidebarShell } from "@/app/components/admin-sidebar-shell";

export const dynamic = "force-dynamic";

export default async function AdminLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const cookieStore = await cookies();
  const uid = cookieStore.get("zostream_admin_uid")?.value?.trim() ?? "";
  const accessToken =
    cookieStore.get("zostream_admin_access_token")?.value?.trim() ?? "";
  const refreshToken =
    cookieStore.get("zostream_admin_refresh_token")?.value?.trim() ?? "";

  if (!uid || !accessToken || !refreshToken) {
    redirect("/");
  }

  const sidebarState = cookieStore.get("sidebar_state")?.value;
  const defaultOpen = sidebarState !== "false";

  return (
    <AdminSidebarShell defaultOpen={defaultOpen} uid={uid}>
      {children}
    </AdminSidebarShell>
  );
}
