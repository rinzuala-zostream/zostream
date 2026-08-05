"use client";

import QRCodeStyling from "qr-code-styling";
import { useEffect, useRef } from "react";

type QrTokenCodeProps = {
  token: string;
  size?: number;
};

export function QrTokenCode({ token, size = 260 }: QrTokenCodeProps) {
  const mountRef = useRef<HTMLDivElement | null>(null);
  const qrRef = useRef<QRCodeStyling | null>(null);

  const applyRoundedLogo = () => {
    const logo = mountRef.current?.querySelector("image");
    if (!logo) return;
    logo.setAttribute("style", "clip-path: inset(0 round 12px);");
  };

  useEffect(() => {
    if (!mountRef.current || qrRef.current) return;

    const qr = new QRCodeStyling({
      width: size,
      height: size,
      type: "svg",
      data: token,
      margin: 10,
      image: "/logo/logo.png",
      imageOptions: {
        crossOrigin: "anonymous",
        margin: 4,
        imageSize: 0.26,
      },
      qrOptions: {
        typeNumber: 0,
        mode: "Byte",
        errorCorrectionLevel: "H",
      },
      dotsOptions: {
        type: "classy-rounded",
        color: "#16213a",
      },
      cornersSquareOptions: {
        type: "rounded",
        color: "#16213a",
      },
      cornersDotOptions: {
        type: "rounded",
        color: "#16213a",
      },
      backgroundOptions: {
        color: "#ffffff",
      },
    });

    qr.append(mountRef.current);
    applyRoundedLogo();
    qrRef.current = qr;
  }, [size, token]);

  useEffect(() => {
    if (!qrRef.current) return;
    qrRef.current.update({ data: token });
    requestAnimationFrame(() => applyRoundedLogo());
  }, [token]);

  return (
    <div className="inline-flex rounded-[2.1rem] border border-white/10 bg-white p-3 shadow-[0_10px_16px_rgba(2,6,23,0.22)]">
      <div ref={mountRef} className="rounded-2xl" />
    </div>
  );
}
