"use client";

import { useEffect } from "react";

export function PwaServiceWorker() {
  useEffect(() => {
    if (!("serviceWorker" in navigator)) {
      return;
    }

    if (process.env.NODE_ENV !== "production") {
      const clearProductionWorker = async () => {
        const registrations = await navigator.serviceWorker.getRegistrations();
        await Promise.all(
          registrations
            .filter(
              (registration) =>
                new URL(registration.scope).origin === window.location.origin,
            )
            .map((registration) => registration.unregister()),
        );

        if ("caches" in window) {
          const cacheNames = await window.caches.keys();
          await Promise.all(
            cacheNames
              .filter((cacheName) => cacheName.startsWith("zo-admin-static-"))
              .map((cacheName) => window.caches.delete(cacheName)),
          );
        }
      };

      void clearProductionWorker();
      return;
    }

    const registerServiceWorker = async () => {
      try {
        const registration = await navigator.serviceWorker.register("/sw.js", {
          scope: "/",
        });

        registration.update();
      } catch (error) {
        console.error("Unable to register service worker.", error);
      }
    };

    window.addEventListener("load", registerServiceWorker, { once: true });

    return () => {
      window.removeEventListener("load", registerServiceWorker);
    };
  }, []);

  return null;
}
