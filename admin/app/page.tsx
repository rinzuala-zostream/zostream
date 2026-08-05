import { LogoSection } from "./components/logo-section";
import { AuthPanelsShell } from "./components/auth-panels-shell";
import { createAdminQrSession } from "./features/qr/services/qr-session-service";
import { cookies, headers } from "next/headers";
import { buildDeviceName, parseUserAgent } from "./features/qr/lib/device-info";
import { redirect } from "next/navigation";
import { ThemeToggle } from "./components/theme-toggle";

export const dynamic = "force-dynamic";

export default async function Home() {
  const cookieStore = await cookies();
  const uid = cookieStore.get("zostream_admin_uid")?.value;
  const accessToken = cookieStore.get("zostream_admin_access_token")?.value;
  const refreshToken = cookieStore.get("zostream_admin_refresh_token")?.value;
  if (uid && accessToken && refreshToken) {
    redirect("/dashboard");
  }
  const savedMode = cookieStore.get("theme-mode")?.value;
  const initialMode = savedMode === "dark" ? "dark" : "light";

  const visitorId =
    cookieStore.get("zostream_admin_visitor_id")?.value ?? "web_browser";

  const headerStore = await headers();
  const userAgent = headerStore.get("user-agent") ?? "";
  const parsed = parseUserAgent(userAgent);

  let token = "";
  let expiresIn = 120;
  let qrError = "";

  try {
    const created = await createAdminQrSession({
      device_type: "browser",
      device_name: buildDeviceName(parsed),
      device_id: visitorId,
      note: `os=${parsed.osName} ${parsed.osVersion}; browser=${parsed.browserName} ${parsed.browserVersion}; model=${parsed.model}`,
    });

    token = created.token;
    expiresIn = created.expires_in;
  } catch (error) {
    qrError =
      error instanceof Error ? error.message : "Failed to create QR session";
  }

  return (
    <main className="relative grid h-svh overflow-hidden place-items-center px-5 text-slate-900 dark:text-white sm:px-8">
      <LogoSection
        initialMode={initialMode}
        className=" absolute left-1/2 top-12 md:top-12 z-20 -translate-x-1/2 px-1 sm:top-10 sm:px-8 lg:px-12"
      />
      <div className="absolute right-2 top-10 z-30">
        <ThemeToggle initialMode={initialMode} />
      </div>
      <AuthPanelsShell token={token} expiresIn={expiresIn} qrError={qrError} />
    </main>
  );
}
