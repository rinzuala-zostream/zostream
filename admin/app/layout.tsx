import type { Metadata, Viewport } from "next";
import { cookies } from "next/headers";
import { AppToaster } from "./components/app-toaster";
import { PwaServiceWorker } from "./components/pwa-service-worker";
import { TokenAutoRefresh } from "./features/auth/components/token-auto-refresh";
import "./globals.css";
import "react-toastify/dist/ReactToastify.css";
import "shaka-player/dist/controls.css";
import { Geist } from "next/font/google";
import { cn } from "@/lib/utils";

const geist = Geist({subsets:['latin'],variable:'--font-sans'});

export const metadata: Metadata = {
  title: "Zo Stream Admin",
  description: "Admin workspace for managing Zo Stream content and users.",
  applicationName: "Zo Stream Admin",
  manifest: "/manifest.webmanifest",
  appleWebApp: {
    capable: true,
    statusBarStyle: "default",
    title: "Zo Admin",
  },
  icons: {
    icon: [
      { url: "/logo/zostream-logo.svg", type: "image/svg+xml" },
      { url: "/logo/logo.png", sizes: "1254x1254", type: "image/png" },
    ],
    apple: [{ url: "/logo/logo.png", sizes: "1254x1254", type: "image/png" }],
  },
};

export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#f1f1f1" },
    { media: "(prefers-color-scheme: dark)", color: "#161616" },
  ],
};

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const cookieStore = await cookies();
  const savedMode = cookieStore.get("theme-mode")?.value;
  const hasRefreshCookie = Boolean(
    cookieStore.get("zostream_admin_refresh_token")?.value?.trim(),
  );
  const themeClass = savedMode === "dark" ? "dark" : "";

  return (
    <html
      lang="en"
      className={cn(
        "h-full",
        "antialiased",
        themeClass,
        "font-sans",
        geist.variable,
      )}
      suppressHydrationWarning
    >
      <body className="relative min-h-svh overflow-x-hidden">
        <div className="relative z-10 flex min-h-svh flex-col">
          {children}
          <TokenAutoRefresh hasRefreshCookie={hasRefreshCookie} />
          <PwaServiceWorker />
          <AppToaster />
        </div>
      </body>
    </html>
  );
}
