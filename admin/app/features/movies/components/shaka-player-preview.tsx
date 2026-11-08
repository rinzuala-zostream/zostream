"use client";

import { useEffect, useRef, useState } from "react";
import { X } from "lucide-react";

type ShakaModule = typeof import("shaka-player/dist/shaka-player.ui").default;

type ShakaPlayerPreviewProps = {
  sourceUrl: string;
  title: string;
  onClose: () => void;
};

export function ShakaPlayerPreview({
  sourceUrl,
  title,
  onClose,
}: ShakaPlayerPreviewProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const videoRef = useRef<HTMLVideoElement>(null);
  const [errorMessage, setErrorMessage] = useState("");

  useEffect(() => {
    let isMounted = true;
    let player: InstanceType<ShakaModule["Player"]> | null = null;
    let overlay: InstanceType<ShakaModule["ui"]["Overlay"]> | null = null;

    async function setupPlayer() {
      if (!containerRef.current || !videoRef.current) return;

      try {
        const shaka = (await import("shaka-player/dist/shaka-player.ui"))
          .default;

        shaka.polyfill.installAll();

        if (!shaka.Player.isBrowserSupported()) {
          throw new Error("This browser does not support Shaka playback.");
        }

        player = new shaka.Player(videoRef.current);
        overlay = new shaka.ui.Overlay(
          player,
          containerRef.current,
          videoRef.current,
        );
        overlay.configure({
          controlPanelElements: [
            "play_pause",
            "time_and_duration",
            "spacer",
            "mute",
            "volume",
            "fullscreen",
            "overflow_menu",
          ],
        });

        await player.load(sourceUrl);

        if (isMounted) {
          await videoRef.current.play().catch(() => undefined);
        }
      } catch (error) {
        if (!isMounted) return;
        setErrorMessage(
          error instanceof Error
            ? error.message
            : "Unable to load this video preview.",
        );
      }
    }

    setupPlayer();

    return () => {
      isMounted = false;
      void overlay?.destroy();
      overlay = null;
      void player?.destroy();
      player = null;
    };
  }, [sourceUrl]);

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/72 p-4 backdrop-blur-sm">
      <div className="relative w-full max-w-5xl overflow-hidden rounded-lg border border-white/18 bg-slate-950 p-3 shadow-[0_30px_90px_rgba(2,6,23,0.55)]">
        <div className="mb-3 flex items-center justify-between gap-3 pr-11">
          <div className="min-w-0">
            <p className="truncate text-sm font-bold text-white">{title}</p>
            <p className="truncate text-xs text-slate-400">{sourceUrl}</p>
          </div>
        </div>

        <button
          type="button"
          onClick={onClose}
          className="absolute right-3 top-3 z-20 inline-flex size-9 items-center justify-center rounded-md bg-white/90 text-slate-950 shadow-lg transition hover:bg-slate-200"
          aria-label="Close video preview"
        >
          <X className="size-4" />
        </button>

        <div
          ref={containerRef}
          className="relative aspect-video overflow-hidden rounded-md bg-black"
        >
          <video
            ref={videoRef}
            className="size-full"
            playsInline
            controls={false}
          />
          {errorMessage ? (
            <div className="absolute inset-0 flex items-center justify-center bg-black/82 p-6 text-center text-sm font-semibold text-rose-100">
              {errorMessage}
            </div>
          ) : null}
        </div>
      </div>
    </div>
  );
}
